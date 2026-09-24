<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Payplex Staff System
Description: Upgrade-safe Staff, Field-Employee, Commission, Expense & Fraud-Control system for Payplex/TechPlex. Batch 3 adds consent-gated field/GPS tracking: explicit revocable opt-in, capture only between check-in and check-out, 90-day raw-point retention with permanent visit summaries, an immutable consent ledger and a privacy/retention dashboard. Batch 1: rich employment-type classification (with per-type salary/commission/expense/TA-DA/attendance eligibility), a full staff lifecycle (Draft -> ... -> Archived) with maker != approver approval and KYC/bank payout gating, a dedicated least-privilege permission profile for commission employees, a 10-role permission matrix, admin-defined permission templates, immutable staff audit log, and a safe classification-required backfill for existing staff. Reads Perfex core staff read-only; never modifies core tables.
Version: 1.2.0
Requires at least: 2.3.*
Author: Payplex
*/

define('PAYPLEX_STAFF_MODULE', 'payplex_staff');
define('PAYPLEX_STAFF_SCHEMA_VERSION', 4);

register_activation_hook(PAYPLEX_STAFF_MODULE, 'payplex_staff_activate');
function payplex_staff_activate()
{
    require __DIR__ . '/install.php';
    $CI = &get_instance();
    $CI->load->model('payplex_staff/payplex_staff_model');
    $CI->payplex_staff_model->setSetting('schema_version', PAYPLEX_STAFF_SCHEMA_VERSION);
    $CI->payplex_staff_model->backfillExistingStaff(0);
}

register_uninstall_hook(PAYPLEX_STAFF_MODULE, 'payplex_staff_uninstall');
function payplex_staff_uninstall()
{
    // Non-destructive by default: keep the module's tables so data survives a
    // reinstall. Drop only on explicit operator action (documented separately).
}

hooks()->add_action('admin_init', 'payplex_staff_migrate');
hooks()->add_action('admin_init', 'payplex_staff_permissions');
hooks()->add_action('admin_init', 'payplex_staff_menu');

/* -------------------------------------------------------------------------
 * Retention and housekeeping — on the cron, not on somebody remembering.
 *
 * Payplex_staff_geo declares RETENTION_DAYS = 90 and the model implements the
 * purge properly: transactional, idempotent, writes a privacy-log entry, and
 * marks a session whose raw trail is gone so the UI can say so rather than
 * imply the points were lost. All of it was reachable only through a button on
 * the privacy screen that a person holding privacy_admin had to remember to
 * press.
 *
 * A retention limit nobody enforces is not a retention limit. Employee
 * location points are the most privacy-sensitive records this module holds,
 * and until now they were kept until somebody chose to delete them — while the
 * screen displayed a 90-day policy.
 *
 * 'after_cron_run' is the hook Perfex actually fires (application/models/
 * Cron_model.php). 'app_cron' does not exist anywhere in Perfex, and this
 * project has already shipped one cron callback registered on it that
 * therefore never ran once.
 * ---------------------------------------------------------------------- */
/* -------------------------------------------------------------------------
 * Coverage, on the event — not on somebody remembering to press a button.
 *
 * ARK Gupta (#29) was created on 2026-09-10 and had no workforce profile at
 * all. Nothing hooked staff creation: coverage came from an admin pressing
 * "Backfill existing staff". A workforce system that silently misses new
 * joiners is worse than one that has none, because the gap is invisible.
 *
 * These four names are fired by application/models/Staff_model.php on this
 * install — read, not assumed. Five silent no-ops on this project have come
 * from callbacks registered on hook names nobody fires.
 * ---------------------------------------------------------------------- */
require_once __DIR__ . '/libraries/Payplex_staff_sync.php';

/**
 * Setup > Staff > New Staff Member: administrators can fill in the whole workforce profile
 * on the same form. The fields are posted as wf_* and must never reach the core insert
 * (tblstaff has no such columns), so the filter below takes them out and parks them until
 * staff_member_created hands us the new staff id.
 */
