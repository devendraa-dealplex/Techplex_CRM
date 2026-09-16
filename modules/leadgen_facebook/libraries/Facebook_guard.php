<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * The envelope checks that stand in front of the signature, now that CSRF no
 * longer stands in front of anything.
 *
 * WHY THESE EXIST, HONESTLY STATED
 * --------------------------------
 * CSRF protection was never securing this endpoint in the sense people mean by
 * "security". A CSRF token proves a request came from a page this application
 * rendered; Meta never loads such a page, so the token could only ever have
 * been absent, which is why the endpoint returned "419 Page Expired" to every
 * real delivery. What CSRF *was* doing, incidentally, was making an
 * unauthenticated stranger's POST cost nothing — it was refused before PHP
 * touched the body.
 *
 * The scoped exemption removes that side effect for exactly one URI. So the
 * work CSRF was accidentally doing has to be done deliberately:
 *
 *   1. **Rate limit, per source.** Otherwise one caller can spend the
 *      processing budget Meta needs. Per source and not global, because a
 *      global limiter is a gift to an attacker: trip it cheaply and every real
 *      lead is refused until the window rolls. Per-source means an abusive IP
 *      throttles itself and Meta's own addresses are unaffected.
 *
 *   2. **Body size, before the HMAC.** `hash_hmac()` over a body whose length
 *      the caller chose is the actual denial-of-service here. The size check
 *      has to come first or it is decoration.
 *
 *   3. **Content type.** Free, and it turns away everything that is not even
 *      pretending to be a Meta delivery.
 *
 * None of this substitutes for the signature. All of it runs in front of the
 * signature, and every refusal writes a log row, so a throttled or oversized
 * delivery is as visible as a forged one. The signature remains the only thing
 * that decides whether a payload is genuine.
 *
 * Pure functions: the caller supplies the headers and the counts, and gets a
 * decision. No database, no options, no superglobals — so each branch,
 * including the ones that need a hostile request, is testable.
 */
class Facebook_guard
{
    /* ---- decisions ---- */
    const OK = 'ok';
    const TOO_LARGE = 'too_large';
    const BAD_CONTENT_TYPE = 'bad_content_type';
    const RATE_LIMITED = 'rate_limited';

    /**
     * Largest body this endpoint will accept, in bytes.
     *
     * A Meta `leadgen` delivery is a few hundred bytes; a batched one with
     * several entries is a few kilobytes. 256 KB is three orders of magnitude
     * of headroom over anything Meta sends and still small enough that an
     * HMAC over it is free. Deliberately not configurable: a size ceiling that
     * an administrator can raise to 500 MB is not a ceiling.
     */
    const MAX_BODY_BYTES = 262144;

    /**
     * Media types accepted on the POST path.
     *
     * Meta sends `application/json`. `application/json; charset=utf-8` and
     * `text/javascript` are accepted as well because intermediaries rewrite
     * this header and a lead lost to a proxy's idea of tidiness is still a
     * lost lead. Compared on the type only — parameters after `;` are ignored.
     */
    const ACCEPTED_CONTENT_TYPES = array(
        'application/json',
        'application/x-www-form-urlencoded',
        'text/json',
        'text/javascript',
        'text/plain',
    );

    /** Requests per source per window, unless configured otherwise. */
    const DEFAULT_RATE_LIMIT = 120;

    /** The window, in seconds. */
    const RATE_WINDOW_SECONDS = 60;

    /**
     * Absolute floor on a configured limit.
     *
     * A limit of 1 would throttle Meta itself on any burst, and a limit of 0
     * would refuse every delivery while looking like a configuration choice
     * rather than an outage. Anything below this is treated as unconfigured.
     */
    const MIN_RATE_LIMIT = 10;

    /**
     * Decide whether this request may proceed to signature verification.
     *
     * @param int    $contentLength bytes, as declared or measured
     * @param string $contentType   the raw Content-Type header
     * @param int    $recentCount   requests already seen from this source in the window
     * @param mixed  $configuredLimit the stored per-window limit
     *
     * @return array decision, reason, limit
     */
    public static function check($contentLength, $contentType, $recentCount, $configuredLimit = null)
    {
        $limit = self::rateLimit($configuredLimit);

        /*
         * Rate limit first: it is the cheapest check and the only one that
         * protects the checks below it from being run a million times.
         */
        if ((int) $recentCount >= $limit) {
            return self::no(self::RATE_LIMITED,
                'Source exceeded ' . $limit . ' requests in '
                . self::RATE_WINDOW_SECONDS . 's.', $limit);
        }

        /*
         * Size before content type, and both before the HMAC. A negative or
         * absent length is treated as unknown and allowed through — the raw
         * body is measured again after reading, and checkBodyLength() below is
         * what actually holds the line.
         */
        if ((int) $contentLength > self::MAX_BODY_BYTES) {
            return self::no(self::TOO_LARGE,
                'Declared body length exceeds ' . self::MAX_BODY_BYTES . ' bytes.', $limit);
        }

        if (!self::contentTypeAccepted($contentType)) {
            return self::no(self::BAD_CONTENT_TYPE,
                'Content type not accepted on this endpoint.', $limit);
        }

        return array('decision' => self::OK, 'reason' => '', 'limit' => $limit);
    }

