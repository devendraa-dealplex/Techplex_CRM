<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_commission_payout — payout batches, exports and reconciliation (§2.4).
 *
 * Pure and framework-independent.
 *
 * THIS CLASS NEVER PAYS ANYBODY. There is no bank API here, no payment call, and
 * deliberately no simulation of one. The spec is explicit: with no bank
 * integration, payout is a maker-checker EXPORT workflow, and a simulated
 * payment is worse than none, because it produces a record that says money moved
 * when it did not. "Paid" is only ever recorded by a human entering a real bank
 * reference after the bank actually settled.
 *
 * Three further rules, each protecting something specific:
 *
 *  1. FULL ACCOUNT NUMBERS NEVER LEAVE THIS SYSTEM through an ordinary screen or
 *     export. Everything here works with a masked account plus a verified account
 *     reference. Releasing a bank-ready file containing full account numbers is a
 *     separate, explicitly approved action, and is not implemented here.
 *
 *  2. NO INVENTED TDS RATE. Tax deducted at source is a statutory and business
 *     decision. A batch whose staff have no configured TDS treatment reports
 *     "TDS not configured" rather than quietly deducting zero, because a silent
 *     zero looks exactly like a legitimate nil deduction.
 *
 *  3. A FAILED PAYMENT IS NOT A PAID ONE. Failures are recorded against the item
 *     with a reason, and a retry produces a NEW attempt rather than overwriting
 *     the failed one, so the history of what was tried survives.
 */
class Payplex_commission_payout
{
    /* ---------------- batch lifecycle ---------------- */

    const DRAFT      = 'draft';
    const SUBMITTED  = 'submitted';
    const APPROVED   = 'approved';
    const EXPORTED   = 'exported';
    const SETTLED    = 'settled';
    const CANCELLED  = 'cancelled';

    /*
     * Every state names the capability that may reach it. The controller
     * previously gated a list of named targets and let every other state
     * through on the weakest capability — so a state nobody listed inherited
     * the weak gate. Unknown states are refused here rather than allowed.
     */
    public static function stateCapabilities()
    {
        return array(
            self::DRAFT     => 'payout',
            self::SUBMITTED => 'payout',
            self::APPROVED  => 'payout_approve',
            self::EXPORTED  => 'payout_approve',
            self::SETTLED   => 'pay',
            self::CANCELLED => 'payout',
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
            self::SUBMITTED => 'Submitted for approval',
            self::APPROVED  => 'Approved for payment',
            self::EXPORTED  => 'Exported to bank / payroll',
            self::SETTLED   => 'Settled',
            self::CANCELLED => 'Cancelled',
        );
    }

    public static function transitions()
    {
        return array(
            self::DRAFT     => array(self::SUBMITTED, self::CANCELLED),
            self::SUBMITTED => array(self::APPROVED, self::DRAFT, self::CANCELLED),
            self::APPROVED  => array(self::EXPORTED, self::DRAFT, self::CANCELLED),
            self::EXPORTED  => array(self::SETTLED),
            self::SETTLED   => array(),
            self::CANCELLED => array(),
        );
    }

    public static function canTransition($from, $to)
    {
        $t = self::transitions();
        if (!isset($t[(string) $from])) { return false; }
        return in_array((string) $to, $t[(string) $from], true);
    }

