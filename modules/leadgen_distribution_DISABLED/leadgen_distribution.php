<?php defined('BASEPATH') or exit('No direct script access allowed');
/*
Module Name: Lead Distribution
Description: Automatically distribute newly captured leads to your sales team using round-robin assignment, with instant CRM and email notification to the assigned agent.
Version: 1.0.0
Requires at least: 2.3.*
Author: TechPlex Solutions Private Limited
Author URI: https://techplex.in
*/
define('LEADGEN_DISTRIBUTION_MODULE_NAME', 'leadgen_distribution');
register_activation_hook(LEADGEN_DISTRIBUTION_MODULE_NAME, 'leadgen_distribution_activation_hook');
register_language_files(LEADGEN_DISTRIBUTION_MODULE_NAME, [LEADGEN_DISTRIBUTION_MODULE_NAME]);
function leadgen_distribution_activation_hook() { $CI = &get_instance(); $CI->db->query("CREATE TABLE IF NOT EXISTS `tblleadgen_distribution_log` (`id` int(11) NOT NULL AUTO_INCREMENT, `lead_id` int(11) NOT NULL, `assigned_staff_id` int(11) NOT NULL, `date_assigned` datetime DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8"); add_option('leadgen_distribution_enabled', '0'); add_option('leadgen_distribution_agents', serialize([])); add_option('leadgen_distribution_last_agent_index', '-1'); }
hooks()->add_filter('module_leadgen_distribution_action_links', 'leadgen_distribution_action_links');
function leadgen_distribution_action_links($actions) { $actions[] = '<a href="' . admin_url('leadgen_distribution') . '">' . _l('leadgen_distribution_settings') . '</a>'; return $actions; }
countihooks()->add_action('lead_created', 'leadgen_distribution_handle_new_lead');
function leadgen_distribution_handle_new_lead($lead_id) { if (get_option('leadgen_distribution_enabled') != '1') { return; }
$CI = &get_instance();
$lead = $CI->db->get_where('leads', ['id' => $lead_id])->row();
if (!$lead || !empty($lead->assigned)) { return; }
$agents = @unserialize(get_option('leadgen_distribution_agents')); if (!is_array($agents) || empty($agents)) { return; }
$active_agents = []; foreach ($agents as $staff_id) { $staff = $CI->db->get_where('staff', ['staffid' => $staff_id, 'active' => 1])->row(); if ($staff) { $active_agents[] = $staff_id; } }
if (empty($active_agents)) { return; }
$last_index = (int) get_option('leadgen_distribution_last_agent_index'); $next_index = ($last_index + 1) % count($active_agents); $picked_staff_id = $active_agents[$next_index];
$CI->db->where('id', $lead_id); $CI->db->update('leads', ['assigned' => $picked_staff_id]);
update_option('leadgen_distribution_last_agent_index', $next_index);
$CI->db->insert('leadgen_distribution_log', ['lead_id' => $lead_id, 'assigned_staff_id' => $picked_staff_id, 'date_assigned' => date('Y-m-d H:i:s')]);
$CI->load->model('leads_model'); $CI->leads_model->lead_assigned_member_notification($lead_id, $picked_staff_id, true); }

hooks()->add_action('admin_init', 'leadgen_distribution_module_init_menu_items');

function leadgen_distribution_module_init_menu_items()
{
    $CI = &get_instance();

    if (staff_can('view', 'leads')) {
        $CI->app_menu->add_sidebar_children_item('leads', [
            'slug'     => 'leadgen-distribution',
            'name'     => 'Lead Distribution',
            'href'     => admin_url('leadgen_distribution'),
            'position' => 43,
        ]);
    }
}
