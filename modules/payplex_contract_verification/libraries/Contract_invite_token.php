<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Contract_invite_token
 *
 * Single-use invitation tokens for the Video KYC journey, and the rules about
 * what may appear in a link, a message or a log.
 *
 * THE THREAT THIS IS BUILT AGAINST
 * --------------------------------
 * A KYC invitation link is, for its lifetime, the ability to present yourself as
 * a named signer to an identity-verification session. It travels by email and
 * SMS — forwarded, screenshotted, synced to three devices, sitting in an
 * archive. It is not a secret in any meaningful sense after delivery.
 *
 * So the design assumes the link leaks and limits what that is worth:
 *
 *   - it is USELESS ONCE USED. Single use, consumed on first successful
 *     presentation, so a forwarded copy opens nothing.
 *   - it EXPIRES SOON. Hours, not days.
 *   - it is NOT IN OUR DATABASE. Only a SHA-256 of it is stored, so a database
 *     disclosure yields no usable links — the same reason passwords are hashed.
 *   - it CARRIES NO IDENTIFIERS. No contract id, no signer id, no email in the
 *     URL. A sequential id in a link is an invitation to try the next one, and
 *     URLs end up in referrer headers, proxy logs and browser history.
 *
 * WHY THE RAW TOKEN IS RETURNED EXACTLY ONCE
 * ------------------------------------------
 * `issue()` is the only moment the raw value exists. It goes into the outbound
 * message and is then unrecoverable — not by an administrator, not by support,
 * not by a database query. Reissuing means a new token, which revokes the old
 * one. That is deliberate: a system that can show you an existing link is a
 * system where anyone with admin access can impersonate a signer at a
 * verification session.
 *
 * Pure: no database, no I/O. The caller stores what `issue()` returns.
 */
class Contract_invite_token
{
    /** Bytes of entropy. 32 bytes = 256 bits, well beyond guessing. */
    const ENTROPY_BYTES = 32;

    /** Hash used for the stored form. */
    const HASH_ALGO = 'sha256';

    /** Default lifetime. Short, because the link is a bearer credential. */
    const DEFAULT_TTL_HOURS = 24;

    /** Nothing may live longer than this, whatever a caller asks for. */
    const MAX_TTL_HOURS = 72;

    /** Resends allowed before a human has to intervene. */
    const MAX_RESENDS = 3;

    /** Minimum gap between resends, in seconds. */
    const RESEND_COOLDOWN_SECONDS = 300;

    /* ---- why a token is no longer usable --------------------------------- */

    const R_CONSUMED    = 'consumed';
    const R_EXPIRED     = 'expired';
    const R_REPLACED    = 'replaced';
    const R_CANCELLED   = 'cancelled';
    const R_COMPLETED   = 'verification_completed';

    /**
     * Every reason a token stops working.
     *
     * @return array
     */
    public static function revocationReasons()
    {
        return array(
            self::R_CONSUMED  => 'Used once, as designed.',
            self::R_EXPIRED   => 'The link passed its expiry.',
            self::R_REPLACED  => 'A newer link was issued; issuing one always revokes the previous.',
            self::R_CANCELLED => 'The contract or session was cancelled.',
            self::R_COMPLETED => 'Verification finished, so the link has no remaining purpose.',
        );
    }

    /**
     * Mint a token.
     *
     * The raw value is returned and never stored. Store `hash`.
     *
     * @param  int $now         unix timestamp
     * @param  int $ttlHours
     * @return array {raw, hash, algo, expires_at, ttl_hours}
     */
    public static function issue($now, $ttlHours = self::DEFAULT_TTL_HOURS)
    {
        $ttl = (int) $ttlHours;

        if ($ttl < 1)                    { $ttl = self::DEFAULT_TTL_HOURS; }
        if ($ttl > self::MAX_TTL_HOURS)  { $ttl = self::MAX_TTL_HOURS; }

        $raw = self::randomToken();

        return array(
            'raw'        => $raw,
            'hash'       => self::hash($raw),
            'algo'       => self::HASH_ALGO,
            'expires_at' => (int) $now + ($ttl * 3600),
            'ttl_hours'  => $ttl,
        );
    }

