<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_custom_template
 *
 * Pure validation + normalization for admin-created custom templates (the
 * "Create Template" form). No CI dependency so it is unit-testable.
 *
 * A custom template may define role, naming, prompt, tools, triggers, budgets
 * and approval tier — but it can NEVER loosen the hard safety envelope: the
 * prohibited actions are always re-applied, and any real/high-risk action stays
 * approval-gated. Fail-closed by construction.
 */
class Payplex_agent_custom_template
{
    /** Actions no template may ever permit (re-applied on every save). */
    public static function prohibitedFloor()
    {
        return array(
            'make_payout', 'issue_refund', 'modify_financial_record',
            'delete_crm_record', 'change_user_permission', 'deploy_code',
            'execute_sql', 'execute_server_command', 'change_payment_provider',
            'unrestricted_shell', 'unrestricted_db',
        );
    }

    /** Actions that always require human approval (re-applied on every save). */
    public static function approvalFloor()
    {
        return array(
            'make_real_call', 'send_bulk_message', 'send_live_message',
            'purchase_service', 'activate_paid_service', 'public_statement',
            'production_deployment', 'material_pricing_change', 'legal_filing',
            'regulatory_submission', 'sensitive_data_export',
        );
    }

    public static function tiers()
    {
        return array('auto', 'manager', 'chairman');
    }

    /**
     * Turn a free-text name/role into a safe slug.
     */
    public static function slugify($text, $fallback = 'custom_template')
    {
        $s = strtolower(trim((string) $text));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = trim($s, '_');
        if ($s === '') {
            $s = $fallback;
        }
        // custom templates are namespaced so they never collide with built-ins
        if (strpos($s, 'custom_') !== 0) {
            $s = 'custom_' . $s;
        }
        return substr($s, 0, 90);
    }

    /**
     * Validate raw form input. Returns array('ok'=>bool, 'errors'=>[], 'data'=>[]).
     * On ok, 'data' is normalized and safe to persist.
     */
    public static function validate(array $in)
    {
        $errors = array();

        $name = isset($in['name']) ? trim((string) $in['name']) : '';
        $role = isset($in['system_role']) ? trim((string) $in['system_role']) : '';
        $prompt = isset($in['system_prompt']) ? trim((string) $in['system_prompt']) : '';

        if ($name === '' || mb_strlen($name) < 2) {
            $errors[] = 'Template name is required (min 2 characters).';
        }
        if ($role === '') {
            $errors[] = 'System role is required.';
        }
        if ($prompt === '' || mb_strlen($prompt) < 20) {
            $errors[] = 'System prompt is required (min 20 characters).';
        }

        // Guard against impersonation: block obvious "impersonate real person" cues.
        $lc = strtolower($name . ' ' . $role . ' ' . (isset($in['persona']) ? $in['persona'] : ''));
        foreach (array('impersonate', 'pretend to be', 'act as the real', 'clone of') as $bad) {
            if (strpos($lc, $bad) !== false) {
                $errors[] = 'Template must not impersonate a real individual. Use a generic role/persona.';
                break;
            }
        }

        $tier = isset($in['approval_tier']) ? strtolower(trim((string) $in['approval_tier'])) : 'chairman';
        if (!in_array($tier, self::tiers(), true)) {
            $tier = 'chairman';
        }

        $conf = isset($in['confidence_threshold']) ? (float) $in['confidence_threshold'] : 0.80;
        if ($conf < 0.5) { $conf = 0.5; }
        if ($conf > 0.99) { $conf = 0.99; }

        $tokenLimit  = isset($in['token_limit']) ? max(0, (int) $in['token_limit']) : 100000;
        $dailyBudget = isset($in['daily_budget']) ? max(0, (float) $in['daily_budget']) : 5.0;
        $monthBudget = isset($in['monthly_budget']) ? max(0, (float) $in['monthly_budget']) : 100.0;

        $tools    = self::toList(isset($in['allowed_tools']) ? $in['allowed_tools'] : '');
        $triggers = self::toList(isset($in['triggers']) ? $in['triggers'] : '');
        $tags     = self::toList(isset($in['expertise_tags']) ? $in['expertise_tags'] : '');

        // Safety envelope is ALWAYS enforced regardless of input.
        $prohibited = self::prohibitedFloor();
        $approval   = self::approvalFloor();

        if (!empty($errors)) {
            return array('ok' => false, 'errors' => $errors, 'data' => array());
        }

        $data = array(
            'template_slug'         => self::slugify(isset($in['template_slug']) && $in['template_slug'] !== '' ? $in['template_slug'] : $name),
            'system_role'           => mb_substr($role, 0, 150),
            'name'                  => mb_substr($name, 0, 150),
            'short_name'            => mb_substr(isset($in['short_name']) ? trim((string) $in['short_name']) : '', 0, 100),
            'agent_ref_prefix'      => mb_substr(isset($in['agent_ref_prefix']) ? trim((string) $in['agent_ref_prefix']) : 'AI-AGT', 0, 50),
            'department'            => mb_substr(isset($in['department']) ? trim((string) $in['department']) : '', 0, 100),
            'company'               => mb_substr(isset($in['company']) ? trim((string) $in['company']) : '', 0, 100),
            'persona'               => mb_substr(isset($in['persona']) ? trim((string) $in['persona']) : '', 0, 150),
            'communication_style'   => mb_substr(isset($in['communication_style']) ? trim((string) $in['communication_style']) : 'executive-brief', 0, 100),
            'primary_language'      => mb_substr(isset($in['primary_language']) ? trim((string) $in['primary_language']) : 'en', 0, 50),
            'extra_languages'       => mb_substr(isset($in['extra_languages']) ? trim((string) $in['extra_languages']) : '', 0, 191),
            'tone'                  => mb_substr(isset($in['tone']) ? trim((string) $in['tone']) : 'professional', 0, 100),
            'expertise_tags'        => implode(',', $tags),
            'purpose'               => mb_substr(isset($in['purpose']) ? trim((string) $in['purpose']) : '', 0, 1000),
            'description'           => mb_substr(isset($in['purpose']) ? trim((string) $in['purpose']) : '', 0, 1000),
            'system_prompt'         => $prompt,
            'ai_provider'           => mb_substr(isset($in['ai_provider']) ? trim((string) $in['ai_provider']) : 'openai', 0, 100),
            'ai_model'              => mb_substr(isset($in['ai_model']) ? trim((string) $in['ai_model']) : 'gpt-4o-mini', 0, 100),
            'fallback_model'        => mb_substr(isset($in['fallback_model']) ? trim((string) $in['fallback_model']) : 'gpt-4o-mini', 0, 100),
            'allowed_tools'         => json_encode(array_values($tools)),
            'triggers'              => json_encode(array_values($triggers)),
            'prohibited_actions'    => json_encode(array_values($prohibited)),
            'approval_required_actions' => json_encode(array_values($approval)),
            'approval_tier'         => $tier,
            'confidence_threshold'  => $conf,
            'human_escalation'      => 1,
            'token_limit'           => $tokenLimit,
            'daily_budget'          => $dailyBudget,
            'monthly_budget'        => $monthBudget,
            'is_executive'          => isset($in['is_executive']) && (int) $in['is_executive'] === 1 ? 1 : 0,
        );

        return array('ok' => true, 'errors' => array(), 'data' => $data);
    }

    /** Split a comma/newline separated string (or array) into a clean list. */
    public static function toList($val)
    {
        if (is_array($val)) {
            $parts = $val;
        } else {
            $parts = preg_split('/[\n,]+/', (string) $val);
        }
        $out = array();
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }
}
