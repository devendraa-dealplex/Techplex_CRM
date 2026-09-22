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
    if (!$CI->db->field_exists('pincode', db_prefix() . 'att_workplaces')) {
        $CI->db->query('ALTER TABLE `' . db_prefix() . 'att_workplaces` ADD `pincode` VARCHAR(12) NULL AFTER `address`');
    }
    // Upgrade: check-in / check-out.
    if (!$CI->db->field_exists('att_type', db_prefix() . 'att_records')) {
        $CI->db->query("ALTER TABLE `" . db_prefix() . "att_records` ADD `att_type` VARCHAR(3) NOT NULL DEFAULT 'in' AFTER `att_date`, ADD `is_early_out` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_late`");
    }
    // Upgrade: admin correction audit.
    if (!$CI->db->field_exists('edited_at', db_prefix() . 'att_records')) {
        $CI->db->query('ALTER TABLE `' . db_prefix() . 'att_records` ADD `edited_by` INT NULL, ADD `edited_at` DATETIME NULL, ADD `edit_note` VARCHAR(255) NULL');
    }
    // Upgrade: link employees to Perfex staff.
    if (!$CI->db->field_exists('staff_id', db_prefix() . 'att_employees')) {
        $CI->db->query('ALTER TABLE `' . db_prefix() . 'att_employees` ADD `staff_id` INT NULL AFTER `id`, ADD UNIQUE KEY `staff_id` (`staff_id`)');
    }
}

// New staff members become employees automatically.
hooks()->add_action('staff_member_created', 'attendance_dashboard_on_staff_created');
function attendance_dashboard_on_staff_created($staffId)
{
    $CI = &get_instance();
    if (is_array($staffId)) {
        $staffId = $staffId['id'] ?? ($staffId['staff_id'] ?? 0);
    }
    attendance_dashboard_ensure_tables();
    $CI->load->model(ATT_MODULE . '/attendance_dashboard_model');
    $CI->attendance_dashboard_model->syncStaff((int) $staffId);
}

hooks()->add_action('admin_init', 'attendance_dashboard_permissions');
function attendance_dashboard_permissions()
{
    register_staff_capabilities(ATT_MODULE, ['capabilities' => [
        'view'   => 'View (Global)',
        'create' => 'Create',
        'edit'   => 'Edit',
        'delete' => 'Delete',
        'mark'   => 'Mark attendance for any employee (camera kiosk)',
        'self'   => 'Employee self-service: mark and view own attendance only',
    ]], 'Attendance Dashboard');
}

/** True for an employee who may only mark/view their own attendance. */
function attendance_dashboard_self_only()
{
    return staff_can('self', ATT_MODULE) && !staff_can('view', ATT_MODULE) && !staff_can('mark', ATT_MODULE);
}

hooks()->add_action('admin_init', 'attendance_dashboard_menu');
function attendance_dashboard_menu()
{
    $self = staff_can('self', ATT_MODULE);
    if (!staff_can('view', ATT_MODULE) && !staff_can('mark', ATT_MODULE) && !$self) {
        return;
    }
    $CI = &get_instance();
    $CI->app_menu->add_sidebar_menu_item(ATT_MODULE, [
        'name'     => 'Attendance',
        'icon'     => 'fa-solid fa-user-clock',
        'position' => 30,
    ]);
    $items = [
        ['dashboard', attendance_dashboard_self_only() ? 'My Attendance' : 'Dashboard', '', staff_can('view', ATT_MODULE) || $self],
        ['employees', 'Employees', '/employees', staff_can('view', ATT_MODULE)],
        ['mark', 'Mark Attendance', '/mark', staff_can('mark', ATT_MODULE) || $self],
        ['records', 'Attendance Records', '/records', staff_can('view', ATT_MODULE)],
        ['workplaces', 'Workplaces', '/workplaces', staff_can('view', ATT_MODULE)],
        ['reports', 'Reports', '/reports', staff_can('view', ATT_MODULE)],
        ['settings', 'Settings', '/settings', staff_can('edit', ATT_MODULE)],
    ];
    foreach ($items as $i => $it) {
        if ($it[3]) {
            $CI->app_menu->add_sidebar_children_item(ATT_MODULE, [
                'slug'     => 'att-' . $it[0],
                'name'     => $it[1],
                'href'     => admin_url(ATT_MODULE . $it[2]),
                'position' => $i + 1,
            ]);
        }
    }
}

// Employees who can only mark their own attendance land straight on the Mark Attendance page.
hooks()->add_action('admin_init', 'attendance_dashboard_landing_redirect');
function attendance_dashboard_landing_redirect()
{
    $CI = &get_instance();
    if (!is_staff_logged_in() || is_admin() || !attendance_dashboard_self_only()) {
        return;
    }
    $seg = $CI->uri->segment(2);
    if (($CI->uri->segment(1) === 'admin') && ($seg === null || $seg === '' || $seg === 'dashboard' || $seg === 'home')) {
        redirect(admin_url(ATT_MODULE . '/mark'));
    }
}

/**
 * New staff get system-generated login details: username = their email address (Perfex logs in by email),
 * password = a random one. Runs before Perfex hashes the password and forces Perfex's own "welcome email"
 * so the person receives exactly one email, containing the generated password. Administrator accounts keep
 * whatever the admin entered.
 */
hooks()->add_filter('before_create_staff_member', 'attendance_dashboard_assign_credentials');
function attendance_dashboard_assign_credentials($data)
{
    if (!empty($data['administrator'])) {
        return $data;
    }
    $data['password'] = attendance_dashboard_random_password();
    $data['send_welcome_email'] = 1;
    // Safety net: outgoing email is not always deliverable, so show the login once to the admin who created it.
    get_instance()->session->set_flashdata('att_new_login', ['email' => $data['email'] ?? '', 'password' => $data['password']]);

    return $data;
}

hooks()->add_action('app_admin_footer', 'attendance_dashboard_show_new_login');
function attendance_dashboard_show_new_login()
{
    $login = get_instance()->session->flashdata('att_new_login');
    if (!is_array($login) || !is_admin()) {
        return;
    }
    $msg = 'Login created. Username: ' . html_escape($login['email']) . ' &nbsp; Password: <strong>' . html_escape($login['password'])
        . '</strong><br>Shown once only. It has also been emailed to the staff member.';
    echo '<script>$(function(){ alert_float("warning", ' . json_encode($msg) . ', 120000); });</script>';
}

function attendance_dashboard_random_password($len = 12)
{
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '@#$%*?'];
    $all = implode('', $sets);
    $pw = [];
    foreach ($sets as $set) {
        $pw[] = $set[random_int(0, strlen($set) - 1)]; // at least one of each class
    }
    while (count($pw) < $len) {
        $pw[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($pw) - 1; $i > 0; $i--) { // Fisher-Yates with a CSPRNG
        $j = random_int(0, $i);
        [$pw[$i], $pw[$j]] = [$pw[$j], $pw[$i]];
    }

    return implode('', $pw);
}
