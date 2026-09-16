<?php
defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Employee expense claims — the thing core Perfex cannot represent.
 *
 * WHAT WAS MEASURED ON STAGING, 2026-09-11
 * ----------------------------------------
 * tblexpenses holds 0 rows and 0 categories, and its columns are clientid,
 * project_id, billable, invoiceid and addedfrom. It models "the company spent
 * money on a client". It has no claimant, no status, no approver and no
 * reimbursement: `addedfrom` is who TYPED the row, not who is owed money. An
 * employee expense claim cannot be put in it, which is why this exists.
 *
 * The payout half is NOT rebuilt here. payplex_commission already contains a
 * complete payout engine — batches, items, events, bank validation, masking,
 * TDS, export, settlement, failure, retry, reconciliation — that has never run
 * once because nobody holds the `payout` capability. A claim that reaches
 * `approved` becomes a payout item in that engine.
 *
 * STATE NAMES SAY WHERE A CLAIM SITS, NOT WHAT LAST HAPPENED TO IT
 * ---------------------------------------------------------------
 * Every ambiguity this project has found came from a name that described a
 * different thing from the control it sat next to. So each state below carries
 * the label that is shown on screen, and the label names WHO HOLDS IT:
 *
 *   submitted       "Awaiting manager"
 *   manager_review  "Manager approved — awaiting finance"
 *   finance_review  "Finance checked — awaiting approval"
 *   approved        "Approved for payout"
 *
 * MAKER-CHECKER, STATED ONCE
 * --------------------------
 * Four distinct people, and the claimant is none of them. The claimant may not
 * review, approve or pay their own claim at any stage, and `is_admin` does not
 * change that — the precedent was set in Module 7, where the administrator was
 * refused verification of their own document and the refusal was proven against
 * a live session rather than hidden behind a missing button.
 */
class Workforce_expense
{
    /* ---------------- states ---------------- */
    const DRAFT             = 'draft';
    const SUBMITTED         = 'submitted';
    const MANAGER_REVIEW    = 'manager_review';
    const FINANCE_REVIEW    = 'finance_review';
    const APPROVED          = 'approved';
    const REJECTED          = 'rejected';
    const PAYOUT_PROCESSING = 'payout_processing';
    const PAID              = 'paid';
    const FAILED            = 'failed';
    const REVERSED          = 'reversed';
    const CANCELLED         = 'cancelled';

    public static function states()
    {
        return array(
            self::DRAFT             => 'Draft',
            self::SUBMITTED         => 'Awaiting manager',
            self::MANAGER_REVIEW    => 'Manager approved — awaiting finance',
            self::FINANCE_REVIEW    => 'Finance checked — awaiting approval',
            self::APPROVED          => 'Approved for payout',
            self::REJECTED          => 'Rejected',
            self::PAYOUT_PROCESSING => 'In a payout batch',
            self::PAID              => 'Paid',
            self::FAILED            => 'Payment failed',
            self::REVERSED          => 'Reversed',
            self::CANCELLED         => 'Withdrawn by the claimant',
        );
    }

    public static function isState($s) { return array_key_exists((string) $s, self::states()); }

    public static function label($s)
    {
        $m = self::states();
        return isset($m[$s]) ? $m[$s] : (string) $s;
    }

    /** Nothing moves out of these. `failed` is deliberately NOT terminal. */
    public static function terminal()
    {
        return array(self::REJECTED, self::REVERSED, self::CANCELLED);
    }

    /**
     * from => list of allowed to-states.
     *
     * A claim can be sent back to draft from any of the three checking stages:
     * "rejected" and "needs more information" are different answers, and a
     * system that offers only rejection turns every query into a refusal.
     */
    public static function transitions()
    {
        return array(
            self::DRAFT             => array(self::SUBMITTED, self::CANCELLED),
            self::SUBMITTED         => array(self::MANAGER_REVIEW, self::DRAFT, self::REJECTED, self::CANCELLED),
            self::MANAGER_REVIEW    => array(self::FINANCE_REVIEW, self::DRAFT, self::REJECTED),
            self::FINANCE_REVIEW    => array(self::APPROVED, self::DRAFT, self::REJECTED),
            self::APPROVED          => array(self::PAYOUT_PROCESSING),
            self::PAYOUT_PROCESSING => array(self::PAID, self::FAILED),
            self::FAILED            => array(self::PAYOUT_PROCESSING),
            self::PAID              => array(self::REVERSED),
            self::REJECTED          => array(),
            self::REVERSED          => array(),
            self::CANCELLED         => array(),
        );
    }

