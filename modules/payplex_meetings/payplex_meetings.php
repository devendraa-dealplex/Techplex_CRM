<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Payplex Meetings
Description: Lead-based meeting booking, calendar, reminders and meeting summaries for Perfex CRM.
Version: 0.1.7
Requires at least: 2.3.*
Author: Payplex Technologies
*/

define('PAYPLEX_MEETINGS_MODULE_NAME', 'payplex_meetings');
define('PAYPLEX_MEETINGS_VERSION', '0.1.7');

require_once __DIR__ . '/helpers/payplex_meetings_helper.php';

register_activation_hook(PAYPLEX_MEETINGS_MODULE_NAME, 'payplex_meetings_activation_hook');
register_language_files(PAYPLEX_MEETINGS_MODULE_NAME, [PAYPLEX_MEETINGS_MODULE_NAME]);

hooks()->add_action('admin_init', 'payplex_meetings_init_menu');
hooks()->add_action('admin_init', 'payplex_meetings_init_permissions');

/*
 * Ride the CRM's existing cron instead of asking for a second crontab entry.
 *
 * Verified on this hosting account: the crontab already runs
 *     *_/5 * * * * wget -q -O /dev/null https://<host>/cron/index
 * for both staging and production. Perfex's own *features* are day-granular, but the
 * entry itself fires every five minutes -- which is exactly the cadence the reminder
 * ladder needs. So the dispatcher hooks in here and needs zero server setup.
 *
 * The standalone endpoint in controllers/Meetings_cron.php stays as a fallback for
 * installs whose cron interval is coarser, and as the health-check surface.
 */
hooks()->add_action('after_cron_run', 'payplex_meetings_cron_tick');
hooks()->add_action('app_admin_head', 'payplex_meetings_head');
hooks()->add_action('app_admin_footer', 'payplex_meetings_footer');

/*
 * Server-side extension points.
 *
 * IMPORTANT (see docs/ATTACHMENT-POINTS.md): the exact hook names Perfex exposes for the
 * leads DataTable column and the lead profile modal differ between builds, and on this
 * installation a sibling module (payplex_aicalling) registers such a hook and renders
 * nothing at all. Registering a filter whose name the core never fires is harmless -- the
 * callback simply never runs -- so we register the likely names AND ship a guaranteed
 * client-side path in assets/js/payplex_meetings.js. Whichever fires first wins; the JS
 * path checks for an already-rendered column and stands down if the server hook worked.
 */
/*
 * NOT REGISTERED, deliberately -- see docs/ATTACHMENT-POINTS.md.
 *
 *   hooks()->add_filter('leads_table_columns', 'payplex_meetings_leads_table_column');
 *
 * This filter DOES fire on this Perfex build, and that is precisely the problem: it adds a
 * 14th <th> to the leads table while the server's row data still returns 13 values. The
 * leads DataTable then aborts every draw with
 *
 *   DataTables warning: table id=leads - Requested unknown parameter '13' for row 0
 *
 * and the entire leads list renders blank. A header column needs a matching row-data
 * filter to be useful, and until that companion filter is confirmed against this build's
 * source, registering the header alone is strictly worse than not registering it.
 *
 * The client-side path in assets/js/payplex_meetings.js covers this surface safely: on a
 * DataTable it never touches the column structure at all.
 */

/*
 * The lead tab this module adds has never rendered.
 *
 * It listened on 'lead_view_tabs' and 'lead_view_tabs_content'. Neither name is
 * fired anywhere in this Perfex: searching core for do_action/apply_filters on
 * both returns nothing, while after_cron_run and admin_init resolve immediately
 * to Cron_model and AdminController. A callback on a hook nobody fires never
 * runs and nothing errors — the tab simply was not there, and no one could tell
 * from the code that it was missing rather than empty.
 *
 * The hooks core actually fires for the lead profile are in
 * application/views/admin/leads/lead.php:
 *
 *     after_lead_lead_tabs      - the tab nav
 *     after_lead_tabs_content   - the tab bodies
 *
 * App_tabs has add_customer_profile_tab, add_project_tab and add_settings_tab
 * but no lead equivalent, so for leads these hooks are the mechanism, not a
 * fallback.
 */
