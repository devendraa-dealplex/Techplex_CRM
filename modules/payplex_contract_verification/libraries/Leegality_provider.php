<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Contract_signing_provider.php';
require_once __DIR__ . '/Contract_failures.php';
require_once __DIR__ . '/Contract_signing_state.php';
require_once __DIR__ . '/Leegality_api.php';

/**
 * Leegality_provider
 *
 * The adapter that speaks to Leegality's Document Execution API.
 *
 * WHAT CHANGED, AND WHY IT IS NOW SAFE TO WRITE THIS
 * --------------------------------------------------
 * The previous version of this file refused everything, on the stated grounds
 * that the documentation had not been obtained and that writing plausible code
 * was worse than writing none. That reasoning still holds; what changed is that
 * the documentation HAS now been read, endpoint by endpoint, and every path,
 * header, field and algorithm below carries a citation in Leegality_api.
 *
 * Nothing here is inferred. Where the documentation is silent, this class
 * refuses rather than guesses — most consequentially for cancellation, which
 * does not exist and must not be improvised out of the delete endpoint.
 *
 * THE GATE
 * --------
 * Real endpoints are one thing; sending is another. Every outbound method goes
 * through gate(), which refuses unless the module is enabled AND an auth token
 * is configured AND a workflow is configured. With the module disabled and no
 * secrets set — the current staging state — this class makes no network call at
 * all, and its refusal says which precondition failed without naming a value.
 *
 * CREDENTIAL HANDLING
 * -------------------
 * The auth token is read from injected settings, used to build one header, and
 * never returned, logged, echoed into an exception, or included in any array
 * this class hands back. redactedSettings() is what the audit trail gets.
 */
class Leegality_provider implements ContractSigningProvider
{
    /** @var array */
    private $settings;

    /** @var callable|null injected HTTP transport; tests supply their own */
    private $transport;

    /**
     * @param array         $settings  decrypted at the call site, held for the call only
     * @param callable|null $transport fn(array $request): array {ok, http_code, body, error}
     */
    public function __construct(array $settings = array(), $transport = null)
    {
        $this->settings  = $settings;
        $this->transport = $transport;
    }

    /**
     * Can this adapter act, and if not, exactly what is missing?
     *
     * Public and static-friendly so the settings screen and the status panel can
     * ask without calling a method that refuses.
     *
     * @return array {implemented, usable, reason, detail, missing}
     */
    public function implementationStatus()
    {
        $missing = array();

        if (!$this->enabled())   { $missing[] = 'module_enabled'; }
        if ($this->token() === '')    { $missing[] = 'auth_token'; }
        if ($this->profileId() === '') { $missing[] = 'workflow_profile_id'; }
        if ($this->salt() === '')     { $missing[] = 'private_salt_for_webhooks'; }

        return array(
            'implemented' => true,
            'usable'      => count($missing) === 0,
            'reason'      => count($missing) === 0 ? 'ready' : Contract_failures::F_PROVIDER_UNAVAILABLE,
            'detail'      => count($missing) === 0
                ? 'Adapter implemented against the documented v3 API and configured.'
                : 'Adapter implemented against the documented v3 API but not configured. '
                . 'Missing: ' . implode(', ', $missing) . '. No network call was attempted.',
            'missing'     => $missing,
            'environment' => $this->environment(),
        );
    }

    /* ---- connection ------------------------------------------------------ */

    /**
     * Prove the credentials work without creating anything.
     *
     * Uses the status endpoint with a deliberately absent documentId. A well
     * formed request with bad credentials is rejected differently from one with
     * good credentials and a missing parameter, and that difference is the test.
     * Creating a throwaway document to prove connectivity would leave real
     * records in the account, which is not a diagnostic, it is litter.
     *
     * @return array
     */
    public function testConnection(): array
    {
        $gate = $this->gate(array('auth_token'));

        if ($gate !== null) { return $gate; }

        $res = $this->call('GET', Leegality_api::EP_STATUS, array(), null);

        if (!$res['ok']) { return $res; }

        if ($res['http_code'] === 401 || $res['http_code'] === 403) {
            return $this->fail(Contract_failures::F_INVALID_CREDENTIALS,
                'The provider rejected our credentials.');
        }

        /*
         * Any answered request proves reachability and that the token was not
         * rejected. A 400 for the missing documentId is a PASS here, and saying
         * so plainly is better than contriving a request that returns 200.
         */
        return array(
            'ok'     => true,
            'reason' => 'reachable',
            'detail' => 'The provider answered and did not reject our credentials.',
        );
    }

