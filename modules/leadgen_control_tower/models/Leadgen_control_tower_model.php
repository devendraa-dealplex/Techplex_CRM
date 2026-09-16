<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Leadgen_control_tower_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();

        /*
         * Align the database clock with the application clock.
         *
         * Every elapsed-time figure in this module is computed in SQL with
         * TIMESTAMPDIFF(..., NOW()), but the timestamps it compares against are
         * written by Perfex through PHP. MySQL here runs with time_zone=SYSTEM
         * and a system zone of MST (UTC-7), while the application renders and
         * stores IST (UTC+5:30). NOW() was therefore 750 minutes behind the
         * clock the data was written on, and EVERY threshold in this module
         * fired 12h30m late: a 5-minute first-response SLA did not trigger for
         * half a day, and lead_health stamped its own run 12h30m in the past.
         *
         * Measured 2026-09-13: NOW() 06:52 vs application 19:22, same instant.
         *
         * Setting the session zone from PHP's own offset fixes every NOW() and
         * TIMESTAMPDIFF in this module at once, without editing 23 separate SQL
         * statements. It applies to this connection for the rest of the request;
         * any other query running in the same request also becomes consistent
         * with how the application writes dates, which is a correction.
         */
        $this->db->query("SET time_zone = " . $this->db->escape(date('P')));
    }

    /**
     * Most recent audit log rows, newest first, joined to staff first/last
     * name so the view doesn't need a separate lookup per row.
     */
    public function get_recent_audit_logs($limit = 100)
    {
        $this->db->select('a.*, CONCAT(s.firstname, " ", s.lastname) as staff_name');
        $this->db->from(db_prefix() . 'leadgen_control_tower_audit_logs a');
        $this->db->join(db_prefix() . 'staff s', 's.staffid = a.staff_id', 'left');
        $this->db->order_by('a.id', 'desc');
        $this->db->limit($limit);

        return $this->db->get()->result_array();
    }

    /**
     * Sub-phase B - Live Dashboard summary card figures, computed straight
     * from the real tblleads table (confirmed live column names: lost,
     * junk, date_converted, assigned, dateadded - see schema notes in the
     * helper file). One query, no per-row PHP loop.
     */
    public function get_dashboard_summary()
    {
        $sql = "SELECT
                COUNT(*) AS total_leads,
                SUM(CASE WHEN lost = 0 AND junk = 0 AND date_converted IS NULL THEN 1 ELSE 0 END) AS active_leads,
                SUM(CASE WHEN DATE(dateadded) = CURDATE() THEN 1 ELSE 0 END) AS new_today,
                SUM(CASE WHEN (assigned = 0 OR assigned IS NULL) AND lost = 0 AND junk = 0 AND date_converted IS NULL THEN 1 ELSE 0 END) AS unassigned_active,
                SUM(CASE WHEN date_converted IS NOT NULL AND date_converted >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS converted_last_30d,
                SUM(CASE WHEN lost = 1 THEN 1 ELSE 0 END) AS lost_total,
                SUM(CASE WHEN junk > 0 THEN 1 ELSE 0 END) AS junk_total
            FROM " . db_prefix() . "leads";

        return $this->db->query($sql)->row_array();
    }

    /**
     * Active-lead counts grouped by source, newest/highest first, source
     * name joined from tblleads_sources. Limited to a reasonable number of
     * bars for the dashboard card.
     */
    public function get_active_leads_by_source($limit = 8)
    {
        $sql = "SELECT s.name AS source_name, COUNT(l.id) AS lead_count
            FROM " . db_prefix() . "leads l
            LEFT JOIN " . db_prefix() . "leads_sources s ON s.id = l.source
            WHERE l.lost = 0 AND l.junk = 0 AND l.date_converted IS NULL
            GROUP BY l.source
            ORDER BY lead_count DESC
            LIMIT " . (int) $limit;

        return $this->db->query($sql)->result_array();
    }

    /**
     * Active-lead counts grouped by status, status name/color joined from
     * tblleads_status so the dashboard bars can use the same colors staff
     * already see on the Leads Kanban board.
     */
    public function get_active_leads_by_status()
    {
        $sql = "SELECT ls.id AS status_id, ls.name AS status_name, ls.color AS status_color, COUNT(l.id) AS lead_count
            FROM " . db_prefix() . "leads l
            LEFT JOIN " . db_prefix() . "leads_status ls ON ls.id = l.status
            WHERE l.lost = 0 AND l.junk = 0 AND l.date_converted IS NULL
            GROUP BY l.status
            ORDER BY lead_count DESC";

        return $this->db->query($sql)->result_array();
    }

    /**
     * Health-status totals (green/amber/red/grey) from the health table -
     * powers the summary strip on the dashboard and the filter buttons on
     * the Lead Health Monitor page.
     */
    public function get_health_summary_counts()
    {
        $sql = "SELECT health_status, COUNT(*) AS c
            FROM " . db_prefix() . "leadgen_control_tower_health
            GROUP BY health_status";

        $rows = $this->db->query($sql)->result_array();

        $out = array('green' => 0, 'amber' => 0, 'red' => 0, 'grey' => 0);
        foreach ($rows as $row) {
            if (isset($out[$row['health_status']])) {
                $out[$row['health_status']] = (int) $row['c'];
            }
        }

        return $out;
    }

    /**
     * Lead Health Monitor list - one row per lead that has a health row,
     * joined to lead/staff/status names so the view needs no extra lookups.
     * $health_status is optional (green/amber/red/grey) to filter the list;
     * null returns every lead, worst-health first.
     */
    public function get_lead_health_list($health_status = null, $limit = 200)
    {
        $this->db->select('h.lead_id, h.health_status, h.reason_code, h.reason_text, h.date_calculated,
            l.name as lead_name, l.company, l.phonenumber, l.email, l.assigned,
            CONCAT(st.firstname, " ", st.lastname) as assigned_name,
            ls.name as status_name, ls.color as status_color');
        $this->db->from(db_prefix() . 'leadgen_control_tower_health h');
        $this->db->join(db_prefix() . 'leads l', 'l.id = h.lead_id');
        $this->db->join(db_prefix() . 'staff st', 'st.staffid = l.assigned', 'left');
        $this->db->join(db_prefix() . 'leads_status ls', 'ls.id = l.status', 'left');

        if ($health_status !== null && $health_status !== '') {
            $this->db->where('h.health_status', $health_status);
        }

        $this->db->order_by("FIELD(h.health_status, 'red', 'amber', 'green', 'grey')", '', false);
        $this->db->order_by('h.date_calculated', 'desc');
        $this->db->limit($limit);

        return $this->db->get()->result_array();
    }

    // ------------------------------------------------------------------
    // Sub-phase C - SLA rules, SLA monitor, Unassigned/Missed-Followup/
    // Stale-Leads screens
    // ------------------------------------------------------------------

    /**
     * All SLA rules, newest first. Used by the SLA Rules management screen.
     */
    public function get_sla_rules()
    {
        $this->db->select('r.*, ls.name as status_name');
        $this->db->from(db_prefix() . 'leadgen_control_tower_sla_rules r');
        $this->db->join(db_prefix() . "leads_status ls", "ls.id = r.applies_to_id AND r.applies_to_type = 'status'", 'left');
        $this->db->order_by('r.stage', 'asc');
        $this->db->order_by('r.id', 'asc');

        return $this->db->get()->result_array();
    }

    public function get_sla_rule($id)
    {
        $this->db->where('id', (int) $id);

        return $this->db->get(db_prefix() . 'leadgen_control_tower_sla_rules')->row_array();
    }

    /**
     * The single "default"/global active rule for a stage (applies_to_type
     * IS NULL) - the threshold the simple, always-live Unassigned/Missed-
     * Followup/Stale-Leads list screens use. Status-specific rules (e.g.
     * the Hot Lead rules) are evaluated by the SLA Monitor / cron engine
     * instead, since a single flat list cannot show two different
     * thresholds for the same stage at once - see the Sub-phase C
     * changelog for this documented simplification.
     */
    public function get_default_stage_threshold($stage, $fallback_minutes)
    {
        $this->db->where('stage', $stage);
        $this->db->where('active', 1);
        $this->db->where('applies_to_type IS NULL', null, false);
        $this->db->order_by('id', 'asc');
        $this->db->limit(1);
        $row = $this->db->get(db_prefix() . 'leadgen_control_tower_sla_rules')->row();

        return $row ? (int) $row->threshold_minutes : (int) $fallback_minutes;
    }

    public function create_sla_rule($data)
    {
        $data['date_created'] = date('Y-m-d H:i:s');
        $this->db->insert(db_prefix() . 'leadgen_control_tower_sla_rules', $data);

        return $this->db->insert_id();
    }

    public function update_sla_rule($id, $data)
    {
        $data['date_updated'] = date('Y-m-d H:i:s');
        $this->db->where('id', (int) $id);
        $this->db->update(db_prefix() . 'leadgen_control_tower_sla_rules', $data);
    }

    public function delete_sla_rule($id)
    {
        $this->db->where('id', (int) $id);
        $this->db->delete(db_prefix() . 'leadgen_control_tower_sla_rules');

        $this->db->where('sla_rule_id', (int) $id);
        $this->db->delete(db_prefix() . 'leadgen_control_tower_sla_events');
    }

    /**
     * Currently-open SLA breach events (date_completed IS NULL) - the SLA
     * Monitor board. Populated/closed by leadgen_control_tower_recalculate_
     * sla_events() (cron + manual recalculation), joined to lead/rule
     * details so the view needs no extra lookups. Optional $stage filter.
     */
    public function get_open_sla_breaches($stage = null, $limit = 200)
    {
        $this->db->select('e.id, e.lead_id, e.stage, e.date_started, e.date_due, e.date_created,
            r.name as rule_name, r.threshold_minutes,
            l.name as lead_name, l.company, l.assigned,
            CONCAT(st.firstname, " ", st.lastname) as assigned_name,
            ls.name as status_name, ls.color as status_color');
        $this->db->from(db_prefix() . 'leadgen_control_tower_sla_events e');
        $this->db->join(db_prefix() . 'leads l', 'l.id = e.lead_id');
        $this->db->join(db_prefix() . 'leadgen_control_tower_sla_rules r', 'r.id = e.sla_rule_id', 'left');
        $this->db->join(db_prefix() . 'staff st', 'st.staffid = l.assigned', 'left');
        $this->db->join(db_prefix() . 'leads_status ls', 'ls.id = l.status', 'left');
        $this->db->where('e.date_completed IS NULL', null, false);

        if ($stage !== null && $stage !== '') {
            $this->db->where('e.stage', $stage);
        }

        $this->db->order_by('e.date_due', 'asc');
        $this->db->limit($limit);

        return $this->db->get()->result_array();
    }

    public function get_open_sla_breach_counts_by_stage()
    {
        $sql = "SELECT stage, COUNT(*) as c
            FROM " . db_prefix() . "leadgen_control_tower_sla_events
            WHERE date_completed IS NULL
            GROUP BY stage";

        $rows = $this->db->query($sql)->result_array();

        $out = array('assignment' => 0, 'first_response' => 0, 'followup' => 0, 'stale' => 0);
        foreach ($rows as $row) {
            if (isset($out[$row['stage']])) {
                $out[$row['stage']] = (int) $row['c'];
            }
        }

        return $out;
    }

    /**
     * Unassigned Leads - active leads with no assigned staff member, oldest
     * first (the longest-waiting lead surfaces at the top). Always a live
     * query against tblleads directly - not dependent on the cron having
     * run, since assignment gaps need to be visible in real time.
     */
    public function get_unassigned_leads($threshold_minutes, $limit = 200)
    {
        $sql = "SELECT l.id as lead_id, l.name as lead_name, l.company, l.phonenumber, l.email, l.dateadded,
                ls.name as status_name, ls.color as status_color, s.name as source_name,
                TIMESTAMPDIFF(MINUTE, l.dateadded, NOW()) as minutes_waiting
            FROM " . db_prefix() . "leads l
            LEFT JOIN " . db_prefix() . "leads_status ls ON ls.id = l.status
            LEFT JOIN " . db_prefix() . "leads_sources s ON s.id = l.source
            WHERE l.lost = 0 AND l.junk = 0 AND l.date_converted IS NULL
                AND (l.assigned = 0 OR l.assigned IS NULL)
            ORDER BY l.dateadded ASC
            LIMIT " . (int) $limit;

        $rows = $this->db->query($sql)->result_array();
        foreach ($rows as &$row) {
            $row['breached'] = ((int) $row['minutes_waiting']) > (int) $threshold_minutes;
        }

        return $rows;
    }

    /**
     * Missed Follow-Ups - active, assigned leads whose most recent
     * tblleadgen_followup_log entry (or, if none exists yet, dateadded) is
     * older than the followup SLA threshold. Deliberately reads only the
     * follow-up log table (confirmed live and in real use by the existing
     * leadgen_followup module) rather than tbltasks/tblreminders, whose
     * rel_type conventions on this install were not verified - see the
     * Sub-phase C changelog.
     */
    public function get_missed_followup_leads($threshold_minutes, $limit = 200)
    {
        $sql = "SELECT l.id as lead_id, l.name as lead_name, l.company, l.assigned,
                CONCAT(st.firstname, ' ', st.lastname) as assigned_name,
                ls.name as status_name, ls.color as status_color,
                fl.last_followup,
                TIMESTAMPDIFF(MINUTE, COALESCE(fl.last_followup, l.dateadded), NOW()) as minutes_since_followup
            FROM " . db_prefix() . "leads l
            LEFT JOIN " . db_prefix() . "staff st ON st.staffid = l.assigned
            LEFT JOIN " . db_prefix() . "leads_status ls ON ls.id = l.status
            LEFT JOIN (
                SELECT leadid, MAX(date_sent) as last_followup
                FROM " . db_prefix() . "leadgen_followup_log
                GROUP BY leadid
            ) fl ON fl.leadid = l.id
            WHERE l.lost = 0 AND l.junk = 0 AND l.date_converted IS NULL
                AND l.assigned IS NOT NULL AND l.assigned != 0
                AND TIMESTAMPDIFF(MINUTE, COALESCE(fl.last_followup, l.dateadded), NOW()) > " . (int) $threshold_minutes . "
            ORDER BY minutes_since_followup DESC
            LIMIT " . (int) $limit;

        return $this->db->query($sql)->result_array();
    }

    /**
     * Stale Leads - active leads whose CRM status has not changed in longer
     * than the stale SLA threshold (falls back to dateadded for leads that
     * have never had a recorded status change).
     */
    public function get_stale_leads($threshold_minutes, $limit = 200)
    {
        $sql = "SELECT l.id as lead_id, l.name as lead_name, l.company, l.assigned,
                CONCAT(st.firstname, ' ', st.lastname) as assigned_name,
                ls.name as status_name, ls.color as status_color,
                COALESCE(l.last_status_change, l.dateadded) as stale_since,
                TIMESTAMPDIFF(MINUTE, COALESCE(l.last_status_change, l.dateadded), NOW()) as minutes_stale
            FROM " . db_prefix() . "leads l
            LEFT JOIN " . db_prefix() . "staff st ON st.staffid = l.assigned
            LEFT JOIN " . db_prefix() . "leads_status ls ON ls.id = l.status
            WHERE l.lost = 0 AND l.junk = 0 AND l.date_converted IS NULL
                AND TIMESTAMPDIFF(MINUTE, COALESCE(l.last_status_change, l.dateadded), NOW()) > " . (int) $threshold_minutes . "
            ORDER BY minutes_stale DESC
            LIMIT " . (int) $limit;

        return $this->db->query($sql)->result_array();
    }

    // ------------------------------------------------------------------
    // Sub-phase D - Hot Lead Monitor, Duplicate Monitor
    // ------------------------------------------------------------------

    /**
     * The id of the tblleads_status row literally named "Hot" on this
     * install, matched by name at read time - same documented approach
     * already used for seeding the Hot-specific SLA rules in Sub-phase C
     * (leadgen_control_tower_migrate_to_v2). Returns null if no such status
     * exists, which the Hot Lead Monitor screen handles by showing an
     * explanatory empty state rather than an error.
     */
    public function get_hot_status_id()
    {
        $row = $this->db->where('name', 'Hot')->get(db_prefix() . 'leads_status')->row();

        return $row ? (int) $row->id : null;
    }

    /**
     * The active SLA threshold for one stage (assignment/first_response)
     * scoped specifically to the Hot status, if a Hot-specific rule exists;
     * otherwise falls back to the same global-default lookup the other
     * live-list screens use (get_default_stage_threshold), then to
     * $fallback_minutes if no rule at all is configured.
     */
    public function get_hot_lead_stage_threshold($stage, $hot_status_id, $fallback_minutes)
    {
        if ($hot_status_id) {
            $this->db->where('stage', $stage);
            $this->db->where('active', 1);
            $this->db->where('applies_to_type', 'status');
            $this->db->where('applies_to_id', $hot_status_id);
            $this->db->order_by('id', 'asc');
            $this->db->limit(1);
            $row = $this->db->get(db_prefix() . 'leadgen_control_tower_sla_rules')->row();

            if ($row) {
                return (int) $row->threshold_minutes;
            }
        }

        return $this->get_default_stage_threshold($stage, $fallback_minutes);
    }

    /**
     * Hot Lead Monitor list - every active lead currently in the Hot status,
     * newest first. Always a live query against tblleads directly (same
     * convention as Unassigned/Missed-Followup/Stale Leads in Sub-phase C),
     * not dependent on the cron having run. Breach flags for assignment and
     * first-response are computed here in PHP against the thresholds passed
     * in, rather than joining sla_events, so this screen is accurate even
     * between cron runs and even for a lead the SLA engine has not yet
     * evaluated.
     */
    public function get_hot_leads($assignment_threshold, $first_response_threshold, $hot_status_id, $limit = 200)
    {
        if (!$hot_status_id) {
            return array();
        }

        $sql = "SELECT l.id as lead_id, l.name as lead_name, l.company, l.phonenumber, l.email,
                l.dateadded, l.dateassigned, l.assigned, l.lastcontact,
                CONCAT(st.firstname, ' ', st.lastname) as assigned_name,
                s.name as source_name,
                TIMESTAMPDIFF(MINUTE, l.dateadded, NOW()) as minutes_since_added,
                CASE WHEN l.assigned IS NOT NULL AND l.assigned != 0 AND l.dateassigned IS NOT NULL
                    THEN TIMESTAMPDIFF(MINUTE, l.dateassigned, NOW()) ELSE NULL END as minutes_since_assigned
            FROM " . db_prefix() . "leads l
            LEFT JOIN " . db_prefix() . "staff st ON st.staffid = l.assigned
            LEFT JOIN " . db_prefix() . "leads_sources s ON s.id = l.source
            WHERE l.lost = 0 AND l.junk = 0 AND l.date_converted IS NULL
                AND l.status = " . (int) $hot_status_id . "
            ORDER BY l.dateadded DESC
            LIMIT " . (int) $limit;

        $rows = $this->db->query($sql)->result_array();

        foreach ($rows as &$row) {
            $is_assigned = !empty($row['assigned']);
            $contacted = !empty($row['lastcontact']) && !empty($row['dateassigned'])
                && strtotime($row['lastcontact']) >= strtotime($row['dateassigned']);

            $row['assignment_breached'] = !$is_assigned
                && (int) $row['minutes_since_added'] > (int) $assignment_threshold;

            $row['first_response_breached'] = $is_assigned && !$contacted
                && $row['minutes_since_assigned'] !== null
                && (int) $row['minutes_since_assigned'] > (int) $first_response_threshold;

            $row['contacted'] = $contacted;
        }

        return $rows;
    }

    /**
     * Duplicate Monitor list - candidate pairs populated by
     * leadgen_control_tower_detect_duplicate_candidates(), joined to both
     * leads names/companies so the view needs no extra lookups. Optional
     * $status filter (pending/merged/rejected); null returns every status.
     */
    public function get_duplicate_candidates($status = null, $limit = 200)
    {
        $this->db->select('d.*,
            la.name as lead_a_name, la.company as lead_a_company, la.phonenumber as lead_a_phone, la.email as lead_a_email,
            lb.name as lead_b_name, lb.company as lead_b_company, lb.phonenumber as lead_b_phone, lb.email as lead_b_email,
            CONCAT(rv.firstname, " ", rv.lastname) as reviewed_by_name');
        $this->db->from(db_prefix() . 'leadgen_control_tower_duplicate_candidates d');
        $this->db->join(db_prefix() . 'leads la', 'la.id = d.lead_id_a');
        $this->db->join(db_prefix() . 'leads lb', 'lb.id = d.lead_id_b');
        $this->db->join(db_prefix() . 'staff rv', 'rv.staffid = d.reviewed_by', 'left');

        if ($status !== null && $status !== '') {
            $this->db->where('d.status', $status);
        }

        $this->db->order_by('d.status = "pending"', '', false);
        $this->db->order_by('d.confidence_score', 'desc');
        $this->db->order_by('d.date_created', 'desc');
        $this->db->limit($limit);

        return $this->db->get()->result_array();
    }

    public function get_duplicate_candidate($id)
    {
        $this->db->where('id', (int) $id);

        return $this->db->get(db_prefix() . 'leadgen_control_tower_duplicate_candidates')->row_array();
    }

    public function get_duplicate_candidate_counts()
    {
        $sql = "SELECT status, COUNT(*) as c
            FROM " . db_prefix() . "leadgen_control_tower_duplicate_candidates
            GROUP BY status";

        $rows = $this->db->query($sql)->result_array();

        $out = array('pending' => 0, 'merged' => 0, 'rejected' => 0);
        foreach ($rows as $row) {
            if (isset($out[$row['status']])) {
                $out[$row['status']] = (int) $row['c'];
            }
        }

        return $out;
    }

    /**
     * Records a staff decision on one duplicate candidate pair - 'merged'
     * (with $master_lead_id set to whichever of the pairs two lead ids
     * staff identified as the one to keep) or 'rejected' (not actually a
     * duplicate). This only ever updates the candidate row itself; it does
     * not touch tblleads or move any data - see the detection engines
     * docblock in the helper file for why actual data consolidation is left
     * to staff working in the existing Leads UI.
     */
    public function resolve_duplicate_candidate($id, $status, $master_lead_id, $reviewed_by)
    {
        $this->db->where('id', (int) $id);
        $this->db->update(db_prefix() . 'leadgen_control_tower_duplicate_candidates', array(
            'status'          => $status,
            'master_lead_id'  => $master_lead_id,
            'reviewed_by'     => $reviewed_by,
            'date_reviewed'   => date('Y-m-d H:i:s'),
        ));
    }
}
