<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Contract_caps
 *
 * The permissions this module registers, and the separation-of-duty rules that
 * a permission alone cannot express.
 *
 * WHY NINETEEN PERMISSIONS AND NOT ONE
 * ------------------------------------
 * "Manage contract signing" would be one tick box covering: preparing a
 * contract, approving it, sending it to a customer for signature, cancelling a
 * live signing request, reading a signer's identity evidence, and downloading a
 * copy of their PAN or Aadhaar document. Those are six different things to
 * trust somebody with, and the last two are the ones that matter most — a
 * junior member of staff may well need to send contracts and should almost
 * certainly not be able to download identity documents.
 *
 * THE RULE A PERMISSION CANNOT EXPRESS
 * ------------------------------------
 * Maker and checker must be different people on the same contract. That is not
 * a capability — both people hold a capability — it is a relationship between
 * two actions and one record. `approvalAllowed()` enforces it, and it refuses
 * even when the actor holds every permission in this file, because an
 * administrator approving their own submission defeats the control entirely.
 *
 * A registered capability that nothing checks is worse than an absent one: it
 * tells the person who unticked it that they have restricted something. Every
 * name below is enforced in the controller, and the test suite asserts it.
 */
class Contract_caps
{
    /** The feature key these capabilities are registered under. */
    const FEATURE = 'payplex_contract_verification';

    const CAP_VIEW              = 'contract_signing_view';
    const CAP_PREPARE           = 'contract_signing_prepare';
    const CAP_APPROVE           = 'contract_signing_approve';
    const CAP_SEND              = 'contract_signing_send';
    const CAP_CANCEL            = 'contract_signing_cancel';
    const CAP_VIEW_EVIDENCE     = 'contract_signing_view_evidence';
    const CAP_DOWNLOAD_IDENTITY = 'contract_signing_download_identity';
    const CAP_SETTINGS          = 'contract_signing_settings';

    /*
     * THE ELEVEN KYC CAPABILITIES.
     *
     * Until now every KYC route was gated by CAP_SEND, because sending and
     * verifying happened to be done by the same person. That is not a
     * permission model, it is a coincidence — and it meant a KYC reviewer, the
     * one role that exists to look at somebody's identity documents and say
     * yes or no, could also despatch contracts to customers.
     *
     * Each name below gates exactly one thing. None of them is implied by
     * CAP_SEND, and CAP_SEND is implied by none of them:
     * mutuallyExclusiveImplications() states that, and the suite asserts it.
     */
    const CAP_KYC_VIEW              = 'contract_kyc_view';
    const CAP_KYC_REVIEW            = 'contract_kyc_review';
    const CAP_KYC_APPROVE           = 'contract_kyc_approve';
    const CAP_KYC_REJECT            = 'contract_kyc_reject';
    const CAP_KYC_RETRY             = 'contract_kyc_retry';
    const CAP_KYC_VIEW_DOCUMENTS    = 'contract_kyc_view_documents';
    const CAP_KYC_VIEW_VIDEO        = 'contract_kyc_view_video';
    const CAP_KYC_DOWNLOAD_EVIDENCE = 'contract_kyc_download_evidence';
    const CAP_KYC_MANAGE_RETENTION  = 'contract_kyc_manage_retention';
    const CAP_KYC_APPLY_HOLD        = 'contract_kyc_apply_legal_hold';
    const CAP_KYC_RELEASE_HOLD      = 'contract_kyc_release_legal_hold';

    /**
     * Registered on the Roles screen. Every one is checked in Signing.php.
     *
     * @return array
     */
    public static function enforced()
    {
        return array(
            self::CAP_VIEW =>
                'Open the contract signing screen and see the status of a request',
            self::CAP_PREPARE =>
                'Prepare a contract for signing: place signature fields, record verification, '
                . 'and submit it for internal approval',
            self::CAP_APPROVE =>
                'Approve a prepared contract for signing. Cannot be used on your own submission',
            self::CAP_SEND =>
                'Send an approved contract to the signing provider and issue signing links',
            self::CAP_CANCEL =>
                'Cancel a live signing request, with a stated reason',
            self::CAP_VIEW_EVIDENCE =>
                'View the signer timeline and download the signed agreement and completion certificate',
            self::CAP_DOWNLOAD_IDENTITY =>
                'Download identity verification documents. Grant this to as few people as possible',
            self::CAP_SETTINGS =>
                'Configure the signing provider connection. Administrator only in practice',

            /* ---- Video KYC. Separate from signing, on purpose. ---- */

            self::CAP_KYC_VIEW =>
                'See the status of a KYC case: state, attempts, decision and dates. '
                . 'No documents, no images, no recording',
            self::CAP_KYC_REVIEW =>
                'Open an assigned KYC case and inspect the verification results in order to decide it',
            self::CAP_KYC_APPROVE =>
                'Record a KYC case as passed. Cannot be used on a case you created or invited',
            self::CAP_KYC_REJECT =>
                'Record a KYC case as failed, with a stated reason',
            self::CAP_KYC_RETRY =>
                'Allow a signer another verification attempt after a failure',
            self::CAP_KYC_VIEW_DOCUMENTS =>
                'View identity documents such as a PAN card or passport scan. '
                . 'Grant this to as few people as possible',
            self::CAP_KYC_VIEW_VIDEO =>
                'Watch a Video KYC recording. Biometric personal data — the most sensitive '
                . 'thing this module holds. Viewing only; downloading is separate',
            self::CAP_KYC_DOWNLOAD_EVIDENCE =>
                'Take a copy of KYC evidence away from this system. A downloaded file is one '
                . 'this CRM can never account for again',
            self::CAP_KYC_MANAGE_RETENTION =>
                'Change how long KYC evidence is kept. Cannot shorten retention while a legal hold stands',
            self::CAP_KYC_APPLY_HOLD =>
                'Place a legal hold that stops evidence being deleted',
            self::CAP_KYC_RELEASE_HOLD =>
                'Lift a legal hold. Deliberately separate from applying one',
        );
    }

