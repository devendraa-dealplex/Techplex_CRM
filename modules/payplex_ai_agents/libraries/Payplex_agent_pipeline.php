<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_pipeline
 *
 * Pure, dependency-free lead-automation logic:
 *   capture -> validate -> dedupe -> score -> assign -> follow-up
 *
 * Every method returns RECOMMENDATIONS ONLY. Nothing here writes to the CRM.
 * The model runs this in sandbox and logs the recommendations; a real mutation
 * only ever happens through a human-approved, production agent action.
 */
class Payplex_agent_pipeline
{
    /* ---- validate ---- */
    public static function validate(array $lead)
    {
        $issues = array();
        $name  = isset($lead['name']) ? trim((string) $lead['name']) : '';
        $email = isset($lead['email']) ? trim((string) $lead['email']) : '';
        $phone = isset($lead['phone']) ? trim((string) $lead['phone']) : '';

        if ($name === '') {
            $issues[] = 'missing_name';
        }
        if ($email === '' && $phone === '') {
            $issues[] = 'no_contact_method';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $issues[] = 'invalid_email';
        }
        if ($phone !== '' && strlen(preg_replace('/[^0-9]/', '', $phone)) < 7) {
            $issues[] = 'invalid_phone';
        }
        return array('valid' => empty($issues), 'issues' => $issues);
    }

    /* ---- dedupe ---- */
    /** Return ids of existing leads matching by normalised email or phone. */
    public static function findDuplicates(array $lead, array $existing)
    {
        $email = strtolower(trim((string) (isset($lead['email']) ? $lead['email'] : '')));
        $phone = preg_replace('/[^0-9]/', '', (string) (isset($lead['phone']) ? $lead['phone'] : ''));
        $dupes = array();
        foreach ($existing as $e) {
            $eid = isset($e['id']) ? $e['id'] : null;
            if ($eid === null) {
                continue;
            }
            $eemail = strtolower(trim((string) (isset($e['email']) ? $e['email'] : '')));
            $ephone = preg_replace('/[^0-9]/', '', (string) (isset($e['phone']) ? $e['phone'] : ''));
            if (($email !== '' && $eemail === $email) || ($phone !== '' && strlen($phone) >= 7 && $ephone === $phone)) {
                $dupes[] = $eid;
            }
        }
        return $dupes;
    }

    /* ---- score ---- */
    /**
     * Score 0..100 from configurable weighted signals. Weights default to a
     * sensible set; an admin config can override.
     */
    public static function score(array $lead, array $weights = array(), array $sourceQuality = array())
    {
        $w = array_merge(array(
            'has_email' => 20, 'has_phone' => 20, 'has_company' => 15,
            'has_name'  => 10, 'source'    => 35,
        ), $weights);

        $score = 0;
        if (!empty($lead['email'])) { $score += $w['has_email']; }
        if (!empty($lead['phone'])) { $score += $w['has_phone']; }
        if (!empty($lead['company'])) { $score += $w['has_company']; }
        if (!empty($lead['name'])) { $score += $w['has_name']; }

        $src = strtolower(trim((string) (isset($lead['source']) ? $lead['source'] : '')));
        $sq = array_change_key_case($sourceQuality, CASE_LOWER);
        $srcFactor = isset($sq[$src]) ? (float) $sq[$src] : 0.5; // 0..1, default medium
        $score += (int) round($w['source'] * $srcFactor);

        return max(0, min(100, (int) $score));
    }