function payplex_staff_create_stash($set = false, $value = null)
{
    static $stash = null;
    if ($set) { $stash = $value; return null; }
    $out = $stash; $stash = null;
    return $out;
}

hooks()->add_action('staff_render_profile_fields', 'payplex_staff_render_create_fields');
function payplex_staff_render_create_fields($member)
{
    if ($member || !function_exists('is_admin') || !is_admin()) { return; }
    payplex_staff_guard(function () {
        $CI = &get_instance();
        $CI->load->model('payplex_staff/payplex_staff_model', 'ppstaff_form_model');
        $types = Payplex_staff_types::types();
        $defs  = array();
        foreach ($types as $slug => $def) { $defs[$slug] = $def['elig']; }
        $managers = array_values(array_filter($CI->ppstaff_form_model->coreStaff(), function ($s) { return (int) $s->active === 1; }));
        $CI->load->view('payplex_staff/staff_create_fields', array(
            'types' => Payplex_staff_types::options(), 'typeDefs' => $defs, 'managers' => $managers,
        ));
    });
}

hooks()->add_filter('before_create_staff_member', 'payplex_staff_extract_create_fields', 5);
function payplex_staff_extract_create_fields($data)
{
    $wf = array();
    foreach (array_keys((array) $data) as $k) {
        if (strpos($k, 'wf_') === 0) {
            $wf[substr($k, 3)] = $data[$k];
            unset($data[$k]);
        }
    }
    // Only an administrator's submission is honoured; anything else is discarded.
    if (!$wf || !function_exists('is_admin') || !is_admin()) {
        payplex_staff_create_stash(true, null);
        return $data;
    }
    $wf['full_name'] = trim(($data['firstname'] ?? '') . ' ' . ($data['lastname'] ?? ''));
    if (trim((string) ($wf['official_email'] ?? '')) === '') { $wf['official_email'] = (string) ($data['email'] ?? ''); }
    foreach (array('kyc_status' => array('pending', 'submitted', 'verified', 'rejected'), 'pan_status' => array('pending', 'submitted', 'verified')) as $f => $ok) {
        if (!in_array($wf[$f] ?? 'pending', $ok, true)) { $wf[$f] = 'pending'; }
    }
    payplex_staff_create_stash(true, $wf);

    return $data;
}

hooks()->add_action('staff_member_created', 'payplex_staff_on_created');
function payplex_staff_on_created($payload)
{
    try {
        $id = Payplex_staff_sync::staffIdFrom($payload);
        if ($id <= 0) { return $payload; }
        $CI = &get_instance();
        $CI->load->model('payplex_staff/payplex_staff_model');

        $wf = payplex_staff_create_stash();
        if (is_array($wf) && trim((string) ($wf['employment_type'] ?? '')) !== '') {
            $wf['staff_id'] = $id;
            $res = $CI->payplex_staff_model->saveProfile($wf, (int) get_staff_user_id());
            if (!empty($res['ok'])) { return $payload; }
            // Account exists already; keep it and fall back to the placeholder profile below.
            set_alert('warning', 'Staff created, but the workforce profile was not saved: '
                . implode(', ', array_map(function ($e) { return str_replace('_', ' ', $e); }, (array) ($res['errors'] ?? array('unknown_error'))))
                . '. Complete it under Staff System.');
        } elseif (is_array($wf)) {
            $filled = array_filter($wf, function ($v, $k) {
                return !in_array($k, array('full_name', 'kyc_status', 'pan_status', 'payout_frequency'), true) && trim((string) $v) !== '';
            }, ARRAY_FILTER_USE_BOTH);
            if ($filled) { set_alert('warning', 'Workforce details were not saved because no employment type was chosen. Complete them under Staff System.'); }
        }
        $CI->payplex_staff_model->ensureProfile($id, 0, 'staff_member_created');
    } catch (\Throwable $e) {
        log_activity('Payplex Staff: profile creation hook failed - ' . $e->getMessage());
    }
    return $payload;
}

