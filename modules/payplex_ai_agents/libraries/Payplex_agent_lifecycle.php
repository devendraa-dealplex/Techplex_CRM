<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_lifecycle
 *
 * Pure, dependency-free state machine for an AI agent's lifecycle. No CodeIgniter
 * or DB access here so it can be unit-tested in isolation and reused by the model,
 * controllers and the sandbox runner.
 *
 * Flow (per spec):
 *   Draft -> Sandbox -> Testing -> Submitted -> Approved -> Scheduled/Active -> Paused -> Archived
 *
 * Hard rules enforced here:
 *  - New agents begin in DRAFT with mode = sandbox.
 *  - An agent can only reach a PRODUCTION run after it is APPROVED, and the
 *    approver must be a different user from both the creator and the submitter
 *    (maker != checker). An agent can never approve/activate itself.
 */
class Payplex_agent_lifecycle
{
    const DRAFT     = 'draft';
    const SANDBOX   = 'sandbox';
    const TESTING   = 'testing';
    const SUBMITTED = 'submitted';
    const APPROVED  = 'approved';
    const SCHEDULED = 'scheduled';
    const ACTIVE    = 'active';
    const PAUSED    = 'paused';
    const ARCHIVED  = 'archived';

    const MODE_SANDBOX    = 'sandbox';
    const MODE_PRODUCTION = 'production';

    /** All valid statuses. */
    public static function statuses()
    {
        return array(
            self::DRAFT, self::SANDBOX, self::TESTING, self::SUBMITTED,
            self::APPROVED, self::SCHEDULED, self::ACTIVE, self::PAUSED, self::ARCHIVED,
        );
    }

    /**
     * Action -> [allowed-from-statuses => resulting-status].
     * The single source of truth for what a user may do to an agent in a status.
     */
    public static function transitions()
    {
        return array(
            'move_to_sandbox' => array(self::DRAFT => self::SANDBOX),
            'start_testing'   => array(self::SANDBOX => self::TESTING, self::DRAFT => self::TESTING),
            'submit'          => array(self::TESTING => self::SUBMITTED, self::SANDBOX => self::SUBMITTED),
            'approve'         => array(self::SUBMITTED => self::APPROVED),
            'reject'          => array(self::SUBMITTED => self::DRAFT),
            'schedule'        => array(self::APPROVED => self::SCHEDULED),
            'activate'        => array(self::APPROVED => self::ACTIVE, self::SCHEDULED => self::ACTIVE, self::PAUSED => self::ACTIVE),
            'pause'           => array(self::ACTIVE => self::PAUSED, self::SCHEDULED => self::PAUSED),
            'resume'          => array(self::PAUSED => self::ACTIVE),
            'new_version'     => array(self::APPROVED => self::DRAFT, self::ACTIVE => self::DRAFT, self::PAUSED => self::DRAFT),
            'archive'         => array(
                self::DRAFT => self::ARCHIVED, self::SANDBOX => self::ARCHIVED, self::TESTING => self::ARCHIVED,
                self::SUBMITTED => self::ARCHIVED, self::APPROVED => self::ARCHIVED, self::SCHEDULED => self::ARCHIVED,
                self::ACTIVE => self::ARCHIVED, self::PAUSED => self::ARCHIVED,
            ),
            'restore'         => array(self::ARCHIVED => self::DRAFT),
        );
    }

    public static function isValidStatus($status)
    {
        return in_array($status, self::statuses(), true);
    }

    /**
     * Resolve a transition. Returns:
     *   ['ok' => true,  'to' => '<status>']
     *   ['ok' => false, 'error' => '<reason>']
     *
     * $context carries the actor + agent metadata needed for guarded actions:
     *   ['actor_id'=>int, 'created_by'=>int, 'submitted_by'=>int, 'is_admin'=>bool]
     */
    public static function apply($action, $fromStatus, array $context = array())
    {
        $map = self::transitions();
        if (!isset($map[$action])) {
            return array('ok' => false, 'error' => 'unknown_action');
        }
        if (!isset($map[$action][$fromStatus])) {
            return array('ok' => false, 'error' => 'invalid_transition');
        }
        $to = $map[$action][$fromStatus];

        // Maker/checker enforcement on approval.
        if ($action === 'approve') {
            $guard = self::guardApproval($context);
            if ($guard !== true) {
                return array('ok' => false, 'error' => $guard);
            }
        }

        return array('ok' => true, 'to' => $to);
    }

    /**
     * Maker != checker. The approver must be set and must differ from both the
     * creator and the submitter. If the agent has a specific approver_id
     * configured, only that staff member may approve it (on top of, not instead
     * of, the maker != checker rule). Returns true when allowed, else an error code.
     */
    public static function guardApproval(array $context)
    {
        $actor      = isset($context['actor_id']) ? (int) $context['actor_id'] : 0;
        $createdBy  = isset($context['created_by']) ? (int) $context['created_by'] : 0;
        $submitted  = isset($context['submitted_by']) ? (int) $context['submitted_by'] : 0;
        $approverId = isset($context['approver_id']) ? (int) $context['approver_id'] : 0;

        if ($actor <= 0) {
            return 'approver_required';
        }
        if ($submitted > 0 && $actor === $submitted) {
            return 'maker_checker_violation';
        }
        if ($createdBy > 0 && $actor === $createdBy) {
            return 'maker_checker_violation';
        }
        // Only enforced when the agent actually names a specific approver -
        // leaving it unset keeps the old "any eligible staff member" behaviour.
        if ($approverId > 0 && $actor !== $approverId) {
            return 'not_designated_approver';
        }
        return true;
    }

    /**
     * Whether an agent in this status/mode is allowed to perform a PRODUCTION
     * (real-world side-effect) run. Only approved+active production-mode agents
     * qualify; everything else is confined to sandbox dry-runs.
     */
    public static function canRunProduction($status, $mode, $approvedBy = 0)
    {
        return $mode === self::MODE_PRODUCTION
            && in_array($status, array(self::ACTIVE, self::SCHEDULED), true)
            && (int) $approvedBy > 0;
    }

    /** Sandbox dry-runs are allowed from any non-archived status. */
    public static function canRunSandbox($status)
    {
        return $status !== self::ARCHIVED;
    }

    /** The status a brand-new agent must start in. */
    public static function initialStatus()
    {
        return self::DRAFT;
    }
}
