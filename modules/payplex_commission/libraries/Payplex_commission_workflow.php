<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_commission_workflow — statement maker-checker (spec §2.3).
 *
 * Pure and framework-independent: the lifecycle, the approval gates and the
 * immutability rules, all as functions of their inputs so they can be tested
 * exhaustively without a database.
 *
 * The gates exist because this is the last point before money is owed:
 *
 *  1. MAKER IS NOT APPROVER. Whoever generated a batch cannot approve it.
 *  2. NOBODY APPROVES THEIR OWN EARNINGS. Distinct from rule 1 and the one that
 *     actually gets missed: a manager who did not create the batch but appears
 *     inside it is still approving their own money. Both are checked.
 *  3. PAID IS IMMUTABLE. Once money has gone out, the record is frozen. A
 *     correction is a new adjustment entry pointing at it, never an edit — an
 *     editable paid record destroys the audit trail that proves what was paid.
 *  4. A REJECTION MUST SAY WHY. "Rejected" with no reason is unactionable and
 *     tends to mean the reviewer could not articulate a problem.
 *  5. EDITING RE-OPENS APPROVAL. A batch changed after approval is a different
 *     batch and must be approved again.
 */
class Payplex_commission_workflow
{
    /* ---------------- lifecycle ---------------- */

    const GENERATED    = 'generated';
    const UNDER_REVIEW = 'under_review';
    const APPROVED     = 'approved';
    const PAYABLE      = 'payable';
    const EXPORTED     = 'exported';
    const PAID         = 'paid';
    const REVERSED     = 'reversed';
    const REJECTED     = 'rejected';

    public static function states()
    {
        return array(
            self::GENERATED    => 'Generated',
            self::UNDER_REVIEW => 'Under review',
            self::APPROVED     => 'Approved',
            self::PAYABLE      => 'Payable',
            self::EXPORTED     => 'Exported',
            self::PAID         => 'Paid',
            self::REVERSED     => 'Reversed / clawed back',
            self::REJECTED     => 'Rejected',
        );
    }

    public static function transitions()
    {
        return array(
            self::GENERATED    => array(self::UNDER_REVIEW, self::REJECTED),
            self::UNDER_REVIEW => array(self::APPROVED, self::REJECTED),
            self::APPROVED     => array(self::PAYABLE, self::UNDER_REVIEW, self::REJECTED),
            self::PAYABLE      => array(self::EXPORTED, self::PAID, self::UNDER_REVIEW),
            self::EXPORTED     => array(self::PAID, self::PAYABLE),
            // Paid is terminal except for a reversal, which is itself recorded as
            // a separate adjustment rather than an edit of the payment.
            self::PAID         => array(self::REVERSED),
            self::REVERSED     => array(),
            self::REJECTED     => array(self::UNDER_REVIEW),
        );
    }

    public static function canTransition($from, $to)
    {
        $t = self::transitions();
        if (!isset($t[(string) $from])) { return false; }
        return in_array((string) $to, $t[(string) $from], true);
    }

    /** Money has left the building — the record is frozen. */
    public static function isImmutable($statement)
    {
        $s = (array) $statement;
        $state = (string) (isset($s['workflow_state']) ? $s['workflow_state'] : '');
        return in_array($state, array(self::PAID, self::EXPORTED, self::REVERSED), true);
    }

    /** States in which a batch may still be edited in place. */
    public static function isEditable($statement)
    {
        $s = (array) $statement;
        $state = (string) (isset($s['workflow_state']) ? $s['workflow_state'] : '');
        return in_array($state, array(self::GENERATED, self::UNDER_REVIEW, self::REJECTED), true);
    }

    /**
     * Map the legacy status enum onto the spec lifecycle, so existing rows get a
     * sensible workflow_state without touching the historical status column.
     */
    public static function fromLegacyStatus($status)
    {
        switch ((string) $status) {
            case 'draft':            return self::GENERATED;
            case 'pending_approval': return self::UNDER_REVIEW;
            case 'approved':         return self::APPROVED;
            case 'paid':             return self::PAID;
            case 'disputed':         return self::UNDER_REVIEW;
            case 'superseded':       return self::REVERSED;
            default:                 return self::GENERATED;
        }
    }