hooks()->add_action('after_lead_lead_tabs', 'payplex_meetings_lead_tab_nav');
hooks()->add_action('after_lead_tabs_content', 'payplex_meetings_lead_tab_content');

/**
 * Reminder dispatch, driven by the CRM's own cron run.
 *
 * Guarded so it does no work more than once a minute even if the cron endpoint is hit
 * repeatedly, and wrapped so a failure here can never break the rest of the CRM's cron.
 */
function payplex_meetings_cron_tick()
{
    $CI = &get_instance();

    $last = (int) pm_setting('cron_last_tick', 0);
    if ($last && (time() - $last) < 55) {
        return;
    }
    update_option('pm_cron_last_tick', time());

    try {
        /*
         * Make this module's own directory searchable before loading from it.
         *
         * The line below used to be $CI->load->library('payplex_meeting_mailer')
         * with no path. Inside a module controller that resolves, because Perfex
         * puts the module on the loader's package path when it dispatches there.
         * From a global hook function there is no such context, so the loader
         * looks only in application/libraries/, does not find it, and answers
         * with show_error() — which echoes and calls exit() rather than throwing.
         *
         * Nothing catches an exit. The try/catch around this block never fired,
         * log_message() went to a log this install does not write, and the tick
         * went on stamping pm_cron_last_tick every cycle while dispatching
         * nothing. Six reminders have sat pending, two of them overdue by about
         * a day, with attempts still 0.
         *
         * Worse than this module's own reminders: after_cron_run runs its
         * listeners in turn, and an exit() takes the rest of the chain with it.
         * Every cron hook registered after payplex_meetings has been dying here
         * too, just as quietly.
         *
         * add_package_path is the fix rather than a prefixed load, because
         * Payplex_meeting_mailer's own constructor loads payplex_ics and two
         * models the same unprefixed way. One package path covers all of them;
         * prefixing this single call would have moved the failure one frame in.
         */
        $CI->load->model('payplex_meetings/payplex_meeting_reminders_model', 'pm_reminders');
        $CI->load->add_package_path(__DIR__);
        $CI->load->library('payplex_meeting_mailer');

        $CI->pm_reminders->expire_missed();

        foreach ($CI->pm_reminders->claim_due((int) pm_setting('dispatch_batch', 50)) as $reminder) {
            $result = $CI->payplex_meeting_mailer->send_reminder($reminder);

            if (!empty($result['success'])) {
                $CI->pm_reminders->mark_sent($reminder->id);
            } else {
                $CI->pm_reminders->mark_failed($reminder->id, isset($result['error']) ? $result['error'] : 'unknown');
            }
        }
        $CI->load->remove_package_path(__DIR__);
    } catch (Throwable $e) {
        /* leave the loader as we found it even when the work failed */
        try { $CI->load->remove_package_path(__DIR__); } catch (Throwable $ignored2) {}

        /*
         * Throwable, not Exception.
         *
         * The comment above this function promises that "a failure here can
         * never break the rest of the CRM's cron". `catch (Exception)` does not
         * keep that promise: in PHP 7+ a loader failure, a TypeError or any
         * other Error is not an Exception, so it escapes this block, takes down
         * the rest of the after_cron_run chain behind it, and leaves nothing
         * behind. The tick is demonstrably producing no effect while updating
         * its own timestamp every cycle, which is exactly what that looks like.
         *
         * log_message() also goes nowhere on this install: display_errors is
         * off and CI's log_threshold resolves to 0, so the newest file in
         * application/logs is weeks old. The failure is therefore recorded in a
         * table this module owns and that can actually be read.
         */
        log_message('error', '[payplex_meetings] cron tick failed: ' . $e->getMessage());

        try {
            $CI->db->insert(db_prefix() . 'payplex_meeting_activity_logs', array(
                'meeting_id'   => 0,
                'rel_type'     => 'cron',
                'rel_id'       => 0,
                'staff_id'     => 0,
                'action'       => 'cron.tick_failed',
                'reason'       => substr(get_class($e) . ': ' . $e->getMessage()
                                  . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), 0, 2000),
                'date_created' => date('Y-m-d H:i:s'),
            ));
        } catch (Throwable $ignored) {
            // recording the failure must never become the failure
        }
    }
}

