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
 *   v3: identity documents table (payplex_vkyc_documents)
 *   v4: Video KYC capabilities granted to the stock sales roles
 *   v5: ...and copied into staff_permissions, which is what staff_can() reads
 *   v6: 'resubmit' status (Ask again) and the Video KYC paragraph in the set-password email
 * ---------------------------------------------------------------------- */
define('PAYPLEX_VIDEOKYC_SCHEMA_VERSION', 6);

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
        'view'         => 'View Video KYC dashboard and requests (own customers only unless "View all" is also given)',
        'view_all'     => 'View all customers\' and leads\' KYC, not only those assigned to the staff member',
        'generate'     => 'Generate / resend KYC links',
        'review'       => 'Approve or reject submissions',
        'video_access' => 'Watch recorded KYC videos',
        'documents'    => 'Upload and view customer identity documents (Step 1 of KYC)',
        'settings'     => 'Manage KYC settings, templates and providers',
    ]], 'Payplex Video KYC');
}

/* -------------------------------------------------------------------------
 * No sidebar entry. Video KYC is its own tab on the customer profile:
 * Customer > "Video KYC" (?group=payplex_vkyc). It holds the customer's document
 * and video progress, their requests and the review modal, gated on the
 * capabilities above. The core Contracts tab is left alone. Staff without 'view'
 * do not get the tab. The /admin/video-kyc/* JSON endpoints stay: the tab uses them.
 * ---------------------------------------------------------------------- */
hooks()->add_action('admin_init', 'payplex_videokyc_customer_tab');
function payplex_videokyc_customer_tab()
{
    if (!function_exists('get_instance') || !(is_admin() || staff_can('view', PAYPLEX_VIDEOKYC_MODULE))) {
        return;
    }
    $CI = &get_instance();
    if (!isset($CI->app_tabs) || !method_exists($CI->app_tabs, 'add_customer_profile_tab')) {
        return;
    }
    $CI->app_tabs->add_customer_profile_tab('payplex_vkyc', [
        'name'     => 'Video KYC',
        'icon'     => 'fa fa-video-camera',
        'view'     => 'payplex_videokyc/client_kyc_tab',
        'position' => 62,
    ]);
}

/* -------------------------------------------------------------------------
 * Customer portal menu: "Video KYC" for logged-in customers.
 * ---------------------------------------------------------------------- */
hooks()->add_action('clients_init', 'payplex_videokyc_portal_menu');
function payplex_videokyc_portal_menu()
{
    if (!is_client_logged_in()) {
        return;
    }
    add_theme_menu_item('payplex-videokyc', [
        'name'     => 'Video KYC',
        'href'     => site_url('clients/video-kyc'),
        'position' => 40,
    ]);
}

/* -------------------------------------------------------------------------
 * Assets — loaded only on Video KYC admin pages. The version token busts
 * browser caches on deploy (Perfex does not version module asset URLs).
 * ---------------------------------------------------------------------- */
function payplex_videokyc_is_kyc_page()
{
    $CI = &get_instance();
    if ($CI->uri->segment(1) === 'admin' && $CI->uri->segment(2) === 'video-kyc') {
        return true;
    }
    // Customer profile > "Video KYC" tab hosts the progress, requests and review.
    return $CI->uri->segment(1) === 'admin' && $CI->uri->segment(2) === 'clients'
        && $CI->input->get('group') === 'payplex_vkyc';
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

/* -------------------------------------------------------------------------
 * Templates / providers / limits live under Setup, for the `settings` capability
 * only (the sidebar entry is gone). Sales never sees this.
 * ---------------------------------------------------------------------- */
hooks()->add_action('admin_init', 'payplex_videokyc_setup_menu');
function payplex_videokyc_setup_menu()
{
    if (!(is_admin() || staff_can('settings', PAYPLEX_VIDEOKYC_MODULE))) {
        return;
    }
    get_instance()->app_menu->add_setup_menu_item('payplex-videokyc-settings', [
        'name'     => 'Video KYC Settings',
        'href'     => admin_url('video-kyc/settings'),
        'position' => 37,
    ]);
}

/* -------------------------------------------------------------------------
 * {video_kyc_url} for the customer's welcome / set-password emails. It is the
 * customer portal's KYC page: a logged-out customer is sent to log in first (after
 * setting their password) and lands back on it, so the link works straight from
 * the email. The video link itself is issued there, once Step 1 is done.
 * ---------------------------------------------------------------------- */
hooks()->add_filter('client_contact_merge_fields', 'payplex_videokyc_merge_fields');
function payplex_videokyc_merge_fields($fields)
{
    $fields['{video_kyc_url}'] = site_url('clients/video-kyc');
    return $fields;
}
