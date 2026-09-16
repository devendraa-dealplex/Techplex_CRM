<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * ContractSigningProvider
 *
 * The boundary between this module and whichever e-signature service it talks
 * to. Nothing outside the adapter that implements this interface knows the
 * provider's name, its endpoints, its field names or its event vocabulary.
 *
 * WHY THE METHOD NAMES ARE OURS
 * -----------------------------
 * These are internal names describing what the CRM needs done. They are
 * deliberately NOT assumed to correspond one-to-one with provider endpoints —
 * `createSigningRequest()` may turn out to be three calls or one, and that is
 * the adapter's problem, not the caller's.
 *
 * EVERY METHOD RETURNS A NAMED OUTCOME
 * ------------------------------------
 * Never an empty array to mean "something went wrong", never a bare false, and
 * never an exception for an ordinary refusal. Each returns at least:
 *
 *     array('ok' => bool, 'reason' => string, ...)
 *
 * where `reason` on failure is a key from Contract_failures. A caller can then
 * decide whether to retry without parsing an error message.
 */
interface ContractSigningProvider
{
    /**
     * Prove the stored credentials work, without revealing them.
     *
     * @return array {ok, reason, detail}
     */
    public function testConnection(): array;

    /**
     * Create a signing request from a prepared PDF, its signers and its mapped
     * signature fields.
     *
     * MUST be idempotent on `$operationReference`: calling twice with the same
     * reference returns the same request rather than creating a second one.
     *
     * @param  int    $contractId
     * @param  string $pdfPath
     * @param  array  $signers
     * @param  array  $fields
     * @return array {ok, reason, external_request_id, operation_reference}
     */
    public function createSigningRequest(
        int $contractId,
        string $pdfPath,
        array $signers,
        array $fields
    ): array;

    /**
     * The provider's authoritative view of a request.
     *
     * This is what reconciliation trusts. A webhook is a prompt to call this,
     * never a substitute for it.
     *
     * @param  string $externalRequestId
     * @return array {ok, reason, state, signers}
     */
    public function getRequestStatus(string $externalRequestId): array;

    /**
     * A signing link for one signer.
     *
     * The link is never persisted in the audit trail and never logged: it is a
     * bearer credential for that signer's identity.
     *
     * @param  string $externalRequestId
     * @param  string $signerReference  opaque; never a database id
     * @return array {ok, reason, url, expires_at}
     */
    public function getSigningLink(
        string $externalRequestId,
        string $signerReference
    ): array;

    /**
     * The final signed PDF.
     *
     * @param  string $externalRequestId
     * @return array {ok, reason, bytes, content_type, sha256}
     */
    public function downloadSignedDocument(
        string $externalRequestId
    ): array;

    /**
     * The completion / audit certificate.
     *
     * @param  string $externalRequestId
     * @return array {ok, reason, bytes, content_type, sha256}
     */
    public function downloadCompletionCertificate(
        string $externalRequestId
    ): array;

    /**
     * Withdraw a live request, with a stated reason.
     *
     * @param  string $externalRequestId
     * @param  string $reason
     * @return array {ok, reason}
     */
    public function cancelRequest(
        string $externalRequestId,
        string $reason
    ): array;

    /**
     * Verify an inbound webhook against the provider's own scheme.
     *
     * Takes the RAW body, because a signature is over the bytes that were sent.
     * A body that has been decoded and re-encoded will not verify — JSON
     * round-tripping changes key order, number formatting and escaping.
     *
     * @param  string $rawPayload
     * @param  array  $headers
     * @return bool
     */
    public function verifyWebhook(
        string $rawPayload,
        array $headers
    ): bool;
}
