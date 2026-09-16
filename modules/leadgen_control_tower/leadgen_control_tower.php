<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Lead Generation - Control Tower
Description: AI Lead Generation Monitoring & No-Lead-Waste Control Tower. Central
  monitoring, SLA, alerting and escalation module for all lead-generation sources.
  Fully separate from the existing read-only "leadgen_monitoring" dashboard module
  (which this module does not touch, modify, or replace).
Version: 1.3.0 (Sub-phase D: Hot Lead Monitor, Duplicate Monitor)
Author: TechPlex Solutions
*/

define('LEADGEN_CONTROL_TOWER_MODULE_NAME', 'leadgen_control_tower');
// Bump this and add a migrate_to_vN() step (see leadgen_control_tower_run_migrations
// below) for every future sub-phase that changes the schema. Never edit an old
// migrate_to_vN() function once it has shipped - add a new one instead, exactly
// the same discipline already used by the sales_targets module.
define('LEADGEN_CONTROL_TOWER_SCHEMA_VERSION', '3');

register_activation_hook(LEADGEN_CONTROL_TOWER_MODULE_NAME, 'leadgen_control_tower_activation_hook');

/**
 * Activation hook - creates the full Sub-phase A table set. All statements are
 * CREATE TABLE IF NOT EXISTS, so re-activation on any environment is safe and
 * idempotent. No existing Perfex core table, and no table belonging to any
 * other module (including the existing leadgen_monitoring module), is touched.
 */
