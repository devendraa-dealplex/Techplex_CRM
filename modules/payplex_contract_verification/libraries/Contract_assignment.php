<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Contract_assignment
 *
 * Who is attached to which contract, in what role, and whether that attachment
 * is still live.
 *
 * WHY THIS EXISTS
 * ---------------
 * Perfex answers contract visibility with two settings: *all contracts*, or
 * *contracts I created*. There is no third answer. So a KYC reviewer who should
 * see four cases this month has exactly two options today — everything, or
 * nothing — and "everything" is what gets chosen, because the alternative stops
 * them working. The permission then means nothing on the accounts it was
 * written for.
 *
 * An assignment is the missing third answer. It is additive and it is scoped to
 * ONE contract: holding an assignment on contract 12 says nothing whatsoever
 * about contract 13. That sounds obvious and it is exactly the property that
 * gets lost when somebody caches "this user is a reviewer" as a boolean.
 *
 * WHAT AN ASSIGNMENT IS NOT
 * -------------------------
 * It is not a capability. A reviewer assigned to a contract who does not hold
 * `contract_kyc_review` may still not review it, and a person holding every
 * capability with no assignment and no view-all still may not open it. Both
 * doors, every time — the same rule the module already applies to contracts,
 * extended to the thing Perfex could not express.
 *
 * WHY REVOCATION IS A COLUMN AND NOT A DELETE
 * -------------------------------------------
 * "Who could see this customer's identity documents in March" is a question
 * that gets asked after something has gone wrong, and a deleted row answers it
 * with silence. Revoking sets `revoked_at`, `revoked_by` and a reason, and the
 * row stays. Changing a contract's owner does not touch assignment history
 * either: the history is the record of who had access, not a description of who
 * has it now.
 */
class Contract_assignment
{
    /* ---- the seven assignment roles ---------------------------------- */

    const ROLE_OWNER           = 'contract_owner';
    const ROLE_MAKER           = 'maker';
    const ROLE_CHECKER         = 'checker';
    const ROLE_FINANCE         = 'finance_reviewer';
    const ROLE_KYC_REVIEWER    = 'kyc_reviewer';
    const ROLE_LEGAL           = 'legal_reviewer';
    const ROLE_OBSERVER        = 'read_only_observer';

    /**
     * Each role, what it is for, and the capabilities an assignment in that
     * role is allowed to unlock.
     *
     * `unlocks` is a CEILING, not a grant. The actor must hold the capability
     * as well. A read-only observer assigned to a contract cannot approve it
     * however many capabilities they were given elsewhere, because approve is
     * not in their list; and a checker who does not hold `contract_signing_approve`
     * cannot approve it either, because the assignment grants nothing by itself.
     *
     * @return array
     */
    public static function roles()
    {
        return array(

            self::ROLE_OWNER => array(
                'label'   => 'Contract owner',
                'means'   => 'Accountable for the contract. Broadest assignment, still not admin.',
                'unlocks' => array(
                    Contract_caps::CAP_VIEW, Contract_caps::CAP_PREPARE,
                    Contract_caps::CAP_SEND, Contract_caps::CAP_CANCEL,
                    Contract_caps::CAP_VIEW_EVIDENCE, Contract_caps::CAP_KYC_VIEW,
                ),
            ),

            self::ROLE_MAKER => array(
                'label'   => 'Maker',
                'means'   => 'Prepares the contract and submits it for approval. Never approves it.',
                'unlocks' => array(
                    Contract_caps::CAP_VIEW, Contract_caps::CAP_PREPARE,
                    Contract_caps::CAP_KYC_VIEW,
                ),
            ),

            self::ROLE_CHECKER => array(
                'label'   => 'Checker',
                'means'   => 'Approves or rejects what a maker submitted. Never the same person.',
                'unlocks' => array(
                    Contract_caps::CAP_VIEW, Contract_caps::CAP_APPROVE,
                    Contract_caps::CAP_VIEW_EVIDENCE, Contract_caps::CAP_KYC_VIEW,
                ),
            ),

            self::ROLE_FINANCE => array(
                'label'   => 'Finance reviewer',
                'means'   => 'Confirms the commercial terms and that execution completed. '
                           . 'Summary-level KYC only — a finance check never needed a face.',
                'unlocks' => array(
                    Contract_caps::CAP_VIEW, Contract_caps::CAP_VIEW_EVIDENCE,
                    Contract_caps::CAP_KYC_VIEW,
                ),
            ),

            self::ROLE_KYC_REVIEWER => array(
                'label'   => 'KYC reviewer',
                'means'   => 'Inspects verification evidence and decides the case. '
                           . 'Cannot send a contract, change a roster or touch provider settings.',
                'unlocks' => array(
                    Contract_caps::CAP_KYC_VIEW, Contract_caps::CAP_KYC_REVIEW,
                    Contract_caps::CAP_KYC_APPROVE, Contract_caps::CAP_KYC_REJECT,
                    Contract_caps::CAP_KYC_RETRY, Contract_caps::CAP_KYC_VIEW_DOCUMENTS,
                    Contract_caps::CAP_KYC_VIEW_VIDEO,
                ),
            ),

            self::ROLE_LEGAL => array(
                'label'   => 'Legal reviewer',
                'means'   => 'Reviews the executed record and manages holds. '
                           . 'Raw biometric material is not part of that job by default.',
                'unlocks' => array(
                    Contract_caps::CAP_VIEW, Contract_caps::CAP_VIEW_EVIDENCE,
                    Contract_caps::CAP_KYC_VIEW, Contract_caps::CAP_KYC_APPLY_HOLD,
                ),
            ),

            self::ROLE_OBSERVER => array(
                'label'   => 'Read-only observer',
                'means'   => 'May look at status. May change nothing and download nothing.',
                'unlocks' => array(Contract_caps::CAP_VIEW, Contract_caps::CAP_KYC_VIEW),
            ),
        );
    }

