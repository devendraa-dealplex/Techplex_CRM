<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Writes one row to tblleadgen_control_tower_audit_logs. Called from the
 * controller for every administrative write (settings changes now; SLA rule
 * changes, alert acknowledgements, assignment overrides, merges, etc. in
 * later sub-phases). $before / $after are arrays and are stored as JSON -
 * pass null for either when there's nothing meaningful to compare (e.g. a
 * pure "viewed" action, though those aren't currently logged to keep this
 * table from growing unnecessarily).
 */
if (!function_exists('leadgen_control_tower_log_action')) {
    function leadgen_control_tower_log_action($action, $subject_type = null, $subject_id = null, $before = null, $after = null)
    {
        $CI = &get_instance();

        $CI->db->insert(db_prefix() . 'leadgen_control_tower_audit_logs', array(
            'staff_id'     => get_staff_user_id(),
            'action'       => $action,
            'subject_type' => $subject_type,
            'subject_id'   => $subject_id,
            'before_json'  => $before !== null ? json_encode($before) : null,
            'after_json'   => $after !== null ? json_encode($after) : null,
            'ip_address'   => $CI->input->ip_address(),
            'date_created' => date('Y-m-d H:i:s'),
        ));
    }
}

/**
 * Reads one module setting (tblleadgen_control_tower_settings) with a default
 * fallback, so pages never have to special-case "not set yet".
 */
if (!function_exists('leadgen_control_tower_get_setting')) {
    function leadgen_control_tower_get_setting($key, $default = null)
    {
        $CI = &get_instance();

        $CI->db->where('setting_key', $key);
        $row = $CI->db->get(db_prefix() . 'leadgen_control_tower_settings')->row();

        return $row ? $row->setting_value : $default;
    }
}

/**
 * Writes one module setting, upserting by setting_key.
 */
if (!function_exists('leadgen_control_tower_set_setting')) {
    function leadgen_control_tower_set_setting($key, $value)
    {
        $CI = &get_instance();

        $CI->db->where('setting_key', $key);
        $existing = $CI->db->get(db_prefix() . 'leadgen_control_tower_settings')->row();

        $data = array(
            'setting_value' => $value,
            'updated_by'    => get_staff_user_id(),
            'date_updated'  => date('Y-m-d H:i:s'),
        );

        if ($existing) {
            $CI->db->where('setting_key', $key);
            $CI->db->update(db_prefix() . 'leadgen_control_tower_settings', $data);
        } else {
            $data['setting_key'] = $key;
            $CI->db->insert(db_prefix() . 'leadgen_control_tower_settings', $data);
        }
    }
}

/**
 * Sub-phase B - Lead Health Monitor calculation.
 *
 * Recalculates health_status for every row in tblleads and upserts it into
 * tblleadgen_control_tower_health, in one set-based SQL statement (no
 * per-lead PHP loop, so this stays fast regardless of table size).
 *
 * Rules (v1 - documented simplification, confirmed against the real
 * tblleads columns via the schema check run before this was written):
 *   - lost = 1              -> grey / lost
 *   - junk > 0               -> grey / junk
 *   - date_converted set      -> grey / converted
 *   - unassigned (active)    -> red  / unassigned
 *   - last activity <= 24h   -> green / recent_activity
 *   - last activity <= 72h   -> amber / activity_ageing
 *   - last activity > 72h    -> red  / activity_stale
 * "Last activity" = the most recent of lastcontact, last_status_change,
 * dateadded.
 *
 * This is a plain elapsed-hours v1, not yet business-hours-aware (the
 * business_hours_start / business_hours_end setting exists but is not
 * consumed here). Business-hours-aware thresholds are part of the full SLA
 * engine planned for Sub-phase C in the design doc's build order - wiring
 * that in now would mean re-deriving these same rules twice. Flagged in the
 * Sub-phase B changelog for confirmation/adjustment together with the
 * status/source mapping open decision.
 *
 * Called by the after_cron_run hook (leadgen_control_tower_health_cron in
 * the module bootstrap) and by the manual "Recalculate Now" button on the
 * Lead Health Monitor page. Runs no destructive writes - every lead gets a
 * row, existing rows are only ever updated, never deleted here.
 */
