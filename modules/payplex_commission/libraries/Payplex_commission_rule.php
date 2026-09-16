<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_commission_rule — the Commission Rule Builder core (spec §2.1).
 *
 * Pure and framework-independent: rule validation, lifecycle, context matching,
 * deterministic priority resolution and conflict detection. No database, no CI,
 * no side effects, so every financial-correctness rule here is unit-testable.
 *
 * Three principles drive the design, and each exists because getting it wrong
 * costs real money:
 *
 *  1. NO INVENTED RATES. Real commission percentages are a business decision that
 *     has not been approved. This class will never supply a default rate, and
 *     resolve() returns CONFIGURATION_REQUIRED rather than falling back to
 *     anything. A rule with no rate is invalid, not "0%" and not "5%".
 *
 *  2. ONLY APPROVED + ACTIVE RULES PAY. A draft or submitted rule can be modelled
 *     and previewed but can never generate a production commission, and a rule
 *     flagged as a test rule can never become active in a production environment.
 *
 *  3. HISTORY IS IMMUTABLE. Editing a rule creates a new version; it never
 *     changes what an already-calculated commission was based on. Every
 *     calculation records the rule id AND its version.
 */
class Payplex_commission_rule
{
    /* ---------------- lifecycle ---------------- */

    const DRAFT     = 'draft';
    const SUBMITTED = 'submitted';
    const APPROVED  = 'approved';
    const ACTIVE    = 'active';
    const PAUSED    = 'paused';
    const EXPIRED   = 'expired';
    const ARCHIVED  = 'archived';

    /** Returned instead of a rule when nothing approved applies. Never a rate. */
    const CONFIGURATION_REQUIRED = 'configuration_required';

    /*
     * Every state names the capability that may reach it. The controller
     * previously gated a list of named targets and let every other state
     * through on the weakest capability — so a state nobody listed inherited
     * the weak gate. Unknown states are refused here rather than allowed.
     */
    public static function stateCapabilities()
    {
        return array(
            self::DRAFT     => 'rules',
            self::SUBMITTED => 'rules',
            self::APPROVED  => 'rules_approve',
            self::ACTIVE    => 'rules_approve',
            self::PAUSED    => 'rules',
            self::EXPIRED   => 'rules',
            self::ARCHIVED  => 'rules',
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
            self::EXPIRED   => 'Expired',
            self::ARCHIVED  => 'Archived',
        );
    }

    /** Permitted transitions. Anything not listed here is refused. */
    public static function transitions()
    {
        return array(
            self::DRAFT     => array(self::SUBMITTED, self::ARCHIVED),
            self::SUBMITTED => array(self::APPROVED, self::DRAFT, self::ARCHIVED),   // back to draft = rejected
            self::APPROVED  => array(self::ACTIVE, self::DRAFT, self::ARCHIVED),
            self::ACTIVE    => array(self::PAUSED, self::EXPIRED, self::ARCHIVED),
            self::PAUSED    => array(self::ACTIVE, self::EXPIRED, self::ARCHIVED),
            self::EXPIRED   => array(self::ARCHIVED),
            self::ARCHIVED  => array(),
        );
    }

    public static function canTransition($from, $to)
    {
        $t = self::transitions();
        $from = (string) $from;
        if (!isset($t[$from])) { return false; }
        return in_array((string) $to, $t[$from], true);
    }

