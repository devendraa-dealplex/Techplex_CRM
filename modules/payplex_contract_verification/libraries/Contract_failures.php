<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Contract_failures
 *
 * Every way this integration can fail, what an operator should see, and
 * whether retrying is sensible or actively harmful.
 *
 * WHY RETRY POLICY IS DATA AND NOT A CATCH BLOCK
 * ----------------------------------------------
 * The costly mistake in this class of integration is retrying a request that
 * the provider ALREADY ACCEPTED. A timeout does not mean "nothing happened" —
 * it means "we stopped listening". If the signing request was created before
 * the socket gave up, a naive retry creates a second one, and the customer gets
 * two links to sign the same contract. One of them produces an executed
 * agreement nobody is tracking.
 *
 * So every failure below declares whether it is retryable, and a retryable
 * failure must reuse the SAME internal operation reference, so the second
 * attempt can be recognised as the same attempt.
 *
 * The other rule: a response the provider SENT is a decision. Asking again gets
 * the same decision and is charged again. Only a failure to reach the provider,
 * a rate limit, or a server-side error is worth repeating.
 *
 * Pure: no I/O, no logging of its own.
 */
class Contract_failures
{
    const F_INVALID_CREDENTIALS   = 'invalid_credentials';
    const F_TIMEOUT               = 'api_timeout';
    const F_RATE_LIMITED          = 'rate_limited';
    const F_INSUFFICIENT_BALANCE  = 'insufficient_balance';
    const F_INVALID_WORKFLOW      = 'invalid_workflow_id';
    const F_INVALID_SIGNER        = 'invalid_signer_details';
    const F_UNSUPPORTED_METHOD    = 'unsupported_signing_method';
    const F_UPLOAD_FAILED         = 'pdf_upload_failed';
    const F_BAD_COORDINATES       = 'invalid_signature_coordinates';
    const F_LINK_FAILED           = 'signing_link_creation_failed';
    const F_WEBHOOK_FAILED        = 'webhook_processing_failed';
    const F_EXPIRED_OR_DECLINED   = 'request_expired_or_declined';
    const F_DOWNLOAD_FAILED       = 'final_document_download_failed';
    const F_PROVIDER_UNAVAILABLE  = 'provider_not_configured';

    /**
     * Every failure, with what it means, what to do, and whether to retry.
     *
     * `operator` is written for the person looking at the screen, not for a
     * developer reading a log. It says what happened and what to do next,
     * because "Error 500" tells somebody to raise a ticket and nothing else.
     *
     * `retryable` false means a retry cannot help and may cause harm.
     * `may_have_succeeded` marks the failures where the provider may already
     * have acted — those retry only with the original operation reference.
     *
     * @return array
     */
    public static function catalogue()
    {
        return array(
            self::F_INVALID_CREDENTIALS => array(
                'retryable' => false, 'may_have_succeeded' => false, 'alert_admin' => true,
                'operator' => 'The signing provider rejected our credentials. Nothing was sent. '
                            . 'An administrator needs to check the connection settings.',
            ),
            self::F_TIMEOUT => array(
                'retryable' => true, 'may_have_succeeded' => true, 'alert_admin' => false,
                'operator' => 'The signing provider did not answer in time. The request may or may '
                            . 'not have been created — we will check before sending anything again, '
                            . 'so the customer cannot receive two links.',
            ),
            self::F_RATE_LIMITED => array(
                'retryable' => true, 'may_have_succeeded' => false, 'alert_admin' => false,
                'operator' => 'The signing provider is asking us to slow down. This will be retried '
                            . 'automatically in a moment.',
            ),
            self::F_INSUFFICIENT_BALANCE => array(
                'retryable' => false, 'may_have_succeeded' => false, 'alert_admin' => true,
                'operator' => 'The signing account has no credits left. Nothing was sent. '
                            . 'Top up the account and try again.',
            ),
            self::F_INVALID_WORKFLOW => array(
                'retryable' => false, 'may_have_succeeded' => false, 'alert_admin' => true,
                'operator' => 'The configured workflow was not recognised by the provider. '
                            . 'Nothing was sent. An administrator needs to correct the workflow ID.',
            ),
            self::F_INVALID_SIGNER => array(
                'retryable' => false, 'may_have_succeeded' => false, 'alert_admin' => false,
                'operator' => 'The provider refused one of the signers. Check the name, email and '
                            . 'mobile number on the contract, then send again.',
            ),
            self::F_UNSUPPORTED_METHOD => array(
                'retryable' => false, 'may_have_succeeded' => false, 'alert_admin' => true,
                'operator' => 'The selected signature method is not enabled on this account. '
                            . 'Nothing was sent. Choose a different method or have it enabled.',
            ),
            self::F_UPLOAD_FAILED => array(
                'retryable' => true, 'may_have_succeeded' => true, 'alert_admin' => false,
                'operator' => 'The contract PDF did not upload. Nothing has been sent to the '
                            . 'customer. This will be retried.',
            ),
            self::F_BAD_COORDINATES => array(
                'retryable' => false, 'may_have_succeeded' => false, 'alert_admin' => false,
                'operator' => 'One or more signature fields are not on the page they claim to be. '
                            . 'Nothing was sent. Open Preview Signature Fields and reposition them.',
            ),
            self::F_LINK_FAILED => array(
                'retryable' => true, 'may_have_succeeded' => false, 'alert_admin' => false,
                'operator' => 'The signing request exists but a signing link could not be issued. '
                            . 'Use Copy/Resend Signing Link to try again.',
            ),
            self::F_WEBHOOK_FAILED => array(
                'retryable' => true, 'may_have_succeeded' => false, 'alert_admin' => true,
                'operator' => 'An update from the signing provider could not be processed. '
                            . 'The contract status is being confirmed directly with the provider.',
            ),
            self::F_EXPIRED_OR_DECLINED => array(
                'retryable' => false, 'may_have_succeeded' => false, 'alert_admin' => false,
                'operator' => 'The signing request is over — it expired or a signer declined. '
                            . 'A new request has to be created; this one cannot be revived.',
            ),
            self::F_DOWNLOAD_FAILED => array(
                'retryable' => true, 'may_have_succeeded' => false, 'alert_admin' => true,
                'operator' => 'The signed document could not be downloaded. The contract stays '
                            . 'un-executed until it is retrieved and verified — this is retried '
                            . 'automatically.',
            ),
            self::F_PROVIDER_UNAVAILABLE => array(
                'retryable' => false, 'may_have_succeeded' => false, 'alert_admin' => true,
                'operator' => 'The signing provider is not configured for use yet. Nothing was sent.',
            ),
        );
    }

