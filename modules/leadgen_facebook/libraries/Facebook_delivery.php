<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Every way an inbound Meta request can end, and the HTTP status each one
 * returns.
 *
 * WHY THIS IS A TABLE AND NOT A SET OF `http_response_code()` CALLS
 * -----------------------------------------------------------------
 * The status code a webhook returns is a protocol decision with consequences:
 * Meta retries a 5xx, does not retry a 403, and treats sustained failure as
 * grounds to disable the subscription. Spread across a controller as eight
 * separate literal calls, that decision cannot be reviewed as a whole, and two
 * paths that must return the same code drift apart without anybody noticing.
 *
 * So the outcome is named first, and the code is looked up. The requirement
 * "an invalid verify token and an invalid signature must both return 403" then
 * becomes one assertion over this map rather than an inspection of control
 * flow, and adding a new outcome without deciding its status code is a test
 * failure rather than an implicit 200.
 */
class Facebook_delivery
{
    /* ---- verification handshake (GET) ---- */

    /** hub.mode=subscribe with the right token: the challenge is echoed. */
    const VERIFY_OK = 'verify_ok';
    /** The token did not match the configured one. */
    const VERIFY_BAD_TOKEN = 'verify_bad_token';
    /** A GET that was not a subscribe handshake at all. */
    const VERIFY_BAD_MODE = 'verify_bad_mode';
    /** No verify token configured on this install, so nothing can be verified. */
    const VERIFY_NOT_CONFIGURED = 'verify_not_configured';

    /* ---- event delivery (POST) ---- */

    /** Signature verified and a new lead was created. */
    const ACCEPTED_CREATED = 'accepted_lead_created';
    /** Signature verified; this leadgen_id had already been processed. */
    const ACCEPTED_DUPLICATE = 'accepted_duplicate';
    /** Signature verified; matched an existing lead by email or phone. */
    const ACCEPTED_MATCHED = 'accepted_matched_existing';
    /** Signature verified; nothing in the payload needed acting on. */
    const ACCEPTED_NO_ACTION = 'accepted_no_action';
    /** Signature verified; lead created but no valid assignee, so queued. */
    const ACCEPTED_UNASSIGNED = 'accepted_unassigned_review';

    /**
     * Handled, kept, and deliberately NOT turned into a lead: the delivery
     * carried neither an email nor a phone number.
     *
     * 200, like the other accepted outcomes, because we did handle it. A 4xx or
     * 5xx here would make Meta retry a delivery we have already stored, forever.
     */
    const ACCEPTED_QUARANTINED = 'accepted_quarantined_no_contact';
    /** No app secret configured: cannot verify, so refuses. */
    const REJECTED_NOT_CONFIGURED = 'rejected_not_configured';
    /** X-Hub-Signature-256 absent. */
    const REJECTED_NO_SIGNATURE = 'rejected_missing_signature';
    /** X-Hub-Signature-256 present and wrong. */
    const REJECTED_BAD_SIGNATURE = 'rejected_bad_signature';
    /** Signature verified, body was not usable JSON. */
    const REJECTED_BAD_PAYLOAD = 'rejected_bad_payload';
    /**
     * Envelope refusals, checked before the signature.
     *
     * These three exist because this endpoint is CSRF-exempt. CSRF was never
     * protecting it from anything a signature does not already cover, but it
     * WAS incidentally stopping an unauthenticated stranger from making the
     * server do work — and the exemption removes that. So the cheap envelope
     * checks come first and the expensive ones come after:
     *
     *   rate limit   -> before anything, per source, so one abusive caller
     *                   cannot spend the budget Meta needs
     *   size         -> before the HMAC, because computing a digest over a
     *                   body the caller chose the length of is the DoS
     *   content type -> before the HMAC, same reason, and it costs nothing
     *
     * None of them weakens the signature check; all of them run in front of
     * it, and every one writes a log row.
     */
    const REJECTED_TOO_LARGE = 'rejected_body_too_large';
    /** Content-Type was not JSON. */
    const REJECTED_BAD_CONTENT_TYPE = 'rejected_bad_content_type';
    /** Too many requests from this source inside the window. */
    const REJECTED_RATE_LIMITED = 'rejected_rate_limited';
    /** Something threw. Recorded, and the sender is told to retry. */
    const FAILED_EXCEPTION = 'failed_exception';

