<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_goals
 *
 * Pure, dependency-free OKR / KPI mathematics for the executive goals system.
 *
 * An objective has one or more measurable key results. Each key result moves a
 * metric from a baseline toward a target, in a direction (increase or decrease).
 * Progress is computed deterministically so a dashboard, a report and an alert
 * all agree on the same number.
 *
 * Health compares progress against how much of the period has elapsed: being
 * 40% of the way to target is "on track" one month in and "off track" with a
 * week to go. Nothing here writes or guesses — it only measures.
 */
class Payplex_agent_goals
{
    const DRAFT     = 'draft';
    const ACTIVE    = 'active';
    const ACHIEVED  = 'achieved';
    const MISSED    = 'missed';
    const CANCELLED = 'cancelled';

    /** Validate + normalize an objective for saving. */
    public static function validateObjective(array $in)
    {
        $errors = array();
        $title  = trim((string) (isset($in['title']) ? $in['title'] : ''));
        if ($title === '')           { $errors[] = 'title_required'; }
        if (mb_strlen($title) > 200) { $errors[] = 'title_too_long'; }
        $period = self::normalizePeriod(isset($in['period']) ? $in['period'] : '');
        if ($period === '')          { $errors[] = 'period_invalid'; }

        $entry = array(
            'title'       => $title,
            'description' => trim((string) (isset($in['description']) ? $in['description'] : '')),
            'company'     => strtolower(trim((string) (isset($in['company']) ? $in['company'] : ''))),
            'period'      => $period,
        );
        return array('ok' => empty($errors), 'errors' => $errors, 'entry' => $entry);
    }

    /** Validate + normalize a key result for saving. */
    public static function validateKeyResult(array $in)
    {
        $errors = array();
        $title  = trim((string) (isset($in['title']) ? $in['title'] : ''));
        if ($title === '') { $errors[] = 'title_required'; }

        $direction = strtolower(trim((string) (isset($in['direction']) ? $in['direction'] : 'increase')));
        if (!in_array($direction, array('increase', 'decrease'), true)) { $direction = 'increase'; }

        $baseline = self::num(isset($in['baseline']) ? $in['baseline'] : 0);
        $target   = self::num(isset($in['target'])   ? $in['target']   : 0);
        $current  = isset($in['current']) && $in['current'] !== '' ? self::num($in['current']) : $baseline;
        if ($baseline === $target) { $errors[] = 'baseline_equals_target'; }

        $entry = array(
            'title'     => $title,
            'metric'    => trim((string) (isset($in['metric']) ? $in['metric'] : '')),
            'unit'      => trim((string) (isset($in['unit']) ? $in['unit'] : '')),
            'direction' => $direction,
            'baseline'  => $baseline,
            'target'    => $target,
            'current'   => $current,
        );
        return array('ok' => empty($errors), 'errors' => $errors, 'entry' => $entry);
    }

    private static function num($v) { return is_numeric($v) ? (float) $v : 0.0; }

    /**
     * Progress of one key result, clamped to [0,1]. 1.0 == target met or beaten.
     * increase: (current-baseline)/(target-baseline)
     * decrease: (baseline-current)/(baseline-target)
     */
    public static function keyResultProgress($kr)
    {
        $k        = (array) $kr;
        $baseline = self::num(isset($k['baseline']) ? $k['baseline'] : 0);
        $target   = self::num(isset($k['target'])   ? $k['target']   : 0);
        $current  = self::num(isset($k['current'])  ? $k['current']  : $baseline);
        $dir      = isset($k['direction']) ? $k['direction'] : 'increase';

        $range = ($dir === 'decrease') ? ($baseline - $target) : ($target - $baseline);
        if ($range == 0.0) {
            // no measurable range: met iff current is at/past target in the intended direction
            if ($dir === 'decrease') { return $current <= $target ? 1.0 : 0.0; }
            return $current >= $target ? 1.0 : 0.0;
        }
        $done = ($dir === 'decrease') ? ($baseline - $current) : ($current - $baseline);
        $p = $done / $range;
        if ($p < 0) { $p = 0.0; }
        if ($p > 1) { $p = 1.0; }
        return round($p, 4);
    }

    public static function keyResultAchieved($kr)
    {
        return self::keyResultProgress($kr) >= 1.0;
    }

