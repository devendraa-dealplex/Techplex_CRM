<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Contract_signing_state.php';

/**
 * Contract_webhook_guard
 *
 * Everything that has to be true before a webhook is allowed to influence a
 * contract, and nothing that is specific to one provider.
 *
 * WHAT THIS CLASS DELIBERATELY CANNOT DO
 * --------------------------------------
 * It cannot verify a Leegality signature, because the algorithm, the header
 * name and the canonical string are in documentation this account has not yet
 * provided. Rather than guess at an HMAC construction that would *look*
 * correct — same function name, same hex digest, wrong canonical form — the
 * verifier REFUSES any algorithm it does not positively recognise.
 *
 * That is the important direction to fail in. A signature check that is wrong
 * in a way that always passes is worse than no signature check at all, because
 * it appears in the security report as a control.
 *
 * THE FIVE THINGS A WEBHOOK MUST SURVIVE
 * --------------------------------------
 *   1. The raw body must be the bytes that were sent. Signatures are over
 *      bytes, not over a structure that has been decoded and re-encoded — JSON
 *      round-tripping changes key order, number formatting and unicode escapes,
 *      and the signature then never matches.
 *   2. The signature must verify, in constant time.
 *   3. The timestamp must be inside a replay window.
 *   4. The event id must not have been processed before. Delivery is
 *      at-least-once everywhere; this is not optional.
 *   5. The event type must be one this code knows. An unknown event is
 *      recorded and changes nothing.
 *
 * Pure: no database, no clock of its own — `now` is always a parameter.
 */
class Contract_webhook_guard
{
    /** How far out of step a payload's timestamp may be, in seconds. */
    const REPLAY_WINDOW_SECONDS = 300;

    /** A body larger than this is refused unread. */
    const MAX_BODY_BYTES = 1048576;   // 1 MiB

    /* ---- rejection reasons --------------------------------------------- */

    const R_OK                = 'accepted';
    const R_BODY_EMPTY        = 'empty_body';
    const R_BODY_TOO_LARGE    = 'body_too_large';
    const R_NO_SECRET         = 'webhook_secret_not_configured';
    const R_ALGO_UNKNOWN      = 'signature_algorithm_not_documented';
    const R_NO_SIGNATURE      = 'signature_header_absent';
    const R_BAD_SIGNATURE     = 'signature_did_not_verify';
    const R_NO_TIMESTAMP      = 'timestamp_absent';
    const R_STALE             = 'outside_replay_window';
    const R_FUTURE            = 'timestamp_in_the_future';
    const R_NO_EVENT_ID       = 'event_id_absent';
    const R_DUPLICATE         = 'already_processed';
    const R_UNKNOWN_EVENT     = 'unknown_event_type';
    const R_MALFORMED         = 'payload_not_parseable';

    /**
     * Signature algorithms this class is prepared to verify.
     *
     * EMPTY ON PURPOSE, AND THIS IS NOT AN OVERSIGHT.
     *
     * The entry for Leegality is added in the same change that records which
     * algorithm, which header and which canonical string their documentation
     * specifies — with the document version and retrieval date in the commit.
     * Until then `verify()` refuses everything, which means the webhook route
     * accepts nothing, which is the correct behaviour for a route whose
     * authentication is unspecified.
     *
     * @return array algorithm key => {hash, canonical}
     */
    public static function supportedAlgorithms()
    {
        return array();
    }

    /**
     * Is a verifier available at all?
     *
     * The settings screen and the security report both ask this, so the answer
     * is a method rather than a comment somebody has to find.
     *
     * @return array {available: bool, reason: string}
     */
    public static function verifierAvailable()
    {
        if (!self::supportedAlgorithms()) {
            return array('available' => false, 'reason' => self::R_ALGO_UNKNOWN);
        }

        return array('available' => true, 'reason' => self::R_OK);
    }

