<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Sales Targets
Description: Set staff sales targets (leads converted) per period and track live progress.
Version: 1.2.0
Author: TechPlex Solutions
*/

define('SALES_TARGETS_MODULE_NAME', 'sales_targets');
define('SALES_TARGETS_SCHEMA_VERSION', '5'); // v5: drop the six unenforced capability grants

register_activation_hook(SALES_TARGETS_MODULE_NAME, 'sales_targets_activation_hook');

function sales_targets_activation_hook()
{
    $CI = &get_instance();

    $CI->db->query("CREATE TABLE IF NOT EXISTS `tblsales_targets` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `staff_id` int(11) NOT NULL,
        `target_value` int(11) NOT NULL,
        `period_start` date NOT NULL,
        `period_end` date NOT NULL,
        `notes` varchar(255) DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `staff_id` (`staff_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Run the Phase 2 schema upgrade immediately on (re)activation too, so a
    // fresh activation on another environment ends up fully up to date in one step.
    sales_targets_run_migrations();
}

register_language_files(SALES_TARGETS_MODULE_NAME, array(SALES_TARGETS_MODULE_NAME));

hooks()->add_action('admin_init', 'sales_targets_module_init_menu_item');
hooks()->add_action('admin_init', 'sales_targets_permissions');
hooks()->add_action('admin_init', 'sales_targets_run_migrations');

// Phase 3 — same after_cron_run pattern the native Goals module uses for its
// own notification job (modules/goals/goals.php). Runs once per Perfex cron
// pass; harmless/no-op if the daily snapshots table doesn't exist yet (e.g.
// mid-deploy before admin_init has run the migration once) because the
// engine's own query would simply find no Active targets to snapshot.
hooks()->add_action('after_cron_run', 'sales_targets_daily_snapshot_cron');

function sales_targets_module_init_menu_item()
{
    $CI = &get_instance();

    // Visible to every logged-in staff member (each sees only their own target
    // on the index page); add/edit/delete is further restricted inside the
    // controller itself via is_admin() or the granular sales_targets capabilities.
    $CI->app_menu->add_sidebar_menu_item('sales_targets', array(
        'name'     => _l('sales_targets_title'),
        'icon'     => 'fa fa-bullseye',
        'href'     => admin_url('sales_targets'),
        'position' => 46,
    ));
}

/**
 * Phase 3 — idempotent daily snapshot job. Guarded so it only runs once the
 * schema v3 migration (tblsales_target_daily_snapshots) has actually
 * completed, avoiding any race with a fresh deploy where cron might fire
 * before an admin has loaded a page yet.
 */
function sales_targets_daily_snapshot_cron()
{
    $CI = &get_instance();

    $CI->db->where('name', 'sales_targets_schema_version');
    $current = $CI->db->get(db_prefix() . 'options')->row();

    if (!$current || (int) $current->value < 3) {
        return;
    }

    if (!class_exists('Kpi_engine')) {
        require_once __DIR__ . '/libraries/Kpi_engine.php';
    }

    $engine = new Kpi_engine();
    $engine->run_daily_snapshot(date('Y-m-d'));
}

/**
 * Phase 2 — register granular Perfex capabilities for this module, following
 * the same register_staff_capabilities() pattern the native Goals module uses
 * (modules/goals/goals.php -> goals_permissions()). is_admin() is still
 * honored everywhere as a superset/fallback so nothing that currently works
 * for admins changes.
 */
function sales_targets_permissions()
{
    $capabilities = array();

        /*
         * The capabilities below are deliberately NOT registered.
         *
         * Each was offered on the Roles screen and enforced nowhere, so
         * granting one changed nothing while telling an administrator they had
         * delegated or restricted something. That is worse than an absent
         * permission: it manufactures confidence in a control that does not
         * exist. They return when the code that honours them does — and who may
         * see revenue or commission figures, export reports, resolve disputes
         * or change module settings is a business decision, not one to invent
         * here.
         *
         *   view_own         — own-target access is already decided by
         *                      assignment (Target_workflow::isAssignee), not by
         *                      a permission.
         *   view_revenue     — no screen separates revenue figures yet.
         *   view_commission  — likewise for commission figures.
         *   export_reports   — no export endpoint exists in this module.
         *   resolve_disputes — no dispute workflow exists in this module.
         *   manage_settings  — no settings screen exists in this module.
         */
    $capabilities['capabilities'] = array(
        'view_team'        => _l('sales_targets_perm_view_team'),
        'view_all'         => _l('sales_targets_perm_view_all'),
        'create'           => _l('sales_targets_perm_create'),
        'edit_draft'       => _l('sales_targets_perm_edit_draft'),
        'submit'           => _l('sales_targets_perm_submit'),
        'approve_activate' => _l('sales_targets_perm_approve_activate'),
        'revise_active'    => _l('sales_targets_perm_revise_active'),
        'lock_complete'    => _l('sales_targets_perm_lock_complete'),
        'cancel_archive'   => _l('sales_targets_perm_cancel_archive'),
    );

    register_staff_capabilities('sales_targets', $capabilities, _l('sales_targets_title'));
}

/**
 * Phase 2 — safe, idempotent schema upgrade for an already-active module.
 * Guarded by a tboptions flag so the (cheap) version check runs on every
 * admin_init, but the actual ALTER/CREATE/backfill statements only run once,
 * the first time a request hits the site after this code is deployed.
 * No existing table is dropped or renamed; only additive columns and brand
 * new tables are created, and existing tblsales_targets rows are preserved
 * exactly (they pick up status = 'Active' via the column DEFAULT).
 */
function sales_targets_run_migrations()
{
    $CI = &get_instance();

    $CI->db->where('name', 'sales_targets_schema_version');
    $current = $CI->db->get(db_prefix() . 'options')->row();
    $current_version = (int) ($current ? $current->value : 0);
    $target_version = (int) SALES_TARGETS_SCHEMA_VERSION;

    if ($current_version >= $target_version) {
        return;
    }

    // Each version block below only runs the steps needed to reach that
    // version, so bumping the version number never re-executes an earlier
    // phase's one-time backfill/audit-log work a second time.
    if ($current_version < 2) {
        sales_targets_migrate_to_v2($CI);
    }

    if ($current_version < 3) {
        sales_targets_migrate_to_v3($CI);
    }

    if ($current_version < 4) {
        sales_targets_migrate_to_v4($CI);
    }

    if ($current_version < 5) {
        sales_targets_migrate_to_v5($CI);
    }

    // Record the schema version so none of the above runs again.
    if ($current) {
        $CI->db->where('name', 'sales_targets_schema_version');
        $CI->db->update(db_prefix() . 'options', array('value' => SALES_TARGETS_SCHEMA_VERSION));
    } else {
        $CI->db->insert(db_prefix() . 'options', array(
            'name'     => 'sales_targets_schema_version',
            'value'    => SALES_TARGETS_SCHEMA_VERSION,
            'autoload' => 0,
        ));
    }
}

/**
 * Phase 2 — safe, idempotent schema upgrade for an already-active module.
 * No existing table is dropped or renamed; only additive columns and brand
 * new tables are created, and existing tblsales_targets rows are preserved
 * exactly (they pick up status = 'Active' via the column DEFAULT).
 */
/**
 * v5 — remove the grants for capabilities this module no longer registers.
 *
 * Six capabilities were dropped because they appeared on the Roles screen and
 * were enforced nowhere (see DROPPED_PERMISSIONS.md). Deregistering them stops
 * the Roles screen offering them, but any grant already written to
 * tblstaff_permissions stays behind: an orphan row naming a permission that no
 * longer exists, which reads in an audit as though somebody still holds it.
 *
 * The rows are deleted rather than remapped. Mapping them onto a broader
 * surviving capability would hand people authority nobody decided to give them,
 * which is the opposite of what dropping an unenforced permission is for.
 */
function sales_targets_migrate_to_v5($CI)
{
    $dropped = array('view_own', 'view_revenue', 'view_commission',
                     'export_reports', 'resolve_disputes', 'manage_settings');

    $table = db_prefix() . 'staff_permissions';
    if (!$CI->db->table_exists($table)) { return; }

    $rows = $CI->db->select('staff_id, capability')
        ->where('feature', SALES_TARGETS_MODULE_NAME)
        ->where_in('capability', $dropped)
        ->get($table)->result_array();

    if (!$rows) { return; }

    $CI->db->where('feature', SALES_TARGETS_MODULE_NAME)
        ->where_in('capability', $dropped)
        ->delete($table);

    if (function_exists('log_activity')) {
        $detail = array();
        foreach ($rows as $r) { $detail[] = $r['capability'] . ' (staff #' . (int) $r['staff_id'] . ')'; }
        @log_activity('Sales Targets: removed ' . count($rows) . ' grant(s) for capabilities the '
            . 'module no longer registers: ' . implode(', ', $detail) . '. These permissions were '
            . 'enforced nowhere; they were dropped, not remapped.');
    }
}

function sales_targets_migrate_to_v2($CI)
{
    // --- Additive columns on the existing tblsales_targets table ---
    $existing_columns = $CI->db->list_fields(db_prefix() . 'sales_targets');

    if (!in_array('status', $existing_columns)) {
        $CI->db->query("ALTER TABLE `" . db_prefix() . "sales_targets`
            ADD COLUMN `status` varchar(20) NOT NULL DEFAULT 'Active' AFTER `notes`");
    }
    if (!in_array('scope_type', $existing_columns)) {
        $CI->db->query("ALTER TABLE `" . db_prefix() . "sales_targets`
            ADD COLUMN `scope_type` varchar(20) DEFAULT NULL AFTER `status`");
    }
    if (!in_array('scope_id', $existing_columns)) {
        $CI->db->query("ALTER TABLE `" . db_prefix() . "sales_targets`
            ADD COLUMN `scope_id` int(11) DEFAULT NULL AFTER `scope_type`");
    }
    if (!in_array('timezone', $existing_columns)) {
        $CI->db->query("ALTER TABLE `" . db_prefix() . "sales_targets`
            ADD COLUMN `timezone` varchar(64) DEFAULT NULL AFTER `scope_id`");
    }

    // --- New Phase 2 foundation tables ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "sales_target_metrics` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `target_id` int(11) NOT NULL,
        `kpi_key` varchar(50) NOT NULL,
        `target_value` decimal(15,2) NOT NULL,
        `unit` varchar(20) NOT NULL DEFAULT 'count',
        `weight` decimal(5,2) NOT NULL DEFAULT 100.00,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `target_id` (`target_id`),
        KEY `kpi_key` (`kpi_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "sales_target_assignees` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `target_id` int(11) NOT NULL,
        `staff_id` int(11) NOT NULL,
        `effective_start` date DEFAULT NULL,
        `effective_end` date DEFAULT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `target_id` (`target_id`),
        KEY `staff_id` (`staff_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "sales_target_revisions` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `target_id` int(11) NOT NULL,
        `field_changed` varchar(50) NOT NULL,
        `old_value` text,
        `new_value` text,
        `reason` text,
        `requested_by` int(11) DEFAULT NULL,
        `approved_by` int(11) DEFAULT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `target_id` (`target_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "sales_target_audit_logs` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `target_id` int(11) DEFAULT NULL,
        `staff_id` int(11) DEFAULT NULL,
        `action` varchar(100) NOT NULL,
        `description` text,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `target_id` (`target_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- One-time backfill: give every pre-existing target row a metric row
    //     and an assignee row, so the new tables are consistent for old data
    //     without changing what the old data means. ---
    $existing_targets = $CI->db->get(db_prefix() . 'sales_targets')->result_array();

    foreach ($existing_targets as $target) {
        $CI->db->where('target_id', $target['id']);
        $CI->db->where('kpi_key', 'converted_leads');
        $has_metric = $CI->db->get(db_prefix() . 'sales_target_metrics')->row();

        if (!$has_metric) {
            $CI->db->insert(db_prefix() . 'sales_target_metrics', array(
                'target_id'    => $target['id'],
                'kpi_key'      => 'converted_leads',
                'target_value' => $target['target_value'],
                'unit'         => 'count',
                'weight'       => 100.00,
                'date_created' => $target['date_created'],
            ));
        }

        $CI->db->where('target_id', $target['id']);
        $CI->db->where('staff_id', $target['staff_id']);
        $has_assignee = $CI->db->get(db_prefix() . 'sales_target_assignees')->row();

        if (!$has_assignee) {
            $CI->db->insert(db_prefix() . 'sales_target_assignees', array(
                'target_id'       => $target['id'],
                'staff_id'        => $target['staff_id'],
                'effective_start' => $target['period_start'],
                'effective_end'   => $target['period_end'],
                'date_created'    => $target['date_created'],
            ));
        }
    }

    if ($existing_targets) {
        $CI->db->insert(db_prefix() . 'sales_target_audit_logs', array(
            'target_id'    => null,
            'staff_id'     => null,
            'action'       => 'phase2_backfill',
            'description'  => 'Phase 2 migration: backfilled ' . count($existing_targets) . ' pre-existing target(s) into tblsales_target_metrics / tblsales_target_assignees.',
            'date_created' => date('Y-m-d H:i:s'),
        ));
    }
}

/**
 * Phase 3 — adds the daily-snapshot table the KPI calculation engine writes
 * to (see libraries/Kpi_engine.php). The unique key on
 * (target_id, metric_id, snapshot_date) is what makes the snapshot job
 * idempotent: re-running it for a day that was already snapshotted updates
 * that one row instead of inserting a duplicate.
 */
function sales_targets_migrate_to_v3($CI)
{
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "sales_target_daily_snapshots` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `target_id` int(11) NOT NULL,
        `metric_id` int(11) NOT NULL,
        `staff_id` int(11) NOT NULL,
        `kpi_key` varchar(50) NOT NULL,
        `snapshot_date` date NOT NULL,
        `achieved_value` decimal(15,2) NOT NULL DEFAULT 0.00,
        `record_count` int(11) NOT NULL DEFAULT 0,
        `date_created` datetime NOT NULL,
        `date_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `target_metric_date` (`target_id`, `metric_id`, `snapshot_date`),
        KEY `staff_id` (`staff_id`),
        KEY `kpi_key` (`kpi_key`),
        KEY `snapshot_date` (`snapshot_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * v4 — multi-KPI weighting (spec §3.2) and the target maker-checker
 * lifecycle (spec §3.3).
 *
 * Additive only. Existing rows keep their current status (the status column
 * already defaults to 'Active'), and every new column is nullable or carries a
 * default, so nothing that reads the old shape breaks and no historical
 * achievement figure is disturbed.
 */
function sales_targets_migrate_to_v4($CI)
{
    $metrics = db_prefix() . 'sales_target_metrics';
    $targets = 'tblsales_targets';

    // --- per-KPI thresholds used by the weighting engine ---
    $metricCols = array();
    foreach ($CI->db->list_fields($metrics) as $f) { $metricCols[] = $f; }

    if (!in_array('min_threshold', $metricCols)) {
        $CI->db->query("ALTER TABLE `{$metrics}`
            ADD COLUMN `min_threshold` decimal(15,2) DEFAULT NULL AFTER `weight`,
            ADD COLUMN `stretch_threshold` decimal(15,2) DEFAULT NULL AFTER `min_threshold`,
            ADD COLUMN `accelerator_eligible` tinyint(1) NOT NULL DEFAULT 0 AFTER `stretch_threshold`,
            ADD COLUMN `manual_value` decimal(15,2) DEFAULT NULL AFTER `accelerator_eligible`,
            ADD COLUMN `last_calculated` datetime DEFAULT NULL AFTER `manual_value`");
    }

    // --- lifecycle and versioning on the target itself ---
    $targetCols = array();
    foreach ($CI->db->list_fields($targets) as $f) { $targetCols[] = $f; }

    if (!in_array('version', $targetCols)) {
        $CI->db->query("ALTER TABLE `{$targets}`
            ADD COLUMN `version` int(11) NOT NULL DEFAULT 1,
            ADD COLUMN `supersedes` int(11) DEFAULT NULL,
            ADD COLUMN `submitted_by` int(11) DEFAULT NULL,
            ADD COLUMN `submitted_at` datetime DEFAULT NULL,
            ADD COLUMN `approved_by` int(11) DEFAULT NULL,
            ADD COLUMN `approved_at` datetime DEFAULT NULL,
            ADD COLUMN `locked_at` datetime DEFAULT NULL,
            ADD COLUMN `cap_over_100` tinyint(1) NOT NULL DEFAULT 1,
            ADD COLUMN `reject_reason` varchar(500) DEFAULT NULL");
    }

    // Whether over-achievement counts beyond 100% is a compensation decision,
    // so it is a setting rather than a constant. Default is to cap, because an
    // uncapped weighting lets one runaway KPI mask failure everywhere else.
    if (function_exists('add_option')) {
        add_option('sales_targets_cap_over_100', 1);
    }
}
