<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_commission_source — configurable Source Policy (spec §2.2).
 *
 * Decides WHICH business events earn commission and on WHAT amount. Pure and
 * framework-independent so every eligibility and money-derivation rule is
 * unit-testable without a database.
 *
 * The design rules, and why each exists:
 *
 *  1. NOTHING IS ELIGIBLE BY DEFAULT. With no approved policy, generation is
 *     refused outright. The previous behaviour keyed off "invoice status = 2 and
 *     sale_agent set", which nobody had approved as the real rule; guessing the
 *     source of commission is as dangerous as guessing the rate.
 *
 *  2. COLLECTION IS PREFERRED OVER BILLING. An invoice being raised is not money
 *     received. A policy may choose billed-based commission, but it must say so
 *     explicitly, because paying on an invoice that is never collected creates a
 *     clawback the business may never recover.
 *
 *  3. EVERY EXCLUSION HAS A STATED REASON. A silent filter is indistinguishable
 *     from a bug. Excluded rows are always returned with the reason attached, so
 *     finance can see why a sale did not earn.
 *
 *  4. ONE SOURCE RECORD EARNS ONCE. Eligibility carries an idempotency key, so a
 *     re-run cannot pay the same payment twice.
 *
 *  5. PREVIEW AND GENERATION SHARE THIS CODE. evaluate() is the single path; the
 *     caller decides whether to persist. That is what makes "preview total equals
 *     generated total" structurally true rather than a coincidence to be tested
 *     and hoped for.
 */
class Payplex_commission_source
{
    /* ---------------- lifecycle (mirrors the rule builder) ---------------- */

    const DRAFT     = 'draft';
    const SUBMITTED = 'submitted';
    const APPROVED  = 'approved';
    const ACTIVE    = 'active';
    const PAUSED    = 'paused';
    const ARCHIVED  = 'archived';

    const POLICY_REQUIRED = 'policy_required';

    /*
     * Every state names the capability that may reach it. The controller
     * previously gated a list of named targets and let every other state
     * through on the weakest capability — so a state nobody listed inherited
     * the weak gate. Unknown states are refused here rather than allowed.
     */
    public static function stateCapabilities()
    {
        return array(
            self::DRAFT     => 'source_policy',
            self::SUBMITTED => 'source_policy',
            self::APPROVED  => 'source_policy_approve',
            self::ACTIVE    => 'source_policy_approve',
            self::PAUSED    => 'source_policy',
            self::ARCHIVED  => 'source_policy',
        );
    }

    /** The capability needed to move a record INTO $state, or null if unknown. */
    public static function capabilityFor($state)
    {
        $map = self::stateCapabilities();
        $state = (string) $state;
        return isset($map[$state]) ? $map[$state] : null;
    }

    public static function states()
    {
        return array(
            self::DRAFT     => 'Draft',
            self::SUBMITTED => 'Submitted',
            self::APPROVED  => 'Approved',
            self::ACTIVE    => 'Active',
            self::PAUSED    => 'Paused',
            self::ARCHIVED  => 'Archived',
        );
    }

    public static function transitions()
    {
        return array(
            self::DRAFT     => array(self::SUBMITTED, self::ARCHIVED),
            self::SUBMITTED => array(self::APPROVED, self::DRAFT, self::ARCHIVED),
            self::APPROVED  => array(self::ACTIVE, self::DRAFT, self::ARCHIVED),
            self::ACTIVE    => array(self::PAUSED, self::ARCHIVED),
            self::PAUSED    => array(self::ACTIVE, self::ARCHIVED),
            self::ARCHIVED  => array(),
        );
    }

    public static function canTransition($from, $to)
    {
        $t = self::transitions();
        if (!isset($t[(string) $from])) { return false; }
        return in_array((string) $to, $t[(string) $from], true);
    }

