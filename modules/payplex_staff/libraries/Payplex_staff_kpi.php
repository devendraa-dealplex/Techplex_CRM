<?php

defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Payplex_staff_kpi
 *
 * The configurable performance-scoring engine. Pure + dependency-free.
 *
 * Rules (spec section 7):
 *  - Admin configures KPI weights, caps, penalties, effective dates and
 *    role-specific scorecards — nothing is hard-coded here beyond a starting
 *    metric catalog and rating bands.
 *  - ONLY verified records earn points. Cancelled, duplicate, test, fraudulent
 *    or self-approved records are excluded (excluded=1) and never counted.
 *  - No verified activity => a score of 0 with rating "no_data", never a guess.
 */
class Payplex_staff_kpi
{
    /** Starting catalog of measurable metrics (admin may add defs for any key). */
    public static function metricCatalog()
    {
        return array(
            'attendance_compliance' => 'Attendance compliance',
            'tasks_completed'       => 'Tasks completed (verified)',
            'on_time_completion'    => 'On-time completion',
            'quality_rating'        => 'Quality rating',
            'leads_qualified'       => 'Leads qualified',
            'followup_sla'          => 'Follow-up SLA',
            'meetings'              => 'Meetings / demos',
            'proposals'             => 'Proposals',
            'conversions'           => 'Conversions (verified)',
            'gross_billing'         => 'Gross billing',
            'amount_collected'      => 'Amount collected (verified)',
            'customer_retention'    => 'Customer retention',
            'verified_visits'       => 'Verified field visits',
            'expense_compliance'    => 'Expense compliance',
            'sla_compliance'        => 'SLA compliance',
            'manager_feedback'      => 'Manager feedback',
        );
    }

    public static function rating($score)
    {
        $s = (float) $score;
        if ($s >= 85) { return 'excellent'; }
        if ($s >= 70) { return 'good'; }
        if ($s >= 50) { return 'fair'; }
        if ($s >= 30) { return 'needs_improvement'; }
        return 'poor';
    }

    public static function ratingClass($rating)
    {
        switch ($rating) {
            case 'excellent': return 'success';
            case 'good':      return 'success';
            case 'fair':      return 'info';
            case 'needs_improvement': return 'warning';
            case 'no_data':   return 'default';
            default:          return 'danger';
        }
    }

    /** Validate/normalise a KPI definition. */
    public static function validateDef($data)
    {
        $errors = array();
        $metric = isset($data['metric']) ? trim((string) $data['metric']) : '';
        if ($metric === '') { $errors[] = 'metric_required'; }

        $weight = isset($data['weight']) && is_numeric($data['weight']) ? (float) $data['weight'] : 1.0;
        if ($weight < 0) { $weight = 0.0; }
        if ($weight > 1) { $weight = 1.0; }

        $from = isset($data['effective_from']) ? trim((string) $data['effective_from']) : '';
        $to   = isset($data['effective_to']) ? trim((string) $data['effective_to']) : '';
        if ($from !== '' && $to !== '' && strtotime($from) !== false && strtotime($to) !== false && strtotime($to) < strtotime($from)) {
            $errors[] = 'effective_window_inverted';
        }

        $entry = array(
            'role'           => isset($data['role']) ? substr(trim((string) $data['role']), 0, 40) : '',
            'metric'         => substr($metric, 0, 60),
            'weight'         => $weight,
            'cap'            => isset($data['cap']) && is_numeric($data['cap']) ? (float) $data['cap'] : null,
            'target'         => isset($data['target']) && is_numeric($data['target']) ? (float) $data['target'] : null,
            'penalty'        => isset($data['penalty']) && is_numeric($data['penalty']) ? (float) $data['penalty'] : 0.0,
            'effective_from' => $from !== '' ? date('Y-m-d', strtotime($from)) : null,
            'effective_to'   => $to !== '' ? date('Y-m-d', strtotime($to)) : null,
            'active'         => isset($data['active']) ? (int) $data['active'] : 1,
        );
        return array('ok' => empty($errors), 'errors' => $errors, 'entry' => $entry);
    }

    /** Is a definition active as-of a date? */
    public static function defActive($def, $asOf = null)
    {
        if (isset($def['active']) && (int) $def['active'] !== 1) { return false; }
        $t = $asOf ? strtotime((string) $asOf) : time();
        if (!empty($def['effective_from']) && strtotime($def['effective_from']) !== false && $t < strtotime($def['effective_from'])) { return false; }
        if (!empty($def['effective_to']) && strtotime($def['effective_to']) !== false && $t > strtotime($def['effective_to'])) { return false; }
        return true;
    }