    /** And back, so legacy screens keep reading a status they understand. */
    public static function toLegacyStatus($state)
    {
        switch ((string) $state) {
            case self::GENERATED:    return 'draft';
            case self::UNDER_REVIEW: return 'pending_approval';
            case self::APPROVED:
            case self::PAYABLE:
            case self::EXPORTED:     return 'approved';
            case self::PAID:         return 'paid';
            case self::REVERSED:     return 'superseded';
            case self::REJECTED:     return 'disputed';
            default:                 return 'draft';
        }
    }


    /* ---------------- draft versus issued ---------------- */

    /*
     * The approved workflow is: generate a draft, obtain an authorised
     * approval, and only then does a final statement exist. A draft must be
     * clearly marked as one, and must not be issued, downloaded as final, or
     * sent to a customer before approval.
     *
     * "Approved" is read from workflow_state, which is the single source of
     * truth. The legacy `status` column is a mirror written alongside it by
     * statementTransition(); it must never be consulted to decide whether
     * something may be issued, because two fields that can disagree cannot
     * answer that question.
     */

    /** States in which the statement is still a draft. */
    public static function draftStates()
    {
        return array(self::GENERATED, self::UNDER_REVIEW, self::REJECTED);
    }

    /** States in which an authorised approval has been recorded. */
    public static function issuedStates()
    {
        return array(self::APPROVED, self::PAYABLE, self::EXPORTED, self::PAID);
    }

    public static function isDraft($statement)
    {
        return in_array(self::stateOf($statement), self::draftStates(), true);
    }

    /**
     * May this statement be issued — printed as final, downloaded as final, or
     * sent to anyone outside the approval chain?
     *
     * Reversed is deliberately NOT issuable: a clawed-back statement is a
     * historical record, not a document to send to somebody as if it stood.
     *
     * @return array allowed, code, reason, label
     */
    public static function canIssue($statement)
    {
        $state = self::stateOf($statement);

        if ($state === '') {
            return array('allowed' => false, 'code' => 'state_unknown', 'label' => 'DRAFT',
                'reason' => 'This statement has no workflow state, so it cannot be shown to be '
                          . 'approved. It is treated as a draft.');
        }
        if (in_array($state, self::draftStates(), true)) {
            return array('allowed' => false, 'code' => 'not_approved', 'label' => 'DRAFT',
                'reason' => 'This statement is ' . self::label($state) . '. A draft cannot be '
                          . 'issued, downloaded as final, or sent until it has been approved.');
        }
        if ($state === self::REVERSED) {
            return array('allowed' => false, 'code' => 'reversed', 'label' => 'REVERSED',
                'reason' => 'This statement was reversed. It is kept as a record and is not '
                          . 'issued as a current document.');
        }
        if (!empty(self::asArray($statement)['edited_since_approval'])) {
            return array('allowed' => false, 'code' => 'edited_since_approval', 'label' => 'DRAFT',
                'reason' => 'This statement changed after it was approved, so the approval no '
                          . 'longer covers what it now says. It must be approved again.');
        }
        return array('allowed' => true, 'code' => 'ok', 'label' => 'FINAL', 'reason' => '');
    }

    /** The word stamped on the document itself. */
    public static function documentLabel($statement)
    {
        return self::canIssue($statement)['label'];
    }

    private static function asArray($statement)
    {
        return is_array($statement) ? $statement : (array) $statement;
    }

    /**
     * The authoritative state.
     *
     * Reads workflow_state ONLY. The legacy `status` enum is deliberately not
     * consulted as a fallback: the two fields were written by separate code
     * paths and could disagree, and a fallback would let whichever happened to
     * say "approved" win.
     */
    public static function stateOf($statement)
    {
        $s = self::asArray($statement);
        return isset($s['workflow_state']) ? trim((string) $s['workflow_state']) : '';
    }