    /**
     * Cryptographically secure random, URL-safe, no padding.
     *
     * `random_bytes` throws rather than returning weak output when no good
     * source is available, which is the behaviour we want: failing to issue a
     * link is recoverable, issuing a guessable one is not. There is deliberately
     * no fallback to mt_rand.
     *
     * @return string
     */
    public static function randomToken()
    {
        return rtrim(strtr(base64_encode(random_bytes(self::ENTROPY_BYTES)), '+/', '-_'), '=');
    }

    /**
     * The stored form of a token.
     *
     * Unsalted, and that is correct here rather than sloppy: the input is 256
     * bits of uniform randomness, so there is no dictionary to attack and no
     * rainbow table to build. A salt would defend against a threat that does not
     * exist for this input, while making lookup by hash impossible.
     *
     * @param  string $raw
     * @return string
     */
    public static function hash($raw)
    {
        return hash(self::HASH_ALGO, (string) $raw);
    }

    /**
     * May this presented token be accepted?
     *
     * Fails closed on everything it does not positively recognise, and the
     * comparison is constant-time.
     *
     * @param  string $presented
     * @param  array  $stored {token_hash, expires_at, consumed_at, revoked_at, revoked_reason}
     * @param  int    $now
     * @return array {ok, reason}
     */
    public static function verify($presented, array $stored, $now)
    {
        $presented = is_string($presented) ? trim($presented) : '';

        if ($presented === '') { return self::no('no_token_presented'); }

        $storedHash = (string) self::pick($stored, 'token_hash', '');

        if ($storedHash === '') { return self::no('no_token_on_record'); }

        /*
         * Constant-time. A length-or-content comparison on a token is a timing
         * oracle, and one that can be exercised as often as the attacker likes.
         */
        if (!hash_equals($storedHash, self::hash($presented))) {
            return self::no('token_does_not_match');
        }

        /* Order matters below: the most specific reason wins, so the audit trail
           records WHY a link failed rather than a generic refusal. */

        if ((int) self::pick($stored, 'consumed_at', 0) > 0) {
            return self::no('already_used');
        }

        if ((int) self::pick($stored, 'revoked_at', 0) > 0) {
            $why = (string) self::pick($stored, 'revoked_reason', self::R_REPLACED);

            return self::no('revoked_' . $why);
        }

        $expires = (int) self::pick($stored, 'expires_at', 0);

        if ($expires <= 0)      { return self::no('no_expiry_recorded'); }
        if ((int) $now >= $expires) { return self::no('expired'); }

        return array('ok' => true, 'reason' => 'valid');
    }

    /**
     * May another invitation be sent right now?
     *
     * Rate limiting here is not only about abuse. An unlimited resend button
     * turns into a way to send a person twenty messages, and every one of them
     * is a live link at the moment it is sent.
     *
     * @param  array $state {resend_count, last_sent_at}
     * @param  int   $now
     * @return array {allowed, reason, retry_after}
     */
    public static function mayResend(array $state, $now)
    {
        $count = (int) self::pick($state, 'resend_count', 0);
        $last  = (int) self::pick($state, 'last_sent_at', 0);

        if ($count >= self::MAX_RESENDS) {
            return array('allowed' => false, 'reason' => 'resend_limit_reached', 'retry_after' => 0);
        }

        if ($last > 0) {
            $elapsed = (int) $now - $last;

            if ($elapsed < self::RESEND_COOLDOWN_SECONDS) {
                return array(
                    'allowed'     => false,
                    'reason'      => 'cooling_down',
                    'retry_after' => self::RESEND_COOLDOWN_SECONDS - $elapsed,
                );
            }
        }

        return array('allowed' => true, 'reason' => 'permitted', 'retry_after' => 0);
    }