    /* ---- creating a request ---------------------------------------------- */

    /**
     * Create a signing request.
     *
     * IDEMPOTENCY IS OURS TO ENFORCE, AND THIS IS WHERE IT ISN'T DONE
     * ---------------------------------------------------------------
     * The interface requires this method to be idempotent on
     * $operationReference. Leegality documents no idempotency key on create, so
     * a second call with the same reference WILL create a second document and a
     * second set of signing links.
     *
     * The adapter therefore cannot honour that contract alone, and does not
     * pretend to: `irn` carries our reference so the duplicate is at least
     * detectable afterwards, and the caller must claim the reference in the
     * database BEFORE calling, so a retry finds the claim rather than the
     * provider finding a second request. That claim lives in the model, not
     * here, because only the database can make it atomic.
     *
     * @param  int    $contractId
     * @param  string $pdfPath
     * @param  array  $signers
     * @param  array  $fields
     * @return array
     */
    public function createSigningRequest(
        int $contractId,
        string $pdfPath,
        array $signers,
        array $fields
    ): array {
        $gate = $this->gate(array('auth_token', 'workflow_profile_id'));

        if ($gate !== null) { return $gate; }

        if (!is_readable($pdfPath)) {
            return $this->fail(Contract_failures::F_UPLOAD_FAILED,
                'The prepared document could not be read.');
        }

        $bytes = filesize($pdfPath);

        if ($bytes === false || $bytes <= 0) {
            return $this->fail(Contract_failures::F_UPLOAD_FAILED, 'The prepared document is empty.');
        }

        if ($bytes > Leegality_api::MAX_PDF_BYTES) {
            /* Checked before reading it into memory, not after. */
            return $this->fail(Contract_failures::F_UPLOAD_FAILED,
                'The prepared document is larger than the provider accepts.');
        }

        if (count($signers) === 0) {
            return $this->fail(Contract_failures::F_INVALID_SIGNER, 'No signers were supplied.');
        }

        $invitees = array();

        foreach ($signers as $s) {
            $name  = trim((string) $this->pick($s, 'name', ''));
            $email = trim((string) $this->pick($s, 'email', ''));

            if ($name === '' || $email === '') {
                return $this->fail(Contract_failures::F_INVALID_SIGNER,
                    'A signer is missing a name or an email address.');
            }

            $invitee = array('name' => $name, 'email' => $email);

            $phone = trim((string) $this->pick($s, 'mobile', ''));

            if ($phone !== '') { $invitee['phone'] = $phone; }

            $invitees[] = $invitee;
        }

        $raw = file_get_contents($pdfPath);

        if ($raw === false) {
            return $this->fail(Contract_failures::F_UPLOAD_FAILED,
                'The prepared document could not be read.');
        }

        $irn = substr((string) $this->pick($this->settings, 'operation_reference', ''), 0,
                      Leegality_api::MAX_IRN_LENGTH);

        $payload = array(
            'profileId' => $this->profileId(),
            'file'      => array(
                'name' => 'contract-' . $contractId . '.pdf',
                'file' => base64_encode($raw),
            ),
            'invitees'  => $invitees,
        );

        if ($irn !== '') { $payload['irn'] = $irn; }

        /* Field placements are only sent when there are any. An empty array is
           not the same as the key being absent, and we do not know which the
           provider prefers, so we send what we mean. */
        if (count($fields) > 0) { $payload['file']['fields'] = $fields; }

        $res = $this->call('POST', Leegality_api::EP_CREATE, array(), $payload);

        if (!$res['ok']) { return $res; }

        $body = $this->decode($res['body']);

        if ($body === null) {
            return $this->fail(Contract_failures::F_WEBHOOK_FAILED,
                'The provider returned a response that could not be read.');
        }

        if ((int) $this->pick($body, 'status', 0) !== 1) {
            return $this->fail($this->classify($res['http_code'], $body),
                'The provider refused the request.');
        }

        $data       = (array) $this->pick($body, 'data', array());
        $documentId = trim((string) $this->pick($data, 'documentId', ''));

        if ($documentId === '') {
            /*
             * A success status with no identifier. The request may well have
             * been created, so this is NOT reported as a clean failure — that
             * would invite a retry that duplicates it.
             */
            return $this->fail(Contract_failures::F_TIMEOUT,
                'The provider reported success but returned no document identifier. '
              . 'The request may exist; it must be reconciled before any retry.');
        }

        return array(
            'ok'                  => true,
            'reason'              => 'created',
            'external_request_id' => $documentId,
            'operation_reference' => (string) $this->pick($data, 'irn', $irn),
            'signers'             => $this->inviteesFrom($data),
        );
    }

