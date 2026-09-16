<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_commission_clawback — automatic clawbacks (spec §2.5).
 *
 * Pure and framework-independent.
 *
 * The governing rule: a clawback is a SEPARATE, IMMUTABLE ADJUSTMENT. The
 * original commission entry is never deleted, never edited, never reduced in
 * place. If a ₹10,000 commission is half clawed back, the record must show
 * ₹10,000 earned and ₹5,000 recovered — not ₹5,000 earned. Anything else
 * destroys the history that proves what was paid and why it changed, which is
 * precisely what an auditor comes looking for.
 *
 * Two supporting rules:
 *
 *  - IDEMPOTENT BY TRIGGER. The same refund arriving twice (a retried webhook, a
 *    re-run of a reconciliation job) must produce one clawback, not two. Every
 *    clawback carries a key derived from the triggering record.
 *  - NEVER RECOVER MORE THAN WAS EARNED. Partial clawbacks accumulate, and the
 *    total can never exceed the original commission.
 */
class Payplex_commission_clawback
{
    /* ---------------- triggers ---------------- */

    /**
     * trigger => [label, default_basis]
     *
     * default_basis is how the recovery is normally sized:
     *   'proportional' — recover in the same ratio as the money returned
     *   'full'         — recover the whole commission
     */
    private static function triggerMap()
    {
        return array(
            'refund'                => array('Refund issued',                'proportional'),
            'payment_reversal'      => array('Payment reversed',             'proportional'),
            'void'                  => array('Transaction voided',           'full'),
            'cancellation'          => array('Order cancelled',              'full'),
            'chargeback'            => array('Chargeback raised',            'proportional'),
            'deal_reversal'         => array('Deal reversed',                'full'),
            'activation_invalid'    => array('Activation invalidated',       'full'),
            'duplicate_commission'  => array('Duplicate commission found',   'full'),
            'bounced_collection'    => array('Collection bounced',           'proportional'),
            'compliance_reversal'   => array('Compliance / fraud reversal',  'full'),
        );
    }

    public static function triggers()
    {
        $out = array();
        foreach (self::triggerMap() as $k => $v) { $out[$k] = $v[0]; }
        return $out;
    }

    public static function isTrigger($t)
    {
        return isset(self::triggerMap()[(string) $t]);
    }

    public static function defaultBasis($trigger)
    {
        $m = self::triggerMap();
        $t = (string) $trigger;
        return isset($m[$t]) ? $m[$t][1] : 'proportional';
    }

    /* ---------------- status ---------------- */

    const PENDING   = 'pending_review';
    const APPROVED  = 'approved';
    const RECOVERED = 'recovered';
    const WAIVED    = 'waived';
    const REJECTED  = 'rejected';

    public static function statuses()
    {
        return array(
            self::PENDING   => 'Pending review',
            self::APPROVED  => 'Approved for recovery',
            self::RECOVERED => 'Recovered',
            self::WAIVED    => 'Waived',
            self::REJECTED  => 'Rejected',
        );
    }

    public static function transitions()
    {
        return array(
            self::PENDING   => array(self::APPROVED, self::WAIVED, self::REJECTED),
            self::APPROVED  => array(self::RECOVERED, self::WAIVED),
            self::RECOVERED => array(),
            self::WAIVED    => array(),
            self::REJECTED  => array(),
        );
    }

    public static function canTransition($from, $to)
    {
        $t = self::transitions();
        if (!isset($t[(string) $from])) { return false; }
        return in_array((string) $to, $t[(string) $from], true);
    }