    public static function canTransition($from, $to)
    {
        $t = self::transitions();
        return isset($t[$from]) && in_array((string) $to, $t[$from], true);
    }

    /**
     * The capability each destination needs.
     *
     * Separate names, because granting one must not silently grant another.
     * The lesson is already in this codebase: `reject` was split out of
     * `review` in payplex_commission precisely because authority to reject had
     * been carrying authority to re-open a rejection with it.
     */
    /**
     * Capability names are FEATURE-QUALIFIED, and that is not decoration.
     *
     * My first version returned bare names, and the CI capability scanner
     * rejected the build: it read `payout` as a capability of payplex_staff,
     * which does not register one, and reported six gates that would be shut
     * for every non-administrator forever while administrators sailed through
     * on is_admin() and nobody noticed. The scanner was right and I was wrong.
     *
     * A bare name genuinely is ambiguous here — payplex_staff and
     * payplex_commission both register `view`, and the payout half of this
     * lifecycle is executed by the commission module's engine, not by this one.
     * "feature:capability" says which gate is meant, and `staff_can()` is given
     * both halves rather than being left to guess.
     */
    public static function stateCapabilities()
    {
        return array(
            self::SUBMITTED         => 'payplex_staff:expense_submit',
            self::CANCELLED         => 'payplex_staff:expense_submit',
            self::MANAGER_REVIEW    => 'payplex_staff:expense_review_manager',
            self::FINANCE_REVIEW    => 'payplex_staff:expense_review_finance',
            self::APPROVED          => 'payplex_staff:expense_approve',
            self::REJECTED          => null,   // whoever holds the current stage — resolved below
            self::DRAFT             => null,   // same: sending back belongs to the stage holder
            self::PAYOUT_PROCESSING => 'payplex_commission:payout',
            self::PAID              => 'payplex_commission:payout',
            self::FAILED            => 'payplex_commission:payout',
            self::REVERSED          => 'payplex_commission:payout_reverse',
        );
    }

    /** Split a qualified name for staff_can($capability, $feature, $staffId). */
    public static function splitCapability($qualified)
    {
        $q = (string) $qualified;
        $i = strpos($q, ':');
        if ($i === false) { return array('feature' => 'payplex_staff', 'capability' => $q); }
        return array('feature' => substr($q, 0, $i), 'capability' => substr($q, $i + 1));
    }

    /** Every capability this lifecycle can ever ask for, grouped by feature. */
    public static function allCapabilities()
    {
        $out = array();
        $names = array_values(array_filter(self::stateCapabilities()));
        $names = array_merge($names, array('payplex_staff:expense_review_manager',
            'payplex_staff:expense_review_finance', 'payplex_staff:expense_approve',
            'payplex_commission:payout_retry', 'payplex_staff:expense_view_own',
            'payplex_staff:expense_view_all', 'payplex_staff:expense_bill_view'));
        foreach (array_unique($names) as $q) {
            $s = self::splitCapability($q);
            $out[$s['feature']][$s['capability']] = true;
        }
        foreach ($out as $f => $caps) { $out[$f] = array_keys($caps); sort($out[$f]); }
        return $out;
    }

    /**
     * Rejecting and returning belong to whoever holds the claim right now, so
     * the capability depends on the state it is IN, not the state it moves to.
     */
    public static function capabilityFor($from, $to)
    {
        if ($to === self::REJECTED || ($to === self::DRAFT && $from !== self::DRAFT)) {
            $byStage = array(
                self::SUBMITTED      => 'payplex_staff:expense_review_manager',
                self::MANAGER_REVIEW => 'payplex_staff:expense_review_finance',
                self::FINANCE_REVIEW => 'payplex_staff:expense_approve',
            );
            return isset($byStage[$from]) ? $byStage[$from] : null;
        }
        if ($from === self::FAILED && $to === self::PAYOUT_PROCESSING) { return 'payplex_commission:payout_retry'; }
        $m = self::stateCapabilities();
        return isset($m[$to]) ? $m[$to] : null;
    }

    /** Moves that must be explained. An unexplained refusal teaches nobody anything. */
    public static function needsReason($from, $to)
    {
        if ($to === self::REJECTED)  { return true; }
        if ($to === self::REVERSED)  { return true; }
        if ($to === self::DRAFT && $from !== self::DRAFT) { return true; }
        return false;
    }