    /**
     * Approval requires a different person than the maker. A rule that sets the
     * rates by which people are paid is exactly where self-approval must not be
     * possible.
     */
    public static function canApprove($rule, $approverId)
    {
        $r = (array) $rule;
        $approverId = (int) $approverId;
        if ($approverId <= 0) {
            return array('allowed' => false, 'code' => 'no_actor', 'reason' => 'An approver must be identified.');
        }
        if ((string) (isset($r['status']) ? $r['status'] : '') !== self::SUBMITTED) {
            return array('allowed' => false, 'code' => 'bad_state', 'reason' => 'Only a submitted rule can be approved.');
        }
        if ((int) (isset($r['created_by']) ? $r['created_by'] : 0) === $approverId) {
            return array('allowed' => false, 'code' => 'maker_is_approver',
                'reason' => 'The person who created a rule cannot approve it.');
        }
        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    /** Only these states may generate a production commission. */
    public static function isPayable($rule, $isProduction = true, $on = null)
    {
        $r = (array) $rule;
        if ((string) (isset($r['status']) ? $r['status'] : '') !== self::ACTIVE) { return false; }
        if ($isProduction && (int) (isset($r['is_test']) ? $r['is_test'] : 0) === 1) { return false; }
        return self::isEffectiveOn($r, $on);
    }

    /**
     * May a statement computed under this rule be approved, made payable or paid?
     *
     * isPayable() guards GENERATION: the resolver refuses to pick a rule that
     * is not active, or that is test-flagged on a production install. Nothing
     * guarded the corridor beyond that door. A statement already written to the
     * table carried its figures forward on its own, and statementTransition()
     * checked only who was acting — the transition, the reason, maker versus
     * approver, self-beneficiary, staleness, immutability — never whether the
     * rule that produced the money was one this install may pay by.
     *
     * On staging that gap is visible in a single row: statement #1 holds
     * ₹12,500 computed at 5% under legacy rule version 2, "TEST Accelerator
     * 5pct x1.25", which was deactivated the following day precisely because
     * test rules must never generate commission. It sits in under_review, and
     * until now any second capability-holder could have walked it to paid.
     *
     * The environment default exists for exactly the scenario this completes:
     * its own docblock names "a database copied from staging to production".
     * In that copy, the test RULE stops being payable and refuses to generate
     * anything new — while this already-generated statement arrives intact and
     * unguarded.
     *
     * @param array|null $rule         the rule row, or null when it cannot be found
     * @param bool       $isProduction the declared environment
     * @param string     $to           the state being moved to
     * @return array allowed, code, reason
     */
    public static function statementGate($rule, $isProduction = true, $to = '', $on = null)
    {
        /* only the steps that ratify or release money */
        if (!in_array((string) $to, array('approved', 'payable', 'paid'), true)) {
            return array('allowed' => true, 'code' => 'not_applicable', 'reason' => '');
        }

        if (!is_array($rule) || empty($rule)) {
            return array('allowed' => false, 'code' => 'rule_unknown',
                'reason' => 'The rule this statement was computed under cannot be identified, '
                          . 'so the figures cannot be shown to rest on an approved rate. '
                          . 'Regenerate the statement under a current rule.');
        }

        /*
         * Two rules answer to the same id and the statement does not say which
         * one produced it. Approving on the wrong rule would ratify a figure
         * against a rate that never applied to it, so this refuses rather than
         * picking one.
         */
        if (!empty($rule['_ambiguous'])) {
            $names = isset($rule['candidates']) && is_array($rule['candidates'])
                ? implode('" and "', $rule['candidates']) : '';
            return array('allowed' => false, 'code' => 'rule_ambiguous',
                'reason' => 'This statement records rule #' . (int) (isset($rule['id']) ? $rule['id'] : 0)
                          . ', and two different rules carry that id'
                          . ($names !== '' ? ' — "' . $names . '"' : '')
                          . '. The statement does not record which one produced its figures, so the '
                          . 'rate behind this money cannot be established. Regenerate it under a '
                          . 'current rule before approving.');
        }

        if (self::isPayable($rule, $isProduction, $on)) {
            return array('allowed' => true, 'code' => 'ok', 'reason' => '');
        }

        /* say which of the reasons applies — "not payable" alone is not actionable */
        $status = (string) (isset($rule['status']) ? $rule['status'] : '');
        $name   = trim((string) (isset($rule['name']) ? $rule['name']
                        : (isset($rule['rule_code']) ? $rule['rule_code'] : '')));
        $label  = $name !== '' ? '"' . $name . '"' : 'the rule';

        if ($isProduction && (int) (isset($rule['is_test']) ? $rule['is_test'] : 0) === 1) {
            return array('allowed' => false, 'code' => 'rule_is_test',
                'reason' => 'This statement was computed under ' . $label . ', which is flagged as a '
                          . 'TEST rule. A test rule may never pay on a production install.');
        }
        if ($status !== self::ACTIVE) {
            return array('allowed' => false, 'code' => 'rule_not_active',
                'reason' => 'This statement was computed under ' . $label . ', which is '
                          . ($status !== '' ? $status : 'not active')
                          . ' rather than active. Approve and activate the rule, or regenerate '
                          . 'the statement under the rule that should apply.');
        }
        return array('allowed' => false, 'code' => 'rule_out_of_window',
            'reason' => 'This statement was computed under ' . $label . ', which is not in force for '
                      . 'this period. Check its effective dates before approving.');
    }

    /* ---------------- calculation types & bases ---------------- */

    public static function calcTypes()
    {
        return array(
            'percentage'  => 'Percentage of base',
            'fixed'       => 'Fixed amount',
            'slab'        => 'Slab (marginal — each band at its own rate)',
            'tiered'      => 'Tiered (whole base at the rate of the band reached)',
            'accelerator' => 'Accelerator (base calculation multiplied on target attainment)',
            'hybrid'      => 'Hybrid (fixed plus percentage)',
        );
    }

    /**
     * What the commission is calculated ON. This is a business decision, so the
     * list is explicit and a rule must name one — there is no default base.
     */
    public static function calcBases()
    {
        return array(
            'gross_billed'          => 'Gross billed',
            'net_billed'            => 'Net billed',
            'amount_collected'      => 'Amount collected',
            'net_revenue'           => 'Net revenue',
            'gross_margin'          => 'Gross margin',
            'activated_customer'    => 'Activated customer',
            'first_transaction'     => 'First successful transaction',
            'subscription_payment'  => 'Subscription payment',
            'renewal'               => 'Renewal collected',
            'custom_kpi'            => 'Approved custom KPI',
        );
    }

    /** The dimensions a rule may be scoped by, most specific first. */
    public static function matchDimensions()
    {
        // order matters: it defines specificity for deterministic tie-breaking
        return array(
            'staff_id', 'employee_role', 'product', 'company',
            'sales_channel', 'lead_source', 'customer_type', 'territory',
        );
    }

    /* ---------------- validation ---------------- */

    /**
     * Validate a rule definition. Returns array('ok'=>bool,'errors'=>array).
     *
     * This is deliberately strict about missing numbers. A rule that reaches
     * production with a null rate would either pay nothing or, worse, invite a
     * "sensible default" somewhere downstream.
     */
    public static function validate($rule)
    {
        $r = (array) $rule;
        $e = array();

        if (trim((string) (isset($r['name']) ? $r['name'] : '')) === '') {
            $e[] = 'Rule name is required.';
        }
        if (trim((string) (isset($r['rule_code']) ? $r['rule_code'] : '')) === '') {
            $e[] = 'Rule code is required.';
        }

        $type = (string) (isset($r['calc_type']) ? $r['calc_type'] : '');
        if (!isset(self::calcTypes()[$type])) {
            $e[] = 'A valid calculation type is required.';
        }

        $base = (string) (isset($r['calc_base']) ? $r['calc_base'] : '');
        if (!isset(self::calcBases()[$base])) {
            $e[] = 'A valid calculation base is required.';
        }

        // type-specific requirements — no silent defaults
        switch ($type) {
            case 'percentage':
                if (!self::isPositiveNumber(isset($r['rate']) ? $r['rate'] : null)) {
                    $e[] = 'A percentage rule requires a rate greater than 0.';
                } elseif ((float) $r['rate'] > 100) {
                    $e[] = 'A percentage rate above 100% is almost certainly an error.';
                }
                break;

            case 'fixed':
                if (!self::isPositiveNumber(isset($r['amount']) ? $r['amount'] : null)) {
                    $e[] = 'A fixed rule requires an amount greater than 0.';
                }
                break;

            case 'hybrid':
                if (!self::isPositiveNumber(isset($r['amount']) ? $r['amount'] : null)
                    && !self::isPositiveNumber(isset($r['rate']) ? $r['rate'] : null)) {
                    $e[] = 'A hybrid rule requires a fixed amount, a percentage rate, or both.';
                }
                break;

            case 'slab':
            case 'tiered':
                $slabErrors = self::validateSlabs(isset($r['slabs']) ? $r['slabs'] : array());
                foreach ($slabErrors as $se) { $e[] = $se; }
                break;

            case 'accelerator':
                if (!self::isPositiveNumber(isset($r['rate']) ? $r['rate'] : null)) {
                    $e[] = 'An accelerator rule requires a base rate greater than 0.';
                }
                if (!self::isPositiveNumber(isset($r['accelerator_rate']) ? $r['accelerator_rate'] : null)) {
                    $e[] = 'An accelerator rule requires an accelerator rate/multiplier.';
                }
                if (!self::isNumber(isset($r['accelerator_threshold']) ? $r['accelerator_threshold'] : null)) {
                    $e[] = 'An accelerator rule requires an attainment threshold.';
                }
                break;
        }

        // thresholds and caps
        $min = isset($r['min_threshold']) ? $r['min_threshold'] : null;
        $max = isset($r['max_eligible_amount']) ? $r['max_eligible_amount'] : null;
        if ($min !== null && $min !== '' && !self::isNumber($min)) { $e[] = 'Minimum threshold must be a number.'; }
        if ($max !== null && $max !== '' && !self::isNumber($max)) { $e[] = 'Maximum eligible amount must be a number.'; }
        if (self::isNumber($min) && self::isNumber($max) && (float) $max > 0 && (float) $min > (float) $max) {
            $e[] = 'Minimum threshold cannot exceed the maximum eligible amount.';
        }

        // effective dating
        $from = isset($r['effective_from']) ? $r['effective_from'] : null;
        $to   = isset($r['effective_to'])   ? $r['effective_to']   : null;
        if ($from !== null && $from !== '' && strtotime((string) $from) === false) {
            $e[] = 'Effective-from is not a valid date.';
        }
        if ($to !== null && $to !== '' && strtotime((string) $to) === false) {
            $e[] = 'Effective-to is not a valid date.';
        }
        if ($from && $to && strtotime((string) $from) !== false && strtotime((string) $to) !== false
            && strtotime((string) $to) < strtotime((string) $from)) {
            $e[] = 'Effective-to cannot be earlier than effective-from.';
        }

        if (isset($r['priority']) && $r['priority'] !== '' && !self::isNumber($r['priority'])) {
            $e[] = 'Priority must be a number.';
        }

        return array('ok' => empty($e), 'errors' => $e);
    }

    /**
     * Slab bands must be ascending, non-overlapping, and carry a rate each. The
     * last band may be unbounded (upto null) to catch everything above.
     */
    public static function validateSlabs($slabs)
    {
        $e = array();
        $slabs = array_values((array) $slabs);

        if (!$slabs) {
            return array('At least one slab band is required.');
        }

        $prevUpto = 0.0;
        $sawUnbounded = false;
        foreach ($slabs as $i => $s) {
            $s = (array) $s;
            $n = $i + 1;

            if ($sawUnbounded) {
                $e[] = 'Band ' . $n . ' is unreachable: an earlier band is already unbounded.';
                break;
            }
            if (!self::isNumber(isset($s['rate']) ? $s['rate'] : null)) {
                $e[] = 'Band ' . $n . ' is missing a rate.';
                continue;
            }
            if ((float) $s['rate'] < 0) {
                $e[] = 'Band ' . $n . ' has a negative rate.';
            }

            $upto = isset($s['upto']) ? $s['upto'] : null;
            if ($upto === null || $upto === '') {
                $sawUnbounded = true;
                continue;
            }
            if (!self::isNumber($upto)) {
                $e[] = 'Band ' . $n . ' upper bound is not a number.';
                continue;
            }
            if ((float) $upto <= $prevUpto) {
                $e[] = 'Band ' . $n . ' upper bound (' . $upto . ') must be greater than the previous band (' . $prevUpto . ').';
            }
            $prevUpto = (float) $upto;
        }

        return $e;
    }

    private static function isNumber($v)
    {
        return $v !== null && $v !== '' && is_numeric($v);
    }

    private static function isPositiveNumber($v)
    {
        return self::isNumber($v) && (float) $v > 0;
    }

    /* ---------------- effective dating ---------------- */

    public static function isEffectiveOn($rule, $on = null)
    {
        $r = (array) $rule;
        $t = $on === null ? time() : (is_numeric($on) ? (int) $on : strtotime((string) $on));
        if ($t === false) { return false; }

        $from = isset($r['effective_from']) && $r['effective_from'] ? strtotime((string) $r['effective_from']) : null;
        $to   = isset($r['effective_to'])   && $r['effective_to']   ? strtotime((string) $r['effective_to'])   : null;

        if ($from !== null && $from !== false && $t < $from) { return false; }
        // effective_to is inclusive of that whole day
        if ($to !== null && $to !== false && $t > ($to + 86399)) { return false; }
        return true;
    }

    /* ---------------- matching ---------------- */

    /**
     * Does this rule apply to this context?
     *
     * A dimension left empty on the rule means "any" — it does not constrain.
     * A dimension set on the rule must equal the context value; if the context
     * does not carry that dimension at all, the rule does NOT match, because
     * applying a territory-specific rate to a sale of unknown territory would be
     * a guess.
     */
    public static function matches($rule, $context)
    {
        $r = (array) $rule;
        $c = (array) $context;

        foreach (self::matchDimensions() as $dim) {
            $want = isset($r[$dim]) ? $r[$dim] : null;
            if ($want === null || $want === '' || $want === 0 || $want === '0') { continue; } // unconstrained

            if (!array_key_exists($dim, $c) || $c[$dim] === null || $c[$dim] === '') {
                return false; // rule is specific, context is silent -> refuse to guess
            }
            if (strcasecmp(trim((string) $want), trim((string) $c[$dim])) !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * How specific is this rule? Higher wins ties. A staff-specific override
     * should always beat a territory rule, which should beat a global one.
     */
    public static function specificity($rule)
    {
        $r = (array) $rule;
        $dims = self::matchDimensions();
        $n = count($dims);
        $score = 0;
        foreach ($dims as $i => $dim) {
            $v = isset($r[$dim]) ? $r[$dim] : null;
            if ($v === null || $v === '' || $v === 0 || $v === '0') { continue; }
            // earlier dimensions are more specific, so weight them higher
            $score += ($n - $i);
        }
        return $score;
    }

    /**
     * Order candidate rules deterministically: explicit priority first (lower
     * number = higher precedence), then specificity, then the newest rule, then
     * id. Fully determined, so the same inputs always select the same rule —
     * a commission run must never depend on row order.
     */
    public static function sortCandidates($rules)
    {
        $list = array();
        foreach ((array) $rules as $r) { $list[] = (array) $r; }

        usort($list, function ($a, $b) {
            $pa = isset($a['priority']) && $a['priority'] !== '' ? (float) $a['priority'] : 1000.0;
            $pb = isset($b['priority']) && $b['priority'] !== '' ? (float) $b['priority'] : 1000.0;
            if ($pa !== $pb) { return $pa < $pb ? -1 : 1; }

            $sa = Payplex_commission_rule::specificity($a);
            $sb = Payplex_commission_rule::specificity($b);
            if ($sa !== $sb) { return $sa > $sb ? -1 : 1; }

            $va = (int) (isset($a['version']) ? $a['version'] : 0);
            $vb = (int) (isset($b['version']) ? $b['version'] : 0);
            if ($va !== $vb) { return $va > $vb ? -1 : 1; }

            $ia = (int) (isset($a['id']) ? $a['id'] : 0);
            $ib = (int) (isset($b['id']) ? $b['id'] : 0);
            if ($ia !== $ib) { return $ia > $ib ? -1 : 1; }
            return 0;
        });

        return $list;
    }

    /**
     * Select the rule that governs a context, or CONFIGURATION_REQUIRED.
     *
     * @return array status => 'ok'|'configuration_required',
     *               rule   => the winning rule or null,
     *               reason => why nothing applied,
     *               candidates, conflicts
     */
    public static function resolve($rules, $context, $on = null, $isProduction = true)
    {
        $candidates = array();
        foreach ((array) $rules as $r) {
            $r = (array) $r;
            if (!self::isPayable($r, $isProduction, $on)) { continue; }
            if (!self::matches($r, $context))             { continue; }
            $candidates[] = $r;
        }

        if (!$candidates) {
            return array(
                'status' => self::CONFIGURATION_REQUIRED,
                'rule'   => null,
                'reason' => 'No approved, active commission rule applies to this transaction. '
                          . 'Commission cannot be calculated until one is configured and approved.',
                'candidates' => array(),
                'conflicts'  => array(),
            );
        }

        $sorted = self::sortCandidates($candidates);

        return array(
            'status'     => 'ok',
            'rule'       => $sorted[0],
            'reason'     => '',
            'candidates' => $sorted,
            'conflicts'  => self::conflicts($sorted),
        );
    }

    /**
     * Rules that are genuinely ambiguous: same priority AND same specificity.
     * Resolution still picks one deterministically, but finance should be told,
     * because it means two people wrote two rates for the same situation.
     */
    public static function conflicts($rules)
    {
        $buckets = array();
        foreach ((array) $rules as $r) {
            $r = (array) $r;
            $p = isset($r['priority']) && $r['priority'] !== '' ? (float) $r['priority'] : 1000.0;
            $key = $p . '|' . self::specificity($r);
            if (!isset($buckets[$key])) { $buckets[$key] = array(); }
            $buckets[$key][] = $r;
        }

        $out = array();
        foreach ($buckets as $key => $group) {
            if (count($group) < 2) { continue; }
            $ids = array();
            foreach ($group as $g) { $ids[] = (int) (isset($g['id']) ? $g['id'] : 0); }
            $out[] = array(
                'priority'    => (float) explode('|', $key)[0],
                'specificity' => (int) explode('|', $key)[1],
                'rule_ids'    => $ids,
                'message'     => 'Rules ' . implode(', ', $ids) . ' have equal priority and scope; '
                               . 'the highest version wins, but this is ambiguous and should be resolved.',
            );
        }
        return $out;
    }

    /**
     * Would activating $candidate overlap an existing active rule for the same
     * scope and dates? Checked BEFORE activation so ambiguity is prevented
     * rather than reported after commissions have been generated.
     */
    public static function overlapsExisting($candidate, $existingRules)
    {
        $c = (array) $candidate;
        $out = array();

        foreach ((array) $existingRules as $r) {
            $r = (array) $r;
            if ((int) (isset($r['id']) ? $r['id'] : 0) === (int) (isset($c['id']) ? $c['id'] : -1)) { continue; }
            if ((string) (isset($r['status']) ? $r['status'] : '') !== self::ACTIVE) { continue; }
            if (self::specificity($r) !== self::specificity($c)) { continue; }
            if (!self::sameScope($r, $c)) { continue; }
            if (!self::datesOverlap($r, $c)) { continue; }

            $out[] = array(
                'rule_id' => (int) (isset($r['id']) ? $r['id'] : 0),
                'name'    => isset($r['name']) ? $r['name'] : '',
                'message' => 'Overlaps active rule "' . (isset($r['name']) ? $r['name'] : '') . '" on the same scope and dates.',
            );
        }
        return $out;
    }

    private static function sameScope($a, $b)
    {
        foreach (self::matchDimensions() as $dim) {
            $va = isset($a[$dim]) && $a[$dim] !== '' ? strtolower(trim((string) $a[$dim])) : '';
            $vb = isset($b[$dim]) && $b[$dim] !== '' ? strtolower(trim((string) $b[$dim])) : '';
            if ($va !== $vb) { return false; }
        }
        return true;
    }

    private static function datesOverlap($a, $b)
    {
        $af = isset($a['effective_from']) && $a['effective_from'] ? strtotime((string) $a['effective_from']) : null;
        $at = isset($a['effective_to'])   && $a['effective_to']   ? strtotime((string) $a['effective_to'])   : null;
        $bf = isset($b['effective_from']) && $b['effective_from'] ? strtotime((string) $b['effective_from']) : null;
        $bt = isset($b['effective_to'])   && $b['effective_to']   ? strtotime((string) $b['effective_to'])   : null;

        // open-ended ranges: treat null as -inf / +inf
        if ($at !== null && $bf !== null && $at < $bf) { return false; }
        if ($bt !== null && $af !== null && $bt < $af) { return false; }
        return true;
    }

    /* ---------------- versioning ---------------- */

    /**
     * Editing an active or approved rule must not rewrite history, so the edit
     * becomes a new version rather than an in-place change. Returns the row for
     * the new version; the caller supersedes the old one.
     */
    public static function nextVersion($rule, $changes, $actorId = 0)
    {
        $r = (array) $rule;
        $new = array_merge($r, (array) $changes);

        unset($new['id']);
        $new['version']     = (int) (isset($r['version']) ? $r['version'] : 1) + 1;
        $new['supersedes']  = (int) (isset($r['id']) ? $r['id'] : 0);
        // a new version always re-enters the approval chain
        $new['status']      = self::DRAFT;
        $new['created_by']  = (int) $actorId;
        $new['approved_by'] = null;
        $new['approved_at'] = null;

        return $new;
    }

    /**
     * May this rule be edited in place? Only while it has never been approved.
     * Once approved, an edit must create a version, because a commission may
     * already have been calculated against it.
     */
    public static function isEditableInPlace($rule)
    {
        $r = (array) $rule;
        return in_array((string) (isset($r['status']) ? $r['status'] : ''), array(self::DRAFT, self::SUBMITTED), true);
    }

    public static function statusClass($status)
    {
        switch ((string) $status) {
            case self::ACTIVE:    return 'success';
            case self::APPROVED:  return 'info';
            case self::SUBMITTED: return 'warning';
            case self::PAUSED:    return 'warning';
            case self::EXPIRED:   return 'default';
            case self::ARCHIVED:  return 'default';
            default:              return 'default';
        }
    }

    /** Convert a stored rule row into the definition the calculator consumes. */
    public static function toCalcDefinition($rule)
    {
        $r = (array) $rule;
        $def = array('type' => (string) (isset($r['calc_type']) ? $r['calc_type'] : ''));

        if (isset($r['rate'])   && $r['rate']   !== '' && $r['rate']   !== null) { $def['rate']   = (float) $r['rate']; }
        if (isset($r['amount']) && $r['amount'] !== '' && $r['amount'] !== null) { $def['amount'] = (float) $r['amount']; }

        if (!empty($r['slabs'])) {
            $def['slabs'] = is_string($r['slabs']) ? json_decode($r['slabs'], true) : $r['slabs'];
        }

        if (self::isPositiveNumber(isset($r['accelerator_rate']) ? $r['accelerator_rate'] : null)) {
            $def['accelerator'] = array(
                'threshold_pct' => (float) (isset($r['accelerator_threshold']) ? $r['accelerator_threshold'] : 100),
                'multiplier'    => (float) $r['accelerator_rate'],
            );
        }

        if (self::isNumber(isset($r['min_threshold']) ? $r['min_threshold'] : null)) {
            $def['min_threshold'] = (float) $r['min_threshold'];
        }
        if (self::isPositiveNumber(isset($r['max_eligible_amount']) ? $r['max_eligible_amount'] : null)) {
            $def['max_eligible_base'] = (float) $r['max_eligible_amount'];
        }
        if (self::isPositiveNumber(isset($r['cap']) ? $r['cap'] : null)) {
            $def['cap'] = (float) $r['cap'];
        }

        return $def;
    }
}
