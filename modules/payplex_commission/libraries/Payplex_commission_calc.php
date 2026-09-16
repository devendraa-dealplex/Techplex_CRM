<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_commission_calc — pure, framework-independent commission calculator.
 * Deterministic and unit-testable (see tests/CalcTest.php).
 *
 * A "rule" is an associative array. Supported types:
 *   ['type' => 'fixed',      'amount' => 500]
 *   ['type' => 'percentage', 'rate'   => 5]                       // 5% of base
 *   ['type' => 'slab',       'slabs'  => [['upto'=>100000,'rate'=>3],
 *                                          ['upto'=>500000,'rate'=>5],
 *                                          ['upto'=>null,   'rate'=>7]]]  // marginal
 * Optional modifiers on any rule:
 *   'accelerator' => ['threshold_pct'=>100, 'multiplier'=>1.25]   // if target met
 *   'cap'         => 50000                                        // max payout
 *
 * All money math uses round(…, 2). No side effects.
 *
 * NOTE: default rates here are PLACEHOLDERS. Real values come from BD-02.
 */
class Payplex_commission_calc
{
    /**
     * Why this definition cannot produce a commission, if it cannot.
     *
     * compute() is deliberately forgiving with `?? 0`, which means a definition
     * with no rate quietly computes ZERO rather than failing. For a calculator
     * that is defensible; for a statement it is not. A staff member owed money
     * would be shown a statement saying they earned nothing, computed and
     * itemised and entirely wrong, with nothing anywhere to indicate that no
     * rate had been configured.
     *
     * Payplex_commission_rule already refuses such a rule at validation — "A
     * rule with no rate is invalid, not 0% and not 5%" — but the legacy
     * rule_versions path decodes rule_json and checks only that it is an array.
     * This is the check that closes that gap, and it is the same principle as
     * everywhere else here: unconfigured means refuse, never zero.
     *
     * @return array reasons; empty means the definition can pay
     */
    public static function definitionProblems(array $rule)
    {
        $type = isset($rule['type']) ? (string) $rule['type'] : '';
        $num  = function ($v) { return $v !== null && $v !== '' && is_numeric($v); };
        $problems = array();

        if ($type === '') {
            return array('The rule has no type, so there is nothing to calculate.');
        }

        switch ($type) {
            case 'fixed':
                if (!$num($rule['amount'] ?? null) || (float) $rule['amount'] <= 0) {
                    $problems[] = 'A fixed rule has no amount greater than zero.';
                }
                break;

            case 'percentage':
            case 'accelerator':
                if (!$num($rule['rate'] ?? null) || (float) $rule['rate'] <= 0) {
                    $problems[] = 'A ' . $type . ' rule has no rate greater than zero.';
                }
                break;

            case 'hybrid':
                $hasFixed = $num($rule['amount'] ?? null) && (float) $rule['amount'] > 0;
                $hasRate  = $num($rule['rate'] ?? null) && (float) $rule['rate'] > 0;
                if (!$hasFixed && !$hasRate) {
                    $problems[] = 'A hybrid rule has neither a fixed amount nor a rate.';
                }
                break;

            case 'slab':
            case 'tiered':
                $bands = isset($rule['slabs']) && is_array($rule['slabs']) ? $rule['slabs'] : array();
                if (!$bands) {
                    $problems[] = 'A ' . $type . ' rule has no bands.';
                    break;
                }
                $anyRate = false;
                foreach ($bands as $i => $band) {
                    $b = (array) $band;
                    if (!$num($b['rate'] ?? null)) {
                        $problems[] = 'Band ' . ($i + 1) . ' has no rate.';
                    } elseif ((float) $b['rate'] > 0) {
                        $anyRate = true;
                    }
                }
                if (!$problems && !$anyRate) {
                    $problems[] = 'Every band of this rule pays zero.';
                }
                break;

            default:
                $problems[] = 'Unknown rule type "' . $type . '".';
        }

        return $problems;
    }

