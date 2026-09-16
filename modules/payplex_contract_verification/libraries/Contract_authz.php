<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Contract_authz
 *
 * One place that answers "may this person do this to this record".
 *
 * WHY CENTRALISE
 * --------------
 * Before this file, each route assembled its own answer: a capability check, a
 * contract-access check, sometimes a lifecycle guard, in whatever order the
 * route was written in. Eighteen routes each doing that is eighteen chances to
 * omit one, and the omission is invisible — the route works, it just works for
 * more people than intended. Adding a nineteenth route meant remembering the
 * pattern from memory.
 *
 * Every decision here is a pure function of its arguments. No session, no
 * database, no globals. That is not tidiness: it means the suite can ask "what
 * happens when a revoked reviewer requests a recording under a legal hold"
 * directly, with no CodeIgniter and no fixture, and get the same answer the
 * live route gets.
 *
 * WHAT ADMINISTRATOR STATUS DOES AND DOES NOT DO
 * ----------------------------------------------
 * An administrator bypasses CAPABILITY checks. That is Perfex's own behaviour
 * and this module does not change it.
 *
 * An administrator does NOT bypass:
 *
 *   - maker-checker separation — they cannot approve their own submission;
 *   - KYC segregation — they cannot decide a case they created or invited;
 *   - a legal hold — a hold exists precisely to stop the powerful account;
 *   - retention expiry — evidence past its period is gone, for everyone;
 *   - the requirement that evidence exist and its hash verify;
 *   - audit. Every decision here carries `audit => true`, admin or not.
 *
 * Each of those is asserted separately in the suite, because "admin bypasses
 * the checks" is the single most common way a control like this quietly stops
 * applying to the accounts that can do the most damage.
 */
class Contract_authz
{
    /* ================================================================
     * 1. Contract access
     * ============================================================== */

    /**
     * May this actor open this contract at all?
     *
     * Three doors, in the order Perfex itself uses, plus the one this module
     * adds:
     *
     *   1. view-all on contracts        → yes, any contract;
     *   2. view-own and they own it     → yes, that one;
     *   3. a live assignment unlocking the action → yes, that one.
     *
     * Nothing else. In particular there is no "they can see the customer, so
     * they can see the contract" step, because customer visibility in Perfex is
     * far broader than contract visibility and wiring the two together would
     * widen this silently.
     *
     * @param  array $in {actor_id, contract_id, contract_owner_id, capability,
     *                    holds_capability, has_view_all, has_view_own, is_admin,
     *                    assignments, now}
     * @return array {allowed, reason, via, audit}
     */
    public static function canAccessContract(array $in)
    {
        $actor      = (int) self::get($in, 'actor_id', 0);
        $contractId = (int) self::get($in, 'contract_id', 0);
        $now        = (int) self::get($in, 'now', 0);

        if ($actor <= 0)      { return self::deny('no_actor'); }
        if ($contractId <= 0) { return self::deny('no_contract'); }

        $capability = (string) self::get($in, 'capability', Contract_caps::CAP_VIEW);
        $isAdmin    = !empty($in['is_admin']);

        /* DOOR ONE: the module capability. An administrator passes this door and
           only this door. */
        if (empty($in['holds_capability']) && !$isAdmin) {
            return self::deny('missing_capability:' . $capability);
        }

        /* DOOR TWO: which contracts. Admin does not skip this reasoning — it
           simply lands on view-all, which is what an administrator has. */
        if ($isAdmin || !empty($in['has_view_all'])) {
            return self::allow('view_all', $isAdmin ? 'administrator' : 'view_all_contracts');
        }

        $owner = (int) self::get($in, 'contract_owner_id', 0);

        if (!empty($in['has_view_own']) && $owner > 0 && $owner === $actor) {
            return self::allow('owner', 'owns_this_contract');
        }

        $assignments = self::get($in, 'assignments', array());

        if (is_array($assignments) && count($assignments) > 0) {
            $u = Contract_assignment::unlocks($assignments, $contractId, $actor, $capability, $now);

            if (!empty($u['allowed'])) {
                return self::allow('assignment', 'assigned_as_' . $u['via_role']);
            }

            return self::deny($u['reason']);
        }

        return self::deny('no_assignment_on_this_contract');
    }

    /* ================================================================
     * 2. KYC case review
     * ============================================================== */