hooks()->add_action('after_staff_status_change', 'payplex_staff_on_status_change');
function payplex_staff_on_status_change($payload)
{
    try {
        $info = Payplex_staff_sync::statusChangeFrom($payload);
        if ($info['staff_id'] <= 0) { return $payload; }
        $CI = &get_instance();
        $CI->load->model('payplex_staff/payplex_staff_model');
        $CI->payplex_staff_model->noteCrmStatusChange($info['staff_id'], $info['active']);
    } catch (\Throwable $e) {
        log_activity('Payplex Staff: status sync hook failed - ' . $e->getMessage());
    }
    return $payload;
}

hooks()->add_action('staff_member_deleted', 'payplex_staff_on_deleted');
function payplex_staff_on_deleted($payload)
{
    /*
     * The profile is NOT deleted. A workforce record carries approval history,
     * consent, field sessions and audit entries, and the brief forbids removing
     * anything before migration and UAT. It is marked instead, so the record
     * survives and says what happened to the account it belonged to.
     */
    try {
        $id = Payplex_staff_sync::staffIdFrom($payload);
        if ($id <= 0) { return $payload; }
        $CI = &get_instance();
        $CI->load->model('payplex_staff/payplex_staff_model');
        $CI->payplex_staff_model->noteCrmAccountRemoved($id);
    } catch (\Throwable $e) {
        log_activity('Payplex Staff: account-removed hook failed - ' . $e->getMessage());
    }
    return $payload;
}

hooks()->add_action('after_cron_run', 'payplex_staff_cron');
function payplex_staff_cron()
{
    try {
        $CI = &get_instance();
        $CI->load->model('payplex_staff/payplex_staff_model', 'ppstaff_cron_model');

        /*
         * Actor 0 means "the system did this, no person chose it". The privacy
         * log records the actor, and attributing an automatic deletion to
         * whichever administrator happened to trigger the cron would put a
         * name against a decision nobody made.
         */
        $purge = $CI->ppstaff_cron_model->purgeExpiredLocations(0);
        $swept = $CI->ppstaff_cron_model->closeAbandonedSessions(0);

        /*
         * Say something when something happened.
         *
         * This used to write two rows into the CRM's main activity feed on
         * every single cron pass — "run started", then "purged 0 … closed 0" —
         * which on this install's schedule is around 290 rows a day of nothing,
         * in the feed people actually read to find out what the system did.
         * Four hours of it was already visible in the log when this was found.
         *
         * The original intent stands and is kept: a job that never runs looks
         * exactly like a job with nothing to do, so silence cannot be the only
         * evidence of health. The purge itself now keeps a daily heartbeat in
         * the privacy log, which is the right place for it — it is a record
         * about personal data — and this feed hears from housekeeping only
         * when housekeeping did something.
         */
        $deleted = (int) (isset($purge['deleted']) ? $purge['deleted'] : 0);
        $failed  = isset($purge['ok']) && !$purge['ok'];
        if (function_exists('log_activity') && ($deleted > 0 || (int) $swept > 0 || $failed)) {
            @log_activity('Payplex Staff housekeeping: '
                . ($failed ? 'PURGE FAILED — ' . (isset($purge['reason']) ? $purge['reason'] : '') . ' '
                           : 'purged ' . $deleted . ' expired location point(s) (cutoff '
                             . (isset($purge['cutoff']) ? $purge['cutoff'] : 'n/a') . '); ')
                . 'closed ' . (int) $swept . ' abandoned session(s).');
        }
    } catch (\Throwable $e) {
        /*
         * Degrade this module rather than the whole cron pass: other modules'
         * jobs run on the same hook and must not be lost to a failure here.
         */
        if (function_exists('log_activity')) {
            @log_activity('Payplex Staff housekeeping error: ' . $e->getMessage()
                . ' @' . $e->getFile() . ':' . $e->getLine());
        }
    }
}

/** Idempotent schema migration + one-time backfill of existing staff. */
/** A module must never be able to 500 the whole admin. Every admin_init hook
 *  body is wrapped so any error is logged and swallowed, not fatal. */