    /**
     * @return array capability names
     */
    public static function all()
    {
        return array_keys(self::enforced());
    }

    /**
     * The eight signing capabilities, as they were before the KYC split.
     *
     * Stated as a literal so the suite can assert none of them was renamed,
     * repurposed or dropped while the KYC set was added. A refactor that
     * quietly widens an existing permission is the failure mode this guards.
     *
     * @return array
     */
    public static function signingCapabilities()
    {
        return array(
            self::CAP_VIEW, self::CAP_PREPARE, self::CAP_APPROVE, self::CAP_SEND,
            self::CAP_CANCEL, self::CAP_VIEW_EVIDENCE, self::CAP_DOWNLOAD_IDENTITY,
            self::CAP_SETTINGS,
        );
    }

    /**
     * The eleven KYC capabilities.
     *
     * @return array
     */
    public static function kycCapabilities()
    {
        return array(
            self::CAP_KYC_VIEW, self::CAP_KYC_REVIEW, self::CAP_KYC_APPROVE,
            self::CAP_KYC_REJECT, self::CAP_KYC_RETRY, self::CAP_KYC_VIEW_DOCUMENTS,
            self::CAP_KYC_VIEW_VIDEO, self::CAP_KYC_DOWNLOAD_EVIDENCE,
            self::CAP_KYC_MANAGE_RETENTION, self::CAP_KYC_APPLY_HOLD,
            self::CAP_KYC_RELEASE_HOLD,
        );
    }

    /**
     * Pairs that must never imply one another, in either direction.
     *
     * The first pair is the whole point of this increment: holding CAP_SEND
     * must not let anyone review KYC, and reviewing KYC must not let anyone
     * send a contract to a customer.
     *
     * The last pair is the separation inside the hold itself. Applying a legal
     * hold is a protective act; releasing one removes the only thing standing
     * between evidence and a deletion job. Whoever can apply a hold must not,
     * by that fact, be able to lift it.
     *
     * @return array
     */
    public static function mutuallyExclusiveImplications()
    {
        return array(
            array(self::CAP_SEND,             self::CAP_KYC_REVIEW),
            array(self::CAP_SEND,             self::CAP_KYC_APPROVE),
            array(self::CAP_KYC_REVIEW,       self::CAP_SEND),
            array(self::CAP_KYC_VIEW,         self::CAP_KYC_VIEW_VIDEO),
            array(self::CAP_KYC_VIEW_DOCUMENTS, self::CAP_KYC_VIEW_VIDEO),
            array(self::CAP_KYC_VIEW_VIDEO,   self::CAP_KYC_DOWNLOAD_EVIDENCE),
            array(self::CAP_VIEW,             self::CAP_KYC_VIEW_DOCUMENTS),
            array(self::CAP_VIEW,             self::CAP_KYC_VIEW_VIDEO),
            array(self::CAP_KYC_APPLY_HOLD,   self::CAP_KYC_RELEASE_HOLD),
        );
    }

    /**
     * A KYC decision may not be taken by the person who created or invited the
     * case, where the policy asks for segregation.
     *
     * Same shape and same reasoning as approvalAllowed(): it is a relationship
     * between two actions and one record, not something a capability can say.
     * Administrator status does not override it.
     *
     * @param  int   $actorId
     * @param  array $case      {created_by, invited_by, decided_by}
     * @param  bool  $holdsCapability
     * @param  bool  $isAdmin
     * @param  bool  $segregationRequired
     * @return array {allowed, reason}
     */
    public static function kycDecisionAllowed($actorId, array $case, $holdsCapability,
                                              $isAdmin = false, $segregationRequired = true)
    {
        $actorId = (int) $actorId;

        if ($actorId <= 0) {
            return self::no('no_actor');
        }

        if (!$holdsCapability && !$isAdmin) {
            return self::no('missing_kyc_decision_capability');
        }

        if (!empty($case['decided_by'])) {
            return self::no('already_decided');
        }

        if ($segregationRequired) {
            $creator = (int) (isset($case['created_by']) ? $case['created_by'] : 0);
            $inviter = (int) (isset($case['invited_by']) ? $case['invited_by'] : 0);

            if ($creator > 0 && $creator === $actorId) {
                return self::no('reviewer_may_not_decide_own_case');
            }

            if ($inviter > 0 && $inviter === $actorId) {
                return self::no('reviewer_may_not_decide_own_case');
            }
        }

        return array('allowed' => true, 'reason' => 'decided_by_a_second_person');
    }

