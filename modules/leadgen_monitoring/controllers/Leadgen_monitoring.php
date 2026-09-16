<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Leadgen_monitoring extends AdminController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $CI = &get_instance();
        $prefix = db_prefix();
        $data = [];

        // ---- Lead volume by channel ----
        $data['total_leads'] = (int) $CI->db->count_all($prefix . 'leads');

        $data['whatsapp_leads'] = (int) $CI->db->query(
            "SELECT COUNT(DISTINCT lead_id) as cnt FROM {$prefix}leadgen_whatsapp_messages WHERE lead_id IS NOT NULL"
        )->row()->cnt;

        $data['facebook_leads'] = (int) $CI->db->query(
            "SELECT COUNT(DISTINCT lead_id) as cnt FROM {$prefix}leadgen_facebook_messages WHERE lead_id IS NOT NULL"
        )->row()->cnt;

        $data['google_leads'] = (int) $CI->db->query(
            "SELECT COUNT(DISTINCT lead_id) as cnt FROM {$prefix}leadgen_google_leads WHERE lead_id IS NOT NULL"
        )->row()->cnt;

        $data['other_leads'] = (int) $CI->db->query(
            "SELECT COUNT(*) as cnt FROM {$prefix}leads l WHERE l.id NOT IN (
                SELECT lead_id FROM {$prefix}leadgen_whatsapp_messages WHERE lead_id IS NOT NULL
                UNION SELECT lead_id FROM {$prefix}leadgen_facebook_messages WHERE lead_id IS NOT NULL
                UNION SELECT lead_id FROM {$prefix}leadgen_google_leads WHERE lead_id IS NOT NULL
            )"
        )->row()->cnt;

        $data['facebook_by_channel'] = $CI->db->query(
            "SELECT channel, COUNT(DISTINCT lead_id) as cnt FROM {$prefix}leadgen_facebook_messages WHERE lead_id IS NOT NULL GROUP BY channel"
        )->result();

        $data['google_by_source'] = $CI->db->query(
            "SELECT source_type, COUNT(DISTINCT lead_id) as cnt FROM {$prefix}leadgen_google_leads WHERE lead_id IS NOT NULL GROUP BY source_type"
        )->result();

        // ---- Distribution overview ----
        $data['distribution_enabled'] = get_option('leadgen_distribution_enabled');
        $data['distribution_total'] = (int) $CI->db->count_all($prefix . 'leadgen_distribution_log');

        $data['distribution_by_agent'] = $CI->db->query(
            "SELECT s.staffid, CONCAT(s.firstname, ' ', s.lastname) as staff_name, COUNT(*) as cnt
             FROM {$prefix}leadgen_distribution_log d
             JOIN {$prefix}staff s ON s.staffid = d.assigned_staff_id
             GROUP BY d.assigned_staff_id
             ORDER BY cnt DESC"
        )->result();

        $data['distribution_recent'] = $CI->db->query(
            "SELECT d.date_assigned, l.name as lead_name, CONCAT(s.firstname, ' ', s.lastname) as staff_name
             FROM {$prefix}leadgen_distribution_log d
             LEFT JOIN {$prefix}leads l ON l.id = d.lead_id
             LEFT JOIN {$prefix}staff s ON s.staffid = d.assigned_staff_id
             ORDER BY d.date_assigned DESC
             LIMIT 5"
        )->result();

        // ---- Follow-up overview ----
        $data['followup_enabled'] = get_option('leadgen_followup_enabled');
        $data['followup_total'] = (int) $CI->db->count_all($prefix . 'leadgen_followup_log');

        $data['followup_by_stage'] = $CI->db->query(
            "SELECT stage_day, COUNT(*) as cnt FROM {$prefix}leadgen_followup_log GROUP BY stage_day ORDER BY stage_day"
        )->result();

        $data['followup_action_counts'] = $CI->db->query(
            "SELECT
                SUM(FIND_IN_SET('agent_reminder', action_taken) > 0) as agent_reminder_count,
                SUM(FIND_IN_SET('lead_email', action_taken) > 0) as lead_email_count,
                SUM(FIND_IN_SET('task_created', action_taken) > 0) as task_created_count
             FROM {$prefix}leadgen_followup_log"
        )->row();

        $data['followup_recent'] = $CI->db->query(
            "SELECT f.date_sent, f.stage_day, f.action_taken, l.name as lead_name, CONCAT(s.firstname, ' ', s.lastname) as staff_name
             FROM {$prefix}leadgen_followup_log f
             LEFT JOIN {$prefix}leads l ON l.id = f.leadid
             LEFT JOIN {$prefix}staff s ON s.staffid = f.staffid
             ORDER BY f.date_sent DESC
             LIMIT 5"
        )->result();

        $this->load->view('leadgen_monitoring/dashboard', $data);
    }
}