/**
 * Runs on module activation. Table creation lives in install.php (idempotent).
 */
function payplex_meetings_activation_hook()
{
    require_once __DIR__ . '/install.php';
}

/**
 * Sidebar menu.
 */
function payplex_meetings_init_menu()
{
    $CI = &get_instance();

    if (!pm_can('view')) {
        return;
    }

    $CI->app_menu->add_sidebar_menu_item('payplex-meetings', [
        'slug'     => 'payplex-meetings',
        'name'     => pm_lang('pm_meetings'),
        'icon'     => 'fa fa-calendar-check-o',
        'position' => 32,
    ]);

    $CI->app_menu->add_sidebar_children_item('payplex-meetings', [
        'slug'     => 'payplex-meetings-list',
        'name'     => pm_lang('pm_all_meetings'),
        'href'     => admin_url('payplex_meetings/meetings'),
        'position' => 1,
    ]);

    $CI->app_menu->add_sidebar_children_item('payplex-meetings', [
        'slug'     => 'payplex-meetings-mine',
        'name'     => pm_lang('pm_my_meetings'),
        'href'     => admin_url('payplex_meetings/meetings/mine'),
        'position' => 2,
    ]);

    $CI->app_menu->add_sidebar_children_item('payplex-meetings', [
        'slug'     => 'payplex-meetings-pending',
        'name'     => pm_lang('pm_pending_completion'),
        'href'     => admin_url('payplex_meetings/meetings/pending'),
        'position' => 3,
    ]);

    if (pm_can('config')) {
        $CI->app_menu->add_sidebar_children_item('payplex-meetings', [
            'slug'     => 'payplex-meetings-settings',
            'name'     => pm_lang('pm_settings'),
            'href'     => admin_url('payplex_meetings/meetings/settings'),
            'position' => 9,
        ]);
    }
}

/**
 * Register capabilities against the CRM's own permission system.
 *
 * Three features map onto the existing Role Permission Matrix domains:
 *   meetings           -> view / view_own / create / edit / delete
 *   meeting_summaries  -> view / create / edit / approve
 *   meeting_config     -> view / edit
 */
function payplex_meetings_init_permissions()
{
    /*
     * None of this module's permissions have ever existed.
     *
     * They were registered through $CI->app->add_staff_permission(), behind a
     * method_exists() guard with the comment "older core: pm_can() falls back
     * to is_admin()". That method does not exist on this Perfex. So the guard
     * returned silently, not one of the eleven ever appeared on the Roles
     * screen, none could be granted, and pm_can() fell through to is_admin() —
     * which is fail-closed and therefore safe, but it made a module with a
     * designed eleven-permission model usable only by administrators. The role
     * scan agrees: no role on this install carries a payplex_meetings key.
     *
     * register_staff_capabilities() is what this core actually provides and
     * what every other module here uses. Note the shape: the capability list
     * must be nested under a 'capabilities' key. A flat map registers nothing
     * at all and reports no error — this project has already found that exact
     * defect in three separate modules.
     *
     * ---------------------------------------------------------------------
     * Three of the eleven are deliberately NOT registered.
     *
     *   approve_summary       - no code anywhere checks it, and
     *   share_client_summary    tblpayplex_meeting_summaries holds zero rows.
     *                           The summaries feature does not exist yet.
     *   export                - the module has no export endpoint.
     *
     * Each would appear on the Roles screen and control nothing. Granting one
     * would tell an administrator they had delegated or restricted something
     * that does not exist, which is worse than the permission being absent:
     * it manufactures confidence in a control. Ten permissions were retired
     * from this install for exactly this reason, and eleven more in
     * leadgen_control_tower are in the same state awaiting the same decision.
     *
     * They come back when the code that honours them does. CI fails the build
     * for a capability registered with no enforcement site, so none of the
     * three can quietly reappear ahead of its behaviour.
     * ---------------------------------------------------------------------
     */
    $capabilities = array();

    $capabilities['capabilities'] = array(
        'view'              => pm_lang('pm_perm_view'),
        'view_own'          => pm_lang('pm_perm_view_own'),
        'create'            => pm_lang('pm_perm_create'),
        'edit'              => pm_lang('pm_perm_edit'),
        'cancel'            => pm_lang('pm_perm_cancel'),
        'override_conflict' => pm_lang('pm_perm_override'),
        'view_confidential' => pm_lang('pm_perm_confidential'),
        'config'            => pm_lang('pm_perm_config'),
    );

    register_staff_capabilities('payplex_meetings', $capabilities, pm_lang('pm_meetings'));
}

