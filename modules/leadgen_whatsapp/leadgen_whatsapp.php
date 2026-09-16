<?php
/*
Module Name: Lead Generation - WhatsApp
Description: Capture leads automatically from WhatsApp via the Meta WhatsApp Cloud API and push them into the CRM lead pipeline.
Version: 1.0.0
Requires at least: 2.3.*
Author: TechPlex Solutions Private Limited
*/
defined('BASEPATH') or exit('No direct script access allowed');
define('LEADGEN_WHATSAPP_MODULE_NAME', 'leadgen_whatsapp');
register_language_files(LEADGEN_WHATSAPP_MODULE_NAME, [LEADGEN_WHATSAPP_MODULE_NAME]);
register_activation_hook(LEADGEN_WHATSAPP_MODULE_NAME, 'leadgen_whatsapp_activation_hook');
hooks()->add_action('admin_init', 'leadgen_whatsapp_activation_hook');
function leadgen_whatsapp_activation_hook() { add_option('whatsapp_phone_number_id', ''); add_option('whatsapp_business_account_id', ''); add_option('whatsapp_access_token', ''); add_option('whatsapp_app_secret', ''); add_option('whatsapp_verify_token', ''); add_option('whatsapp_default_lead_status', ''); add_option('whatsapp_default_lead_source', ''); $CI = &get_instance(); if (!$CI->db->table_exists(db_prefix() . 'leadgen_whatsapp_messages')) { $CI->db->query('CREATE TABLE IF NOT EXISTS `' . db_prefix() . 'leadgen_whatsapp_messages` (`id` INT(11) NOT NULL AUTO_INCREMENT, `lead_id` INT(11) DEFAULT NULL, `wa_from` VARCHAR(32) NOT NULL, `wa_message_id` VARCHAR(128) DEFAULT NULL, `direction` VARCHAR(3) NOT NULL DEFAULT \'in\', `message_body` TEXT, `raw_payload` LONGTEXT, `date_created` DATETIME NOT NULL, PRIMARY KEY (`id`), KEY `lead_id` (`lead_id`), KEY `wa_from` (`wa_from`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'); } }
hooks()->add_filter('module_leadgen_whatsapp_action_links', 'leadgen_whatsapp_action_links');
function leadgen_whatsapp_action_links($actions) { $actions[] = '<a href="' . admin_url('leadgen_whatsapp') . '">' . _l('leadgen_whatsapp_settings') . '</a>'; return $actions; }

hooks()->add_action('admin_init', 'leadgen_whatsapp_module_init_menu_items');
hooks()->add_action('admin_init', 'leadgen_whatsapp_permissions');

function leadgen_whatsapp_module_init_menu_items()
{
$CI = &get_instance();

if (staff_can('view', 'leads') || staff_can('view', 'leadgen_whatsapp')) {
$CI->app_menu->add_sidebar_children_item('leads', [
'slug' => 'leadgen-whatsapp',
'name' => 'WhatsApp Leads',
'href' => admin_url('leadgen_whatsapp'),
'position' => 40,
]);
}
}

/**
* Phase 1.6 (reduced scope) - register this module's own named Perfex
* capabilities, following the same register_staff_capabilities() +
* hooks()->add_action('admin_init', ...) pattern already proven safe in
* modules/sales_targets/sales_targets.php (Phase 2.6) and re-verified on
* modules/staff_classification and modules/leadgen_facebook this same phase.
* The controller's existing staff_cant(..., 'settings') checks are left as
* an additional condition (not removed) so no one who could previously
* access this page loses access; the new 'leadgen_whatsapp' capability
* lets access be granted independently of the broader Settings permission.
*/
function leadgen_whatsapp_permissions()
{
$capabilities = array();

$capabilities['capabilities'] = array(
'view' => _l('leadgen_whatsapp_perm_view'),
'edit' => _l('leadgen_whatsapp_perm_edit'),
);

register_staff_capabilities('leadgen_whatsapp', $capabilities, _l('leadgen_whatsapp_settings'));
}