    /* ---------------- the gate ---------------- */

    /**
     * May $actorId move this claim to $to?
     *
     * @param array $claim   claimant_id, state, manager_reviewer_id,
     *                       finance_reviewer_id, approver_id, amount
     * @param string $to
     * @param int   $actorId
     * @param array $heldCapabilities  capability names the actor actually holds.
     *                       Pass NULL only when they could not be determined —
     *                       which is answered `unknown_capabilities`, never
     *                       waved through. A gate that defaults to permissive
     *                       when it cannot tell is the single defect this
     *                       project has found most often.
     * @param string $reason
     * @return array allowed, code, reason, required_capability, capability_checked
     */
    public static function canAct($claim, $to, $actorId, $heldCapabilities = null, $reason = '')
    {
        $c       = (array) $claim;
        $actorId = (int) $actorId;
        $from    = (string) (isset($c['state']) ? $c['state'] : '');
        $claimant= (int) (isset($c['claimant_id']) ? $c['claimant_id'] : 0);
        $checked = is_array($heldCapabilities);

        $no = function ($code, $why, $extra = array()) use ($checked) {
            return array_merge(array('allowed' => false, 'code' => $code, 'reason' => $why,
                                     'capability_checked' => $checked), $extra);
        };

        if ($actorId <= 0) {
            return $no('no_actor', 'An acting user must be identified.');
        }
        if (!self::isState($to)) {
            return $no('unknown_state', '"' . $to . '" is not a state a claim can be in.');
        }
        if (!self::canTransition($from, $to)) {
            return $no('bad_transition', 'A claim cannot move from ' . self::label($from ?: '(none)')
                                       . ' to ' . self::label($to) . '.');
        }

        /* --- the claimant is never a checker on their own claim --- */
        $checkerStates = array(self::MANAGER_REVIEW, self::FINANCE_REVIEW, self::APPROVED,
                               self::REJECTED, self::PAYOUT_PROCESSING, self::PAID,
                               self::FAILED, self::REVERSED);
        if ($claimant === $actorId && in_array($to, $checkerStates, true)) {
            return $no('self_approval',
                'You raised this claim, so you cannot review, approve, pay or reverse it. '
              . 'This applies to administrators too.');
        }
        /* --- and only the claimant may submit or withdraw it --- */
        if (in_array($to, array(self::SUBMITTED, self::CANCELLED), true) && $claimant !== $actorId
            && !self::actorIsDelegate($c, $actorId)) {
            return $no('not_the_claimant',
                'Only the person the claim belongs to can submit or withdraw it.');
        }

        /* --- a checker may occupy exactly one seat --- */
        $manager = (int) (isset($c['manager_reviewer_id']) ? $c['manager_reviewer_id'] : 0);
        $finance = (int) (isset($c['finance_reviewer_id']) ? $c['finance_reviewer_id'] : 0);
        if ($to === self::FINANCE_REVIEW && $manager === $actorId && $manager > 0) {
            return $no('same_checker_twice',
                'You already reviewed this claim as the manager. Finance review is a second '
              . 'pair of eyes, and one person filling both seats is not a review.');
        }
        if ($to === self::APPROVED && (($manager === $actorId && $manager > 0)
                                    || ($finance === $actorId && $finance > 0))) {
            return $no('same_checker_twice',
                'You already reviewed this claim earlier in the chain, so you cannot also be '
              . 'the person who approves it.');
        }

        if (self::needsReason($from, $to) && trim((string) $reason) === '') {
            return $no('reason_required', 'This decision must be explained.');
        }

        /* --- capability, last, so the more specific refusals are reported first --- */
        $need = self::capabilityFor($from, $to);
        if (!$checked) {
            return $no('unknown_capabilities',
                'The acting user\'s permissions could not be read, so this was refused. '
              . 'A gate that cannot tell must not assume yes.',
                array('required_capability' => $need));
        }
        if ($need !== null && !in_array($need, $heldCapabilities, true)) {
            return $no('missing_capability', 'This needs the "' . $need . '" permission.',
                array('required_capability' => $need));
        }

        return array('allowed' => true, 'code' => 'ok', 'reason' => '',
                     'required_capability' => $need, 'capability_checked' => true);
    }

