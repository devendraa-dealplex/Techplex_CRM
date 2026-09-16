<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Module Name: Payplex Reports
 * Description: Read-only analytics — lead funnel, source performance, agent
 *              performance, AI call cost, commission liability. CSV export.
 *              Upgrade-safe; no Perfex core files modified; creates no tables.
 * Version: 0.1.0
 * Requires at least: 2.3.*
 * Author: Payplex
 */

define('PAYPLEX_REPORTS_MODULE', 'payplex_reports');

require_once __DIR__ . '/libraries/Payplex_report_definitions.php';

register_activation_hook(PAYPLEX_REPORTS_MODULE, function () { /* read-only: no tables */ });

hooks()->add_action('admin_init', 'payplex_reports_permissions');
function payplex_reports_permissions()
{
    /*
     * 'view_all' is separate from 'view' on purpose. Agent Performance and AI
     * Call Cost are per-person figures — an employee ranking and a spend
     * breakdown by colleague. They were previously visible to anyone holding
     * 'view', so a salesperson could read the whole team's numbers, which §5
     * forbids. Plain 'view' now shows the holder their own row only.
     */
    /*
     * Perfex groups capabilities before it renders them: the second argument is
     * a map of GROUP => (capability => label), and the catch-all group Perfex's
     * own modules use is 'capabilities'. Passing the capability map directly —
     * as this module did — is accepted without complaint and registers nothing
     * that the Roles screen can draw, so no role and no staff member could ever
     * be granted one. Every staff_can() call then answered false for everyone,
     * and only is_admin() ever opened a door: a maker-checker design with no
     * way to appoint a checker.
     */
    register_staff_capabilities('payplex_reports', array('capabilities' => [
            'view'     => 'View reports (own figures only)',
            'view_all' => 'View team-wide figures (managers/admin)',
            'export'   => 'Export reports',
    
    ]), 'Payplex Reports');
}

/*
 * Close three unguarded core dashboard endpoints, WITHOUT touching core.
 *
 * application/controllers/admin/Dashboard.php exposes:
 *
 *   ticket_widget/<period>              the per-staff tickets report
 *   weekly_payments_statistics/<cur>    company payment totals
 *   monthly_payments_statistics/<cur>   company payment totals
 *
 * Each is checked by the WIDGET that calls it — tickets_report.php renders only
 * inside is_admin(), payments_chart.php only for staff_can('view','payments') or
 * staff_can('view_own','invoices') — and by nothing in the controller. All three
 * inherit AdminController, so a logged-in staff session is the only requirement
 * and the URL can simply be typed. dashboard_js.php is the only caller of any of
 * them, so enforcing each widget's own rule here refuses nothing that previously
 * worked for someone entitled to it.
 *
 * admin_init fires inside AdminController::__construct(), before the routed
 * method runs, so this guard lands ahead of the controller — which is why the
 * fix does not need a core edit. §3.1: no Perfex core file is modified.
 */
hooks()->add_action('admin_init', 'payplex_reports_dashboard_endpoint_guard');
function payplex_reports_dashboard_endpoint_guard()
{
    $CI = &get_instance();
    if (!isset($CI->router)) {
        return;
    }
    $class  = $CI->router->fetch_class();
    $method = $CI->router->fetch_method();

    if (Payplex_report_definitions::dashboardEndpointRule($method) === null) {
        return; // cheapest possible exit on every other request
    }

    $canPayments = staff_can('view', 'payments')
                || staff_can('view', 'invoices')
                || staff_can('view_own', 'invoices');

    if (Payplex_report_definitions::dashboardGuardRefuses($class, $method, is_admin(), $canPayments)) {
        access_denied('dashboard/' . $method);
    }
}

hooks()->add_action('admin_init', 'payplex_reports_menu');
function payplex_reports_menu()
{
    if (!(staff_can('view', 'payplex_reports') || is_admin())) {
        return;
    }
    $CI = &get_instance();
    $CI->app_menu->add_sidebar_menu_item('payplex-reports', [
        'name' => 'Payplex Reports', 'href' => admin_url('payplex_reports/reports'),
        'icon' => 'fa fa-bar-chart', 'position' => 32,
    ]);
    $items = [
        'funnel'              => 'Lead Funnel',
        'source_performance'  => 'Source Performance',
        'agent_performance'   => 'Agent Performance',
        'call_cost'           => 'AI Call Cost',
        'commission_liability'=> 'Commission Liability',
    ];
    $i = 1;
    foreach ($items as $slug => $name) {
        $CI->app_menu->add_sidebar_children_item('payplex-reports', [
            'slug' => 'payplex-reports-' . $slug, 'name' => $name,
            'href' => admin_url('payplex_reports/reports/' . $slug), 'position' => $i++,
        ]);
    }
}