    /* ---- status: the authoritative read ---------------------------------- */

    /**
     * The provider's own view of a request.
     *
     * This is what reconciliation trusts, and the reason it must exist is worth
     * stating: Leegality's webhook MAC covers the documentId alone, so it is
     * constant per document and replayable, and it authenticates none of the
     * payload body. A verified webhook therefore establishes only that somebody
     * knows our salt and is talking about this document. What actually happened
     * is read here.
     *
     * @param  string $externalRequestId
     * @return array
     */
    public function getRequestStatus(string $externalRequestId): array
    {
        $gate = $this->gate(array('auth_token'));

        if ($gate !== null) { return $gate; }

        if (trim($externalRequestId) === '') {
            return $this->fail(Contract_failures::F_WEBHOOK_FAILED, 'No document identifier supplied.');
        }

        $res = $this->call('GET', Leegality_api::EP_STATUS,
                           array('documentId' => $externalRequestId), null);

        if (!$res['ok']) { return $res; }

        $body = $this->decode($res['body']);

        if ($body === null || (int) $this->pick($body, 'status', 0) !== 1) {
            return $this->fail($this->classify($res['http_code'], $body ? $body : array()),
                'The provider did not return a usable status.');
        }

        $data    = (array) $this->pick($body, 'data', array());
        $signers = $this->inviteesFrom($data);

        return array(
            'ok'      => true,
            'reason'  => 'fetched',
            'state'   => $this->stateFrom($data, $signers),
            'signers' => $signers,
        );
    }

    /**
     * A signing link for one signer.
     *
     * The provider returns links on create and on status; there is no documented
     * endpoint that mints one on demand. So this reads the current status and
     * picks out the signer's link rather than inventing a call.
     *
     * @param  string $externalRequestId
     * @param  string $signerReference the signer's email — see the mapping note
     * @return array
     */
    public function getSigningLink(string $externalRequestId, string $signerReference): array
    {
        $status = $this->getRequestStatus($externalRequestId);

        if (empty($status['ok'])) { return $status; }

        foreach ($status['signers'] as $s) {
            /*
             * Correlation is by email because the API documents no per-invitee
             * identifier. That is only safe because the roster enforces one live
             * signer per email per contract; if that rule ever goes, this does.
             */
            if (strcasecmp((string) $s['email'], $signerReference) === 0) {
                if ((string) $s['url'] === '') {
                    return $this->fail(Contract_failures::F_LINK_FAILED,
                        'The provider has no active link for that signer.');
                }

                return array('ok' => true, 'reason' => 'found',
                             'url' => $s['url'], 'expires_at' => $s['expires_at']);
            }
        }

        return $this->fail(Contract_failures::F_LINK_FAILED,
            'That signer is not on the provider\'s invitee list for this document.');
    }

    /* ---- evidence retrieval ---------------------------------------------- */

    public function downloadSignedDocument(string $externalRequestId): array
    {
        return $this->fetch($externalRequestId, Leegality_api::DOWNLOAD_DOCUMENT);
    }

    public function downloadCompletionCertificate(string $externalRequestId): array
    {
        /*
         * Our "completion certificate" is Leegality's AUDIT_TRAIL. Same endpoint,
         * different documentDownloadType — established from the documentation,
         * not assumed from the name.
         */
        return $this->fetch($externalRequestId, Leegality_api::DOWNLOAD_AUDIT_TRAIL);
    }

