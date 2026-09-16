<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Contract_evidence_types
 *
 * The ten kinds of evidence this module can hold, and the capability each one
 * needs on its own.
 *
 * WHY A TAXONOMY AND NOT ONE PERMISSION
 * -------------------------------------
 * Until now `contract_signing_view_evidence` granted everything a contract had:
 * the signed agreement, the audit trail, the identity documents, the face-match
 * result and — once a vendor exists — the Video KYC recording. Those are not
 * the same thing. A finance reviewer checking that an agreement was executed
 * needs the signed PDF and the certificate. They do not need to watch a
 * customer's face on video, and nothing about their job required it; they had
 * it because the permission could not tell the difference.
 *
 * So each type below is grantable alone, and NONE of them is implied by
 * `contract_signing_view`. Opening a contract tells you the state of its
 * verification. It does not hand you the customer's PAN card.
 *
 * THE ORDER IS THE SENSITIVITY ORDER
 * ----------------------------------
 * Roughly: what happened → what was decided → the raw personal material. The
 * last three (identity documents, location, recording) are the ones a data
 * protection officer asks about, and they are the ones marked `raw_personal`.
 *
 * DOWNLOAD IS NOT VIEW
 * --------------------
 * Viewing is transient and watermarked and leaves a log line. Downloading
 * produces a file on somebody's laptop that this system can never see again.
 * They are separate capabilities for every type, and for the recording the
 * download capability is not grantable at all until a lawful basis exists.
 */
class Contract_evidence_types
{
    const KYC_SUMMARY       = 'kyc_summary';
    const CONSENT_RECORD    = 'consent_record';
    const IDENTITY_DOCUMENT = 'identity_document';
    const ID_VERIFICATION   = 'id_verification_result';
    const FACE_MATCH        = 'face_match_result';
    const LOCATION          = 'location_evidence';
    const VIDEO_RECORDING   = 'video_kyc_recording';
    const SIGNED_AGREEMENT  = 'signed_agreement';
    const PROVIDER_AUDIT    = 'provider_certificate_audit';
    const FULL_PACKAGE      = 'complete_evidence_package';

    /**
     * Every type, with the capability that grants it and what it actually is.
     *
     * `view_capability`     — may look at it, in the browser, watermarked.
     * `download_capability` — may take a copy away. Null means never, by any
     *                         grant, through this module.
     * `raw_personal`        — biometric or identity material about a person.
     * `needs_reason`        — the actor must state why, and the reason is stored.
     *
     * @return array
     */
    public static function catalogue()
    {
        return array(

            self::KYC_SUMMARY => array(
                'label'               => 'KYC summary',
                'means'               => 'State, attempt count, decision and timestamps. No documents, '
                                       . 'no images, no recording.',
                'view_capability'     => Contract_caps::CAP_KYC_VIEW,
                'download_capability' => Contract_caps::CAP_KYC_DOWNLOAD_EVIDENCE,
                'raw_personal'        => false,
                'needs_reason'        => false,
            ),

            self::CONSENT_RECORD => array(
                'label'               => 'Consent record',
                'means'               => 'What the signer was shown and agreed to, when, and in which '
                                       . 'language. The document that answers "did they consent".',
                'view_capability'     => Contract_caps::CAP_KYC_VIEW,
                'download_capability' => Contract_caps::CAP_KYC_DOWNLOAD_EVIDENCE,
                'raw_personal'        => false,
                'needs_reason'        => false,
            ),

            self::ID_VERIFICATION => array(
                'label'               => 'PAN / ID verification result',
                'means'               => 'The verdict and the masked reference — not the number. '
                                       . 'Enough to know the check passed without holding the identifier.',
                'view_capability'     => Contract_caps::CAP_KYC_REVIEW,
                'download_capability' => Contract_caps::CAP_KYC_DOWNLOAD_EVIDENCE,
                'raw_personal'        => false,
                'needs_reason'        => false,
            ),

            self::FACE_MATCH => array(
                'label'               => 'Face match / liveness result',
                'means'               => 'Score and verdict from the provider. A biometric CONCLUSION, '
                                       . 'not the biometric itself.',
                'view_capability'     => Contract_caps::CAP_KYC_REVIEW,
                'download_capability' => Contract_caps::CAP_KYC_DOWNLOAD_EVIDENCE,
                'raw_personal'        => false,
                'needs_reason'        => false,
            ),

            self::SIGNED_AGREEMENT => array(
                'label'               => 'Signed agreement',
                'means'               => 'The executed PDF. A business record, and the thing most '
                                       . 'people asking about a contract actually want.',
                'view_capability'     => Contract_caps::CAP_VIEW_EVIDENCE,
                'download_capability' => Contract_caps::CAP_VIEW_EVIDENCE,
                'raw_personal'        => false,
                'needs_reason'        => false,
            ),

            self::PROVIDER_AUDIT => array(
                'label'               => 'Provider certificate and audit trail',
                'means'               => 'The signing provider\'s completion certificate and its own '
                                       . 'trail of what happened. The evidence of execution.',
                'view_capability'     => Contract_caps::CAP_VIEW_EVIDENCE,
                'download_capability' => Contract_caps::CAP_VIEW_EVIDENCE,
                'raw_personal'        => false,
                'needs_reason'        => false,
            ),

            self::LOCATION => array(
                'label'               => 'Location evidence',
                'means'               => 'Where the provider says the session took place. Personal, '
                                       . 'and separately recorded from what the browser claimed — the '
                                       . 'two are never merged.',
                'view_capability'     => Contract_caps::CAP_KYC_REVIEW,
                'download_capability' => Contract_caps::CAP_KYC_DOWNLOAD_EVIDENCE,
                'raw_personal'        => true,
                'needs_reason'        => true,
            ),

            self::IDENTITY_DOCUMENT => array(
                'label'               => 'Identity document',
                'means'               => 'A scan or image of a PAN card, Aadhaar or passport. The '
                                       . 'document itself, not a verdict about it.',
                'view_capability'     => Contract_caps::CAP_KYC_VIEW_DOCUMENTS,
                'download_capability' => Contract_caps::CAP_DOWNLOAD_IDENTITY,
                'raw_personal'        => true,
                'needs_reason'        => true,
            ),

            self::VIDEO_RECORDING => array(
                'label'               => 'Video KYC recording',
                'means'               => 'The V-CIP recording. Biometric personal data, and the single '
                                       . 'most sensitive artefact this module will ever touch.',
                'view_capability'     => Contract_caps::CAP_KYC_VIEW_VIDEO,
                /*
                 * Deliberately NOT the same capability as viewing, and
                 * deliberately not grantable on its own either — see
                 * downloadPermitted(), which refuses the recording outright
                 * until a lawful basis and retention period are approved.
                 */
                'download_capability' => Contract_caps::CAP_KYC_DOWNLOAD_EVIDENCE,
                'raw_personal'        => true,
                'needs_reason'        => true,
            ),

            self::FULL_PACKAGE => array(
                'label'               => 'Complete evidence package',
                'means'               => 'Everything available for a contract, bundled. The convenient '
                                       . 'option, and therefore the one that quietly defeats a '
                                       . 'per-type permission if it is not itself constrained.',
                'view_capability'     => Contract_caps::CAP_VIEW_EVIDENCE,
                'download_capability' => Contract_caps::CAP_VIEW_EVIDENCE,
                'raw_personal'        => false,
                'needs_reason'        => true,
            ),
        );
    }

