<?php

defined('BASEPATH') or defined('SALES_TARGETS_TEST') or exit('No direct script access allowed');

/**
 * Kpi_weighting — multi-KPI achievement (spec §3.2).
 *
 * Pure and framework-independent.
 *
 * The formula the spec fixes:
 *     weighted achievement = (capped or configured KPI achievement) × KPI weight
 * and weights across a target must total 100%, blocking approval otherwise.
 *
 * Two decisions this class makes deliberately, because the alternative quietly
 * misleads someone:
 *
 *  1. AN UNMEASURABLE KPI IS NOT A ZERO. If a target carries 40% of its weight
 *     in KPIs that nothing in this system records, the honest answer is not
 *     "you achieved 60%" — it is "60% is all that can be measured, and 40% is
 *     unmeasured". Overall achievement is therefore reported alongside the
 *     measurable weight, and flagged as incomplete. Scaling the measured part up
 *     to look like 100% would be worse still: it invents performance.
 *
 *  2. OVER-ACHIEVEMENT IS CAPPED BY CONFIGURATION, NOT BY ASSUMPTION. Whether
 *     150% on one KPI can carry a whole target is a compensation decision, so it
 *     is a setting. The default is to cap, because uncapped weighting lets one
 *     runaway metric mask total failure everywhere else.
 */
class Kpi_weighting
{
    /** Weights must sum to this, within tolerance. */
    const REQUIRED_TOTAL = 100.0;

    /** Rounding slack, so 33.33 + 33.33 + 33.34 is accepted. */
    const TOLERANCE = 0.01;

    /* ---------------- weights ---------------- */

    /**
     * Validate the weight set for a target.
     *
     * @param array $metrics rows with kpi_key and weight
     * @return array ok, total, errors
     */
    public static function validateWeights($metrics)
    {
        $metrics = array_values((array) $metrics);
        $errors = array();
        $total = 0.0;
        $seen = array();

        if (!$metrics) {
            return array('ok' => false, 'total' => 0.0,
                'errors' => array('A target must have at least one KPI.'));
        }

        foreach ($metrics as $i => $m) {
            $m = (array) $m;
            $n = $i + 1;
            $key = (string) (isset($m['kpi_key']) ? $m['kpi_key'] : '');

            if ($key === '') {
                $errors[] = 'KPI ' . $n . ' has no key.';
            } elseif (in_array($key, $seen, true)) {
                $errors[] = 'KPI "' . $key . '" appears more than once; each KPI may only be weighted once.';
            } else {
                $seen[] = $key;
            }

            $w = isset($m['weight']) ? $m['weight'] : null;
            if ($w === null || $w === '' || !is_numeric($w)) {
                $errors[] = 'KPI ' . $n . ' has no weight.';
                continue;
            }
            if ((float) $w <= 0) {
                $errors[] = 'KPI ' . $n . ' has a weight of zero or less; remove it instead.';
                continue;
            }
            if ((float) $w > self::REQUIRED_TOTAL) {
                $errors[] = 'KPI ' . $n . ' has a weight above 100%.';
            }
            $total += (float) $w;
        }

        $total = round($total, 2);
        if (abs($total - self::REQUIRED_TOTAL) > self::TOLERANCE) {
            $errors[] = 'Weights total ' . $total . '% but must total 100%. '
                      . ($total < self::REQUIRED_TOTAL
                          ? 'Add ' . round(self::REQUIRED_TOTAL - $total, 2) . '%.'
                          : 'Remove ' . round($total - self::REQUIRED_TOTAL, 2) . '%.');
        }

        return array('ok' => empty($errors), 'total' => $total, 'errors' => $errors);
    }

    /* ---------------- per-KPI achievement ---------------- */

