<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Attendance Dashboard
Description: Employee registry, workplace geofences and camera + GPS + time verified attendance. Attendance can only be created through the server-verified Mark Attendance flow.
Version: 1.0.0
Requires at least: 2.3.*
*/

define('ATT_MODULE', 'attendance_dashboard');

register_activation_hook(ATT_MODULE, 'attendance_dashboard_install');
function attendance_dashboard_install()
{
    $CI = &get_instance();
    require __DIR__ . '/install.php';
}

// Self-heal: create tables if the module was enabled without the activation hook running.
hooks()->add_action('admin_init', 'attendance_dashboard_ensure_tables');
function attendance_dashboard_ensure_tables()
{
    $CI = &get_instance();
    if (!$CI->db->table_exists(db_prefix() . 'att_records')) {
        attendance_dashboard_install();
    }
}

hooks()->add_action('admin_init', 'attendance_dashboard_permissions');
function attendance_dashboard_permissions()
{
    register_staff_capabilities(ATT_MODULE, ['capabilities' => [
        'view'   => 'View (Global)',
        'create' => 'Create',
        'edit'   => 'Edit',
        'delete' => 'Delete',
        'mark'   => 'Mark attendance (camera kiosk)',
    ]], 'Attendance Dashboard');
}

hooks()->add_action('admin_init', 'attendance_dashboard_menu');
function attendance_dashboard_menu()
{
    if (!staff_can('view', ATT_MODULE) && !staff_can('mark', ATT_MODULE)) {
        return;
    }
    $CI = &get_instance();
    $CI->app_menu->add_sidebar_menu_item(ATT_MODULE, [
        'name'     => 'Attendance',
        'icon'     => 'fa-solid fa-user-clock',
        'position' => 30,
    ]);
    $items = [
        ['dashboard', 'Dashboard', '', 'view'],
        ['employees', 'Employees', '/employees', 'view'],
        ['mark', 'Mark Attendance', '/mark', 'mark'],
        ['records', 'Attendance Records', '/records', 'view'],
        ['workplaces', 'Workplaces', '/workplaces', 'view'],
        ['reports', 'Reports', '/reports', 'view'],
        ['settings', 'Settings', '/settings', 'edit'],
    ];
    foreach ($items as $i => $it) {
        if (staff_can($it[3], ATT_MODULE)) {
            $CI->app_menu->add_sidebar_children_item(ATT_MODULE, [
                'slug'     => 'att-' . $it[0],
                'name'     => $it[1],
                'href'     => admin_url(ATT_MODULE . $it[2]),
                'position' => $i + 1,
            ]);
        }
    }
}
