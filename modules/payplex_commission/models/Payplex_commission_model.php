<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_commission_calc.php';
require_once __DIR__ . '/../libraries/Payplex_commission_rule.php';
require_once __DIR__ . '/../libraries/Payplex_commission_source.php';
require_once __DIR__ . '/../libraries/Payplex_commission_workflow.php';
require_once __DIR__ . '/../libraries/Payplex_commission_clawback.php';
require_once __DIR__ . '/../libraries/Payplex_commission_payout.php';

/**
 * Commission statements: compute -> snapshot -> maker-checker approve -> pay.
 * Immutability: once a statement is APPROVED/PAID it is never mutated; a
 * correction creates a NEW statement with supersedes_id pointing at the old one,
 * and the old one is marked 'superseded'. Changing a rule creates a new
 * rule_version and never rewrites approved statements.
 */
class Payplex_commission_model extends App_Model
{
    /** Where the newest audit-chain hash is kept, outside the audit table. */
    const AUDIT_HEAD_OPTION = 'payplex_commission_audit_head';

    private $tRules;
    private $tStmt;
    private $tItems;
    private $tDisp;

    public function __construct()
    {
        parent::__construct();
        $this->tRules = db_prefix() . 'payplex_commission_rule_versions';
        $this->tStmt  = db_prefix() . 'payplex_commission_statements';
        $this->tItems = db_prefix() . 'payplex_commission_items';
        $this->tDisp  = db_prefix() . 'payplex_commission_disputes';
    }

    public function activeRule($scope = 'global', $scopeRef = null)
    {
        $this->db->where('active', 1)->where('scope', $scope);
        if ($scopeRef !== null) {
            $this->db->where('scope_ref', $scopeRef);
        }
        $row = $this->db->order_by('id', 'DESC')->limit(1)->get($this->tRules)->row();
        if (!$row) {
            $row = $this->db->where('active', 1)->where('scope', 'global')
                ->order_by('id', 'DESC')->limit(1)->get($this->tRules)->row();
        }
        return $row;
    }

