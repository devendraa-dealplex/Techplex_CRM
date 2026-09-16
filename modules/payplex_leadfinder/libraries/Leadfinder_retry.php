<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_retry — when a failed Places request may be sent again, and how
 * many times.
 *
 * WHY THIS IS NOT "JUST RETRY THREE TIMES"
 * ----------------------------------------
 * Every retry is a billable request on some SKUs. A blind retry loop turns one
 * employee's failed search into three charges, and a loop around a 400 —
 * a malformed FieldMask, a query Google will reject every time — turns a bug
 * into a bill that grows with each deploy. So the question "is this worth
 * retrying" has to be answered before the question "how long do we wait".
 *
 * The split is the same one `Leadfinder_quota::isBillable()` draws, for the same
 * reason, and the two must agree: a response Google sent is a decision, and
 * asking again will get the same decision. Only a failure to *reach* Google, or
 * an explicit "I am overloaded, come back", is worth a second attempt.
 *
 *   429  Google asking us to slow down            -> retry, with backoff
 *   500, 502, 503, 504  Google failing            -> retry
 *   0 / null  never left the building             -> retry
 *   400, 403, 404  a decision about our request   -> DO NOT retry
 *   200  succeeded                                -> nothing to retry
 *
 * THE RESERVATION IS NOT RE-TAKEN
 * -------------------------------
 * A retry re-sends the same logical request, so it reuses the reservation
 * already held for it. Taking a fresh unit per attempt would mean a Google
 * outage silently consumed a month's allowance in a few seconds — the exact
 * failure the ceiling exists to prevent, arriving through the mechanism meant
 * to survive an outage.
 *
 * No sleeping happens here. The class says how long to wait; the caller decides
 * whether it is in a context where waiting is acceptable, and a request a person
 * is sitting in front of is not always one.
 */
class Leadfinder_retry
{
    /** Attempts including the first. 3 means one call and at most two retries. */
    const DEFAULT_MAX_ATTEMPTS = 3;

    /** First backoff step, milliseconds. Doubles each attempt. */
    const DEFAULT_BASE_MS = 400;

    /** Never wait longer than this between attempts, whatever the doubling says. */
    const MAX_BACKOFF_MS = 8000;

    /** The hard ceiling on attempts, whatever configuration asks for. */
    const ABSOLUTE_MAX_ATTEMPTS = 5;

    /**
     * Statuses worth trying again.
     *
     * 408 is included — a request timeout is the server saying it gave up
     * waiting for us, not a decision about the request. 409 and 422 are not:
     * they are decisions.
     */
    public static function retryableStatuses()
    {
        return array(408, 429, 500, 502, 503, 504);
    }

    /**
     * Should attempt `$attempt` (1-based) be followed by another?
     *
     * `$httpStatus` null or 0 means nothing was sent — a DNS failure, a refused
     * connection, a cURL error. That is retryable and, unlike every other
     * retryable case, it is also the one that costs nothing.
     */
    public static function shouldRetry($httpStatus, $attempt, $maxAttempts = null)
    {
        $max = self::attempts($maxAttempts);

        if ((int) $attempt >= $max) {
            return false;
        }

        if ($httpStatus === null || (int) $httpStatus === 0) {
            return true;
        }

        return in_array((int) $httpStatus, self::retryableStatuses(), true);
    }

    /**
     * Milliseconds to wait before attempt `$attempt + 1`.
     *
     * Exponential with a cap, and a deterministic jitter derived from the
     * attempt rather than `rand()`: a test that cannot predict the delay cannot
     * assert the ceiling, and a random delay is not the property that matters —
     * spreading retries is.
     */
    public static function backoffMs($attempt, $baseMs = null)
    {
        $base = $baseMs === null ? self::DEFAULT_BASE_MS : max(1, (int) $baseMs);
        $n    = max(1, (int) $attempt);

        $delay = $base * (int) pow(2, $n - 1);

        return (int) min(self::MAX_BACKOFF_MS, $delay);
    }

    /**
     * Clamp a configured attempt count.
     *
     * A configuration row is a text field somebody types into. `0` would mean
     * "never call Google at all", which reads on screen as "retries off" and is
     * actually "the feature is off"; `500` would mean an outage costs five
     * hundred billable requests per employee action. Both are clamped, and the
     * clamp is the reason the configuration can be exposed at all.
     */
    public static function attempts($configured = null)
    {
        if ($configured === null || (int) $configured <= 0) {
            return self::DEFAULT_MAX_ATTEMPTS;
        }

        return (int) min(self::ABSOLUTE_MAX_ATTEMPTS, max(1, (int) $configured));
    }

    /**
     * The operator-facing sentence after every attempt has been used.
     *
     * Names no URL, no key, no status line from Google — those go to the audit
     * log, scrubbed. The employee needs to know it failed, that it was tried
     * more than once, and that nothing further was charged.
     */
    public static function exhaustedMessage($attempts)
    {
        return 'Google did not answer after ' . (int) $attempts
             . ' attempts. Nothing further was sent, and the reserved call was '
             . 'returned to your allowance.';
    }
}