    /**
     * May this actor review or decide this KYC case?
     *
     * The case is checked against the contract before anything else. A case id
     * posted by a browser is an untrusted number, and a reviewer legitimately
     * assigned to contract 12 asking about a case belonging to contract 13 is
     * the cross-contract IDOR this whole model exists to prevent. It is not
     * enough to load the case and check the contract afterwards — this refuses
     * on the mismatch itself.
     *
     * @param  array $in {actor_id, contract_id, case, action, holds_capability,
     *                    is_admin, has_view_all, has_view_own, contract_owner_id,
     *                    assignments, segregation_required, now}
     * @return array {allowed, reason, via, audit}
     */
    public static function canReviewKycCase(array $in)
    {
        $actor = (int) self::get($in, 'actor_id', 0);
        $case  = self::get($in, 'case', array());
        $cid   = (int) self::get($in, 'contract_id', 0);

        if ($actor <= 0)          { return self::deny('no_actor'); }
        if (!is_array($case) || empty($case)) { return self::deny('kyc_case_not_found'); }

        /* The case must belong to the contract the caller named. */
        if ((int) self::get($case, 'contract_id', 0) !== $cid || $cid <= 0) {
            return self::deny('kyc_case_belongs_to_a_different_contract');
        }

        $action = (string) self::get($in, 'action', 'review');

        $capability = self::kycCapabilityForAction($action);

        if ($capability === null) {
            return self::deny('unknown_kyc_action');
        }

        /* Contract access first, asked for with the KYC capability so an
           assignment that does not unlock KYC cannot let somebody in. */
        $access = self::canAccessContract(array(
            'actor_id'          => $actor,
            'contract_id'       => $cid,
            'contract_owner_id' => self::get($in, 'contract_owner_id', 0),
            'capability'        => $capability,
            'holds_capability'  => self::get($in, 'holds_capability', false),
            'has_view_all'      => self::get($in, 'has_view_all', false),
            'has_view_own'      => self::get($in, 'has_view_own', false),
            'is_admin'          => self::get($in, 'is_admin', false),
            'assignments'       => self::get($in, 'assignments', array()),
            'now'               => self::get($in, 'now', 0),
        ));

        if (empty($access['allowed'])) {
            return self::deny($access['reason']);
        }

        /* Deciding is not reviewing. Segregation applies to the decision only,
           and it applies to administrators too. */
        if ($action === 'approve' || $action === 'reject') {
            $d = Contract_caps::kycDecisionAllowed(
                $actor, $case,
                (bool) self::get($in, 'holds_capability', false),
                (bool) self::get($in, 'is_admin', false),
                self::get($in, 'segregation_required', true) !== false
            );

            if (empty($d['allowed'])) {
                return self::deny($d['reason']);
            }
        }

        return self::allow($access['via'], 'kyc_' . $action . '_permitted');
    }

    /**
     * Which capability a KYC action needs. One map, so a new route cannot
     * invent its own answer.
     *
     * @param  string $action
     * @return string|null
     */
    public static function kycCapabilityForAction($action)
    {
        $map = array(
            'view'           => Contract_caps::CAP_KYC_VIEW,
            'review'         => Contract_caps::CAP_KYC_REVIEW,
            'approve'        => Contract_caps::CAP_KYC_APPROVE,
            'reject'         => Contract_caps::CAP_KYC_REJECT,
            'retry'          => Contract_caps::CAP_KYC_RETRY,
            'manual_review'  => Contract_caps::CAP_KYC_REVIEW,
            'invite'         => Contract_caps::CAP_KYC_REVIEW,
            'create_session' => Contract_caps::CAP_KYC_REVIEW,
        );

        return isset($map[$action]) ? $map[$action] : null;
    }

    /* ================================================================
     * 3 & 4. Evidence
     * ============================================================== */