    /**
     * @return array failure keys
     */
    public static function all()
    {
        return array_keys(self::catalogue());
    }

    /**
     * @param  string $failure
     * @return bool
     */
    public static function isFailure($failure)
    {
        return is_string($failure) && array_key_exists($failure, self::catalogue());
    }

    /**
     * Should this be retried, and under what conditions?
     *
     * Fails closed: an unrecognised failure is not retried. A retry loop that
     * treats "I do not know what went wrong" as "try again" is how a transient
     * outage becomes a duplicated signing request.
     *
     * @param  string $failure
     * @param  int    $attempt   1-based
     * @param  int    $maxRetries
     * @return array {retry, reason, must_reuse_reference}
     */
    public static function shouldRetry($failure, $attempt, $maxRetries)
    {
        $c = self::catalogue();

        if (!isset($c[$failure])) {
            return array('retry' => false, 'reason' => 'unrecognised_failure',
                         'must_reuse_reference' => true);
        }

        if (empty($c[$failure]['retryable'])) {
            return array('retry' => false, 'reason' => 'not_retryable',
                         'must_reuse_reference' => true);
        }

        if ((int) $attempt > (int) $maxRetries) {
            return array('retry' => false, 'reason' => 'retries_exhausted',
                         'must_reuse_reference' => true);
        }

        return array(
            'retry'  => true,
            'reason' => 'retryable',
            /*
             * Always true, even where the provider certainly did nothing. A
             * rule with an exception is a rule somebody applies the exception
             * to by mistake, and the cost of reusing a reference unnecessarily
             * is nil.
             */
            'must_reuse_reference' => true,
        );
    }

    /**
     * Failures after which the provider's state must be checked before acting.
     *
     * @return array
     */
    public static function requireReconciliationBeforeRetry()
    {
        $out = array();

        foreach (self::catalogue() as $k => $meta) {
            if (!empty($meta['may_have_succeeded'])) { $out[] = $k; }
        }

        return $out;
    }

    /**
     * The message an operator sees.
     *
     * Never the provider's raw error text: that is where API keys, internal
     * hostnames and stack traces travel. The provider's response is recorded in
     * the audit trail, redacted; the screen gets the sentence above.
     *
     * @param  string $failure
     * @return string
     */
    public static function operatorMessage($failure)
    {
        $c = self::catalogue();

        if (isset($c[$failure])) { return $c[$failure]['operator']; }

        return 'Something went wrong talking to the signing provider. Nothing was sent to the '
             . 'customer. The details are in the contract audit trail.';
    }

    /**
     * @param  string $failure
     * @return bool
     */
    public static function alertsAdministrator($failure)
    {
        $c = self::catalogue();

        return isset($c[$failure]) ? (bool) $c[$failure]['alert_admin'] : true;
    }

    /**
     * Backoff in milliseconds before attempt N.
     *
     * Exponential with a ceiling. A person is waiting, so the ceiling is low;
     * the scheduled reconciliation job is what covers a longer outage.
     *
     * @param  int $attempt 1-based
     * @param  int $baseMs
     * @return int
     */
    public static function backoffMs($attempt, $baseMs = 500)
    {
        $attempt = max(1, (int) $attempt);
        $ms      = (int) $baseMs * (1 << min($attempt - 1, 4));

        return min($ms, 8000);
    }
}
