<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Contract_failures.php';

/**
 * Contract_circuit
 *
 * Provider health, and the decision to stop calling a provider that is not
 * answering.
 *
 * WHY A BREAKER RATHER THAN JUST RETRIES
 * --------------------------------------
 * Retry policy already exists in Contract_failures and handles a single
 * operation. What it cannot see is the pattern ACROSS operations: when a
 * provider is down, every request in the queue independently discovers this by
 * timing out, and each one holds a PHP worker for the full timeout while doing
 * so. Thirty contracts waiting on a thirty-second timeout is fifteen minutes of
 * worker time spent learning something the first one already established.
 *
 * The breaker makes that knowledge shared: once enough consecutive failures have
 * accumulated, calls are refused immediately with a named reason rather than
 * attempted. After a cooling period one call is let through to test the water,
 * and its outcome either restores service or starts the clock again.
 *
 * THE RULE THAT MATTERS MOST HERE
 * -------------------------------
 * An OPEN breaker must never be read as "the operation failed". It means the
 * operation was NOT ATTEMPTED. Conflating the two would let a cancelled send be
 * recorded as a provider rejection, and would let a reconciliation job conclude
 * that a request does not exist at the provider when it was simply never asked.
 *
 * So `refusal()` returns `attempted => false`, and the model writes that into
 * the audit trail verbatim.
 *
 * WHAT DOES NOT TRIP THE BREAKER
 * ------------------------------
 * A provider answering "no" is a working provider. Invalid credentials, a bad
 * workflow id, a rejected signer — these are decisions, and repeating them by
 * opening a breaker would hide a configuration problem behind an outage
 * message. Only failures to REACH the provider count.
 *
 * Pure: no database, no clock of its own, no I/O. State is passed in.
 */
class Contract_circuit
{
    const CLOSED    = 'closed';     /* normal */
    const OPEN      = 'open';       /* refusing */
    const HALF_OPEN = 'half_open';  /* one trial call permitted */

    /** Consecutive reachability failures before the breaker opens. */
    const FAILURE_THRESHOLD = 5;

    /** How long it stays open before allowing a trial call, in seconds. */
    const COOLDOWN_SECONDS = 120;

    /** Consecutive successes in half-open before closing again. */
    const RECOVERY_SUCCESSES = 2;

    /**
     * Failures that mean "we could not reach the provider".
     *
     * Everything else is the provider working correctly and giving an answer we
     * did not like.
     *
     * @return array
     */
    public static function reachabilityFailures()
    {
        return array(
            Contract_failures::F_TIMEOUT,
            Contract_failures::F_RATE_LIMITED,
        );
    }

    /**
     * @param  string $failure
     * @return bool
     */
    public static function countsAgainstHealth($failure)
    {
        return in_array($failure, self::reachabilityFailures(), true);
    }

    /**
     * The breaker's state, derived from what has been recorded.
     *
     * Derived rather than stored, so there is no separate state field to drift
     * out of step with the counters that justify it.
     *
     * @param  array $h {consecutive_failures, opened_at, half_open_successes}
     * @param  int   $now
     * @return string
     */
    public static function state(array $h, $now)
    {
        $openedAt = (int) self::pick($h, 'opened_at', 0);

        if ($openedAt <= 0) { return self::CLOSED; }

        if (((int) $now - $openedAt) < self::COOLDOWN_SECONDS) { return self::OPEN; }

        return self::HALF_OPEN;
    }

    /**
     * May a call be made right now?
     *
     * @param  array $h
     * @param  int   $now
     * @return array {allowed, state, reason, retry_after}
     */
    public static function mayCall(array $h, $now)
    {
        $state = self::state($h, $now);

        if ($state === self::CLOSED) {
            return array('allowed' => true, 'state' => $state,
                         'reason' => 'healthy', 'retry_after' => 0);
        }

        if ($state === self::HALF_OPEN) {
            /* Exactly one trial call at a time. */
            return array('allowed' => true, 'state' => $state,
                         'reason' => 'trial_call', 'retry_after' => 0);
        }

        $openedAt = (int) self::pick($h, 'opened_at', 0);
        $wait     = self::COOLDOWN_SECONDS - ((int) $now - $openedAt);

        return array(
            'allowed'     => false,
            'state'       => self::OPEN,
            'reason'      => 'circuit_open',
            'retry_after' => max(1, $wait),
        );
    }

    /**
     * What a refused call looks like.
     *
     * `attempted => false` is the important field. An open breaker means the
     * provider was never asked, and nothing downstream may record this as the
     * provider having refused, failed or answered.
     *
     * @param  int $retryAfter
     * @return array
     */
    public static function refusal($retryAfter)
    {
        return array(
            'ok'        => false,
            'attempted' => false,
            'reason'    => Contract_failures::F_TIMEOUT,
            'circuit'   => self::OPEN,
            'retry_after' => (int) $retryAfter,
            'message'   => 'The signing provider has not been responding, so this was not sent. '
                         . 'It will be retried automatically — nothing was lost and nothing was '
                         . 'duplicated.',
            'detail'    => 'Circuit open: the call was NOT attempted. This is not a provider '
                         . 'rejection and must not be recorded as one.',
        );
    }

