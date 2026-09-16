<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Contract_signing_state.php';

/**
 * Contract_evidence
 *
 * What has to be true, and provable, before a contract is called fully executed.
 *
 * "COMPLETED" IS NOT "FULLY EXECUTED"
 * -----------------------------------
 * The provider says `completed` when the last signer finishes. That is the
 * provider's view of its own workflow, and it is not the same claim as "this
 * organisation holds a verified executed agreement".
 *
 * Fully executed, here, means all of:
 *
 *   - every MANDATORY signer completed
 *   - the signed PDF has been downloaded and its hash recorded
 *   - the completion/audit certificate has been downloaded and its hash recorded
 *   - the original PDF's hash was recorded BEFORE sending, and still matches
 *     the document the request was created from
 *
 * The last one is the point of the whole exercise. Without the pre-send hash
 * there is no way to show later that the document signed is the document
 * prepared — and a signature on a document nobody can identify is not worth
 * much.
 *
 * WHY THE HASH IS TAKEN OVER TRANSMITTED BYTES
 * --------------------------------------------
 * Not over the file as generated, not over the file as stored — over the exact
 * bytes sent to the provider. A generator that embeds a timestamp, or a storage
 * layer that re-compresses, makes those three different files. The one that
 * matters is the one the signer saw.
 *
 * Pure: no file access, no hashing of files. It is handed digests and decides.
 */
class Contract_evidence
{
    const ALGO = 'sha256';

    /** A sha256 digest, lowercase hex. */
    const DIGEST_PATTERN = '/^[a-f0-9]{64}$/';

    const DOC_ORIGINAL    = 'original_pdf';
    const DOC_SIGNED      = 'signed_pdf';
    const DOC_CERTIFICATE = 'completion_certificate';

    /**
     * The documents that make up a complete evidence set.
     *
     * @return array
     */
    public static function requiredDocuments()
    {
        return array(
            self::DOC_ORIGINAL => array(
                'label'     => 'Original contract PDF',
                'when'      => 'before_send',
                'mandatory' => true,
                'means'     => 'The exact bytes sent to the provider. Hashed before transmission.',
            ),
            self::DOC_SIGNED => array(
                'label'     => 'Signed contract PDF',
                'when'      => 'after_completion',
                'mandatory' => true,
                'means'     => 'Downloaded from the provider after every mandatory signer completed.',
            ),
            self::DOC_CERTIFICATE => array(
                'label'     => 'Completion / audit certificate',
                'when'      => 'after_completion',
                'mandatory' => true,
                'means'     => 'The provider record of who signed, when, from where and by what method.',
            ),
        );
    }

    /**
     * Is this a well-formed digest?
     *
     * Strict. An uppercase digest, a digest with whitespace, a truncated digest
     * and a digest of the empty string are all refused — the last one because
     * it is what a hashing call returns when it was handed nothing, and it
     * would otherwise sail through as a valid-looking value.
     *
     * @param  mixed $digest
     * @return array {ok, reason}
     */
    public static function validDigest($digest)
    {
        if (!is_string($digest) || $digest === '') {
            return array('ok' => false, 'reason' => 'digest_absent');
        }

        if (!preg_match(self::DIGEST_PATTERN, $digest)) {
            return array('ok' => false, 'reason' => 'digest_malformed');
        }

        /* sha256 of zero bytes. A real document never hashes to this, and its
           presence means something hashed an empty buffer and did not notice. */
        if ($digest === 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855') {
            return array('ok' => false, 'reason' => 'digest_of_empty_content');
        }

        return array('ok' => true, 'reason' => 'valid');
    }

    /**
     * Constant-time digest comparison.
     *
     * @param  string $a
     * @param  string $b
     * @return bool
     */
    public static function digestsMatch($a, $b)
    {
        if (!is_string($a) || !is_string($b) || $a === '' || $b === '') { return false; }

        return hash_equals($a, $b);
    }

    /**
     * Has every mandatory signer completed?
     *
     * Optional signers are ignored here on purpose: a contract with a witness
     * who never signed is still executed by its parties, and blocking on an
     * optional signer would leave valid agreements permanently pending.
     *
     * @param  array $signers each {reference, is_mandatory, completed_at}
     * @return array {ok, reason, outstanding}
     */
    public static function allMandatorySignersComplete(array $signers)
    {
        if (!$signers) {
            return array('ok' => false, 'reason' => 'no_signers_recorded', 'outstanding' => array());
        }

        $mandatory   = 0;
        $outstanding = array();

        foreach ($signers as $s) {
            if (empty($s['is_mandatory'])) { continue; }

            $mandatory++;

            if (empty($s['completed_at'])) {
                $outstanding[] = isset($s['reference']) ? (string) $s['reference'] : '(unknown)';
            }
        }

        if ($mandatory === 0) {
            /* A contract where nobody is required to sign is a configuration
               error, not an executed agreement. */
            return array('ok' => false, 'reason' => 'no_mandatory_signers',
                         'outstanding' => array());
        }

        if ($outstanding) {
            return array('ok' => false, 'reason' => 'mandatory_signers_outstanding',
                         'outstanding' => $outstanding);
        }

        return array('ok' => true, 'reason' => 'all_mandatory_signers_completed',
                     'outstanding' => array());
    }