    /**
     * Somebody may submit on another person's behalf only if the claim records
     * them as a delegate. Nothing sets this yet; it exists so the rule is one
     * place rather than an `isset()` somewhere in a controller later.
     */
    public static function actorIsDelegate($claim, $actorId)
    {
        $c = (array) $claim;
        $d = isset($c['delegate_id']) ? (int) $c['delegate_id'] : 0;
        return $d > 0 && $d === (int) $actorId;
    }

    /* ---------------- what a claim may contain ---------------- */

    public static function categories()
    {
        return array(
            'travel'        => 'Travel',
            'accommodation' => 'Accommodation',
            'meals'         => 'Meals',
            'fuel'          => 'Fuel / mileage',
            'telecom'       => 'Telephone / internet',
            'stationery'    => 'Stationery and supplies',
            'client_meeting'=> 'Client meeting',
            'training'      => 'Training and certification',
            'medical'       => 'Medical',
            'other'         => 'Other',
        );
    }

    public static function isCategory($c) { return array_key_exists((string) $c, self::categories()); }

    /**
     * What kind of money this is. Drives TDS and sign in the payout engine,
     * whose tdsState() already refuses to pay anything it has not been told
     * how to treat.
     *
     *   tds: 'not_applicable' | 'per_profile'
     *   sign: +1 money out to the person, -1 money recovered from them
     */
    public static function payableKinds()
    {
        return array(
            'reimbursement'     => array('label' => 'Reimbursement',      'tds' => 'not_applicable', 'sign' => 1),
            'commission'        => array('label' => 'Commission',         'tds' => 'per_profile',    'sign' => 1),
            'incentive'         => array('label' => 'Incentive',          'tds' => 'per_profile',    'sign' => 1),
            'salary_adjustment' => array('label' => 'Salary adjustment',  'tds' => 'per_profile',    'sign' => 1),
            'recovery'          => array('label' => 'Recovery / clawback','tds' => 'not_applicable', 'sign' => -1),
        );
    }

    public static function isPayableKind($k) { return array_key_exists((string) $k, self::payableKinds()); }

    /**
     * Repaying somebody for money they already spent is not income, so TDS does
     * not apply to a reimbursement. It does apply to commission, incentive and
     * salary adjustments — at whatever rate is CONFIGURED. No rate is invented
     * here, and 'per_profile' with nothing configured blocks the payout rather
     * than guessing zero.
     */
    public static function tdsTreatment($kind)
    {
        $k = self::payableKinds();
        return isset($k[$kind]) ? $k[$kind]['tds'] : 'per_profile';
    }

    public static function sign($kind)
    {
        $k = self::payableKinds();
        return isset($k[$kind]) ? (int) $k[$kind]['sign'] : 1;
    }

    /**
     * Validate a claim before it is stored.
     *
     * @param array $post
     * @param string $today
     * @return array ok, errors (field => message)
     */
    public static function validate($post, $today = null)
    {
        $today = $today ?: date('Y-m-d');
        $p = (array) $post;
        $e = array();

        if (!self::isCategory(isset($p['category']) ? $p['category'] : '')) {
            $e['category'] = 'Choose a category this system knows.';
        }
        if (!self::isPayableKind(isset($p['payable_kind']) ? $p['payable_kind'] : '')) {
            $e['payable_kind'] = 'Choose what kind of payment this is.';
        }

        $purpose = trim((string) (isset($p['purpose']) ? $p['purpose'] : ''));
        if ($purpose === '') {
            $e['purpose'] = 'Say what the money was spent on. "Expenses" is not a purpose.';
        } elseif (mb_strlen($purpose) > 500) {
            $e['purpose'] = 'Keep the purpose under 500 characters.';
        }

        $amount = isset($p['amount']) ? $p['amount'] : null;
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            $e['amount'] = 'The amount must be a number.';
        } elseif ((float) $amount <= 0) {
            $e['amount'] = 'The amount must be greater than zero.';
        } elseif ((float) $amount > 10000000) {
            $e['amount'] = 'That amount is beyond anything this system should accept without a '
                         . 'separate decision. Raise it through finance directly.';
        }

        $tax = isset($p['tax_amount']) ? $p['tax_amount'] : 0;
        if ($tax !== '' && $tax !== null && !is_numeric($tax)) {
            $e['tax_amount'] = 'Tax must be a number.';
        } elseif (is_numeric($tax) && is_numeric($amount) && (float) $tax > (float) $amount) {
            $e['tax_amount'] = 'Tax cannot be larger than the amount.';
        }

