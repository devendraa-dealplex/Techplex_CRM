<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Lead Generation - Monitoring Dashboard
Description: Cross-channel monitoring dashboard for lead volume by source, distribution assignments, and outreach activity
*/

define('LEADGEN_MONITORING_MODULE_NAME', 'leadgen_monitoring');

register_activation_hook(LEADGEN_MONITORING_MODULE_NAME, 'leadgen_monitoring_activation_hook');
register_language_files(LEADGEN_MONITORING_MODULE_NAME, [LEADGEN_MONITORING_MODULE_NAME]);

function leadgen_monitoring_activation_hook()
{
    // This module is read-only reporting: it queries existing leadgen_* and core Perfex tables.
    // No new database tables or options are created.
}


hooks()->add_action('admin_init', 'leadgen_monitoring_module_init_menu_items');

function leadgen_monitoring_module_init_menu_items()
{
    $CI = &get_instance();

    if (staff_can('view', 'leads')) {
        $CI->app_menu->add_sidebar_children_item('leads', [
            'slug'     => 'leadgen-monitoring',
            'name'     => 'Lead Monitoring',
            'href'     => admin_url('leadgen_monitoring'),
            'position' => 45,
        ]);
    }
}
