<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Pure, testable helpers for report math (framework-independent).
 */
class Payplex_report_calc
{
    /**
     * Safe percentage, rounded.
     *
     * Returns NULL, not 0.0, when there is nothing to divide by. "0%" and "no
     * data" are different statements: an agent with no leads at all has no
     * conversion rate, whereas an agent with ten leads and no conversions has a
     * rate of zero. Rendering both as "0%" reports a failure that never
     * happened, and the views show a dash for null.
     */
    public static function pct($part, $whole, $decimals = 1)
    {
        $whole = (float) $whole;
        if ($whole <= 0) {
            return null;
        }
        return round(((float) $part) * 100.0 / $whole, $decimals);
    }

    /** Render a percentage for display, distinguishing "none" from "zero". */
    public static function pctLabel($pct)
    {
        return $pct === null ? '—' : $pct . '%';
    }

    /**
     * Build funnel stages with stage-to-stage conversion %.
     * @param array $stages ordered [['label'=>..,'count'=>..], ...]
     * @return array same, with 'pct_of_top' and 'pct_of_prev'
     */
    public static function funnel(array $stages)
    {
        $top = isset($stages[0]) ? (float) $stages[0]['count'] : 0.0;
        $prev = null;
        $out = [];
        foreach ($stages as $s) {
            $c = (float) $s['count'];
            $out[] = [
                'label'       => $s['label'],
                'count'       => (int) $c,
                'pct_of_top'  => self::pct($c, $top),
                'pct_of_prev' => $prev === null ? 100.0 : self::pct($c, $prev),
            ];
            $prev = $c;
        }
        return $out;
    }

    /** ROI = (revenue - cost) / cost * 100, guarded. */
    public static function roi($revenue, $cost)
    {
        $cost = (float) $cost;
        if ($cost <= 0) {
            return null; // undefined
        }
        return round((((float) $revenue) - $cost) * 100.0 / $cost, 1);
    }

    /** Cost per acquisition. */
    public static function cpa($cost, $conversions)
    {
        $conversions = (int) $conversions;
        if ($conversions <= 0) {
            return null;
        }
        return round(((float) $cost) / $conversions, 2);
    }
}
