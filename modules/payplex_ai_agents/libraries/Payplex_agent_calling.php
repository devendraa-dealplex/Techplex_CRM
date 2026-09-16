<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_calling
 *
 * Pure, dependency-free decision + simulation layer for the AI Calling bridge to
 * the separately-owned "Payplex AI Calling" (Sonivo) backend.
 *
 * SAFETY MODEL (fail closed):
 *   A real outbound call is placed ONLY when ALL of these hold:
 *     - agent mode == production
 *     - agent is approved (approved_by > 0)
 *     - THIS run's call action was explicitly approved (action_approved)
 *     - the global "allow real calls" master switch is ON (default OFF)
 *   In every other case the call is SIMULATED (sandbox dry-run) or BLOCKED and
 *   routed to human review. Nothing here ever dials by itself.
 */
class Payplex_agent_calling
{
    /**
     * Decide what to do with a proposed call.
     * Returns ['action'=>'simulate'|'place_real'|'blocked', 'reason'=>string].
     *
     * $ctx: mode, approved_by, action_approved(bool), allow_real_global(bool),
     *       global_kill(bool), agent_kill(bool)
     */
    public static function decide(array $ctx)
    {
        if (!empty($ctx['global_kill'])) {
            return array('action' => 'blocked', 'reason' => 'global_kill_switch');
        }
        if (!empty($ctx['agent_kill'])) {
            return array('action' => 'blocked', 'reason' => 'agent_kill_switch');
        }

        $mode = isset($ctx['mode']) ? $ctx['mode'] : 'sandbox';
        if ($mode !== 'production') {
            return array('action' => 'simulate', 'reason' => 'sandbox_mode');
        }

        // Production path — every gate must be satisfied for a real call.
        if ((int) (isset($ctx['approved_by']) ? $ctx['approved_by'] : 0) <= 0) {
            return array('action' => 'blocked', 'reason' => 'agent_not_approved');
        }
        if (empty($ctx['action_approved'])) {
            return array('action' => 'blocked', 'reason' => 'call_approval_required');
        }
        if (empty($ctx['allow_real_global'])) {
            return array('action' => 'blocked', 'reason' => 'real_calls_disabled_globally');
        }
        return array('action' => 'place_real', 'reason' => 'all_gates_passed');
    }

    /**
     * Produce a safe simulated call result (no network, no dialing).
     * Deterministic-ish outcome from the lead completeness so tests are stable.
     */
    public static function simulate(array $lead, $script = '')
    {
        $phone = isset($lead['phone']) ? trim((string) $lead['phone']) : '';
        $hasPhone = $phone !== '';
        $name = isset($lead['name']) ? trim((string) $lead['name']) : '';

        $transcript = array();
        $transcript[] = self::line('dial', $hasPhone ? 'Would dial ' . self::mask($phone) . ' (SIMULATED, not dialed)' : 'No phone number - cannot call', $hasPhone ? 'ok' : 'blocked');
        if (!$hasPhone) {
            return array(
                'outcome'    => 'no_phone',
                'duration'   => 0,
                'cost'       => 0.0,
                'simulated'  => true,
                'transcript' => $transcript,
            );
        }
        $transcript[] = self::line('greeting', 'Agent: "Hello' . ($name !== '' ? ' ' . $name : '') . ', this is a courtesy call from our team."', 'ok');
        if ($script !== '') {
            $transcript[] = self::line('script', 'Agent (script): "' . substr((string) $script, 0, 160) . '"', 'ok');
        }
        $transcript[] = self::line('outcome', 'Call would proceed; outcome recorded as SIMULATED (no real connection).', 'ok');

        // Estimated minute-cost for the dashboard (dry-run).
        $durationSec = 90;
        $cost = round(($durationSec / 60.0) * 0.02, 4); // $0.02/min placeholder
        return array(
            'outcome'    => 'simulated',
            'duration'   => $durationSec,
            'cost'       => $cost,
            'simulated'  => true,
            'transcript' => $transcript,
        );
    }

    public static function mask($phone)
    {
        $p = preg_replace('/[^0-9+]/', '', (string) $phone);
        $len = strlen($p);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return substr($p, 0, 2) . str_repeat('*', $len - 4) . substr($p, -2);
    }

    private static function line($type, $message, $state)
    {
        return array('type' => $type, 'message' => $message, 'state' => $state);
    }
}