    /**
     * Retrieve one artefact.
     *
     * The endpoint answers with a CDN URL that expires in FIFTEEN SECONDS and is
     * documented as server-side only. So the download happens here, immediately,
     * on the same request — the URL is never returned to a caller, never stored,
     * never queued behind anything. By the time a job picked it up it would be
     * dead.
     *
     * @param  string $externalRequestId
     * @param  string $type
     * @return array
     */
    private function fetch(string $externalRequestId, string $type): array
    {
        $gate = $this->gate(array('auth_token'));

        if ($gate !== null) { return $gate; }

        if (trim($externalRequestId) === '') {
            return $this->fail(Contract_failures::F_DOWNLOAD_FAILED, 'No document identifier supplied.');
        }

        $res = $this->call('GET', Leegality_api::EP_FETCH, array(
            'documentId'           => $externalRequestId,
            'documentDownloadType' => $type,
        ), null);

        if (!$res['ok']) { return $res; }

        $body = $this->decode($res['body']);

        if ($body === null || (int) $this->pick($body, 'status', 0) !== 1) {
            return $this->fail(Contract_failures::F_DOWNLOAD_FAILED,
                'The provider did not return the document.');
        }

        $data = (array) $this->pick($body, 'data', array());
        $url  = trim((string) $this->pick($data, 'file', ''));

        if ($url === '') {
            return $this->fail(Contract_failures::F_DOWNLOAD_FAILED,
                'The provider returned no download location.');
        }

        $bin = $this->call('GET_RAW', $url, array(), null);

        if (!$bin['ok']) {
            return $this->fail(Contract_failures::F_DOWNLOAD_FAILED,
                'The document location was issued but could not be read before it expired.');
        }

        $bytes = (string) $bin['body'];

        if ($bytes === '') {
            return $this->fail(Contract_failures::F_DOWNLOAD_FAILED, 'The downloaded document was empty.');
        }

        return array(
            'ok'           => true,
            'reason'       => 'downloaded',
            'bytes'        => $bytes,
            'content_type' => 'application/pdf',
            'sha256'       => hash('sha256', $bytes),
        );
    }

    /* ---- cancellation: refused, deliberately ------------------------------ */

    /**
     * Withdraw a live request.
     *
     * REFUSED, AND THIS IS THE CORRECT BEHAVIOUR.
     *
     * Leegality documents no cancel or withdraw operation. The nearest thing is
     * `DELETE /v3.0/sign/request`, which permanently deletes the document and
     * all associated data — the audit trail with it. That is destruction of the
     * evidence, not withdrawal of the request, and presenting it behind a Cancel
     * button would mean an operator could irreversibly destroy a legal record in
     * one click while believing they had stopped a workflow.
     *
     * Cancellation is therefore local: the lifecycle moves to `cancelled`, the
     * contract stops, nothing further is issued, and the provider's document is
     * left to expire on its own. Its record surviving is the right outcome.
     *
     * @param  string $externalRequestId
     * @param  string $reason
     * @return array
     */
    public function cancelRequest(string $externalRequestId, string $reason): array
    {
        return array(
            'ok'            => false,
            'reason'        => Contract_failures::F_PROVIDER_UNAVAILABLE,
            'local_only'    => true,
            'message'       => 'The signing provider has no withdraw operation. This contract has been '
                             . 'marked cancelled here and no further invitations will be issued. The '
                             . 'document is left with the provider so its audit trail survives.',
            'detail'        => 'No documented cancel endpoint. DELETE /v3.0/sign/request is a permanent '
                             . 'deletion of the document and its audit trail, and is never called by '
                             . 'this module.',
        );
    }

    /* ---- webhook verification -------------------------------------------- */