    public static function label($state)
    {
        $all = self::states();
        return isset($all[(string) $state]) ? $all[(string) $state] : (string) $state;
    }

    /* ---------------- the approval gates ---------------- */

    /**
     * May $actorId move this statement to $to?
     *
     * $beneficiaries: staff ids that earn from this batch. Passed in explicitly
     * rather than read off the statement, so a caller cannot accidentally omit
     * the check by handing over a partially-loaded row — an empty list with a
     * non-zero staff_id on the statement still counts that staff member.
     *
     * @return array allowed, code, reason
     */
    /**
     * TWO QUESTIONS UNDER ONE NAME, now separated.
     *
     * This answers "does the workflow permit this actor, given their
     * relationship to this record" — maker, beneficiary, stale approver. It has
     * never answered "does this actor hold the capability the transition
     * requires"; that check lives in the controller.
     *
     * Measured on staging 2026-09-11 against the real pending statement: canAct
     * returned allowed = true for WF Manager (#32) and WF Consultant (#36),
     * neither of whom holds ANY payplex_commission capability. The controller
     * refuses both, so nothing was ever exposed — but a function called canAct
     * that answers true for somebody who cannot act is a trap for the next
     * caller, and this module already has a model method that calls it.
     *
     * $held is optional and backwards compatible:
     *   - pass the actor's capabilities and the required one is enforced here;
     *   - pass nothing and the answer carries capability_checked => false, so a
     *     caller cannot mistake silence for a pass.
     *
     * @param array|null $held capability names the actor holds, or null
     */
    public static function canAct($statement, $to, $actorId, $beneficiaries = array(), $reason = '', $held = null)
    {
        $s = (array) $statement;
        $actorId = (int) $actorId;
        $from = (string) (isset($s['workflow_state']) ? $s['workflow_state'] : '');
        $checked = is_array($held);

        if ($actorId <= 0) {
            return array('allowed' => false, 'code' => 'no_actor', 'reason' => 'An acting user must be identified.',
                'capability_checked' => $checked);
        }

        if ($checked) {
            $need = self::capabilityFor($to);
            if ($need === null) {
                return array('allowed' => false, 'code' => 'unknown_state',
                    'reason' => 'No capability is defined for the state "' . $to . '", so it cannot be entered.',
                    'capability_checked' => true);
            }
            if (!in_array((string) $need, array_map('strval', $held), true)) {
                return array('allowed' => false, 'code' => 'missing_capability',
                    'reason' => 'Moving a statement to ' . $to . ' requires the "' . $need
                              . '" capability, which this person does not hold.',
                    'capability_checked' => true, 'required_capability' => $need);
            }
        }
        if (!self::canTransition($from, $to)) {
            return array('allowed' => false, 'code' => 'bad_transition',
                'reason' => 'A statement cannot move from ' . ($from ?: '(none)') . ' to ' . $to . '.',
                'capability_checked' => $checked);
        }

        // a rejection must be explained
        if ($to === self::REJECTED && trim((string) $reason) === '') {
            return array('allowed' => false, 'code' => 'reason_required',
                'reason' => 'A rejection must include a reason.', 'capability_checked' => $checked);
        }

        // the two separation-of-duties checks, applied to every approving step
        if (in_array($to, array(self::APPROVED, self::PAYABLE, self::PAID), true)) {

            if ((int) (isset($s['created_by']) ? $s['created_by'] : 0) === $actorId) {
                return array('allowed' => false, 'code' => 'maker_is_approver',
                    'reason' => 'The person who generated this statement cannot approve or pay it.',
                    'capability_checked' => $checked);
            }

            $earners = self::beneficiariesOf($s, $beneficiaries);
            if (in_array($actorId, $earners, true)) {
                return array('allowed' => false, 'code' => 'self_beneficiary',
                    'reason' => 'You earn commission in this batch, so you cannot approve or pay it.',
                    'capability_checked' => $checked);
            }

            // approving a batch that a previous approver already signed off, after
            // it was edited, requires a fresh decision rather than inheriting one
            if ($to === self::APPROVED && !empty($s['edited_since_approval'])) {
                if ((int) (isset($s['approved_by']) ? $s['approved_by'] : 0) === $actorId) {
                    return array('allowed' => false, 'code' => 'stale_approval',
                        'reason' => 'This batch changed after you approved it; a different reviewer must look again.',
                        'capability_checked' => $checked);
                }
            }
        }

        if (self::isImmutable($s) && $to !== self::REVERSED) {
            return array('allowed' => false, 'code' => 'immutable',
                'reason' => 'A paid or exported statement cannot be changed. Raise an adjustment instead.',
                'capability_checked' => $checked);
        }

        return array('allowed' => true, 'code' => 'ok', 'reason' => '', 'capability_checked' => $checked);
    }

