<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Leadgen_followup extends AdminController
{
public function __construct()
{
parent::__construct();
}

/**
 * Reading these settings needs 'view'; changing them needs 'edit'.
 *
 * This controller had no authorization call of any kind — not staff_can, not
 * staff_cant, not has_permission, not is_admin — while the module registered
 * 'view' and 'edit' and enforced neither. The sidebar hid the link from most
 * staff, which is not security: /admin/leadgen_followup was reachable directly.
 *
 * What was reachable matters more here than on a normal settings page. This
 * screen switches the drip on and off and holds the subject line and body of
 * the message sent to leads from the company's address. Any staff member could
 * rewrite what the company says to its own prospects and customers, or silence
 * the engine entirely, and the only trace would be one log_activity line.
 *
 * It also lists the twenty most recent follow-ups with lead NAMES joined in, so
 * the page leaked lead data to staff with no lead permission at all.
 */
public function index()
{
if (!is_admin() && !staff_can('view', 'leadgen_followup')) {
access_denied('leadgen_followup');
}

if ($this->input->post()) {
if (!is_admin() && !staff_can('edit', 'leadgen_followup')) {
access_denied('leadgen_followup');
}
$this->_save_settings();
}

$data['enabled'] = get_option('leadgen_followup_enabled');
$data['outbound_enabled'] = get_option('leadgen_followup_outbound_enabled');
$stages = @unserialize(get_option('leadgen_followup_stages'));
$stages = is_array($stages) ? array_values($stages) : [1, 3, 7];
$data['stage1'] = isset($stages[0]) ? $stages[0] : '';
$data['stage2'] = isset($stages[1]) ? $stages[1] : '';
$data['stage3'] = isset($stages[2]) ? $stages[2] : '';
$data['remind_agent'] = get_option('leadgen_followup_remind_agent');
$data['email_lead'] = get_option('leadgen_followup_email_lead');
$data['create_task'] = get_option('leadgen_followup_create_task');
$data['task_due_days'] = get_option('leadgen_followup_task_due_days');
$data['email_subject'] = get_option('leadgen_followup_email_subject');
$data['email_body'] = get_option('leadgen_followup_email_body');

$data['recent_log'] = $this->db->select(db_prefix() . 'leadgen_followup_log.*, ' . db_prefix() . 'leads.name as lead_name, ' . db_prefix() . 'staff.firstname, ' . db_prefix() . 'staff.lastname')
->from('leadgen_followup_log')
->join('leads', db_prefix() . 'leads.id = ' . db_prefix() . 'leadgen_followup_log.leadid', 'left')
->join('staff', db_prefix() . 'staff.staffid = ' . db_prefix() . 'leadgen_followup_log.staffid', 'left')
->order_by('date_sent', 'desc')
->limit(20)
->get()->result_array();

$data['title'] = _l('leadgen_followup_settings');
$this->load->view('leadgen_followup/settings', $data);
}

private function _save_settings()
{
update_option('leadgen_followup_enabled', $this->input->post('enabled') ? '1' : '0');

/*
 * The outbound gate is administrator-only, and it is written only when an
 * administrator submits the form.
 *
 * The check is here and not only in the view. A hidden field is not a
 * permission: the control is rendered for administrators, and a non-admin who
 * posts `outbound_enabled=1` by hand would otherwise switch sending on from a
 * screen that never showed them the switch. Omitting the update entirely —
 * rather than writing '0' — means a non-admin save of the other settings leaves
 * the gate exactly as the administrator set it, in either direction.
 */
if (is_admin()) {
    update_option('leadgen_followup_outbound_enabled', $this->input->post('outbound_enabled') ? '1' : '0');
}

$stages = [];
foreach (['stage1', 'stage2', 'stage3'] as $key) {
$val = (int) $this->input->post($key);
if ($val > 0) {
    $stages[] = $val;
    }
    }
    sort($stages);
    update_option('leadgen_followup_stages', serialize($stages));
    
    update_option('leadgen_followup_remind_agent', $this->input->post('remind_agent') ? '1' : '0');
    update_option('leadgen_followup_email_lead', $this->input->post('email_lead') ? '1' : '0');
    update_option('leadgen_followup_create_task', $this->input->post('create_task') ? '1' : '0');
    
    $dueDays = (int) $this->input->post('task_due_days');
    update_option('leadgen_followup_task_due_days', $dueDays > 0 ? $dueDays : 2);
    
    update_option('leadgen_followup_email_subject', $this->input->post('email_subject'));
    update_option('leadgen_followup_email_body', $this->input->post('email_body'));
    
    set_alert('success', _l('settings_updated'));
    log_activity('Leadgen Followup Settings Updated');
    redirect(admin_url('leadgen_followup'));
    }
    }
    