    /**
     * Outcome => HTTP status.
     *
     *   403  the caller's request is not acceptable and retrying it unchanged
     *        will not help. Both verify-token and signature failures land here,
     *        deliberately and identically.
     *   503  this server is not configured. The request may well be valid; a
     *        sender reading codes should retry later rather than conclude the
     *        subscription is dead.
     *   500  we broke. Meta retries, and the idempotency claim makes that safe.
     *   413  the body is larger than this endpoint will read.
     *   415  the body is not the media type this endpoint accepts.
     *   429  too many requests from this source; come back shortly. Retryable,
     *        and the only retryable status below 500 — a limiter that told the
     *        sender "never again" would drop real leads on a burst.
     *   200  we have the event and have finished with it.
     */
    const HTTP = array(
        self::VERIFY_OK               => 200,
        self::VERIFY_BAD_TOKEN        => 403,
        self::VERIFY_BAD_MODE         => 403,
        self::VERIFY_NOT_CONFIGURED   => 503,

        self::ACCEPTED_CREATED        => 200,
        self::ACCEPTED_DUPLICATE      => 200,
        self::ACCEPTED_MATCHED        => 200,
        self::ACCEPTED_QUARANTINED    => 200,
        self::ACCEPTED_NO_ACTION      => 200,
        self::ACCEPTED_UNASSIGNED     => 200,

        self::REJECTED_NOT_CONFIGURED => 503,
        self::REJECTED_NO_SIGNATURE   => 403,
        self::REJECTED_BAD_SIGNATURE  => 403,
        self::REJECTED_BAD_PAYLOAD    => 200,

        self::REJECTED_TOO_LARGE        => 413,
        self::REJECTED_BAD_CONTENT_TYPE => 415,
        self::REJECTED_RATE_LIMITED     => 429,

        self::FAILED_EXCEPTION        => 500,
    );

    /**
     * A short, safe explanation for the log.
     *
     * Deliberately says nothing a caller could use to work out what the correct
     * value would have been — no expected signature, no token length, no
     * configured page id. "Signature did not match" and nothing more.
     */
    const REASON = array(
        self::VERIFY_OK               => 'Handshake verified; challenge echoed.',
        self::VERIFY_BAD_TOKEN        => 'Verify token did not match.',
        self::VERIFY_BAD_MODE         => 'Not a subscribe handshake.',
        self::VERIFY_NOT_CONFIGURED   => 'No verify token configured on this install.',

        self::ACCEPTED_CREATED        => 'Lead created.',
        self::ACCEPTED_DUPLICATE      => 'Already processed; no second lead created.',
        self::ACCEPTED_MATCHED        => 'Matched an existing lead; no new lead created.',
        self::ACCEPTED_QUARANTINED    => 'Stored for review: the delivery carried no email and no phone number, so no lead was created.',
        self::ACCEPTED_NO_ACTION      => 'Nothing in the payload required action.',
        self::ACCEPTED_UNASSIGNED     => 'Lead created with no valid assignee; queued for review.',

        self::REJECTED_NOT_CONFIGURED => 'No app secret configured; cannot verify.',
        self::REJECTED_NO_SIGNATURE   => 'Signature header absent.',
        self::REJECTED_BAD_SIGNATURE  => 'Signature did not match.',
        self::REJECTED_BAD_PAYLOAD    => 'Body was not a usable event payload.',

        self::REJECTED_TOO_LARGE        => 'Request body exceeded the accepted size.',
        self::REJECTED_BAD_CONTENT_TYPE => 'Content type is not accepted on this endpoint.',
        self::REJECTED_RATE_LIMITED     => 'Too many requests from this source; throttled.',

        self::FAILED_EXCEPTION        => 'Processing error; delivery not applied.',
    );

    /** Outcomes that mean the delivery was taken. */
    const ACCEPTED = array(
        self::VERIFY_OK, self::ACCEPTED_CREATED, self::ACCEPTED_DUPLICATE,
        self::ACCEPTED_MATCHED, self::ACCEPTED_NO_ACTION, self::ACCEPTED_UNASSIGNED,
        self::ACCEPTED_QUARANTINED,
    );

    /** Outcomes that mean it was refused. */
    const REJECTED = array(
        self::VERIFY_BAD_TOKEN, self::VERIFY_BAD_MODE, self::VERIFY_NOT_CONFIGURED,
        self::REJECTED_NOT_CONFIGURED, self::REJECTED_NO_SIGNATURE,
        self::REJECTED_BAD_SIGNATURE, self::REJECTED_BAD_PAYLOAD,
        self::REJECTED_TOO_LARGE, self::REJECTED_BAD_CONTENT_TYPE,
        self::REJECTED_RATE_LIMITED,
        self::FAILED_EXCEPTION,
    );

    public static function all()
    {
        return array_keys(self::HTTP);
    }

    public static function httpFor($outcome)
    {
        return isset(self::HTTP[$outcome]) ? self::HTTP[$outcome] : 500;
    }

    public static function reasonFor($outcome)
    {
        return isset(self::REASON[$outcome]) ? self::REASON[$outcome] : 'Unrecognised outcome.';
    }

    public static function isAccepted($outcome)
    {
        return in_array($outcome, self::ACCEPTED, true);
    }

    /**
     * Body text to return. Kept to Meta's expected tokens for the accepted
     * cases; a refusal says only which check failed.
     */
    public static function bodyFor($outcome)
    {
        if ($outcome === self::VERIFY_OK) {
            return '';  /* the challenge is written by the caller */
        }

        if (self::isAccepted($outcome)) {
            return 'EVENT_RECEIVED';
        }

        return self::reasonFor($outcome);
    }

    /**
     * Whether this module should tell Meta to try again.
     *
     * Only the two cases where the fault is ours: an unconfigured install and
     * an exception. Everything else is final.
     */
    public static function isRetryable($outcome)
    {
        return in_array($outcome, array(
            self::VERIFY_NOT_CONFIGURED,
            self::REJECTED_NOT_CONFIGURED,
            self::REJECTED_RATE_LIMITED,
            self::FAILED_EXCEPTION,
        ), true);
    }
}
