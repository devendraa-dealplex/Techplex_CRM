<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Thin controller - Sub-phase A. Only three actions exist yet: a placeholder
 * dashboard landing page, the Settings screen, and the Audit Logs list.
 * Every future sub-phase's screens (SLA Monitor, Stale Leads, Alert Centre,
 * etc.) get added as their own method here, each behind its own
 * staff_can(..., 'leadgen_control_tower') check - never widening what an
 * existing permission already grants.
 */
class Leadgen_control_tower extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->helper('leadgen_control_tower');
        $this->load->model('leadgen_control_tower_model');
    }

    /**
     * Live Dashboard - Sub-phase B. Real summary cards, source/status
     * breakdown and the lead-health summary strip, all read straight from
     * tblleads / tblleads_status / tblleads_sources / the health table -
     * every figure here is a live query, nothing fabricated or hardcoded.
     */
    public function index()
    {
        if (!staff_can('view_dashboard', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $data['title'] = _l('leadgen_control_tower_title');
        $data['schema_version'] = defined('LEADGEN_CONTROL_TOWER_SCHEMA_VERSION') ? LEADGEN_CONTROL_TOWER_SCHEMA_VERSION : null;
        $data['summary'] = $this->leadgen_control_tower_model->get_dashboard_summary();
        $data['by_source'] = $this->leadgen_control_tower_model->get_active_leads_by_source();
        $data['by_status'] = $this->leadgen_control_tower_model->get_active_leads_by_status();
        $data['health_counts'] = $this->leadgen_control_tower_model->get_health_summary_counts();

        // init_head()/init_tail() are called inside the view itself, matching
        // the established convention in this codebase (see e.g.
        // leadgen_followup/views/settings.php) - NOT here in the controller.
        // A prior module in this project (leadgen_monitoring) shipped with a
        // view missing those calls and rendered with no admin theme at all;
        // keeping the calls inside the view, consistently, is how that class
        // of bug is avoided going forward.
        $this->load->view('leadgen_control_tower/dashboard', $data);
    }

    /**
     * Lead Health Monitor - Sub-phase B. Lists every lead that has a health
     * row (populated by the cron / manual recalculation), worst-health
     * first, optionally filtered to one health status via ?status=.
     *
     * Gated on view_dashboard for now rather than the finer view_all_leads /
     * view_team_leads / view_own_leads split in the permission matrix -
     * that split needs a confirmed staff hierarchy (design doc Section 13,
     * open decision 4), which is not yet confirmed. Documented as a known
     * Sub-phase B simplification in the changelog; scoping by team/own
     * leads is layered on once that decision is made, without changing this
     * URL or breaking anything already using it.
     */
    public function lead_health()
    {
        if (!staff_can('view_dashboard', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $status_filter = $this->input->get('status');
        $allowed = array('green', 'amber', 'red', 'grey');
        if (!in_array($status_filter, $allowed, true)) {
            $status_filter = null;
        }

        $data['title'] = _l('leadgen_control_tower_lead_health');
        $data['status_filter'] = $status_filter;
        $data['health_counts'] = $this->leadgen_control_tower_model->get_health_summary_counts();
        $data['leads'] = $this->leadgen_control_tower_model->get_lead_health_list($status_filter);
        $data['can_recalculate'] = staff_can('manage_settings', 'leadgen_control_tower');

        $this->load->view('leadgen_control_tower/lead_health', $data);
    }

    /**
     * Manual "Recalculate Now" trigger for the health engine, for use
     * between scheduled cron runs while verifying Sub-phase B. Runs the
     * same read/upsert-only logic as the cron job - no leads are read
     * outside tblleads, nothing is deleted.
     */
    public function lead_health_recalculate()
    {
        if (!staff_can('manage_settings', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        leadgen_control_tower_recalculate_lead_health();

        leadgen_control_tower_log_action('lead_health_recalculated', 'health', null, null, null);

        set_alert('success', _l('leadgen_control_tower_health_recalculated'));
        redirect(admin_url('leadgen_control_tower/lead_health'));
    }

    /**
     * SLA Rules - Sub-phase C. List + add/edit form on one page (no modal),
     * gated on manage_sla. Stage is restricted to the four stages this
     * sub-phase's engine understands - assignment / first_response /
     * followup / stale - so a typo here can never silently create a rule
     * the recalculation engine will not act on.
     */
    public function sla_rules()
    {
        if (!staff_can('manage_sla', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $data['title'] = _l('leadgen_control_tower_sla_rules');
        $data['rules'] = $this->leadgen_control_tower_model->get_sla_rules();
        $data['statuses'] = $this->db->get(db_prefix() . 'leads_status')->result_array();
        $data['edit_rule'] = null;

        $edit_id = $this->input->get('edit');
        if ($edit_id) {
            $data['edit_rule'] = $this->leadgen_control_tower_model->get_sla_rule($edit_id);
        }

        $this->load->view('leadgen_control_tower/sla_rules', $data);
    }

    /**
     * Create or update one SLA rule. Every field is validated server-side
     * (stage whitelist, positive integer threshold) regardless of what the
     * form sends, since this value flows directly into SQL built by the
     * recalculation helper.
     */
    public function sla_rule_save()
    {
        if (!staff_can('manage_sla', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $allowed_stages = array('assignment', 'first_response', 'followup', 'stale');
        $stage = $this->input->post('stage');
        if (!in_array($stage, $allowed_stages, true)) {
            set_alert('danger', _l('leadgen_control_tower_sla_invalid_stage'));
            redirect(admin_url('leadgen_control_tower/sla_rules'));
        }

        $threshold = (int) $this->input->post('threshold_minutes');
        if ($threshold <= 0) {
            set_alert('danger', _l('leadgen_control_tower_sla_invalid_threshold'));
            redirect(admin_url('leadgen_control_tower/sla_rules'));
        }

        $applies_status = $this->input->post('applies_to_status');
        $data = array(
            'name'                 => trim((string) $this->input->post('name')),
            'stage'                => $stage,
            'threshold_minutes'    => $threshold,
            'business_hours_only'  => $this->input->post('business_hours_only') ? 1 : 0,
            'active'               => $this->input->post('active') ? 1 : 0,
            'applies_to_type'      => $applies_status ? 'status' : null,
            'applies_to_id'        => $applies_status ? (int) $applies_status : null,
        );

        $rule_id = (int) $this->input->post('id');

        if ($rule_id) {
            $before = $this->leadgen_control_tower_model->get_sla_rule($rule_id);
            $this->leadgen_control_tower_model->update_sla_rule($rule_id, $data);
            leadgen_control_tower_log_action('sla_rule_updated', 'sla_rule', $rule_id, $before, $data);
        } else {
            $data['created_by'] = get_staff_user_id();
            $new_id = $this->leadgen_control_tower_model->create_sla_rule($data);
            leadgen_control_tower_log_action('sla_rule_created', 'sla_rule', $new_id, null, $data);
        }

        set_alert('success', _l('leadgen_control_tower_sla_rule_saved'));
        redirect(admin_url('leadgen_control_tower/sla_rules'));
    }

    public function sla_rule_delete($id)
    {
        /*
         * Deletion is a POST, not a GET.
         *
         * This was a plain anchor. A GET that deletes can be triggered by
         * anything that makes a signed-in administrator's browser issue a
         * request, and Perfex's CSRF token -- which only guards POST bodies --
         * never saw it. The client-side `_delete` confirm is a convenience, not
         * a control: it lives in the page an attacker is not using.
         *
         * 405 rather than a redirect is deliberate. A redirect would make a
         * blocked attempt look like ordinary navigation in the access log.
         *
         * CSRF itself is enforced by the framework -- csrf_protection is active
         * at runtime on this install, so a POST without a valid token is
         * rejected before routing -- and the view supplies the token through
         * form_open(). This method only has to insist on the verb.
         */
        if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST') {
            $this->output->set_status_header(405);

            return;
        }

        if (!staff_can('manage_sla', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $id = (int) $id;

        $before = $this->leadgen_control_tower_model->get_sla_rule($id);
        if ($before) {
            $this->leadgen_control_tower_model->delete_sla_rule($id);
            leadgen_control_tower_log_action('sla_rule_deleted', 'sla_rule', $id, $before, null);
            set_alert('success', _l('leadgen_control_tower_sla_rule_deleted'));
        }

        redirect(admin_url('leadgen_control_tower/sla_rules'));
    }

    /**
     * SLA Monitor - the consolidated board of currently-open SLA breach
     * events across all four stages, populated by the cron / manual
     * recalculation. Optional ?stage= filter.
     */
    public function sla_monitor()
    {
        if (!staff_can('view_dashboard', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $stage_filter = $this->input->get('stage');
        $allowed = array('assignment', 'first_response', 'followup', 'stale');
        if (!in_array($stage_filter, $allowed, true)) {
            $stage_filter = null;
        }

        $data['title'] = _l('leadgen_control_tower_sla_monitor');
        $data['stage_filter'] = $stage_filter;
        $data['stage_counts'] = $this->leadgen_control_tower_model->get_open_sla_breach_counts_by_stage();
        $data['breaches'] = $this->leadgen_control_tower_model->get_open_sla_breaches($stage_filter);
        $data['can_recalculate'] = staff_can('manage_settings', 'leadgen_control_tower');

        $this->load->view('leadgen_control_tower/sla_monitor', $data);
    }

    /**
     * Manual "Recalculate Now" trigger for the SLA breach engine, mirroring
     * lead_health_recalculate() from Sub-phase B.
     */
    public function sla_recalculate()
    {
        if (!staff_can('manage_settings', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $result = leadgen_control_tower_recalculate_sla_events();

        leadgen_control_tower_log_action('sla_recalculated', 'sla_events', null, null, $result);

        set_alert('success', _l('leadgen_control_tower_sla_recalculated'));
        redirect(admin_url('leadgen_control_tower/sla_monitor'));
    }

    /**
     * Unassigned Leads - Sub-phase C. Always a live query (see the model),
     * so this is accurate even if the cron has never run.
     */
    public function unassigned_leads()
    {
        if (!staff_can('view_dashboard', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $threshold = $this->leadgen_control_tower_model->get_default_stage_threshold('assignment', 30);

        $data['title'] = _l('leadgen_control_tower_unassigned_leads');
        $data['threshold_minutes'] = $threshold;
        $data['leads'] = $this->leadgen_control_tower_model->get_unassigned_leads($threshold);

        $this->load->view('leadgen_control_tower/unassigned_leads', $data);
    }

    /**
     * Missed Follow-Ups - Sub-phase C. Live query against
     * tblleadgen_followup_log + tblleads only (see the model docblock for
     * why tbltasks/tblreminders were deliberately left out of this sub-phase).
     */
    public function missed_followups()
    {
        if (!staff_can('view_dashboard', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $threshold = $this->leadgen_control_tower_model->get_default_stage_threshold('followup', 1440);

        $data['title'] = _l('leadgen_control_tower_missed_followups');
        $data['threshold_minutes'] = $threshold;
        $data['leads'] = $this->leadgen_control_tower_model->get_missed_followup_leads($threshold);

        $this->load->view('leadgen_control_tower/missed_followups', $data);
    }

    /**
     * Stale Leads - Sub-phase C. Live query, status-stagnation focused
     * (distinct from the broader "no activity" signal already shown on the
     * Lead Health Monitor - see the Sub-phase C changelog).
     */
    public function stale_leads()
    {
        if (!staff_can('view_dashboard', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $threshold = $this->leadgen_control_tower_model->get_default_stage_threshold('stale', 4320);

        $data['title'] = _l('leadgen_control_tower_stale_leads');
        $data['threshold_minutes'] = $threshold;
        $data['leads'] = $this->leadgen_control_tower_model->get_stale_leads($threshold);

        $this->load->view('leadgen_control_tower/stale_leads', $data);
    }

    /**
     * Hot Lead Monitor - Sub-phase D. Every active lead currently in the
     * Hot status, with assignment/first-response SLA breach flags computed
     * live against the Hot-specific SLA rules (falling back to the global
     * default, then a hardcoded fallback, exactly like the other live-list
     * screens in Sub-phase C).
     */
    public function hot_leads()
    {
        if (!staff_can('view_dashboard', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $hot_status_id = $this->leadgen_control_tower_model->get_hot_status_id();
        $assignment_threshold = $this->leadgen_control_tower_model->get_hot_lead_stage_threshold('assignment', $hot_status_id, 2);
        $first_response_threshold = $this->leadgen_control_tower_model->get_hot_lead_stage_threshold('first_response', $hot_status_id, 5);

        $data['title'] = _l('leadgen_control_tower_hot_leads');
        $data['hot_status_id'] = $hot_status_id;
        $data['assignment_threshold'] = $assignment_threshold;
        $data['first_response_threshold'] = $first_response_threshold;
        $data['leads'] = $this->leadgen_control_tower_model->get_hot_leads($assignment_threshold, $first_response_threshold, $hot_status_id);

        $this->load->view('leadgen_control_tower/hot_leads', $data);
    }

    /**
     * Duplicate Monitor - Sub-phase D. Lists candidate duplicate lead pairs
     * found by leadgen_control_tower_detect_duplicate_candidates(), with an
     * optional ?status= filter (pending/merged/rejected).
     */
    public function duplicate_monitor()
    {
        if (!staff_can('view_dashboard', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $status_filter = $this->input->get('status');
        $allowed = array('pending', 'merged', 'rejected');
        if (!in_array($status_filter, $allowed, true)) {
            $status_filter = null;
        }

        $data['title'] = _l('leadgen_control_tower_duplicate_monitor');
        $data['status_filter'] = $status_filter;
        $data['status_counts'] = $this->leadgen_control_tower_model->get_duplicate_candidate_counts();
        $data['candidates'] = $this->leadgen_control_tower_model->get_duplicate_candidates($status_filter);
        $data['can_merge'] = staff_can('merge_duplicates', 'leadgen_control_tower');
        $data['can_detect'] = staff_can('manage_settings', 'leadgen_control_tower');

        $this->load->view('leadgen_control_tower/duplicate_monitor', $data);
    }

    /**
     * Manual "Detect Now" trigger for the duplicate-candidate engine,
     * mirroring lead_health_recalculate() / sla_recalculate() - same
     * manage_settings gate used for every other manual-recalculation
     * button in this module, since this only generates candidate data, it
     * does not decide anything.
     */
    public function duplicate_detect()
    {
        if (!staff_can('manage_settings', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $result = leadgen_control_tower_detect_duplicate_candidates();

        leadgen_control_tower_log_action('duplicate_detect_run', 'duplicate_candidates', null, null, $result);

        set_alert('success', _l('leadgen_control_tower_duplicate_detected'));
        redirect(admin_url('leadgen_control_tower/duplicate_monitor'));
    }

    /**
     * Records a staff decision on one duplicate candidate pair - merge (with
     * a chosen master lead) or reject (not a duplicate). Gated on
     * merge_duplicates specifically, distinct from the view_dashboard gate
     * on the list screen itself, matching the permission matrix in the
     * design doc (Section 6). Does not touch tblleads or move any lead data
     * - see the model/helper docblocks for why actual consolidation is a
     * manual step left to staff in the existing Leads UI.
     */
    public function duplicate_resolve()
    {
        if (!staff_can('merge_duplicates', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $id = (int) $this->input->post('id');
        $decision = $this->input->post('decision');
        $allowed_decisions = array('merged', 'rejected');

        if (!$id || !in_array($decision, $allowed_decisions, true)) {
            set_alert('danger', _l('leadgen_control_tower_duplicate_invalid_decision'));
            redirect(admin_url('leadgen_control_tower/duplicate_monitor'));
        }

        $before = $this->leadgen_control_tower_model->get_duplicate_candidate($id);
        if (!$before) {
            redirect(admin_url('leadgen_control_tower/duplicate_monitor'));
        }

        $master_lead_id = null;
        if ($decision === 'merged') {
            $master_lead_id = (int) $this->input->post('master_lead_id');
            if ($master_lead_id !== (int) $before['lead_id_a'] && $master_lead_id !== (int) $before['lead_id_b']) {
                set_alert('danger', _l('leadgen_control_tower_duplicate_invalid_master'));
                redirect(admin_url('leadgen_control_tower/duplicate_monitor'));
            }
        }

        $this->leadgen_control_tower_model->resolve_duplicate_candidate($id, $decision, $master_lead_id, get_staff_user_id());

        leadgen_control_tower_log_action(
            'duplicate_' . $decision,
            'duplicate_candidate',
            $id,
            $before,
            array('status' => $decision, 'master_lead_id' => $master_lead_id)
        );

        set_alert('success', _l('leadgen_control_tower_duplicate_resolved'));
        redirect(admin_url('leadgen_control_tower/duplicate_monitor'));
    }

    /**
     * Settings screen. GET renders the form; POST validates + saves via the
     * helper's upsert function and writes one audit log row per change.
     */
    public function settings()
    {
        if (!staff_can('manage_settings', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        if ($this->input->post()) {
            $before = array(
                'business_hours_start' => leadgen_control_tower_get_setting('business_hours_start'),
                'business_hours_end'   => leadgen_control_tower_get_setting('business_hours_end'),
                'retention_days_audit' => leadgen_control_tower_get_setting('retention_days_audit'),
            );

            $start = $this->input->post('business_hours_start');
            $end   = $this->input->post('business_hours_end');
            $retention = (int) $this->input->post('retention_days_audit');

            leadgen_control_tower_set_setting('business_hours_start', $start);
            leadgen_control_tower_set_setting('business_hours_end', $end);
            leadgen_control_tower_set_setting('retention_days_audit', $retention > 0 ? $retention : 365);

            $after = array(
                'business_hours_start' => $start,
                'business_hours_end'   => $end,
                'retention_days_audit' => $retention,
            );

            leadgen_control_tower_log_action('settings_updated', 'settings', null, $before, $after);

            set_alert('success', _l('leadgen_control_tower_settings_saved'));
            redirect(admin_url('leadgen_control_tower/settings'));
        }

        $data['title'] = _l('leadgen_control_tower_settings');
        $data['business_hours_start'] = leadgen_control_tower_get_setting('business_hours_start', '09:00');
        $data['business_hours_end']   = leadgen_control_tower_get_setting('business_hours_end', '18:00');
        $data['retention_days_audit'] = leadgen_control_tower_get_setting('retention_days_audit', 365);

        $this->load->view('leadgen_control_tower/settings', $data);
    }

    /**
     * Audit Logs list - most recent 100 entries, newest first. Pagination /
     * filters are added later if the volume warrants it; Sub-phase A just
     * needs every administrative action to be visible somewhere.
     */
    public function audit_logs()
    {
        if (!staff_can('view_audit_logs', 'leadgen_control_tower')) {
            access_denied('leadgen_control_tower');
        }

        $data['title'] = _l('leadgen_control_tower_audit_logs');
        $data['logs'] = $this->leadgen_control_tower_model->get_recent_audit_logs(100);

        $this->load->view('leadgen_control_tower/audit_logs', $data);
    }

}