    /* ---- what may be shown ------------------------------------------------ */

    /**
     * An email address, masked for display and for the audit trail.
     *
     * The audit trail is read by more people than the contract is, and it is
     * exported. "We sent it to the right person" is answerable from a mask;
     * it does not require the address itself.
     *
     * @param  string $email
     * @return string
     */
    public static function maskEmail($email)
    {
        $email = trim((string) $email);

        if ($email === '' || strpos($email, '@') === false) { return '(not recorded)'; }

        list($local, $domain) = explode('@', $email, 2);

        $keep = strlen($local) <= 2 ? 1 : 2;
        $mask = str_repeat('*', max(1, strlen($local) - $keep));

        return substr($local, 0, $keep) . $mask . '@' . $domain;
    }

    /**
     * A mobile number, masked to its last four digits.
     *
     * @param  string $mobile
     * @return string
     */
    public static function maskMobile($mobile)
    {
        $digits = preg_replace('/\D+/', '', (string) $mobile);

        if ($digits === '' || strlen($digits) < 4) { return '(not recorded)'; }

        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    /**
     * Things that must never appear in a public URL.
     *
     * Kept as data so a test can assert the built link against it, rather than
     * a reviewer having to notice.
     *
     * @return array
     */
    public static function forbiddenInUrl()
    {
        return array('contract_id', 'signer_id', 'request_id', 'session_id',
                     'email', 'mobile', 'phone', 'staff_id', 'id');
    }

    /**
     * Build the public verification URL.
     *
     * The token and nothing else. No identifier, no email, no contract
     * reference — a URL is not a private place: it reaches referrer headers,
     * proxy logs, browser history and anything the recipient pastes it into.
     *
     * @param  string $base
     * @param  string $rawToken
     * @return string
     */
    public static function publicUrl($base, $rawToken)
    {
        return rtrim((string) $base, '/') . '/verify/' . rawurlencode((string) $rawToken);
    }

    /* ---- the message ------------------------------------------------------ */

    /**
     * Everything an invitation must tell the signer.
     *
     * Listed as data because a template is easy to edit and easy to quietly
     * strip. A recording notice removed from an email is a consent problem that
     * nobody sees until it matters, so the required elements are asserted rather
     * than trusted.
     *
     * @return array
     */
    public static function requiredMessageElements()
    {
        return array(
            'signer_name'      => 'Who the message is for',
            'contract_ref'     => 'Which agreement it concerns',
            'company_name'     => 'Who is asking — a verification request from nobody in particular is a phishing email',
            'secure_link'      => 'The single-use verification link',
            'link_expiry'      => 'When it stops working, stated plainly',
            'privacy_notice'   => 'That the session is recorded, and what happens to the recording',
            'support_contact'  => 'How to reach a human',
            'do_not_share'     => 'That the link is personal and must not be forwarded',
        );
    }

    /**
     * Does a rendered message contain everything it must?
     *
     * @param  string $body
     * @param  array  $values
     * @return array {ok, missing}
     */
    public static function checkMessage($body, array $values)
    {
        $body    = (string) $body;
        $missing = array();

        foreach (self::requiredMessageElements() as $key => $ignored) {
            $needle = isset($values[$key]) ? trim((string) $values[$key]) : '';

            if ($needle === '' || strpos($body, $needle) === false) { $missing[] = $key; }
        }

        return array('ok' => count($missing) === 0, 'missing' => $missing);
    }

    /* ---- helpers ---------------------------------------------------------- */

    private static function no($reason)
    {
        return array('ok' => false, 'reason' => $reason);
    }

    private static function pick($a, $k, $default)
    {
        if (is_array($a) && array_key_exists($k, $a)) { return $a[$k]; }

        return $default;
    }
}