    /** Objective progress = mean of its key results' progress (0 if none). */
    public static function objectiveProgress($keyResults)
    {
        $krs = array_values((array) $keyResults);
        if (empty($krs)) { return 0.0; }
        $sum = 0.0;
        foreach ($krs as $kr) { $sum += self::keyResultProgress($kr); }
        return round($sum / count($krs), 4);
    }

    /* ---------------- Periods ---------------- */

    /** Accept "2026-Q3", "2026-q3", or a bare year "2026" (annual). Returns "" if invalid. */
    public static function normalizePeriod($p)
    {
        $p = strtoupper(trim((string) $p));
        if (preg_match('/^(\d{4})-Q([1-4])$/', $p, $m)) { return $m[1] . '-Q' . $m[2]; }
        if (preg_match('/^(\d{4})$/', $p, $m))          { return $m[1]; }
        return '';
    }

    /** [startDate, endDate] (Y-m-d) for a normalized period. */
    public static function periodBounds($period)
    {
        $period = self::normalizePeriod($period);
        if ($period === '') { return array('', ''); }
        if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $m)) {
            $y = (int) $m[1]; $q = (int) $m[2];
            $startMonth = ($q - 1) * 3 + 1;
            $start = sprintf('%04d-%02d-01', $y, $startMonth);
            $endMonth = $startMonth + 2;
            $end = date('Y-m-t', mktime(0, 0, 0, $endMonth, 1, $y));
            return array($start, $end);
        }
        $y = (int) $period;
        return array(sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y));
    }

    /** Fraction of the period elapsed as of $asOf, clamped [0,1]. */
    public static function elapsedFraction($period, $asOf = null)
    {
        list($start, $end) = self::periodBounds($period);
        if ($start === '') { return 0.0; }
        $t0 = strtotime($start . ' 00:00:00');
        $t1 = strtotime($end . ' 23:59:59');
        $now = $asOf ? strtotime((string) $asOf) : time();
        if ($now <= $t0) { return 0.0; }
        if ($now >= $t1) { return 1.0; }
        return round(($now - $t0) / ($t1 - $t0), 4);
    }

    /**
     * Health of an objective given its progress and how much of the period is gone.
     * achieved | not_started | on_track | at_risk | off_track
     */
    public static function health($progress, $elapsed)
    {
        $progress = (float) $progress; $elapsed = (float) $elapsed;
        if ($progress >= 1.0) { return 'achieved'; }
        if ($elapsed <= 0.0 && $progress <= 0.0) { return 'not_started'; }
        $gap = $progress - $elapsed;           // ahead (>0) or behind (<0) schedule
        if ($gap >= -0.10) { return 'on_track'; }
        if ($gap >= -0.25) { return 'at_risk'; }
        return 'off_track';
    }

    /**
     * What the dashboard should show. An objective with no key results is unmeasured,
     * not failing, and a draft or cancelled one isn't being graded at all - so neither
     * gets an on-track/off-track verdict.
     */
    public static function displayHealth($status, $keyResultCount, $progress, $elapsed)
    {
        if ((int) $keyResultCount === 0) { return 'no_key_results'; }
        if (in_array($status, array(self::DRAFT, self::CANCELLED), true)) { return 'not_graded'; }
        return self::health($progress, $elapsed);
    }

    /** Bootstrap label colour for a health string. */
    public static function healthClass($health)
    {
        switch ($health) {
            case 'achieved':  return 'success';
            case 'on_track':  return 'success';
            case 'at_risk':   return 'warning';
            case 'off_track': return 'danger';
            default:          return 'default';
        }
    }

    /** Objective status transition (lightweight). */
    public static function transitionStatus($from, $action)
    {
        $from = $from ? $from : self::DRAFT;
        switch ($action) {
            case 'activate': return in_array($from, array(self::DRAFT), true) ? self::ACTIVE : $from;
            case 'achieve':  return in_array($from, array(self::ACTIVE), true) ? self::ACHIEVED : $from;
            case 'miss':     return in_array($from, array(self::ACTIVE), true) ? self::MISSED : $from;
            case 'cancel':   return in_array($from, array(self::DRAFT, self::ACTIVE), true) ? self::CANCELLED : $from;
            case 'reopen':   return in_array($from, array(self::ACHIEVED, self::MISSED, self::CANCELLED), true) ? self::ACTIVE : $from;
        }
        return $from;
    }
}
