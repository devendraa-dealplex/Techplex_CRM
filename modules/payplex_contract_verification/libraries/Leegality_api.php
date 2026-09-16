<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Leegality_api
 *
 * Everything this module knows about Leegality's HTTP surface, in one place,
 * with a citation against every fact.
 *
 * WHY THE CONSTANTS ARE SEPARATED FROM THE ADAPTER
 * ------------------------------------------------
 * The adapter has behaviour worth testing — retry decisions, refusals, mapping.
 * This file has none: it is a transcription of someone else's documentation.
 * Keeping it apart means the test suite can assert, cheaply and exactly, that
 * no endpoint path exists anywhere else in the module, so a plausible-looking
 * URL cannot be typed into a controller six months from now and quietly work.
 *
 * EVERY VALUE BELOW WAS READ FROM THE DOCUMENTATION. NONE WAS INFERRED.
 * Where the documentation is silent, there is no constant — there is a refusal
 * in the adapter instead.
 *
 * @see https://knowledge.leegality.com/document-execution/api/document-execution-api
 */
class Leegality_api
{
    /* ---- hosts ---------------------------------------------------------- */

    const BASE_SANDBOX    = 'https://sandbox.leegality.com/api';
    const BASE_PRODUCTION = 'https://app1.leegality.com/api';

    /* ---- authentication -------------------------------------------------- */

    /**
     * The documentation is explicit that v3 uses this header and that
     * `Authorization: Bearer` will NOT work. Recorded as a constant so the
     * mistake cannot be made twice.
     */
    const AUTH_HEADER = 'X-Auth-Token';

    /* ---- the v3 family, which is the one we use -------------------------- */

    /** POST — create an eSigning request. */
    const EP_CREATE = '/v3.0/sign/request';

    /** GET ?documentId= — lightweight transaction status. The pull-authoritative call. */
    const EP_STATUS = '/v3.2/sign/request';

    /** GET ?documentId= — full document details, invitations, optional file URLs. */
    const EP_DETAILS = '/v3.3/document/details';

    /** GET — signed PDF or audit trail, as a 15-second CDN URL. */
    const EP_FETCH = '/v3.3/document/fetchDocument';

    /** POST — extend the expiry of an ALREADY EXPIRED document. Not a resend. */
    const EP_REACTIVATE = '/v3.0/sign/request/reactivate';

    /**
     * PERMANENT DELETION — and read the path before assuming you are safe.
     *
     *     DELETE /v3.0/sign/request?documentId=
     *
     * THE PATH IS BYTE-FOR-BYTE IDENTICAL TO EP_CREATE.
     *
     * Creating a signing request and permanently destroying one — the document
     * and its entire audit trail — are the same URL. The only thing separating
     * them is the HTTP verb. A one-word edit from POST to DELETE, on a line that
     * still reads `/v3.0/sign/request` and still passes any review that greps
     * for dangerous paths, deletes the legal record.
     *
     * This was not obvious until a test asserted that the delete path was absent
     * from the permitted list and could not pass, because the permitted list
     * necessarily contains that exact string for create.
     *
     * SO THE DEFENCE CANNOT BE THE PATH. IT HAS TO BE THE METHOD.
     * `permittedMethods()` below is the allowlist that actually protects this,
     * and the transport is asserted to emit nothing outside it.
     */
    const EP_DELETE_NEVER_CALL = '/v3.0/sign/request';

    /**
     * Every HTTP method this module may use against the provider.
     *
     * DELETE is absent, and that absence is the whole protection against the
     * collision documented above. A module that can only speak POST and GET
     * cannot destroy a document however its URLs are assembled.
     *
     * @return array
     */
    public static function permittedMethods()
    {
        return array('GET', 'POST');
    }

    /* ---- download types -------------------------------------------------- */

    const DOWNLOAD_DOCUMENT    = 'DOCUMENT';
    const DOWNLOAD_AUDIT_TRAIL = 'AUDIT_TRAIL';