    /**
     * Capabilities that expose a person's identity documents or contact details.
     *
     * Named as a group so the security report and the test suite can both ask
     * the question "who can see personal data" without re-deriving the answer.
     *
     * @return array
     */
    public static function personalDataCapabilities()
    {
        return array(self::CAP_VIEW_EVIDENCE, self::CAP_DOWNLOAD_IDENTITY);
    }

    /**
     * Capabilities that must never be implied by another.
     *
     * Perfex has no capability inheritance, so this is a statement of intent
     * that the suite asserts rather than a mechanism. It exists because the
     * tempting shortcut — "send implies view evidence, they need to check it
     * worked" — is exactly how the identity-document permission stops meaning
     * anything.
     *
     * @return array
     */
    public static function neverImplied()
    {
        return array(self::CAP_DOWNLOAD_IDENTITY, self::CAP_APPROVE, self::CAP_SETTINGS);
    }

    /**
     * Maker and checker must differ.
     *
     * Refuses when the approver is the submitter, when either is unknown, and
     * when the actor lacks the capability. Administrator status does NOT
     * override this: an administrator approving their own submission is the
     * precise thing a maker-checker control exists to prevent, and allowing it
     * "because they are an administrator" makes the control decorative on
     * exactly the accounts that most need it.
     *
     * @param  int  $actorId
     * @param  int  $submittedBy
     * @param  bool $holdsApprove
     * @param  bool $isAdmin
     * @return array {allowed, reason}
     */
    public static function approvalAllowed($actorId, $submittedBy, $holdsApprove, $isAdmin = false)
    {
        $actorId     = (int) $actorId;
        $submittedBy = (int) $submittedBy;

        if ($actorId <= 0) {
            return self::no('no_actor');
        }

        if ($submittedBy <= 0) {
            return self::no('contract_was_never_submitted');
        }

        if (!$holdsApprove && !$isAdmin) {
            return self::no('missing_approve_capability');
        }

        if ($actorId === $submittedBy) {
            return self::no('maker_and_checker_must_differ');
        }

        return array('allowed' => true, 'reason' => 'approved_by_a_second_person');
    }

    /**
     * May this actor send the contract to the provider?
     *
     * Separate from approval on purpose: approving says the contract is right,
     * sending spends money and reaches a customer. They are commonly the same
     * person and must be separately grantable.
     *
     * @param  array $contract {approved_by, approved_at, state}
     * @param  bool  $holdsSend
     * @param  bool  $isAdmin
     * @return array {allowed, reason}
     */
    public static function sendAllowed(array $contract, $holdsSend, $isAdmin = false)
    {
        if (!$holdsSend && !$isAdmin) {
            return self::no('missing_send_capability');
        }

        if ((int) (isset($contract['approved_by']) ? $contract['approved_by'] : 0) <= 0) {
            return self::no('not_approved');
        }

        if (empty($contract['approved_at'])) {
            return self::no('approval_not_timestamped');
        }

        return array('allowed' => true, 'reason' => 'approved_and_sendable');
    }

    /**
     * Downloading identity documents is its own decision, every time.
     *
     * Requires the capability, an authenticated actor, and a stated purpose
     * that is recorded. The purpose is not bureaucracy: a download of somebody's
     * PAN or Aadhaar document is the single most sensitive action in this
     * module, and "who downloaded it and why" is the question that will be
     * asked afterwards.
     *
     * @param  bool   $holdsCapability
     * @param  int    $actorId
     * @param  string $purpose
     * @return array {allowed, reason}
     */
    public static function identityDownloadAllowed($holdsCapability, $actorId, $purpose)
    {
        if (!$holdsCapability) {
            return self::no('missing_identity_download_capability');
        }

        if ((int) $actorId <= 0) {
            return self::no('no_actor');
        }

        $purpose = trim((string) $purpose);

        if (strlen($purpose) < 10) {
            return self::no('purpose_required');
        }

        return array('allowed' => true, 'reason' => 'permitted_and_recorded');
    }

    /* ---- helpers ------------------------------------------------------- */

    private static function no($reason)
    {
        return array('allowed' => false, 'reason' => $reason);
    }
}