/**
 * Stylesheet + the small bootstrap payload the JS needs.
 */
function payplex_meetings_head()
{
    $url = module_dir_url(PAYPLEX_MEETINGS_MODULE_NAME, 'assets/css/payplex_meetings.css');
    echo '<link rel="stylesheet" type="text/css" href="' . $url . '?v=' . PAYPLEX_MEETINGS_VERSION . '">';
}

function payplex_meetings_footer()
{
    $CI = &get_instance();

    $boot = [
        'base'        => admin_url('payplex_meetings/meetings'),
        'csrfName'    => $CI->security->get_csrf_token_name(),
        'csrfHash'    => $CI->security->get_csrf_hash(),
        'canCreate'   => pm_can('create'),
        'canEdit'     => pm_can('edit'),
        'canCancel'   => pm_can('cancel'),
        'canOverride' => pm_can('override_conflict'),
        'lang'        => [
            'quick_actions' => pm_lang('pm_quick_actions'),
            'engagement'    => pm_lang('pm_engagement'),
            'no_meeting'    => pm_lang('pm_no_meeting_yet'),
            'overdue'       => pm_lang('pm_followup_overdue'),
            'meetings_tab'  => pm_lang('pm_meetings_and_followups'),
            'loading'       => pm_lang('pm_loading'),
        ],
    ];

    echo '<script>window.PayplexMeetings = ' . json_encode($boot) . ';</script>';

    $url = module_dir_url(PAYPLEX_MEETINGS_MODULE_NAME, 'assets/js/payplex_meetings.js');
    echo '<script src="' . $url . '?v=' . PAYPLEX_MEETINGS_VERSION . '"></script>';
}

/**
 * Server-side leads column, used only if the core actually fires this filter.
 */
function payplex_meetings_leads_table_column($columns)
{
    if (!is_array($columns) || !pm_can('view')) {
        return $columns;
    }

    $columns[] = [
        'name'     => pm_lang('pm_engagement'),
        'th_attrs' => ['class' => 'pm-engagement-col', 'data-pm-server-rendered' => '1'],
    ];

    return $columns;
}

function payplex_meetings_lead_tab_nav($lead_id = null)
{
    if (!pm_can('view') || !$lead_id) {
        return;
    }
    echo '<li role="presentation" data-pm-server-rendered="1">'
        . '<a href="#pm_lead_meetings" aria-controls="pm_lead_meetings" role="tab" data-toggle="tab">'
        . '<i class="fa fa-calendar-check-o"></i> ' . pm_lang('pm_meetings_and_followups')
        . '</a></li>';
}

function payplex_meetings_lead_tab_content($lead_id = null)
{
    if (!pm_can('view') || !$lead_id) {
        return;
    }
    echo '<div role="tabpanel" class="tab-pane" id="pm_lead_meetings"'
        . ' data-pm-lead="' . (int) $lead_id . '">'
        . '<div class="pm-tab-loading">' . pm_lang('pm_loading') . '</div></div>';
}