    /** @return array */
    public static function all()
    {
        return array_keys(self::catalogue());
    }

    /** @param string $type @return bool */
    public static function isType($type)
    {
        return array_key_exists((string) $type, self::catalogue());
    }

    /**
     * The raw personal material. Named as a group so a report can ask
     * "who can see personal data" without re-deriving the answer.
     *
     * @return array
     */
    public static function rawPersonal()
    {
        $out = array();

        foreach (self::catalogue() as $type => $meta) {
            if (!empty($meta['raw_personal'])) { $out[] = $type; }
        }

        return $out;
    }

    /**
     * Types the complete package may contain.
     *
     * THE PACKAGE IS NOT A BACK DOOR. It contains only what the actor could
     * have asked for one at a time. Building it from `all()` minus itself would
     * hand a finance reviewer the recording inside a zip, which is the same
     * disclosure with an extra step.
     *
     * @param  array $heldCapabilities
     * @return array
     */
    public static function packageContentsFor(array $heldCapabilities)
    {
        $out = array();

        foreach (self::catalogue() as $type => $meta) {
            if ($type === self::FULL_PACKAGE)                                  { continue; }
            if (!in_array($meta['view_capability'], $heldCapabilities, true))  { continue; }
            $out[] = $type;
        }

        return $out;
    }

    /**
     * The capability needed to VIEW a type.
     *
     * @param  string $type
     * @return string|null
     */
    public static function viewCapability($type)
    {
        $c = self::catalogue();

        return isset($c[$type]) ? $c[$type]['view_capability'] : null;
    }

    /**
     * The capability needed to DOWNLOAD a type, or null where no capability
     * grants it.
     *
     * @param  string $type
     * @return string|null
     */
    public static function downloadCapability($type)
    {
        $c = self::catalogue();

        return isset($c[$type]) ? $c[$type]['download_capability'] : null;
    }

    /** @param string $type @return bool */
    public static function needsReason($type)
    {
        $c = self::catalogue();

        return isset($c[$type]) ? !empty($c[$type]['needs_reason']) : true;
    }

    /**
     * May this type be downloaded at all, regardless of who is asking?
     *
     * The Video KYC recording is refused here, above any capability check, and
     * it stays refused until a lawful basis, a retention period and approved
     * consent wording exist. A capability that could be ticked to enable it
     * would make that a role-editor decision; it is a compliance decision.
     *
     * @param  string $type
     * @param  array  $compliance {recording_download_approved}
     * @return array {allowed, reason}
     */
    public static function downloadPermittedForType($type, array $compliance = array())
    {
        if (!self::isType($type)) {
            return array('allowed' => false, 'reason' => 'unknown_evidence_type');
        }

        if (self::downloadCapability($type) === null) {
            return array('allowed' => false, 'reason' => 'type_is_never_downloadable');
        }

        if ($type === self::VIDEO_RECORDING && empty($compliance['recording_download_approved'])) {
            return array('allowed' => false, 'reason' => 'recording_download_needs_compliance_approval');
        }

        return array('allowed' => true, 'reason' => 'type_is_downloadable');
    }

    /**
     * Viewing a contract must not imply any of these.
     *
     * Asserted by the suite rather than enforced by a mechanism, because Perfex
     * has no capability inheritance — the risk is not that the code implies it,
     * it is that somebody later writes a convenience check that does.
     *
     * @return array
     */
    public static function neverImpliedByContractView()
    {
        return array(
            self::IDENTITY_DOCUMENT,
            self::VIDEO_RECORDING,
            self::LOCATION,
            self::FULL_PACKAGE,
        );
    }
}