    /**
     * Compute a DRAFT statement for a staff member + period from source rows.
     * $sources: array of ['type','id','base','achieved_pct'].
     * Does NOT touch any already-approved statement.
     */
    public function computeDraft($staffId, $period, array $sources, $currency = 'INR')
    {
        $rule = $this->activeRule('staff', (string) $staffId) ?: $this->activeRule('global');
        // [Payplex safety - revised requirement section 10] No assumed rates.
        // If no active/approved commission rule exists for this staff/scope, do
        // NOT compute with a placeholder rate. Signal that configuration is
        // required so the caller marks it instead of paying a guessed 5%.
        if (!$rule) {
            return 'configuration_required';
        }
        $ruleDef = json_decode($rule->rule_json, true);
        if (!is_array($ruleDef)) {
            return 'configuration_required';
        }
        /*
         * Being an array is not the same as being payable. This path reads
         * rule_json from the legacy rule_versions table, which no validation
         * governs, so a row like {"type":"percentage"} with no rate decoded
         * cleanly, passed the check above, and computed a commission of ZERO.
         * The staff member would have been shown a statement saying they earned
         * nothing — itemised, plausible, and wrong — while the guard three lines
         * up existed precisely to stop an unconfigured rule from paying.
         */
        $problems = Payplex_commission_calc::definitionProblems($ruleDef);
        if ($problems) {
            return 'configuration_required';
        }

        $gross = 0.0;
        $items = [];
        // Sales Targets integration point: resolve this staff member's
        // achievement % once for the whole statement via a read-only bridge
        // to the Sales Targets module (0.0 when that module is absent or
        // inactive or has no active target for the period). A source may
        // still override with an explicit positive achieved_pct.
        $targetPct = $this->salesTargetAchievementPct((int) $staffId, $period);
        foreach ($sources as $s) {
            $provided    = isset($s['achieved_pct']) ? (float) $s['achieved_pct'] : 0.0;
            $achievedPct = $provided > 0 ? $provided : $targetPct;
            $res = Payplex_commission_calc::compute($s['base'] ?? 0, $ruleDef, $achievedPct);
            $gross += $res['amount'];
            $items[] = [
                'source_type'       => $s['type'] ?? 'invoice',
                'source_id'         => $s['id'] ?? null,
                'base_amount'       => round((float) ($s['base'] ?? 0), 2),
                'commission_amount' => $res['amount'],
                'breakdown'         => implode('; ', $res['breakdown']),
            ];
        }
        $gross = round($gross, 2);

        $computed = ['rule' => $ruleDef, 'items' => $items, 'gross' => $gross, 'target_achievement_pct' => $targetPct];
        /*
         * A statement is BORN as a draft, and the two status fields are written
         * together. This wrote `status` alone, so workflow_state took its
         * column default of 'generated' while `status` claimed
         * 'pending_approval' — divergent from the moment of creation, and
         * saying the statement was awaiting approval when nobody had submitted
         * it for review. The legacy mirror is derived, never typed by hand.
         */
        $createdBy = function_exists('get_staff_user_id') ? get_staff_user_id() : null;
        $this->db->insert($this->tStmt, [
            'staff_id'        => (int) $staffId,
            'period'          => $period,
            'rule_version_id' => $rule ? $rule->id : null,
            'gross_amount'    => $gross,
            'net_amount'      => $gross,
            'currency'        => $currency,
            'computed_json'   => json_encode($computed),
            'workflow_state'  => Payplex_commission_workflow::GENERATED,
            'status'          => Payplex_commission_workflow::toLegacyStatus(
                                     Payplex_commission_workflow::GENERATED),
            'created_by'      => $createdBy,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        $statementId = $this->db->insert_id();
        // §7: every generation is recorded, not only what happens to it later.
        $this->statementAudit($statementId, 'generated', null,
            Payplex_commission_workflow::GENERATED, (int) $createdBy,
            'Draft generated for ' . $period . '.',
            array('gross' => $gross, 'items' => count($items)));
        foreach ($items as $it) {
            $it['statement_id'] = $statementId;
            $it['created_at'] = date('Y-m-d H:i:s');
            $this->db->insert($this->tItems, $it);
        }
        return $statementId;
    }

    /**
     * Read-only bridge to the separately-owned Sales Targets module. Returns
     * the staff member converted-leads achievement % for the commission
     * period, used to drive the accelerator in Payplex_commission_calc.
     * Returns 0.0 on ANY of: module not deployed, no active target
     * overlapping the period, target value <= 0, or any lookup error. A
     * missing or broken Sales Targets module must never break commission
     * computation.
     *
     * @param  int    $staffId
     * @param  string $period   commission period, Y-m
     * @return float
     */
    private function salesTargetAchievementPct($staffId, $period)
    {
        try {
            $staffId = (int) $staffId;
            if ($staffId <= 0) {
                return 0.0;
            }

            // The module must be physically present; loading a missing model
            // would raise a fatal that try/catch cannot trap.
            $modelFile = FCPATH . 'modules/sales_targets/models/Sales_targets_model.php';
            if (!is_file($modelFile)) {
                return 0.0;
            }

            $range  = $this->periodToDateRange($period);
            $pStart = $range[0];
            $pEnd   = $range[1];

            $ci = &get_instance();
            $ci->load->model('sales_targets/sales_targets_model');
            if (!isset($ci->sales_targets_model)) {
                return 0.0;
            }

            // get_all() returns the staff member non-cancelled targets, each
            // already carrying target_value plus a live 'achieved' figure.
            $targets = $ci->sales_targets_model->get_all($staffId);
            if (empty($targets)) {
                return 0.0;
            }

            // Pick the target whose [period_start, period_end] overlaps the
            // commission period; prefer the latest-starting one on ties.
            $best = null;
            foreach ($targets as $t) {
                $status = isset($t['status']) ? $t['status'] : 'Active';
                if ($status === 'Cancelled') {
                    continue;
                }
                $ts = isset($t['period_start']) ? $t['period_start'] : null;
                $te = isset($t['period_end']) ? $t['period_end'] : null;
                if (!$ts || !$te) {
                    continue;
                }
                // DATE strings (Y-m-d) compare correctly as text.
                if ($te >= $pStart && $ts <= $pEnd) {
                    if ($best === null || $ts > $best['period_start']) {
                        $best = $t;
                    }
                }
            }
            if ($best === null) {
                return 0.0;
            }

            $target = (float) $best['target_value'];
            if ($target <= 0) {
                return 0.0;
            }

            return round((float) $best['achieved'] / $target * 100.0, 2);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Map a commission period string to a [start, end] date range (Y-m-d).
     * Supports Y-m (calendar month); anything else falls back to the current
     * calendar month so a malformed period can never fatal.
     *
     * @param  string $period
     * @return array  [startDate, endDate]
     */
    private function periodToDateRange($period)
    {
        if (is_string($period) && preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $period)) {
            $start = $period . '-01';
            return [$start, date('Y-m-t', strtotime($start))];
        }

        return [date('Y-m-01'), date('Y-m-t')];
    }

    public function get($id)
    {
        return $this->db->where('id', (int) $id)->get($this->tStmt)->row();
    }

    public function all($status = null, $staffId = null, $limit = 200)
    {
        if ($status) { $this->db->where('status', $status); }
        if ($staffId) { $this->db->where('staff_id', (int) $staffId); }
        return $this->db->order_by('created_at', 'DESC')->limit($limit)->get($this->tStmt)->result();
    }

    public function itemsFor($statementId)
    {
        return $this->db->where('statement_id', (int) $statementId)->get($this->tItems)->result();
    }

    /**
     * Maker-checker approval. Freezes the row with a snapshot hash. Immutable after.
     * @return true|string reason
     */
    /**
     * Legacy approval — retired. Delegates to the workflow.
     *
     * This wrote ONLY the `status` enum, while statementTransition() writes
     * `workflow_state` and mirrors `status` alongside it. Two independent
     * writers meant the two fields could disagree: approving here set
     * status = 'approved' while workflow_state stayed 'under_review', so the
     * Statements list and an employee's own My Commission page would show an
     * APPROVED statement that the workflow still considered a draft. That is
     * exactly the state a draft must never be displayed in.
     *
     * It now routes through the one transition path, so the two fields cannot
     * be written apart, and the maker-checker and self-beneficiary gates that
     * live there apply to this route too.
     */
    public function approve($id, $approver)
    {
        $s = $this->statementRow((int) $id);
        if (!$s) { return 'not_found'; }

        $state = Payplex_commission_workflow::stateOf($s);
        // The old contract only allowed approving something awaiting approval.
        if ($state !== Payplex_commission_workflow::UNDER_REVIEW) { return 'not_pending'; }

        $res = $this->statementTransition((int) $id, Payplex_commission_workflow::APPROVED,
            (int) $approver, 'Approved from the statement view.');
        if (!empty($res['ok'])) {
            $this->stampSnapshotHash((int) $id);
            return true;
        }
        if (isset($res['code']) && $res['code'] === 'maker_is_approver') { return 'maker_is_checker'; }
        if (isset($res['code']) && $res['code'] === 'self_beneficiary')   { return 'maker_is_checker'; }
        return 'race_lost';
    }

    /**
     * Legacy "mark paid" — retired for the same reason, and additionally
     * because it allowed jumping straight from approved to paid, skipping
     * payable and exported. Money leaving is the one transition that should
     * not have a shortcut.
     */
    public function markPaid($id, $payer)
    {
        $s = $this->statementRow((int) $id);
        if (!$s) { return 'not_approved'; }

        $res = $this->statementTransition((int) $id, Payplex_commission_workflow::PAID,
            (int) $payer, 'Marked paid from the statement view.');
        return !empty($res['ok']) ? true : 'not_approved';
    }

    /**
     * Freeze what was approved, so a later edit is detectable.
     * Recomputed from the row as approved rather than trusted from before.
     */
    private function stampSnapshotHash($id)
    {
        $s = $this->statementRow((int) $id);
        if (!$s) { return; }
        $basis = $s['staff_id'] . '|' . $s['period'] . '|' . $s['net_amount'] . '|'
               . (isset($s['computed_json']) ? $s['computed_json'] : '');
        $this->db->where('id', (int) $id)->update($this->tStmt, array(
            'snapshot_hash' => hash('sha256', $basis),
            'updated_at'    => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * Correction: never edits an approved statement. Creates a superseding draft.
     */
    public function supersede($oldId, array $sources)
    {
        $old = $this->get($oldId);
        if (!$old) { return 'not_found'; }
        $newId = $this->computeDraft($old->staff_id, $old->period, $sources, $old->currency);
        if (!is_numeric($newId)) { return $newId; } // e.g. 'configuration_required'
        $this->db->where('id', $newId)->update($this->tStmt, ['supersedes_id' => (int) $oldId]);
        $this->db->where('id', $oldId)->update($this->tStmt, ['status' => 'superseded', 'updated_at' => date('Y-m-d H:i:s')]);
        return $newId;
    }

    /** Verify an approved statement has not been tampered with. */
    public function verifyIntegrity($id)
    {
        $s = $this->get($id);
        if (!$s || !$s->snapshot_hash) { return null; }
        $hashBasis = $s->staff_id . '|' . $s->period . '|' . $s->net_amount . '|' . $s->computed_json;
        return hash_equals($s->snapshot_hash, hash('sha256', $hashBasis));
    }

    /* disputes */
    public function raiseDispute($statementId, $reason)
    {
        $this->db->insert($this->tDisp, [
            'statement_id' => (int) $statementId,
            'raised_by'    => function_exists('get_staff_user_id') ? get_staff_user_id() : null,
            'reason'       => $reason,
            'status'       => 'open',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        $this->db->where('id', $statementId)->update($this->tStmt, ['status' => 'disputed']);
        return $this->db->insert_id();
    }

    /* ================= v0.2.0 — Commission Rule Builder (§2.1) ================= */

    private function tRuleBuilder() { return db_prefix() . 'payplex_commission_rules'; }
    private function tRuleAudit()   { return db_prefix() . 'payplex_commission_rule_audit'; }

    /** Append-only audit. Every rule action is recorded; nothing is ever updated. */
    public function ruleAudit($ruleId, $action, $fromStatus, $toStatus, $actorId, $reason = '', $snapshot = null)
    {
        /*
         * Chained for the same reason statement decisions are. A commission
         * rule decides what every person is paid, so a quietly edited record
         * of who approved which rate is worth as much to forge as a statement.
         */
        $this->appendChainedAudit($this->tRuleAudit(), 'rule', array(
            'rule_id'       => $ruleId ? (int) $ruleId : null,
            'rule_code'     => is_array($snapshot) && isset($snapshot['rule_code']) ? $snapshot['rule_code'] : null,
            'action'        => substr((string) $action, 0, 40),
            'from_status'   => $fromStatus,
            'to_status'     => $toStatus,
            'actor_id'      => (int) $actorId,
            'reason'        => substr((string) $reason, 0, 500),
            'snapshot_json' => $snapshot === null ? null : json_encode($snapshot),
            'occurred_at'   => date('Y-m-d H:i:s'),
        ));
    }

    public function ruleAuditLog($ruleId = 0, $limit = 200)
    {
        if (!$this->db->table_exists($this->tRuleAudit())) { return array(); }
        if ($ruleId) { $this->db->where('rule_id', (int) $ruleId); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($this->tRuleAudit())->result();
    }

    public function rulesAll($status = null, $limit = 500)
    {
        if (!$this->db->table_exists($this->tRuleBuilder())) { return array(); }
        if ($status) { $this->db->where('status', $status); }
        return $this->db->order_by('priority', 'ASC')->order_by('id', 'DESC')
            ->limit((int) $limit)->get($this->tRuleBuilder())->result_array();
    }

    public function ruleGet($id)
    {
        if (!$this->db->table_exists($this->tRuleBuilder())) { return null; }
        $r = $this->db->where('id', (int) $id)->get($this->tRuleBuilder())->row_array();
        return $r ?: null;
    }

    /** Map a stored row into the shape the pure rule library expects. */
    private function ruleRowToArray($row)
    {
        $r = (array) $row;
        if (isset($r['slabs_json']) && $r['slabs_json'] !== '' && $r['slabs_json'] !== null) {
            $r['slabs'] = json_decode($r['slabs_json'], true);
        }
        return $r;
    }

    public function rulesForResolution()
    {
        $out = array();
        foreach ($this->rulesAll() as $r) { $out[] = $this->ruleRowToArray($r); }
        return $out;
    }

    /**
     * Which rule governs this transaction? Returns the library's resolution,
     * including CONFIGURATION_REQUIRED when nothing approved and active applies.
     * There is no fallback rate anywhere on this path.
     */
    public function resolveRule($context, $on = null, $isProduction = true)
    {
        return Payplex_commission_rule::resolve($this->rulesForResolution(), $context, $on, $isProduction);
    }

    /**
     * Create a rule. Always born as a DRAFT — nothing reaches 'active' without
     * passing through submission and approval by a different person.
     */
    public function ruleCreate($data, $actorId = 0)
    {
        $v = Payplex_commission_rule::validate($data);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors']); }
        if (!$this->db->table_exists($this->tRuleBuilder())) {
            return array('ok' => false, 'errors' => array('Rule builder tables are not installed yet.'));
        }

        $row = $this->ruleColumns($data);
        $row['status']     = Payplex_commission_rule::DRAFT;
        $row['version']    = isset($data['version']) ? (int) $data['version'] : 1;
        $row['supersedes'] = isset($data['supersedes']) && $data['supersedes'] ? (int) $data['supersedes'] : null;
        $row['created_by'] = (int) $actorId;
        $row['created_at'] = date('Y-m-d H:i:s');

        $this->db->insert($this->tRuleBuilder(), $row);
        $id = (int) $this->db->insert_id();
        $this->ruleAudit($id, 'created', null, Payplex_commission_rule::DRAFT, $actorId, '', $row);

        return array('ok' => true, 'id' => $id, 'errors' => array());
    }

    /** Whitelist of writable columns, so a stray POST field cannot reach the table. */
    private function ruleColumns($d)
    {
        $d = (array) $d;
        $val = function ($k) use ($d) {
            return isset($d[$k]) && $d[$k] !== '' ? $d[$k] : null;
        };
        return array(
            'rule_code'             => substr((string) $val('rule_code'), 0, 64),
            'name'                  => substr((string) $val('name'), 0, 191),
            'company'               => $val('company'),
            'product'               => $val('product'),
            'employee_role'         => $val('employee_role'),
            'staff_id'              => $val('staff_id') === null ? null : (int) $val('staff_id'),
            'sales_channel'         => $val('sales_channel'),
            'lead_source'           => $val('lead_source'),
            'customer_type'         => $val('customer_type'),
            'territory'             => $val('territory'),
            'calc_type'             => (string) $val('calc_type'),
            'calc_base'             => (string) $val('calc_base'),
            'rate'                  => $val('rate'),
            'amount'                => $val('amount'),
            'slabs_json'            => isset($d['slabs'])
                                        ? (is_string($d['slabs']) ? $d['slabs'] : json_encode($d['slabs']))
                                        : $val('slabs_json'),
            'min_threshold'         => $val('min_threshold'),
            'max_eligible_amount'   => $val('max_eligible_amount'),
            'accelerator_threshold' => $val('accelerator_threshold'),
            'accelerator_rate'      => $val('accelerator_rate'),
            'cap'                   => $val('cap'),
            'priority'              => $val('priority') === null ? 100 : (int) $val('priority'),
            'effective_from'        => $val('effective_from'),
            'effective_to'          => $val('effective_to'),
            'is_test'               => !empty($d['is_test']) ? 1 : 0,
            'notes'                 => $val('notes'),
        );
    }

    /**
     * Update a rule. A draft or submitted rule is edited in place; anything that
     * has already been approved becomes a NEW VERSION instead, because a
     * commission may already have been calculated against the current one.
     */
    public function ruleUpdate($id, $data, $actorId = 0)
    {
        $rule = $this->ruleGet($id);
        if (!$rule) { return array('ok' => false, 'errors' => array('Rule not found.')); }

        $merged = array_merge($this->ruleRowToArray($rule), (array) $data);
        $v = Payplex_commission_rule::validate($merged);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors']); }

        if (Payplex_commission_rule::isEditableInPlace($rule)) {
            $row = $this->ruleColumns($merged);
            $row['updated_at'] = date('Y-m-d H:i:s');
            $this->db->where('id', (int) $id)->update($this->tRuleBuilder(), $row);
            $this->ruleAudit($id, 'edited', $rule['status'], $rule['status'], $actorId, '', $row);
            return array('ok' => true, 'id' => (int) $id, 'versioned' => false, 'errors' => array());
        }

        $next = Payplex_commission_rule::nextVersion($this->ruleRowToArray($rule), (array) $data, $actorId);
        $res  = $this->ruleCreate($next, $actorId);
        if (!empty($res['ok'])) {
            $this->ruleAudit((int) $res['id'], 'versioned_from', $rule['status'],
                Payplex_commission_rule::DRAFT, $actorId,
                'New version ' . $next['version'] . ' created from rule #' . (int) $id
                . ' (the approved rule was left untouched so historical commissions do not change).');
            $res['versioned'] = true;
        }
        return $res;
    }

    /** Move a rule through its lifecycle, enforcing the permitted transitions. */
    public function ruleTransition($id, $to, $actorId = 0, $reason = '')
    {
        $rule = $this->ruleGet($id);
        if (!$rule) { return array('ok' => false, 'reason' => 'Rule not found.'); }

        $from = (string) $rule['status'];
        if (!Payplex_commission_rule::canTransition($from, $to)) {
            return array('ok' => false, 'reason' => 'Cannot move a rule from ' . $from . ' to ' . $to . '.');
        }

        // approval is the one transition with a separation-of-duties rule
        if ($to === Payplex_commission_rule::APPROVED) {
            $gate = Payplex_commission_rule::canApprove($rule, $actorId);
            if (!$gate['allowed']) { return array('ok' => false, 'reason' => $gate['reason']); }
        }

        // a rejection must say why
        if ($from === Payplex_commission_rule::SUBMITTED && $to === Payplex_commission_rule::DRAFT
            && trim((string) $reason) === '') {
            return array('ok' => false, 'reason' => 'A rejection must include a reason.');
        }

        // activation must not create an ambiguous overlap
        if ($to === Payplex_commission_rule::ACTIVE) {
            $overlaps = Payplex_commission_rule::overlapsExisting(
                $this->ruleRowToArray($rule), $this->rulesForResolution());
            if ($overlaps) {
                $msgs = array();
                foreach ($overlaps as $o) { $msgs[] = $o['message']; }
                return array('ok' => false, 'reason' => implode(' ', $msgs)
                    . ' Pause or end-date the existing rule before activating this one.');
            }
        }

        $set = array('status' => $to, 'updated_at' => date('Y-m-d H:i:s'));
        if ($to === Payplex_commission_rule::SUBMITTED) { $set['submitted_by'] = (int) $actorId; }
        if ($to === Payplex_commission_rule::APPROVED) {
            $set['approved_by'] = (int) $actorId;
            $set['approved_at'] = date('Y-m-d H:i:s');
        }
        if ($to === Payplex_commission_rule::ACTIVE) { $set['activated_at'] = date('Y-m-d H:i:s'); }

        $this->db->where('id', (int) $id)->update($this->tRuleBuilder(), $set);
        $this->ruleAudit($id, 'transition', $from, $to, $actorId, $reason, $this->ruleGet($id));

        return array('ok' => true, 'reason' => '');
    }

    /**
     * Deactivate legacy rules flagged as test rules. Spec §2.1 requires the
     * staging TEST accelerator to be retired once real rules pass their tests,
     * and forbids a test rule ever becoming active in production. Reversible:
     * rows are kept, only the active flag is cleared.
     */
    public function retireLegacyTestRules($actorId = 0)
    {
        $legacy = db_prefix() . 'payplex_commission_rule_versions';
        if (!$this->db->table_exists($legacy)) { return array('ok' => false, 'retired' => 0, 'names' => array()); }

        $rows = $this->db->where('active', 1)
            ->group_start()->like('name', 'TEST')->or_like('name', 'placeholder')->group_end()
            ->get($legacy)->result();

        $names = array();
        foreach ($rows as $r) {
            $this->db->where('id', $r->id)->update($legacy, array('active' => 0));
            $names[] = $r->name;
            $this->ruleAudit(null, 'legacy_test_rule_retired', 'active', 'inactive', $actorId,
                'Deactivated legacy rule "' . $r->name . '" (test/placeholder rules must never generate commission).',
                (array) $r);
        }
        return array('ok' => true, 'retired' => count($rows), 'names' => $names);
    }

    /** Legacy rules still marked active — surfaced as a warning in the UI. */
    public function legacyActiveRules()
    {
        $legacy = db_prefix() . 'payplex_commission_rule_versions';
        if (!$this->db->table_exists($legacy)) { return array(); }
        return $this->db->where('active', 1)->order_by('id', 'DESC')->get($legacy)->result();
    }

    /* ================= v0.2.0 — Source Policy & generation (§2.2) ================= */

    private function tPolicies() { return db_prefix() . 'payplex_commission_source_policies'; }
    private function tRuns()     { return db_prefix() . 'payplex_commission_generation_runs'; }
    private function tLedger()   { return db_prefix() . 'payplex_commission_source_ledger'; }

    public function policiesAll($limit = 200)
    {
        if (!$this->db->table_exists($this->tPolicies())) { return array(); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($this->tPolicies())->result_array();
    }

    public function policyGet($id)
    {
        if (!$this->db->table_exists($this->tPolicies())) { return null; }
        $r = $this->db->where('id', (int) $id)->get($this->tPolicies())->row_array();
        return $r ? $this->policyRowToArray($r) : null;
    }

    /** The one policy currently governing generation, or null. */
    public function activePolicy()
    {
        if (!$this->db->table_exists($this->tPolicies())) { return null; }
        $r = $this->db->where('status', Payplex_commission_source::ACTIVE)
            ->order_by('id', 'DESC')->limit(1)->get($this->tPolicies())->row_array();
        return $r ? $this->policyRowToArray($r) : null;
    }

    private function policyRowToArray($row)
    {
        $r = (array) $row;
        $r['events']  = isset($r['events_json'])  ? json_decode((string) $r['events_json'], true)  : array();
        $r['filters'] = isset($r['filters_json']) ? json_decode((string) $r['filters_json'], true) : array();
        if (!is_array($r['events']))  { $r['events']  = array(); }
        if (!is_array($r['filters'])) { $r['filters'] = array(); }
        return $r;
    }

    private function policyColumns($d)
    {
        $p = Payplex_commission_source::normalizePolicy($d);
        return array(
            'name'                  => substr($p['name'], 0, 191),
            'events_json'           => json_encode($p['events']),
            'filters_json'          => json_encode($p['filters']),
            'exclude_refunded'      => $p['exclude_refunded'] ? 1 : 0,
            'exclude_cancelled'     => $p['exclude_cancelled'] ? 1 : 0,
            'allow_partial'         => $p['allow_partial'] ? 1 : 0,
            'include_tax'           => $p['include_tax'] ? 1 : 0,
            'include_discount'      => $p['include_discount'] ? 1 : 0,
            'gateway_fee_treatment' => $p['gateway_fee_treatment'],
            'min_collected_amount'  => $p['min_collected_amount'],
            'currencies'            => $p['currencies'] ? implode(',', $p['currencies']) : null,
            'date_from'             => $p['date_from'],
            'date_to'               => $p['date_to'],
            'notes'                 => isset($d['notes']) && $d['notes'] !== '' ? (string) $d['notes'] : null,
        );
    }

    /** Policies are always born as drafts — never active on creation. */
    public function policyCreate($data, $actorId = 0)
    {
        $v = Payplex_commission_source::validate($data);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors']); }
        if (!$this->db->table_exists($this->tPolicies())) {
            return array('ok' => false, 'errors' => array('Source policy tables are not installed yet.'));
        }

        $row = $this->policyColumns($data);
        $row['status']     = Payplex_commission_source::DRAFT;
        $row['version']    = isset($data['version']) ? (int) $data['version'] : 1;
        $row['supersedes'] = isset($data['supersedes']) && $data['supersedes'] ? (int) $data['supersedes'] : null;
        $row['created_by'] = (int) $actorId;
        $row['created_at'] = date('Y-m-d H:i:s');

        $this->db->insert($this->tPolicies(), $row);
        $id = (int) $this->db->insert_id();
        $this->ruleAudit(null, 'policy_created', null, Payplex_commission_source::DRAFT, $actorId,
            'Source policy "' . $row['name'] . '" created.', array('policy_id' => $id));

        return array('ok' => true, 'id' => $id, 'errors' => array());
    }

    /**
     * Edit a policy. Draft and submitted policies change in place; anything that
     * has been approved becomes a new version, because commissions may already
     * have been generated under the current one.
     */
    public function policyUpdate($id, $data, $actorId = 0)
    {
        $policy = $this->policyGet($id);
        if (!$policy) { return array('ok' => false, 'errors' => array('Policy not found.')); }

        $merged = array_merge($policy, (array) $data);
        $v = Payplex_commission_source::validate($merged);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors']); }

        $editable = in_array((string) $policy['status'],
            array(Payplex_commission_source::DRAFT, Payplex_commission_source::SUBMITTED), true);

        if ($editable) {
            $row = $this->policyColumns($merged);
            $row['updated_at'] = date('Y-m-d H:i:s');
            $this->db->where('id', (int) $id)->update($this->tPolicies(), $row);
            $this->ruleAudit(null, 'policy_edited', $policy['status'], $policy['status'], $actorId,
                'Source policy #' . (int) $id . ' edited.', array('policy_id' => (int) $id));
            return array('ok' => true, 'id' => (int) $id, 'versioned' => false, 'errors' => array());
        }

        $next = $merged;
        $next['version']    = (int) $policy['version'] + 1;
        $next['supersedes'] = (int) $id;
        $res = $this->policyCreate($next, $actorId);
        if (!empty($res['ok'])) {
            $res['versioned'] = true;
            $this->ruleAudit(null, 'policy_versioned', $policy['status'], Payplex_commission_source::DRAFT, $actorId,
                'New source policy version ' . $next['version'] . ' created from #' . (int) $id
                . '; the approved policy was left untouched.', array('policy_id' => (int) $res['id']));
        }
        return $res;
    }

    public function policyTransition($id, $to, $actorId = 0, $reason = '')
    {
        $policy = $this->policyGet($id);
        if (!$policy) { return array('ok' => false, 'reason' => 'Policy not found.'); }

        $from = (string) $policy['status'];
        if (!Payplex_commission_source::canTransition($from, $to)) {
            return array('ok' => false, 'reason' => 'Cannot move a policy from ' . $from . ' to ' . $to . '.');
        }
        if ($to === Payplex_commission_source::APPROVED) {
            $gate = Payplex_commission_source::canApprove($policy, $actorId);
            if (!$gate['allowed']) { return array('ok' => false, 'reason' => $gate['reason']); }
        }
        if ($from === Payplex_commission_source::SUBMITTED && $to === Payplex_commission_source::DRAFT
            && trim((string) $reason) === '') {
            return array('ok' => false, 'reason' => 'A rejection must include a reason.');
        }

        // exactly one policy governs generation at a time
        if ($to === Payplex_commission_source::ACTIVE) {
            $current = $this->activePolicy();
            if ($current && (int) $current['id'] !== (int) $id) {
                return array('ok' => false, 'reason' => 'Policy #' . (int) $current['id'] . ' ("'
                    . $current['name'] . '") is already active. Pause it before activating this one, so it is '
                    . 'always unambiguous which policy generated a commission.');
            }
        }

        $set = array('status' => $to, 'updated_at' => date('Y-m-d H:i:s'));
        if ($to === Payplex_commission_source::SUBMITTED) { $set['submitted_by'] = (int) $actorId; }
        if ($to === Payplex_commission_source::APPROVED) {
            $set['approved_by'] = (int) $actorId;
            $set['approved_at'] = date('Y-m-d H:i:s');
        }
        if ($to === Payplex_commission_source::ACTIVE) { $set['activated_at'] = date('Y-m-d H:i:s'); }

        $this->db->where('id', (int) $id)->update($this->tPolicies(), $set);
        $this->ruleAudit(null, 'policy_transition', $from, $to, $actorId, $reason, array('policy_id' => (int) $id));

        return array('ok' => true, 'reason' => '');
    }

    /* ---------------- idempotency ledger ---------------- */

    public function paidKeys($limit = 100000)
    {
        if (!$this->db->table_exists($this->tLedger())) { return array(); }
        $rows = $this->db->select('idempotency_key')->limit((int) $limit)->get($this->tLedger())->result();
        $out = array();
        foreach ($rows as $r) { $out[] = $r->idempotency_key; }
        return $out;
    }

    /* ---------------- evaluation: preview and generate ---------------- */

    /**
     * Evaluate source events against the active policy and the rule engine.
     *
     * Preview and generation both come through here and share the SAME
     * evaluation call, so their totals cannot drift apart. $persist decides
     * whether anything financial is written.
     */
    public function evaluateSources($events, $persist = false, $period = null, $actorId = 0)
    {
        $policy = $this->activePolicy();

        $self = $this;
        // Whether test artefacts may pay is decided by the declared environment,
        // not by a hardcoded constant. Unset means production, which refuses them.
        $isProduction = $this->isProduction();
        $resolveRule = function ($context, $on) use ($self, $isProduction) {
            return $self->resolveRule($context, $on, $isProduction);
        };
        $computeCommission = function ($base, $rule, $achieved) {
            return Payplex_commission_calc::compute(
                $base, Payplex_commission_rule::toCalcDefinition($rule), $achieved);
        };

        $evaluation = Payplex_commission_source::evaluate(
            $events,
            $policy ?: array('status' => 'draft', 'events' => array()),
            $resolveRule,
            $computeCommission,
            $persist ? $this->paidKeys() : array()
        );

        $run = array(
            'mode'                  => $persist ? 'generate' : 'preview',
            'period'                => $period,
            'policy_id'             => $policy ? (int) $policy['id'] : null,
            'policy_version'        => $policy ? (int) $policy['version'] : null,
            'eligible_count'        => (int) $evaluation['totals']['eligible_count'],
            'excluded_count'        => (int) $evaluation['totals']['excluded_count'],
            'config_required_count' => (int) $evaluation['totals']['config_required_count'],
            'base_total'            => $evaluation['totals']['base'],
            'commission_total'      => $evaluation['totals']['commission'],
            'statements_created'    => 0,
            'result_json'           => json_encode($evaluation),
            'actor_id'              => (int) $actorId,
            'created_at'            => date('Y-m-d H:i:s'),
        );

        // A preview records the run and stops. No statement, no item, no ledger
        // entry — nothing that could later be mistaken for an earning.
        if (!$persist) {
            if ($this->db->table_exists($this->tRuns())) {
                $this->db->insert($this->tRuns(), $run);
                $evaluation['run_id'] = (int) $this->db->insert_id();
            }
            $evaluation['mode'] = 'preview';
            return $evaluation;
        }

        if (!$policy) {
            $evaluation['mode']  = 'blocked';
            $evaluation['error'] = 'Commission generation is disabled until a source policy has been '
                                 . 'approved and activated.';
            if ($this->db->table_exists($this->tRuns())) {
                $this->db->insert($this->tRuns(), $run);
                $evaluation['run_id'] = (int) $this->db->insert_id();
            }
            return $evaluation;
        }

        return $this->persistEvaluation($evaluation, $run, $policy, $period, $actorId);
    }

    /** Turn an evaluation into statements, items and ledger rows, atomically. */
    private function persistEvaluation($evaluation, $run, $policy, $period, $actorId)
    {
        $period = $period ?: date('Y-m');
        $now    = date('Y-m-d H:i:s');

        $this->db->trans_start();

        $this->db->insert($this->tRuns(), $run);
        $runId = (int) $this->db->insert_id();

        $created = 0;
        foreach (Payplex_commission_source::groupByStaff($evaluation) as $staffId => $group) {
            if ($staffId <= 0) { continue; }

            $exists = $this->db->where('staff_id', (int) $staffId)->where('period', $period)
                ->where_not_in('status', array('superseded'))
                ->count_all_results($this->tStmt);
            if ($exists) { continue; }

            $computed = array(
                'source_policy_id'      => (int) $policy['id'],
                'source_policy_version' => (int) $policy['version'],
                'run_id'                => $runId,
                'rows'                  => $group['rows'],
                'base'                  => $group['base'],
                'gross'                 => $group['commission'],
            );

            $this->db->insert($this->tStmt, array(
                'staff_id'        => (int) $staffId,
                'period'          => $period,
                'rule_version_id' => isset($group['rows'][0]['rule_id']) ? (int) $group['rows'][0]['rule_id'] : null,
                'gross_amount'    => $group['commission'],
                'clawback_amount' => 0,
                'net_amount'      => $group['commission'],
                'currency'        => get_option('payplex_commission_currency') ?: 'INR',
                'computed_json'   => json_encode($computed),
                // Born a draft, with the legacy mirror derived rather than typed.
                'workflow_state'  => Payplex_commission_workflow::GENERATED,
                'status'          => Payplex_commission_workflow::toLegacyStatus(
                                         Payplex_commission_workflow::GENERATED),
                'created_by'      => (int) $actorId,
                'snapshot_hash'   => hash('sha256', json_encode($computed)),
                'created_at'      => $now,
            ));
            $statementId = (int) $this->db->insert_id();
            $created++;

            // §7: the generation itself is audited, with the run it came from.
            $this->statementAudit($statementId, 'generated', null,
                Payplex_commission_workflow::GENERATED, (int) $actorId,
                'Generated for ' . $period . ' by run #' . (int) $runId . '.',
                array('run_id' => (int) $runId, 'rows' => count($group['rows']),
                      'gross' => $group['commission']));

            foreach ($group['rows'] as $row) {
                $this->db->insert($this->tItems, array(
                    'statement_id'      => $statementId,
                    'source_type'       => substr((string) $row['source_type'], 0, 32),
                    'source_id'         => isset($row['source_id']) ? (int) $row['source_id'] : null,
                    'base_amount'       => $row['base_amount'],
                    'commission_amount' => $row['commission_amount'],
                    'breakdown'         => implode('; ', (array) $row['breakdown']),
                    'created_at'        => $now,
                ));

                // the ledger's unique key is what stops a second run paying again
                $this->db->insert($this->tLedger(), array(
                    'idempotency_key'  => $row['idempotency_key'],
                    'source_type'      => substr((string) $row['source_type'], 0, 40),
                    'source_id'        => isset($row['source_id'])  ? (int) $row['source_id']  : null,
                    'payment_id'       => isset($row['payment_id']) ? (int) $row['payment_id'] : null,
                    'staff_id'         => (int) $staffId,
                    'statement_id'     => $statementId,
                    'run_id'           => $runId,
                    'policy_id'        => (int) $policy['id'],
                    'policy_version'   => (int) $policy['version'],
                    'rule_id'          => isset($row['rule_id'])      ? (int) $row['rule_id']      : null,
                    'rule_version'     => isset($row['rule_version']) ? (int) $row['rule_version'] : null,
                    'base_amount'      => $row['base_amount'],
                    'commission_amount'=> $row['commission_amount'],
                    'created_at'       => $now,
                ));
            }
        }

        $this->db->where('id', $runId)->update($this->tRuns(), array('statements_created' => $created));
        $this->db->trans_complete();

        $evaluation['mode']               = 'generate';
        $evaluation['run_id']             = $runId;
        $evaluation['statements_created'] = $created;
        return $evaluation;
    }

    public function generationRuns($limit = 50)
    {
        if (!$this->db->table_exists($this->tRuns())) { return array(); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($this->tRuns())->result();
    }

    public function generationRun($id)
    {
        if (!$this->db->table_exists($this->tRuns())) { return null; }
        return $this->db->where('id', (int) $id)->get($this->tRuns())->row();
    }

    /** Public wrapper over the period parser, for callers outside the model. */
    public function periodRange($period)
    {
        return $this->periodToDateRange($period);
    }

    /**
     * Read Perfex invoices and their payment records READ-ONLY and return neutral
     * source events for the policy engine.
     *
     * Deliberately returns EVERYTHING in the window, including refunded,
     * cancelled and unattributed rows. Filtering is the source policy's job, and
     * excluding rows here would hide them from the preview's "excluded, and here
     * is why" list — which is the whole point of the preview.
     *
     * Built on payment records rather than invoice status, because a payment row
     * is evidence that money was actually received, whereas an invoice status is
     * a workflow state. Perfex core tables are never written to.
     */
    public function collectInvoicePaymentEvents($from, $to)
    {
        $inv = db_prefix() . 'invoices';
        $pay = db_prefix() . 'invoicepaymentrecords';

        if (!$this->db->table_exists($inv) || !$this->db->table_exists($pay)) { return array(); }

        try {
            $rows = $this->db->query(
                "SELECT p.id AS payment_id,
                        p.invoiceid AS source_id,
                        p.amount AS amount_collected,
                        p.date AS occurred_at,
                        i.total AS gross_billed,
                        i.subtotal AS subtotal,
                        i.total_tax AS tax_amount,
                        i.discount_total AS discount_amount,
                        i.status AS invoice_status,
                        i.sale_agent AS staff_id
                 FROM {$pay} p
                 INNER JOIN {$inv} i ON i.id = p.invoiceid
                 WHERE DATE(p.date) BETWEEN ? AND ?
                 ORDER BY p.id ASC",
                array($from, $to)
            )->result_array();
        } catch (\Throwable $e) {
            if (function_exists('log_activity')) {
                @log_activity('Payplex Commission source collection error: ' . $e->getMessage());
            }
            return array();
        }

        $currency = get_option('payplex_commission_currency') ?: 'INR';
        $out = array();

        foreach ($rows as $r) {
            $status = (int) $r['invoice_status'];
            // Perfex invoice statuses: 2 = paid, 3 = partially paid, 5 = cancelled
            $isCancelled = ($status === 5) ? 1 : 0;
            $isPartial   = ($status === 3) ? 1 : 0;

            $out[] = array(
                'source_type'      => $status === 2 ? 'invoice_paid_full' : 'payment_received',
                'source_id'        => (int) $r['source_id'],
                'payment_id'       => (int) $r['payment_id'],
                'staff_id'         => (int) $r['staff_id'],
                'occurred_at'      => $r['occurred_at'],
                'currency'         => $currency,
                'gross_billed'     => (float) $r['gross_billed'],
                'net_billed'       => (float) $r['gross_billed'] - (float) $r['discount_amount'],
                'amount_collected' => (float) $r['amount_collected'],
                'tax_amount'       => (float) $r['tax_amount'],
                'discount_amount'  => (float) $r['discount_amount'],
                'gateway_fee'      => 0,
                'is_refunded'      => 0,
                'is_cancelled'     => $isCancelled,
                'is_partial'       => $isPartial,
                'invoice_status'   => $status,
            );
        }

        return $out;
    }

    /* ================= v0.2.0 — Maker-checker & clawbacks (§2.3, §2.5) ================= */

    private function tStmtAudit() { return db_prefix() . 'payplex_commission_statement_audit'; }
    private function tAdjust()    { return db_prefix() . 'payplex_commission_adjustments'; }
    private function tClawbacks() { return db_prefix() . 'payplex_commission_clawbacks'; }

    /** Append-only statement audit. Never updated, never deleted. */
    /**
     * Append one audit entry. Insert-only, and hash-chained so that an edit
     * made outside this code — straight against the database — cannot be
     * hidden. See Payplex_commission_workflow::verifyAuditChain().
     */
    public function statementAudit($statementId, $action, $from, $to, $actorId, $reason = '', $data = array())
    {
        $this->appendChainedAudit($this->tStmtAudit(), 'statement', array(
            'statement_id'  => (int) $statementId,
            'action'        => substr((string) $action, 0, 40),
            'from_state'    => $from,
            'to_state'      => $to,
            'actor_id'      => (int) $actorId,
            'reason'        => substr((string) $reason, 0, 500),
            'snapshot_hash' => isset($data['snapshot_hash']) ? $data['snapshot_hash'] : null,
            'data_json'     => json_encode($data),
            'occurred_at'   => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * Append one audit entry to any chained log.
     *
     * Written once rather than per table. Two copies of a hash chain is two
     * chances to normalise a field differently, and a chain that disagrees
     * with its own verifier reports honest rows as tampered — which is worse
     * than no chain at all, because people stop believing the alarm.
     */
    private function appendChainedAudit($table, $kind, array $row)
    {
        if (!$this->db->table_exists($table)) { return; }

        // Pre-migration installs have no chain columns; the entry is still
        // recorded, and the verifier reports it as unattested rather than
        // silently treating it as verified.
        if (!$this->db->field_exists('row_hash', $table)) {
            $this->db->insert($table, $row);
            return;
        }

        /*
         * The read of the previous head and the write of the new row have to be
         * one atomic step. Two concurrent appends that both read the same head
         * would produce two rows claiming the same predecessor — a fork that
         * looks exactly like tampering. FOR UPDATE inside a transaction makes
         * the second writer wait. trans_start() nests safely: when the caller
         * already opened a transaction this joins it rather than committing
         * the outer work early.
         */
        $this->db->trans_start();

        $prev = $this->db->query(
            'SELECT `row_hash` FROM `' . $table . '` WHERE `row_hash` IS NOT NULL'
            . ' ORDER BY `id` DESC LIMIT 1 FOR UPDATE'
        )->row();

        $prevHash = ($prev && $prev->row_hash !== null && $prev->row_hash !== '')
            ? (string) $prev->row_hash
            : Payplex_commission_workflow::auditGenesis();

        $row['prev_hash'] = $prevHash;
        $row['row_hash']  = Payplex_commission_workflow::auditHash($prevHash, $row, $kind);

        $this->db->insert($table, $row);
        $this->rememberAuditHead($kind, $row['row_hash']);

        $this->db->trans_complete();
    }

    /** Where the head hash for one audit kind is kept. */
    private function auditHeadOption($kind)
    {
        return self::AUDIT_HEAD_OPTION . ($kind === 'statement' ? '' : '_' . $kind);
    }

    /**
     * Keep the newest row's hash outside the audit table.
     *
     * Without it, deleting the most recent entries leaves a chain that still
     * verifies perfectly — the evidence of the deletion goes with the rows.
     * Holding the head elsewhere makes a truncated tail visible.
     */
    private function rememberAuditHead($kind, $hash)
    {
        if (!function_exists('get_option')) { return; }
        $opt = $this->auditHeadOption($kind);

        // get_option() answers '' for an option that does not exist, so the
        // create/update choice is made on emptiness, not on null.
        $existing = get_option($opt);
        $missing  = ($existing === null || $existing === false || trim((string) $existing) === '');

        if ($missing) {
            if (function_exists('add_option')) { add_option($opt, $hash, 0); }
            return;
        }
        if (function_exists('update_option')) { update_option($opt, $hash); }
    }

    /**
     * Verify one audit chain. Returns the structure documented on
     * Payplex_commission_workflow::verifyAuditChain(), plus 'available'.
     */
    public function verifyAuditChain($kind = 'statement')
    {
        $table = $kind === 'rule' ? $this->tRuleAudit() : $this->tStmtAudit();

        if (!$this->db->table_exists($table) || !$this->db->field_exists('row_hash', $table)) {
            return array('available' => false, 'ok' => false, 'checked' => 0, 'unchained' => 0,
                         'broken_id' => 0, 'tail_proof' => false, 'kind' => $kind,
                         'reason' => 'Audit tamper-evidence is not installed on this database yet.');
        }

        $rows = $this->db->order_by('id', 'ASC')->get($table)->result_array();
        $head = function_exists('get_option') ? get_option($this->auditHeadOption($kind)) : null;

        $res = Payplex_commission_workflow::verifyAuditChain($rows, $head, $kind);
        $res['available'] = true;
        $res['kind']      = $kind;
        return $res;
    }

    public function statementAuditLog($statementId = 0, $limit = 200)
    {
        if (!$this->db->table_exists($this->tStmtAudit())) { return array(); }
        if ($statementId) { $this->db->where('statement_id', (int) $statementId); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($this->tStmtAudit())->result();
    }

    /** Kept for callers written before the verifier covered more than one log. */
    public function verifyStatementAuditChain()
    {
        return $this->verifyAuditChain('statement');
    }

    /**
     * Who can actually exercise a commission capability right now.
     *
     * Assigning a role is done outside this module, in Perfex's staff screen,
     * so "the approver has been set up" is a claim this module cannot make on
     * its own — but it can show whether anyone holds the capability. Without
     * that, a maker-checker split can sit unassigned indefinitely and look
     * configured, because every screen still works: administrators pass the
     * capability check by virtue of being administrators.
     *
     * Administrators are listed separately for exactly that reason. They can
     * open the queue, but they are not a substitute for a checker — the person
     * who generated a statement is still refused at the point of approval.
     *
     * @return array holders => rows, admins => rows
     */
    public function staffWithCapability($capability)
    {
        $out = array('holders' => array(), 'admins' => array());
        $staff = db_prefix() . 'staff';
        $perms = db_prefix() . 'staff_permissions';

        if (!$this->db->table_exists($staff)) { return $out; }

        $out['admins'] = $this->db->select('staffid, firstname, lastname')
            ->where('admin', 1)->where('active', 1)
            ->order_by('staffid', 'ASC')->get($staff)->result_array();

        if (!$this->db->table_exists($perms)) { return $out; }

        $out['holders'] = $this->db->select('s.staffid, s.firstname, s.lastname, s.admin')
            ->from($staff . ' s')
            ->join($perms . ' p', 'p.staff_id = s.staffid', 'inner')
            ->where('p.feature', 'payplex_commission')
            ->where('p.capability', (string) $capability)
            ->where('s.active', 1)
            ->order_by('s.staffid', 'ASC')
            ->get()->result_array();

        return $out;
    }

    /** Statements awaiting a decision, for the approval queue. */
    public function approvalQueue($limit = 200)
    {
        if (!$this->db->field_exists('workflow_state', $this->tStmt)) { return array(); }
        return $this->db->where_in('workflow_state', array('generated', 'under_review', 'approved', 'payable'))
            ->order_by('id', 'DESC')->limit((int) $limit)->get($this->tStmt)->result_array();
    }

    public function statementRow($id)
    {
        $r = $this->db->where('id', (int) $id)->get($this->tStmt)->row_array();
        if (!$r) { return null; }
        if (!isset($r['workflow_state']) || $r['workflow_state'] === '') {
            $r['workflow_state'] = Payplex_commission_workflow::fromLegacyStatus($r['status']);
        }
        return $r;
    }

    /**
     * Move a statement through the maker-checker lifecycle.
     *
     * Every gate lives in the pure library and is enforced here regardless of
     * what the UI offered, because a hidden button is not a control.
     */
    /**
     * The commission capabilities one staff member holds.
     *
     * An administrator passes every gate on is_admin(), so they are reported as
     * holding the lot — anything else would refuse the one account that
     * certainly may act.
     */
    public function capabilitiesOf($staffId)
    {
        $staffId = (int) $staffId;
        if ($staffId <= 0) { return array(); }

        $staff = $this->db->select('admin')->where('staffid', $staffId)
                          ->get(db_prefix() . 'staff')->row();
        if ($staff && (int) $staff->admin === 1) {
            return array_values(array_unique(array_values(Payplex_commission_workflow::stateCapabilities())));
        }

        $out = array();
        foreach ($this->db->select('capability')
                          ->where('staff_id', $staffId)
                          ->where('feature', 'payplex_commission')
                          ->get(db_prefix() . 'staff_permissions')->result_array() as $r) {
            $out[] = $r['capability'];
        }
        return $out;
    }

    public function statementTransition($id, $to, $actorId = 0, $reason = '')
    {
        $s = $this->statementRow($id);
        if (!$s) { return array('ok' => false, 'reason' => 'Statement not found.'); }

        /*
         * The capability is now supplied, so the model enforces it rather than
         * trusting that every caller reached here through the controller. The
         * controller's own check stays where it is: two independent refusals
         * are the point, not a duplication to tidy away.
         */
        $gate = Payplex_commission_workflow::canAct($s, $to, $actorId, $this->beneficiariesFor($s),
            $reason, $this->capabilitiesOf($actorId));
        if (!$gate['allowed']) { return array('ok' => false, 'code' => $gate['code'], 'reason' => $gate['reason']); }

        /*
         * Who may act is only half the question. What the money rests on is the
         * other half, and nothing asked it: a statement computed under a test
         * or deactivated rule could be walked all the way to paid because every
         * gate above is about the actor.
         */
        $ruleGate = Payplex_commission_rule::statementGate(
            $this->ruleBehindStatement($s), $this->isProduction(), $to, $s['period']);
        if (!$ruleGate['allowed']) {
            return array('ok' => false, 'code' => $ruleGate['code'], 'reason' => $ruleGate['reason']);
        }

        $from = (string) $s['workflow_state'];
        $now  = date('Y-m-d H:i:s');

        $set = array(
            'workflow_state' => $to,
            'status'         => Payplex_commission_workflow::toLegacyStatus($to),
            'updated_at'     => $now,
        );
        switch ($to) {
            case Payplex_commission_workflow::UNDER_REVIEW:
                $set['reviewed_by'] = (int) $actorId; $set['reviewed_at'] = $now; break;
            case Payplex_commission_workflow::APPROVED:
                $set['approved_by'] = (int) $actorId; $set['approved_at'] = $now;
                $set['edited_since_approval'] = 0; break;
            case Payplex_commission_workflow::PAYABLE:
                $set['payable_at'] = $now; break;
            case Payplex_commission_workflow::EXPORTED:
                $set['exported_at'] = $now; break;
            case Payplex_commission_workflow::PAID:
                $set['paid_at'] = $now; break;
        }

        $this->db->trans_start();
        $this->db->where('id', (int) $id)->update($this->tStmt, $set);
        $this->statementAudit($id, 'transition', $from, $to, $actorId, $reason,
            array('snapshot_hash' => isset($s['snapshot_hash']) ? $s['snapshot_hash'] : null));
        $this->db->trans_complete();

        return array('ok' => true, 'reason' => '');
    }

    /**
     * The rule a statement was computed under, normalised to one shape.
     *
     * `rule_version_id` means two different tables depending on which generator
     * wrote the row: the retired legacy path stored an id from
     * payplex_commission_rule_versions, the policy path stores one from
     * payplex_commission_rules. Both still exist on this install — statement #1
     * points at the legacy table — so both are looked up, builder first.
     *
     * A legacy row has no status column; `active` is the whole of its
     * lifecycle, so it is mapped onto the builder vocabulary rather than being
     * judged by a rule written for a different shape. Legacy rows carry no
     * is_test flag either: the ones that were test rules were deactivated by
     * retireLegacyTestRules(), so inactive is the signal that survives.
     *
     * Returns null when neither table knows the id — which the gate treats as a
     * refusal, not as permission.
     */
    public function ruleBehindStatement($statement)
    {
        $s  = (array) $statement;
        $id = (int) (isset($s['rule_version_id']) ? $s['rule_version_id'] : 0);
        if ($id <= 0) { return null; }

        $builder = null;
        if ($this->db->table_exists($this->tRuleBuilder())) {
            $builder = $this->db->where('id', $id)->get($this->tRuleBuilder())->row_array() ?: null;
        }

        $legacyRow = null;
        if ($this->db->table_exists($this->tRules)) {
            $legacyRow = $this->db->where('id', $id)->get($this->tRules)->row_array() ?: null;
        }
        $legacy = $legacyRow === null ? null : array(
            'id'        => $legacyRow['id'],
            'name'      => isset($legacyRow['name']) ? $legacyRow['name'] : null,
            'rule_code' => null,
            'status'    => ((int) (isset($legacyRow['active']) ? $legacyRow['active'] : 0) === 1)
                           ? Payplex_commission_rule::ACTIVE
                           : 'inactive',
            'is_test'   => 0,
            'effective_from' => null,
            'effective_to'   => null,
            'legacy'    => true,
        );

        if ($builder === null && $legacy === null) { return null; }
        if ($builder === null) { return $legacy; }
        if ($legacy === null)  { return $builder; }

        /*
         * Both tables hold a row with this id, and that is not hypothetical:
         * on staging, id 2 is legacy "TEST Accelerator 5pct x1.25" AND builder
         * "DUMMY-FLAT 4%". Statement #1 was computed by the first. Preferring
         * the builder row by position — which is what this method did when it
         * was first written — judged a 5% statement against an unrelated 4%
         * rule and let it through.
         *
         * The statement carries the rule it was computed under inside
         * computed_json, so ask that rather than guess. An exact structural
         * match with the legacy rule's stored definition identifies it; nothing
         * weaker is accepted, because a near-match on a money figure is not
         * identification.
         */
        $computed = isset($s['computed_json']) ? $s['computed_json'] : null;
        if (is_string($computed)) { $computed = json_decode($computed, true); }
        $computedRule = is_array($computed) && isset($computed['rule']) ? $computed['rule'] : null;

        if ($computedRule !== null && isset($legacyRow['rule_json'])) {
            $legacyDef = json_decode((string) $legacyRow['rule_json'], true);
            if (is_array($legacyDef) && $this->sameRuleDefinition($computedRule, $legacyDef)) {
                return $legacy;
            }
        }

        /*
         * The statement does not say which one produced it. Refusing is the
         * only honest answer: approving on the wrong rule is how a figure gets
         * ratified against a rate that never applied to it.
         */
        return array('_ambiguous' => true, 'id' => $id,
                     'candidates' => array(
                         isset($legacy['name']) ? $legacy['name'] : 'legacy rule #' . $id,
                         isset($builder['rule_code']) ? $builder['rule_code'] : 'rule #' . $id,
                     ));
    }

    /** Two rule definitions are the same rule only if they match exactly. */
    private function sameRuleDefinition($a, $b)
    {
        if (!is_array($a) || !is_array($b)) { return false; }
        $norm = function ($x) use (&$norm) {
            if (!is_array($x)) { return $x; }
            ksort($x);
            foreach ($x as $k => $v) { $x[$k] = $norm($v); }
            return $x;
        };
        return json_encode($norm($a)) === json_encode($norm($b));
    }

    /** Everyone who earns from a statement — used by the self-approval gate. */
    public function beneficiariesFor($statement)
    {
        $s = (array) $statement;
        $out = array();
        if ($this->db->table_exists($this->tLedger()) && !empty($s['id'])) {
            $rows = $this->db->select('staff_id')->where('statement_id', (int) $s['id'])
                ->get($this->tLedger())->result();
            foreach ($rows as $r) { $out[] = (int) $r->staff_id; }
        }
        return $out;
    }

    /* ---------------- adjustments ---------------- */

    public function adjustmentsFor($statementId)
    {
        if (!$this->db->table_exists($this->tAdjust())) { return array(); }
        return $this->db->where('statement_id', (int) $statementId)
            ->order_by('id', 'ASC')->get($this->tAdjust())->result_array();
    }

    /**
     * Add an adjustment. This is the ONLY way a frozen statement changes: the
     * statement row itself is never rewritten, only its running totals.
     */
    public function addAdjustment($statementId, $data, $actorId = 0)
    {
        $s = $this->statementRow($statementId);
        if (!$s) { return array('ok' => false, 'errors' => array('Statement not found.')); }

        $v = Payplex_commission_workflow::validateAdjustment($s, $data);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors']); }

        // an employee cannot write an adjustment on their own commission
        if (in_array((int) $actorId, Payplex_commission_workflow::beneficiariesOf($s, $this->beneficiariesFor($s)), true)) {
            return array('ok' => false, 'errors' => array('You earn commission on this statement, so you cannot adjust it.'));
        }

        $now = date('Y-m-d H:i:s');
        $this->db->trans_start();

        $this->db->insert($this->tAdjust(), array(
            'statement_id' => (int) $statementId,
            'staff_id'     => (int) $s['staff_id'],
            'type'         => (string) $data['type'],
            'amount'       => round((float) $data['amount'], 2),
            'reason'       => substr((string) $data['reason'], 0, 500),
            'clawback_id'  => isset($data['clawback_id']) ? (int) $data['clawback_id'] : null,
            'status'       => 'approved',
            'created_by'   => (int) $actorId,
            'created_at'   => $now,
        ));
        $adjId = (int) $this->db->insert_id();

        $this->recalcStatementTotals($statementId);
        $this->statementAudit($statementId, 'adjustment', $s['workflow_state'], $s['workflow_state'],
            $actorId, (string) $data['reason'],
            array('adjustment_id' => $adjId, 'amount' => (float) $data['amount'], 'type' => $data['type']));

        $this->db->trans_complete();
        return array('ok' => true, 'id' => $adjId, 'errors' => array());
    }

    /**
     * Recompute the running totals. gross_amount is NEVER touched — what was
     * earned is a historical fact. Only clawback_amount, adjustment_total and
     * net_amount move.
     */
    public function recalcStatementTotals($statementId)
    {
        $s = $this->statementRow($statementId);
        if (!$s) { return; }

        $adjustments = $this->adjustmentsFor($statementId);
        $clawTotal = 0.0;
        $adjTotal  = 0.0;
        foreach ($adjustments as $a) {
            if ((string) $a['status'] === 'rejected') { continue; }
            $amt = (float) $a['amount'];
            $adjTotal += $amt;
            if ((string) $a['type'] === 'clawback' && $amt < 0) { $clawTotal += abs($amt); }
        }

        $net = round((float) $s['gross_amount'] + $adjTotal, 2);
        if ($net < 0) { $net = 0.0; }

        $this->db->where('id', (int) $statementId)->update($this->tStmt, array(
            'clawback_amount'  => round($clawTotal, 2),
            'adjustment_total' => round($adjTotal, 2),
            'net_amount'       => $net,
            'updated_at'       => date('Y-m-d H:i:s'),
        ));
    }

    /* ---------------- clawbacks ---------------- */

    public function clawbacks($status = null, $limit = 200)
    {
        if (!$this->db->table_exists($this->tClawbacks())) { return array(); }
        if ($status) { $this->db->where('status', $status); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($this->tClawbacks())->result_array();
    }

    public function clawbackGet($id)
    {
        if (!$this->db->table_exists($this->tClawbacks())) { return null; }
        $r = $this->db->where('id', (int) $id)->get($this->tClawbacks())->row_array();
        return $r ?: null;
    }

    public function clawbacksForLedger($ledgerId)
    {
        if (!$this->db->table_exists($this->tClawbacks())) { return array(); }
        return $this->db->where('original_ledger_id', (int) $ledgerId)->get($this->tClawbacks())->result_array();
    }

    public function ledgerEntry($id)
    {
        if (!$this->db->table_exists($this->tLedger())) { return null; }
        $r = $this->db->where('id', (int) $id)->get($this->tLedger())->row_array();
        return $r ?: null;
    }

    public function ledgerEntries($limit = 200)
    {
        if (!$this->db->table_exists($this->tLedger())) { return array(); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($this->tLedger())->result_array();
    }

    /**
     * Raise a clawback against a ledger entry. The original entry is referenced
     * and left completely untouched; the clawback is a new immutable record.
     *
     * Idempotent: the same triggering record cannot recover twice, enforced both
     * here and by a UNIQUE index.
     */
    public function raiseClawback($ledgerId, $params, $actorId = 0)
    {
        $original = $this->ledgerEntry($ledgerId);
        if (!$original) { return array('ok' => false, 'errors' => array('Original commission entry not found.')); }
        if (!$this->db->table_exists($this->tClawbacks())) {
            return array('ok' => false, 'errors' => array('Clawback tables are not installed yet.'));
        }

        $existing  = $this->clawbacksForLedger($ledgerId);
        $already   = Payplex_commission_clawback::totalClawedBack($existing);

        $built = Payplex_commission_clawback::build($original, $params, $already);
        if (empty($built['ok'])) { return array('ok' => false, 'errors' => $built['errors']); }

        $row = $built['row'];

        // idempotency: the same trigger against the same entry recovers once
        $dupe = $this->db->where('idempotency_key', $row['idempotency_key'])
            ->get($this->tClawbacks())->row();
        if ($dupe) {
            return array('ok' => false, 'duplicate' => true, 'id' => (int) $dupe->id,
                'errors' => array('A clawback for this exact triggering record already exists (#' . (int) $dupe->id . '). '
                                . 'Nothing was recovered twice.'));
        }

        $row['created_by'] = (int) $actorId;
        $row['created_at'] = date('Y-m-d H:i:s');

        $this->db->insert($this->tClawbacks(), $row);
        $id = (int) $this->db->insert_id();

        if (!empty($row['statement_id'])) {
            $this->statementAudit((int) $row['statement_id'], 'clawback_raised', null, null, $actorId,
                $row['reason'], array('clawback_id' => $id, 'amount' => $row['amount'],
                    'trigger' => $row['trigger_event'], 'trigger_ref' => $row['trigger_ref']));
        }

        return array('ok' => true, 'id' => $id, 'amount' => $row['amount'],
            'capped' => !empty($built['calc']['capped']), 'errors' => array());
    }

    /**
     * Decide a clawback. Approving to 'recovered' posts the matching adjustment
     * against the statement — which is what actually reduces the net payable.
     */
    public function decideClawback($id, $to, $actorId = 0, $reason = '')
    {
        $c = $this->clawbackGet($id);
        if (!$c) { return array('ok' => false, 'reason' => 'Clawback not found.'); }

        $gate = Payplex_commission_clawback::canDecide($c, $to, $actorId, $reason);
        if (!$gate['allowed']) { return array('ok' => false, 'reason' => $gate['reason']); }

        $now = date('Y-m-d H:i:s');

        $this->db->trans_start();
        $this->db->where('id', (int) $id)->update($this->tClawbacks(), array(
            'status'        => $to,
            'reviewed_by'   => (int) $actorId,
            'reviewed_at'   => $now,
            'review_reason' => substr((string) $reason, 0, 500),
        ));

        if ($to === Payplex_commission_clawback::RECOVERED && !empty($c['statement_id'])) {
            $already = $this->db->where('clawback_id', (int) $id)->count_all_results($this->tAdjust());
            if (!$already) {
                $this->db->insert($this->tAdjust(), array(
                    'statement_id' => (int) $c['statement_id'],
                    'staff_id'     => (int) $c['staff_id'],
                    'type'         => 'clawback',
                    'amount'       => -1 * round((float) $c['amount'], 2),
                    'reason'       => 'Clawback #' . (int) $id . ' (' . $c['trigger_event'] . ' ' . $c['trigger_ref'] . '): '
                                    . (string) $c['reason'],
                    'clawback_id'  => (int) $id,
                    'status'       => 'approved',
                    'created_by'   => (int) $actorId,
                    'created_at'   => $now,
                ));
                $this->recalcStatementTotals((int) $c['statement_id']);
            }
            $this->statementAudit((int) $c['statement_id'], 'clawback_recovered', null, null, $actorId, $reason,
                array('clawback_id' => (int) $id, 'amount' => (float) $c['amount']));
        }
        $this->db->trans_complete();

        return array('ok' => true, 'reason' => '');
    }

    /** Earned / recovered / net for one staff member. Gross is never reduced. */
    public function clawbackSummaryFor($staffId)
    {
        $gross = 0.0;
        if ($this->db->table_exists($this->tStmt)) {
            $r = $this->db->select('SUM(gross_amount) AS g')->where('staff_id', (int) $staffId)
                ->where_not_in('status', array('superseded'))->get($this->tStmt)->row();
            $gross = $r && $r->g ? (float) $r->g : 0.0;
        }
        $claws = array();
        if ($this->db->table_exists($this->tClawbacks())) {
            $claws = $this->db->where('staff_id', (int) $staffId)->get($this->tClawbacks())->result_array();
        }
        return Payplex_commission_clawback::summarise($gross, $claws);
    }

    /* ================= v0.2.0 — Payout batches & export (§2.4) ================= */

    private function tBatches() { return db_prefix() . 'payplex_commission_payout_batches'; }
    private function tPItems()  { return db_prefix() . 'payplex_commission_payout_items'; }
    private function tPEvents() { return db_prefix() . 'payplex_commission_payout_events'; }

    public function payoutEvent($batchId, $itemId, $action, $from, $to, $actorId, $reason = '', $data = array())
    {
        if (!$this->db->table_exists($this->tPEvents())) { return; }
        $this->db->insert($this->tPEvents(), array(
            'batch_id'    => $batchId ? (int) $batchId : null,
            'item_id'     => $itemId ? (int) $itemId : null,
            'action'      => substr((string) $action, 0, 40),
            'from_state'  => $from,
            'to_state'    => $to,
            'actor_id'    => (int) $actorId,
            'reason'      => substr((string) $reason, 0, 500),
            'data_json'   => json_encode($data),
            'occurred_at' => date('Y-m-d H:i:s'),
        ));
    }

    public function payoutEvents($batchId = 0, $limit = 100)
    {
        if (!$this->db->table_exists($this->tPEvents())) { return array(); }
        if ($batchId) { $this->db->where('batch_id', (int) $batchId); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($this->tPEvents())->result();
    }

    public function payoutBatches($limit = 100)
    {
        if (!$this->db->table_exists($this->tBatches())) { return array(); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($this->tBatches())->result_array();
    }

    public function payoutBatch($id)
    {
        if (!$this->db->table_exists($this->tBatches())) { return null; }
        $r = $this->db->where('id', (int) $id)->get($this->tBatches())->row_array();
        return $r ?: null;
    }

    public function payoutItems($batchId)
    {
        if (!$this->db->table_exists($this->tPItems())) { return array(); }
        return $this->db->where('batch_id', (int) $batchId)->order_by('id', 'ASC')
            ->get($this->tPItems())->result_array();
    }

    public function payoutItem($id)
    {
        if (!$this->db->table_exists($this->tPItems())) { return null; }
        $r = $this->db->where('id', (int) $id)->get($this->tPItems())->row_array();
        return $r ?: null;
    }

    /** Statements that are payable and not already in an open payout batch. */
    public function payableStatements($period)
    {
        if (!$this->db->field_exists('workflow_state', $this->tStmt)) { return array(); }

        $taken = array();
        if ($this->db->table_exists($this->tPItems())) {
            $rows = $this->db->select('statement_id')
                ->join($this->tBatches() . ' b', 'b.id = ' . $this->tPItems() . '.batch_id', 'left')
                ->where_not_in('b.status', array('cancelled'))
                ->get($this->tPItems())->result();
            foreach ($rows as $r) { if ($r->statement_id) { $taken[] = (int) $r->statement_id; } }
        }

        $this->db->where('workflow_state', Payplex_commission_workflow::PAYABLE);
        if ($period) { $this->db->where('period', $period); }
        if ($taken) { $this->db->where_not_in('id', $taken); }
        return $this->db->order_by('id', 'ASC')->get($this->tStmt)->result_array();
    }

    /**
     * Beneficiary bank details for a payout item.
     *
     * Reads the staff module's profile when present and returns the MASKED
     * account plus a verified reference. The full number is never read into this
     * module — there is no code path here that could leak what it does not hold.
     */
    public function beneficiaryDetails($staffId)
    {
        $out = array(
            'beneficiary_name' => '', 'masked_account' => '', 'account_ref' => '',
            'bank_name' => '', 'ifsc' => '', 'bank_verified' => 0,
            'tds_rate' => null, 'tds_exempt' => 0,
        );

        /*
         * The bank store, added in Module 8.
         *
         * Before it existed this method returned an empty IFSC for every person
         * alive — the whole database had no IFSC column — so validateBankDetails()
         * answered `no_ifsc` and no batch could ever be approved. The gate was
         * never wrong; it had nothing to read. This is what it reads now.
         */
        $accounts = db_prefix() . 'wf_bank_accounts';
        if ($this->db->table_exists($accounts)) {
            $a = $this->db->where('staff_id', (int) $staffId)->where('is_current', 1)
                ->order_by('id', 'DESC')->limit(1)->get($accounts)->row_array();
            /* The model fetches; the library decides. Verification is computed
               from the row rather than trusted, and the rule is testable
               without a database. */
            $b = Payplex_commission_payout::beneficiaryFromAccount((array) $a, (int) $staffId);
            unset($b['unverified_reason']);
            foreach ($b as $k => $v) { if ($v !== '' && $v !== 0) { $out[$k] = $v; } }
            if ($a) { $out['bank_verified'] = (int) $b['bank_verified']; }
        }

        $profiles = db_prefix() . 'payplex_staff_profiles';
        if ($out['ifsc'] === '' && $this->db->table_exists($profiles)) {
            $p = $this->db->where('staff_id', (int) $staffId)->where('is_current', 1)
                ->limit(1)->get($profiles)->row();
            if ($p) {
                $out['beneficiary_name'] = (string) $p->full_name;
                $out['bank_verified']    = (int) $p->bank_verified;
                // bank_enc is encrypted at rest and is deliberately NOT decrypted
                // here; a verified reference is all a masked export needs.
                $out['account_ref']      = $p->bank_enc ? ('BANKREF-' . substr(sha1((string) $p->bank_enc), 0, 12)) : '';
            }
        }

        $staff = db_prefix() . 'staff';
        if ($out['beneficiary_name'] === '' && $this->db->table_exists($staff)) {
            $s = $this->db->where('staffid', (int) $staffId)->limit(1)->get($staff)->row();
            if ($s) { $out['beneficiary_name'] = trim((string) $s->firstname . ' ' . (string) $s->lastname); }
        }

        return $out;
    }

    /**
     * Assemble a draft payout batch from payable statements. Items that cannot
     * be paid are still added, flagged, so the preparer can see and fix them
     * rather than wondering why someone is missing.
     */
    public function createPayoutBatch($period, $actorId = 0)
    {
        if (!$this->db->table_exists($this->tBatches())) {
            return array('ok' => false, 'errors' => array('Payout tables are not installed yet.'));
        }

        $statements = $this->payableStatements($period);
        $claims     = $this->payableExpenseClaims();

        if (!$statements && !$claims) {
            return array('ok' => false, 'errors' => array(
                'Nothing payable for ' . $period . '. A commission statement must be approved and '
                . 'marked payable, or an expense claim approved, before it can be paid.'));
        }

        $now = date('Y-m-d H:i:s');

        $this->db->trans_start();
        $this->db->insert($this->tBatches(), array(
            'reference'  => 'PENDING',
            'period'     => $period,
            'currency'   => get_option('payplex_commission_currency') ?: 'INR',
            'status'     => Payplex_commission_payout::DRAFT,
            'created_by' => (int) $actorId,
            'created_at' => $now,
        ));
        $batchId = (int) $this->db->insert_id();
        $reference = Payplex_commission_payout::makeReference($period, $batchId);
        $this->db->where('id', $batchId)->update($this->tBatches(), array('reference' => $reference));

        $gross = $tds = $net = 0.0; $count = 0;

        /*
         * Two sources, one engine.
         *
         * A commission statement is income and is taxed as configured. An
         * expense reimbursement is repaying money the person already spent —
         * not income, so no TDS — and `tds_exempt` says so on the row rather
         * than being inferred by whoever reads the export later.
         */
        $sources = array();
        foreach ($statements as $s) {
            $sources[] = array(
                'source_type'  => Payplex_commission_payout::SOURCE_STATEMENT,
                'source_id'    => (int) $s['id'],
                'statement_id' => (int) $s['id'],
                'staff_id'     => (int) $s['staff_id'],
                'gross'        => (float) $s['net_amount'],
                'payable_kind' => 'commission',
                'tds_exempt'   => 0,
            );
        }
        foreach ($claims as $c) {
            $kind   = (string) $c['payable_kind'];
            $exempt = ($kind === 'reimbursement' || $kind === 'recovery') ? 1 : 0;
            $sources[] = array(
                'source_type'  => Payplex_commission_payout::SOURCE_CLAIM,
                'source_id'    => (int) $c['id'],
                'statement_id' => null,
                'staff_id'     => (int) $c['claimant_id'],
                'gross'        => (float) $c['amount'],
                'payable_kind' => $kind,
                'tds_exempt'   => $exempt,
            );
        }

        $claimIds = array();
        foreach ($sources as $s) {
            $b = $this->beneficiaryDetails((int) $s['staff_id']);
            $item = array_merge($b, array(
                'batch_id'      => $batchId,
                'statement_id'  => $s['statement_id'],
                'source_type'   => $s['source_type'],
                'source_id'     => $s['source_id'],
                'payable_kind'  => $s['payable_kind'],
                'staff_id'      => (int) $s['staff_id'],
                'gross_payable' => (float) $s['gross'],
                'other_deductions' => 0,
                'status'        => Payplex_commission_payout::ITEM_PENDING,
                'created_at'    => $now,
            ));
            if ($s['tds_exempt']) { $item['tds_exempt'] = 1; $item['tds_rate'] = null; }

            $calc = Payplex_commission_payout::computeNet($item);
            $item['tds_amount']  = $calc['ok'] ? $calc['tds'] : 0;
            $item['net_payable'] = $calc['ok'] ? $calc['net'] : 0;

            /*
             * UNIQUE (batch_id, source_type, source_id) means a duplicate line
             * fails here at the database rather than depending on the query above
             * having been right. A failed insert is skipped, not fatal: the
             * constraint has done its job.
             */
            if (!$this->db->insert($this->tPItems(), $item)) { continue; }
            $itemId = (int) $this->db->insert_id();
            if ($itemId <= 0) { continue; }

            if ($s['source_type'] === Payplex_commission_payout::SOURCE_CLAIM) {
                $claimIds[$s['source_id']] = $itemId;
            }
            $count++;
            $gross += (float) $item['gross_payable'];
            $tds   += (float) $item['tds_amount'];
            $net   += (float) $item['net_payable'];
        }
        foreach ($claimIds as $claimId => $itemId) {
            $this->linkClaimToPayoutItem((int) $claimId, (int) $itemId, (int) $actorId);
        }

        $this->db->where('id', $batchId)->update($this->tBatches(), array(
            'item_count'  => $count,
            'gross_total' => round($gross, 2),
            'tds_total'   => round($tds, 2),
            'net_total'   => round($net, 2),
            'updated_at'  => $now,
        ));

        $this->payoutEvent($batchId, null, 'created', null, Payplex_commission_payout::DRAFT, $actorId,
            'Assembled from ' . $count . ' payable statement(s).', array('reference' => $reference));
        $this->db->trans_complete();

        $blocked = Payplex_commission_payout::blockingIssues($this->payoutItems($batchId));

        return array('ok' => true, 'id' => $batchId, 'reference' => $reference,
            'count' => $count, 'blocked' => $blocked, 'errors' => array());
    }

    /** Update an item's payable configuration (TDS treatment, deductions). */
    public function updatePayoutItem($itemId, $data, $actorId = 0)
    {
        $item = $this->payoutItem($itemId);
        if (!$item) { return array('ok' => false, 'reason' => 'Item not found.'); }

        $batch = $this->payoutBatch((int) $item['batch_id']);
        if (!$batch || !in_array((string) $batch['status'],
            array(Payplex_commission_payout::DRAFT, Payplex_commission_payout::SUBMITTED), true)) {
            return array('ok' => false, 'reason' => 'Items can only be changed while the batch is draft or submitted.');
        }

        $set = array();
        if (array_key_exists('tds_rate', $data)) {
            $set['tds_rate'] = ($data['tds_rate'] === '' || $data['tds_rate'] === null) ? null : (float) $data['tds_rate'];
        }
        if (array_key_exists('tds_exempt', $data))       { $set['tds_exempt'] = !empty($data['tds_exempt']) ? 1 : 0; }
        if (array_key_exists('other_deductions', $data)) { $set['other_deductions'] = (float) $data['other_deductions']; }
        if (array_key_exists('details_corrected', $data)){ $set['details_corrected'] = !empty($data['details_corrected']) ? 1 : 0; }

        $merged = array_merge($item, $set);
        $calc = Payplex_commission_payout::computeNet($merged);
        $set['tds_amount']  = $calc['ok'] ? $calc['tds'] : 0;
        $set['net_payable'] = $calc['ok'] ? $calc['net'] : 0;

        $this->db->where('id', (int) $itemId)->update($this->tPItems(), $set);
        $this->recalcPayoutBatch((int) $item['batch_id']);
        $this->payoutEvent((int) $item['batch_id'], (int) $itemId, 'item_updated', null, null, $actorId, '', $set);

        return array('ok' => true, 'reason' => '');
    }

    public function recalcPayoutBatch($batchId)
    {
        $items = $this->payoutItems($batchId);
        $gross = $tds = $net = 0.0;
        foreach ($items as $i) {
            $gross += (float) $i['gross_payable'];
            $tds   += (float) $i['tds_amount'];
            $net   += (float) $i['net_payable'];
        }
        $this->db->where('id', (int) $batchId)->update($this->tBatches(), array(
            'item_count'  => count($items),
            'gross_total' => round($gross, 2),
            'tds_total'   => round($tds, 2),
            'net_total'   => round($net, 2),
            'updated_at'  => date('Y-m-d H:i:s'),
        ));
    }

    /** Move a payout batch, enforcing every gate server-side. */
    public function payoutTransition($batchId, $to, $actorId = 0, $reason = '')
    {
        $batch = $this->payoutBatch($batchId);
        if (!$batch) { return array('ok' => false, 'reason' => 'Batch not found.'); }

        $items = $this->payoutItems($batchId);
        $gate  = Payplex_commission_payout::canAct($batch, $to, $actorId, $items, $reason);
        if (!$gate['allowed']) { return array('ok' => false, 'code' => $gate['code'], 'reason' => $gate['reason']); }

        $from = (string) $batch['status'];
        $now  = date('Y-m-d H:i:s');
        $set  = array('status' => $to, 'updated_at' => $now);

        switch ($to) {
            case Payplex_commission_payout::SUBMITTED:
                $set['submitted_by'] = (int) $actorId; break;
            case Payplex_commission_payout::APPROVED:
                $set['approved_by'] = (int) $actorId;
                $set['approved_at'] = $now;
                $set['approval_reference'] = 'APR-' . (int) $batchId . '-' . (int) $actorId;
                break;
            case Payplex_commission_payout::EXPORTED:
                $set['exported_by'] = (int) $actorId; $set['exported_at'] = $now; break;
            case Payplex_commission_payout::SETTLED:
                $set['settled_at'] = $now; break;
        }

        $this->db->trans_start();
        $this->db->where('id', (int) $batchId)->update($this->tBatches(), $set);
        $this->payoutEvent($batchId, null, 'transition', $from, $to, $actorId, $reason);

        // moving statements along with the batch keeps the two consistent
        if ($to === Payplex_commission_payout::EXPORTED) {
            foreach ($items as $i) {
                if ((string) (isset($i['source_type']) ? $i['source_type'] : '')
                        === Payplex_commission_payout::SOURCE_CLAIM) {
                    $this->setClaimStateFromPayout((int) $i['id'], 'payout_processing', $actorId,
                        'Exported in batch ' . $batch['reference']);
                }
                if (!empty($i['statement_id'])) {
                    $this->statementTransition((int) $i['statement_id'],
                        Payplex_commission_workflow::EXPORTED, $actorId, 'Exported in batch ' . $batch['reference']);
                }
            }
        }
        $this->db->trans_complete();

        return array('ok' => true, 'reason' => '');
    }

    /** Build the masked export for a batch. */
    public function buildPayoutExport($batchId)
    {
        $batch = $this->payoutBatch($batchId);
        if (!$batch) { return array('ok' => false, 'reason' => 'Batch not found.', 'rows' => array()); }
        return Payplex_commission_payout::buildExport($batch, $this->payoutItems($batchId));
    }

    /**
     * Record a REAL settlement reported by a person. Nothing here contacts a
     * bank; this is the human writing down what the bank actually did.
     */
    public function recordSettlement($itemId, $params, $actorId = 0)
    {
        $item = $this->payoutItem($itemId);
        if (!$item) { return array('ok' => false, 'errors' => array('Item not found.')); }

        $batch = $this->payoutBatch((int) $item['batch_id']);
        if (!$batch || (string) $batch['status'] !== Payplex_commission_payout::EXPORTED) {
            return array('ok' => false, 'errors' => array(
                'Settlement can only be recorded against an exported batch.'));
        }
        if ((int) $item['staff_id'] === (int) $actorId) {
            return array('ok' => false, 'errors' => array(
                'You are the beneficiary of this item, so you cannot mark it paid.'));
        }

        $v = Payplex_commission_payout::validateSettlement($item, $params);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors']); }

        /*
         * The same bank reference twice.
         *
         * Found by testing, not by review: the UNIQUE index added in migration
         * 009 does refuse a duplicate UTR — but the refusal surfaced as an
         * unhandled database error, which to a finance officer is a white page,
         * not an answer. The constraint stays as the thing that actually
         * guarantees it; this is the sentence that explains it.
         */
        $ref = trim((string) $params['bank_reference']);
        $clash = $this->db->select('id, batch_id')->where('bank_reference', $ref)
            ->where('id !=', (int) $itemId)->limit(1)->get($this->tPItems())->row();
        if ($clash) {
            return array('ok' => false, 'errors' => array(
                'Bank reference "' . $ref . '" is already recorded against payout item #'
                . (int) $clash->id . '. One transfer cannot pay two people, and recording it '
                . 'twice is how a single payment comes to look like two.'));
        }

        $now = date('Y-m-d H:i:s');
        $this->db->trans_start();
        $this->db->where('id', (int) $itemId)->update($this->tPItems(), array(
            'status'         => Payplex_commission_payout::ITEM_PAID,
            'bank_reference' => substr((string) $params['bank_reference'], 0, 80),
            'paid_amount'    => round((float) $params['paid_amount'], 2),
            'paid_at'        => !empty($params['paid_at']) ? date('Y-m-d H:i:s', strtotime((string) $params['paid_at'])) : $now,
        ));
        $this->payoutEvent((int) $item['batch_id'], (int) $itemId, 'settled', $item['status'],
            Payplex_commission_payout::ITEM_PAID, $actorId, '',
            array('bank_reference' => $params['bank_reference'], 'paid_amount' => (float) $params['paid_amount']));

        if (!empty($item['statement_id'])) {
            $this->statementTransition((int) $item['statement_id'], Payplex_commission_workflow::PAID,
                $actorId, 'Settled, bank reference ' . $params['bank_reference']);
        }
        $this->setClaimStateFromPayout((int) $itemId, 'paid', $actorId,
            'Settled, bank reference ' . $params['bank_reference']);
        $this->db->trans_complete();

        $this->closeBatchIfComplete((int) $item['batch_id'], $actorId);
        return array('ok' => true, 'errors' => array());
    }

    /** Record a failed payment. The item is not paid and the reason is kept. */
    public function recordFailure($itemId, $params, $actorId = 0)
    {
        $item = $this->payoutItem($itemId);
        if (!$item) { return array('ok' => false, 'errors' => array('Item not found.')); }

        $v = Payplex_commission_payout::validateFailure($params);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors']); }

        $this->db->where('id', (int) $itemId)->update($this->tPItems(), array(
            'status'         => Payplex_commission_payout::ITEM_FAILED,
            'failure_reason' => (string) $params['failure_reason'],
            'failure_notes'  => substr((string) (isset($params['notes']) ? $params['notes'] : ''), 0, 500),
        ));
        $this->payoutEvent((int) $item['batch_id'], (int) $itemId, 'failed', $item['status'],
            Payplex_commission_payout::ITEM_FAILED, $actorId,
            (string) (isset($params['notes']) ? $params['notes'] : ''),
            array('failure_reason' => $params['failure_reason']));
        $this->setClaimStateFromPayout((int) $itemId, 'failed', $actorId,
            (string) $params['failure_reason']);

        return array('ok' => true, 'errors' => array());
    }

    /** Retry a failed item as a NEW attempt; the failure history is preserved. */
    public function retryPayoutItem($itemId, $actorId = 0)
    {
        $item = $this->payoutItem($itemId);
        if (!$item) { return array('ok' => false, 'reason' => 'Item not found.'); }

        $gate = Payplex_commission_payout::canRetry($item);
        if (!$gate['allowed']) { return array('ok' => false, 'reason' => $gate['reason']); }

        $this->db->where('id', (int) $itemId)->update($this->tPItems(), array(
            'status'         => Payplex_commission_payout::ITEM_PENDING,
            'attempt'        => (int) $item['attempt'] + 1,
            'failure_reason' => null,
            'failure_notes'  => null,
        ));
        $this->payoutEvent((int) $item['batch_id'], (int) $itemId, 'retry',
            Payplex_commission_payout::ITEM_FAILED, Payplex_commission_payout::ITEM_PENDING, $actorId,
            'Attempt ' . ((int) $item['attempt'] + 1), array('previous_failure' => $item['failure_reason']));
        /* The SAME item is re-queued with attempt + 1. No second item is created,
           so a retry can never become a second payment. */
        $this->setClaimStateFromPayout((int) $itemId, 'payout_processing', $actorId,
            'Retry, attempt ' . ((int) $item['attempt'] + 1));

        return array('ok' => true, 'reason' => '');
    }

    private function closeBatchIfComplete($batchId, $actorId)
    {
        $items = $this->payoutItems($batchId);
        if (!$items) { return; }
        foreach ($items as $i) {
            if ((string) $i['status'] !== Payplex_commission_payout::ITEM_PAID) { return; }
        }
        $this->payoutTransition($batchId, Payplex_commission_payout::SETTLED, $actorId, 'All items settled.');
    }

    /** Reconcile a batch against bank rows the finance team supply. */
    public function reconcileBatch($batchId, $bankRows)
    {
        return Payplex_commission_payout::reconcile($this->payoutItems($batchId), $bankRows);
    }

    /* ================= Environment gate & staging bootstrap ================= */

    /**
     * Which environment is this install?
     *
     * Defaults to PRODUCTION when unset. That default is deliberate and is the
     * whole safety property: a fresh install, a restored backup, or a database
     * copied from staging to production all land on "production", where test
     * rules refuse to pay. Being wrong in that direction costs a blocked run;
     * being wrong the other way pays people at invented rates.
     */
    public function environment()
    {
        $v = strtolower(trim((string) get_option('payplex_commission_environment')));
        return $v === 'staging' ? 'staging' : 'production';
    }

    public function isProduction()
    {
        return $this->environment() !== 'staging';
    }

    /**
     * May a test-flagged artefact be used or approved here?
     * Only on an explicitly-declared staging install.
     */
    public function allowsTestArtefacts()
    {
        return !$this->isProduction();
    }

    /**
     * Staging-only bootstrap approval.
     *
     * The maker-is-not-approver rule exists so nobody can set and ratify the
     * rates they are paid by. On a staging install with no second account, that
     * rule makes it impossible to demonstrate the pipeline at all — so this
     * narrow path exists, and it is fenced on every side:
     *
     *   - refused outright unless the environment is explicitly 'staging'
     *   - refused unless the artefact is flagged is_test
     *   - recorded under its own audit action, never as a normal approval, so
     *     nobody can later mistake it for a real sign-off
     *
     * It can approve nothing that could ever pay in production, because a test
     * artefact is refused by the resolver there regardless.
     */
    public function bootstrapApproveRule($id, $actorId = 0, $reason = '')
    {
        if (!$this->allowsTestArtefacts()) {
            return array('ok' => false, 'reason' => 'Bootstrap approval is refused: this install is not '
                . 'declared as staging. Set the environment to staging first, or approve the rule properly '
                . 'with a second person.');
        }

        $rule = $this->ruleGet($id);
        if (!$rule) { return array('ok' => false, 'reason' => 'Rule not found.'); }

        if ((int) $rule['is_test'] !== 1) {
            return array('ok' => false, 'reason' => 'Bootstrap approval only applies to rules flagged as TEST. '
                . 'A real rule must be approved by someone other than its author.');
        }
        if ((string) $rule['status'] !== Payplex_commission_rule::SUBMITTED) {
            return array('ok' => false, 'reason' => 'Only a submitted rule can be approved.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->where('id', (int) $id)->update($this->tRuleBuilder(), array(
            'status'      => Payplex_commission_rule::APPROVED,
            'approved_by' => (int) $actorId,
            'approved_at' => $now,
            'updated_at'  => $now,
        ));

        $this->ruleAudit($id, 'staging_bootstrap_approval', Payplex_commission_rule::SUBMITTED,
            Payplex_commission_rule::APPROVED, $actorId,
            'STAGING BOOTSTRAP — not a real approval. Test rule approved without a second person so the '
            . 'pipeline could be demonstrated. This rule cannot pay in production. ' . $reason,
            $this->ruleGet($id));

        return array('ok' => true, 'reason' => '');
    }

    /** Same narrow path for a test source policy. */
    public function bootstrapApprovePolicy($id, $actorId = 0, $reason = '')
    {
        if (!$this->allowsTestArtefacts()) {
            return array('ok' => false, 'reason' => 'Bootstrap approval is refused: this install is not '
                . 'declared as staging.');
        }

        $policy = $this->policyGet($id);
        if (!$policy) { return array('ok' => false, 'reason' => 'Policy not found.'); }
        if (stripos((string) $policy['name'], 'TEST') === false
            && stripos((string) $policy['name'], 'DUMMY') === false) {
            return array('ok' => false, 'reason' => 'Bootstrap approval only applies to a policy named as '
                . 'TEST or DUMMY, so it cannot be confused with a real one.');
        }
        if ((string) $policy['status'] !== Payplex_commission_source::SUBMITTED) {
            return array('ok' => false, 'reason' => 'Only a submitted policy can be approved.');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->where('id', (int) $id)->update($this->tPolicies(), array(
            'status'      => Payplex_commission_source::APPROVED,
            'approved_by' => (int) $actorId,
            'approved_at' => $now,
            'updated_at'  => $now,
        ));

        $this->ruleAudit(null, 'staging_bootstrap_approval', Payplex_commission_source::SUBMITTED,
            Payplex_commission_source::APPROVED, $actorId,
            'STAGING BOOTSTRAP — not a real approval. Test source policy #' . (int) $id . ' approved without a '
            . 'second person so the pipeline could be demonstrated. ' . $reason,
            array('policy_id' => (int) $id));

        return array('ok' => true, 'reason' => '');
    }

    /**
     * Seed clearly-labelled dummy rules and a source policy for a staging
     * demonstration. Refuses outright on a production install.
     */
    public function seedDummyRules($actorId = 0)
    {
        if (!$this->allowsTestArtefacts()) {
            return array('ok' => false, 'created' => 0,
                'reason' => 'Refused: dummy rules can only be seeded on an install declared as staging.');
        }

        $existing = $this->db->like('rule_code', 'DUMMY-')->count_all_results($this->tRuleBuilder());
        if ($existing > 0) {
            return array('ok' => false, 'created' => 0, 'reason' => 'Dummy rules already exist.');
        }

        $defs = array(
            array(
                'rule_code' => 'DUMMY-FLAT', 'name' => 'DUMMY (staging only) — flat 4% of collections',
                'calc_type' => 'percentage', 'calc_base' => 'amount_collected', 'rate' => 4,
                'priority' => 100, 'is_test' => 1,
                'notes' => 'Placeholder figure for pipeline demonstration only. NOT an approved commercial rate.',
            ),
            array(
                'rule_code' => 'DUMMY-SLAB', 'name' => 'DUMMY (staging only) — slab on collections',
                'calc_type' => 'slab', 'calc_base' => 'amount_collected',
                'slabs' => array(
                    array('upto' => 100000, 'rate' => 3),
                    array('upto' => 500000, 'rate' => 5),
                    array('upto' => null,   'rate' => 7),
                ),
                'priority' => 50, 'territory' => 'West', 'is_test' => 1,
                'notes' => 'Placeholder slab for pipeline demonstration only. NOT an approved commercial rate.',
            ),
            array(
                'rule_code' => 'DUMMY-ACCEL', 'name' => 'DUMMY (staging only) — accelerator on attainment',
                'calc_type' => 'accelerator', 'calc_base' => 'amount_collected', 'rate' => 4,
                'accelerator_threshold' => 100, 'accelerator_rate' => 1.25,
                'priority' => 40, 'employee_role' => 'senior_sales', 'is_test' => 1,
                'notes' => 'Placeholder accelerator for pipeline demonstration only. NOT approved.',
            ),
        );

        $created = 0; $ids = array();
        foreach ($defs as $d) {
            $res = $this->ruleCreate($d, $actorId);
            if (!empty($res['ok'])) { $created++; $ids[] = (int) $res['id']; }
        }

        return array('ok' => $created > 0, 'created' => $created, 'ids' => $ids, 'reason' => '');
    }

    /** Seed the matching dummy source policy. */
    public function seedDummyPolicy($actorId = 0)
    {
        if (!$this->allowsTestArtefacts()) {
            return array('ok' => false, 'reason' => 'Refused: not a staging install.');
        }
        $existing = $this->db->like('name', 'DUMMY')->count_all_results($this->tPolicies());
        if ($existing > 0) { return array('ok' => false, 'reason' => 'A dummy policy already exists.'); }

        return $this->policyCreate(array(
            'name'    => 'DUMMY (staging only) — collections',
            'events'  => array('payment_received', 'invoice_paid_full'),
            'exclude_refunded' => 1, 'exclude_cancelled' => 1,
            'gateway_fee_treatment' => 'ignore', 'currencies' => 'INR',
            'notes'   => 'Placeholder policy for pipeline demonstration only. NOT an approved source policy.',
        ), $actorId);
    }

    /* ==================================================================== *
     * Expense claims as a payout source  (Module 8)
     * ==================================================================== */

    private function claimsTable() { return db_prefix() . 'wf_expense_claims'; }
    private function claimEventsTable() { return db_prefix() . 'wf_expense_events'; }

    /**
     * Approved expense claims not already sitting on a live payout item.
     *
     * The same two-layer guard as payableStatements(), and for the same reason:
     * this query keeps an already-batched claim out of a second batch, and the
     * UNIQUE (batch_id, source_type, source_id) index catches the case two
     * concurrent requests both pass the query.
     */
    public function payableExpenseClaims()
    {
        if (!$this->db->table_exists($this->claimsTable())) { return array(); }

        $taken = array();
        if ($this->db->table_exists($this->tPItems())) {
            $rows = $this->db->select($this->tPItems() . '.source_id')
                ->join($this->tBatches() . ' b', 'b.id = ' . $this->tPItems() . '.batch_id', 'left')
                ->where($this->tPItems() . '.source_type', Payplex_commission_payout::SOURCE_CLAIM)
                ->where_not_in('b.status', array('cancelled'))
                ->get($this->tPItems())->result();
            foreach ($rows as $r) { if ($r->source_id) { $taken[] = (int) $r->source_id; } }
        }

        $this->db->where('state', 'approved');
        $this->db->group_start()->where('payout_item_id', null)->or_where('payout_item_id', 0)->group_end();
        if ($taken) { $this->db->where_not_in('id', $taken); }
        return $this->db->order_by('id', 'ASC')->get($this->claimsTable())->result_array();
    }

    /** Record which payout item is carrying a claim, and move the claim along. */
    public function linkClaimToPayoutItem($claimId, $itemId, $actorId = 0)
    {
        if (!$this->db->table_exists($this->claimsTable())) { return false; }
        $this->db->where('id', (int) $claimId)->update($this->claimsTable(), array(
            'payout_item_id' => (int) $itemId,
            'state'          => 'payout_processing',
            'state_since'    => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ));
        $this->claimStateEvent($claimId, 'payout_linked', 'approved', 'payout_processing', $actorId,
            'Added to payout item #' . (int) $itemId);
        return true;
    }

    /**
     * Move a claim to follow what happened to its payout item.
     *
     * Deliberately narrow: this only writes the states the payout engine is
     * entitled to decide — payout_processing, paid, failed, reversed. It cannot
     * approve or reject a claim, because that is not the payout engine's business.
     */
    public function setClaimStateFromPayout($itemId, $to, $actorId = 0, $reason = '')
    {
        if (!$this->db->table_exists($this->claimsTable())) { return false; }
        $allowed = array('payout_processing', 'paid', 'failed', 'reversed');
        if (!in_array($to, $allowed, true)) { return false; }

        $c = $this->db->where('payout_item_id', (int) $itemId)->limit(1)->get($this->claimsTable())->row_array();
        if (!$c) { return false; }

        $set = array('state' => $to, 'state_since' => date('Y-m-d H:i:s'),
                     'updated_at' => date('Y-m-d H:i:s'));
        if ($to === 'paid') { $set['paid_at'] = date('Y-m-d H:i:s'); }
        if ($to === 'reversed' && $reason !== '') { $set['decided_reason'] = substr($reason, 0, 500); }

        $this->db->where('id', (int) $c['id'])->update($this->claimsTable(), $set);
        $this->claimStateEvent((int) $c['id'], 'payout_' . $to, $c['state'], $to, $actorId, $reason);
        return true;
    }

    private function claimStateEvent($claimId, $action, $from, $to, $actorId, $reason = '')
    {
        if (!$this->db->table_exists($this->claimEventsTable())) { return; }
        $this->db->insert($this->claimEventsTable(), array(
            'claim_id'    => (int) $claimId,
            'action'      => substr((string) $action, 0, 40),
            'from_state'  => $from,
            'to_state'    => $to,
            'actor_id'    => (int) $actorId,
            'reason'      => $reason === '' ? null : substr((string) $reason, 0, 500),
            'data_json'   => json_encode(array('source' => 'payplex_commission payout engine')),
            'ip'          => substr((string) $this->input->ip_address(), 0, 45),
            'occurred_at' => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * Reverse a payment that has already settled.
     *
     * The item keeps its bank reference and paid amount. A reversal is a new
     * fact about a payment that happened, not an erasure of it — and the UNIQUE
     * index on bank_reference means the original UTR can never be reused by a
     * later item either.
     *
     * @return array ok, code, reason
     */
    public function reversePayoutItem($itemId, $actorId, $hasCapability, $reason = '')
    {
        $item = $this->payoutItem($itemId);
        if (!$item) { return array('ok' => false, 'code' => 'not_found', 'reason' => 'Item not found.'); }

        $gate = Payplex_commission_payout::canReverse($item, $actorId, $hasCapability, $reason);
        if (!$gate['allowed']) {
            $this->payoutEvent((int) $item['batch_id'], (int) $itemId, 'reverse_refused',
                $item['status'], Payplex_commission_payout::ITEM_REVERSED, $actorId, $gate['code']);
            return array('ok' => false, 'code' => $gate['code'], 'reason' => $gate['reason']);
        }

        $this->db->where('id', (int) $itemId)->update($this->tPItems(), array(
            'status' => Payplex_commission_payout::ITEM_REVERSED,
        ));
        $this->payoutEvent((int) $item['batch_id'], (int) $itemId, 'reversed',
            Payplex_commission_payout::ITEM_PAID, Payplex_commission_payout::ITEM_REVERSED,
            $actorId, $reason, array('bank_reference' => $item['bank_reference'],
                                     'paid_amount' => $item['paid_amount']));
        $this->setClaimStateFromPayout((int) $itemId, 'reversed', $actorId, $reason);

        return array('ok' => true, 'code' => 'ok', 'reason' => '');
    }
}
