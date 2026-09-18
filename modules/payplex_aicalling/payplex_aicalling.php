<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Module Name: Payplex AI Calling
 * Description: Upgrade-safe CRM front end for the Sonivo AI Calling backend. Lead-level
 *              Call Now / Schedule, admin dashboard, signed-webhook sync, idempotency,
 *              hash-chained audit. No Perfex core files are modified.
 * Version: 0.3.1
 * Requires at least: 2.3.*
 * Author: Payplex
 */

define('PAYPLEX_AICALLING_MODULE', 'payplex_aicalling');

register_activation_hook(PAYPLEX_AICALLING_MODULE, 'payplex_aicalling_activate');
function payplex_aicalling_activate()
{
    require_once __DIR__ . '/install.php';
}

register_deactivation_hook(PAYPLEX_AICALLING_MODULE, 'payplex_aicalling_deactivate');
function payplex_aicalling_deactivate()
{
    // Non-destructive: tables are kept (data retention). Feature flag off.
    update_option('payplex_aicalling_enabled', '0');
}

/* -------------------------------------------------------------------------
 * Schema / option migrations (spec §8).
 *
 * install.php runs on activation only, so options added in a later version
 * never reach an install that is already active — the settings screen would
 * read them as empty forever and nobody would know a control had not landed.
 * This runner is idempotent, version-guarded, and adds only; it never
 * overwrites a value an administrator has set.
 *
 * Rollback is by design rather than by script: every option here defaults to
 * a value that REFUSES or constrains, so reverting the code to v0.2.0 with the
 * options still present changes nothing.
 * ---------------------------------------------------------------------- */
define('PAYPLEX_AICALLING_SCHEMA_VERSION', 6); // v6: re-encrypt plaintext secrets at rest

