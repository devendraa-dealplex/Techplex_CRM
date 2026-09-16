<?php defined('BASEPATH') or exit('No direct script access allowed');
class Leadgen_distribution extends AdminController {
public function __construct() { parent::__construct(); }
public function index() { if ($this->input->post()) { $this->_save_settings(); }
$data['staff'] = $this->db->where('active', 1)->get('staff')->result_array();
$selected = @unserialize(get_option('leadgen_distribution_agents')); $data['selected_agents'] = is_array($selected) ? $selected : [];
$data['enabled'] = get_option('leadgen_distribution_enabled');
$data['recent_log'] = $this->db->select(db_prefix() . 'leadgen_distribution_log.*, ' . db_prefix() . 'leads.name as lead_name, ' . db_prefix() . 'staff.firstname, ' . db_prefix() . 'staff.lastname')->join(db_prefix() . 'leads', db_prefix() . 'leads.id = ' . db_prefix() . 'leadgen_distribution_log.lead_id', 'left')->join(db_prefix() . 'staff', db_prefix() . 'staff.staffid = ' . db_prefix() . 'leadgen_distribution_log.assigned_staff_id', 'left')->order_by(db_prefix() . 'leadgen_distribution_log.id', 'desc')->limit(20)->get('leadgen_distribution_log')->result_array();
$data['title'] = _l('leadgen_distribution_settings');
$this->load->view('leadgen_distribution/settings', $data); }
private function _save_settings() { $enabled = $this->input->post('leadgen_distribution_enabled') ? '1' : '0'; update_option('leadgen_distribution_enabled', $enabled);
$agents = $this->input->post('leadgen_distribution_agents'); $agents = is_array($agents) ? array_map('intval', $agents) : []; update_option('leadgen_distribution_agents', serialize($agents));
set_alert('success', _l('settings_updated'));
log_activity('Leadgen Distribution Settings Updated');
redirect(admin_url('leadgen_distribution')); }
}