    /**
     * The second size check, against the body actually read.
     *
     * Content-Length is a claim by the caller. A chunked request has none, and
     * a lying one can declare 10 bytes and send 10 megabytes. So the real
     * length is checked again after reading and before the digest is computed.
     * Checking only the header would be a control that cannot say "no" to the
     * case it exists for.
     */
    public static function checkBodyLength($actualBytes)
    {
        if ((int) $actualBytes > self::MAX_BODY_BYTES) {
            return array('decision' => self::TOO_LARGE,
                         'reason' => 'Body exceeds ' . self::MAX_BODY_BYTES . ' bytes.');
        }

        return array('decision' => self::OK, 'reason' => '');
    }

    /**
     * Is this media type one we accept?
     *
     * Type only; parameters after the semicolon are ignored, because
     * `application/json; charset=UTF-8` is the same media type as
     * `application/json` and refusing it would reject real deliveries.
     *
     * An empty or absent header is refused. Every legitimate sender sets it,
     * and "absent means allowed" is the same shape of mistake as the signature
     * check that the caller could switch off by omitting a header — which is
     * the defect this module was first opened to fix.
     */
    public static function contentTypeAccepted($contentType)
    {
        $type = strtolower(trim((string) $contentType));

        if ($type === '') {
            return false;
        }

        $semi = strpos($type, ';');

        if ($semi !== false) {
            $type = trim(substr($type, 0, $semi));
        }

        return in_array($type, self::ACCEPTED_CONTENT_TYPES, true);
    }

    /**
     * The effective per-window limit.
     *
     * Validated, never cast: `(int) '5abc'` is 5, which would silently throttle
     * Meta to five deliveries a minute because a setting picked up a stray
     * character. Anything malformed, absent or below the floor falls back to
     * the default.
     */
    public static function rateLimit($configured)
    {
        $v = trim((string) $configured);

        if (preg_match('/\A[1-9][0-9]*\z/', $v) !== 1) {
            return self::DEFAULT_RATE_LIMIT;
        }

        $n = (int) $v;

        return $n < self::MIN_RATE_LIMIT ? self::DEFAULT_RATE_LIMIT : $n;
    }

    /**
     * A stable, non-reversible label for the caller, for rate limiting only.
     *
     * The raw address is never stored. It is hashed with a per-install salt, so
     * the log can count requests per source and compare two rows without
     * holding an address — and so that the hashes from one install say nothing
     * about another. Truncated to 32 hex characters, which is far beyond what
     * a collision would need to matter at this volume.
     *
     * A salt is required. Hashing an IPv4 address without one is reversible by
     * anybody with four billion hashes and an afternoon, which is no
     * protection at all; the caller generates and stores the salt once.
     */
    public static function sourceKey($ip, $salt)
    {
        $ip = trim((string) $ip);
        $salt = (string) $salt;

        if ($ip === '' || $salt === '') {
            return '';
        }

        return substr(hash('sha256', $salt . '|' . $ip), 0, 32);
    }

    /**
     * Pick the address to rate-limit on.
     *
     * `REMOTE_ADDR` only. Deliberately NOT `X-Forwarded-For`: that header is
     * supplied by the caller, so limiting on it lets an attacker rotate a
     * header value and get an unlimited budget, while an innocent client
     * behind the same proxy is throttled on somebody else's behaviour. If this
     * install later sits behind a trusted proxy, the trusted hop has to be
     * configured explicitly — it cannot be inferred from a request.
     */
    public static function remoteAddress(array $server)
    {
        return isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : '';
    }

    /** Map a guard decision onto the delivery outcome it produces. */
    public static function outcomeFor($decision)
    {
        $map = array(
            self::TOO_LARGE        => Facebook_delivery::REJECTED_TOO_LARGE,
            self::BAD_CONTENT_TYPE => Facebook_delivery::REJECTED_BAD_CONTENT_TYPE,
            self::RATE_LIMITED     => Facebook_delivery::REJECTED_RATE_LIMITED,
        );

        return isset($map[$decision]) ? $map[$decision] : null;
    }

    private static function no($decision, $reason, $limit)
    {
        return array('decision' => $decision, 'reason' => $reason, 'limit' => $limit);
    }
}