    /** @return array */
    public static function allRoles()
    {
        return array_keys(self::roles());
    }

    /** @param string $role @return bool */
    public static function isRole($role)
    {
        return array_key_exists((string) $role, self::roles());
    }

    /**
     * What an assignment in this role may unlock.
     *
     * @param  string $role
     * @return array
     */
    public static function unlockedBy($role)
    {
        $r = self::roles();

        return isset($r[$role]) ? $r[$role]['unlocks'] : array();
    }

    /**
     * Roles that must never unlock the send capability.
     *
     * Stated positively somewhere a person will read it, because the pressure
     * to "just let the reviewer send it, they are right there" is the exact
     * force this increment exists to resist.
     *
     * @return array
     */
    public static function rolesThatMayNeverSend()
    {
        return array(self::ROLE_KYC_REVIEWER, self::ROLE_LEGAL,
                     self::ROLE_FINANCE, self::ROLE_OBSERVER, self::ROLE_CHECKER);
    }

    /**
     * Is this assignment row live, right now, for this contract?
     *
     * Four ways to be dead, and the order matters only for the reason string:
     * inactive, revoked, expired, or simply belonging to a different contract.
     * That last one is not a formality — an assignment object handed to the
     * wrong contract is precisely the IDOR this table could otherwise create,
     * so the contract id is a REQUIRED argument rather than something the
     * caller is trusted to have checked.
     *
     * @param  array $row        {contract_id, staff_id, assignment_role, is_active,
     *                            expires_at, revoked_at}
     * @param  int   $contractId the contract actually being accessed
     * @param  int   $staffId    the actor actually asking
     * @param  int   $now
     * @return array {valid, reason}
     */
    public static function isLive(array $row, $contractId, $staffId, $now)
    {
        $contractId = (int) $contractId;
        $staffId    = (int) $staffId;
        $now        = (int) $now;

        if ($contractId <= 0 || $staffId <= 0) {
            return self::no('no_subject');
        }

        if ((int) (isset($row['contract_id']) ? $row['contract_id'] : 0) !== $contractId) {
            return self::no('assignment_is_for_a_different_contract');
        }

        if ((int) (isset($row['staff_id']) ? $row['staff_id'] : 0) !== $staffId) {
            return self::no('assignment_belongs_to_a_different_person');
        }

        if (!self::isRole(isset($row['assignment_role']) ? $row['assignment_role'] : '')) {
            return self::no('unknown_assignment_role');
        }

        if (empty($row['is_active'])) {
            return self::no('assignment_is_not_active');
        }

        if (!empty($row['revoked_at']) && (int) $row['revoked_at'] > 0) {
            return self::no('assignment_was_revoked');
        }

        $expires = (int) (isset($row['expires_at']) ? $row['expires_at'] : 0);

        if ($expires > 0 && $now >= $expires) {
            return self::no('assignment_has_expired');
        }

        return array('valid' => true, 'reason' => 'assignment_is_live');
    }

    /**
     * Does any live assignment in this set unlock the capability?
     *
     * Returns the reason as well as the verdict, because "you have an
     * assignment but it expired on Tuesday" and "you were never assigned" are
     * different problems and the person reading the screen needs to know which.
     *
     * @param  array  $rows
     * @param  int    $contractId
     * @param  int    $staffId
     * @param  string $capability
     * @param  int    $now
     * @return array {allowed, reason, via_role}
     */
    public static function unlocks(array $rows, $contractId, $staffId, $capability, $now)
    {
        $sawSomething = false;
        $lastReason   = 'no_assignment_on_this_contract';

        foreach ($rows as $row) {
            $live = self::isLive($row, $contractId, $staffId, $now);

            if (empty($live['valid'])) {
                /* Only report a near-miss for rows that were about this person
                   and this contract; a row for somebody else is not a reason. */
                if ($live['reason'] === 'assignment_was_revoked'
                    || $live['reason'] === 'assignment_has_expired'
                    || $live['reason'] === 'assignment_is_not_active') {
                    $sawSomething = true;
                    $lastReason   = $live['reason'];
                }
                continue;
            }

            if (in_array($capability, self::unlockedBy($row['assignment_role']), true)) {
                return array('allowed' => true, 'reason' => 'unlocked_by_assignment',
                             'via_role' => $row['assignment_role']);
            }

            $sawSomething = true;
            $lastReason   = 'assignment_role_does_not_unlock_this_action';
        }

        return array('allowed' => false,
                     'reason'  => $sawSomething ? $lastReason : 'no_assignment_on_this_contract',
                     'via_role' => null);
    }

    /* ---- helpers ------------------------------------------------------- */

    private static function no($reason)
    {
        return array('valid' => false, 'reason' => (string) $reason);
    }
}