hooks()->add_action('admin_init', 'payplex_aicalling_migrate');
function payplex_aicalling_migrate()
{
    $current = (int) get_option('payplex_aicalling_schema_version');
    if ($current >= PAYPLEX_AICALLING_SCHEMA_VERSION) {
        return;
    }

    // add-only: an option an admin has already configured is never touched
    $ensure = function ($key, $default) {
        $existing = get_option($key);
        if ($existing === '' || $existing === null || $existing === false) {
            update_option($key, $default);
        }
    };

    if ($current < 1) {
        // §4.3 — the permitted calling window. Timezone is intentionally empty:
        // it falls back to the Perfex default and then refuses, never to the
        // server clock.
        $ensure('payplex_aicalling_timezone', '');
        $ensure('payplex_aicalling_hours_start', '9');
        $ensure('payplex_aicalling_hours_end', '20');
    }

    if ($current < 2) {
        // §4.4 — frequency limits are left empty so the library's conservative
        // defaults apply and are shown as defaults; budgets and the recording
        // disclosure are left empty so they REFUSE until somebody authorises
        // them. See install.php for why those two are treated differently.
        foreach (array('max_per_day', 'max_per_week', 'cooldown_minutes', 'max_duration_sec',
                       'agent_budget', 'account_budget', 'recording_disclosure') as $k) {
            $ensure('payplex_aicalling_' . $k, '');
        }
        $ensure('payplex_aicalling_disclosure_confirmed', '0');
    }

    if ($current < 3) {
        /*
         * §4.4 classification lists. Registered EMPTY on purpose: empty means
         * "use the built-in defaults", which are shown as defaults on the
         * settings screen. Seeding the default words here instead would make
         * them look like a decision somebody made about this backend, when
         * nobody has yet checked what this backend actually sends.
         */
        foreach (array('in_flight_statuses', 'invalid_dispositions', 'human_dispositions') as $k) {
            $ensure('payplex_aicalling_' . $k, '');
        }
    }

    if ($current < 4) {
        /*
         * The approved window is measured in the RECIPIENT's local time, which
         * is derived per lead from their country. This option is only the
         * fallback for leads whose zone cannot be derived, and it ships EMPTY
         * so those calls refuse until somebody decides otherwise.
         *
         * It deliberately does NOT inherit payplex_aicalling_timezone, which
         * held the company's single zone. Copying that value across would have
         * turned "we measured everything in our own clock" into "assume every
         * unknown lead shares our clock" — the same assumption, silently
         * preserved through the change that was meant to remove it.
         */
        $ensure('payplex_aicalling_fallback_timezone', '');
    }

    if ($current < 5) {
        /*
         * A terminal state for the outbox.
         *
         * The drain asked Payplex_retry whether a failure was retryable and
         * then wrote 'failed' either way — the answer was computed and thrown
         * away. pendingOutbox() re-selects 'failed', so an item the retry
         * policy had already judged permanent (a 4xx, or one past the attempt
         * ceiling) was resent on every cron pass, for ever, with no way to
         * stop it short of editing the row by hand.
         *
         * 'abandoned' is not in pendingOutbox()'s selection, so an item that
         * reaches it stops. Additive: the existing four values and every
         * existing row are untouched.
         */
        $CI = &get_instance();
        $table = db_prefix() . 'payplex_outbox';
        if ($CI->db->table_exists($table)) {
            $CI->db->query("ALTER TABLE `{$table}` MODIFY COLUMN `status`
                ENUM('pending','sent','failed','reconciled','abandoned')
                NOT NULL DEFAULT 'pending'");
        }

        /*
         * Why the last health check failed, not just that it did.
         *
         * A client with no request secret cannot sign and answers without
         * touching the network, which is a different problem from the backend
         * being down. Recording only a reachable flag sent an operator hunting
         * a network fault that was never there.
         */
        $health = db_prefix() . 'payplex_integration_health';
        if ($CI->db->table_exists($health) && !$CI->db->field_exists('last_error', $health)) {
            $CI->db->query("ALTER TABLE `{$health}` ADD COLUMN `last_error` VARCHAR(191) NULL");
        }
    }

    if ($current < 6) {
        /*
         * Re-encrypt any secret sitting in clear text.
         *
         * Payplex_secret::read() falls back to the raw value when decryption
         * fails, so an unencrypted credential keeps working and every
         * indicator stays green — isUsable() is true, the settings badge says
         * "configured", and nothing tells them apart. On this install the
         * service JWT was exactly that: readable in the options table while
         * request_secret and webhook_secret beside it were ciphertext.
         *
         * encryptAtRest() verifies the round-trip before writing, so a failure
         * leaves the working plaintext alone rather than replacing a live
         * credential with something unreadable. Anything that goes wrong is
         * logged and swallowed: a hardening step must not be able to take
         * admin_init down with it.
         */
        try {
            /* the bootstrap does not autoload module libraries, and a class
             * that is merely assumed to be present is how this project has
             * already shipped several silent no-ops */
            require_once __DIR__ . '/libraries/Payplex_secret.php';
            foreach (array('payplex_aicalling_service_jwt',
                           'payplex_aicalling_request_secret',
                           'payplex_aicalling_webhook_secret') as $secretKey) {
                $result = Payplex_secret::encryptAtRest($secretKey);
                if ($result === 'encrypted' && function_exists('log_activity')) {
                    /* the option NAME only — never the value, not even a length */
                    @log_activity('Payplex AI calling: re-encrypted a secret that was stored in clear '
                        . 'text at rest (' . $secretKey . '). Rotate it if it may have been exposed.');
                } elseif ($result === 'failed' && function_exists('log_activity')) {
                    @log_activity('Payplex AI calling: could NOT re-encrypt ' . $secretKey
                        . ' — it is still stored in clear text.');
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('log_activity')) {
                @log_activity('Payplex AI calling secret re-encryption failed: ' . $e->getMessage());
            }
        }
    }

    update_option('payplex_aicalling_schema_version', PAYPLEX_AICALLING_SCHEMA_VERSION);
}

/* -------------------------------------------------------------------------
 * Capabilities / permissions (menu + page + action + API + record scope).
 * Hiding a menu is NOT authorization — controllers re-check every capability.
 * ---------------------------------------------------------------------- */
hooks()->add_action('admin_init', 'payplex_aicalling_permissions');
function payplex_aicalling_permissions()
{
    $capabilities = [
        'view'               => _l('permission_view') . '(' . _l('permission_global') . ')',
        'view_all'           => 'View all calls (managers/admin)',
        'create'             => 'Start / schedule AI call',
        'cancel'             => 'Cancel scheduled call',
        'retry'              => 'Retry failed call',
        'recording_access'   => 'Access recordings',
        /*
         * 'transcript_access' is deliberately NOT registered. The webhook
         * records a transcript_available flag and nothing else in the module
         * ever serves a transcript, so there is no access to control. Offering
         * the permission told an administrator they had restricted something
         * that does not exist. Register it again with the endpoint that needs
         * it, not before.
         */
        'settings'           => 'Manage integration settings',
        'campaign_view'      => 'View campaigns',
        'campaign_create'    => 'Create campaigns',
        'campaign_approve'   => 'Approve campaigns (checker)',
        'campaign_delete'    => 'Delete campaigns',
        'consent_view'       => 'View consent/DND',
        'consent_manage'     => 'Manage consent/DND',
        'reconcile_view'     => 'View failed sync',
        'reconcile_run'      => 'Run reconciliation',
    ];
    /*
     * Perfex groups capabilities before rendering: the second argument is a map
     * of GROUP => (capability => label). A bare capability map registers
     * nothing the Roles screen can draw, so nobody could be granted any of
     * these and staff_can() answered false for every non-administrator.
     */
    register_staff_capabilities('payplex_aicalling', array('capabilities' => $capabilities), 'Payplex AI Calling');
}

/* -------------------------------------------------------------------------
 * Admin menu (rendered only if the staff member has the capability).
 * ---------------------------------------------------------------------- */
hooks()->add_action('admin_init', 'payplex_aicalling_menu');
function payplex_aicalling_menu()
{
    $CI = &get_instance();
    if (staff_can('view', 'payplex_aicalling') || is_admin()) {
        $CI->app_menu->add_sidebar_menu_item('payplex-aicalling', [
            'name'     => 'AI Calling',
            'href'     => admin_url('payplex_aicalling/aicalling'),
            'icon'     => 'fa fa-phone',
            'position' => 30,
        ]);
        $CI->app_menu->add_sidebar_children_item('payplex-aicalling', [
            'slug'     => 'payplex-aicalling-dashboard',
            'name'     => 'Dashboard',
            'href'     => admin_url('payplex_aicalling/aicalling'),
            'position' => 1,
        ]);
        $CI->app_menu->add_sidebar_children_item('payplex-aicalling', [
            'slug'     => 'payplex-aicalling-history',
            'name'     => 'Call History',
            'href'     => admin_url('payplex_aicalling/aicalling/history'),
            'position' => 2,
        ]);
        $CI->app_menu->add_sidebar_children_item('payplex-aicalling', [
            'slug'     => 'payplex-aicalling-my',
            'name'     => 'My Calls',
            'href'     => admin_url('payplex_aicalling/aicalling/my'),
            'position' => 3,
        ]);
        if (staff_can('view_all', 'payplex_aicalling') || is_admin()) {
            $CI->app_menu->add_sidebar_children_item('payplex-aicalling', [
                'slug'     => 'payplex-aicalling-team',
                'name'     => 'Team',
                'href'     => admin_url('payplex_aicalling/aicalling/team'),
                'position' => 4,
            ]);
        }
        if (staff_can('campaign_view', 'payplex_aicalling') || is_admin()) {
            $CI->app_menu->add_sidebar_children_item('payplex-aicalling', [
                'slug'     => 'payplex-aicalling-campaigns',
                'name'     => 'Campaigns',
                'href'     => admin_url('payplex_aicalling/campaigns'),
                'position' => 5,
            ]);
        }
        if (staff_can('consent_view', 'payplex_aicalling') || is_admin()) {
            $CI->app_menu->add_sidebar_children_item('payplex-aicalling', [
                'slug'     => 'payplex-aicalling-consent',
                'name'     => 'Consent & DND',
                'href'     => admin_url('payplex_aicalling/consent'),
                'position' => 6,
            ]);
        }
        if (staff_can('reconcile_view', 'payplex_aicalling') || is_admin()) {
            $CI->app_menu->add_sidebar_children_item('payplex-aicalling', [
                'slug'     => 'payplex-aicalling-reconcile',
                'name'     => 'Failed Sync',
                'href'     => admin_url('payplex_aicalling/reconciliation'),
                'position' => 7,
            ]);
        }
        if (staff_can('settings', 'payplex_aicalling') || is_admin()) {
            $CI->app_menu->add_sidebar_children_item('payplex-aicalling', [
                'slug'     => 'payplex-aicalling-settings',
                'name'     => 'Settings',
                'href'     => admin_url('payplex_aicalling/aicalling/settings'),
                'position' => 8,
            ]);
        }
    }
}

/* -------------------------------------------------------------------------
 * Lead profile tab: injects the Call Now / Schedule panel into the lead modal.
 *
 * This panel has never rendered. It listened on 'lead_profile_tabs', and the
 * comment that used to sit here called that "the documented Perfex hook". It is
 * not fired anywhere in this install: searching application/ for
 * do_action/apply_filters on that name returns nothing, while after_cron_run and
 * admin_init resolve immediately to Cron_model and AdminController. A callback
 * on a hook nobody fires never runs and nothing errors, so the tab was simply
 * absent and the code gave no sign of it.
 *
 * Worse, the name was in this project's own CI list of hooks Perfex fires — a
 * list written from memory rather than read from core — so the check that exists
 * to catch exactly this defect was passing it. Both have been corrected against
 * core: views/admin/leads/lead.php fires
 *
 *     after_lead_lead_tabs      - the tab nav
 *     after_lead_tabs_content   - the tab bodies
 *
 * These take output rather than a returned array, which is why the shape below
 * changed along with the name.
 * ---------------------------------------------------------------------- */
hooks()->add_action('after_lead_lead_tabs', 'payplex_aicalling_lead_tab_nav');
function payplex_aicalling_lead_tab_nav()
{
    echo '<li role="presentation">'
       . '<a href="#payplex_aicalling_lead" aria-controls="payplex_aicalling_lead" '
       . 'role="tab" data-toggle="tab"><i class="fa fa-phone"></i> AI Calling</a></li>';
}

hooks()->add_action('after_lead_tabs_content', 'payplex_aicalling_lead_tab_content');
function payplex_aicalling_lead_tab_content()
{
    $CI = &get_instance();
    echo '<div role="tabpanel" class="tab-pane" id="payplex_aicalling_lead">';
    echo $CI->load->view('payplex_aicalling/lead_panel', array(), true);
    echo '</div>';
}

/* Reconciliation cron (drains outbox, refreshes health, reconciles stuck calls). */
require_once __DIR__ . '/modules/cron.php';

/* Load module assets (namespaced; no collision with Perfex core). */
/**
 * A cache-busting token for the module's assets: the declared module version,
 * falling back to the file's own modification time if the header cannot be
 * read, so the token always changes when the file does.
 */
function payplex_aicalling_asset_version()
{
    static $v = null;
    if ($v !== null) { return $v; }
    if (preg_match('/^\s*\*?\s*Version:\s*([0-9][0-9.]*)/m', file_get_contents(__FILE__), $m)) {
        $v = $m[1];
    } else {
        $js = __DIR__ . '/assets/js/aicalling.js';
        $v = file_exists($js) ? (string) filemtime($js) : (string) time();
    }
    return $v;
}

hooks()->add_action('app_admin_head', 'payplex_aicalling_head');
function payplex_aicalling_head()
{
    /*
     * The module version is appended so a browser cannot keep running a stale
     * asset after a deploy. Perfex does not version module asset URLs, so
     * without this a shipped fix sits inert in every browser that already has
     * the old file — which is how four dead buttons could have been "fixed"
     * and still done nothing for most users.
     */
    echo '<link href="' . module_dir_url(PAYPLEX_AICALLING_MODULE, 'assets/css/aicalling.css')
       . '?v=' . payplex_aicalling_asset_version() . '" rel="stylesheet">';
}
hooks()->add_action('app_admin_footer', 'payplex_aicalling_footer');
function payplex_aicalling_footer()
{
    echo '<script src="' . module_dir_url(PAYPLEX_AICALLING_MODULE, 'assets/js/aicalling.js')
       . '?v=' . payplex_aicalling_asset_version() . '"></script>';
}