    /**
     * May this actor VIEW this evidence type on this contract?
     *
     * Seven gates, in this order, and the order is the point. Contract access
     * comes before evidence type so that a person with no business on the
     * contract is refused without ever revealing which evidence exists on it;
     * retention and legal hold come after the permission checks so that a
     * refusal never has to say "you could have seen this, but it expired" to
     * somebody who was not entitled to it anyway.
     *
     *   1. contract access
     *   2. live assignment (inside canAccessContract)
     *   3. evidence-type capability
     *   4. retention status
     *   5. legal hold
     *   6. evidence exists and its hash verifies
     *   7. a stated reason where the type requires one
     *
     * @param  array $in {actor_id, contract_id, evidence_type, held_capabilities,
     *                    is_admin, has_view_all, has_view_own, contract_owner_id,
     *                    assignments, evidence, retention, legal_hold, reason, now}
     * @return array {allowed, reason, via, audit}
     */
    public static function canAccessEvidenceType(array $in)
    {
        $type = (string) self::get($in, 'evidence_type', '');

        if (!Contract_evidence_types::isType($type)) {
            return self::deny('unknown_evidence_type');
        }

        $needed = Contract_evidence_types::viewCapability($type);
        $held   = self::get($in, 'held_capabilities', array());
        $held   = is_array($held) ? $held : array();
        $isAdmin = !empty($in['is_admin']);

        /* GATE 1 + 2 */
        $access = self::canAccessContract(array(
            'actor_id'          => self::get($in, 'actor_id', 0),
            'contract_id'       => self::get($in, 'contract_id', 0),
            'contract_owner_id' => self::get($in, 'contract_owner_id', 0),
            'capability'        => $needed,
            'holds_capability'  => in_array($needed, $held, true),
            'has_view_all'      => self::get($in, 'has_view_all', false),
            'has_view_own'      => self::get($in, 'has_view_own', false),
            'is_admin'          => $isAdmin,
            'assignments'       => self::get($in, 'assignments', array()),
            'now'               => self::get($in, 'now', 0),
        ));

        if (empty($access['allowed'])) {
            return self::deny($access['reason']);
        }

        /*
         * GATE 3. Checked again, explicitly, and NOT skipped for an
         * administrator.
         *
         * canAccessContract() lets an administrator past its capability door,
         * which is correct for opening a contract. It is not correct for a
         * Video KYC recording: "is an administrator" and "has been granted
         * sight of biometric data" are different statements, and this is the
         * line where they stop being treated as the same one.
         */
        if (!in_array($needed, $held, true)) {
            return self::deny('missing_evidence_capability:' . $needed);
        }

        /* GATE 4. Retention. */
        $retention = self::get($in, 'retention', array());
        $hold      = self::get($in, 'legal_hold', array());
        $now       = (int) self::get($in, 'now', 0);

        $retained = self::retentionStatus($retention, $hold, $now);

        if (!$retained['available']) {
            return self::deny($retained['reason']);
        }

        /* GATE 6. The artefact itself. */
        $evidence = self::get($in, 'evidence', array());

        if (!is_array($evidence) || empty($evidence) || empty($evidence['storage_key'])) {
            return self::deny('evidence_not_stored');
        }

        if (!empty($evidence['deleted_at'])) {
            return self::deny('evidence_deleted');
        }

        $digest = (string) self::get($evidence, 'sha256', '');

        if (!Contract_evidence::validDigest($digest)) {
            return self::deny('evidence_hash_missing_or_malformed');
        }

        if (isset($evidence['hash_verified']) && empty($evidence['hash_verified'])) {
            return self::deny('evidence_hash_did_not_verify');
        }

        /* GATE 7. A reason, where the type asks for one. */
        if (Contract_evidence_types::needsReason($type)) {
            $why = trim((string) self::get($in, 'reason', ''));

            if (strlen($why) < 10) {
                return self::deny('access_reason_required');
            }
        }

        return self::allow($access['via'], 'evidence_view_permitted');
    }

    /**
     * May this actor DOWNLOAD it?
     *
     * Viewing first — a download is a view plus a file — then the separate
     * download capability, then the type-level compliance rule that refuses the
     * recording outright until a lawful basis exists.
     *
     * @param  array $in  as canAccessEvidenceType, plus {compliance}
     * @return array {allowed, reason, via, audit}
     */
    public static function canDownloadEvidenceType(array $in)
    {
        $view = self::canAccessEvidenceType($in);

        if (empty($view['allowed'])) {
            return $view;
        }

        $type = (string) self::get($in, 'evidence_type', '');

        $typeRule = Contract_evidence_types::downloadPermittedForType(
            $type, self::get($in, 'compliance', array())
        );

        if (empty($typeRule['allowed'])) {
            return self::deny($typeRule['reason']);
        }

        $needed = Contract_evidence_types::downloadCapability($type);
        $held   = self::get($in, 'held_capabilities', array());
        $held   = is_array($held) ? $held : array();

        /* Again, not skipped for an administrator. A download leaves this
           system permanently. */
        if ($needed === null || !in_array($needed, $held, true)) {
            return self::deny('missing_download_capability:' . (string) $needed);
        }

        return self::allow($view['via'], 'evidence_download_permitted');
    }

    /* ================================================================
     * 5 & 6. Retention and legal hold
     * ============================================================== */

    /**
     * Is the evidence still available, given retention and any hold?
     *
     * A legal hold makes evidence available past its retention date — that is
     * what a hold is for — and simultaneously forbids deletion. Retention
     * expiry with no hold makes it unavailable to everybody including an
     * administrator.
     *
     * @param  array $retention {retention_until}
     * @param  array $hold      {active, id}
     * @param  int   $now
     * @return array {available, reason}
     */
    public static function retentionStatus($retention, $hold, $now)
    {
        $retention = is_array($retention) ? $retention : array();
        $hold      = is_array($hold) ? $hold : array();
        $now       = (int) $now;

        $holdActive = !empty($hold['active']);
        $until      = (int) self::get($retention, 'retention_until', 0);

        if ($until > 0 && $now >= $until) {
            if ($holdActive) {
                return array('available' => true,
                             'reason'    => 'retained_under_legal_hold_past_expiry');
            }

            return array('available' => false, 'reason' => 'retention_period_expired');
        }

        return array('available' => true, 'reason' => 'within_retention_period');
    }