    /**
     * The CDN URL returned by fetchDocument expires in 15 seconds and is
     * documented as server-side download only.
     *
     * That number is a design constraint, not trivia: it rules out handing the
     * URL to a browser, queueing it, or storing it for later. Whatever receives
     * it must download immediately, on the same request.
     */
    const CDN_URL_TTL_SECONDS = 15;

    /** Documented maximum size of the base64 PDF on create. */
    const MAX_PDF_BYTES = 15728640; /* 15 MB */

    /** Documented maximum length of the internal reference number. */
    const MAX_IRN_LENGTH = 255;

    /* ---- webhook verification -------------------------------------------- */

    /**
     * The documented MAC: HMAC-SHA1 over the documentId, keyed with the account's
     * Private Salt, compared against the payload's `mac`.
     *
     * SHA-1 is weak, and this is not a choice we get to make — it is the
     * provider's scheme. What matters is understanding exactly how little it
     * proves; see verifiedMacLimitations().
     */
    const MAC_ALGORITHM = 'sha1';
    const MAC_FIELD     = 'mac';

    /**
     * What the MAC does and does not establish.
     *
     * Stated as data rather than a comment so the webhook guard can quote it
     * into the audit trail, and so a reviewer reading the security report sees
     * the same sentences the code is written against.
     *
     * @return array
     */
    public static function verifiedMacLimitations()
    {
        return array(
            'covers'        => 'documentId only',
            'authenticates' => 'that the sender knows the private salt for this account',
            'does_not_authenticate' => 'any other field in the payload — action, documentStatus, '
                                     . 'the verification block and the invitee details are all unsigned',
            'replay_safe'   => false,
            'why_not_replay_safe' => 'The MAC is computed over the documentId alone, so it is constant '
                                   . 'for the lifetime of that document and identical on every event '
                                   . 'about it. A captured webhook replays successfully forever.',
            'consequence'   => 'A verified webhook is a PROMPT TO GO AND LOOK, never a fact. State '
                             . 'changes come from the authoritative status endpoint, not from the '
                             . 'payload body.',
        );
    }

    /**
     * Documented webhook actions, and how each is understood internally.
     *
     * Anything absent from this list maps to nothing and changes no state — the
     * provider is free to add events, and an unrecognised one must be inert
     * rather than surprising.
     *
     * @return array provider action => internal request state, or null
     */
    public static function webhookActions()
    {
        return array(
            'Signed'   => 'signed',
            'Approved' => 'approved',
            'Rejected' => 'rejected',
            'Expired'  => 'expired',
            'Failed'   => 'failed',
        );
    }

    /**
     * Documented values of `document.status` from the details endpoint.
     *
     * @return array
     */
    public static function documentStatuses()
    {
        return array('DRAFT', 'SENT', 'COMPLETED');
    }

    /**
     * The v4 "packs" endpoints, recorded ONLY so they are never mistaken for ours.
     *
     * `GET /v4/request/{workflowRunContextId}/status` and
     * `POST /v4/packs/invitations/reactivate` key off identifiers — a
     * workflowRunContextId and a signUrl — that the v3.0 create call does not
     * return. They belong to the Send Pack for eSigning API, a different product
     * surface.
     *
     * Calling them with a v3 documentId would not be an integration; it would be
     * an invention that happens to compile.
     *
     * @return array
     */
    public static function notOurApi()
    {
        return array(
            '/v4/request/{workflowRunContextId}/status' => 'v4 packs — needs workflowRunContextId, which v3.0 create does not return',
            '/v4/packs/invitations/reactivate'          => 'v4 packs — needs signUrl as its key; different product surface',
        );
    }

    /**
     * @param  string $environment 'sandbox' | 'production'
     * @return string
     */
    public static function baseUrl($environment)
    {
        return $environment === 'production' ? self::BASE_PRODUCTION : self::BASE_SANDBOX;
    }

    /**
     * Every endpoint this module may call, for the discipline check.
     *
     * Deliberately excludes EP_DELETE_NEVER_CALL.
     *
     * @return array
     */
    public static function permittedEndpoints()
    {
        return array(
            self::EP_CREATE,
            self::EP_STATUS,
            self::EP_DETAILS,
            self::EP_FETCH,
            self::EP_REACTIVATE,
        );
    }
}