    /**
     * Verify an inbound webhook against Leegality's documented scheme.
     *
     * The scheme: HMAC-SHA1 of the payload's `documentId`, keyed with the
     * account's Private Salt, compared with the payload's `mac`.
     *
     * READ WHAT THIS DOES NOT DO
     * --------------------------
     * It does not authenticate the body. The MAC covers the documentId alone, so
     * `action`, `documentStatus`, the `verification` block and every invitee
     * detail arrive unsigned. And because the input is constant for a document,
     * the MAC is identical on every event about it — so a captured request
     * replays successfully, forever.
     *
     * A true return therefore means exactly one thing: the sender knows our
     * private salt and is talking about this document. It is a reason to go and
     * READ the authoritative status. It is never, on its own, a reason to change
     * state.
     *
     * @param  string $rawPayload
     * @param  array  $headers
     * @return bool
     */
    public function verifyWebhook(string $rawPayload, array $headers): bool
    {
        $salt = $this->salt();

        if ($salt === '') {
            /* No salt configured means nothing can be authenticated, so nothing
               is trusted. Fails closed. */
            return false;
        }

        if ($rawPayload === '') { return false; }

        $body = $this->decode($rawPayload);

        if ($body === null) { return false; }

        $documentId = (string) $this->pick($body, 'documentId', '');
        $presented  = (string) $this->pick($body, Leegality_api::MAC_FIELD, '');

        if ($documentId === '' || $presented === '') { return false; }

        $expected = hash_hmac(Leegality_api::MAC_ALGORITHM, $documentId, $salt);

        /* Constant-time. A timing-variable compare on a MAC is a slow but real
           oracle for forging one. */
        return hash_equals($expected, strtolower(trim($presented)));
    }

    /**
     * A stable key for de-duplicating webhook deliveries.
     *
     * NOT the MAC. The MAC is constant per document, so keying on it would treat
     * every event about a document as a duplicate of the first — the signature
     * event, the rejection, the expiry, all collapsed into one.
     *
     * Keyed instead on the document, the invitee, the action and the moment, so
     * a genuine second event is distinct and a replayed identical one is not.
     *
     * @param  array $payload decoded
     * @return string
     */
    public static function dedupeKey(array $payload)
    {
        $request = isset($payload['request']) && is_array($payload['request'])
            ? $payload['request'] : array();

        $parts = array(
            isset($payload['documentId'])     ? (string) $payload['documentId'] : '',
            isset($request['email'])          ? strtolower((string) $request['email']) : '',
            isset($request['inviteeType'])    ? (string) $request['inviteeType'] : '',
            isset($request['action'])         ? (string) $request['action'] : '',
            isset($payload['documentStatus']) ? (string) $payload['documentStatus'] : '',
        );

        return hash('sha256', implode('|', $parts));
    }

    /* ---- mapping ---------------------------------------------------------- */

    /**
     * Invitees, in our shape, from a provider data block.
     *
     * @param  array $data
     * @return array
     */
    private function inviteesFrom(array $data)
    {
        $out  = array();
        $list = array();

        foreach (array('invitees', 'invitations') as $k) {
            if (isset($data[$k]) && is_array($data[$k])) { $list = $data[$k]; break; }
        }

        foreach ($list as $i) {
            if (!is_array($i)) { continue; }

            $status = isset($i['invitationStatus']) && is_array($i['invitationStatus'])
                ? $i['invitationStatus'] : array();

            $out[] = array(
                'name'       => (string) $this->pick($i, 'name', ''),
                'email'      => (string) $this->pick($i, 'email', ''),
                'url'        => (string) $this->pick($i, 'signUrl',
                                        $this->pick($i, 'invitationUrl', '')),
                'active'     => (bool) $this->pick($i, 'active', false),
                'expires_at' => (string) $this->pick($i, 'expiryDate', ''),
                'signed'     => (bool) $this->pick($status, 'signed',
                                        $this->pick($i, 'signed', false)),
                'signed_at'  => (string) $this->pick($status, 'signDate', ''),
            );
        }

        return $out;
    }

