<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_safety
 *
 * Pure, dependency-free safety gate. Decides whether a proposed agent action is
 * allowed to actually execute, given the agent's mode, approval state, kill
 * switches, confidence and budget. Called by the runner BEFORE any side effect.
 *
 * Design principle: fail closed. Anything not positively permitted is blocked
 * and routed to the human-review queue.
 */
class Payplex_agent_safety
{
    /**
     * Actions that must NEVER run without an explicit, per-run human approval,
     * regardless of agent config. These are the irreversible / high-risk ones
     * from the spec.
     */
    /**
     * Is there anything on this install that can actually RUN an agent for real?
     *
     * No. And that matters, because the module will happily record that an
     * agent is live.
     *
     * evaluate() below carries a complete production branch — never-autonomous
     * actions, per-run human approval, a confidence gate, an approved-by
     * requirement. Payplex_agent_lifecycle::canRunProduction() decides whether
     * an agent qualifies. activateProduction() writes mode = production onto
     * the row. All of it is written, and all of it is unreachable:
     *
     *   - the only runner is Payplex_agent_sandbox::run(), whose single caller
     *     is the model's test-run path;
     *   - that runner hard-codes MODE_SANDBOX when it calls evaluate(), so
     *     evaluate() returns at step 3 every time and steps 4 to 7 have never
     *     executed outside a test;
     *   - canRunProduction() is called from nowhere at all.
     *
     * So promoting an agent to production changes a label and nothing else. The
     * dashboard would show an agent as live while nothing would ever run it —
     * the same shape as a calling integration that is switched on and has never
     * authenticated, or an audit panel reading "Verified — 0 entries".
     *
     * Stated once, here, so the promotion path and the tests agree about it.
     * When a production runner is built, flip this to true; the suite will then
     * require the runner to exist rather than take the flag's word for it.
     */
    const PRODUCTION_RUNNER_IMPLEMENTED = false;

    public static function productionExecutionAvailable()
    {
        if (self::PRODUCTION_RUNNER_IMPLEMENTED) {
            return array('available' => true, 'reason' => '');
        }
        return array('available' => false, 'reason' => 'no_production_runner');
    }

    /** What to tell a person who tried to put an agent live. */
    public static function productionUnavailableMessage()
    {
        return 'This agent cannot be activated in production mode: nothing on this install '
             . 'executes agents outside the sandbox. Promoting it would mark it live while it '
             . 'would never run. Sandbox test runs remain available.';
    }

    public static function approvalRequiredActions()
    {
        return array(
            'make_real_call',
            'send_bulk_message',
            'send_live_message',
            'issue_refund',
            'make_payout',
            'modify_financial_record',
            'delete_crm_record',
            'change_user_permission',
            'reveal_confidential_data',
            'purchase_service',
            'activate_paid_service',
            'deploy_code',
            'execute_sql',
            'execute_server_command',
        );
    }

    /**
     * Actions that are outright prohibited for an agent to perform autonomously
     * in ANY mode (they may only ever happen through a human doing them). These
     * overlap with approval-required but represent the "never by the agent" set.
     */
    public static function neverAutonomousActions()
    {
        return array(
            'execute_sql',
            'execute_server_command',
            'deploy_code',
            'change_user_permission',
            'make_payout',
            'issue_refund',
            'modify_financial_record',
        );
    }

    public static function isApprovalRequired($action)
    {
        return in_array($action, self::approvalRequiredActions(), true);
    }

    public static function isNeverAutonomous($action)
    {
        return in_array($action, self::neverAutonomousActions(), true);
    }

