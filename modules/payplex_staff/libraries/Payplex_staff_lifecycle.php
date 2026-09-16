<?php

defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Payplex_staff_lifecycle
 *
 * The staff record state machine and the access/financial gates that hang off
 * the status. Pure + dependency-free.
 *
 *   draft -> documentation_pending -> submitted -> verified -> approved
 *         -> active -> suspended -> notice_period -> exited -> archived
 *
 * Hard rules encoded here (see the unit tests for the exact contract):
 *  - The staff CREATOR can never approve the same record (maker != approver).
 *  - Approval needs an approver capability.
 *  - Incomplete KYC/bank blocks FINANCIAL payout only — not ordinary work.
 *  - Suspended / exited / archived lose login and new-assignment access.
 *  - Changing employment type must create a new effective-dated version.
 *  - Historical commission is never edited retroactively without an approved
 *    adjustment.
 */
class Payplex_staff_lifecycle
{
    public static function states()
    {
        return array(
            'draft', 'documentation_pending', 'submitted', 'verified', 'approved',
            'active', 'suspended', 'notice_period', 'exited', 'archived',
        );
    }

    public static function isTerminal($status)
    {
        return $status === 'archived';
    }

    /** action => [from => to]. Approval/verify carry extra guards below. */
    private static function map()
    {
        return array(
            'submit_docs'  => array('draft' => 'documentation_pending'),
            'submit'       => array('documentation_pending' => 'submitted', 'draft' => 'submitted', 'returned' => 'submitted'),
            'verify'       => array('submitted' => 'verified'),
            'return'       => array('submitted' => 'documentation_pending', 'verified' => 'documentation_pending'),
            'approve'      => array('verified' => 'approved'),
            'activate'     => array('approved' => 'active'),
            'suspend'      => array('active' => 'suspended'),
            'reinstate'    => array('suspended' => 'active'),
            'issue_notice' => array('active' => 'notice_period'),
            'exit'         => array('notice_period' => 'exited', 'suspended' => 'exited', 'active' => 'exited'),
            'archive'      => array('exited' => 'archived'),
        );
    }

    /**
     * Attempt a transition.
     * @param array $record  needs 'status' and (for approve) 'created_by'
     * @param string $action
     * @param array $ctx     actor_id, is_approver(bool), is_verifier(bool)
     * @return array ok/status/error
     */
    public static function transition($record, $action, array $ctx = array())
    {
        $status = isset($record['status']) ? $record['status'] : 'draft';
        $map = self::map();
        if (!isset($map[$action])) {
            return array('ok' => false, 'error' => 'unknown_action', 'status' => $status);
        }
        if (!isset($map[$action][$status])) {
            return array('ok' => false, 'error' => 'invalid_transition', 'status' => $status);
        }

        if ($action === 'approve') {
            if (empty($ctx['is_approver'])) {
                return array('ok' => false, 'error' => 'not_authorized_to_approve', 'status' => $status);
            }
            $creator = isset($record['created_by']) ? (int) $record['created_by'] : 0;
            $actor   = isset($ctx['actor_id']) ? (int) $ctx['actor_id'] : 0;
            if ($creator > 0 && $creator === $actor) {
                return array('ok' => false, 'error' => 'maker_cannot_approve_own_record', 'status' => $status);
            }
        }

        return array('ok' => true, 'status' => $map[$action][$status], 'from' => $status);
    }

    /* ---------------- access gates ---------------- */

    public static function canLogin($status)
    {
        return !in_array($status, array('suspended', 'exited', 'archived'), true);
    }

    public static function canReceiveAssignment($status)
    {
        // suspended / exited / archived may not receive NEW assignments.
        return !in_array($status, array('suspended', 'exited', 'archived'), true);
    }

    /** Ordinary non-financial work: allowed whenever the staff is active-ish. */
    public static function canDoNonFinancialWork($record)
    {
        $status = isset($record['status']) ? $record['status'] : '';
        return in_array($status, array('active', 'notice_period'), true);
    }

    /** Financial payout requires active status AND complete KYC AND verified bank. */
    public static function canFinancialPayout($record)
    {
        $status = isset($record['status']) ? $record['status'] : '';
        if ($status !== 'active') { return false; }
        $kyc  = isset($record['kyc_status']) ? $record['kyc_status'] : '';
        $bank = isset($record['bank_verified']) ? (int) $record['bank_verified'] : 0;
        return $kyc === 'verified' && $bank === 1;
    }

    /* ---------------- versioning & retro rules ---------------- */

    /** Changing employment type requires a new effective-dated version. */
    public static function shouldVersion($oldType, $newType)
    {
        return (string) $oldType !== (string) $newType;
    }

    /** Historical commission is immutable unless an approved adjustment exists. */
    public static function allowsHistoricalCommissionEdit($ctx)
    {
        return !empty($ctx['has_approved_adjustment']);
    }

    /** Human-facing label class for a status (Bootstrap label). */
    public static function statusClass($status)
    {
        switch ($status) {
            case 'active':   return 'success';
            case 'approved': return 'success';
            case 'verified': return 'info';
            case 'submitted': return 'info';
            case 'suspended': return 'warning';
            case 'notice_period': return 'warning';
            case 'documentation_pending': return 'warning';
            case 'exited':   return 'danger';
            case 'archived': return 'default';
            default:         return 'default';
        }
    }
}
