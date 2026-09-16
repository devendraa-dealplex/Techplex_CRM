<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payplex Staff System — idempotent installer.
 * Every statement is CREATE TABLE IF NOT EXISTS so activation, migration and
 * upgrade never destroy data. Perfex core tables are never touched.
 */

$CI = &get_instance();
$charset = 'DEFAULT CHARSET=' . $CI->db->char_set;
$prefix  = db_prefix();

// ---- Staff profiles (EFFECTIVE-DATED: type changes create a new version) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_staff_profiles')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_staff_profiles` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `staff_id` INT(11) NOT NULL,
        `version` INT(11) NOT NULL DEFAULT 1,
        `is_current` TINYINT(1) NOT NULL DEFAULT 1,
        `effective_from` DATE NULL,
        `full_name` VARCHAR(191) NULL,
        `employee_code` VARCHAR(60) NULL,
        `official_email` VARCHAR(191) NULL,
        `official_mobile` VARCHAR(40) NULL,
        `department` VARCHAR(100) NULL,
        `designation` VARCHAR(100) NULL,
        `reporting_manager_id` INT(11) NULL,
        `branch` VARCHAR(100) NULL,
        `territory` VARCHAR(100) NULL,
        `employment_type` VARCHAR(40) NOT NULL DEFAULT "custom",
        `joining_date` DATE NULL,
        `probation_end_date` DATE NULL,
        `salary_eligibility` TINYINT(1) NOT NULL DEFAULT 0,
        `commission_eligibility` TINYINT(1) NOT NULL DEFAULT 0,
        `expense_eligibility` TINYINT(1) NOT NULL DEFAULT 0,
        `tada_eligibility` TINYINT(1) NOT NULL DEFAULT 0,
        `attendance_required` TINYINT(1) NOT NULL DEFAULT 0,
        `target_plan` VARCHAR(100) NULL,
        `commission_plan` VARCHAR(100) NULL,
        `payout_frequency` VARCHAR(40) NOT NULL DEFAULT "monthly",
        `bank_verified` TINYINT(1) NOT NULL DEFAULT 0,
        `bank_enc` MEDIUMTEXT NULL,
        `pan_status` VARCHAR(30) NOT NULL DEFAULT "pending",
        `kyc_status` VARCHAR(30) NOT NULL DEFAULT "pending",
        `emergency_contact` VARCHAR(191) NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT "draft",
        `classification_required` TINYINT(1) NOT NULL DEFAULT 0,
        `suspension_date` DATE NULL,
        `exit_date` DATE NULL,
        `exit_reason` VARCHAR(255) NULL,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `submitted_by` INT(11) NULL,
        `verified_by` INT(11) NULL,
        `approved_by` INT(11) NULL,
        `datecreated` DATETIME NULL,
        `dateupdated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `staff_id` (`staff_id`),
        KEY `is_current` (`is_current`),
        KEY `status` (`status`),
        KEY `classification_required` (`classification_required`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Admin-defined custom employment types ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_staff_types')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_staff_types` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `slug` VARCHAR(60) NOT NULL,
        `label` VARCHAR(120) NOT NULL,
        `salary_eligibility` TINYINT(1) NOT NULL DEFAULT 0,
        `commission_eligibility` TINYINT(1) NOT NULL DEFAULT 0,
        `expense_eligibility` TINYINT(1) NOT NULL DEFAULT 0,
        `tada_eligibility` TINYINT(1) NOT NULL DEFAULT 0,
        `attendance_required` TINYINT(1) NOT NULL DEFAULT 0,
        `field_tracking` TINYINT(1) NOT NULL DEFAULT 0,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `slug` (`slug`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Admin-defined permission templates (no code changes to add) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_staff_perm_templates')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_staff_perm_templates` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL,
        `base_role` VARCHAR(40) NULL,
        `allowed_json` MEDIUMTEXT NULL,
        `prohibited_json` MEDIUMTEXT NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `datecreated` DATETIME NULL,
        `dateupdated` DATETIME NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Immutable staff audit log ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_staff_audit')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_staff_audit` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `staff_id` INT(11) NULL,
        `event_type` VARCHAR(60) NOT NULL,
        `actor_id` INT(11) NOT NULL DEFAULT 0,
        `message` VARCHAR(500) NULL,
        `data_json` MEDIUMTEXT NULL,
        `ip` VARCHAR(60) NULL,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `staff_id` (`staff_id`),
        KEY `event_type` (`event_type`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Module settings (schema_version, backfilled flag, config) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_staff_settings')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_staff_settings` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL,
        `value` MEDIUMTEXT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `name` (`name`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

/* ===================== Batch 2: Activity tracking + Performance/KPI ===================== */

// ---- Employee activity (legitimate business events) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_staff_activity')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_staff_activity` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `staff_id` INT(11) NOT NULL,
        `event_type` VARCHAR(40) NOT NULL,
        `category` VARCHAR(20) NOT NULL DEFAULT "other",
        `ref_type` VARCHAR(40) NULL,
        `ref_id` INT(11) NULL,
        `verified` TINYINT(1) NOT NULL DEFAULT 0,
        `excluded` TINYINT(1) NOT NULL DEFAULT 0,
        `quality` DECIMAL(4,2) NULL,
        `value` DECIMAL(14,2) NULL,
        `meta_json` MEDIUMTEXT NULL,
        `occurred_at` DATETIME NULL,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `staff_id` (`staff_id`),
        KEY `event_type` (`event_type`),
        KEY `occurred_at` (`occurred_at`),
        KEY `verified` (`verified`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Admin-configurable KPI definitions (weights/caps/targets/penalties, effective-dated) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_staff_kpi_defs')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_staff_kpi_defs` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `role` VARCHAR(40) NULL,
        `metric` VARCHAR(60) NOT NULL,
        `weight` DECIMAL(5,3) NOT NULL DEFAULT 1.000,
        `cap` DECIMAL(14,2) NULL,
        `target` DECIMAL(14,2) NULL,
        `penalty` DECIMAL(8,2) NOT NULL DEFAULT 0,
        `effective_from` DATE NULL,
        `effective_to` DATE NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `role` (`role`),
        KEY `metric` (`metric`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Computed performance snapshots (per staff, per period) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_staff_performance')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_staff_performance` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `staff_id` INT(11) NOT NULL,
        `period` VARCHAR(10) NOT NULL DEFAULT "monthly",
        `period_key` VARCHAR(20) NOT NULL,
        `score` DECIMAL(6,2) NOT NULL DEFAULT 0,
        `rating` VARCHAR(30) NOT NULL DEFAULT "no_data",
        `components_json` MEDIUMTEXT NULL,
        `computed_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `staff_period` (`staff_id`, `period`, `period_key`),
        KEY `staff_id` (`staff_id`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

/* ===================== Batch 3: Field / GPS tracking with privacy & consent ===================== */

/* Policy (approved by Chairman, 2026-09-10):
 *   - Consent model  : EXPLICIT OPT-IN, revocable at any time by the staff member.
 *   - Capture window : ONLY between an explicit field check-in and check-out.
 *   - Retention      : raw GPS points purged after 90 days; session aggregates kept.
 * The consent table is APPEND-ONLY: a withdrawal is a new row, never an update or
 * delete, so the consent history is legally reconstructible for any point in time.
 */

// ---- Consent ledger (append-only; never updated, never deleted) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_staff_consent')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_staff_consent` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `staff_id` INT(11) NOT NULL,
        `purpose` VARCHAR(60) NOT NULL DEFAULT "location_tracking",
        `action` VARCHAR(20) NOT NULL,
        `policy_version` VARCHAR(20) NOT NULL DEFAULT "1.0",
        `notice_text_sha1` VARCHAR(40) NULL,
        `source` VARCHAR(30) NOT NULL DEFAULT "web",
        `ip` VARCHAR(45) NULL,
        `user_agent` VARCHAR(255) NULL,
        `actor_id` INT(11) NOT NULL DEFAULT 0,
        `reason` VARCHAR(255) NULL,
        `occurred_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `staff_purpose` (`staff_id`, `purpose`),
        KEY `occurred_at` (`occurred_at`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Field visit sessions (the only window in which location may be captured) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_staff_field_sessions')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_staff_field_sessions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `staff_id` INT(11) NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT "open",
        `purpose` VARCHAR(60) NULL,
        `ref_type` VARCHAR(40) NULL,
        `ref_id` INT(11) NULL,
        `consent_id` INT(11) NOT NULL DEFAULT 0,
        `started_at` DATETIME NULL,
        `ended_at` DATETIME NULL,
        `start_lat` DECIMAL(10,7) NULL,
        `start_lng` DECIMAL(10,7) NULL,
        `end_lat` DECIMAL(10,7) NULL,
        `end_lng` DECIMAL(10,7) NULL,
        `point_count` INT(11) NOT NULL DEFAULT 0,
        `distance_m` INT(11) NOT NULL DEFAULT 0,
        `duration_s` INT(11) NOT NULL DEFAULT 0,
        `points_purged` TINYINT(1) NOT NULL DEFAULT 0,
        `purged_at` DATETIME NULL,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `staff_id` (`staff_id`),
        KEY `status` (`status`),
        KEY `started_at` (`started_at`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Raw GPS points (retention-limited: purged after 90 days) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_staff_locations')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_staff_locations` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `session_id` INT(11) NOT NULL,
        `staff_id` INT(11) NOT NULL,
        `lat` DECIMAL(10,7) NOT NULL,
        `lng` DECIMAL(10,7) NOT NULL,
        `accuracy_m` INT(11) NULL,
        `source` VARCHAR(20) NOT NULL DEFAULT "app",
        `captured_at` DATETIME NULL,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `session_id` (`session_id`),
        KEY `staff_id` (`staff_id`),
        KEY `captured_at` (`captured_at`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Privacy operations log (purges + subject access; immutable) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_staff_privacy_log')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_staff_privacy_log` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `event_type` VARCHAR(40) NOT NULL,
        `staff_id` INT(11) NULL,
        `actor_id` INT(11) NOT NULL DEFAULT 0,
        `affected_rows` INT(11) NOT NULL DEFAULT 0,
        `cutoff_date` DATE NULL,
        `message` VARCHAR(500) NULL,
        `data_json` MEDIUMTEXT NULL,
        `occurred_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `event_type` (`event_type`),
        KEY `occurred_at` (`occurred_at`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

/* ---------------------------------------------------------------------------
 * Schema 4 — provenance for a frozen distance.
 *
 * CREATE TABLE IF NOT EXISTS cannot add a column to a table that already
 * exists, and every install that has ever opened a field session already has
 * this table. So these are additive ALTERs, each guarded by a field_exists
 * check so the file stays runnable any number of times.
 *
 * Nothing is dropped, nothing is rewritten, and engine_version defaults to 0 —
 * which is the truth about every row written before today: computed by rules
 * the row does not identify.
 * ------------------------------------------------------------------------- */
$pp_fs = db_prefix() . 'payplex_staff_field_sessions';
if ($CI->db->table_exists($pp_fs)) {
    $pp_add = array(
        'legs_counted'        => 'INT(11) NOT NULL DEFAULT 0',
        'skipped_jitter'      => 'INT(11) NOT NULL DEFAULT 0',
        'skipped_implausible' => 'INT(11) NOT NULL DEFAULT 0',
        'engine_version'      => 'INT(11) NOT NULL DEFAULT 0',
    );
    foreach ($pp_add as $pp_col => $pp_def) {
        if (!$CI->db->field_exists($pp_col, $pp_fs)) {
            $CI->db->query('ALTER TABLE `' . $pp_fs . '` ADD COLUMN `' . $pp_col . '` ' . $pp_def);
        }
    }
}