    /**
     * Gate for moving a payout batch. Same separation-of-duties as everywhere
     * else, plus a hard block on approving or exporting a batch that contains
     * unresolved problems.
     */
    public static function canAct($batch, $to, $actorId, $items = array(), $reason = '')
    {
        $b = (array) $batch;
        $actorId = (int) $actorId;
        $from = (string) (isset($b['status']) ? $b['status'] : '');

        if ($actorId <= 0) {
            return array('allowed' => false, 'code' => 'no_actor', 'reason' => 'An acting user must be identified.');
        }
        if (!self::canTransition($from, $to)) {
            return array('allowed' => false, 'code' => 'bad_transition',
                'reason' => 'A payout batch cannot move from ' . ($from ?: '(none)') . ' to ' . $to . '.');
        }
        if (in_array($to, array(self::DRAFT, self::CANCELLED), true) && trim((string) $reason) === ''
            && $from === self::SUBMITTED) {
            return array('allowed' => false, 'code' => 'reason_required',
                'reason' => 'Sending a batch back or cancelling it must be explained.');
        }

        if (in_array($to, array(self::APPROVED, self::EXPORTED), true)) {

            if ((int) (isset($b['created_by']) ? $b['created_by'] : 0) === $actorId) {
                return array('allowed' => false, 'code' => 'maker_is_approver',
                    'reason' => 'The person who prepared this payout batch cannot approve or export it.');
            }

            foreach ((array) $items as $it) {
                if ((int) (isset($it['staff_id']) ? $it['staff_id'] : 0) === $actorId) {
                    return array('allowed' => false, 'code' => 'self_beneficiary',
                        'reason' => 'You are paid in this batch, so you cannot approve or export it.');
                }
            }

            $blocking = self::blockingIssues($items);
            if ($blocking) {
                return array('allowed' => false, 'code' => 'items_blocked',
                    'reason' => count($blocking) . ' item(s) cannot be paid: ' . implode('; ', array_slice($blocking, 0, 3))
                              . (count($blocking) > 3 ? ' …' : ''));
            }
        }

        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    /** Problems that must be fixed before a batch can be approved or exported. */
    public static function blockingIssues($items)
    {
        $out = array();
        foreach ((array) $items as $it) {
            $it = (array) $it;
            $who = 'staff #' . (int) (isset($it['staff_id']) ? $it['staff_id'] : 0);

            $v = self::validateBankDetails($it);
            if (!$v['ok']) { $out[] = $who . ': ' . $v['reason']; continue; }

            if (!isset($it['net_payable']) || !is_numeric($it['net_payable'])) {
                $out[] = $who . ': net payable is not a number.'; continue;
            }
            if ((float) $it['net_payable'] <= 0) {
                $out[] = $who . ': net payable is zero or negative.'; continue;
            }
            if (self::tdsState($it) === 'not_configured') {
                $out[] = $who . ': TDS treatment is not configured.'; continue;
            }
        }
        return $out;
    }

    /* ---------------- bank details ---------------- */

    /**
     * Validate a beneficiary's bank details.
     *
     * The account number is expected already masked here; what must be present
     * and verified is the ACCOUNT REFERENCE — the id of a bank record someone has
     * actually verified. Paying against unverified details is how money reaches
     * the wrong person and does not come back.
     */
    public static function validateBankDetails($item)
    {
        $it = (array) $item;

        if (empty($it['bank_verified'])) {
            return array('ok' => false, 'code' => 'unverified',
                'reason' => 'bank details are not verified.');
        }
        if (trim((string) (isset($it['account_ref']) ? $it['account_ref'] : '')) === '') {
            return array('ok' => false, 'code' => 'no_account_ref',
                'reason' => 'no verified account reference on file.');
        }
        $ifsc = strtoupper(trim((string) (isset($it['ifsc']) ? $it['ifsc'] : '')));
        if ($ifsc === '') {
            return array('ok' => false, 'code' => 'no_ifsc', 'reason' => 'no IFSC on file.');
        }
        if (!self::validIfsc($ifsc)) {
            return array('ok' => false, 'code' => 'bad_ifsc', 'reason' => 'IFSC "' . $ifsc . '" is not a valid format.');
        }
        if (trim((string) (isset($it['beneficiary_name']) ? $it['beneficiary_name'] : '')) === '') {
            return array('ok' => false, 'code' => 'no_beneficiary', 'reason' => 'no beneficiary name.');
        }
        return array('ok' => true, 'code' => 'ok', 'reason' => '');
    }

    /** Indian IFSC: 4 letters, then 0, then 6 alphanumerics. */
    public static function validIfsc($ifsc)
    {
        return (bool) preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper(trim((string) $ifsc)));
    }

    /**
     * Mask an account number, leaving only the last four digits.
     *
     * Everything that reaches a screen, an export or a log goes through here.
     * A short or empty value masks to something harmless rather than revealing
     * what little it has.
     */
    public static function maskAccount($account)
    {
        $a = preg_replace('/\s+/', '', (string) $account);
        $len = strlen($a);
        if ($len === 0) { return ''; }
        if ($len <= 4) { return str_repeat('X', $len); }
        return str_repeat('X', $len - 4) . substr($a, -4);
    }

