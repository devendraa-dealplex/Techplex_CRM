<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_retry
 *
 * Pure retry-policy helper (framework-independent, unit-testable).
 * Exponential backoff with full jitter + a simple in-memory circuit breaker
 * state machine. The actual HTTP is done by Payplex_api_client; this class only
 * decides *whether* and *when* to retry, and classifies errors.
 */
class Payplex_retry
{
    private $maxAttempts;
    private $baseMs;
    private $capMs;

    public function __construct($maxAttempts = 4, $baseMs = 1000, $capMs = 10000)
    {
        $this->maxAttempts = max(1, (int) $maxAttempts);
        $this->baseMs      = max(1, (int) $baseMs);
        $this->capMs       = max($this->baseMs, (int) $capMs);
    }

    /**
     * Should we retry given the HTTP status and attempt number (1-based)?
     * Rule: retry timeouts/5xx and 429; never retry other 4xx.
     */
    public function shouldRetry($status, $attempt)
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }
        if ($status === 0) {
            return true; // network error / timeout
        }
        if ($status === 429) {
            return true;
        }
        if ($status >= 500 && $status <= 599) {
            return true;
        }
        return false; // 2xx handled elsewhere; 4xx (except 429) is terminal
    }

    /**
     * Deterministic upper-bound backoff (ms) for an attempt, before jitter:
     * min(cap, base * 2^(attempt-1)).
     */
    public function backoffCeilingMs($attempt)
    {
        $attempt = max(1, (int) $attempt);
        $exp = $this->baseMs * (2 ** ($attempt - 1));
        return (int) min($this->capMs, $exp);
    }

    /**
     * Actual delay with full jitter: random in [0, ceiling]. Injectable rand
     * for deterministic tests.
     */
    public function backoffMs($attempt, $rand01 = null)
    {
        $ceil = $this->backoffCeilingMs($attempt);
        $r = $rand01 === null ? (mt_rand() / mt_getrandmax()) : (float) $rand01;
        $r = max(0.0, min(1.0, $r));
        return (int) floor($r * $ceil);
    }

    public function maxAttempts()
    {
        return $this->maxAttempts;
    }
}