    /**
     * May this actor change the retention rule?
     *
     * Two refusals that are not about permissions at all: retention cannot be
     * shortened while a hold stands, and storing recordings needs an approved
     * period. Both bind administrators.
     *
     * @param  array $in {actor_id, holds_capability, is_admin, legal_hold,
     *                    current, proposed}
     * @return array {allowed, reason, audit}
     */
    public static function canChangeRetention(array $in)
    {
        if ((int) self::get($in, 'actor_id', 0) <= 0) {
            return self::deny('no_actor');
        }

        if (empty($in['holds_capability']) && empty($in['is_admin'])) {
            return self::deny('missing_capability:' . Contract_caps::CAP_KYC_MANAGE_RETENTION);
        }

        $current  = self::get($in, 'current', array());
        $proposed = self::get($in, 'proposed', array());
        $hold     = self::get($in, 'legal_hold', array());

        $currentDays  = (int) self::get($current, 'retention_days', 0);
        $proposedDays = (int) self::get($proposed, 'retention_days', 0);

        if (!empty($hold['active']) && $proposedDays > 0 && $currentDays > 0
            && $proposedDays < $currentDays) {
            return self::deny('cannot_shorten_retention_under_legal_hold');
        }

        if (!empty($proposed['retain_recordings']) && $proposedDays <= 0) {
            return self::deny('recordings_need_an_approved_retention_period');
        }

        return self::allow('capability', 'retention_change_permitted');
    }

    /**
     * May this actor apply or release a legal hold?
     *
     * Applying and releasing are separate capabilities, and this refuses when
     * one is presented in place of the other. `release` additionally refuses
     * when the actor is the person who applied the hold, where segregation is
     * required — a hold somebody can place and lift alone protects nothing from
     * that person.
     *
     * @param  array $in {actor_id, action, holds_apply, holds_release, is_admin,
     *                    hold, segregation_required}
     * @return array {allowed, reason, audit}
     */
    public static function canChangeLegalHold(array $in)
    {
        $actor  = (int) self::get($in, 'actor_id', 0);
        $action = (string) self::get($in, 'action', '');

        if ($actor <= 0) { return self::deny('no_actor'); }

        if ($action !== 'apply' && $action !== 'release') {
            return self::deny('unknown_legal_hold_action');
        }

        $isAdmin = !empty($in['is_admin']);

        if ($action === 'apply') {
            if (empty($in['holds_apply']) && !$isAdmin) {
                return self::deny('missing_capability:' . Contract_caps::CAP_KYC_APPLY_HOLD);
            }

            if (!empty($in['hold']) && !empty($in['hold']['active'])) {
                return self::deny('legal_hold_already_active');
            }

            return self::allow('capability', 'legal_hold_apply_permitted');
        }

        if (empty($in['holds_release']) && !$isAdmin) {
            return self::deny('missing_capability:' . Contract_caps::CAP_KYC_RELEASE_HOLD);
        }

        $hold = self::get($in, 'hold', array());

        if (empty($hold) || empty($hold['active'])) {
            return self::deny('no_active_legal_hold_to_release');
        }

        if (self::get($in, 'segregation_required', true) !== false) {
            $appliedBy = (int) self::get($hold, 'applied_by', 0);

            if ($appliedBy > 0 && $appliedBy === $actor) {
                return self::deny('releaser_may_not_be_the_person_who_applied_the_hold');
            }
        }

        return self::allow('capability', 'legal_hold_release_permitted');
    }

    /* ================================================================
     * Helpers
     * ============================================================== */

    /**
     * Every decision carries audit => true.
     *
     * Not a flag the caller may set: it is returned by both allow() and deny()
     * so there is no shape of result that says "do not log this". A denied
     * attempt to open a Video KYC recording is at least as interesting as a
     * successful one.
     */
    private static function allow($via, $reason)
    {
        return array('allowed' => true, 'reason' => (string) $reason,
                     'via' => (string) $via, 'audit' => true);
    }

    private static function deny($reason)
    {
        return array('allowed' => false, 'reason' => (string) $reason,
                     'via' => null, 'audit' => true);
    }

    private static function get($a, $k, $default = null)
    {
        return (is_array($a) && array_key_exists($k, $a)) ? $a[$k] : $default;
    }
}
