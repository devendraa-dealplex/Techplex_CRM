<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Payplex All Leads
Description: Adds an "All Leads" option under the Leads menu that lists every lead in one page.
Version: 1.0.0
Author: Payplex
*/

define('PAYPLEX_ALL_LEADS_MODULE', 'payplex_all_leads');

hooks()->add_action('admin_init', 'payplex_all_leads_menu');

/**
 * Add a child item "All Leads" under the native Leads sidebar menu.
 * Falls back to a top-level sidebar item if the Leads parent is unavailable.
 */
function payplex_all_leads_menu()
{
    $CI = &get_instance();

    if (!isset($CI->app_menu) || !$CI->app_menu) {
        return;
    }

    // Only show to users who can view leads (admins included).
    if (!is_admin() && !has_permission('leads', '', 'view')) {
        return;
    }

    $CI->app_menu->add_sidebar_children_item('leads', [
        'slug'     => 'payplex-all-leads',
        'name'     => 'All Leads',
        'href'     => admin_url('payplex_all_leads/all_leads'),
        'position' => 30,
    ]);
}

/*
 * One missing index on tblleads.phonenumber.
 *
 * tblleads is indexed on name, company, email, assigned, status, source,
 * lastcontact, dateadded, leadorder and from_form_id — and not on phonenumber.
 * Three things scan it:
 *
 *   - the WhatsApp webhook, which looks a lead up by phone number on EVERY
 *     inbound message before deciding whether to create one;
 *   - the Google webhook, same, as its second deduplication key;
 *   - the All Leads search on this page, which filters on it.
 *
 * At thirteen rows none of that matters. At the volume the lead-gen modules
 * exist to produce, every inbound WhatsApp message becomes a full table scan,
 * and the cost arrives exactly when the system is busiest. An index is the
 * cheapest fix in this entire audit and the one most likely to be noticed only
 * once it is too late.
 *
 * Additive, idempotent and guarded by a schema-version option. It creates one
 * index, drops nothing, rewrites nothing, and rolls back with a single
 * DROP INDEX. tblleads is a Perfex core table, so no core FILE is touched —
 * adding a non-unique index changes no behaviour and survives an upgrade,
 * which is the distinction that matters here.
 */
hooks()->add_action('admin_init', 'payplex_all_leads_run_migrations');

function payplex_all_leads_run_migrations()
{
    if ((int) get_option('payplex_all_leads_schema_version') >= 1) {
        return;
    }

    $CI    = &get_instance();
    $table = db_prefix() . 'leads';

    if (!$CI->db->table_exists($table)) {
        return;
    }

    try {
        $existing = $CI->db->query(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = 'payplex_phonenumber'"
        )->result();

        if (empty($existing)) {
            $CI->db->query("ALTER TABLE `{$table}` ADD INDEX `payplex_phonenumber` (`phonenumber`)");
            log_activity('payplex_all_leads: added index on tblleads.phonenumber — '
                . 'the WhatsApp and Google webhooks look leads up by phone number on every '
                . 'inbound message and were doing so without one.');
        }

        update_option('payplex_all_leads_schema_version', '1');
    } catch (Throwable $e) {
        /*
         * Throwable, not Exception. A schema error here must not take down
         * admin_init and every module hooked after it — payplex_meetings did
         * precisely that to the cron chain by catching the narrower type, and
         * it went unnoticed for as long as it existed.
         */
        log_activity('payplex_all_leads migration failed: ' . $e->getMessage());
    }
}