        $d = trim((string) (isset($p['expense_date']) ? $p['expense_date'] : ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $e['expense_date'] = 'Give the date the money was spent, as YYYY-MM-DD.';
        } elseif (strtotime($d) > strtotime($today)) {
            $e['expense_date'] = 'The expense date is in the future. Money cannot have been spent yet.';
        } elseif (strtotime($d) < strtotime('-365 days', strtotime($today))) {
            $e['expense_date'] = 'This is more than a year old. It needs a decision outside this '
                               . 'screen rather than a routine claim.';
        }

        return array('ok' => empty($e), 'errors' => $e);
    }

    /* ---------------- idempotency ---------------- */

    /**
     * The key that makes a duplicate claim impossible.
     *
     * Measured on staging: the payout engine's only duplicate guard is a
     * `where_not_in` inside payableStatements() — a query, not a constraint, so
     * two concurrent requests both pass it. This key is stored UNIQUE, so the
     * second write fails at the database whatever the UI or the race does.
     *
     * The bill digest is part of the key: the same amount on the same day for
     * the same category is a real and legitimate thing (two taxis), and what
     * makes it a duplicate is that it is the SAME BILL.
     */
    public static function idempotencyKey($claimantId, $category, $amount, $expenseDate, $billDigest = '')
    {
        return sha1(implode('|', array(
            (int) $claimantId,
            strtolower(trim((string) $category)),
            number_format((float) $amount, 2, '.', ''),
            trim((string) $expenseDate),
            strtolower(trim((string) $billDigest)),
        )));
    }

    /**
     * Is this claim a duplicate of one already on file?
     *
     * @param array $existing rows with idempotency_key and state
     * @return array duplicate, of_id, reason
     */
    public static function duplicateOf($key, array $existing)
    {
        foreach ($existing as $row) {
            $r = (array) $row;
            if ((string) (isset($r['idempotency_key']) ? $r['idempotency_key'] : '') !== (string) $key) { continue; }
            /* A withdrawn or rejected claim does not block a corrected resubmission —
               that is the whole point of sending something back. */
            if (in_array((string) $r['state'], array(self::CANCELLED, self::REJECTED), true)) { continue; }
            return array('duplicate' => true, 'of_id' => (int) $r['id'],
                'reason' => 'Claim #' . (int) $r['id'] . ' already covers this bill, for the same '
                          . 'amount on the same date. It is ' . self::label($r['state']) . '.');
        }
        return array('duplicate' => false, 'of_id' => 0, 'reason' => '');
    }

    /* ---------------- what a screen needs ---------------- */

    /**
     * How long has this been waiting, and with whom?
     *
     * A pending-approval list sorted by date tells you nothing about who is
     * holding things up. This names the stage holder as well as the age.
     */
    public static function ageing($claim, $now = null)
    {
        $c    = (array) $claim;
        $now  = $now ?: date('Y-m-d H:i:s');
        $from = (string) (isset($c['state']) ? $c['state'] : '');
        $since= (string) (isset($c['state_since']) ? $c['state_since'] : (isset($c['created_at']) ? $c['created_at'] : ''));

        $waitingOn = array(
            self::SUBMITTED      => 'the manager',
            self::MANAGER_REVIEW => 'finance',
            self::FINANCE_REVIEW => 'the approver',
            self::APPROVED       => 'a payout batch',
            self::PAYOUT_PROCESSING => 'the bank',
            self::FAILED         => 'a retry',
            self::DRAFT          => 'the claimant',
        );

        if (!isset($waitingOn[$from])) {
            return array('waiting' => false, 'days' => 0, 'with' => '', 'bucket' => 'none');
        }
        $days = $since ? (int) floor((strtotime($now) - strtotime($since)) / 86400) : 0;
        if ($days < 0) { $days = 0; }

        if ($days >= 14)     { $bucket = 'over_14d'; }
        elseif ($days >= 7)  { $bucket = '7_14d'; }
        elseif ($days >= 3)  { $bucket = '3_7d'; }
        else                 { $bucket = 'under_3d'; }

        return array('waiting' => true, 'days' => $days, 'with' => $waitingOn[$from], 'bucket' => $bucket);
    }

    public static function ageingBuckets()
    {
        return array('under_3d' => 'Under 3 days', '3_7d' => '3–7 days',
                     '7_14d' => '7–14 days', 'over_14d' => 'Over 14 days');
    }