    /** Does this string still look like an exposed account number? */
    public static function looksUnmasked($value)
    {
        $v = preg_replace('/\s+/', '', (string) $value);
        if ($v === '') { return false; }
        // 6+ consecutive digits with no masking character is a bare account number
        return (bool) preg_match('/^\d{6,}$/', $v);
    }

    /* ---------------- TDS and deductions ---------------- */

    /**
     * TDS state for an item. Rates are statutory and are never invented here.
     *
     *   'not_configured' — nobody has said how this person is treated
     *   'exempt'         — explicitly recorded as nil
     *   'rate'           — an explicit rate applies
     */
    public static function tdsState($item)
    {
        $it = (array) $item;
        if (array_key_exists('tds_exempt', $it) && !empty($it['tds_exempt'])) { return 'exempt'; }
        if (isset($it['tds_rate']) && $it['tds_rate'] !== '' && $it['tds_rate'] !== null
            && is_numeric($it['tds_rate'])) {
            return 'rate';
        }
        return 'not_configured';
    }

    /**
     * Compute TDS and the net payable.
     *
     * Returns ok=false when the treatment is unconfigured, rather than deducting
     * nothing — a silent nil deduction is indistinguishable from a real one and
     * lands the company with the liability.
     */
    public static function computeNet($item)
    {
        $it    = (array) $item;
        $gross = isset($it['gross_payable']) && is_numeric($it['gross_payable']) ? (float) $it['gross_payable'] : null;

        if ($gross === null) {
            return array('ok' => false, 'code' => 'no_gross', 'gross' => 0.0, 'tds' => 0.0, 'other' => 0.0,
                'net' => 0.0, 'reason' => 'No gross payable amount on this item.');
        }

        $state = self::tdsState($it);
        if ($state === 'not_configured') {
            return array('ok' => false, 'code' => 'tds_not_configured', 'gross' => round($gross, 2),
                'tds' => 0.0, 'other' => 0.0, 'net' => 0.0,
                'reason' => 'TDS treatment is not configured for this beneficiary. Record an explicit rate '
                          . 'or an exemption before paying.');
        }

        $tds = 0.0;
        if ($state === 'rate') {
            $tds = round($gross * ((float) $it['tds_rate'] / 100.0), 2);
        }

        $other = isset($it['other_deductions']) && is_numeric($it['other_deductions'])
            ? round((float) $it['other_deductions'], 2) : 0.0;

        $net = round($gross - $tds - $other, 2);
        if ($net < 0) { $net = 0.0; }

        return array('ok' => true, 'code' => 'ok', 'gross' => round($gross, 2),
            'tds' => $tds, 'other' => $other, 'net' => $net, 'reason' => '');
    }

    /* ---------------- export ---------------- */

    /** The export contract, in order. */
    public static function exportColumns()
    {
        return array(
            'batch_reference'    => 'Batch reference',
            'approval_reference' => 'Approval reference',
            'period'             => 'Period',
            'staff_id'           => 'Staff id',
            /*
             * What this line is paying for.
             *
             * A bank file whose rows cannot be told apart is reconciled against
             * the ledger by guesswork. Since a batch can now carry a commission
             * statement and an expense reimbursement side by side — taxed
             * differently, owed for different reasons — the row has to say which.
             */
            'source'             => 'Source',
            'source_ref'         => 'Source reference',
            'payable_kind'       => 'Kind',
            'beneficiary_name'   => 'Beneficiary',
            'masked_account'     => 'Account (masked)',
            'account_ref'        => 'Verified account reference',
            'bank_name'          => 'Bank',
            'ifsc'               => 'IFSC',
            'gross_payable'      => 'Gross payable',
            'tds'                => 'TDS',
            'other_deductions'   => 'Other deductions',
            'net_payable'        => 'Net payable',
            'currency'           => 'Currency',
        );
    }