if (!function_exists('leadgen_control_tower_recalculate_lead_health')) {
    function leadgen_control_tower_recalculate_lead_health()
    {
        $CI = &get_instance();

        $leads_table  = db_prefix() . 'leads';
        $health_table = db_prefix() . 'leadgen_control_tower_health';

        $activity_expr = "GREATEST(
                COALESCE(l.lastcontact, '1970-01-01 00:00:00'),
                COALESCE(l.last_status_change, '1970-01-01 00:00:00'),
                COALESCE(l.dateadded, '1970-01-01 00:00:00')
            )";

        $sql = "INSERT INTO `{$health_table}` (lead_id, health_status, reason_code, reason_text, date_calculated)
            SELECT
                l.id,
                CASE
                    WHEN l.lost = 1 THEN 'grey'
                    WHEN l.junk > 0 THEN 'grey'
                    WHEN l.date_converted IS NOT NULL THEN 'grey'
                    WHEN l.assigned = 0 OR l.assigned IS NULL THEN 'red'
                    WHEN TIMESTAMPDIFF(HOUR, {$activity_expr}, NOW()) <= 24 THEN 'green'
                    WHEN TIMESTAMPDIFF(HOUR, {$activity_expr}, NOW()) <= 72 THEN 'amber'
                    ELSE 'red'
                END AS health_status,
                CASE
                    WHEN l.lost = 1 THEN 'lost'
                    WHEN l.junk > 0 THEN 'junk'
                    WHEN l.date_converted IS NOT NULL THEN 'converted'
                    WHEN l.assigned = 0 OR l.assigned IS NULL THEN 'unassigned'
                    WHEN TIMESTAMPDIFF(HOUR, {$activity_expr}, NOW()) <= 24 THEN 'recent_activity'
                    WHEN TIMESTAMPDIFF(HOUR, {$activity_expr}, NOW()) <= 72 THEN 'activity_ageing'
                    ELSE 'activity_stale'
                END AS reason_code,
                CASE
                    WHEN l.lost = 1 THEN 'Lead marked lost'
                    WHEN l.junk > 0 THEN 'Lead marked junk or spam'
                    WHEN l.date_converted IS NOT NULL THEN 'Lead already converted to client'
                    WHEN l.assigned = 0 OR l.assigned IS NULL THEN 'Lead has no assigned staff member'
                    WHEN TIMESTAMPDIFF(HOUR, {$activity_expr}, NOW()) <= 24 THEN 'Activity recorded within the last 24 hours'
                    WHEN TIMESTAMPDIFF(HOUR, {$activity_expr}, NOW()) <= 72 THEN 'No activity for between 24 and 72 hours'
                    ELSE 'No activity for more than 72 hours'
                END AS reason_text,
                NOW()
            FROM `{$leads_table}` l
            ON DUPLICATE KEY UPDATE
                health_status = VALUES(health_status),
                reason_code = VALUES(reason_code),
                reason_text = VALUES(reason_text),
                date_calculated = VALUES(date_calculated)";

        $CI->db->query($sql);

        return $CI->db->affected_rows();
    }
}

/**
 * Sub-phase C - SLA breach event engine.
 *
 * For every active SLA rule, this opens a new event row in
 * tblleadgen_control_tower_sla_events for any lead currently breaching that
 * rule that does not already have an OPEN event for it (date_completed IS
 * NULL), and closes (sets date_completed) any open event whose underlying
 * condition no longer holds - the lead got assigned/contacted/followed up/
 * its status changed, or it left the active pipeline (lost/junk/converted).
 * Events already closed are never touched again, matching the table's
 * intended use as an audit trail rather than a live-recalculated cache
 * (contrast with the health table in Sub-phase B, which is a pure upsert
 * snapshot) - see the Sub-phase C changelog for this design choice.
 *
 * Four stages are supported in this sub-phase, each with its own condition
 * against the real tblleads columns (confirmed live via the schema check
 * run before Sub-phase B):
 *   assignment      - active lead, unassigned, longer than threshold since dateadded
 *   first_response  - active lead, assigned, no contact recorded since dateassigned, longer than threshold
 *   followup        - active lead, assigned, no tblleadgen_followup_log entry (or none ever) longer than threshold
 *   stale           - active lead, status unchanged longer than threshold
 *
 * A rule's business_hours_only flag is stored but not yet enforced here -
 * same documented v1 simplification as the health engine in Sub-phase B.
 * A rule's applies_to_type/applies_to_id ('status', a tblleads_status id)
 * narrows the rule to leads currently in that status; NULL applies to
 * every active lead in that stage.
 *
 * Runs a small, fixed number of queries (two per active rule), not one per
 * lead, so this stays fast regardless of table size. Called by the
 * after_cron_run hook and the manual "Recalculate Now" button on the SLA
 * Monitor page.
 */