    /**
     * Health after a successful call.
     *
     * @param  array $h
     * @param  int   $now
     * @return array
     */
    public static function recordSuccess(array $h, $now)
    {
        $state = self::state($h, $now);

        if ($state === self::HALF_OPEN) {
            $successes = (int) self::pick($h, 'half_open_successes', 0) + 1;

            if ($successes >= self::RECOVERY_SUCCESSES) {
                return self::healthy($now);
            }

            /* Still proving itself: stay half-open, keep the opened_at stamp so
               the state does not silently revert to closed. */
            return array(
                'consecutive_failures' => 0,
                'opened_at'            => (int) self::pick($h, 'opened_at', 0),
                'half_open_successes'  => $successes,
                'last_success_at'      => (int) $now,
                'last_failure_at'      => (int) self::pick($h, 'last_failure_at', 0),
            );
        }

        return self::healthy($now);
    }

    /**
     * Health after a failure.
     *
     * A failure while half-open reopens immediately, without waiting to
     * re-accumulate the threshold. The trial call existed to answer one
     * question, and it answered it.
     *
     * @param  array  $h
     * @param  string $failure
     * @param  int    $now
     * @return array
     */
    public static function recordFailure(array $h, $failure, $now)
    {
        if (!self::countsAgainstHealth($failure)) {
            /*
             * A provider that says "no" is a provider that is up. Counting a
             * rejected signer or a bad workflow id towards an outage would hide
             * a configuration error behind an outage banner, and the banner
             * would clear on its own without anybody fixing anything.
             */
            return array(
                'consecutive_failures' => (int) self::pick($h, 'consecutive_failures', 0),
                'opened_at'            => (int) self::pick($h, 'opened_at', 0),
                'half_open_successes'  => (int) self::pick($h, 'half_open_successes', 0),
                'last_success_at'      => (int) self::pick($h, 'last_success_at', 0),
                'last_failure_at'      => (int) $now,
            );
        }

        $state = self::state($h, $now);

        if ($state === self::HALF_OPEN) {
            return array(
                'consecutive_failures' => (int) self::pick($h, 'consecutive_failures', 0) + 1,
                'opened_at'            => (int) $now,
                'half_open_successes'  => 0,
                'last_success_at'      => (int) self::pick($h, 'last_success_at', 0),
                'last_failure_at'      => (int) $now,
            );
        }

        $failures = (int) self::pick($h, 'consecutive_failures', 0) + 1;
        $openedAt = (int) self::pick($h, 'opened_at', 0);

        if ($failures >= self::FAILURE_THRESHOLD && $openedAt <= 0) {
            $openedAt = (int) $now;
        }

        return array(
            'consecutive_failures' => $failures,
            'opened_at'            => $openedAt,
            'half_open_successes'  => 0,
            'last_success_at'      => (int) self::pick($h, 'last_success_at', 0),
            'last_failure_at'      => (int) $now,
        );
    }

    /**
     * A health summary for the admin screen.
     *
     * @param  array $h
     * @param  int   $now
     * @return array {state, label, healthy, detail, retry_after}
     */
    public static function summary(array $h, $now)
    {
        $state = self::state($h, $now);
        $call  = self::mayCall($h, $now);

        $labels = array(
            self::CLOSED    => 'Healthy',
            self::OPEN      => 'Not responding',
            self::HALF_OPEN => 'Recovering',
        );

        $details = array(
            self::CLOSED    => 'The provider is answering normally.',
            self::OPEN      => 'The provider has failed to respond repeatedly. Requests are being '
                             . 'held rather than sent, so nothing is lost or duplicated while it is down.',
            self::HALF_OPEN => 'Testing whether the provider has recovered. One request at a time '
                             . 'is being allowed through.',
        );

        return array(
            'state'       => $state,
            'label'       => $labels[$state],
            'healthy'     => $state === self::CLOSED,
            'detail'      => $details[$state],
            'retry_after' => (int) $call['retry_after'],
            'consecutive_failures' => (int) self::pick($h, 'consecutive_failures', 0),
            'last_success_at'      => (int) self::pick($h, 'last_success_at', 0),
            'last_failure_at'      => (int) self::pick($h, 'last_failure_at', 0),
        );
    }

    /* ---- helpers ---------------------------------------------------------- */

    private static function healthy($now)
    {
        return array(
            'consecutive_failures' => 0,
            'opened_at'            => 0,
            'half_open_successes'  => 0,
            'last_success_at'      => (int) $now,
            'last_failure_at'      => 0,
        );
    }

    private static function pick($a, $k, $default)
    {
        if (is_array($a) && array_key_exists($k, $a)) { return $a[$k]; }

        return $default;
    }
}