    /**
     * Which claims a viewer may see, given their scope.
     * Reuses the ladder from Workforce_access rather than inventing a second one.
     */
    public static function visibleToScope($claim, $viewerId, $canViewAll)
    {
        $c = (array) $claim;
        if ($canViewAll) { return true; }
        return (int) (isset($c['claimant_id']) ? $c['claimant_id'] : 0) === (int) $viewerId;
    }

    /**
     * May this actor see the bill attached to a claim?
     *
     * A bill is a receipt with a person's movements, card tail and sometimes an
     * address on it. Owning the claim is enough; anything else needs the
     * separate `expense_bill_view` capability, NOT merely the ability to
     * approve. Approving an amount and reading the underlying receipt are
     * different acts, and Module 7 established that the wider one gets its own
     * permission.
     */
    public static function canSeeBill($claim, $actorId, $hasBillView)
    {
        $c = (array) $claim;
        if ((int) (isset($c['claimant_id']) ? $c['claimant_id'] : 0) === (int) $actorId) {
            return array('allowed' => true, 'code' => 'own_claim');
        }
        if ($hasBillView) { return array('allowed' => true, 'code' => 'ok'); }
        return array('allowed' => false, 'code' => 'not_permitted',
            'reason' => 'Reading the bill needs the expense bill permission. Approving an amount '
                      . 'and reading the receipt behind it are different acts.');
    }

    /**
     * A claim may only enter a payout batch once, and only from `approved`.
     * The payout engine gets a second, independent constraint in the database;
     * this is the first.
     */
    public static function payoutEligibility($claim)
    {
        $c = (array) $claim;
        $state = (string) (isset($c['state']) ? $c['state'] : '');
        if ($state !== self::APPROVED) {
            return array('ok' => false, 'code' => 'not_approved',
                'reason' => 'Only an approved claim can be paid. This one is ' . self::label($state) . '.');
        }
        if ((int) (isset($c['payout_item_id']) ? $c['payout_item_id'] : 0) > 0) {
            return array('ok' => false, 'code' => 'already_in_batch',
                'reason' => 'This claim is already on payout item #'
                          . (int) $c['payout_item_id'] . '. Paying it again would pay it twice.');
        }
        return array('ok' => true, 'code' => 'ok', 'reason' => '');
    }

    /* ==================================================================== *
     * Export and dashboard  (requirement 12)
     * ==================================================================== */

    /**
     * Columns for a claims export.
     *
     * Deliberately NOT the bank file: that is the payout engine's export, it
     * carries account details, and it is produced from a batch. This one is the
     * management view — who claimed what, who decided it, how long it sat, and
     * where the money got to — and it carries no bank detail at all, because a
     * spreadsheet that leaves this building should not have any in it.
     */
    public static function exportColumns()
    {
        return array(
            'claim_ref'       => 'Claim',
            'claimant_id'     => 'Claimant id',
            'claimant'        => 'Claimant',
            'category'        => 'Category',
            'payable_kind'    => 'Kind',
            'expense_date'    => 'Spent on',
            'amount'          => 'Amount',
            'tax_amount'      => 'Tax',
            'currency'        => 'Currency',
            'state'           => 'State',
            'waiting_on'      => 'Waiting on',
            'days_waiting'    => 'Days waiting',
            'manager_id'      => 'Manager reviewer',
            'finance_id'      => 'Finance reviewer',
            'approver_id'     => 'Approver',
            'decided_reason'  => 'Last decision reason',
            'payout_item_id'  => 'Payout item',
            'paid_at'         => 'Paid at',
            'created_at'      => 'Raised at',
        );
    }