if (!function_exists('leadgen_control_tower_recalculate_sla_events')) {
    function leadgen_control_tower_recalculate_sla_events()
    {
        $CI = &get_instance();

        $leads_table    = db_prefix() . 'leads';
        $rules_table    = db_prefix() . 'leadgen_control_tower_sla_rules';
        $events_table   = db_prefix() . 'leadgen_control_tower_sla_events';
        $followup_table = db_prefix() . 'leadgen_followup_log';

        $active_base = "l.lost = 0 AND l.junk = 0 AND l.date_converted IS NULL";

        $rules = $CI->db->where('active', 1)->get($rules_table)->result_array();

        $opened = 0;
        $closed = 0;

        foreach ($rules as $rule) {
            $rule_id    = (int) $rule['id'];
            $threshold  = (int) $rule['threshold_minutes'];
            $stage      = $rule['stage'];

            // Defense in depth: only ever act on the four known stages,
            // even if a row somehow exists with something else in it
            // (this module's own forms restrict stage to this same list).
            if (!in_array($stage, array('assignment', 'first_response', 'followup', 'stale'), true)) {
                continue;
            }
            $stage_escaped = $CI->db->escape($stage);

            $scope_sql  = '1=1';

            if ($rule['applies_to_type'] === 'status' && $rule['applies_to_id'] !== null) {
                $scope_sql = 'l.status = ' . (int) $rule['applies_to_id'];
            }

            if ($stage === 'assignment') {
                $started_expr = 'l.dateadded';
                $condition = "{$active_base} AND (l.assigned = 0 OR l.assigned IS NULL)"
                    . " AND TIMESTAMPDIFF(MINUTE, l.dateadded, NOW()) > {$threshold}"
                    . " AND {$scope_sql}";
                $from_sql = "`{$leads_table}` l";
            } elseif ($stage === 'first_response') {
                $started_expr = 'l.dateassigned';
                $condition = "{$active_base} AND l.assigned IS NOT NULL AND l.assigned != 0"
                    . " AND l.dateassigned IS NOT NULL"
                    . " AND (l.lastcontact IS NULL OR l.lastcontact < l.dateassigned)"
                    . " AND TIMESTAMPDIFF(MINUTE, l.dateassigned, NOW()) > {$threshold}"
                    . " AND {$scope_sql}";
                $from_sql = "`{$leads_table}` l";
            } elseif ($stage === 'followup') {
                $started_expr = 'COALESCE(fl.last_followup, l.dateadded)';
                $condition = "{$active_base} AND l.assigned IS NOT NULL AND l.assigned != 0"
                    . " AND TIMESTAMPDIFF(MINUTE, COALESCE(fl.last_followup, l.dateadded), NOW()) > {$threshold}"
                    . " AND {$scope_sql}";
                $from_sql = "`{$leads_table}` l LEFT JOIN (
                        SELECT leadid, MAX(date_sent) as last_followup
                        FROM `{$followup_table}`
                        GROUP BY leadid
                    ) fl ON fl.leadid = l.id";
            } elseif ($stage === 'stale') {
                $started_expr = 'COALESCE(l.last_status_change, l.dateadded)';
                $condition = "{$active_base}"
                    . " AND TIMESTAMPDIFF(MINUTE, COALESCE(l.last_status_change, l.dateadded), NOW()) > {$threshold}"
                    . " AND {$scope_sql}";
                $from_sql = "`{$leads_table}` l";
            } else {
                // Unknown/future stage - skip rather than guess.
                continue;
            }

            $due_expr = "DATE_ADD({$started_expr}, INTERVAL {$threshold} MINUTE)";

            $open_sql = "INSERT INTO `{$events_table}` (lead_id, sla_rule_id, stage, date_started, date_due, breached, date_created)
                SELECT l.id, {$rule_id}, {$stage_escaped}, {$started_expr}, {$due_expr}, 1, NOW()
                FROM {$from_sql}
                WHERE {$condition}
                AND NOT EXISTS (
                    SELECT 1 FROM `{$events_table}` e
                    WHERE e.lead_id = l.id AND e.sla_rule_id = {$rule_id} AND e.date_completed IS NULL
                )";

            $CI->db->query($open_sql);
            $opened += $CI->db->affected_rows();

            $close_sql = "UPDATE `{$events_table}` e
                JOIN `{$leads_table}` l ON l.id = e.lead_id
                " . ($stage === 'followup' ? "LEFT JOIN (
                        SELECT leadid, MAX(date_sent) as last_followup
                        FROM `{$followup_table}`
                        GROUP BY leadid
                    ) fl ON fl.leadid = l.id" : '') . "
                SET e.date_completed = NOW()
                WHERE e.sla_rule_id = {$rule_id} AND e.date_completed IS NULL
                AND NOT ({$condition})";

            $CI->db->query($close_sql);
            $closed += $CI->db->affected_rows();
        }

        return array('opened' => $opened, 'closed' => $closed);
    }
}