function leadgen_control_tower_activation_hook()
{
    $CI = &get_instance();

    // --- Settings (key/value) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_settings` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `setting_key` varchar(100) NOT NULL,
        `setting_value` text,
        `updated_by` int(11) DEFAULT NULL,
        `date_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Audit logs (every administrative action in this module) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_audit_logs` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `staff_id` int(11) DEFAULT NULL,
        `action` varchar(100) NOT NULL,
        `subject_type` varchar(50) DEFAULT NULL,
        `subject_id` int(11) DEFAULT NULL,
        `before_json` longtext,
        `after_json` longtext,
        `ip_address` varchar(45) DEFAULT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `staff_id` (`staff_id`),
        KEY `subject` (`subject_type`, `subject_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Lead health (1 row per active lead, upserted by the future health cron - Sub-phase B) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_health` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `lead_id` int(11) NOT NULL,
        `health_status` enum('green','amber','red','grey') NOT NULL DEFAULT 'grey',
        `reason_code` varchar(50) DEFAULT NULL,
        `reason_text` varchar(255) DEFAULT NULL,
        `date_calculated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `lead_id` (`lead_id`),
        KEY `health_status` (`health_status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Periodic rollup snapshots (trend charts, Sub-phase B) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_snapshots` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `snapshot_date` date NOT NULL,
        `total_leads` int(11) NOT NULL DEFAULT 0,
        `new_leads` int(11) NOT NULL DEFAULT 0,
        `unassigned` int(11) NOT NULL DEFAULT 0,
        `awaiting_first_response` int(11) NOT NULL DEFAULT 0,
        `sla_breaches` int(11) NOT NULL DEFAULT 0,
        `followups_due` int(11) NOT NULL DEFAULT 0,
        `followups_missed` int(11) NOT NULL DEFAULT 0,
        `stale_leads` int(11) NOT NULL DEFAULT 0,
        `hot_leads` int(11) NOT NULL DEFAULT 0,
        `qualified` int(11) NOT NULL DEFAULT 0,
        `proposals_sent` int(11) NOT NULL DEFAULT 0,
        `converted` int(11) NOT NULL DEFAULT 0,
        `lost` int(11) NOT NULL DEFAULT 0,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `snapshot_date` (`snapshot_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- SLA rules (Sub-phase C) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_sla_rules` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(150) NOT NULL,
        `applies_to_type` varchar(30) DEFAULT NULL,
        `applies_to_id` int(11) DEFAULT NULL,
        `stage` varchar(50) NOT NULL,
        `threshold_minutes` int(11) NOT NULL,
        `business_hours_only` tinyint(1) NOT NULL DEFAULT 0,
        `active` tinyint(1) NOT NULL DEFAULT 1,
        `created_by` int(11) DEFAULT NULL,
        `date_created` datetime NOT NULL,
        `date_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `stage` (`stage`),
        KEY `applies_to` (`applies_to_type`, `applies_to_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- SLA events (immutable audit trail, Sub-phase C) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_sla_events` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `lead_id` int(11) NOT NULL,
        `sla_rule_id` int(11) DEFAULT NULL,
        `stage` varchar(50) NOT NULL,
        `date_started` datetime DEFAULT NULL,
        `date_due` datetime DEFAULT NULL,
        `date_completed` datetime DEFAULT NULL,
        `date_paused` datetime DEFAULT NULL,
        `pause_reason` varchar(255) DEFAULT NULL,
        `breached` tinyint(1) NOT NULL DEFAULT 0,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `lead_id` (`lead_id`),
        KEY `stage` (`stage`),
        KEY `breached` (`breached`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Alert rules (Sub-phase F) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_alert_rules` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `alert_type` varchar(60) NOT NULL,
        `conditions_json` longtext,
        `channels_json` longtext,
        `cooldown_minutes` int(11) NOT NULL DEFAULT 30,
        `active` tinyint(1) NOT NULL DEFAULT 1,
        `date_created` datetime NOT NULL,
        `date_updated` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `alert_type` (`alert_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Alerts (generated instances, Sub-phase F) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_alerts` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `alert_rule_id` int(11) DEFAULT NULL,
        `lead_id` int(11) DEFAULT NULL,
        `staff_id` int(11) DEFAULT NULL,
        `severity` varchar(20) NOT NULL DEFAULT 'medium',
        `message` varchar(500) NOT NULL,
        `status` varchar(20) NOT NULL DEFAULT 'open',
        `escalation_level` int(11) NOT NULL DEFAULT 1,
        `dedupe_key` varchar(191) DEFAULT NULL,
        `acknowledged_by` int(11) DEFAULT NULL,
        `date_acknowledged` datetime DEFAULT NULL,
        `date_resolved` datetime DEFAULT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `status` (`status`),
        KEY `lead_id` (`lead_id`),
        KEY `dedupe_key` (`dedupe_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Escalations (Sub-phase F) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_escalations` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `alert_id` int(11) NOT NULL,
        `level` int(11) NOT NULL,
        `escalated_to_staff_id` int(11) DEFAULT NULL,
        `date_escalated` datetime NOT NULL,
        `date_acknowledged` datetime DEFAULT NULL,
        `notes` varchar(500) DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `alert_id` (`alert_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Source performance daily aggregates (Sub-phase E) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_source_metrics` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `metric_date` date NOT NULL,
        `source_id` int(11) DEFAULT NULL,
        `campaign` varchar(150) DEFAULT NULL,
        `leads_received` int(11) NOT NULL DEFAULT 0,
        `valid_leads` int(11) NOT NULL DEFAULT 0,
        `spam_leads` int(11) NOT NULL DEFAULT 0,
        `duplicate_leads` int(11) NOT NULL DEFAULT 0,
        `assigned_leads` int(11) NOT NULL DEFAULT 0,
        `contacted_leads` int(11) NOT NULL DEFAULT 0,
        `qualified_leads` int(11) NOT NULL DEFAULT 0,
        `demo_leads` int(11) NOT NULL DEFAULT 0,
        `proposal_leads` int(11) NOT NULL DEFAULT 0,
        `converted_leads` int(11) NOT NULL DEFAULT 0,
        `lost_leads` int(11) NOT NULL DEFAULT 0,
        `avg_response_minutes` decimal(10,2) DEFAULT NULL,
        `pipeline_value` decimal(15,2) DEFAULT NULL,
        `revenue` decimal(15,2) DEFAULT NULL,
        `cost` decimal(15,2) DEFAULT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `date_source_campaign` (`metric_date`, `source_id`, `campaign`(100))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Employee performance daily aggregates (Sub-phase E) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_employee_metrics` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `metric_date` date NOT NULL,
        `staff_id` int(11) NOT NULL,
        `assigned` int(11) NOT NULL DEFAULT 0,
        `accepted` int(11) NOT NULL DEFAULT 0,
        `avg_accept_minutes` decimal(10,2) DEFAULT NULL,
        `avg_first_response_minutes` decimal(10,2) DEFAULT NULL,
        `followups_due` int(11) NOT NULL DEFAULT 0,
        `followups_on_time` int(11) NOT NULL DEFAULT 0,
        `followups_missed` int(11) NOT NULL DEFAULT 0,
        `calls_connected` int(11) NOT NULL DEFAULT 0,
        `demos` int(11) NOT NULL DEFAULT 0,
        `proposals` int(11) NOT NULL DEFAULT 0,
        `qualified` int(11) NOT NULL DEFAULT 0,
        `converted` int(11) NOT NULL DEFAULT 0,
        `revenue` decimal(15,2) DEFAULT NULL,
        `lost` int(11) NOT NULL DEFAULT 0,
        `sla_breaches` int(11) NOT NULL DEFAULT 0,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `date_staff` (`metric_date`, `staff_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- Duplicate candidates (Sub-phase D) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_duplicate_candidates` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `lead_id_a` int(11) NOT NULL,
        `lead_id_b` int(11) NOT NULL,
        `match_reason` varchar(255) DEFAULT NULL,
        `confidence_score` decimal(5,2) DEFAULT NULL,
        `status` varchar(20) NOT NULL DEFAULT 'pending',
        `reviewed_by` int(11) DEFAULT NULL,
        `date_reviewed` datetime DEFAULT NULL,
        `master_lead_id` int(11) DEFAULT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `status` (`status`),
        KEY `lead_pair` (`lead_id_a`, `lead_id_b`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- AI insights (STUB structure only - not used until Sonivo is live and
    //     a separate phase is explicitly approved, per standing project rule) ---
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_ai_insights` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `subject_type` varchar(50) DEFAULT NULL,
        `subject_id` int(11) DEFAULT NULL,
        `model` varchar(100) DEFAULT NULL,
        `prompt_version` varchar(30) DEFAULT NULL,
        `input_ref` varchar(255) DEFAULT NULL,
        `output_text` longtext,
        `confidence` decimal(5,2) DEFAULT NULL,
        `explanation` text,
        `date_generated` datetime DEFAULT NULL,
        `approved_by` int(11) DEFAULT NULL,
        `date_approved` datetime DEFAULT NULL,
        `action_taken` varchar(255) DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `subject` (`subject_type`, `subject_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Record schema version so leadgen_control_tower_run_migrations() below
    // knows this is a fresh install already at the latest version.
    $CI->db->where('name', 'leadgen_control_tower_schema_version');
    $existing = $CI->db->get(db_prefix() . 'options')->row();

    if ($existing) {
        $CI->db->where('name', 'leadgen_control_tower_schema_version');
        $CI->db->update(db_prefix() . 'options', array('value' => LEADGEN_CONTROL_TOWER_SCHEMA_VERSION));
    } else {
        $CI->db->insert(db_prefix() . 'options', array(
            'name'     => 'leadgen_control_tower_schema_version',
            'value'    => LEADGEN_CONTROL_TOWER_SCHEMA_VERSION,
            'autoload' => 0,
        ));
    }
}

register_language_files(LEADGEN_CONTROL_TOWER_MODULE_NAME, array(LEADGEN_CONTROL_TOWER_MODULE_NAME));

hooks()->add_action('admin_init', 'leadgen_control_tower_module_init_menu_item');
hooks()->add_action('admin_init', 'leadgen_control_tower_permissions');
hooks()->add_action('admin_init', 'leadgen_control_tower_run_migrations');

// Sub-phase B - daily/periodic health recalculation, same after_cron_run
// hook mechanism already proven by sales_targets_daily_snapshot_cron in
// this project. Runs whenever Perfex's own /cron/index fires (external OS
// cron hits that URL - this module adds no new server-level cron entry).
hooks()->add_action('after_cron_run', 'leadgen_control_tower_health_cron');

// Sub-phase C - SLA breach event recalculation, same mechanism, separate
// try/catch so a fault in one job can never stop the other from running.
hooks()->add_action('after_cron_run', 'leadgen_control_tower_sla_cron');

// Sub-phase D - duplicate-candidate detection, same mechanism, own
// try/catch again. Added and verified against a live /cron/index call on
// its own before being deployed alongside anything else, per the standing
// discipline (design doc Section 12) established after /cron/index was
// part of the earlier site-wide incident.
hooks()->add_action('after_cron_run', 'leadgen_control_tower_duplicate_cron');

/**
 * Sidebar menu - single top-level item for Sub-phase A (Live Dashboard is a
 * placeholder page until Sub-phase B). Children items (SLA Monitor, Stale
 * Leads, Alert Centre, etc.) are added one at a time as each sub-phase's
 * screens are actually built, via add_sidebar_children_item - not before,
 * so the menu never links to a page that doesn't exist yet.
 */
function leadgen_control_tower_module_init_menu_item()
{
    $CI = &get_instance();

    if (!staff_can('view_dashboard', 'leadgen_control_tower')) {
        return;
    }

    $CI->app_menu->add_sidebar_menu_item('leadgen_control_tower', array(
        'name'     => _l('leadgen_control_tower_title'),
        'icon'     => 'fa fa-tachometer',
        'href'     => admin_url('leadgen_control_tower'),
        'position' => 47,
    ));

    $CI->app_menu->add_sidebar_children_item('leadgen_control_tower', array(
        'slug'     => 'leadgen_control_tower_lead_health',
        'name'     => _l('leadgen_control_tower_lead_health'),
        'href'     => admin_url('leadgen_control_tower/lead_health'),
        'position' => 1,
    ));

    $CI->app_menu->add_sidebar_children_item('leadgen_control_tower', array(
        'slug'     => 'leadgen_control_tower_unassigned_leads',
        'name'     => _l('leadgen_control_tower_unassigned_leads'),
        'href'     => admin_url('leadgen_control_tower/unassigned_leads'),
        'position' => 2,
    ));

    $CI->app_menu->add_sidebar_children_item('leadgen_control_tower', array(
        'slug'     => 'leadgen_control_tower_missed_followups',
        'name'     => _l('leadgen_control_tower_missed_followups'),
        'href'     => admin_url('leadgen_control_tower/missed_followups'),
        'position' => 3,
    ));

    $CI->app_menu->add_sidebar_children_item('leadgen_control_tower', array(
        'slug'     => 'leadgen_control_tower_stale_leads',
        'name'     => _l('leadgen_control_tower_stale_leads'),
        'href'     => admin_url('leadgen_control_tower/stale_leads'),
        'position' => 4,
    ));

    $CI->app_menu->add_sidebar_children_item('leadgen_control_tower', array(
        'slug'     => 'leadgen_control_tower_sla_monitor',
        'name'     => _l('leadgen_control_tower_sla_monitor'),
        'href'     => admin_url('leadgen_control_tower/sla_monitor'),
        'position' => 5,
    ));

    // Sub-phase D - Hot Lead Monitor, Duplicate Monitor. Inserted here
    // (positions 6-7) rather than appended at the end, so the sidebar keeps
    // reading as "the live lists" (Unassigned/Missed-Followup/Stale/SLA/Hot/
    // Duplicate) grouped together, ahead of the configuration/admin screens
    // (SLA Rules, Settings, Audit Logs) - those three are renumbered 8-10
    // below, a UI-only change with no effect on their URLs or permissions.
    $CI->app_menu->add_sidebar_children_item('leadgen_control_tower', array(
        'slug'     => 'leadgen_control_tower_hot_leads',
        'name'     => _l('leadgen_control_tower_hot_leads'),
        'href'     => admin_url('leadgen_control_tower/hot_leads'),
        'position' => 6,
    ));

    $CI->app_menu->add_sidebar_children_item('leadgen_control_tower', array(
        'slug'     => 'leadgen_control_tower_duplicate_monitor',
        'name'     => _l('leadgen_control_tower_duplicate_monitor'),
        'href'     => admin_url('leadgen_control_tower/duplicate_monitor'),
        'position' => 7,
    ));

    if (staff_can('manage_sla', 'leadgen_control_tower')) {
        $CI->app_menu->add_sidebar_children_item('leadgen_control_tower', array(
            'slug'     => 'leadgen_control_tower_sla_rules',
            'name'     => _l('leadgen_control_tower_sla_rules'),
            'href'     => admin_url('leadgen_control_tower/sla_rules'),
            'position' => 8,
        ));
    }

    $CI->app_menu->add_sidebar_children_item('leadgen_control_tower', array(
        'slug'     => 'leadgen_control_tower_settings',
        'name'     => _l('leadgen_control_tower_settings'),
        'href'     => admin_url('leadgen_control_tower/settings'),
        'position' => 9,
    ));

    if (staff_can('view_audit_logs', 'leadgen_control_tower')) {
        $CI->app_menu->add_sidebar_children_item('leadgen_control_tower', array(
            'slug'     => 'leadgen_control_tower_audit_logs',
            'name'     => _l('leadgen_control_tower_audit_logs'),
            'href'     => admin_url('leadgen_control_tower/audit_logs'),
            'position' => 10,
        ));
    }
}

/**
 * Sub-phase B - health recalculation cron. Idempotent (upsert-only, see the
 * helper), no lock table needed since a re-run just refreshes the same rows
 * with current values. Any error is caught and logged to this module's own
 * audit log rather than allowed to break /cron/index for other modules -
 * that route was part of the earlier site-wide incident, so every job added
 * to it here is defensive on purpose.
 */
function leadgen_control_tower_health_cron()
{
    $CI = &get_instance();
    $CI->load->helper('leadgen_control_tower');

    try {
        leadgen_control_tower_recalculate_lead_health();
    } catch (Exception $e) {
        leadgen_control_tower_log_action('health_cron_error', 'health', null, null, array('error' => $e->getMessage()));
    }
}

/**
 * Sub-phase C - SLA breach event recalculation. Separate try/catch from the
 * health cron above on purpose - one job failing must never stop the other.
 */
function leadgen_control_tower_sla_cron()
{
    $CI = &get_instance();
    $CI->load->helper('leadgen_control_tower');

    try {
        leadgen_control_tower_recalculate_sla_events();
    } catch (Exception $e) {
        leadgen_control_tower_log_action('sla_cron_error', 'sla_events', null, null, array('error' => $e->getMessage()));
    }
}

/**
 * Sub-phase D - duplicate-candidate detection. Separate try/catch again,
 * same reasoning as the two crons above.
 */
function leadgen_control_tower_duplicate_cron()
{
    $CI = &get_instance();
    $CI->load->helper('leadgen_control_tower');

    try {
        leadgen_control_tower_detect_duplicate_candidates();
    } catch (Exception $e) {
        leadgen_control_tower_log_action('duplicate_cron_error', 'duplicate_candidates', null, null, array('error' => $e->getMessage()));
    }
}

/**
 * Granular Perfex capabilities, same register_staff_capabilities() pattern
 * already proven across leadgen_facebook / leadgen_followup / sales_targets
 * on this install. Every capability listed here exists now so RBAC is ready
 * up front, even though most of the screens they gate (SLA, alerts, hot
 * leads, etc.) are only built in later sub-phases - staff_can() checks on
 * not-yet-built pages simply have no page to protect yet.
 */
function leadgen_control_tower_permissions()
{
    $capabilities = array();

    $capabilities['capabilities'] = array(
        'view_dashboard'            => _l('leadgen_control_tower_perm_view_dashboard'),
        'view_all_leads'            => _l('leadgen_control_tower_perm_view_all_leads'),
        'view_team_leads'           => _l('leadgen_control_tower_perm_view_team_leads'),
        'view_own_leads'            => _l('leadgen_control_tower_perm_view_own_leads'),
        'view_employee_performance' => _l('leadgen_control_tower_perm_view_employee_performance'),
        'view_source_costs'         => _l('leadgen_control_tower_perm_view_source_costs'),
        'view_revenue'              => _l('leadgen_control_tower_perm_view_revenue'),
        'manage_sla'                => _l('leadgen_control_tower_perm_manage_sla'),
        'manage_alerts'             => _l('leadgen_control_tower_perm_manage_alerts'),
        'assign_leads'              => _l('leadgen_control_tower_perm_assign_leads'),
        'merge_duplicates'          => _l('leadgen_control_tower_perm_merge_duplicates'),
        'export_reports'            => _l('leadgen_control_tower_perm_export_reports'),
        'view_ai_insights'          => _l('leadgen_control_tower_perm_view_ai_insights'),
        'approve_ai_actions'        => _l('leadgen_control_tower_perm_approve_ai_actions'),
        'view_audit_logs'           => _l('leadgen_control_tower_perm_view_audit_logs'),
        'manage_settings'           => _l('leadgen_control_tower_perm_manage_settings'),
    );

    register_staff_capabilities('leadgen_control_tower', $capabilities, _l('leadgen_control_tower_title'));
}

/**
 * Idempotent schema upgrade path for future sub-phases (B-G). Sub-phase A
 * ships everything at LEADGEN_CONTROL_TOWER_SCHEMA_VERSION = 1, so this is
 * currently a no-op on every request after the first - it only starts doing
 * real work once a future sub-phase bumps the version and adds a
 * leadgen_control_tower_migrate_to_vN() function below.
 */
function leadgen_control_tower_run_migrations()
{
    $CI = &get_instance();

    $CI->db->where('name', 'leadgen_control_tower_schema_version');
    $current = $CI->db->get(db_prefix() . 'options')->row();
    $current_version = (int) ($current ? $current->value : 0);
    $target_version  = (int) LEADGEN_CONTROL_TOWER_SCHEMA_VERSION;

    if ($current_version >= $target_version) {
        return;
    }

    // Future sub-phases add "if ($current_version < N) { leadgen_control_tower_migrate_to_vN($CI); }"
    // blocks here, one per version - never edit a shipped block, only append.

    if ($current_version < 2) {
        leadgen_control_tower_migrate_to_v2($CI);
    }

    if ($current_version < 3) {
        leadgen_control_tower_migrate_to_v3($CI);
    }

    if ($current) {
        $CI->db->where('name', 'leadgen_control_tower_schema_version');
        $CI->db->update(db_prefix() . 'options', array('value' => $target_version));
    } else {
        $CI->db->insert(db_prefix() . 'options', array(
            'name'     => 'leadgen_control_tower_schema_version',
            'value'    => $target_version,
            'autoload' => 0,
        ));
    }
}

/**
 * Sub-phase C - seeds default SLA rules the first time this version runs.
 * No schema change (sla_rules already existed, empty, since Sub-phase A) -
 * this only inserts starter data, and only if the table is still empty, so
 * re-running this function (or re-activating the module) never duplicates
 * or overwrites rules an admin has since edited.
 *
 * Defaults are adapted from the design doc's Section 7 SLA defaults table.
 * The hot-lead-specific rules are only created if a lead status literally
 * named "Hot" exists on this install (matched by name at migration time,
 * a documented simplification - see the Sub-phase C changelog); if none is
 * found, only the global (status-independent) rules are seeded. Every
 * threshold here is a normal row in leadgen_control_tower_sla_rules and is
 * editable/removable from the SLA Rules screen - nothing here is
 * hardcoded into the application logic.
 */
function leadgen_control_tower_migrate_to_v2($CI)
{
    $existing_count = $CI->db->count_all(db_prefix() . 'leadgen_control_tower_sla_rules');
    if ($existing_count > 0) {
        return;
    }

    $hot_status = $CI->db->query(
        'SELECT id FROM ' . db_prefix() . "leads_status WHERE name = 'Hot' LIMIT 1"
    )->row();

    $now = date('Y-m-d H:i:s');
    $rules = array();

    if ($hot_status) {
        $rules[] = array(
            'name' => 'Hot Lead - Assignment SLA', 'applies_to_type' => 'status', 'applies_to_id' => $hot_status->id,
            'stage' => 'assignment', 'threshold_minutes' => 2, 'business_hours_only' => 0,
        );
        $rules[] = array(
            'name' => 'Hot Lead - First Response SLA', 'applies_to_type' => 'status', 'applies_to_id' => $hot_status->id,
            'stage' => 'first_response', 'threshold_minutes' => 5, 'business_hours_only' => 0,
        );
    }

    $rules[] = array(
        'name' => 'Standard Lead - Assignment SLA', 'applies_to_type' => null, 'applies_to_id' => null,
        'stage' => 'assignment', 'threshold_minutes' => 30, 'business_hours_only' => 1,
    );
    $rules[] = array(
        'name' => 'Standard Lead - First Response SLA', 'applies_to_type' => null, 'applies_to_id' => null,
        'stage' => 'first_response', 'threshold_minutes' => 15, 'business_hours_only' => 1,
    );
    $rules[] = array(
        'name' => 'Missed Follow-Up Alert', 'applies_to_type' => null, 'applies_to_id' => null,
        'stage' => 'followup', 'threshold_minutes' => 1440, 'business_hours_only' => 0,
    );
    $rules[] = array(
        'name' => 'Stale Lead Alert', 'applies_to_type' => null, 'applies_to_id' => null,
        'stage' => 'stale', 'threshold_minutes' => 4320, 'business_hours_only' => 0,
    );

    foreach ($rules as $rule) {
        $rule['active'] = 1;
        $rule['created_by'] = null;
        $rule['date_created'] = $now;
        $CI->db->insert(db_prefix() . 'leadgen_control_tower_sla_rules', $rule);
    }
}

/**
 * Sub-phase D - no schema change (leadgen_control_tower_duplicate_candidates
 * was already drafted into the full Sub-phase A schema and created on
 * activation - see Section 4 of the design doc), but this re-issues the same
 * CREATE TABLE IF NOT EXISTS here too, defensively. Running this is always
 * safe whether or not the table already exists, and it removes any
 * dependency on exactly what ran during this installs original activation -
 * the same "verify against the live schema, do not assume" discipline used
 * throughout this module, applied by simply making the assumption
 * unnecessary rather than trusting it.
 */
function leadgen_control_tower_migrate_to_v3($CI)
{
    $CI->db->query("CREATE TABLE IF NOT EXISTS `" . db_prefix() . "leadgen_control_tower_duplicate_candidates` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `lead_id_a` int(11) NOT NULL,
        `lead_id_b` int(11) NOT NULL,
        `match_reason` varchar(255) DEFAULT NULL,
        `confidence_score` decimal(5,2) DEFAULT NULL,
        `status` varchar(20) NOT NULL DEFAULT 'pending',
        `reviewed_by` int(11) DEFAULT NULL,
        `date_reviewed` datetime DEFAULT NULL,
        `master_lead_id` int(11) DEFAULT NULL,
        `date_created` datetime NOT NULL,
        PRIMARY KEY (`id`),
        KEY `status` (`status`),
        KEY `lead_pair` (`lead_id_a`, `lead_id_b`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