    /**
     * The verified value of a metric across records. A record counts ONLY when
     * verified=1 and excluded!=1 (excludes cancelled/duplicate/test/self-approved).
     */
    /**
     * How many qualifying records exist for a metric, regardless of their value.
     *
     * metricValue() sums, and a sum of 0.0 is returned both when a metric was
     * measured at zero and when no record exists at all. Those are not the same
     * thing, and telling them apart is the difference between "performed badly"
     * and "we have no evidence" — see score().
     */
    public static function metricEvidence($records, $metric)
    {
        $n = 0;
        foreach ($records as $r) {
            if ((string) (isset($r['metric']) ? $r['metric'] : '') !== (string) $metric) { continue; }
            if ((int) (isset($r['verified']) ? $r['verified'] : 0) !== 1) { continue; }
            if ((int) (isset($r['excluded']) ? $r['excluded'] : 0) === 1) { continue; }
            $n++;
        }
        return $n;
    }

    public static function metricValue($records, $metric)
    {
        $sum = 0.0;
        foreach ($records as $r) {
            if ((string) (isset($r['metric']) ? $r['metric'] : '') !== (string) $metric) { continue; }
            if ((int) (isset($r['verified']) ? $r['verified'] : 0) !== 1) { continue; }
            if ((int) (isset($r['excluded']) ? $r['excluded'] : 0) === 1) { continue; }
            $sum += isset($r['value']) ? (float) $r['value'] : 1.0;
        }
        return $sum;
    }

    /**
     * Weighted attainment score in [0,100] from active definitions applied to
     * already-computed (verified) metric values.
     * attainment = min(1, min(value,cap)/target); no target => value>0 ? 1 : 0.
     */
    /**
     * @param array      $defs     KPI definitions
     * @param array      $values   metric => summed value from verified records
     * @param string|null $asOf    effective-date cutoff
     * @param array|null $evidence metric => count of qualifying records. Pass it.
     *                             Without it the all-zero fallback below applies.
     */
    public static function score($defs, $values, $asOf = null, $evidence = null)
    {
        $totalW = 0.0; $acc = 0.0; $components = array();
        $anyEvidence = false;
        foreach ($defs as $d) {
            if (!self::defActive($d, $asOf)) { continue; }
            $w = isset($d['weight']) ? (float) $d['weight'] : 0.0;
            if ($w <= 0) { continue; }
            $metric = isset($d['metric']) ? $d['metric'] : '';
            $val = isset($values[$metric]) ? (float) $values[$metric] : 0.0;
            if (isset($d['cap']) && $d['cap'] !== null && $d['cap'] !== '') { $val = min($val, (float) $d['cap']); }
            $target = isset($d['target']) && $d['target'] !== null && $d['target'] !== '' ? (float) $d['target'] : null;
            if ($target !== null && $target > 0) {
                $attain = min(1.0, $val / $target);
            } else {
                $attain = $val > 0 ? 1.0 : 0.0;
            }
            $totalW += $w;
            $acc += $w * $attain;

            $recs = is_array($evidence) && isset($evidence[$metric]) ? (int) $evidence[$metric] : null;
            if ($recs === null ? ($val > 0) : ($recs > 0)) { $anyEvidence = true; }

            $components[$metric] = array(
                'value'      => $val,
                'attainment' => round($attain, 4),
                'weight'     => $w,
                'records'    => $recs,
            );
        }
        if ($totalW <= 0) {
            return array('score' => 0.0, 'rating' => 'no_data', 'components' => $components);
        }

        /*
         * No evidence is not evidence of poor performance.
         *
         * This returned 'no_data' only when no KPI was CONFIGURED — $totalW <= 0 —
         * and never when there was simply nothing to measure. So on an install
         * with five weighted KPIs and not one verified outcome, the engine scored
         * 0.00 and rated the person 'poor'. That is exactly what the contract at
         * the top of this class forbids: "No verified activity => a score of 0
         * with rating no_data, never a guess."
         *
         * The live row proves it happened: staff #1, period 2026-09, score 0.00,
         * rating 'poor', every component at attainment 0 — computed from two
         * activity records, one of which is a login and the other unverified.
         *
         * A performance rating can feed a review, a bonus or an exit. Rating
         * somebody 'poor' has to require evidence that they performed poorly,
         * and a sum of zero does not distinguish "did nothing" from "nothing was
         * ever recorded". Where record counts are supplied they decide it; where
         * they are not, an all-zero result is treated as absence rather than
         * failure, because that is the direction whose error is recoverable.
         */
        if (!$anyEvidence) {
            return array('score' => 0.0, 'rating' => 'no_data', 'components' => $components);
        }

        $score = round(100.0 * ($acc / $totalW), 2);
        return array('score' => $score, 'rating' => self::rating($score), 'components' => $components);
    }

    /** Subtract configured penalties (per-unit) for negative counts; never below 0. */
    public static function applyPenalty($score, $counts, $penaltyPerUnit)
    {
        $s = (float) $score;
        foreach ((array) $counts as $k => $n) {
            $per = isset($penaltyPerUnit[$k]) ? (float) $penaltyPerUnit[$k] : 0.0;
            $s -= ((float) $n) * $per;
        }
        return $s < 0 ? 0.0 : round($s, 2);
    }
}
