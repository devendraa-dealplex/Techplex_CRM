<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Sales_targets_model extends App_Model
{
    /** @var Kpi_engine */
    private $kpi_engine;

    public function __construct()
    {
        parent::__construct();

        // Loaded via a direct require_once (rather than $this->load->library())
        // so this does not depend on any undocumented assumption about how
        // Perfex's loader resolves module-prefixed library paths — this way
        // works identically regardless, and cannot break page loads if that
        // assumption were wrong.
        if (!class_exists('Kpi_engine')) {
            require_once __DIR__ . '/../libraries/Kpi_engine.php';
        }
        $this->kpi_engine = new Kpi_engine();
    }

    /**
     * @param mixed $staff_id
     * Unchanged field shape from Phase 1 — every field the view already
     * relies on (id, staff_id, target_value, period_start, period_end,
     * notes, firstname, lastname, achieved) is still returned exactly as
     * before. Cancelled targets (Phase 2 soft-cancel, see delete()) are
     * excluded so the visible list behaves the same as the old hard-delete.
     *
     * Phase 3: the 'achieved' value is now produced by Kpi_engine's
     * converted_leads calculator (see get_leads_converted() below) instead
     * of an inline query, adopting the same proven definition the native
     * Goals module uses (status = 1 AND a real client record exists), which
     * is stricter and more reconciling than the plain date_converted check
     * this method used prior to Phase 3.
     *
     * Phase 4 note: this list view intentionally still shows only the
     * converted-leads achieved/progress columns, unchanged. A target may
     * now also carry gross_billed_revenue / amount_collected metrics (see
     * add()/update() below), but surfacing their achievement here is left
     * to a later phase — see get_metric_achievement() for the read path.
     */
    public function get_all($staff_id = null, $includeClosed = false)
    {
        $this->db->select('t.id, t.staff_id, t.target_value, t.period_start, t.period_end, t.notes, t.status,
                          t.submitted_by, t.approved_by, s.firstname, s.lastname');
        $this->db->from(db_prefix() . 'sales_targets t');
        $this->db->join(db_prefix() . 'staff s', 's.staffid = t.staff_id', 'left');
        /*
         * Cancelled targets were unreachable from the list while their edit URL
         * still opened them. They are now one click away instead of hidden.
         */
        if (!$includeClosed) { $this->db->where('t.status !=', 'Cancelled'); }

        if ($staff_id) {
            $this->db->where('t.staff_id', $staff_id);
        }

        $this->db->order_by('t.period_start', 'desc');

        $rows = $this->db->get()->result_array();

        foreach ($rows as &$row) {
            $row['achieved'] = $this->get_leads_converted($row['staff_id'], $row['period_start'], $row['period_end']);
        }

        return $rows;
    }

    public function get($id)
    {
        $this->db->where('id', $id);

        return $this->db->get(db_prefix() . 'sales_targets')->row_array();
    }

    /**
     * Phase 3: delegates to Kpi_engine's converted_leads calculator (see
     * libraries/Kpi_engine.php for the documented source table/condition/
     * date field) instead of the Phase 1/2 inline query, so the list view's
     * 'achieved' number and the KPI engine's drill-down/snapshot logic can
     * never disagree — there is exactly one place converted-leads
     * achievement is calculated.
     */
    public function get_leads_converted($staff_id, $period_start, $period_end)
    {
        $result = $this->kpi_engine->calculate('converted_leads', $staff_id, $period_start, $period_end);

        return (int) $result['achieved'];
    }

    /**
     * Phase 3: generic achievement lookup for any of the metrics attached to
     * a target, for the 3 KPIs the engine supports. Returns the same shape
     * Kpi_engine::calculate() returns (achieved, record_count, record_ids,
     * source_table, unavailable[, reason]) so a caller can both display the
     * total and drill down to the exact contributing records. Intended for
     * Phase 4's dashboard; not yet wired into any view.
     */
    public function get_metric_achievement($metric_row, $staff_id, $period_start, $period_end)
    {
        return $this->kpi_engine->calculate($metric_row['kpi_key'], $staff_id, $period_start, $period_end);
    }

    /**
     * Phase 3: all metric rows attached to a target. Phase 4 wires this
     * into the manage() controller action (see the module's controller) so
     * the Edit form can pre-fill the gross_billed_revenue / amount_collected
     * fields from whatever metric rows already exist for the target — a
     * target can now carry 1 to 3 metric rows (converted_leads is always
     * present; the other two are attached only when a value was provided).
     */
    public function get_target_metrics($target_id)
    {
        $this->db->where('target_id', $target_id);

        return $this->db->get(db_prefix() . 'sales_target_metrics')->result_array();
    }

    public function get_active_staff()
    {
        $this->db->select('staffid, firstname, lastname');
        $this->db->where('active', 1);
        /*
         * Targets #4 and #5 belong to "Payplex Groups" (#24), which carries
         * is_not_staff = 1 — a group mailbox, not a person. A sales target on a
         * group account has nobody to achieve it and nobody to hold to it.
         */
        $this->db->where('is_not_staff', 0);
        $this->db->order_by('firstname', 'asc');

        return $this->db->get(db_prefix() . 'staff')->result_array();
    }

    /**
     * Add a new target. Behavior for tblsales_targets itself is unchanged;
     * Phase 2 additionally mirrors the target into the metrics/assignees
     * foundation tables and writes an audit log entry, so every target
     * created from now on already has the data future phases need.
     *
     * Phase 4: $data may optionally carry 'target_revenue_value' and/or
     * 'target_collected_value' (both nullable). Neither is a column on
     * tblsales_targets, so they are read individually below rather than
     * being passed wholesale into the base-table insert (which would
     * otherwise fail with an unknown-column SQL error) — only a metric row
     * is created for whichever of the two was actually given a value
     * greater than zero. Leaving both blank reproduces Phase 1–3 behavior
     * exactly: a target with a single converted_leads metric.
     */
    /**
     * Would creating this target duplicate a live one?
     *
     * The overlap rule only ever ran on the way into Active, and the create
     * path never went that way, so targets #4 and #5 — same person, same
     * period, same value, seventeen minutes apart — were both accepted.
     */
    public function creation_gate($data)
    {
        $candidate = array(
            'id'           => 0,
            'staff_id'     => isset($data['staff_id']) ? (int) $data['staff_id'] : 0,
            'period_start' => isset($data['period_start']) ? $data['period_start'] : '',
            'period_end'   => isset($data['period_end']) ? $data['period_end'] : '',
            'status'       => Target_workflow::ACTIVE,
            'metrics'      => array(array('kpi_key' => 'converted_leads')),
        );
        if ($this->metric_value_given($data, 'target_revenue_value')) {
            $candidate['metrics'][] = array('kpi_key' => 'gross_billed_revenue');
        }
        if ($this->metric_value_given($data, 'target_collected_value')) {
            $candidate['metrics'][] = array('kpi_key' => 'amount_collected');
        }

        $others = $this->db->get(db_prefix() . 'sales_targets')->result_array();
        foreach ($others as $k => $o) { $others[$k]['metrics'] = $this->metrics_for((int) $o['id']); }

        return Target_workflow::creationGate($candidate, $others);
    }

    public function add($data)
    {
        /*
         * A target used to be born Active: add() set no status and the column
         * defaults to 'Active', so every guard in this module — maker-checker,
         * the capability map, the overlap rule — was skipped, because all of
         * them live behind target_transition(). Draft is stated explicitly here
         * as well as in the column default, so neither alone can fail open.
         */
        $insert_data = array(
            'staff_id' => $data['staff_id'],
            'target_value' => $data['target_value'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'notes' => $data['notes'],
            'status' => Target_workflow::initialState(),
            'created_by' => $data['created_by'],
            'date_created' => $data['date_created'],
        );

        $this->db->insert(db_prefix() . 'sales_targets', $insert_data);
        $insert_id = $this->db->insert_id();

        if ($insert_id) {
            $this->add_metric_row($insert_id, 'converted_leads', $data['target_value'], $data['date_created']);

            if ($this->metric_value_given($data, 'target_revenue_value')) {
                $this->add_metric_row($insert_id, 'gross_billed_revenue', $data['target_revenue_value'], $data['date_created'], 'currency');
            }
            if ($this->metric_value_given($data, 'target_collected_value')) {
                $this->add_metric_row($insert_id, 'amount_collected', $data['target_collected_value'], $data['date_created'], 'currency');
            }

            $this->add_assignee_row($insert_id, $data['staff_id'], $data['period_start'], $data['period_end'], $data['date_created']);
            $this->write_audit_log($insert_id, $data['created_by'], 'target_created', 'Target created for staff_id=' . $data['staff_id'] . ', value=' . $data['target_value'] . '.');
        }

        return $insert_id;
    }

    /**
     * Update an existing target. Phase 2 additionally records a revision row
     * for every changed field (old value -> new value) and keeps the
     * metrics/assignees rows in sync, without changing what update() returns
     * or how the controller uses it.
     *
     * Phase 4: 'target_revenue_value' / 'target_collected_value' in $data
     * (when the key is present at all — see manage(), which always submits
     * both, null or a value) sync or remove the corresponding optional
     * metric row via sync_or_remove_metric_row(), the same
     * create-if-missing / update-if-present pattern sync_metric_row() has
     * always used for converted_leads, plus removal when cleared back to
     * blank so a target never carries a stale target_value for a KPI the
     * user un-set.
     */
    public function update($id, $data)
    {
        $before = $this->get($id);

        $update_data = array(
            'staff_id' => $data['staff_id'],
            'target_value' => $data['target_value'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'notes' => $data['notes'],
        );

        $this->db->where('id', $id);
        $updated = $this->db->update(db_prefix() . 'sales_targets', $update_data);

        if ($updated && $before) {
            $staff_id = get_staff_user_id();

            foreach (array('staff_id', 'target_value', 'period_start', 'period_end', 'notes') as $field) {
                if (isset($data[$field]) && (string) $before[$field] !== (string) $data[$field]) {
                    $this->record_revision($id, $field, $before[$field], $data[$field], 'Edited via Sales Targets manage form.', $staff_id);
                }
            }

            if (isset($data['target_value'])) {
                $this->sync_metric_row($id, 'converted_leads', $data['target_value']);
            }

            if (array_key_exists('target_revenue_value', $data)) {
                $this->sync_or_remove_metric_row($id, 'gross_billed_revenue', $data['target_revenue_value'], 'currency');
            }
            if (array_key_exists('target_collected_value', $data)) {
                $this->sync_or_remove_metric_row($id, 'amount_collected', $data['target_collected_value'], 'currency');
            }

            if (isset($data['staff_id']) || isset($data['period_start']) || isset($data['period_end'])) {
                $this->sync_assignee_row(
                    $id,
                    isset($data['staff_id']) ? $data['staff_id'] : $before['staff_id'],
                    isset($data['period_start']) ? $data['period_start'] : $before['period_start'],
                    isset($data['period_end']) ? $data['period_end'] : $before['period_end']
                );
            }

            $this->write_audit_log($id, $staff_id, 'target_updated', 'Target ID ' . $id . ' updated.');
        }

        return $updated;
    }

    /**
     * Phase 2: no more hard DELETE (gap #12). The row is soft-cancelled
     * (status = 'Cancelled') so it disappears from get_all() exactly like a
     * hard delete used to, but the record, its metrics/assignees and full
     * history stay in the database for audit purposes and can be restored.
     */
    public function delete($id)
    {
        $before = $this->get($id);

        if (!$before) {
            return false;
        }

        $this->db->where('id', $id);
        $updated = $this->db->update(db_prefix() . 'sales_targets', array('status' => 'Cancelled'));

        if ($updated) {
            $staff_id = get_staff_user_id();
            $this->record_revision($id, 'status', $before['status'], 'Cancelled', 'Cancelled via Sales Targets delete action.', $staff_id);
            $this->write_audit_log($id, $staff_id, 'target_cancelled', 'Target ID ' . $id . ' cancelled (soft-delete).');
        }

        return $updated;
    }

    // ------------------------------------------------------------------
    // Phase 2 foundation helpers (Phase 4 extends add_metric_row() with an
    // optional $unit parameter and adds sync_or_remove_metric_row() /
    // metric_value_given() alongside them)
    // ------------------------------------------------------------------

    private function add_metric_row($target_id, $kpi_key, $target_value, $date_created, $unit = 'count')
    {
        $this->db->insert(db_prefix() . 'sales_target_metrics', array(
            'target_id' => $target_id,
            'kpi_key' => $kpi_key,
            'target_value' => $target_value,
            'unit' => $unit,
            'weight' => 100.00,
            'date_created' => $date_created,
        ));
    }

    private function add_assignee_row($target_id, $staff_id, $effective_start, $effective_end, $date_created)
    {
        $this->db->insert(db_prefix() . 'sales_target_assignees', array(
            'target_id' => $target_id,
            'staff_id' => $staff_id,
            'effective_start' => $effective_start,
            'effective_end' => $effective_end,
            'date_created' => $date_created,
        ));
    }

    private function sync_metric_row($target_id, $kpi_key, $target_value)
    {
        $this->db->where('target_id', $target_id);
        $this->db->where('kpi_key', $kpi_key);
        $row = $this->db->get(db_prefix() . 'sales_target_metrics')->row();

        if ($row) {
            $this->db->where('id', $row->id);
            $this->db->update(db_prefix() . 'sales_target_metrics', array('target_value' => $target_value));
        } else {
            $this->add_metric_row($target_id, $kpi_key, $target_value, date('Y-m-d H:i:s'));
        }
    }

    /**
     * Phase 4 — like sync_metric_row(), but for the optional
     * gross_billed_revenue / amount_collected metrics: a blank or
     * zero/negative value means "not tracking this KPI for this target", so
     * any existing metric row for that kpi_key is removed instead of being
     * left behind at a stale target_value. Any daily-snapshot history rows
     * already written for a removed metric are left in place (audit trail),
     * exactly as a Cancelled target's snapshot rows are left in place —
     * they simply stop being added to once the metric is gone.
     */
    private function sync_or_remove_metric_row($target_id, $kpi_key, $value, $unit)
    {
        $has_value = $value !== null && $value !== '' && (float) $value > 0;

        $this->db->where('target_id', $target_id);
        $this->db->where('kpi_key', $kpi_key);
        $row = $this->db->get(db_prefix() . 'sales_target_metrics')->row();

        if ($has_value) {
            if ($row) {
                $this->db->where('id', $row->id);
                $this->db->update(db_prefix() . 'sales_target_metrics', array('target_value' => $value));
            } else {
                $this->add_metric_row($target_id, $kpi_key, $value, date('Y-m-d H:i:s'), $unit);
            }
        } elseif ($row) {
            $this->db->where('id', $row->id);
            $this->db->delete(db_prefix() . 'sales_target_metrics');
        }
    }

    /**
     * Phase 4 — true only when $data[$key] is present and a positive
     * number, so a blank/zero/omitted optional target value never creates
     * a metric row (matches sync_or_remove_metric_row()'s "has_value"
     * rule, used on the add() path where there is no existing row to
     * remove).
     */
    private function metric_value_given($data, $key)
    {
        return isset($data[$key]) && $data[$key] !== null && $data[$key] !== '' && (float) $data[$key] > 0;
    }

    private function sync_assignee_row($target_id, $staff_id, $effective_start, $effective_end)
    {
        $this->db->where('target_id', $target_id);
        $row = $this->db->get(db_prefix() . 'sales_target_assignees')->row();

        if ($row) {
            $this->db->where('id', $row->id);
            $this->db->update(db_prefix() . 'sales_target_assignees', array(
                'staff_id' => $staff_id,
                'effective_start' => $effective_start,
                'effective_end' => $effective_end,
            ));
        } else {
            $this->add_assignee_row($target_id, $staff_id, $effective_start, $effective_end, date('Y-m-d H:i:s'));
        }
    }

    private function record_revision($target_id, $field_changed, $old_value, $new_value, $reason, $requested_by)
    {
        $this->db->insert(db_prefix() . 'sales_target_revisions', array(
            'target_id' => $target_id,
            'field_changed' => $field_changed,
            'old_value' => $old_value,
            'new_value' => $new_value,
            'reason' => $reason,
            'requested_by' => $requested_by,
            'approved_by' => null,
            'date_created' => date('Y-m-d H:i:s'),
        ));
    }

    private function write_audit_log($target_id, $staff_id, $action, $description)
    {
        $this->db->insert(db_prefix() . 'sales_target_audit_logs', array(
            'target_id' => $target_id,
            'staff_id' => $staff_id,
            'action' => $action,
            'description' => $description,
            'date_created' => date('Y-m-d H:i:s'),
        ));
    }

    /* ================= v1.2.0 — multi-KPI achievement (§3.2) ================= */

    /** Tables that exist, so the KPI catalogue can be resolved honestly. */
    private function existing_source_tables()
    {
        $candidates = array('leads', 'invoices', 'invoicepaymentrecords', 'clients', 'proposals', 'itemable');
        $out = array();
        foreach ($candidates as $t) {
            if ($this->db->table_exists(db_prefix() . $t)) { $out[] = $t; }
        }
        return $out;
    }

    /** Metric rows for a target. */
    public function metrics_for($target_id)
    {
        $t = db_prefix() . 'sales_target_metrics';
        if (!$this->db->table_exists($t)) { return array(); }
        return $this->db->where('target_id', (int) $target_id)->order_by('id', 'ASC')
            ->get($t)->result_array();
    }

    /**
     * Full achievement breakdown for a target: every KPI scored, plus the
     * overall rollup with measured and unmeasured weight stated separately.
     *
     * A KPI the engine cannot calculate is reported as unmeasurable with its
     * reason — never as zero achievement, which would read as failure.
     */
    public function achievement_breakdown($target)
    {
        $t = (array) $target;
        $target_id = (int) (isset($t['id']) ? $t['id'] : 0);
        $staff_id  = (int) (isset($t['staff_id']) ? $t['staff_id'] : 0);
        $start     = isset($t['period_start']) ? $t['period_start'] : null;
        $end       = isset($t['period_end']) ? $t['period_end'] : null;

        $tables  = $this->existing_source_tables();
        $capOver = (int) (isset($t['cap_over_100']) ? $t['cap_over_100'] : get_option('sales_targets_cap_over_100')) !== 0;

        $results = array();
        foreach ($this->metrics_for($target_id) as $m) {
            $key = (string) $m['kpi_key'];
            $kpi = Kpi_catalog::resolve($key, $tables);

            // manual KPIs carry their value on the metric row itself
            if ($kpi !== null && $kpi['status'] === Kpi_catalog::MANUAL) {
                $actual = array(
                    'achieved'        => isset($m['manual_value']) && $m['manual_value'] !== null
                                          ? (float) $m['manual_value'] : 0.0,
                    'measurable'      => isset($m['manual_value']) && $m['manual_value'] !== null,
                    'reason'          => (isset($m['manual_value']) && $m['manual_value'] !== null)
                                          ? '' : 'No value has been entered for this manual KPI yet.',
                    'record_count'    => 0,
                    'last_calculated' => isset($m['last_calculated']) ? $m['last_calculated'] : null,
                );
            } elseif ($kpi !== null && $kpi['status'] === Kpi_catalog::IMPLEMENTED
                      && $this->kpi_engine->is_supported($key)) {
                $calc = $this->kpi_engine->calculate($key, $staff_id, $start, $end);
                $actual = array(
                    'achieved'        => isset($calc['achieved']) ? $calc['achieved'] : 0,
                    'measurable'      => empty($calc['unavailable']),
                    'reason'          => !empty($calc['unavailable']) ? 'The engine reported this KPI as unavailable.' : '',
                    'record_count'    => isset($calc['record_count']) ? (int) $calc['record_count'] : 0,
                    'record_ids'      => isset($calc['record_ids']) ? $calc['record_ids'] : array(),
                    'definition'      => isset($calc['definition']) ? $calc['definition'] : '',
                    'last_calculated' => date('Y-m-d H:i:s'),
                );
            } else {
                $actual = array(
                    'measurable' => false,
                    'reason'     => Kpi_catalog::unmeasurableReason($key, $tables),
                );
            }

            $row = Kpi_weighting::metricAchievement($m, $actual, $capOver);
            $row['label']       = $kpi !== null ? $kpi['label'] : $key;
            $row['kpi_status']  = $kpi !== null ? $kpi['status'] : 'unknown';
            $row['unit']        = $kpi !== null ? $kpi['unit'] : 'count';
            $row['record_ids']  = isset($actual['record_ids']) ? $actual['record_ids'] : array();
            $row['definition']  = isset($actual['definition']) ? $actual['definition'] : '';
            $row['metric_id']   = (int) $m['id'];

            $progress = Kpi_weighting::periodProgress($start, $end);
            $row['pace']     = Kpi_weighting::pace($row['achievement_pct'], $progress);
            $row['forecast'] = $row['measurable']
                ? Kpi_weighting::forecast($row['achieved'], $progress) : null;

            $results[] = $row;
        }

        $overall = Kpi_weighting::overall($results);

        return array(
            'metrics'   => $results,
            'overall'   => $overall,
            'summary'   => Kpi_weighting::summarise($overall),
            'progress'  => Kpi_weighting::periodProgress($start, $end),
            'cap_over'  => $capOver,
            'refreshed' => date('Y-m-d H:i:s'),
        );
    }

    /* ================= v1.2.0 — target lifecycle (§3.3) ================= */

    public function assignees_for($target_id)
    {
        $t = db_prefix() . 'sales_target_assignees';
        if (!$this->db->table_exists($t)) { return array(); }
        $rows = $this->db->where('target_id', (int) $target_id)->get($t)->result_array();
        $out = array();
        foreach ($rows as $r) { $out[] = (int) $r['staff_id']; }
        return $out;
    }

    public function target_row($id)
    {
        $r = $this->db->where('id', (int) $id)->get('tblsales_targets')->row_array();
        return $r ?: null;
    }

    public function target_audit($target_id, $action, $description, $staff_id = 0)
    {
        $t = db_prefix() . 'sales_target_audit_logs';
        if (!$this->db->table_exists($t)) { return; }
        $this->db->insert($t, array(
            'target_id'    => $target_id ? (int) $target_id : null,
            'staff_id'     => (int) $staff_id,
            'action'       => substr((string) $action, 0, 100),
            'description'  => (string) $description,
            'date_created' => date('Y-m-d H:i:s'),
        ));
    }

    public function target_audit_log($target_id = 0, $limit = 100)
    {
        $t = db_prefix() . 'sales_target_audit_logs';
        if (!$this->db->table_exists($t)) { return array(); }
        if ($target_id) { $this->db->where('target_id', (int) $target_id); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($t)->result();
    }

    /**
     * Move a target through its lifecycle. Every gate is enforced here, not in
     * the view, so a crafted request cannot skip one.
     */
    public function target_transition($id, $to, $actor_id = 0, $opts = array())
    {
        $target = $this->target_row($id);
        if (!$target) { return array('ok' => false, 'reason' => 'Target not found.'); }

        $assignees = $this->assignees_for($id);
        $metrics   = $this->metrics_for($id);

        $gate = Target_workflow::canAct($target, $to, $actor_id, $assignees, $metrics, $opts);
        if (!$gate['allowed']) { return array('ok' => false, 'code' => $gate['code'], 'reason' => $gate['reason']); }

        // activation must not create two live targets on one KPI
        if ($to === Target_workflow::ACTIVE) {
            $candidate = array_merge($target, array('metrics' => $metrics));
            $others = $this->db->get('tblsales_targets')->result_array();
            foreach ($others as $k => $o) { $others[$k]['metrics'] = $this->metrics_for((int) $o['id']); }
            $clashes = Target_workflow::overlaps($candidate, $others);
            if ($clashes) {
                $msgs = array();
                foreach ($clashes as $c) { $msgs[] = $c['message']; }
                return array('ok' => false, 'code' => 'overlap', 'reason' => implode(' ', $msgs));
            }
        }

        $from = (string) $target['status'];
        $now  = date('Y-m-d H:i:s');
        $set  = array('status' => $to);

        if ($to === Target_workflow::SUBMITTED) { $set['submitted_by'] = (int) $actor_id; $set['submitted_at'] = $now; }
        if ($to === Target_workflow::APPROVED)  { $set['approved_by']  = (int) $actor_id; $set['approved_at']  = $now; }
        if ($to === Target_workflow::LOCKED)    { $set['locked_at']    = $now; }
        if ($to === Target_workflow::REJECTED)  { $set['reject_reason'] = substr((string) (isset($opts['reason']) ? $opts['reason'] : ''), 0, 500); }

        $this->db->where('id', (int) $id)->update('tblsales_targets', $set);
        $this->target_audit($id, 'status_' . strtolower($to),
            'Moved from ' . $from . ' to ' . $to
            . (isset($opts['reason']) && $opts['reason'] !== '' ? ' — ' . $opts['reason'] : ''), $actor_id);

        return array('ok' => true, 'reason' => '');
    }

    /**
     * Save an edit. A draft changes in place; anything live becomes a NEW
     * VERSION so achievement already reported against the old one still stands.
     */
    public function target_save_edit($id, $changes, $metrics, $actor_id = 0)
    {
        $target = $this->target_row($id);
        if (!$target) { return array('ok' => false, 'errors' => array('Target not found.')); }

        $assignees = $this->assignees_for($id);
        $gate = Target_workflow::canEdit($target, $actor_id, $assignees);

        $v = Target_workflow::validate(array_merge($target, (array) $changes), $metrics);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors']); }

        if ($gate['allowed']) {
            $this->db->where('id', (int) $id)->update('tblsales_targets', (array) $changes);
            $this->target_audit($id, 'edited', 'Edited in place while ' . $target['status'] . '.', $actor_id);
            return array('ok' => true, 'id' => (int) $id, 'versioned' => false, 'errors' => array());
        }

        if ($gate['code'] === 'own_target') {
            return array('ok' => false, 'errors' => array($gate['reason']));
        }

        $next = Target_workflow::nextVersion($target, (array) $changes, $actor_id);
        $next['date_created'] = date('Y-m-d H:i:s');
        unset($next['metrics']);

        $this->db->insert('tblsales_targets', $next);
        $newId = (int) $this->db->insert_id();

        foreach ($metrics as $m) {
            $m = (array) $m;
            unset($m['id']);
            $m['target_id']    = $newId;
            $m['date_created'] = date('Y-m-d H:i:s');
            $this->db->insert(db_prefix() . 'sales_target_metrics', $m);
        }

        $this->target_audit($newId, 'versioned',
            'Version ' . $next['version'] . ' created from target #' . (int) $id
            . '; the original was left unchanged so past results stand.', $actor_id);

        return array('ok' => true, 'id' => $newId, 'versioned' => true, 'errors' => array());
    }
}