    /**
     * Achievement for one KPI.
     *
     * @param array $metric   kpi_key, target_value, weight, min_threshold,
     *                        stretch_threshold
     * @param array $actual   achieved, measurable, reason, record_count,
     *                        last_calculated
     * @param bool  $capOver  cap achievement at 100%
     *
     * @return array with achievement_pct, capped_pct, weighted, measurable,
     *               qualified, remaining, stretch_met
     */
    public static function metricAchievement($metric, $actual, $capOver = true)
    {
        $m = (array) $metric;
        $a = (array) $actual;

        $key    = (string) (isset($m['kpi_key']) ? $m['kpi_key'] : '');
        $target = isset($m['target_value']) && is_numeric($m['target_value']) ? (float) $m['target_value'] : null;
        $weight = isset($m['weight']) && is_numeric($m['weight']) ? (float) $m['weight'] : 0.0;

        $base = array(
            'kpi_key'         => $key,
            'target_value'    => $target,
            'weight'          => $weight,
            'achieved'        => null,
            'achievement_pct' => null,
            'capped_pct'      => null,
            'weighted'        => null,
            'measurable'      => false,
            'qualified'       => false,
            'stretch_met'     => false,
            'remaining'       => null,
            'reason'          => '',
            'record_count'    => isset($a['record_count']) ? (int) $a['record_count'] : 0,
            'last_calculated' => isset($a['last_calculated']) ? $a['last_calculated'] : null,
        );

        // unmeasurable: report why, contribute nothing, and do NOT pretend it is 0%
        if (empty($a['measurable'])) {
            $base['reason'] = isset($a['reason']) && $a['reason'] !== ''
                ? (string) $a['reason']
                : 'This KPI cannot be measured on this install.';
            return $base;
        }

        if ($target === null || $target <= 0) {
            $base['reason'] = 'No target value set for this KPI, so achievement cannot be expressed as a percentage.';
            return $base;
        }

        $achieved = isset($a['achieved']) && is_numeric($a['achieved']) ? (float) $a['achieved'] : 0.0;
        $base['achieved']   = round($achieved, 2);
        $base['measurable'] = true;
        $base['remaining']  = round(max(0.0, $target - $achieved), 2);

        // minimum qualifying threshold: below it the KPI scores nothing at all
        $min = isset($m['min_threshold']) && $m['min_threshold'] !== '' && is_numeric($m['min_threshold'])
            ? (float) $m['min_threshold'] : null;
        if ($min !== null && $achieved < $min) {
            $base['achievement_pct'] = round(($achieved / $target) * 100, 2);
            $base['capped_pct']      = 0.0;
            $base['weighted']        = 0.0;
            $base['qualified']       = false;
            $base['reason']          = 'Below the minimum qualifying threshold of ' . $min
                                     . ', so this KPI scores zero.';
            return $base;
        }

        $base['qualified'] = true;

        $pct = ($achieved / $target) * 100.0;
        $base['achievement_pct'] = round($pct, 2);

        $capped = $capOver ? min(100.0, $pct) : $pct;
        $base['capped_pct'] = round($capped, 2);

        // the spec's formula
        $base['weighted'] = round(($capped / 100.0) * $weight, 4);

        $stretch = isset($m['stretch_threshold']) && $m['stretch_threshold'] !== '' && is_numeric($m['stretch_threshold'])
            ? (float) $m['stretch_threshold'] : null;
        if ($stretch !== null) { $base['stretch_met'] = $achieved >= $stretch; }

        return $base;
    }

    /* ---------------- overall ---------------- */