    /**
     * Build one export row. Never emits a full account number: whatever is
     * supplied is masked on the way out, so an unmasked value arriving from the
     * database cannot leak through the export.
     */
    public static function exportRow($batch, $item)
    {
        $b = (array) $batch;
        $it = (array) $item;

        $calc = self::computeNet($it);

        $account = isset($it['masked_account']) && $it['masked_account'] !== ''
            ? (string) $it['masked_account']
            : (isset($it['account_number']) ? (string) $it['account_number'] : '');

        return array(
            'batch_reference'    => (string) (isset($b['reference']) ? $b['reference'] : ''),
            'approval_reference' => (string) (isset($b['approval_reference']) ? $b['approval_reference'] : ''),
            'period'             => (string) (isset($b['period']) ? $b['period'] : ''),
            'staff_id'           => (int) (isset($it['staff_id']) ? $it['staff_id'] : 0),
            'source'             => self::sourceLabel(isset($it['source_type']) ? $it['source_type'] : null),
            'source_ref'         => (string) (isset($it['source_ref']) && $it['source_ref'] !== ''
                                        ? $it['source_ref']
                                        : (isset($it['source_id']) && $it['source_id']
                                            ? '#' . (int) $it['source_id'] : '')),
            'payable_kind'       => (string) (isset($it['payable_kind']) && $it['payable_kind'] !== ''
                                        ? $it['payable_kind'] : 'commission'),
            'beneficiary_name'   => (string) (isset($it['beneficiary_name']) ? $it['beneficiary_name'] : ''),
            // masked unconditionally, even if already masked — masking is idempotent
            'masked_account'     => self::maskAccount($account),
            'account_ref'        => (string) (isset($it['account_ref']) ? $it['account_ref'] : ''),
            'bank_name'          => (string) (isset($it['bank_name']) ? $it['bank_name'] : ''),
            'ifsc'               => strtoupper((string) (isset($it['ifsc']) ? $it['ifsc'] : '')),
            'gross_payable'      => number_format($calc['gross'], 2, '.', ''),
            'tds'                => number_format($calc['tds'], 2, '.', ''),
            'other_deductions'   => number_format($calc['other'], 2, '.', ''),
            'net_payable'        => number_format($calc['net'], 2, '.', ''),
            'currency'           => (string) (isset($b['currency']) ? $b['currency'] : 'INR'),
        );
    }

    /**
     * Build the whole export. Refuses outright if any row would carry an
     * unmasked account, which is the belt-and-braces check behind maskAccount().
     */
    /**
     * May this batch's payment file be produced?
     *
     * The export IS the payment instruction — the file a person uploads to the
     * bank. Everything else in this module treated it as a report: the state
     * machine required payout_approve to move a batch to approved or exported,
     * while the export itself checked only that the batch existed. A preparer
     * holding nothing but 'payout' could therefore download the full payment
     * file for a DRAFT batch and take it to the bank, and the maker-checker
     * split would have been enforced everywhere except the one place where
     * money actually leaves.
     *
     * Approval is what makes an instruction legitimate, so approval is what
     * this asks about. A cancelled batch is refused outright: its file must
     * never be obtainable, whoever asks.
     *
     * @return array allowed, code, reason
     */
    public static function canExport($batch)
    {
        $b = (array) $batch;
        $status = isset($b['status']) ? (string) $b['status'] : '';

        if ($status === '') {
            return array('allowed' => false, 'code' => 'status_unknown',
                'reason' => 'This batch has no status, so it cannot be shown to have been '
                          . 'approved. No payment file will be produced.');
        }
        if ($status === self::CANCELLED) {
            return array('allowed' => false, 'code' => 'cancelled',
                'reason' => 'This batch was cancelled. A cancelled batch never produces a '
                          . 'payment file.');
        }
        if (!in_array($status, self::exportableStates(), true)) {
            return array('allowed' => false, 'code' => 'not_approved',
                'reason' => 'This batch is ' . $status . '. A payment file is only produced '
                          . 'once the batch has been approved, because the file is the '
                          . 'instruction to pay.');
        }
        return array('allowed' => true, 'code' => '', 'reason' => '');
    }

    /**
     * States in which a payment file may be produced.
     *
     * Exported and settled are included so an already-approved instruction can
     * be retrieved again — a re-upload after a failed transfer, or a record for
     * reconciliation. Neither re-opens the approval question.
     */
    public static function exportableStates()
    {
        return array(self::APPROVED, self::EXPORTED, self::SETTLED);
    }