    /* ---- assign ---- */
    /**
     * Suggest a staff id. Territory match wins; otherwise least-loaded staff.
     * $staff: [ ['id'=>, 'load'=>int, 'territory'=>str, 'available'=>bool], ... ]
     */
    public static function suggestAssignment(array $lead, array $staff)
    {
        $leadTerr = strtolower(trim((string) (isset($lead['territory']) ? $lead['territory'] : '')));
        $candidates = array();
        foreach ($staff as $s) {
            if (isset($s['available']) && !$s['available']) {
                continue;
            }
            $candidates[] = $s;
        }
        if (empty($candidates)) {
            return array('staff_id' => null, 'reason' => 'no_available_staff');
        }
        // territory match first
        if ($leadTerr !== '') {
            $terr = array();
            foreach ($candidates as $s) {
                if (strtolower(trim((string) (isset($s['territory']) ? $s['territory'] : ''))) === $leadTerr) {
                    $terr[] = $s;
                }
            }
            if (!empty($terr)) {
                $candidates = $terr;
            }
        }
        // least-loaded
        usort($candidates, function ($a, $b) {
            return ((int) (isset($a['load']) ? $a['load'] : 0)) <=> ((int) (isset($b['load']) ? $b['load'] : 0));
        });
        $pick = $candidates[0];
        return array('staff_id' => $pick['id'], 'reason' => ($leadTerr !== '' && strtolower(trim((string) (isset($pick['territory']) ? $pick['territory'] : ''))) === $leadTerr) ? 'territory+workload' : 'least_loaded');
    }

    /* ---- follow-up ---- */
    /** A follow-up plan of steps with hour offsets. Default cadence if none given. */
    public static function followupPlan(array $lead, array $cadence = array())
    {
        if (empty($cadence)) {
            $cadence = array(
                array('offset_hours' => 1,  'channel' => 'call',  'note' => 'First contact attempt'),
                array('offset_hours' => 24, 'channel' => 'email', 'note' => 'Follow-up email if no answer'),
                array('offset_hours' => 72, 'channel' => 'call',  'note' => 'Second call attempt'),
            );
        }
        // Only include channels the lead can actually receive.
        $hasEmail = !empty($lead['email']);
        $hasPhone = !empty($lead['phone']);
        $plan = array();
        foreach ($cadence as $step) {
            $ch = isset($step['channel']) ? $step['channel'] : 'task';
            if ($ch === 'email' && !$hasEmail) { continue; }
            if (($ch === 'call' || $ch === 'sms' || $ch === 'whatsapp') && !$hasPhone) { continue; }
            $plan[] = $step;
        }
        return $plan;
    }

    /**
     * Full pipeline for one lead. Returns a recommendation bundle (no writes).
     * $config: ['existing'=>[], 'weights'=>[], 'source_quality'=>[], 'staff'=>[], 'cadence'=>[]]
     */
    public static function run(array $lead, array $config = array())
    {
        $existing = isset($config['existing']) ? $config['existing'] : array();
        $validation = self::validate($lead);
        $dupes      = self::findDuplicates($lead, $existing);
        $score      = self::score($lead, isset($config['weights']) ? $config['weights'] : array(), isset($config['source_quality']) ? $config['source_quality'] : array());
        $assign     = self::suggestAssignment($lead, isset($config['staff']) ? $config['staff'] : array());
        $followup   = self::followupPlan($lead, isset($config['cadence']) ? $config['cadence'] : array());

        $stages = array();
        $stages[] = array('stage' => 'capture',  'ok' => true, 'detail' => 'Lead received');
        $stages[] = array('stage' => 'validate', 'ok' => $validation['valid'], 'detail' => $validation['valid'] ? 'Valid' : implode(', ', $validation['issues']));
        $stages[] = array('stage' => 'dedupe',   'ok' => empty($dupes), 'detail' => empty($dupes) ? 'No duplicates' : 'Duplicate of #' . implode(', #', $dupes));
        $stages[] = array('stage' => 'score',    'ok' => true, 'detail' => 'Score ' . $score . '/100');
        $stages[] = array('stage' => 'assign',   'ok' => $assign['staff_id'] !== null, 'detail' => $assign['staff_id'] !== null ? 'Suggest staff #' . $assign['staff_id'] . ' (' . $assign['reason'] . ')' : $assign['reason']);
        $stages[] = array('stage' => 'followup', 'ok' => !empty($followup), 'detail' => count($followup) . ' step(s) planned');

        // Anything needing a human? invalid or duplicate lead escalates.
        $escalate = !$validation['valid'] || !empty($dupes);

        return array(
            'validation' => $validation,
            'duplicates' => $dupes,
            'score'      => $score,
            'assignment' => $assign,
            'followup'   => $followup,
            'stages'     => $stages,
            'escalate'   => $escalate,
            'recommendation_only' => true,
        );
    }
}
