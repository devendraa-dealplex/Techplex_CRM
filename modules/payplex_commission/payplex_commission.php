<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Module Name: Payplex Commission
 * Description: Commission engine — configurable rules (fixed/%/slab/accelerator),
 *              immutable approved statements, maker-checker approval, payout,
 *              disputes, clawback. Upgrade-safe; no Perfex core files modified.
 * Version: 0.3.0
 * Requires at least: 2.3.*
 * Author: Payplex
 */

define('PAYPLEX_COMMISSION_MODULE', 'payplex_commission');
define('PAYPLEX_COMMISSION_SCHEMA_VERSION', 9);

register_activation_hook(PAYPLEX_COMMISSION_MODULE, 'payplex_commission_activate');
function payplex_commission_activate()
{
    require_once __DIR__ . '/install.php';
}

register_deactivation_hook(PAYPLEX_COMMISSION_MODULE, 'payplex_commission_deactivate');
function payplex_commission_deactivate()
{
    // Non-destructive: financial data retained.
}

hooks()->add_action('admin_init', 'payplex_commission_migrate');

/**
 * Idempotent schema migration. install.php is entirely CREATE TABLE IF NOT
 * EXISTS plus guarded data fixes, so re-running it is safe. Wrapped so that a
 * failure here degrades this module rather than taking down the whole admin.
 */
function payplex_commission_migrate()
{
    try {
        $CI = &get_instance();
        $current = (int) get_option('payplex_commission_schema_version');
        if ($current < PAYPLEX_COMMISSION_SCHEMA_VERSION) {
            require __DIR__ . '/install.php';
            update_option('payplex_commission_schema_version', PAYPLEX_COMMISSION_SCHEMA_VERSION);
        }
    } catch (\Throwable $e) {
        if (function_exists('log_activity')) {
            @log_activity('Payplex Commission migration error: ' . $e->getMessage()
                . ' @' . $e->getFile() . ':' . $e->getLine());
        }
    }
}

hooks()->add_action('admin_init', 'payplex_commission_permissions');
function payplex_commission_permissions()
{
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
    register_staff_capabilities('payplex_commission', array('capabilities' => [
            'view_own'  => 'View own commission',
            'view_all'  => 'View all commission',
            'compute'   => 'Generate statements',
            'approve'   => 'Approve statements (checker)',
            'pay'       => 'Mark paid (finance)',
            'rules'     => 'Manage commission rules',
            'rules_approve' => 'Approve commission rules (must not be the rule author)',
            'source_policy' => 'Manage the commission source policy',
            'source_policy_approve' => 'Approve the source policy (must not be its author)',
            'review' => 'Send a statement for review or re-open a rejected one',
            /*
             * 'reject' is separate from 'review' on purpose.
             *
             * Rejection used to ride on 'review', which also permits moving a
             * statement back INTO review — so granting the authority to reject
             * silently granted the authority to re-open a rejection. A checker
             * appointed to approve or reject does not need to undo either, and
             * the decision that created this role named the two acts
             * separately. Splitting them is what makes "grant only these" mean
             * what it says.
             */
            'reject' => 'Reject a statement (checker)',
            'audit_view' => 'View the statement audit trail',
            'clawback' => 'Raise and review clawbacks',
            'payout' => 'Prepare payout batches and exports',
            'payout_approve' => 'Approve and export payout batches (must not be the preparer)',
            /*
             * Added for Module 8. Retrying a failed payment and reversing a
             * completed one were both riding on `payout`, which meant the person
             * who prepares a batch could also undo a settled payment. They are
             * different acts with different risk and now need different grants.
             */
            'payout_retry' => 'Retry a failed payout item (after the details are corrected)',
            'payout_reverse' => 'Reverse a payment that has already settled',
    
    ]), 'Payplex Commission');
}

hooks()->add_action('admin_init', 'payplex_commission_menu');
function payplex_commission_menu()
{
    $CI = &get_instance();
    // Everyone with a staff account can see "My Commission".
    $CI->app_menu->add_sidebar_menu_item('payplex-commission', [
        'name'     => 'Commission',
        'href'     => admin_url('payplex_commission/my_commission'),
        'icon'     => 'fa fa-percent',
        'position' => 31,
    ]);
    $CI->app_menu->add_sidebar_children_item('payplex-commission', [
        'slug' => 'payplex-commission-my', 'name' => 'My Commission',
        'href' => admin_url('payplex_commission/my_commission'), 'position' => 1,
    ]);
    if (staff_can('view_all', 'payplex_commission') || is_admin()) {
        $CI->app_menu->add_sidebar_children_item('payplex-commission', [
            'slug' => 'payplex-commission-admin', 'name' => 'All Statements',
            'href' => admin_url('payplex_commission/commission'), 'position' => 2,
        ]);
    }
    if (staff_can('rules', 'payplex_commission') || is_admin()) {
        $CI->app_menu->add_sidebar_children_item('payplex-commission', [
            'slug' => 'payplex-commission-rule-builder', 'name' => 'Rule Builder',
            'href' => admin_url('payplex_commission/commission/rule_builder'), 'position' => 3,
        ]);
        $CI->app_menu->add_sidebar_children_item('payplex-commission', [
            'slug' => 'payplex-commission-source-policy', 'name' => 'Source Policy',
            'href' => admin_url('payplex_commission/commission/source_policy'), 'position' => 4,
        ]);
        $CI->app_menu->add_sidebar_children_item('payplex-commission', [
            'slug' => 'payplex-commission-preview', 'name' => 'Generation Preview',
            'href' => admin_url('payplex_commission/commission/preview'), 'position' => 5,
        ]);
        $CI->app_menu->add_sidebar_children_item('payplex-commission', [
            'slug' => 'payplex-commission-approvals', 'name' => 'Approval Queue',
            'href' => admin_url('payplex_commission/commission/approvals'), 'position' => 6,
        ]);
        $CI->app_menu->add_sidebar_children_item('payplex-commission', [
            'slug' => 'payplex-commission-clawbacks', 'name' => 'Clawbacks',
            'href' => admin_url('payplex_commission/commission/clawbacks'), 'position' => 7,
        ]);
        $CI->app_menu->add_sidebar_children_item('payplex-commission', [
            'slug' => 'payplex-commission-payouts', 'name' => 'Payout Batches',
            'href' => admin_url('payplex_commission/commission/payouts'), 'position' => 8,
        ]);
        $CI->app_menu->add_sidebar_children_item('payplex-commission', [
            'slug' => 'payplex-commission-rules', 'name' => 'Rules (legacy)',
            'href' => admin_url('payplex_commission/commission/rules'), 'position' => 9,
        ]);
    }
}