    public static function buildExport($batch, $items)
    {
        /*
         * Checked here rather than only in the controller: this is the function
         * that turns a batch into a payment file, so every caller — the
         * download route, a future API, a cron job — is covered by one rule.
         */
        $gate = self::canExport($batch);
        if (!$gate['allowed']) {
            return array('ok' => false, 'rows' => array(), 'columns' => self::exportColumns(),
                         'code' => $gate['code'], 'reason' => $gate['reason']);
        }

        $cols = self::exportColumns();
        $rows = array();
        $leaks = array();

        foreach ((array) $items as $it) {
            $row = self::exportRow($batch, $it);
            foreach ($row as $k => $v) {
                if (self::looksUnmasked($v)) { $leaks[] = $k . ' on staff #' . $row['staff_id']; }
            }
            $rows[] = $row;
        }

        if ($leaks) {
            return array('ok' => false, 'rows' => array(), 'columns' => $cols,
                'reason' => 'Export blocked: unmasked account data detected in ' . implode(', ', $leaks) . '.');
        }

        $total = 0.0;
        foreach ($rows as $r) { $total += (float) $r['net_payable']; }

        return array('ok' => true, 'columns' => $cols, 'rows' => $rows,
            'count' => count($rows), 'total_net' => round($total, 2), 'reason' => '');
    }

    /** Render an export as CSV text. */
    public static function toCsv($export)
    {
        if (empty($export['ok'])) { return ''; }
        $out = array();
        $out[] = implode(',', array_map(array(__CLASS__, 'csvCell'), array_values($export['columns'])));
        foreach ($export['rows'] as $r) {
            $line = array();
            foreach (array_keys($export['columns']) as $k) {
                $line[] = self::csvCell(isset($r[$k]) ? $r[$k] : '');
            }
            $out[] = implode(',', $line);
        }
        return implode("\r\n", $out) . "\r\n";
    }

    /**
     * Quote a CSV cell, and neutralise spreadsheet formula injection: a value
     * beginning =, +, - or @ is executed by Excel when the finance team opens
     * the file, which is a real attack path through a beneficiary name.
     */
    public static function csvCell($v)
    {
        $v = (string) $v;
        if ($v !== '' && strpos('=+-@', $v[0]) !== false) { $v = "'" . $v; }
        if (preg_match('/[",\r\n]/', $v)) { $v = '"' . str_replace('"', '""', $v) . '"'; }
        return $v;
    }

    /** Deterministic, human-quotable batch reference. */
    public static function makeReference($period, $batchId)
    {
        return 'PC-' . preg_replace('/[^0-9]/', '', (string) $period) . '-' . str_pad((string) (int) $batchId, 5, '0', STR_PAD_LEFT);
    }

    /* ---------------- payment outcome ---------------- */

    const ITEM_PENDING = 'pending';
    const ITEM_PAID    = 'paid';
    const ITEM_FAILED  = 'failed';
    const ITEM_HELD    = 'held';

    public static function failureReasons()
    {
        return array(
            'account_invalid'   => 'Account number invalid or closed',
            'ifsc_invalid'      => 'IFSC invalid',
            'name_mismatch'     => 'Beneficiary name mismatch',
            'insufficient_funds'=> 'Insufficient funds in the source account',
            'bank_rejected'     => 'Rejected by the bank',
            'limit_exceeded'    => 'Transfer limit exceeded',
            'other'             => 'Other (see notes)',
        );
    }

    /**
     * Record a real settlement. A bank reference is mandatory — this is the line
     * between "we sent a file" and "the money arrived", and without a reference
     * there is nothing to reconcile against.
     */
    public static function validateSettlement($item, $params)
    {
        $it = (array) $item;
        $p  = (array) $params;
        $e  = array();

        if ((string) (isset($it['status']) ? $it['status'] : '') === self::ITEM_PAID) {
            $e[] = 'This item is already marked paid.';
        }
        if (trim((string) (isset($p['bank_reference']) ? $p['bank_reference'] : '')) === '') {
            $e[] = 'A bank reference (UTR / transaction id) is required to mark an item paid.';
        }
        if (!isset($p['paid_amount']) || !is_numeric($p['paid_amount'])) {
            $e[] = 'The amount actually paid must be recorded.';
        } elseif ((float) $p['paid_amount'] <= 0) {
            $e[] = 'The amount actually paid must be greater than zero.';
        }
        if (isset($p['paid_at']) && $p['paid_at'] !== '' && strtotime((string) $p['paid_at']) === false) {
            $e[] = 'The payment date is not valid.';
        }
        return array('ok' => empty($e), 'errors' => $e);
    }