    /**
     * Verify a payload signature.
     *
     * Refuses any algorithm not in the supported list. Uses `hash_equals` so a
     * near-miss and a wild miss take the same time — timing a signature check
     * is a practical attack when an endpoint is public and rate limits are
     * generous.
     *
     * @param  string $rawBody    the bytes as received
     * @param  string $signature  the value from the provider's header
     * @param  string $secret     the configured webhook secret
     * @param  string $algorithm  key from supportedAlgorithms()
     * @return array {ok: bool, reason: string}
     */
    public static function verify($rawBody, $signature, $secret, $algorithm)
    {
        if (!is_string($secret) || $secret === '') {
            return self::no(self::R_NO_SECRET);
        }

        if (!is_string($signature) || $signature === '') {
            return self::no(self::R_NO_SIGNATURE);
        }

        $algos = self::supportedAlgorithms();

        if (!is_string($algorithm) || !isset($algos[$algorithm])) {
            /*
             * The refusal that matters. See supportedAlgorithms(): guessing an
             * HMAC construction produces a check that looks right in review and
             * either rejects every real webhook or, far worse, accepts forged
             * ones because the canonical string was wrong in a way that made
             * the comparison trivially satisfiable.
             */
            return self::no(self::R_ALGO_UNKNOWN);
        }

        $spec     = $algos[$algorithm];
        $expected = hash_hmac($spec['hash'], $rawBody, $secret);

        if (!hash_equals($expected, $signature)) {
            return self::no(self::R_BAD_SIGNATURE);
        }

        return array('ok' => true, 'reason' => self::R_OK);
    }

    /**
     * Is this delivery inside the replay window?
     *
     * Both directions are checked. A timestamp far in the future is as much a
     * sign of a forged or replayed request as one far in the past, and clock
     * skew of five minutes covers any honest disagreement between two servers.
     *
     * @param  mixed $timestamp  unix seconds
     * @param  int   $now
     * @param  int   $window
     * @return array {ok, reason, drift}
     */
    public static function withinReplayWindow($timestamp, $now, $window = self::REPLAY_WINDOW_SECONDS)
    {
        if ($timestamp === null || $timestamp === '' || !is_numeric($timestamp)) {
            return array('ok' => false, 'reason' => self::R_NO_TIMESTAMP, 'drift' => null);
        }

        $drift = (int) $now - (int) $timestamp;

        if ($drift > (int) $window) {
            return array('ok' => false, 'reason' => self::R_STALE, 'drift' => $drift);
        }

        if ($drift < -(int) $window) {
            return array('ok' => false, 'reason' => self::R_FUTURE, 'drift' => $drift);
        }

        return array('ok' => true, 'reason' => self::R_OK, 'drift' => $drift);
    }

    /**
     * The idempotency key for a delivery.
     *
     * Derived from the provider's own event id where there is one. If there is
     * not, a digest of the raw body is used — which is weaker, because two
     * genuinely distinct events with identical payloads would collide, but it
     * is far better than processing every retry.
     *
     * @param  array  $payload
     * @param  string $rawBody
     * @return array {key, source, ok, reason}
     */
    public static function idempotencyKey(array $payload, $rawBody)
    {
        foreach (array('event_id', 'eventId', 'id', 'delivery_id', 'deliveryId') as $k) {
            if (!empty($payload[$k]) && is_scalar($payload[$k])) {
                return array('ok' => true, 'key' => (string) $payload[$k],
                             'source' => 'provider_event_id', 'reason' => self::R_OK);
            }
        }

        if (is_string($rawBody) && $rawBody !== '') {
            return array('ok' => true, 'key' => 'body:' . hash('sha256', $rawBody),
                         'source' => 'body_digest', 'reason' => 'no_event_id_in_payload');
        }

        return array('ok' => false, 'key' => '', 'source' => null, 'reason' => self::R_NO_EVENT_ID);
    }

    /**
     * The full gate, in the order the checks have to happen.
     *
     * Order is not cosmetic. Size is checked before parsing; the signature is
     * checked before the payload is trusted for anything, including its own
     * timestamp and event id; idempotency is checked before any work is done.
     *
     * @param  array $in {raw_body, headers, secret, algorithm, signature,
     *                    timestamp, now, seen_keys}
     * @return array {accept, reason, event_key, payload, http_status}
     */
    public static function admit(array $in)
    {
        $raw = isset($in['raw_body']) ? $in['raw_body'] : '';

        if (!is_string($raw) || $raw === '') {
            return self::reject(self::R_BODY_EMPTY, 400);
        }

        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return self::reject(self::R_BODY_TOO_LARGE, 413);
        }