    /**
     * @param float $base        the amount commission is computed on (e.g. invoice/deal value)
     * @param array $rule        rule definition (see above)
     * @param float $achievedPct target achievement % (for accelerator); default 0
     * @return array ['amount'=>float, 'breakdown'=>array]
     */
    public static function compute($base, array $rule, $achievedPct = 0.0)
    {
        $base = max(0.0, (float) $base);
        $type = $rule['type'] ?? 'percentage';
        $breakdown = [];
        $amount = 0.0;

        // A definition that cannot pay must say so rather than returning a
        // confident zero. The amount stays 0.0 so existing callers are not
        // broken, but 'refused' is what a caller writing a statement must read.
        $problems = self::definitionProblems($rule);
        if ($problems) {
            return [
                'amount'    => 0.0,
                'refused'   => true,
                'reason'    => implode(' ', $problems),
                'breakdown' => ['not calculated: ' . implode(' ', $problems)],
            ];
        }

        // Minimum qualifying threshold: below it the transaction earns nothing.
        // Checked against the ORIGINAL base, before any eligible-amount capping,
        // so lowering the cap can never accidentally disqualify a sale.
        if (isset($rule['min_threshold']) && $rule['min_threshold'] !== null
            && $base < (float) $rule['min_threshold']) {
            return [
                'amount'    => 0.0,
                'refused'   => false,   // a real, correct zero: the sale did not qualify
                'reason'    => '',
                'breakdown' => ["base {$base} below minimum threshold " . (float) $rule['min_threshold'] . " -> not eligible"],
            ];
        }

        // Maximum eligible amount: commission is earned only on the first N of
        // the sale. Distinct from 'cap', which limits the payout itself.
        if (isset($rule['max_eligible_base']) && $rule['max_eligible_base'] !== null
            && $base > (float) $rule['max_eligible_base']) {
            $breakdown[] = "eligible base limited to " . (float) $rule['max_eligible_base'] . " (of {$base})";
            $base = (float) $rule['max_eligible_base'];
        }

        switch ($type) {
            case 'fixed':
                $amount = (float) ($rule['amount'] ?? 0);
                $breakdown[] = "fixed {$amount}";
                break;

            case 'percentage':
                $rate = (float) ($rule['rate'] ?? 0);
                $amount = $base * $rate / 100.0;
                $breakdown[] = "{$rate}% of {$base} = " . round($amount, 2);
                break;

            case 'slab':
                $remaining = $base;
                $prevCap = 0.0;
                foreach ($rule['slabs'] ?? [] as $slab) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $upto = $slab['upto']; // null = unbounded
                    $band = ($upto === null) ? $remaining : max(0.0, min($remaining, (float) $upto - $prevCap));
                    $rate = (float) ($slab['rate'] ?? 0);
                    $part = $band * $rate / 100.0;
                    $amount += $part;
                    if ($band > 0) {
                        $breakdown[] = "slab {$rate}% on " . round($band, 2) . " = " . round($part, 2);
                    }
                    $remaining -= $band;
                    $prevCap = ($upto === null) ? $prevCap : (float) $upto;
                }
                break;

            case 'tiered':
                // Unlike 'slab', a tiered rule pays ONE rate on the WHOLE base:
                // the rate of the highest band the base reaches. The difference
                // matters enormously at a boundary, so the two are kept separate
                // rather than approximated by one another.
                $chosen = null;
                foreach ($rule['slabs'] ?? [] as $band) {
                    $upto = $band['upto'] ?? null;
                    if ($upto === null || $base <= (float) $upto) { $chosen = $band; break; }
                }
                if ($chosen === null) {
                    $breakdown[] = "no tier matched base {$base} -> 0";
                    break;
                }
                $rate = (float) ($chosen['rate'] ?? 0);
                $amount = $base * $rate / 100.0;
                $label = ($chosen['upto'] ?? null) === null ? 'top tier' : ('tier up to ' . (float) $chosen['upto']);
                $breakdown[] = "{$label}: {$rate}% of {$base} = " . round($amount, 2);
                break;

            case 'hybrid':
                // Fixed component plus a percentage component. Either may be
                // absent, but a hybrid with neither is rejected at validation.
                $fixed = (float) ($rule['amount'] ?? 0);
                $rate  = (float) ($rule['rate'] ?? 0);
                if ($fixed > 0) {
                    $amount += $fixed;
                    $breakdown[] = "fixed component {$fixed}";
                }
                if ($rate > 0) {
                    $part = $base * $rate / 100.0;
                    $amount += $part;
                    $breakdown[] = "{$rate}% of {$base} = " . round($part, 2);
                }
                break;

            case 'accelerator':
                // The base calculation of an accelerator rule is a percentage;
                // the multiplier is applied by the shared modifier below.
                $rate = (float) ($rule['rate'] ?? 0);
                $amount = $base * $rate / 100.0;
                $breakdown[] = "{$rate}% of {$base} = " . round($amount, 2);
                break;

            default:
                $breakdown[] = "unknown rule type '{$type}' -> 0";
        }

        // Accelerator (if target achievement crosses threshold).
        if (!empty($rule['accelerator'])) {
            $th  = (float) ($rule['accelerator']['threshold_pct'] ?? 100);
            $mul = (float) ($rule['accelerator']['multiplier'] ?? 1);
            if ($achievedPct >= $th && $mul > 0) {
                $before = $amount;
                $amount *= $mul;
                $breakdown[] = "accelerator x{$mul} (achieved {$achievedPct}% >= {$th}%): " . round($before, 2) . " -> " . round($amount, 2);
            }
        }

        // Cap.
        if (isset($rule['cap']) && $rule['cap'] !== null && $amount > (float) $rule['cap']) {
            $breakdown[] = "capped at " . (float) $rule['cap'];
            $amount = (float) $rule['cap'];
        }

        return ['amount' => round($amount, 2), 'refused' => false, 'reason' => '', 'breakdown' => $breakdown];
    }

    /**
     * Clawback: reverse a previously-earned commission by a fraction (e.g. refund).
     * @param float $earned      original commission
     * @param float $refundRatio 0..1 fraction of the deal refunded
     * @return array ['clawback'=>float, 'net'=>float]
     */
    public static function clawback($earned, $refundRatio)
    {
        $earned = (float) $earned;
        $ratio = max(0.0, min(1.0, (float) $refundRatio));
        $clawback = round($earned * $ratio, 2);
        return ['clawback' => $clawback, 'net' => round($earned - $clawback, 2)];
    }
}