    public static function validateFailure($params)
    {
        $p = (array) $params;
        $e = array();
        $reason = (string) (isset($p['failure_reason']) ? $p['failure_reason'] : '');
        if (!isset(self::failureReasons()[$reason])) { $e[] = 'A valid failure reason is required.'; }
        if ($reason === 'other' && trim((string) (isset($p['notes']) ? $p['notes'] : '')) === '') {
            $e[] = 'A failure recorded as "other" must carry a note explaining it.';
        }
        return array('ok' => empty($e), 'errors' => $e);
    }

    /**
     * May a failed item be retried? Only failures, and only when the underlying
     * problem plausibly changes — a name mismatch retried unchanged just fails
     * again, so it must be corrected first.
     */
    public static function canRetry($item)
    {
        $it = (array) $item;
        if ((string) (isset($it['status']) ? $it['status'] : '') !== self::ITEM_FAILED) {
            return array('allowed' => false, 'code' => 'not_failed',
                'reason' => 'Only a failed payment can be retried.');
        }
        $needsFix = array('account_invalid', 'ifsc_invalid', 'name_mismatch');
        $reason   = (string) (isset($it['failure_reason']) ? $it['failure_reason'] : '');
        if (in_array($reason, $needsFix, true) && empty($it['details_corrected'])) {
            return array('allowed' => false, 'code' => 'fix_first',
                'reason' => 'This failed because the beneficiary details were wrong. Correct and re-verify '
                          . 'them before retrying, or the same payment will fail again.');
        }
        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    /* ---------------- reconciliation ---------------- */

    /**
     * Match what the bank says settled against what the batch expected.
     *
     * Amount mismatches are reported rather than absorbed: a payment that went
     * out at a different figure than intended is exactly the thing reconciliation
     * exists to surface.
     */
    public static function reconcile($items, $bankRows)
    {
        $byRef = array();
        foreach ((array) $bankRows as $r) {
            $r = (array) $r;
            $ref = trim((string) (isset($r['bank_reference']) ? $r['bank_reference'] : ''));
            if ($ref !== '') { $byRef[$ref] = $r; }
        }

        $matched = array(); $mismatched = array(); $missing = array(); $reversed = array();
        $seen = array();

        foreach ((array) $items as $it) {
            $it  = (array) $it;
            $ref = trim((string) (isset($it['bank_reference']) ? $it['bank_reference'] : ''));

            /*
             * A reversed payment reconciles perfectly and must NOT be reported
             * as clean. The bank paid it and the money came back; both facts are
             * true and a reconciliation that shows only the first is the one
             * that lets a reversal go unnoticed at period end.
             */
            if ((string) (isset($it['status']) ? $it['status'] : '') === self::ITEM_REVERSED) {
                $seen[$ref] = true;
                $reversed[] = array_merge($it, array(
                    'actual' => isset($byRef[$ref]['amount']) ? round((float) $byRef[$ref]['amount'], 2) : null,
                    'recon_note' => 'Paid and then reversed. The bank record is expected to be here; '
                                  . 'the money is not.',
                ));
                continue;
            }

            if ($ref === '' || !isset($byRef[$ref])) {
                $missing[] = array_merge($it, array('recon_note' => 'No matching bank record for this item.'));
                continue;
            }

            $seen[$ref] = true;
            $expected = (float) (isset($it['net_payable']) ? $it['net_payable'] : 0);
            $actual   = (float) (isset($byRef[$ref]['amount']) ? $byRef[$ref]['amount'] : 0);

            if (abs($expected - $actual) > 0.005) {
                $mismatched[] = array_merge($it, array(
                    'expected' => round($expected, 2), 'actual' => round($actual, 2),
                    'difference' => round($actual - $expected, 2),
                    'recon_note' => 'Bank settled a different amount than the batch expected.',
                ));
            } else {
                $matched[] = array_merge($it, array('actual' => round($actual, 2)));
            }
        }

        $unexpected = array();
        foreach ($byRef as $ref => $r) {
            if (!isset($seen[$ref])) {
                $unexpected[] = array_merge($r, array('recon_note' => 'Bank record with no matching payout item.'));
            }
        }

        return array(
            'matched'    => $matched,
            'mismatched' => $mismatched,
            'missing'    => $missing,
            'reversed'   => $reversed,
            'unexpected' => $unexpected,
            /* `clean` means nothing needs a human. A reversal does. */
            'clean'      => empty($mismatched) && empty($missing) && empty($unexpected) && empty($reversed),
            'totals'     => array(
                'matched'    => count($matched),
                'mismatched' => count($mismatched),
                'missing'    => count($missing),
                'reversed'   => count($reversed),
                'unexpected' => count($unexpected),
            ),
        );
    }

    /* ---------------- payment advice ---------------- */

    /** Per-beneficiary advice text. Masked account only. */
    public static function paymentAdvice($batch, $item)
    {
        $b = (array) $batch;
        $calc = self::computeNet($item);
        $it = (array) $item;

        $lines = array();
        $lines[] = 'Payment advice — ' . (string) (isset($b['reference']) ? $b['reference'] : '');
        $lines[] = 'Period: ' . (string) (isset($b['period']) ? $b['period'] : '');
        $lines[] = 'Beneficiary: ' . (string) (isset($it['beneficiary_name']) ? $it['beneficiary_name'] : '');
        // isset() is not enough here: a masked_account present but EMPTY must fall
        // through to the raw number and mask it, otherwise the advice silently
        // shows a blank account instead of XXXXXXXX9012.
        $adviceAccount = isset($it['masked_account']) && $it['masked_account'] !== ''
            ? $it['masked_account']
            : (isset($it['account_number']) ? $it['account_number'] : '');
        $lines[] = 'Account: ' . self::maskAccount($adviceAccount);
        $lines[] = 'Bank / IFSC: ' . (string) (isset($it['bank_name']) ? $it['bank_name'] : '')
                 . ' / ' . strtoupper((string) (isset($it['ifsc']) ? $it['ifsc'] : ''));
        $lines[] = '';
        $lines[] = 'Gross commission payable: ' . number_format($calc['gross'], 2);
        $lines[] = 'Less TDS:                 ' . number_format($calc['tds'], 2);
        $lines[] = 'Less other deductions:    ' . number_format($calc['other'], 2);
        $lines[] = 'Net payable:              ' . number_format($calc['net'], 2);
        $lines[] = '';
        $lines[] = 'Approval reference: ' . (string) (isset($b['approval_reference']) ? $b['approval_reference'] : '');
        if (!empty($it['bank_reference'])) {
            $lines[] = 'Bank reference: ' . (string) $it['bank_reference'];
        }
        return implode("\n", $lines);
    }

    public static function statusClass($status)
    {
        switch ((string) $status) {
            case self::SETTLED:
            case self::ITEM_PAID:   return 'success';
            case self::APPROVED:    return 'info';
            case self::EXPORTED:    return 'primary';
            case self::SUBMITTED:   return 'warning';
            case self::ITEM_FAILED:
            case self::CANCELLED:   return 'danger';
            case self::ITEM_HELD:   return 'warning';
            default:                return 'default';
        }
    }

    /* ==================================================================== *
     * Where a payout item came from  (added for Module 8)
     * ==================================================================== */

    /**
     * A payout item has never been required to come from a commission
     * statement — `statement_id` was already NULLABLE in the original schema.
     * Module 8 makes that explicit rather than implicit, so an employee expense
     * claim can be paid by this same engine instead of a second one being built
     * beside it.
     */
    const SOURCE_STATEMENT = 'commission_statement';
    const SOURCE_CLAIM     = 'expense_claim';

    public static function sourceKinds()
    {
        return array(
            self::SOURCE_STATEMENT => 'Commission statement',
            self::SOURCE_CLAIM     => 'Expense claim',
        );
    }

    public static function sourceLabel($t)
    {
        $k = self::sourceKinds();
        return isset($k[$t]) ? $k[$t] : ($t === null || $t === '' ? 'Commission statement' : (string) $t);
    }

    /**
     * May a settled payment be reversed?
     *
     * Reversal used to ride on `payout`, which meant the person who prepared a
     * batch could undo a payment that had already left the bank. It is now its
     * own capability, it refuses the beneficiary, and it insists on a reason —
     * a reversal with no explanation is indistinguishable from an error.
     *
     * @return array allowed, code, reason
     */
    public static function canReverse($item, $actorId, $hasCapability, $reason = '')
    {
        $it      = (array) $item;
        $actorId = (int) $actorId;

        if ($actorId <= 0) {
            return array('allowed' => false, 'code' => 'no_actor',
                         'reason' => 'An acting user must be identified.');
        }
        if (!$hasCapability) {
            return array('allowed' => false, 'code' => 'not_permitted',
                'reason' => 'Reversing a settled payment needs the payout reversal permission. '
                          . 'It is deliberately not the same permission as preparing a batch.');
        }
        if ((string) (isset($it['status']) ? $it['status'] : '') !== self::ITEM_PAID) {
            return array('allowed' => false, 'code' => 'not_paid',
                'reason' => 'Only a payment that has actually settled can be reversed. '
                          . 'An unpaid item is cancelled or corrected, not reversed.');
        }
        if ((int) (isset($it['staff_id']) ? $it['staff_id'] : 0) === $actorId) {
            return array('allowed' => false, 'code' => 'self_beneficiary',
                'reason' => 'You are the person this payment was made to, so you cannot reverse it.');
        }
        if (trim((string) $reason) === '') {
            return array('allowed' => false, 'code' => 'reason_required',
                'reason' => 'A reversal must be explained. Money that went out and came back with no '
                          . 'recorded reason is the hardest kind of entry to answer questions about.');
        }
        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    const ITEM_REVERSED = 'reversed';

    /**
     * Turn a stored bank-account row into the beneficiary fields an item needs.
     *
     * This lives here, not in the model, because of a test that was not really a
     * test. The model's version was checked by grepping its source for the
     * query — and the same query text appears in the profile-fallback method a
     * few lines below, so removing the `is_current` filter from the account
     * lookup changed nothing that the suite could see. A rule worth enforcing is
     * worth putting somewhere it can be exercised directly.
     *
     * `bank_verified` is computed here rather than trusted from the row: an
     * account is verified only if somebody said so, that somebody is recorded,
     * that somebody is not the account holder, and the row is the current one.
     *
     * @param array $account row from wf_bank_accounts (empty for none)
     * @param int   $staffId the person being paid
     * @return array beneficiary_name, masked_account, account_ref, bank_name,
     *               ifsc, bank_verified, unverified_reason
     */
    public static function beneficiaryFromAccount($account, $staffId)
    {
        $a    = (array) $account;
        $base = array('beneficiary_name' => '', 'masked_account' => '', 'account_ref' => '',
                      'bank_name' => '', 'ifsc' => '', 'bank_verified' => 0, 'unverified_reason' => '');

        if (!$a) { $base['unverified_reason'] = 'no account on file'; return $base; }

        $get = function ($k) use ($a) { return isset($a[$k]) ? $a[$k] : ''; };

        $out = array(
            'beneficiary_name' => (string) $get('beneficiary_name'),
            'masked_account'   => (string) $get('masked_account'),
            'bank_name'        => (string) $get('bank_name'),
            'ifsc'             => strtoupper(trim((string) $get('ifsc'))),
            'account_ref'      => $get('account_enc')
                                    ? ('BANKREF-' . substr(sha1((string) $get('account_enc')), 0, 12)) : '',
            'bank_verified'    => 0,
            'unverified_reason'=> '',
        );

        $verifiedBy = (int) $get('verified_by');
        if ((int) (isset($a['is_current']) ? $a['is_current'] : 1) !== 1) {
            $out['unverified_reason'] = 'this account has been replaced by a newer one';
        } elseif ((string) $get('verification_state') !== 'verified') {
            $out['unverified_reason'] = 'not verified';
        } elseif ($verifiedBy <= 0) {
            $out['unverified_reason'] = 'marked verified with nobody recorded as the verifier';
        } elseif ($verifiedBy === (int) $staffId) {
            $out['unverified_reason'] = 'verified by the person it belongs to';
        } else {
            $out['bank_verified'] = 1;
        }
        return $out;
    }
}
