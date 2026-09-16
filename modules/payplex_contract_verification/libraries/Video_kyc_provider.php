<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * VideoKycProvider
 *
 * The boundary between this module and whichever Video KYC service performs
 * identity verification.
 *
 * WHY THIS IS A SEPARATE INTERFACE FROM THE SIGNING PROVIDER
 * ----------------------------------------------------------
 * Leegality does not do Video KYC. That was established by reading their
 * documentation rather than assumed: the Document Execution API documents no
 * session creation, no liveness step, no officer review and no KYC webhook.
 *
 * What Leegality DOES document — Face Match, Capture Photo, Smart and Manual
 * User Liveliness, GPS capture — are invitee-level security steps INSIDE the
 * signing ceremony. They are useful, and they are not V-CIP. Treating them as
 * Video KYC would be relabelling a photo check as a regulated verification.
 *
 * So verification is a second provider, behind its own interface, and a
 * contract requiring it simply cannot complete until one exists. That is a
 * feature: the alternative is a system that reports verified identities it never
 * verified.
 *
 * WHAT AN IMPLEMENTATION MUST NEVER DO
 * ------------------------------------
 * Return `passed` for anything a human asserted. The decision on this interface
 * belongs to the provider, and `Contract_lifecycle` refuses `kyc_passed` from
 * any staff actor precisely so that an implementation cannot be talked into it
 * later by a well-meaning operator with a stuck contract.
 *
 * EVERY METHOD RETURNS A NAMED OUTCOME
 * ------------------------------------
 *     array('ok' => bool, 'reason' => string, ...)
 *
 * Never a bare false, never an empty array meaning failure, never an exception
 * for an ordinary refusal.
 */
interface VideoKycProvider
{
    /**
     * Can this provider act at all, and if not, what is missing?
     *
     * @return array {implemented, usable, reason, detail, missing}
     */
    public function implementationStatus();

    /**
     * Is the provider reachable and are the credentials accepted?
     *
     * @return array {ok, reason, detail}
     */
    public function healthCheck();

    /**
     * Create a verification session for one signer on one contract.
     *
     * @param  array $signer  {name, email, mobile, reference}
     * @param  array $context {contract_id, request_id, purpose, policy}
     * @return array {ok, reason, session_reference, expires_at}
     */
    public function createSession(array $signer, array $context);

    /**
     * The provider's own invitation URL for a session.
     *
     * Separate from createSession because a link may need reissuing without a
     * new session, and because the link is a bearer credential: it is never
     * written to the audit trail and never logged.
     *
     * @param  string $sessionReference
     * @return array {ok, reason, url, expires_at}
     */
    public function invitationUrl($sessionReference);

    /**
     * The provider's authoritative view of a session.
     *
     * This is what the completion gate trusts. A webhook is a prompt to call
     * this, never a substitute for it.
     *
     * @param  string $sessionReference
     * @return array {ok, reason, state, decision, decided_at, reject_reason}
     */
    public function getSessionStatus($sessionReference);

    /**
     * Verify an inbound webhook against the provider's own scheme.
     *
     * Takes the RAW body, because a signature is over the bytes that were sent.
     *
     * @param  string $rawPayload
     * @param  array  $headers
     * @return bool
     */
    public function verifyWebhook($rawPayload, array $headers);

    /**
     * Evidence this provider permits us to retrieve and retain.
     *
     * Deliberately not "all evidence". A V-CIP recording contains biometric and
     * identity-document data whose retention is governed by the provider's
     * contract and by DPDP; an integration that pulls everything available by
     * default is an integration that has made a retention decision nobody took.
     *
     * @param  string $sessionReference
     * @return array {ok, reason, items:[{type, bytes, content_type, sha256}]}
     */
    public function retrieveEvidence($sessionReference);

    /**
     * Allow a signer another attempt.
     *
     * @param  string $sessionReference
     * @param  string $reason
     * @return array {ok, reason, session_reference}
     */
    public function retrySession($sessionReference, $reason);

    /**
     * Refer a session to a human reviewer at the provider.
     *
     * @param  string $sessionReference
     * @param  string $reason
     * @return array {ok, reason}
     */
    public function sendForManualReview($sessionReference, $reason);

    /**
     * End a session without a decision.
     *
     * @param  string $sessionReference
     * @param  string $reason
     * @return array {ok, reason}
     */
    public function cancelSession($sessionReference, $reason);

    /**
     * The capabilities this provider actually offers.
     *
     * Asked rather than assumed, so the screen shows what will really happen
     * instead of a fixed list of features the account may not have. A capability
     * absent from this map is not attempted and is not claimed anywhere in the
     * interface.
     *
     * @return array capability => bool
     */
    public function capabilities();
}