    /**
     * Roll per-KPI results into an overall figure.
     *
     * Reports measured and unmeasured weight separately. The overall percentage
     * is the sum of weighted contributions out of the FULL 100, not out of the
     * measurable portion — inflating the measured part to fill the gap would
     * invent performance that was never demonstrated.
     */
    public static function overall($metricResults)
    {
        $results = array_values((array) $metricResults);

        $weighted = 0.0;
        $measuredWeight = 0.0;
        $unmeasuredWeight = 0.0;
        $unmeasured = array();

        foreach ($results as $r) {
            $r = (array) $r;
            $w = isset($r['weight']) ? (float) $r['weight'] : 0.0;

            if (empty($r['measurable'])) {
                $unmeasuredWeight += $w;
                $unmeasured[] = array(
                    'kpi_key' => isset($r['kpi_key']) ? $r['kpi_key'] : '',
                    'weight'  => $w,
                    'reason'  => isset($r['reason']) ? $r['reason'] : '',
                );
                continue;
            }
            $measuredWeight += $w;
            $weighted += isset($r['weighted']) ? (float) $r['weighted'] : 0.0;
        }

        $measuredWeight   = round($measuredWeight, 2);
        $unmeasuredWeight = round($unmeasuredWeight, 2);

        // what the measured part would score on its own, for context only
        $ofMeasured = $measuredWeight > 0 ? round(($weighted / $measuredWeight) * 100, 2) : null;

        return array(
            'overall_pct'        => round($weighted, 2),
            'measured_weight'    => $measuredWeight,
            'unmeasured_weight'  => $unmeasuredWeight,
            'complete'           => $unmeasuredWeight <= self::TOLERANCE,
            'pct_of_measured'    => $ofMeasured,
            'unmeasured'         => $unmeasured,
            'metric_count'       => count($results),
        );
    }

    /**
     * A one-line honest summary. Used wherever a single number would otherwise
     * be shown without its caveat.
     */
    public static function summarise($overall)
    {
        $o = (array) $overall;
        $pct = isset($o['overall_pct']) ? $o['overall_pct'] : 0;

        if (!empty($o['complete'])) {
            return $pct . '% overall achievement.';
        }
        return $pct . '% of the full target achieved, but only '
             . (isset($o['measured_weight']) ? $o['measured_weight'] : 0) . '% of the weighting can be '
             . 'measured — ' . (isset($o['unmeasured_weight']) ? $o['unmeasured_weight'] : 0)
             . '% is unmeasured, so this figure is incomplete rather than low.';
    }

    /* ---------------- progress and forecast ---------------- */

    /** How far through the period are we, 0..1. */
    public static function periodProgress($periodStart, $periodEnd, $asAt = null)
    {
        $s = strtotime((string) $periodStart);
        $e = strtotime((string) $periodEnd);
        if ($s === false || $e === false || $e < $s) { return null; }

        $t = $asAt === null ? time() : (is_numeric($asAt) ? (int) $asAt : strtotime((string) $asAt));
        if ($t === false) { return null; }

        // whole-day inclusive, matching how the KPI queries bound their periods
        $e += 86399;
        if ($t <= $s) { return 0.0; }
        if ($t >= $e) { return 1.0; }
        return round(($t - $s) / ($e - $s), 4);
    }

    /**
     * Straight-line forecast: at the current run rate, where does this KPI land?
     *
     * Returns null rather than a number when the period has barely begun — a
     * forecast from two days of a quarter is noise presented as insight.
     */
    public static function forecast($achieved, $progress, $minProgress = 0.1)
    {
        if ($progress === null || $progress <= 0) { return null; }
        if ($progress < $minProgress) { return null; }
        if (!is_numeric($achieved)) { return null; }
        return round(((float) $achieved) / $progress, 2);
    }

    /** Is the KPI ahead of, on, or behind a straight-line pace? */
    public static function pace($achievementPct, $progress)
    {
        if ($progress === null || $achievementPct === null) { return 'unknown'; }
        $expected = $progress * 100.0;
        $diff = (float) $achievementPct - $expected;
        if ($diff > 5)  { return 'ahead'; }
        if ($diff < -5) { return 'behind'; }
        return 'on_track';
    }

    public static function paceClass($pace)
    {
        switch ((string) $pace) {
            case 'ahead':    return 'success';
            case 'on_track': return 'info';
            case 'behind':   return 'danger';
            default:         return 'default';
        }
    }
}