function payplex_staff_guard($fn)
{
    try { $fn(); }
    catch (\Throwable $e) {
        if (function_exists('log_activity')) {
            @log_activity('Payplex Staff System error: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        }
    }
}

function payplex_staff_migrate()
{
    payplex_staff_guard(function () {
        $CI = &get_instance();
        $CI->load->model('payplex_staff/payplex_staff_model');
        $current = (int) $CI->payplex_staff_model->getSetting('schema_version', 0);
        if ($current < PAYPLEX_STAFF_SCHEMA_VERSION) {
            require __DIR__ . '/install.php';   // all CREATE TABLE IF NOT EXISTS
            $CI->payplex_staff_model->setSetting('schema_version', PAYPLEX_STAFF_SCHEMA_VERSION);
        }
        // Backfill runs once (guarded by a setting) so existing staff are flagged.
        if ((int) $CI->payplex_staff_model->getSetting('backfilled', 0) !== 1) {
            $CI->payplex_staff_model->backfillExistingStaff(0);
            $CI->payplex_staff_model->setSetting('backfilled', 1);
        }
    });
}

function payplex_staff_permissions()
{
    payplex_staff_guard('payplex_staff_permissions_do');
}
function payplex_staff_permissions_do()
{
    if (!function_exists('register_staff_capabilities')) { return; }
    /*
     * 'classify' and 'bank' are deliberately NOT registered.
     *
     * All three appeared on the Roles screen and were enforced nowhere, so
     * granting one changed nothing while telling an administrator they had
     * restricted access to classification or bank details. For permissions over
     * bank data that false assurance is the worst kind. They return when the
     * screens that honour them exist; who may see that data is a business
     * decision, not one to invent here.
     *
     * 'kyc' was on that list until the document store existed. It is now
     * registered as 'documents_verify' and enforced in three places — the
     * documents screen, the verify action and the download action, which refuses
     * and logs before serving a byte. A capability is registered here only once
     * something actually answers to it.
     */
    $caps = array(
        'view'            => 'View staff system',
        'manage'          => 'Create / edit staff profiles',
        'submit'          => 'Submit staff for verification',
        'verify'          => 'Verify staff records',
        'approve'         => 'Approve staff records',
        'lifecycle'       => 'Suspend / notice / exit / archive',
        'perm_templates'  => 'Manage permission templates',
        'audit'           => 'View staff audit log',
        'activity'        => 'View / log employee activity',
        'performance'     => 'View / compute performance scores',
        'kpi_config'      => 'Configure KPI definitions',
        'field_tracking'  => 'View / manage field sessions & location data',
        'privacy_admin'   => 'Manage data retention & privacy log',
        'documents_verify'=> 'Verify / reject compliance documents and view others\' documents',
        /*
         * Expense claims. Nine names rather than two, because every one of them
         * is a different act and granting one must not silently grant another.
         * The lesson is already in payplex_commission, where `reject` had to be
         * split out of `review` after it turned out that authority to reject was
         * carrying authority to re-open a rejection with it.
         *
         * The payout half of the lifecycle — payout, payout_retry,
         * payout_approve, payout_reverse — is NOT registered here. It belongs to
         * payplex_commission, whose engine performs it. Registering it in both
         * places would create two gates with one name, which is how a permission
         * comes to mean different things on different screens.
         */
        'expense_submit'        => 'Raise and submit an expense claim',
        'expense_view_own'      => 'View own expense claims',
        'expense_view_all'      => 'View everybody\'s expense claims',
        'expense_review_manager'=> 'Manager review of an expense claim (first checker)',
        'expense_review_finance'=> 'Finance review of an expense claim (second checker)',
        'expense_approve'       => 'Approve an expense claim for payout (must not have reviewed it)',
        'expense_bill_view'     => 'View the bills and payment evidence behind others\' claims',
        'bank_manage'           => 'Record and update beneficiary bank details',
        'bank_verify'           => 'Verify beneficiary bank details (never your own)',
    );
    register_staff_capabilities(PAYPLEX_STAFF_MODULE, array('capabilities' => $caps), 'Payplex Staff System');
}

function payplex_staff_can($cap)
{
    if (function_exists('is_admin') && is_admin()) { return true; }
    if (function_exists('staff_can')) { return staff_can($cap, PAYPLEX_STAFF_MODULE); }
    return false;
}

function payplex_staff_menu()
{
    payplex_staff_guard('payplex_staff_menu_do');
}
function payplex_staff_menu_do()
{
    $CI = &get_instance();
    if (!isset($CI->app_menu) || !$CI->app_menu) { return; }
    if (!payplex_staff_can('view')) { return; }

    $CI->app_menu->add_sidebar_menu_item('payplex-staff', array(
        'name'     => 'Staff System',
        'icon'     => 'fa fa-id-badge',
        'href'     => admin_url('payplex_staff/staff'),
        'position' => 27,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
        'slug'     => 'payplex-staff-classification',
        'name'     => 'Classification',
        'href'     => admin_url('payplex_staff/staff'),
        'position' => 1,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
        'slug'     => 'payplex-staff-templates',
        'name'     => 'Permission Templates',
        'href'     => admin_url('payplex_staff/staff/templates'),
        'position' => 2,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
        'slug'     => 'payplex-staff-matrix',
        'name'     => 'Role Matrix',
        'href'     => admin_url('payplex_staff/staff/matrix'),
        'position' => 3,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
        'slug'     => 'payplex-staff-audit',
        'name'     => 'Audit Log',
        'href'     => admin_url('payplex_staff/staff/audit'),
        'position' => 4,
    ));
    if (payplex_staff_can('activity')) {
        $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
            'slug'     => 'payplex-staff-performance',
            'name'     => 'Performance Board',
            'href'     => admin_url('payplex_staff/staff/performance'),
            'position' => 5,
        ));
    }
    $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
        'slug'     => 'payplex-staff-consent',
        'name'     => 'Location Consent',
        'href'     => admin_url('payplex_staff/staff/consent'),
        'position' => 7,
    ));
    if (payplex_staff_can('field_tracking')) {
        $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
            'slug'     => 'payplex-staff-field',
            'name'     => 'Field Sessions',
            'href'     => admin_url('payplex_staff/staff/field'),
            'position' => 8,
        ));
    }
    $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
        'slug'     => 'payplex-staff-documents',
        'name'     => 'My Documents',
        'href'     => admin_url('payplex_staff/staff/documents'),
        'position' => 10,
    ));
    if (payplex_staff_can('documents_verify')) {
        $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
            'slug'     => 'payplex-staff-documents-register',
            'name'     => 'Document Compliance',
            'href'     => admin_url('payplex_staff/staff/documents_register'),
            'position' => 11,
        ));
    }
    if (payplex_staff_can('expense_view_own') || payplex_staff_can('expense_view_all')) {
        $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
            'slug'     => 'payplex-staff-expenses',
            'name'     => 'Expense Claims',
            'href'     => admin_url('payplex_staff/staff/expenses'),
            'position' => 12,
        ));
    }
    $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
        'slug'     => 'payplex-staff-bank',
        'name'     => 'Bank Details',
        'href'     => admin_url('payplex_staff/staff/bank'),
        'position' => 13,
    ));
    if (payplex_staff_can('privacy_admin')) {
        $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
            'slug'     => 'payplex-staff-privacy',
            'name'     => 'Privacy & Retention',
            'href'     => admin_url('payplex_staff/staff/privacy'),
            'position' => 9,
        ));
    }
    if (payplex_staff_can('kpi_config')) {
        $CI->app_menu->add_sidebar_children_item('payplex-staff', array(
            'slug'     => 'payplex-staff-kpi',
            'name'     => 'KPI Config',
            'href'     => admin_url('payplex_staff/staff/kpi'),
            'position' => 6,
        ));
    }
}