        $v = self::verify(
            $raw,
            isset($in['signature']) ? $in['signature'] : '',
            isset($in['secret']) ? $in['secret'] : '',
            isset($in['algorithm']) ? $in['algorithm'] : '');

        if (empty($v['ok'])) {
            /*
             * 401 for anything that failed authentication, including the
             * not-yet-documented algorithm. A 500 would invite the provider to
             * retry a delivery that can never succeed, and a 200 would be a lie.
             */
            return self::reject($v['reason'], 401);
        }

        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            return self::reject(self::R_MALFORMED, 400);
        }

        $w = self::withinReplayWindow(
            isset($in['timestamp']) ? $in['timestamp'] : null,
            isset($in['now']) ? (int) $in['now'] : 0);

        if (empty($w['ok'])) {
            return self::reject($w['reason'], 401);
        }

        $k = self::idempotencyKey($payload, $raw);

        if (empty($k['ok'])) {
            return self::reject($k['reason'], 400);
        }

        $seen = isset($in['seen_keys']) && is_array($in['seen_keys']) ? $in['seen_keys'] : array();

        if (in_array($k['key'], $seen, true)) {
            /*
             * 200, not an error. A duplicate is the provider doing exactly what
             * at-least-once delivery says it will do; answering with an error
             * makes it retry harder.
             */
            return array('accept' => false, 'reason' => self::R_DUPLICATE,
                         'event_key' => $k['key'], 'payload' => $payload, 'http_status' => 200);
        }

        return array('accept' => true, 'reason' => self::R_OK,
                     'event_key' => $k['key'], 'payload' => $payload, 'http_status' => 200);
    }

    /* ---- what may be stored about a delivery --------------------------- */

    /**
     * Fields recorded for every delivery.
     *
     * @return array
     */
    public static function storedFields()
    {
        return array('event_key', 'event_type', 'external_request_id',
                     'received_at', 'processed_at', 'result', 'http_status', 'state_before',
                     'state_after', 'body_sha256');
    }

    /**
     * Fields that must NEVER be stored about a delivery.
     *
     * The secret and the signature are the obvious ones. The raw body is on the
     * list too: it is the provider's copy of the signer's personal data, and
     * keeping it turns a webhook log into a second, unmanaged store of exactly
     * the information the contract record already holds under proper controls.
     * A digest is kept instead, which is enough to prove what arrived.
     *
     * @return array
     */
    public static function neverStored()
    {
        return array('secret', 'webhook_secret', 'signature', 'authorization',
                     'raw_body', 'payload', 'signer_email', 'signer_phone',
                     'aadhaar', 'aadhaar_number', 'pan', 'otp', 'access_token');
    }

    /**
     * Map a provider event name to an internal state.
     *
     * EMPTY ON PURPOSE — the same reasoning as supportedAlgorithms(). Provider
     * event names come from the documentation, not from a guess. Until the map
     * is filled, every event resolves to null, which `Contract_signing_state`
     * treats as "record it and change nothing".
     *
     * @return array provider event => internal state
     */
    public static function eventMap()
    {
        return array();
    }

    /**
     * @param  string $providerEvent
     * @return array {state: string|null, known: bool}
     */
    public static function toInternalState($providerEvent)
    {
        $map = self::eventMap();

        if (!is_string($providerEvent) || !isset($map[$providerEvent])) {
            return array('state' => null, 'known' => false);
        }

        $state = $map[$providerEvent];

        /* A mapping table that names a state this build does not have is a
           deployment mistake, not an unknown event. Fail closed either way. */
        if (!Contract_signing_state::isState($state)) {
            return array('state' => null, 'known' => false);
        }

        return array('state' => $state, 'known' => true);
    }

    /* ---- helpers ------------------------------------------------------- */

    private static function no($reason)
    {
        return array('ok' => false, 'reason' => $reason);
    }

    private static function reject($reason, $status)
    {
        return array('accept' => false, 'reason' => $reason, 'event_key' => null,
                     'payload' => null, 'http_status' => (int) $status);
    }
}
