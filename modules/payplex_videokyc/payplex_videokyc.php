<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Module Name: Payplex Video KYC
 * Description: Send customers a secure, expiring link; they record a short video reading a
 *              generated statement in their browser; staff review and approve/reject. Links
 *              go out by Email, SMS and WhatsApp. No Perfex core files are modified.
 * Version: 1.0.0
 * Requires at least: 2.3.*
 * Author: Payplex
 */

define('PAYPLEX_VIDEOKYC_MODULE', 'payplex_videokyc');

register_activation_hook(PAYPLEX_VIDEOKYC_MODULE, 'payplex_videokyc_activate');
function payplex_videokyc_activate()
{
    require_once __DIR__ . '/install.php';
}

/* -------------------------------------------------------------------------
 * Schema upgrades. install.php only runs on activation, so a module that is
 * already active would never get columns added in a later version. install.php
 * is idempotent (every step is guarded), so it doubles as the migration.
 *   v2: multi-language scripts (templates.body_hi/body_mr, requests.script_language)
 * ---------------------------------------------------------------------- */
define('PAYPLEX_VIDEOKYC_SCHEMA_VERSION', 2);

hooks()->add_action('admin_init', 'payplex_videokyc_migrate');
function payplex_videokyc_migrate()
{
    if ((int) get_option('payplex_videokyc_schema_version') >= PAYPLEX_VIDEOKYC_SCHEMA_VERSION) {
        return;
    }
    require __DIR__ . '/install.php';
    update_option('payplex_videokyc_schema_version', (string) PAYPLEX_VIDEOKYC_SCHEMA_VERSION);
}

/* -------------------------------------------------------------------------
 * Capabilities. Hiding a menu item is NOT authorization — every controller
 * action re-checks the capability it needs.
 * ---------------------------------------------------------------------- */
hooks()->add_action('admin_init', 'payplex_videokyc_permissions');
function payplex_videokyc_permissions()
{
    register_staff_capabilities(PAYPLEX_VIDEOKYC_MODULE, ['capabilities' => [
        'view'         => 'View Video KYC dashboard and requests',
        'generate'     => 'Generate / resend KYC links',
        'review'       => 'Approve or reject submissions',
        'video_access' => 'Watch recorded KYC videos',
        'settings'     => 'Manage KYC settings, templates and providers',
    ]], 'Payplex Video KYC');
}

/* -------------------------------------------------------------------------
 * Sidebar: "Video KYC" with collapsible children, next to AI Agents (26) and
 * AI Calling (30).
 * ---------------------------------------------------------------------- */
hooks()->add_action('admin_init', 'payplex_videokyc_menu');
function payplex_videokyc_menu()
{
    if (!(is_admin() || staff_can('view', PAYPLEX_VIDEOKYC_MODULE))) {
        return;
    }
    $CI = &get_instance();

    $CI->app_menu->add_sidebar_menu_item('payplex-videokyc', [
        'name'     => 'Video KYC',
        'href'     => admin_url('video-kyc/dashboard'),
        'icon'     => 'fa fa-video-camera',
        'position' => 31,
    ]);
    $CI->app_menu->add_sidebar_children_item('payplex-videokyc', [
        'slug'     => 'payplex-videokyc-dashboard',
        'name'     => 'Dashboard',
        'href'     => admin_url('video-kyc/dashboard'),
        'position' => 1,
    ]);
    $CI->app_menu->add_sidebar_children_item('payplex-videokyc', [
        'slug'     => 'payplex-videokyc-requests',
        'name'     => 'Requests / Logs',
        'href'     => admin_url('video-kyc/requests'),
        'position' => 2,
    ]);
    if (is_admin() || staff_can('settings', PAYPLEX_VIDEOKYC_MODULE)) {
        $CI->app_menu->add_sidebar_children_item('payplex-videokyc', [
            'slug'     => 'payplex-videokyc-settings',
            'name'     => 'Settings & Templates',
            'href'     => admin_url('video-kyc/settings'),
            'position' => 3,
        ]);
    }
}

/* -------------------------------------------------------------------------
 * Assets — loaded only on Video KYC admin pages. The version token busts
 * browser caches on deploy (Perfex does not version module asset URLs).
 * ---------------------------------------------------------------------- */
function payplex_videokyc_is_kyc_page()
{
    $CI = &get_instance();
    return $CI->uri->segment(1) === 'admin' && $CI->uri->segment(2) === 'video-kyc';
}

hooks()->add_action('app_admin_head', 'payplex_videokyc_head');
function payplex_videokyc_head()
{
    if (!payplex_videokyc_is_kyc_page()) {
        return;
    }
    echo '<link href="' . module_dir_url(PAYPLEX_VIDEOKYC_MODULE, 'assets/css/videokyc.css')
       . '?v=' . filemtime(__DIR__ . '/assets/css/videokyc.css') . '" rel="stylesheet">';
}

hooks()->add_action('app_admin_footer', 'payplex_videokyc_footer');
function payplex_videokyc_footer()
{
    if (!payplex_videokyc_is_kyc_page()) {
        return;
    }
    echo '<script src="' . module_dir_url(PAYPLEX_VIDEOKYC_MODULE, 'assets/js/videokyc.js')
       . '?v=' . filemtime(__DIR__ . '/assets/js/videokyc.js') . '"></script>';
}