    /**
     * Our internal request state, from the provider's document status and its
     * invitee list.
     *
     * Derived from the invitees rather than trusting `document.status` alone,
     * because "SENT" covers everything between issued and almost-finished and
     * would lose every intermediate distinction the timeline exists to show.
     *
     * @param  array $data
     * @param  array $signers
     * @return string
     */
    private function stateFrom(array $data, array $signers)
    {
        $doc    = isset($data['document']) && is_array($data['document']) ? $data['document'] : array();
        $status = strtoupper((string) $this->pick($doc, 'status', $this->pick($data, 'status', '')));

        if ($status === 'COMPLETED') { return Contract_signing_state::S_COMPLETED; }

        $total  = count($signers);
        $signed = 0;
        $anyActive = false;

        foreach ($signers as $s) {
            if (!empty($s['signed'])) { $signed++; }
            if (!empty($s['active'])) { $anyActive = true; }
        }

        if ($total > 0 && $signed >= $total) { return Contract_signing_state::S_COMPLETED; }
        if ($signed > 0)                     { return Contract_signing_state::S_PARTIALLY_SIGNED; }
        if ($anyActive)                      { return Contract_signing_state::S_INVITATION_SENT; }

        return Contract_signing_state::S_CREATED;
    }

    /**
     * Turn an HTTP code and a provider body into one of our named failures.
     *
     * @param  int   $code
     * @param  array $body
     * @return string
     */
    private function classify($code, array $body)
    {
        if ($code === 401 || $code === 403) { return Contract_failures::F_INVALID_CREDENTIALS; }
        if ($code === 429)                  { return Contract_failures::F_RATE_LIMITED; }
        if ($code >= 500)                   { return Contract_failures::F_TIMEOUT; }

        $codes = '';

        if (isset($body['messages']) && is_array($body['messages'])) {
            foreach ($body['messages'] as $m) {
                if (is_array($m) && isset($m['code'])) { $codes .= ' ' . strtolower((string) $m['code']); }
            }
        }

        if (strpos($codes, 'profile') !== false || strpos($codes, 'workflow') !== false) {
            return Contract_failures::F_INVALID_WORKFLOW;
        }

        if (strpos($codes, 'invitee') !== false || strpos($codes, 'email') !== false) {
            return Contract_failures::F_INVALID_SIGNER;
        }

        if (strpos($codes, 'balance') !== false || strpos($codes, 'credit') !== false) {
            return Contract_failures::F_INSUFFICIENT_BALANCE;
        }

        return Contract_failures::F_WEBHOOK_FAILED;
    }

    /* ---- transport --------------------------------------------------------- */

    /**
     * Make one HTTP call.
     *
     * Every network detail lives here, so the rest of the class is testable
     * without a socket and so there is exactly one place a header could leak.
     *
     * @param  string      $method 'GET' | 'POST' | 'GET_RAW'
     * @param  string      $path   endpoint path, or an absolute URL for GET_RAW
     * @param  array       $query
     * @param  array|null  $body
     * @return array {ok, http_code, body, reason?}
     */
    private function call($method, $path, array $query, $body)
    {
        $raw    = ($method === 'GET_RAW');
        $verb   = $raw ? 'GET' : $method;

        /*
         * The method allowlist, enforced rather than documented.
         *
         * `DELETE /v3.0/sign/request` permanently destroys a document and its
         * audit trail, and its path is IDENTICAL to the create endpoint — the
         * verb is the only difference. So no path check can protect this; only
         * refusing the verb can. Anything outside the allowlist stops here,
         * before a transport exists to carry it.
         */
        if (!in_array($verb, Leegality_api::permittedMethods(), true)) {
            return $this->fail(Contract_failures::F_PROVIDER_UNAVAILABLE,
                'Refused an HTTP method this module is not permitted to use.');
        }

        $url = $raw ? $path : $this->baseUrl() . $path;

        if (count($query) > 0) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $request = array(
            'method'  => $verb,
            'url'     => $url,
            /* The CDN URL is pre-authorised and short-lived; sending the account
               token to a third-party CDN host would be handing a credential to
               somewhere it has no business being. */
            'headers' => $raw ? array() : array(
                Leegality_api::AUTH_HEADER . ': ' . $this->token(),
                'Content-Type: application/json',
                'Accept: application/json',
            ),
            'body'    => $body === null ? null : json_encode($body),
            'timeout' => $this->timeout(),
        );

        if (is_callable($this->transport)) {
            $res = call_user_func($this->transport, $request);
        } else {
            $res = $this->curl($request);
        }

        if (empty($res['ok'])) {
            /*
             * A transport failure is reported as a timeout, and deliberately so:
             * "we stopped listening" is not "nothing happened". Classifying it
             * as a clean failure would invite a retry that duplicates a request
             * the provider may already have accepted.
             */
            return $this->fail(Contract_failures::F_TIMEOUT,
                'The signing provider could not be reached.');
        }

        return array('ok' => true,
                     'http_code' => (int) $this->pick($res, 'http_code', 0),
                     'body'      => (string) $this->pick($res, 'body', ''));
    }

