<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Staff Classification
Description: Adds employment type, department, reporting manager, branch, territory, work mode and shift classification fields for staff members.
Version: 1.0.0
Author: TechPlex Solutions
*/

define('STAFF_CLASSIFICATION_MODULE_NAME', 'staff_classification');

register_activation_hook(STAFF_CLASSIFICATION_MODULE_NAME, 'staff_classification_activation_hook');

function staff_classification_activation_hook()
{
$CI = &get_instance();

$CI->db->query("CREATE TABLE IF NOT EXISTS `tblstaff_classification` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`staff_id` int(11) NOT NULL,
`employment_type` varchar(30) DEFAULT NULL,
`department` varchar(100) DEFAULT NULL,
`manager_id` int(11) DEFAULT NULL,
`branch` varchar(100) DEFAULT NULL,
`territory` varchar(100) DEFAULT NULL,
`work_mode` varchar(30) DEFAULT NULL,
`shift` varchar(100) DEFAULT NULL,
`date_updated` datetime DEFAULT NULL,
PRIMARY KEY (`id`),
UNIQUE KEY `staff_id` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

register_language_files(STAFF_CLASSIFICATION_MODULE_NAME, array(STAFF_CLASSIFICATION_MODULE_NAME));

hooks()->add_action('admin_init', 'staff_classification_module_init_menu_item');
hooks()->add_action('admin_init', 'staff_classification_permissions');

function staff_classification_module_init_menu_item()
{
$CI = &get_instance();

if (!staff_can('view', 'staff') && !staff_can('view', 'staff_classification')) {
return;
}

// NOTE: the 'staff' top-level sidebar item in this theme is a hardcoded template
// element that does not render module-added children (verified: add_sidebar_children_item
// fires correctly - confirmed via a controlled test against the 'leads' menu, which DOES
// support children - but the 'staff' parent renders no <ul>/children regardless).
// Per the engagement rule against modifying Perfex core/theme files, we register this as
// its own top-level sidebar item instead of a child of Staff.
$CI->app_menu->add_sidebar_menu_item('staff_classification', array(
'name' => _l('staff_classification_title'),
'icon' => 'fa fa-address-card',
'href' => admin_url('staff_classification'),
'position' => 25,
));
}

/**
* Phase 1.6 (reduced scope) - register this module's own named Perfex
* capabilities, following the same register_staff_capabilities() +
* hooks()->add_action('admin_init', ...) pattern already proven safe in
* modules/sales_targets/sales_targets.php (Phase 2.6). is_admin() is still
* honored everywhere as a superset/fallback so nothing that currently works
* for admins changes. The controller's existing staff_can(..., 'staff')
* checks are left as an additional OR-condition (not removed) so no one who
* could previously access this page loses access; the new 'staff_classification'
* capability lets it also be granted independently of the broader Staff module.
*/
function staff_classification_permissions()
{
$capabilities = array();

$capabilities['capabilities'] = array(
'view' => _l('staff_classification_perm_view'),
'edit' => _l('staff_classification_perm_edit'),
);

register_staff_capabilities('staff_classification', $capabilities, _l('staff_classification_title'));
}