/**
 * Sub-phase D - duplicate-candidate detection.
 *
 * Finds pairs of active leads (lost = 0, junk = 0, date_converted IS NULL)
 * that appear to be the same person, on two independent signals, each a
 * single set-based INSERT...SELECT...WHERE NOT EXISTS statement (no
 * per-lead PHP loop, matching every other engine in this module):
 *
 *   1. phone_match  - same phone number once punctuation/spaces are
 *      stripped and compared on the last 10 digits (so a stored "+91"
 *      country-code prefix does not itself prevent a match). Confidence 90.
 *   2. email_match   - same email address, case-insensitive, trimmed.
 *      Confidence 80. Only inserted for a pair not already flagged by the
 *      phone check above (the NOT EXISTS guard on the pair applies
 *      regardless of match_reason), so a pair that matches on both signals
 *      is recorded once, as the higher-confidence phone_match.
 *
 * A pair is only ever inserted once, ever - the NOT EXISTS guard checks for
 * ANY existing row for that (lead_id_a, lead_id_b) pair regardless of its
 * current status, so a pair a staff member has already reviewed (merged or
 * rejected) never reappears just because both leads are still active. This
 * mirrors the sla_events table's append-only-until-decided design: once a
 * human has made a call on a pair, this engine leaves it alone.
 *
 * Deliberately does NOT move, merge, or delete any lead data - it only
 * detects and records candidate pairs for a human to review on the
 * Duplicate Monitor screen. Actually consolidating two leads is a
 * significant, judgment-heavy CRM operation (which contacts/tasks/notes/
 * proposals move where) that this sub-phase intentionally leaves to staff
 * doing it manually in the existing Leads UI after marking a pair
 * "merged" here and noting which lead is the master - see the Sub-phase D
 * changelog for this documented scope decision, consistent with the
 * standing rule to never risk automated data loss.
 *
 * Not optimized for very large lead volumes (the phone/email equality
 * checks are wrapped in SQL functions, so they cannot use a plain index) -
 * acceptable at this installs current lead volume, but flagged in the
 * changelog as a known simplification, same spirit as the "not tested at
 * 1M-lead scale" caveat the spec itself raises in the design doc's Section 0
 * scope-reality-check.
 *
 * Called by the after_cron_run hook and the manual "Detect Now" button on
 * the Duplicate Monitor page.
 */
if (!function_exists('leadgen_control_tower_detect_duplicate_candidates')) {
    function leadgen_control_tower_detect_duplicate_candidates()
    {
        $CI = &get_instance();

        $leads_table = db_prefix() . 'leads';
        $dupes_table = db_prefix() . 'leadgen_control_tower_duplicate_candidates';

        $active_a = "a.lost = 0 AND a.junk = 0 AND a.date_converted IS NULL";
        $active_b = "b.lost = 0 AND b.junk = 0 AND b.date_converted IS NULL";
        $normalize = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(%s, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
        $norm_a = sprintf($normalize, 'a.phonenumber');
        $norm_b = sprintf($normalize, 'b.phonenumber');

        $phone_sql = "INSERT INTO `{$dupes_table}` (lead_id_a, lead_id_b, match_reason, confidence_score, status, date_created)
            SELECT a.id, b.id, 'phone_match', 90.00, 'pending', NOW()
            FROM `{$leads_table}` a
            JOIN `{$leads_table}` b ON b.id > a.id
                AND RIGHT({$norm_b}, 10) = RIGHT({$norm_a}, 10)
            WHERE {$active_a} AND {$active_b}
                AND a.phonenumber IS NOT NULL AND TRIM(a.phonenumber) != ''
                AND b.phonenumber IS NOT NULL AND TRIM(b.phonenumber) != ''
                AND LENGTH({$norm_a}) >= 7 AND LENGTH({$norm_b}) >= 7
                AND NOT EXISTS (
                    SELECT 1 FROM `{$dupes_table}` d
                    WHERE d.lead_id_a = a.id AND d.lead_id_b = b.id
                )";

        $CI->db->query($phone_sql);
        $phone_matches = $CI->db->affected_rows();

        $email_sql = "INSERT INTO `{$dupes_table}` (lead_id_a, lead_id_b, match_reason, confidence_score, status, date_created)
            SELECT a.id, b.id, 'email_match', 80.00, 'pending', NOW()
            FROM `{$leads_table}` a
            JOIN `{$leads_table}` b ON b.id > a.id
                AND LOWER(TRIM(b.email)) = LOWER(TRIM(a.email))
            WHERE {$active_a} AND {$active_b}
                AND a.email IS NOT NULL AND TRIM(a.email) != ''
                AND b.email IS NOT NULL AND TRIM(b.email) != ''
                AND NOT EXISTS (
                    SELECT 1 FROM `{$dupes_table}` d
                    WHERE d.lead_id_a = a.id AND d.lead_id_b = b.id
                )";

        $CI->db->query($email_sql);
        $email_matches = $CI->db->affected_rows();

        return array('phone_matches' => $phone_matches, 'email_matches' => $email_matches);
    }
}