    /**
     * Everyone who earns from this batch. The statement's own staff_id always
     * counts, plus anyone named in its rows.
     */
    public static function beneficiariesOf($statement, $extra = array())
    {
        $s = (array) $statement;
        $out = array();

        $own = (int) (isset($s['staff_id']) ? $s['staff_id'] : 0);
        if ($own > 0) { $out[] = $own; }

        foreach ((array) $extra as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $out, true)) { $out[] = $id; }
        }

        // rows carried inside a computed batch
        $computed = isset($s['computed_json']) ? $s['computed_json'] : null;
        if (is_string($computed)) { $computed = json_decode($computed, true); }
        if (is_array($computed) && isset($computed['rows']) && is_array($computed['rows'])) {
            foreach ($computed['rows'] as $row) {
                $id = (int) (isset($row['staff_id']) ? $row['staff_id'] : 0);
                if ($id > 0 && !in_array($id, $out, true)) { $out[] = $id; }
            }
        }

        return $out;
    }

    /**
     * May this statement be edited by this actor? Paid records never; and an
     * employee may not edit a batch they earn from even while it is in review.
     */
    public static function canEdit($statement, $actorId, $beneficiaries = array())
    {
        $s = (array) $statement;
        if (!self::isEditable($s)) {
            return array('allowed' => false, 'code' => 'not_editable',
                'reason' => 'This statement is ' . (isset($s['workflow_state']) ? $s['workflow_state'] : 'locked')
                          . ' and can only be corrected with an adjustment entry.');
        }
        if (in_array((int) $actorId, self::beneficiariesOf($s, $beneficiaries), true)) {
            return array('allowed' => false, 'code' => 'self_beneficiary',
                'reason' => 'You earn commission in this batch, so you cannot edit it.');
        }
        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    /**
     * After an edit, an approved batch drops back into review and its previous
     * approval is cleared. Returns the fields to write.
     */
    public static function afterEdit($statement)
    {
        $s = (array) $statement;
        $state = (string) (isset($s['workflow_state']) ? $s['workflow_state'] : '');

        if (in_array($state, array(self::APPROVED, self::PAYABLE), true)) {
            return array(
                'workflow_state'        => self::UNDER_REVIEW,
                'status'                => self::toLegacyStatus(self::UNDER_REVIEW),
                'approved_by'           => null,
                'approved_at'           => null,
                'edited_since_approval' => 1,
            );
        }
        return array('edited_since_approval' => 1);
    }

    /* ---------------- adjustments ---------------- */

    public static function adjustmentTypes()
    {
        return array(
            'correction' => 'Correction of a calculation error',
            'clawback'   => 'Clawback (refund, reversal or cancellation)',
            'bonus'      => 'Approved discretionary addition',
            'deduction'  => 'Approved deduction',
        );
    }

    /**
     * Validate an adjustment against the statement it corrects. An adjustment is
     * how a frozen record gets fixed; it must never be able to rewrite one.
     */
    public static function validateAdjustment($statement, $adjustment)
    {
        $s = (array) $statement;
        $a = (array) $adjustment;
        $e = array();

        if (!$s) { return array('ok' => false, 'errors' => array('The statement being adjusted was not found.')); }

        $type = (string) (isset($a['type']) ? $a['type'] : '');
        if (!isset(self::adjustmentTypes()[$type])) { $e[] = 'A valid adjustment type is required.'; }

        $amount = isset($a['amount']) ? $a['amount'] : null;
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            $e[] = 'An adjustment amount is required.';
        } elseif ((float) $amount == 0.0) {
            $e[] = 'An adjustment of zero has no effect.';
        }

        if (trim((string) (isset($a['reason']) ? $a['reason'] : '')) === '') {
            $e[] = 'An adjustment must state its reason.';
        }

        // a negative adjustment cannot exceed what was earned
        if (is_numeric($amount) && (float) $amount < 0) {
            $gross = (float) (isset($s['gross_amount']) ? $s['gross_amount'] : 0);
            $already = (float) (isset($s['clawback_amount']) ? $s['clawback_amount'] : 0);
            $remaining = $gross - $already;
            if (abs((float) $amount) > $remaining + 0.005) {
                $e[] = 'This would recover ' . abs((float) $amount) . ' but only ' . round($remaining, 2)
                     . ' remains on the statement.';
            }
        }

        return array('ok' => empty($e), 'errors' => $e);
    }

    /** Net payable after adjustments. */
    public static function netOf($statement, $adjustments = array())
    {
        $s = (array) $statement;
        $net = (float) (isset($s['gross_amount']) ? $s['gross_amount'] : 0);
        foreach ((array) $adjustments as $a) {
            $a = (array) $a;
            if ((string) (isset($a['status']) ? $a['status'] : 'approved') === 'rejected') { continue; }
            $net += (float) (isset($a['amount']) ? $a['amount'] : 0);
        }
        return round($net, 2);
    }

    /* ---------------- which capability each move requires ---------------- */

    /*
     * The controller used to gate transitions with a list of exceptions:
     * everything was allowed under the weakest capability, and a handful of
     * named targets required a stronger one. Anything nobody thought to name
     * inherited the weak gate — so 'review' alone could move a statement to
     * PAYABLE, which releases it for payment, or to REVERSED, which unwinds a
     * payment already made.
     *
     * The list is inverted here: every state names its capability, and a state
     * that is not in the map is refused rather than allowed. Adding a state
     * without deciding who may reach it now fails closed, and fails the tests.
     */
    public static function stateCapabilities()
    {
        return array(
            self::GENERATED    => 'compute',
            self::UNDER_REVIEW => 'review',
            self::REJECTED     => 'reject',
            self::APPROVED     => 'approve',
            self::PAYABLE      => 'pay',
            self::EXPORTED     => 'pay',
            self::PAID         => 'pay',
            self::REVERSED     => 'pay',
        );
    }

    /** The capability needed to move a statement INTO $state, or null if unknown. */
    public static function capabilityFor($state)
    {
        $map = self::stateCapabilities();
        $state = (string) $state;
        return isset($map[$state]) ? $map[$state] : null;
    }

    /* ---------------- audit tamper-evidence (spec §7) ---------------- */

    /*
     * Append-only is a rule about the code. It stops the module from rewriting
     * history; it says nothing about anyone with a MySQL prompt. A hash chain
     * changes the question from "is editing allowed?" to "can an edit be
     * hidden?" — each row commits to every row before it, so a single altered
     * character invalidates the rest of the chain.
     *
     * The writer and the verifier MUST agree byte-for-byte on what is hashed.
     * The failure mode this guards against is the two drifting — a column
     * added on one side only — after which verification fails on honest rows
     * and everyone stops believing it. So there is exactly one function that
     * says what a row's hashed content is, and both sides call it.
     */

    /** The chain's starting value: no row precedes the first one. */
    public static function auditGenesis()
    {
        return str_repeat('0', 64);
    }

    /**
     * The columns each kind of audit row commits to, in order.
     *
     * Order is part of the digest — json_encode preserves insertion order — so
     * reordering a spec invalidates every hash already stored under it. These
     * lists are append-only in the same sense the logs are.
     *
     * 'int' and 'string' say how to normalise; 'intn' and 'stringn' allow null.
     * Normalisation is not cosmetic: the writer holds PHP ints while the
     * verifier reads the same values back from MySQL as strings, and without an
     * explicit cast on both sides the JSON differs and every honest row reads
     * as tampered.
     */
    public static function auditFieldSpec($kind = 'statement')
    {
        $specs = array(
            'statement' => array(
                'statement_id'  => 'int',
                'action'        => 'string',
                'from_state'    => 'stringn',
                'to_state'      => 'stringn',
                'actor_id'      => 'intn',
                'reason'        => 'string',
                'snapshot_hash' => 'stringn',
                'data_json'     => 'stringn',
                'occurred_at'   => 'string',
            ),
            'rule' => array(
                'rule_id'       => 'intn',
                'rule_code'     => 'stringn',
                'action'        => 'string',
                'from_status'   => 'stringn',
                'to_status'     => 'stringn',
                'actor_id'      => 'intn',
                'reason'        => 'string',
                'snapshot_json' => 'stringn',
                'occurred_at'   => 'string',
            ),
        );
        $kind = (string) $kind;
        return isset($specs[$kind]) ? $specs[$kind] : null;
    }

    /** Every audit kind this module chains. */
    public static function auditKinds()
    {
        return array('statement', 'rule');
    }

    /**
     * The canonical, type-normalised content of an audit row.
     *
     * One function, used by both the writer and the verifier, so the two
     * cannot drift into disagreeing about what was hashed.
     */
    public static function auditCore($row, $kind = 'statement')
    {
        $spec = self::auditFieldSpec($kind);
        if ($spec === null) { return array(); }

        $r = (array) $row;
        $core = array();

        foreach ($spec as $field => $type) {
            $v = isset($r[$field]) ? $r[$field] : null;
            switch ($type) {
                case 'int':     $core[$field] = (int) $v; break;
                case 'intn':    $core[$field] = $v === null ? null : (int) $v; break;
                case 'stringn': $core[$field] = $v === null ? null : (string) $v; break;
                default:        $core[$field] = (string) $v; break;
            }
        }
        return $core;
    }

    /** sha256(prev_hash + canonical(core)). */
    public static function auditHash($prevHash, $core, $kind = 'statement')
    {
        $prevHash = (string) $prevHash;
        if ($prevHash === '') { $prevHash = self::auditGenesis(); }
        return hash('sha256', $prevHash . json_encode(self::auditCore($core, $kind), JSON_UNESCAPED_SLASHES));
    }

    /**
     * Recompute a whole chain and report exactly what is wrong with it.
     *
     * Rows must arrive ordered by id ASC. Returns:
     *   ok          bool    the chain verifies end to end
     *   checked     int     rows carrying a chain
     *   unchained   int     rows written before chaining existed (see below)
     *   broken_id   int     first row that fails, 0 if none
     *   reason      string  why it failed, '' if it did not
     *   tail_proof  bool    whether removal of the newest rows is detectable
     *
     * Honest limit, stated rather than hidden: a chain proves that no row was
     * edited and that none was removed from the middle, because the next row's
     * prev_hash would no longer match. Deleting from the END breaks nothing —
     * the remaining chain is still internally consistent. tail_proof is false
     * whenever that is all we have, so nobody reads "ok" as more than it is.
     */
    public static function verifyAuditChain($rows, $expectedHead = null, $kind = 'statement')
    {
        $out = array('ok' => true, 'checked' => 0, 'unchained' => 0,
                     'broken_id' => 0, 'reason' => '', 'tail_proof' => false,
                     'head' => self::auditGenesis(), 'verdict' => 'verified');

        $prev = self::auditGenesis();
        $started = false;

        foreach ((array) $rows as $row) {
            $r  = (array) $row;
            $id = (int) (isset($r['id']) ? $r['id'] : 0);
            $rowHash = isset($r['row_hash']) ? (string) $r['row_hash'] : '';

            if ($rowHash === '') {
                /*
                 * A row from before the chain existed. It cannot be verified —
                 * and pretending otherwise by hashing it now would be worse
                 * than useless, because it would make un-attested rows look
                 * attested. It is counted, reported, and skipped; the chain
                 * begins at the first row that actually carries a hash.
                 */
                if ($started) {
                    $out['ok'] = false;
                    $out['verdict'] = 'broken';
                    $out['broken_id'] = $id;
                    $out['reason'] = 'Row #' . $id . ' has no hash but follows chained rows — the chain was cut.';
                    return $out;
                }
                $out['unchained']++;
                continue;
            }

            $expected = self::auditHash($prev, $r, $kind);
            $storedPrev = isset($r['prev_hash']) ? (string) $r['prev_hash'] : '';

            if (!hash_equals($prev, $storedPrev)) {
                $out['ok'] = false;
                $out['verdict'] = 'broken';
                $out['broken_id'] = $id;
                $out['reason'] = 'Row #' . $id . ' does not follow the row before it — a row was removed or reordered.';
                return $out;
            }
            if (!hash_equals($expected, $rowHash)) {
                $out['ok'] = false;
                $out['verdict'] = 'broken';
                $out['broken_id'] = $id;
                $out['reason'] = 'Row #' . $id . ' was altered after it was written.';
                return $out;
            }

            $started = true;
            $prev = $rowHash;
            $out['checked']++;
        }

        $out['head'] = $prev;

        /*
         * An independently-stored head turns "internally consistent" into
         * "complete": if the last row were deleted, the surviving chain would
         * still verify, but its head would no longer match the one recorded
         * outside the table.
         */
        if ($expectedHead !== null && (string) $expectedHead !== '') {
            $out['tail_proof'] = true;
            if (!hash_equals((string) $expectedHead, $prev)) {
                $out['ok'] = false;
                $out['verdict'] = 'broken';
                $out['reason'] = 'The newest audit entries are missing — the chain ends earlier than recorded.';
            }
        }

        /*
         * "No tampering found" and "nothing was checked" are not the same
         * answer, and ok=true said both.
         *
         * On this install the rule log is the second one exactly: twenty
         * entries — including the approval of the rule that sets what people
         * are paid, and four changes to the declared environment — every one of
         * them written before the chain columns existed, so checked=0,
         * unchained=20, ok=true. The Rules screen rendered that as a green
         * padlock reading "Verified — 0 entries hash-chained and unaltered",
         * with the twenty uncovered entries noted underneath in small grey
         * text. The prominent signal was the wrong one.
         *
         * ok is left alone: it still means "no tampering was detected", which
         * is what existing callers and tests ask it. The verdict says what was
         * actually established.
         */
        if ($out['verdict'] !== 'broken') {
            if ($out['checked'] === 0) {
                $out['verdict'] = $out['unchained'] > 0 ? 'nothing_chained' : 'empty';
            } elseif ($out['unchained'] > 0) {
                $out['verdict'] = 'partly_chained';
            }
        }

        return $out;
    }

    public static function stateClass($state)
    {
        switch ((string) $state) {
            case self::PAID:         return 'success';
            case self::APPROVED:
            case self::PAYABLE:      return 'info';
            case self::EXPORTED:     return 'primary';
            case self::UNDER_REVIEW: return 'warning';
            case self::REJECTED:
            case self::REVERSED:     return 'danger';
            default:                 return 'default';
        }
    }
}