    /**
     * Central decision. Returns:
     *   ['allow'=>true]
     *   ['allow'=>false, 'reason'=>'<code>', 'escalate'=>bool]
     *
     * $ctx keys (all optional, sensible defaults):
     *   mode                 'sandbox'|'production'
     *   status               lifecycle status
     *   approved_by          int
     *   global_kill          bool  (system-wide emergency stop)
     *   agent_kill           bool  (per-agent emergency stop)
     *   confidence           float 0..1
     *   confidence_threshold float 0..1
     *   action_approved      bool  (a human pre-approved THIS run's action)
     *   budget               ['tokens_used','token_limit','spent','budget','daily_runs','daily_limit']
     */
    public static function evaluate($action, array $ctx = array())
    {
        $mode = isset($ctx['mode']) ? $ctx['mode'] : Payplex_agent_lifecycle::MODE_SANDBOX;

        // 1. Kill switches trump everything.
        if (!empty($ctx['global_kill'])) {
            return self::deny('global_kill_switch', false);
        }
        if (!empty($ctx['agent_kill'])) {
            return self::deny('agent_kill_switch', false);
        }

        // 2. Budget / rate limits.
        $b = isset($ctx['budget']) && is_array($ctx['budget']) ? $ctx['budget'] : array();
        $budgetCheck = self::checkBudget($b);
        if ($budgetCheck !== true) {
            return self::deny($budgetCheck, false);
        }

        // 3. In sandbox mode, side-effecting actions are simulated, never real.
        //    So sandbox always "allows" but the runner must treat it as dry-run.
        if ($mode !== Payplex_agent_lifecycle::MODE_PRODUCTION) {
            return array('allow' => true, 'simulated' => true);
        }

        // ---- From here: production mode, real side effects possible. ----

        // 4. Never-autonomous actions can only proceed with an explicit approval
        //    for this exact run.
        if (self::isNeverAutonomous($action) && empty($ctx['action_approved'])) {
            return self::deny('action_not_permitted_autonomously', true);
        }

        // 5. Approval-required actions need a per-run human approval.
        if (self::isApprovalRequired($action) && empty($ctx['action_approved'])) {
            return self::deny('approval_required', true);
        }

        // 6. Confidence gate — low confidence escalates to human review.
        $conf = isset($ctx['confidence']) ? (float) $ctx['confidence'] : 1.0;
        $thr  = isset($ctx['confidence_threshold']) ? (float) $ctx['confidence_threshold'] : 0.0;
        if ($thr > 0 && $conf < $thr) {
            return self::deny('low_confidence', true);
        }

        // 7. Production side effects require an approved agent.
        if ((int) (isset($ctx['approved_by']) ? $ctx['approved_by'] : 0) <= 0) {
            return self::deny('agent_not_approved', true);
        }

        return array('allow' => true, 'simulated' => false);
    }

    /**
     * Budget/limit enforcement. Returns true if within limits, else a reason
     * code. A breached hard limit is also the trigger for auto-pause upstream.
     */
    public static function checkBudget(array $b)
    {
        $tokensUsed = isset($b['tokens_used']) ? (float) $b['tokens_used'] : 0;
        $tokenLimit = isset($b['token_limit']) ? (float) $b['token_limit'] : 0;
        if ($tokenLimit > 0 && $tokensUsed >= $tokenLimit) {
            return 'token_limit_exceeded';
        }

        $spent  = isset($b['spent']) ? (float) $b['spent'] : 0;
        $budget = isset($b['budget']) ? (float) $b['budget'] : 0;
        if ($budget > 0 && $spent >= $budget) {
            return 'budget_exceeded';
        }

        $dailyRuns  = isset($b['daily_runs']) ? (int) $b['daily_runs'] : 0;
        $dailyLimit = isset($b['daily_limit']) ? (int) $b['daily_limit'] : 0;
        if ($dailyLimit > 0 && $dailyRuns >= $dailyLimit) {
            return 'daily_limit_exceeded';
        }

        return true;
    }

    /**
     * Is spend past the warning threshold (but not yet the hard limit)?
     * Returns true when a warning should be surfaced.
     */
    public static function isWarningThreshold(array $b, $warnPct = 0.8)
    {
        $spent  = isset($b['spent']) ? (float) $b['spent'] : 0;
        $budget = isset($b['budget']) ? (float) $b['budget'] : 0;
        if ($budget <= 0) {
            return false;
        }
        return ($spent / $budget) >= $warnPct && $spent < $budget;
    }

    /**
     * Summarise budget health for a dashboard. Returns:
     *   ['level'=>'ok'|'warning'|'exceeded', 'pct'=>float(0..1+), 'over'=>bool]
     * Uses whichever of spend/budget or tokens_used/token_limit gives the
     * higher utilisation, so a breach on either dimension is surfaced.
     */
    public static function budgetStatus(array $b, $warnPct = 0.8)
    {
        $pcts = array();
        $budget = isset($b['budget']) ? (float) $b['budget'] : 0;
        if ($budget > 0) {
            $pcts[] = (isset($b['spent']) ? (float) $b['spent'] : 0) / $budget;
        }
        $tl = isset($b['token_limit']) ? (float) $b['token_limit'] : 0;
        if ($tl > 0) {
            $pcts[] = (isset($b['tokens_used']) ? (float) $b['tokens_used'] : 0) / $tl;
        }
        $pct = empty($pcts) ? 0.0 : max($pcts);
        $level = 'ok';
        if ($pct >= 1.0) {
            $level = 'exceeded';
        } elseif ($pct >= $warnPct) {
            $level = 'warning';
        }
        return array('level' => $level, 'pct' => round($pct, 4), 'over' => $pct >= 1.0);
    }

    private static function deny($reason, $escalate)
    {
        return array('allow' => false, 'reason' => $reason, 'escalate' => (bool) $escalate);
    }
}
