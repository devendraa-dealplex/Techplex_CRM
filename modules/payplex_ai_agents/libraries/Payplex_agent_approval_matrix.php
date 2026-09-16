<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_approval_matrix
 *
 * Pure, dependency-free decision-authority logic for the Plex Group AI
 * Executive Leadership System. Given an action (and optional amount/context)
 * it returns the REQUIRED approval tier. This is the gate that guarantees a
 * high-risk action can never be executed without the Chairman.
 *
 * Tiers (ascending authority): auto < manager < chairman.
 * Fail-closed: anything unknown, irreversible, or above a manager limit is
 * escalated to the Chairman.
 */
class Payplex_agent_approval_matrix
{
    const AUTO     = 'auto';
    const MANAGER  = 'manager';
    const CHAIRMAN = 'chairman';

    /** Ordering so callers can compare "is X at least Y". */
    public static function rank($tier)
    {
        $r = array(self::AUTO => 0, self::MANAGER => 1, self::CHAIRMAN => 2);
        return isset($r[$tier]) ? $r[$tier] : 2; // unknown -> highest
    }

    /**
     * Actions an agent may execute automatically: low-risk, reversible,
     * pre-approved. Everything here is safe to run in production without a human.
     */
    public static function autoActions()
    {
        return array(
            'internal_summary', 'dashboard', 'lead_scoring', 'create_task',
            'create_reminder', 'draft_document', 'draft_campaign',
            'data_quality_alert', 'sla_alert', 'approved_report', 'crm_update_permitted',
            'sandbox_simulation', 'notify_manager', 'risk_score', 'compliance_check',
        );
    }

    /** Actions that need MANAGER approval, subject to configurable limits. */
    public static function managerActions()
    {
        return array(
            'lead_reassignment', 'routine_discount', 'expense_recommendation',
            'campaign_scheduling', 'workflow_change_nonsensitive', 'staff_task_reallocation',
        );
    }

    /**
     * Actions that ALWAYS require the Chairman. Irreversible / high-risk /
     * public / financial / legal / security-sensitive.
     */
    public static function chairmanActions()
    {
        return array(
            'production_deployment', 'new_product_launch', 'material_pricing_change',
            'budget_increase', 'real_payout', 'large_expense', 'compensation_change',
            'hiring_decision', 'termination_decision', 'contract_commitment',
            'legal_filing', 'regulatory_submission', 'public_statement',
            'paid_marketing_activation', 'real_bulk_communication', 'real_customer_calling',
            'call_recording', 'sensitive_data_export', 'security_policy_exception',
            'payment_provider_change', 'acquisition_or_investment', 'partnership_commitment',
            'irreversible_action',
        );
    }

    /**
     * Classify an action to its required tier.
     *
     * @param string $action  action key
     * @param array  $ctx     optional: amount (float), reversible (bool),
     *                        manager_limit (float, default 0 = every $ needs chairman
     *                        once it's a manager action with an amount), custom_rules
     *                        (map action=>tier), category (auto|manager|chairman) hint.
     * @return array  ['tier'=>..., 'reason'=>...]
     */
    public static function classify($action, array $ctx = array())
    {
        $action = strtolower(trim((string) $action));

        // 1) explicit custom rules (admin-configured) win, but can only RAISE
        //    the tier above the built-in floor, never lower a chairman action.
        $custom = isset($ctx['custom_rules']) && is_array($ctx['custom_rules']) ? $ctx['custom_rules'] : array();

        $floor = self::builtinTier($action);

        $tier = $floor;
        $reason = 'builtin:' . $floor;

        if (isset($custom[$action]) && in_array($custom[$action], array(self::AUTO, self::MANAGER, self::CHAIRMAN), true)) {
            // take the STRICTER of custom vs floor (never weaker than the floor)
            if (self::rank($custom[$action]) > self::rank($tier)) {
                $tier = $custom[$action];
                $reason = 'custom_rule';
            } elseif (self::rank($custom[$action]) < self::rank($tier) && $floor === self::CHAIRMAN) {
                // attempt to weaken a chairman-floor action: ignored, stays chairman
                $reason = 'custom_ignored_chairman_floor';
            }
        }

        // 2) irreversible always escalates to chairman
        if (isset($ctx['reversible']) && $ctx['reversible'] === false) {
            if (self::rank(self::CHAIRMAN) > self::rank($tier)) {
                $tier = self::CHAIRMAN;
                $reason = 'irreversible';
            }
        }

        // 3) amount thresholds: a manager-tier action over the manager limit
        //    escalates to chairman.
        if ($tier === self::MANAGER && isset($ctx['amount'])) {
            $amount = (float) $ctx['amount'];
            $limit  = isset($ctx['manager_limit']) ? (float) $ctx['manager_limit'] : 0.0;
            if ($limit > 0 && $amount > $limit) {
                $tier = self::CHAIRMAN;
                $reason = 'amount_over_manager_limit';
            }
        }

        return array('tier' => $tier, 'reason' => $reason);
    }

    /** The built-in floor tier for an action (unknown -> chairman, fail-closed). */
    public static function builtinTier($action)
    {
        if (in_array($action, self::autoActions(), true))     { return self::AUTO; }
        if (in_array($action, self::managerActions(), true))  { return self::MANAGER; }
        if (in_array($action, self::chairmanActions(), true)) { return self::CHAIRMAN; }
        return self::CHAIRMAN; // unknown action -> highest authority
    }

    /**
     * Can an actor with $actorTier authority approve something that requires
     * $requiredTier? (Delegation is handled separately with an amount limit.)
     */
    public static function canApprove($actorTier, $requiredTier)
    {
        return self::rank($actorTier) >= self::rank($requiredTier);
    }

    /** Default seed rows for the configurable approval matrix (action => tier). */
    public static function defaultRows()
    {
        $rows = array();
        foreach (self::autoActions() as $a)     { $rows[] = array('action' => $a, 'tier' => self::AUTO); }
        foreach (self::managerActions() as $a)  { $rows[] = array('action' => $a, 'tier' => self::MANAGER); }
        foreach (self::chairmanActions() as $a) { $rows[] = array('action' => $a, 'tier' => self::CHAIRMAN); }
        return $rows;
    }
}
