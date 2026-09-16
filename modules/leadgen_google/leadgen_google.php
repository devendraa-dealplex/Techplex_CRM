<?php defined('BASEPATH') or exit('No direct script access allowed');
/*
Module Name: Lead Generation - Google
Description: Capture leads automatically from Google Ads Lead Form Extensions and website/Google Form submissions, and push them into the CRM lead pipeline.
Version: 1.0.0
Requires at least: 2.3.*
Author: TechPlex Solutions Private Limited
Author URI: https://techplex.in
*/
define('LEADGEN_GOOGLE_MODULE_NAME', 'leadgen_google');
register_activation_hook(LEADGEN_GOOGLE_MODULE_NAME, 'leadgen_google_activation_hook');
register_language_files(LEADGEN_GOOGLE_MODULE_NAME, [LEADGEN_GOOGLE_MODULE_NAME]);
function leadgen_google_activation_hook() { $CI = &get_instance(); $CI->db->query("CREATE TABLE IF NOT EXISTS `tblleadgen_google_leads` (`id` int(11) NOT NULL AUTO_INCREMENT, `lead_id` int(11) DEFAULT NULL, `google_ref` varchar(191) DEFAULT NULL, `source_type` varchar(20) DEFAULT NULL, `raw_payload` longtext, `date_created` datetime DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8"); add_option('google_ads_webhook_key', ''); add_option('google_website_form_api_key', ''); add_option('google_ads_default_source', 'Google Ads'); add_option('google_website_default_source', 'Website Form'); add_option('google_default_lead_status', ''); }
hooks()->add_filter('module_leadgen_google_action_links', 'leadgen_google_action_links');
function leadgen_google_action_links($actions) { $actions[] = '<a href="' . admin_url('leadgen_google') . '">' . _l('leadgen_google_settings') . '</a>'; return $actions; }

hooks()->add_action('admin_init', 'leadgen_google_module_init_menu_items');
hooks()->add_action('admin_init', 'leadgen_google_permissions');

function leadgen_google_module_init_menu_items()
{
$CI = &get_instance();

if (staff_can('view', 'leads') || staff_can('view', 'leadgen_google')) {
$CI->app_menu->add_sidebar_children_item('leads', [
'slug' => 'leadgen-google',
'name' => 'Google Leads',
'href' => admin_url('leadgen_google'),
'position' => 42,
]);
}
}

/**
* Phase 1.6 (reduced scope) - register this module's own named Perfex
* capabilities, following the same register_staff_capabilities() +
* hooks()->add_action('admin_init', ...) pattern already proven safe in
* modules/sales_targets/sales_targets.php (Phase 2.6) and re-verified on
* modules/staff_classification, modules/leadgen_facebook and
* modules/leadgen_whatsapp this same phase.
* NOTE: unlike the facebook/whatsapp controllers, this module's controller
* (Leadgen_google.php) currently has NO staff_can/staff_cant guard at all -
* the settings page is reachable by any staff member who can already access
* admin. Adding an enforcing capability check here would be a functional/
* access-control change beyond "register permissions", and could lock out
* staff who currently rely on this working page - which conflicts with the
* standing rule to preserve current workflows/working features exactly.
* So for this module we register the capability (making it assignable in
* Roles & Permissions) and OR it into the menu-visibility guard above
* (widening visibility only, never narrowing it), but we deliberately do
* NOT add an enforcing check into the controller.
*/
function leadgen_google_permissions()
{
$capabilities = array();

$capabilities['capabilities'] = array(
'view' => _l('leadgen_google_perm_view'),
'edit' => _l('leadgen_google_perm_edit'),
);

register_staff_capabilities('leadgen_google', $capabilities, _l('leadgen_google_settings'));
}