    public static function exportRow($claim, $name = '', $now = null)
    {
        $c = (array) $claim;
        $a = self::ageing($c, $now);
        $g = function ($k, $d = '') use ($c) { return isset($c[$k]) && $c[$k] !== null ? $c[$k] : $d; };

        return array(
            'claim_ref'      => (string) $g('claim_ref'),
            'claimant_id'    => (int) $g('claimant_id', 0),
            'claimant'       => (string) $name,
            'category'       => (string) (self::categories()[$g('category')] ?? $g('category')),
            'payable_kind'   => (string) (self::payableKinds()[$g('payable_kind')]['label'] ?? $g('payable_kind')),
            'expense_date'   => (string) $g('expense_date'),
            'amount'         => number_format((float) $g('amount', 0), 2, '.', ''),
            'tax_amount'     => number_format((float) $g('tax_amount', 0), 2, '.', ''),
            'currency'       => (string) $g('currency', 'INR'),
            'state'          => self::label((string) $g('state')),
            'waiting_on'     => $a['waiting'] ? $a['with'] : '',
            'days_waiting'   => $a['waiting'] ? (int) $a['days'] : '',
            'manager_id'     => (int) $g('manager_reviewer_id', 0) ?: '',
            'finance_id'     => (int) $g('finance_reviewer_id', 0) ?: '',
            'approver_id'    => (int) $g('approver_id', 0) ?: '',
            'decided_reason' => (string) $g('decided_reason'),
            'payout_item_id' => (int) $g('payout_item_id', 0) ?: '',
            'paid_at'        => (string) $g('paid_at'),
            'created_at'     => (string) $g('created_at'),
        );
    }

    /** No bank detail may reach this export. Asserted, not assumed. */
    public static function exportCarriesNoBankDetail()
    {
        $banned = array('account', 'ifsc', 'bank', 'iban', 'upi', 'masked');
        foreach (array_keys(self::exportColumns()) as $k) {
            foreach ($banned as $b) { if (strpos(strtolower($k), $b) !== false) { return false; } }
        }
        return true;
    }