    /** A policy that decides who gets paid must not be self-approved. */
    public static function canApprove($policy, $approverId)
    {
        $p = (array) $policy;
        $approverId = (int) $approverId;
        if ($approverId <= 0) {
            return array('allowed' => false, 'code' => 'no_actor', 'reason' => 'An approver must be identified.');
        }
        if ((string) (isset($p['status']) ? $p['status'] : '') !== self::SUBMITTED) {
            return array('allowed' => false, 'code' => 'bad_state', 'reason' => 'Only a submitted policy can be approved.');
        }
        if ((int) (isset($p['created_by']) ? $p['created_by'] : 0) === $approverId) {
            return array('allowed' => false, 'code' => 'maker_is_approver',
                'reason' => 'The person who created a source policy cannot approve it.');
        }
        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    /* ---------------- event taxonomy ---------------- */

    /**
     * event => [label, is_collection_event]
     *
     * is_collection_event marks events where money has actually been received.
     * Billing-only events are allowed but flagged, because commission paid on
     * them is at risk until the invoice is collected.
     */
    private static function eventMap()
    {
        return array(
            'invoice_paid_full'     => array('Invoice fully paid',        true),
            'invoice_paid_partial'  => array('Invoice partially paid',    true),
            'payment_received'      => array('Payment received',          true),
            'subscription_collected'=> array('Subscription payment collected', true),
            'renewal_collected'     => array('Renewal collected',         true),
            'first_transaction'     => array('First successful transaction', true),
            'invoice_created'       => array('Invoice raised (not yet collected)', false),
            'customer_converted'    => array('Customer converted',        false),
            'deal_won'              => array('Deal won',                  false),
            'merchant_activated'    => array('Merchant activated',        false),
            'product_delivered'     => array('Product delivered',         false),
            'custom_approved'       => array('Approved custom event',     false),
        );
    }

    public static function events()
    {
        $out = array();
        foreach (self::eventMap() as $k => $v) { $out[$k] = $v[0]; }
        return $out;
    }

    public static function isCollectionEvent($event)
    {
        $m = self::eventMap();
        $e = (string) $event;
        return isset($m[$e]) ? (bool) $m[$e][1] : false;
    }

    /** Events that only represent billing — flagged when a policy selects them. */
    public static function billingOnlyEvents($selected)
    {
        $out = array();
        foreach ((array) $selected as $e) {
            if (isset(self::eventMap()[(string) $e]) && !self::isCollectionEvent($e)) { $out[] = (string) $e; }
        }
        return $out;
    }

    public static function gatewayFeeTreatments()
    {
        return array(
            'ignore' => 'Ignore gateway fees (commission on the full amount)',
            'deduct' => 'Deduct gateway fees before calculating commission',
        );
    }

    /* ---------------- policy validation ---------------- */

    /**
     * Normalise a stored policy into a predictable shape. Absent settings take
     * the SAFE side: refunds and cancellations excluded, partials off, tax
     * excluded from the base.
     */
    public static function normalizePolicy($policy)
    {
        $p = (array) $policy;

        $events = isset($p['events']) ? $p['events'] : array();
        if (is_string($events)) { $events = json_decode($events, true); }
        if (!is_array($events)) { $events = array(); }
        $clean = array();
        foreach ($events as $e) {
            $e = (string) $e;
            if (isset(self::eventMap()[$e]) && !in_array($e, $clean, true)) { $clean[] = $e; }
        }

        $filters = isset($p['filters']) ? $p['filters'] : array();
        if (is_string($filters)) { $filters = json_decode($filters, true); }
        if (!is_array($filters)) { $filters = array(); }

        $bool = function ($v, $default) {
            if ($v === null || $v === '') { return $default; }
            return (bool) (int) $v;
        };

        return array(
            'id'             => isset($p['id']) ? (int) $p['id'] : 0,
            'name'           => isset($p['name']) ? (string) $p['name'] : '',
            'version'        => isset($p['version']) ? (int) $p['version'] : 1,
            'status'         => isset($p['status']) ? (string) $p['status'] : self::DRAFT,
            'created_by'     => isset($p['created_by']) ? (int) $p['created_by'] : 0,
            'events'         => $clean,
            'filters'        => $filters,

            // safe defaults
            'exclude_refunded'      => $bool(isset($p['exclude_refunded']) ? $p['exclude_refunded'] : null, true),
            'exclude_cancelled'     => $bool(isset($p['exclude_cancelled']) ? $p['exclude_cancelled'] : null, true),
            'allow_partial'         => $bool(isset($p['allow_partial']) ? $p['allow_partial'] : null, false),
            'include_tax'           => $bool(isset($p['include_tax']) ? $p['include_tax'] : null, false),
            'include_discount'      => $bool(isset($p['include_discount']) ? $p['include_discount'] : null, false),
            'gateway_fee_treatment' => isset($p['gateway_fee_treatment']) && $p['gateway_fee_treatment'] !== ''
                                        ? (string) $p['gateway_fee_treatment'] : 'ignore',
            'min_collected_amount'  => isset($p['min_collected_amount']) && $p['min_collected_amount'] !== '' && $p['min_collected_amount'] !== null
                                        ? (float) $p['min_collected_amount'] : null,
            'currencies'            => self::listify(isset($p['currencies']) ? $p['currencies'] : null),
            'date_from'             => isset($p['date_from']) && $p['date_from'] ? (string) $p['date_from'] : null,
            'date_to'               => isset($p['date_to']) && $p['date_to'] ? (string) $p['date_to'] : null,
        );
    }

    private static function listify($v)
    {
        if ($v === null || $v === '') { return array(); }
        if (is_array($v)) { $out = $v; }
        else {
            $decoded = json_decode((string) $v, true);
            $out = is_array($decoded) ? $decoded : explode(',', (string) $v);
        }
        $clean = array();
        foreach ($out as $x) {
            $x = trim((string) $x);
            if ($x !== '') { $clean[] = $x; }
        }
        return $clean;
    }

    public static function validate($policy)
    {
        $p = self::normalizePolicy($policy);
        $e = array();

        if (trim($p['name']) === '') { $e[] = 'Policy name is required.'; }
        if (!$p['events']) {
            $e[] = 'At least one eligible event must be selected — a policy that matches nothing would silently stop all commission.';
        }
        if (!isset(self::gatewayFeeTreatments()[$p['gateway_fee_treatment']])) {
            $e[] = 'Gateway fee treatment must be either ignore or deduct.';
        }
        if ($p['min_collected_amount'] !== null && $p['min_collected_amount'] < 0) {
            $e[] = 'Minimum collected amount cannot be negative.';
        }
        if ($p['date_from'] && strtotime($p['date_from']) === false) { $e[] = 'Date-from is not a valid date.'; }
        if ($p['date_to'] && strtotime($p['date_to']) === false)     { $e[] = 'Date-to is not a valid date.'; }
        if ($p['date_from'] && $p['date_to'] && strtotime($p['date_from']) !== false
            && strtotime($p['date_to']) !== false && strtotime($p['date_to']) < strtotime($p['date_from'])) {
            $e[] = 'Date-to cannot be earlier than date-from.';
        }

        // partial payments only make sense if a partial event is selected
        if (in_array('invoice_paid_partial', $p['events'], true) && !$p['allow_partial']) {
            $e[] = 'Partial-payment events are selected but partial payments are not allowed; '
                 . 'either enable partial payments or remove that event.';
        }

        return array('ok' => empty($e), 'errors' => $e, 'policy' => $p);
    }

    /** Warnings that should be seen but do not block saving. */
    public static function warnings($policy)
    {
        $p = self::normalizePolicy($policy);
        $w = array();

        $billing = self::billingOnlyEvents($p['events']);
        if ($billing) {
            $w[] = 'This policy pays on billing events (' . implode(', ', $billing) . ') rather than on money '
                 . 'received. Commission will be owed before the invoice is collected, and will need a clawback '
                 . 'if it never is.';
        }
        if (!$p['exclude_refunded']) {
            $w[] = 'Refunded transactions are NOT excluded. Commission will be paid on money that was returned.';
        }
        if (!$p['exclude_cancelled']) {
            $w[] = 'Cancelled transactions are NOT excluded.';
        }
        if ($p['include_tax']) {
            $w[] = 'Tax is included in the commission base, so commission is paid on money owed to the tax authority.';
        }
        return $w;
    }

    public static function isUsable($policy)
    {
        $p = self::normalizePolicy($policy);
        return $p['status'] === self::ACTIVE && !empty($p['events']);
    }

    /* ---------------- idempotency ---------------- */

    /**
     * Stable identity for a source record. A payment is the finest grain we pay
     * on, so the key includes it; two partial payments against one invoice are
     * two earnings, but the SAME payment can never earn twice.
     */
    public static function idempotencyKey($event)
    {
        $e = (array) $event;
        return sha1(implode('|', array(
            (string) (isset($e['source_type']) ? $e['source_type'] : ''),
            (string) (isset($e['source_id'])   ? $e['source_id']   : ''),
            (string) (isset($e['payment_id'])  ? $e['payment_id']  : ''),
            (string) (isset($e['staff_id'])    ? $e['staff_id']    : ''),
        )));
    }

    /* ---------------- base derivation ---------------- */

    /**
     * The amount commission is calculated on, given the rule's chosen base and
     * the policy's treatment of tax, discount and gateway fees.
     *
     * Returns array('ok'=>bool,'base'=>float,'reason'=>string,'notes'=>array).
     * A base the event cannot supply is an explicit failure, never a zero — a
     * silent zero looks like a legitimately tiny commission.
     */
    public static function deriveBase($event, $calcBase, $policy)
    {
        $e = (array) $event;
        $p = self::normalizePolicy($policy);
        $notes = array();

        $num = function ($k) use ($e) {
            return isset($e[$k]) && $e[$k] !== '' && is_numeric($e[$k]) ? (float) $e[$k] : null;
        };

        switch ((string) $calcBase) {
            case 'amount_collected':
            case 'subscription_payment':
            case 'renewal':
            case 'first_transaction':
                $base = $num('amount_collected');
                $field = 'amount_collected';
                break;

            case 'gross_billed':
                $base = $num('gross_billed');
                $field = 'gross_billed';
                break;

            case 'net_billed':
                $base = $num('net_billed');
                if ($base === null) {
                    // net = gross less discount, when the event supplies the parts
                    $g = $num('gross_billed'); $d = $num('discount_amount');
                    if ($g !== null) {
                        $base = $g - ($d === null ? 0.0 : $d);
                        $notes[] = 'net billed derived as gross less discount';
                    }
                }
                $field = 'net_billed';
                break;

            case 'net_revenue':
                $base = $num('net_revenue');
                $field = 'net_revenue';
                break;

            case 'gross_margin':
                $base = $num('gross_margin');
                $field = 'gross_margin';
                break;

            case 'activated_customer':
            case 'custom_kpi':
                // unit-based bases: the rule should be fixed-amount, so the base
                // is nominal and any supplied value is passed through
                $base = $num('unit_value');
                if ($base === null) { $base = 0.0; $notes[] = 'unit-based rule: base is nominal'; }
                $field = 'unit_value';
                break;

            default:
                return array('ok' => false, 'base' => 0.0, 'notes' => $notes,
                    'reason' => 'Unknown calculation base "' . (string) $calcBase . '".');
        }

        if ($base === null) {
            return array('ok' => false, 'base' => 0.0, 'notes' => $notes,
                'reason' => 'This transaction does not carry a ' . $field . ' amount, so the rule\'s '
                          . 'calculation base cannot be determined.');
        }

        // tax
        if (!$p['include_tax']) {
            $tax = $num('tax_amount');
            if ($tax !== null && $tax > 0 && in_array((string) $calcBase,
                array('gross_billed', 'net_billed', 'amount_collected', 'subscription_payment', 'renewal', 'first_transaction'), true)) {
                // for a partial collection, remove tax in the same proportion as the collection
                $gross = $num('gross_billed');
                if ((string) $calcBase !== 'gross_billed' && $gross !== null && $gross > 0 && $base < $gross) {
                    $tax = $tax * ($base / $gross);
                    $notes[] = 'tax apportioned to the collected share';
                }
                $base -= $tax;
                $notes[] = 'tax of ' . round($tax, 2) . ' excluded';
            }
        }

        // gateway fees
        if ($p['gateway_fee_treatment'] === 'deduct') {
            $fee = $num('gateway_fee');
            if ($fee !== null && $fee > 0) {
                $base -= $fee;
                $notes[] = 'gateway fee of ' . round($fee, 2) . ' deducted';
            }
        }

        if ($base < 0) {
            $notes[] = 'deductions exceeded the amount; base floored at 0';
            $base = 0.0;
        }

        return array('ok' => true, 'base' => round($base, 2), 'reason' => '', 'notes' => $notes);
    }

    /* ---------------- eligibility ---------------- */

    /**
     * Is one event eligible under this policy? Returns the decision AND the
     * reason, so an exclusion is always explainable.
     */
    public static function isEligible($event, $policy, $alreadyPaidKeys = array())
    {
        $e = (array) $event;
        $p = self::normalizePolicy($policy);

        if (!self::isUsable($p)) {
            return array('eligible' => false, 'code' => self::POLICY_REQUIRED,
                'reason' => 'No approved and active source policy, so no transaction is eligible.');
        }

        $type = (string) (isset($e['source_type']) ? $e['source_type'] : '');
        if (!in_array($type, $p['events'], true)) {
            return array('eligible' => false, 'code' => 'event_not_selected',
                'reason' => 'Event "' . $type . '" is not one of the events this policy pays on.');
        }

        // already earned — the guard against paying the same money twice
        $key = self::idempotencyKey($e);
        if (in_array($key, (array) $alreadyPaidKeys, true)) {
            return array('eligible' => false, 'code' => 'already_paid', 'key' => $key,
                'reason' => 'Commission has already been generated for this exact source record.');
        }

        if ($p['exclude_refunded'] && !empty($e['is_refunded'])) {
            return array('eligible' => false, 'code' => 'refunded',
                'reason' => 'Transaction was refunded.');
        }
        if ($p['exclude_cancelled'] && !empty($e['is_cancelled'])) {
            return array('eligible' => false, 'code' => 'cancelled',
                'reason' => 'Transaction was cancelled.');
        }

        if (!$p['allow_partial'] && !empty($e['is_partial'])) {
            return array('eligible' => false, 'code' => 'partial_not_allowed',
                'reason' => 'This is a partial payment and the policy does not pay on partial collections.');
        }

        if ((int) (isset($e['staff_id']) ? $e['staff_id'] : 0) <= 0) {
            return array('eligible' => false, 'code' => 'no_staff',
                'reason' => 'No staff member is attributed to this transaction.');
        }

        if ($p['currencies']) {
            $cur = strtoupper(trim((string) (isset($e['currency']) ? $e['currency'] : '')));
            $allowed = array_map('strtoupper', $p['currencies']);
            if ($cur === '' || !in_array($cur, $allowed, true)) {
                return array('eligible' => false, 'code' => 'currency',
                    'reason' => 'Currency ' . ($cur ?: '(none)') . ' is not covered by this policy.');
            }
        }

        if ($p['min_collected_amount'] !== null) {
            $collected = isset($e['amount_collected']) && is_numeric($e['amount_collected'])
                ? (float) $e['amount_collected'] : 0.0;
            if ($collected < $p['min_collected_amount']) {
                return array('eligible' => false, 'code' => 'below_minimum',
                    'reason' => 'Collected ' . round($collected, 2) . ' is below the policy minimum of '
                              . round($p['min_collected_amount'], 2) . '.');
            }
        }

        $when = isset($e['occurred_at']) ? strtotime((string) $e['occurred_at']) : false;
        if ($p['date_from'] && $when !== false && $when < strtotime($p['date_from'] . ' 00:00:00')) {
            return array('eligible' => false, 'code' => 'before_window',
                'reason' => 'Transaction predates the policy window.');
        }
        if ($p['date_to'] && $when !== false && $when > strtotime($p['date_to'] . ' 23:59:59')) {
            return array('eligible' => false, 'code' => 'after_window',
                'reason' => 'Transaction falls after the policy window.');
        }

        // scope filters (company/product/team/...): each set filter must match
        foreach ($p['filters'] as $field => $wanted) {
            if ($wanted === null || $wanted === '' || $wanted === array()) { continue; }
            $allowed = self::listify($wanted);
            if (!$allowed) { continue; }
            $have = isset($e[$field]) ? trim((string) $e[$field]) : '';
            $match = false;
            foreach ($allowed as $a) { if (strcasecmp($a, $have) === 0) { $match = true; break; } }
            if (!$match) {
                return array('eligible' => false, 'code' => 'filter_' . $field,
                    'reason' => ucfirst(str_replace('_', ' ', $field)) . ' "' . ($have ?: '(none)')
                              . '" is not covered by this policy.');
            }
        }

        return array('eligible' => true, 'code' => 'ok', 'reason' => '', 'key' => $key);
    }

    /**
     * THE single evaluation path, used by both preview and real generation.
     *
     * @param array    $events           source records
     * @param array    $policy           the active source policy
     * @param callable $resolveRule      fn(array $context, string $on) => rule resolution
     * @param callable $computeCommission fn(float $base, array $ruleDef, float $achievedPct) => ['amount'=>..,'breakdown'=>..]
     * @param array    $alreadyPaidKeys  idempotency keys already earned
     *
     * @return array eligible[], excluded[], totals, config_required[]
     */
    public static function evaluate($events, $policy, $resolveRule, $computeCommission, $alreadyPaidKeys = array())
    {
        $p = self::normalizePolicy($policy);

        $out = array(
            'policy_id'      => $p['id'],
            'policy_version' => $p['version'],
            'eligible'       => array(),
            'excluded'       => array(),
            'config_required'=> array(),
            'totals'         => array('eligible_count' => 0, 'excluded_count' => 0,
                                      'config_required_count' => 0, 'commission' => 0.0, 'base' => 0.0),
        );

        if (!self::isUsable($p)) {
            foreach ((array) $events as $e) {
                $e = (array) $e;
                $out['excluded'][] = array_merge($e, array(
                    'exclusion_code'   => self::POLICY_REQUIRED,
                    'exclusion_reason' => 'No approved and active source policy exists, so commission generation '
                                        . 'is disabled. Configure and approve a source policy first.',
                ));
            }
            $out['totals']['excluded_count'] = count($out['excluded']);
            return $out;
        }

        // keys seen within THIS run, so a duplicated row in one batch cannot
        // earn twice either
        $seen = array();

        foreach ((array) $events as $e) {
            $e = (array) $e;

            $decision = self::isEligible($e, $p, array_merge((array) $alreadyPaidKeys, $seen));
            if (!$decision['eligible']) {
                $out['excluded'][] = array_merge($e, array(
                    'exclusion_code'   => $decision['code'],
                    'exclusion_reason' => $decision['reason'],
                ));
                continue;
            }

            $key = $decision['key'];

            // which rule governs this transaction?
            $context = array(
                'staff_id'      => isset($e['staff_id'])      ? $e['staff_id']      : null,
                'employee_role' => isset($e['employee_role']) ? $e['employee_role'] : null,
                'product'       => isset($e['product'])       ? $e['product']       : null,
                'company'       => isset($e['company'])       ? $e['company']       : null,
                'sales_channel' => isset($e['sales_channel']) ? $e['sales_channel'] : null,
                'lead_source'   => isset($e['lead_source'])   ? $e['lead_source']   : null,
                'customer_type' => isset($e['customer_type']) ? $e['customer_type'] : null,
                'territory'     => isset($e['territory'])     ? $e['territory']     : null,
            );
            $resolution = call_user_func($resolveRule, $context, isset($e['occurred_at']) ? $e['occurred_at'] : null);

            if (!is_array($resolution) || !isset($resolution['status']) || $resolution['status'] !== 'ok') {
                $out['config_required'][] = array_merge($e, array(
                    'exclusion_code'   => 'configuration_required',
                    'exclusion_reason' => is_array($resolution) && isset($resolution['reason'])
                        ? $resolution['reason']
                        : 'No approved, active commission rule applies to this transaction.',
                ));
                continue;
            }

            $rule = (array) $resolution['rule'];

            $baseRes = self::deriveBase($e, isset($rule['calc_base']) ? $rule['calc_base'] : '', $p);
            if (!$baseRes['ok']) {
                $out['config_required'][] = array_merge($e, array(
                    'exclusion_code'   => 'base_unavailable',
                    'exclusion_reason' => $baseRes['reason'],
                    'rule_id'          => isset($rule['id']) ? (int) $rule['id'] : 0,
                ));
                continue;
            }

            $achieved = isset($e['achieved_pct']) && is_numeric($e['achieved_pct']) ? (float) $e['achieved_pct'] : 0.0;
            $calc = call_user_func($computeCommission, $baseRes['base'], $rule, $achieved);
            $amount = isset($calc['amount']) ? (float) $calc['amount'] : 0.0;

            $seen[] = $key;

            $out['eligible'][] = array_merge($e, array(
                'idempotency_key'  => $key,
                'rule_id'          => isset($rule['id']) ? (int) $rule['id'] : 0,
                'rule_version'     => isset($rule['version']) ? (int) $rule['version'] : 1,
                'rule_name'        => isset($rule['name']) ? $rule['name'] : '',
                'calc_base'        => isset($rule['calc_base']) ? $rule['calc_base'] : '',
                'base_amount'      => $baseRes['base'],
                'base_notes'       => $baseRes['notes'],
                'commission_amount'=> round($amount, 2),
                'breakdown'        => isset($calc['breakdown']) ? $calc['breakdown'] : array(),
                'achieved_pct'     => $achieved,
            ));

            $out['totals']['base']       += $baseRes['base'];
            $out['totals']['commission'] += $amount;
        }

        $out['totals']['eligible_count']        = count($out['eligible']);
        $out['totals']['excluded_count']        = count($out['excluded']);
        $out['totals']['config_required_count'] = count($out['config_required']);
        $out['totals']['base']       = round($out['totals']['base'], 2);
        $out['totals']['commission'] = round($out['totals']['commission'], 2);

        return $out;
    }

    /** Group an evaluation's eligible rows by staff, for statement creation. */
    public static function groupByStaff($evaluation)
    {
        $out = array();
        foreach ((array) (isset($evaluation['eligible']) ? $evaluation['eligible'] : array()) as $row) {
            $sid = (int) (isset($row['staff_id']) ? $row['staff_id'] : 0);
            if (!isset($out[$sid])) { $out[$sid] = array('rows' => array(), 'commission' => 0.0, 'base' => 0.0); }
            $out[$sid]['rows'][] = $row;
            $out[$sid]['commission'] += (float) $row['commission_amount'];
            $out[$sid]['base']       += (float) $row['base_amount'];
        }
        foreach ($out as $sid => $g) {
            $out[$sid]['commission'] = round($g['commission'], 2);
            $out[$sid]['base']       = round($g['base'], 2);
        }
        return $out;
    }

    public static function statusClass($status)
    {
        switch ((string) $status) {
            case self::ACTIVE:    return 'success';
            case self::APPROVED:  return 'info';
            case self::SUBMITTED: return 'warning';
            case self::PAUSED:    return 'warning';
            default:              return 'default';
        }
    }
}