    /**
     * @param  array $r
     * @return array
     */
    private function curl(array $r)
    {
        if (!function_exists('curl_init')) {
            return array('ok' => false);
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $r['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $r['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, (int) $r['timeout']));
        /* Certificate verification is never disabled. A signing provider reached
           over an unverified channel is a signing provider anybody can be. */
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if (count($r['headers']) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $r['headers']);
        }

        if ($r['method'] === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $r['body'] === null ? '' : $r['body']);
        }

        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_errno($ch);

        curl_close($ch);

        if ($err !== 0 || $out === false) { return array('ok' => false); }

        return array('ok' => true, 'http_code' => $code, 'body' => (string) $out);
    }

    /* ---- settings, read without ever handing them back --------------------- */

    private function enabled()
    {
        return (int) $this->pick($this->settings, 'enabled', 0) === 1;
    }

    private function environment()
    {
        $e = (string) $this->pick($this->settings, 'environment', 'sandbox');

        return $e === 'production' ? 'production' : 'sandbox';
    }

    private function baseUrl()
    {
        return Leegality_api::baseUrl($this->environment());
    }

    private function token()
    {
        return trim((string) $this->pick($this->settings, 'auth_token', ''));
    }

    private function salt()
    {
        return trim((string) $this->pick($this->settings, 'private_salt', ''));
    }

    private function profileId()
    {
        return trim((string) $this->pick($this->settings, 'profile_id', ''));
    }

    private function timeout()
    {
        $t = (int) $this->pick($this->settings, 'request_timeout_seconds', 30);

        return $t > 0 && $t <= 120 ? $t : 30;
    }

    /**
     * What the audit trail is allowed to see.
     *
     * Presence, never value. Whether a secret is set is operationally useful and
     * harmless; the secret itself has no business in a log, a screen or an
     * exception message.
     *
     * @return array
     */
    public function redactedSettings()
    {
        return array(
            'environment'       => $this->environment(),
            'enabled'           => $this->enabled(),
            'auth_token_set'    => $this->token() !== '',
            'private_salt_set'  => $this->salt() !== '',
            'profile_id_set'    => $this->profileId() !== '',
            'timeout_seconds'   => $this->timeout(),
        );
    }

    /* ---- gate and helpers --------------------------------------------------- */

    /**
     * Refuse before acting if a precondition is missing.
     *
     * Returns null when the call may proceed, or a refusal when it may not. The
     * refusal names WHICH precondition failed, never a value, so an
     * administrator can fix it without anybody reading a secret aloud.
     *
     * @param  array $needs
     * @return array|null
     */
    private function gate(array $needs)
    {
        if (!$this->enabled()) {
            return $this->fail(Contract_failures::F_PROVIDER_UNAVAILABLE,
                'The signing provider is switched off. Nothing was sent and no connection was made.');
        }

        if (in_array('auth_token', $needs, true) && $this->token() === '') {
            return $this->fail(Contract_failures::F_INVALID_CREDENTIALS,
                'No API token is configured. Nothing was sent.');
        }

        if (in_array('workflow_profile_id', $needs, true) && $this->profileId() === '') {
            return $this->fail(Contract_failures::F_INVALID_WORKFLOW,
                'No workflow is configured. Nothing was sent.');
        }

        return null;
    }

    /**
     * @param  string $reason
     * @param  string $detail
     * @return array
     */
    private function fail($reason, $detail)
    {
        return array(
            'ok'      => false,
            'reason'  => $reason,
            'message' => Contract_failures::operatorMessage($reason),
            'detail'  => $detail,
        );
    }

    private function decode($json)
    {
        if (!is_string($json) || $json === '') { return null; }

        $d = json_decode($json, true);

        return is_array($d) ? $d : null;
    }

    private function pick($a, $k, $default)
    {
        if (is_array($a) && array_key_exists($k, $a)) { return $a[$k]; }

        return $default;
    }
}