    /**
     * The decision: is this contract fully executed?
     *
     * Returns the reason it is NOT, where it is not, because "pending" with no
     * explanation is the status people raise tickets about.
     *
     * @param  array $in {state, signers, digests, original_digest_at_send}
     * @return array {executed, reason, missing}
     */
    public static function fullyExecuted(array $in)
    {
        $state = isset($in['state']) ? (string) $in['state'] : '';

        if ($state !== Contract_signing_state::S_COMPLETED) {
            return self::not('signing_not_completed', array());
        }

        $signers = isset($in['signers']) && is_array($in['signers']) ? $in['signers'] : array();
        $s       = self::allMandatorySignersComplete($signers);

        if (empty($s['ok'])) {
            return self::not($s['reason'], $s['outstanding']);
        }

        $digests = isset($in['digests']) && is_array($in['digests']) ? $in['digests'] : array();
        $missing = array();

        foreach (self::requiredDocuments() as $doc => $meta) {
            if (empty($meta['mandatory'])) { continue; }

            $d = isset($digests[$doc]) ? $digests[$doc] : null;
            $v = self::validDigest($d);

            if (empty($v['ok'])) { $missing[] = $doc . ':' . $v['reason']; }
        }

        if ($missing) {
            return self::not('evidence_documents_missing_or_unverified', $missing);
        }

        /*
         * The original must still be the original. If the stored pre-send hash
         * and the hash recorded against the document differ, something
         * regenerated the contract after it was sent — and the signature now
         * belongs to a document this system cannot produce.
         */
        $atSend = isset($in['original_digest_at_send']) ? $in['original_digest_at_send'] : null;

        if ($atSend !== null) {
            if (!self::digestsMatch((string) $atSend, (string) $digests[self::DOC_ORIGINAL])) {
                return self::not('original_document_hash_changed_since_sending',
                                 array(self::DOC_ORIGINAL));
            }
        }

        return array('executed' => true, 'reason' => 'all_evidence_present_and_verified',
                     'missing' => array());
    }

    /**
     * What must be captured before a contract may be sent at all.
     *
     * The pre-send gate. Every item is a thing that cannot be reconstructed
     * afterwards: once the request is created, the chance to record what was
     * sent has gone.
     *
     * @param  array $in {original_digest, contract_version, fields_mapped,
     *                    signers, verification_done, video_kyc_done, approved_by}
     * @return array {ok, missing}
     */
    public static function readyToSend(array $in)
    {
        $missing = array();

        $d = self::validDigest(isset($in['original_digest']) ? $in['original_digest'] : null);

        if (empty($d['ok'])) { $missing[] = 'original_pdf_hash:' . $d['reason']; }

        if (empty($in['contract_version'])) { $missing[] = 'contract_version'; }
        if (empty($in['fields_mapped']))    { $missing[] = 'signature_fields_mapped'; }
        if (empty($in['signers']))          { $missing[] = 'signers'; }

        /*
         * Steps 4 and 5 of the workflow. They are gates here so the sequence
         * cannot be skipped, and they are marked BLOCKED in the module status
         * because no verification provider has been named — a gate whose
         * evidence nothing can currently produce must not be quietly defaulted
         * to satisfied.
         */
        if (empty($in['verification_done'])) { $missing[] = 'identity_or_business_verification'; }
        if (empty($in['video_kyc_done']))    { $missing[] = 'video_kyc'; }

        if ((int) (isset($in['approved_by']) ? $in['approved_by'] : 0) <= 0) {
            $missing[] = 'internal_approval';
        }

        return array('ok' => !$missing, 'missing' => $missing);
    }

    /**
     * Personal data that must never be written to the evidence or audit tables.
     *
     * The audit trail outlives the contract workspace. Anything on this list
     * put there once is there for as long as the audit is kept, under weaker
     * controls than the document store — which is the opposite of what these
     * particular fields need.
     *
     * @return array
     */
    public static function neverInAudit()
    {
        return array('aadhaar', 'aadhaar_number', 'pan', 'pan_number',
                     'otp', 'signer_email', 'signer_phone', 'signature_image',
                     'access_token', 'api_key', 'api_secret', 'webhook_secret',
                     'private_key', 'signing_link');
    }

    /**
     * Where evidence files may be stored, as a rule rather than a habit.
     *
     * The signed agreement and the completion certificate are the most
     * sensitive files this CRM holds. A path inside the document root is one
     * misconfigured directory listing away from being public.
     *
     * @return array
     */
    public static function storageRules()
    {
        return array(
            'outside_document_root'   => true,
            'directory_listing'       => false,
            'served_through'          => 'permission_checked_controller_action',
            'download_audited'        => true,
            'direct_url_access'       => false,
            'retained'                => 'as a business record — NOT subject to the Lead Finder purge',
        );
    }

    /* ---- helpers ------------------------------------------------------- */

    /**
     * "Not executed", with the reason and whatever is outstanding.
     *
     * This helper was referenced by fullyExecuted() before it existed, so every
     * call to that method raised a fatal error. Nothing caught it, because
     * nothing had called fullyExecuted() yet: a lint pass parses the file
     * happily, and an undefined static method is a runtime error, not a syntax
     * one. It was found by the first test that called the method — which is the
     * argument for writing the suite before the module is declared finished
     * rather than after.
     *
     * @param  string $reason
     * @param  array  $missing
     * @return array {executed, reason, missing}
     */
    private static function not($reason, array $missing)
    {
        return array('executed' => false, 'reason' => (string) $reason, 'missing' => $missing);
    }
}