    /**
     * Waiving or rejecting a recovery is writing off money the company is owed,
     * so it needs a reason and cannot be done by the person who benefits.
     */
    public static function canDecide($clawback, $to, $actorId, $reason = '')
    {
        $c = (array) $clawback;
        $actorId = (int) $actorId;

        if ($actorId <= 0) {
            return array('allowed' => false, 'code' => 'no_actor', 'reason' => 'A reviewer must be identified.');
        }
        $from = (string) (isset($c['status']) ? $c['status'] : '');
        if (!self::canTransition($from, $to)) {
            return array('allowed' => false, 'code' => 'bad_transition',
                'reason' => 'A clawback cannot move from ' . ($from ?: '(none)') . ' to ' . $to . '.');
        }
        if (in_array($to, array(self::WAIVED, self::REJECTED), true) && trim((string) $reason) === '') {
            return array('allowed' => false, 'code' => 'reason_required',
                'reason' => 'Writing off a recovery must be justified in writing.');
        }
        if ((int) (isset($c['staff_id']) ? $c['staff_id'] : 0) === $actorId) {
            return array('allowed' => false, 'code' => 'self_beneficiary',
                'reason' => 'This clawback recovers money from you, so you cannot decide it.');
        }
        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    /* ---------------- idempotency ---------------- */

    /**
     * One triggering record produces one clawback against one commission entry.
     * The key deliberately includes the original entry, so the same refund
     * touching two commissions produces two distinct, legitimate clawbacks.
     */
    public static function idempotencyKey($trigger, $triggerRef, $originalKey)
    {
        return sha1(implode('|', array(
            (string) $trigger,
            (string) $triggerRef,
            (string) $originalKey,
        )));
    }

    /* ---------------- amount ---------------- */

    /**
     * How much to recover.
     *
     * @param float  $originalCommission what was earned
     * @param float  $originalBase       the amount it was earned on
     * @param float  $returnedAmount     how much of that base came back
     * @param string $basis              'proportional' | 'full' | 'fixed'
     * @param float  $alreadyClawed      previously recovered against this entry
     * @param float  $fixedAmount        used when basis is 'fixed'
     *
     * @return array amount, capped, reason, ratio
     */
    public static function computeAmount($originalCommission, $originalBase, $returnedAmount,
                                         $basis = 'proportional', $alreadyClawed = 0.0, $fixedAmount = 0.0)
    {
        $commission = max(0.0, (float) $originalCommission);
        $base       = max(0.0, (float) $originalBase);
        $returned   = max(0.0, (float) $returnedAmount);
        $already    = max(0.0, (float) $alreadyClawed);

        $remaining = round($commission - $already, 2);
        if ($remaining <= 0) {
            return array('amount' => 0.0, 'capped' => true, 'ratio' => 0.0,
                'reason' => 'The whole commission has already been recovered.');
        }

        switch ((string) $basis) {
            case 'full':
                $amount = $remaining;
                $ratio  = 1.0;
                break;

            case 'fixed':
                $amount = max(0.0, (float) $fixedAmount);
                $ratio  = $commission > 0 ? $amount / $commission : 0.0;
                break;

            case 'proportional':
            default:
                if ($base <= 0) {
                    // Without a base there is no ratio to apply. Recovering the
                    // whole thing would be a guess in the company's favour, so
                    // this is surfaced rather than assumed.
                    return array('amount' => 0.0, 'capped' => false, 'ratio' => 0.0,
                        'reason' => 'The original transaction amount is unknown, so a proportional '
                                  . 'recovery cannot be calculated. Choose a full or fixed clawback instead.');
                }
                $ratio  = min(1.0, $returned / $base);
                $amount = round($commission * $ratio, 2);
                break;
        }

        $capped = false;
        if ($amount > $remaining) {
            $amount = $remaining;
            $capped = true;
        }

        return array(
            'amount' => round($amount, 2),
            'capped' => $capped,
            'ratio'  => round($ratio, 6),
            'reason' => $capped ? 'Recovery limited to the amount still outstanding on this commission.' : '',
        );
    }

    /* ---------------- construction ---------------- */

    public static function recoveryMethods()
    {
        return array(
            'next_payout'    => 'Deduct from the next payout',
            'direct_recovery'=> 'Recover directly from the employee',
            'write_off'      => 'Write off (requires approval)',
            'salary_offset'  => 'Offset against salary',
        );
    }

    /**
     * Build a clawback record. Returns array('ok'=>bool,'errors'=>[],'row'=>[]).
     *
     * $original is the source ledger entry (or statement item) being reversed —
     * it is referenced, never modified.
     */
    public static function build($original, $params, $alreadyClawed = 0.0)
    {
        $o = (array) $original;
        $p = (array) $params;
        $e = array();

        $trigger = (string) (isset($p['trigger']) ? $p['trigger'] : '');
        if (!self::isTrigger($trigger)) { $e[] = 'A valid clawback trigger is required.'; }

        if (trim((string) (isset($p['trigger_ref']) ? $p['trigger_ref'] : '')) === '') {
            $e[] = 'The triggering record (refund id, chargeback reference, and so on) must be identified.';
        }
        if (trim((string) (isset($p['reason']) ? $p['reason'] : '')) === '') {
            $e[] = 'A clawback must state its reason.';
        }

        $method = (string) (isset($p['recovery_method']) ? $p['recovery_method'] : 'next_payout');
        if (!isset(self::recoveryMethods()[$method])) { $e[] = 'A valid recovery method is required.'; }

        $commission = (float) (isset($o['commission_amount']) ? $o['commission_amount'] : 0);
        if ($commission <= 0) { $e[] = 'The original entry has no commission to recover.'; }

        if ($e) { return array('ok' => false, 'errors' => $e, 'row' => null); }

        $basis = isset($p['basis']) && $p['basis'] !== '' ? (string) $p['basis'] : self::defaultBasis($trigger);

        $calc = self::computeAmount(
            $commission,
            (float) (isset($o['base_amount']) ? $o['base_amount'] : 0),
            (float) (isset($p['returned_amount']) ? $p['returned_amount'] : 0),
            $basis,
            $alreadyClawed,
            (float) (isset($p['fixed_amount']) ? $p['fixed_amount'] : 0)
        );

        if ($calc['amount'] <= 0) {
            return array('ok' => false, 'errors' => array($calc['reason'] ?: 'Nothing left to recover.'), 'row' => null);
        }

        $originalKey = (string) (isset($o['idempotency_key']) ? $o['idempotency_key'] : '');

        return array('ok' => true, 'errors' => array(), 'calc' => $calc, 'row' => array(
            'idempotency_key'    => self::idempotencyKey($trigger, $p['trigger_ref'], $originalKey),
            'original_key'       => $originalKey,
            'original_ledger_id' => isset($o['id']) ? (int) $o['id'] : null,
            'statement_id'       => isset($o['statement_id']) ? (int) $o['statement_id'] : null,
            'staff_id'           => (int) (isset($o['staff_id']) ? $o['staff_id'] : 0),
            'source_type'        => isset($o['source_type']) ? (string) $o['source_type'] : null,
            'source_id'          => isset($o['source_id']) ? (int) $o['source_id'] : null,
            'trigger_event'      => $trigger,
            'trigger_ref'        => (string) $p['trigger_ref'],
            'basis'              => $basis,
            'original_commission'=> round($commission, 2),
            'original_base'      => round((float) (isset($o['base_amount']) ? $o['base_amount'] : 0), 2),
            'returned_amount'    => round((float) (isset($p['returned_amount']) ? $p['returned_amount'] : 0), 2),
            'amount'             => $calc['amount'],
            'recovery_method'    => $method,
            'reason'             => substr((string) $p['reason'], 0, 500),
            'status'             => self::PENDING,
            'effective_period'   => isset($p['effective_period']) && $p['effective_period'] !== ''
                                     ? (string) $p['effective_period'] : date('Y-m'),
        ));
    }

    /**
     * Total already recovered against one original entry. Waived and rejected
     * clawbacks do not count — they recovered nothing.
     */
    public static function totalClawedBack($clawbacks)
    {
        $t = 0.0;
        foreach ((array) $clawbacks as $c) {
            $c = (array) $c;
            $status = (string) (isset($c['status']) ? $c['status'] : '');
            if (in_array($status, array(self::WAIVED, self::REJECTED), true)) { continue; }
            $t += (float) (isset($c['amount']) ? $c['amount'] : 0);
        }
        return round($t, 2);
    }

    /**
     * The line every report needs: earned, recovered, and what is actually owed.
     * Gross is never reduced — that is the whole point.
     */
    public static function summarise($originalCommission, $clawbacks)
    {
        $gross    = round((float) $originalCommission, 2);
        $clawed   = self::totalClawedBack($clawbacks);
        $net      = round($gross - $clawed, 2);
        return array(
            'gross'     => $gross,
            'clawed'    => $clawed,
            'net'       => $net < 0 ? 0.0 : $net,
            'fully_recovered' => $clawed >= $gross - 0.005,
        );
    }

    public static function statusClass($status)
    {
        switch ((string) $status) {
            case self::RECOVERED: return 'success';
            case self::APPROVED:  return 'info';
            case self::PENDING:   return 'warning';
            case self::WAIVED:
            case self::REJECTED:  return 'default';
            default:              return 'default';
        }
    }
}