    /** One cell, RFC4180, and never a formula. */
    public static function csvCell($v)
    {
        $v = (string) $v;
        /*
         * A leading =, +, - or @ makes a spreadsheet treat the cell as a
         * formula. An export of claim text is not a place to run somebody's
         * code, so those are prefixed rather than trusted.
         */
        if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) { $v = "'" . $v; }
        if (preg_match('/[",\n\r]/', $v)) { $v = '"' . str_replace('"', '""', $v) . '"'; }
        return $v;
    }

    public static function toCsv(array $rows)
    {
        $cols = self::exportColumns();
        $out  = implode(',', array_map(array(__CLASS__, 'csvCell'), array_values($cols))) . "\r\n";
        foreach ($rows as $r) {
            $line = array();
            foreach (array_keys($cols) as $k) { $line[] = self::csvCell(isset($r[$k]) ? $r[$k] : ''); }
            $out .= implode(',', $line) . "\r\n";
        }
        return $out;
    }

    /**
     * The dashboard figures.
     *
     * `needs_someone` is the only number that matters on a Monday: how many
     * claims are sitting waiting for a human. Totals by state are context.
     */
    public static function dashboard(array $claims, $now = null)
    {
        $byState = array(); $buckets = array(); $needs = 0; $value = 0.0; $paid = 0.0; $oldest = 0;
        foreach (array_keys(self::states()) as $st) { $byState[$st] = 0; }
        foreach (array_keys(self::ageingBuckets()) as $b) { $buckets[$b] = 0; }

        foreach ($claims as $c) {
            $st = isset($c['state']) ? (string) $c['state'] : '';
            if (isset($byState[$st])) { $byState[$st]++; }
            $a = self::ageing($c, $now);
            if ($a['waiting'] && $st !== self::DRAFT) {
                $needs++;
                $buckets[$a['bucket']]++;
                $value += (float) (isset($c['amount']) ? $c['amount'] : 0);
                if ((int) $a['days'] > $oldest) { $oldest = (int) $a['days']; }
            }
            if ($st === self::PAID) { $paid += (float) (isset($c['amount']) ? $c['amount'] : 0); }
        }
        return array(
            'total'          => count($claims),
            'by_state'       => $byState,
            'needs_someone'  => $needs,
            'buckets'        => $buckets,
            'value_waiting'  => round($value, 2),
            'value_paid'     => round($paid, 2),
            'oldest_days'    => $oldest,
            /* A draft is the claimant's to finish; it is not a queue anybody
               else is holding up, so it is counted but never chased. */
            'drafts_excluded'=> $byState[self::DRAFT],
        );
    }

    /**
     * Page through an entire result set and emit one row at a time.
     *
     * The export used to borrow the screen's 500-row cap, so a file of more
     * than 500 claims stopped there and said nothing while its totals still
     * looked plausible. Reporting the truncation was not a fix: the file has to
     * contain every record that matches the filter.
     *
     * KEYSET, NOT OFFSET. Paging with LIMIT/OFFSET over a table that is still
     * being written to shifts the window underneath the reader: a claim raised
     * mid-export pushes a row across a page boundary and it is emitted twice,
     * or a delete pulls one back and it is never emitted at all. Neither shows
     * up as an error - you get a file that is quietly wrong, which is the thing
     * this whole exercise is about. Walking descending ids with `id < last`
     * cannot do either, and it uses the primary key rather than making the
     * database count past a growing offset.
     *
     * The page fetcher is injected so the paging can be tested against a real
     * result set without a database, including well past the old cap.
     *
     * @param callable $fetchPage function($afterId, $limit): array of claims,
     *                            ordered by id DESC, ids strictly below $afterId
     * @param callable $emit      function(array $claim): void
     * @return array totals computed across everything actually emitted
     */
    public static function streamExport($fetchPage, $emit, $pageSize = 500, $maxPages = 100000)
    {
        $pageSize = max(1, (int) $pageSize);
        $after    = null;                 /* null means "from the top" */
        $prevId   = null;                 /* last id actually emitted */
        $n = 0; $pages = 0;
        $sumAmount = 0.0; $sumTax = 0.0; $sumPaid = 0.0; $sumReversed = 0.0;
        $byState = array();
        $firstRef = null; $lastRef = null; $firstId = null; $lastId = null;

        while ($pages < $maxPages) {
            $rows = (array) call_user_func($fetchPage, $after, $pageSize);
            $pages++;
            if (!$rows) { break; }

            foreach ($rows as $c) {
                $c  = (array) $c;
                $id = isset($c['id']) ? (int) $c['id'] : 0;

                /*
                 * ONE invariant: each id emitted must be strictly below the one
                 * before it. That is what "paging forwards through a descending
                 * list" means, and breaking it is the only way this can emit a
                 * record twice or run for ever.
                 *
                 * It began as two checks - a row-level one keyed on the cursor
                 * and a page-level one comparing page heads. Mutation testing
                 * showed why that was wrong: each disabled the other's failures,
                 * so both could be deleted one at a time with every test still
                 * green, and the page-level one could not be made to fire by any
                 * fetcher at all - it only existed to catch a bug in this
                 * function's own cursor. A control no test can trigger is the
                 * exact thing this project keeps finding in other people's code.
                 *
                 * Tracking the last EMITTED id instead of the cursor covers both:
                 * a cursor that never advances, and a fetcher with an off-by-one
                 * that returns `id <= after` so the boundary row repeats on every
                 * page while the page head still moves.
                 */
                if ($prevId !== null && $id >= $prevId) {
                    return array('ok' => false, 'rows' => $n, 'pages' => $pages,
                        'error' => 'Record id ' . $id . ' came after id ' . $prevId . ', which is not '
                                 . 'a step forward. Paging is not advancing, so the export was stopped '
                                 . 'rather than repeat a record or run without end.');
                }
                $prevId = $id;

                $st = isset($c['state']) ? (string) $c['state'] : '';
                $a  = (float) (isset($c['amount']) ? $c['amount'] : 0);
                $sumAmount += $a;
                $sumTax    += (float) (isset($c['tax_amount']) ? $c['tax_amount'] : 0);
                if ($st === self::PAID)     { $sumPaid     += $a; }
                if ($st === self::REVERSED) { $sumReversed += $a; }
                if (!isset($byState[$st]))  { $byState[$st] = 0; }
                $byState[$st]++;

                if ($firstId === null) { $firstId = $id; $firstRef = isset($c['claim_ref']) ? $c['claim_ref'] : null; }
                $lastId  = $id;
                $lastRef = isset($c['claim_ref']) ? $c['claim_ref'] : null;
                $after   = $id;
                $n++;

                call_user_func($emit, $c);
            }

            /* a short page is the end of the data; a full one might not be */
            if (count($rows) < $pageSize) { break; }
        }

        return array(
            'ok'             => true,
            'rows'           => $n,
            'pages'          => $pages,
            'page_size'      => $pageSize,
            'sum_amount'     => round($sumAmount, 2),
            'sum_tax'        => round($sumTax, 2),
            /* paid excludes reversed money, for the same reason the dashboard does */
            'sum_paid'       => round($sumPaid, 2),
            'sum_reversed'   => round($sumReversed, 2),
            'by_state'       => $byState,
            'first_id'       => $firstId,
            'last_id'        => $lastId,
            'first_claim_ref'=> $firstRef,
            'last_claim_ref' => $lastRef,
            'complete'       => true,
        );
    }
}
