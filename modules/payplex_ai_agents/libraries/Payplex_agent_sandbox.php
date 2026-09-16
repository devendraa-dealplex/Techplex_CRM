<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Payplex_agent_lifecycle.php';
require_once __DIR__ . '/Payplex_agent_safety.php';

/**
 * Payplex_agent_sandbox
 *
 * A safe dry-run engine. It exercises an agent's configured pipeline WITHOUT any
 * real side effect: no external AI provider call, no real customer contact, no
 * CRM mutation. Every proposed action is passed through the safety gate and
 * recorded in a transcript, and token/cost are estimated so budget logic can be
 * demonstrated. This is what powers the "run a complete sandbox test" flow.
 */
class Payplex_agent_sandbox
{
    /** Rough per-1K-token prices (USD) for cost estimation only. */
    public static function modelPrices()
    {
        return array(
            'gpt-4o-mini'   => 0.00075,
            'gpt-4o'        => 0.0075,
            'gpt-4.1'       => 0.008,
            'claude-haiku'  => 0.0015,
            'claude-sonnet' => 0.009,
            'default'       => 0.002,
        );
    }

    public static function priceFor($model)
    {
        $p = self::modelPrices();
        return isset($p[$model]) ? $p[$model] : $p['default'];
    }

    /**
     * Run a complete sandbox test.
     *
     * @param array $agent     agent config (name, ai_model, allowed_tools, triggers,
     *                         confidence_threshold, approval_required_actions, ...)
     * @param array $input     test payload (e.g. a sample lead)
     * @param array $ctx       ['global_kill'=>bool,'agent_kill'=>bool,'budget'=>[...]]
     * @return array           structured run result (always mode=sandbox)
     */
    public static function run(array $agent, array $input = array(), array $ctx = array())
    {
        $transcript = array();
        $model = isset($agent['ai_model']) ? $agent['ai_model'] : 'gpt-4o-mini';
        $threshold = isset($agent['confidence_threshold']) ? (float) $agent['confidence_threshold'] : 0.75;

        $tools = self::toolList($agent);
        if (empty($tools)) {
            $tools = array('read_input', 'draft_output');
        }

        // Deterministic pseudo-confidence from input completeness so the
        // confidence gate is actually exercised in a test.
        $confidence = self::estimateConfidence($input);

        $tokens = 120; // base prompt overhead
        $escalations = 0;
        $blocked = 0;
        $simulatedActions = 0;

        $transcript[] = self::line('trigger', 'Sandbox run started for agent "' . (isset($agent['name']) ? $agent['name'] : 'unnamed') . '"', 'ok');
        $transcript[] = self::line('input', 'Received test input with ' . count($input) . ' field(s); estimated confidence ' . number_format($confidence, 2), 'ok');

        foreach ($tools as $tool) {
            $tokens += 60 + strlen($tool) * 2; // per-step token estimate

            $verdict = Payplex_agent_safety::evaluate($tool, array(
                'mode'                 => Payplex_agent_lifecycle::MODE_SANDBOX,
                'status'               => isset($agent['status']) ? $agent['status'] : 'sandbox',
                'global_kill'          => !empty($ctx['global_kill']),
                'agent_kill'           => !empty($ctx['agent_kill']),
                'confidence'           => $confidence,
                'confidence_threshold' => $threshold,
                'budget'               => isset($ctx['budget']) ? $ctx['budget'] : array(),
            ));

            if (empty($verdict['allow'])) {
                $blocked++;
                if (!empty($verdict['escalate'])) {
                    $escalations++;
                }
                $transcript[] = self::line('tool_call', 'Action "' . $tool . '" BLOCKED (' . $verdict['reason'] . ')', 'blocked');
                // A hard stop (kill switch / budget) ends the run.
                if (in_array($verdict['reason'], array('global_kill_switch', 'agent_kill_switch',
                        'token_limit_exceeded', 'budget_exceeded', 'daily_limit_exceeded'), true)) {
                    $transcript[] = self::line('lifecycle', 'Run halted by ' . $verdict['reason'], 'halted');
                    return self::result($agent, $model, $tokens, $confidence, $threshold,
                        $transcript, $escalations, $blocked, $simulatedActions, 'halted');
                }
                continue;
            }

            // Would this action need approval / escalation in production?
            $needsApproval = Payplex_agent_safety::isApprovalRequired($tool) || Payplex_agent_safety::isNeverAutonomous($tool);
            if ($confidence < $threshold) {
                $escalations++;
                $transcript[] = self::line('tool_call', 'Action "' . $tool . '" simulated; LOW-CONFIDENCE -> would escalate to human in production', 'escalate');
            } elseif ($needsApproval) {
                $transcript[] = self::line('tool_call', 'Action "' . $tool . '" simulated; would REQUIRE approval in production', 'needs_approval');
            } else {
                $transcript[] = self::line('tool_call', 'Action "' . $tool . '" simulated successfully (dry-run)', 'ok');
            }
            $simulatedActions++;
        }

        $transcript[] = self::line('output', 'Draft output produced (not delivered — sandbox).', 'ok');

        return self::result($agent, $model, $tokens, $confidence, $threshold,
            $transcript, $escalations, $blocked, $simulatedActions, 'completed');
    }

    private static function toolList($agent)
    {
        $tools = isset($agent['allowed_tools']) ? $agent['allowed_tools'] : array();
        if (is_string($tools)) {
            $decoded = json_decode($tools, true);
            $tools = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $tools)));
        }
        return is_array($tools) ? array_values($tools) : array();
    }

    private static function estimateConfidence(array $input)
    {
        if (empty($input)) {
            return 0.5;
        }
        $filled = 0;
        foreach ($input as $v) {
            if ($v !== null && $v !== '') {
                $filled++;
            }
        }
        $ratio = $filled / max(1, count($input));
        return round(0.55 + 0.4 * $ratio, 2); // 0.55 .. 0.95
    }

    private static function result($agent, $model, $tokens, $confidence, $threshold,
                                   $transcript, $escalations, $blocked, $simulated, $status)
    {
        $cost = round(($tokens / 1000.0) * self::priceFor($model), 6);
        return array(
            'mode'              => Payplex_agent_lifecycle::MODE_SANDBOX,
            'status'            => $status,        // completed | halted
            'model'             => $model,
            'tokens'            => $tokens,
            'estimated_cost'    => $cost,
            'confidence'        => $confidence,
            'confidence_threshold' => $threshold,
            'simulated_actions' => $simulated,
            'blocked_actions'   => $blocked,
            'escalations'       => $escalations,
            'transcript'        => $transcript,
        );
    }

    private static function line($type, $message, $state)
    {
        return array('type' => $type, 'message' => $message, 'state' => $state);
    }
}
