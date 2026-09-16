<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');
/**
 * Lead Finder migration 101 — Phase 1 schema.
 *
 * NUMBERING
 * ---------
 * 101, not 019. The workforce sequence occupies 001–018 with 019–020 reserved,
 * and 013–018 are unapplied. A Lead Finder migration numbered into that range
 * would look like part of a batch it must never be applied with. This module
 * owns 1xx and its own directory.
 *
 * NOT SELF-APPLYING — AND WHY THE MODULE WILL NOT RUN WITHOUT IT
 * --------------------------------------------------------------
 * Every other module in this CRM installs its own tables on activation via an
 * option-guarded `schema_version`. This one deliberately does not. The standing
 * instruction is that migrations run as versioned files applied by an
 * authorised operator, and that is the instruction rather than the house habit.
 *
 * The consequence is stated plainly rather than discovered: **until a DBA
 * applies this, the module cannot run at all.** No search, no queue, no
 * end-to-end evidence. That is the cost of the review step, and it was the
 * accepted trade.
 *
 * SHAPE
 * -----
 * `up` and `down` are lists of plain SQL. No ORM, no runner-specific classes:
 * an operator can read every statement and execute it directly. `{P}` is the
 * installation's table prefix (`tbl` on this build) and is substituted by the
 * caller — hard-coding it would break any install that changed it.
 *
 * ADDITIVE ONLY
 * -------------
 * Nothing here alters, drops or truncates an existing table. `tblleads` is not
 * touched. The module reads core leads for duplicate detection and writes to
 * them only at conversion, through the CRM's own insert path.
 */
return array(
    'id'          => 101,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Lead Finder Phase 1 schema',
    'destructive' => false,
    'touches_core_tables' => false,

    'up' => array(

        /* ---------------------------------------------------------------
         * §2 — API connection profiles.
         * `api_key_enc` holds ciphertext only. There is deliberately no
         * `api_key` column: a nullable plaintext column is an invitation, and
         * one day something writes to it.
         * ------------------------------------------------------------- */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_api_profiles` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(160) NOT NULL,
            `gcp_project` VARCHAR(160) NULL,
            `api_key_enc` TEXT NULL,
            `api_key_fingerprint` VARCHAR(24) NULL,
            `billing_label` VARCHAR(160) NULL,
            `monthly_search_limit` INT UNSIGNED NOT NULL DEFAULT 0,
            `monthly_detail_limit` INT UNSIGNED NOT NULL DEFAULT 0,
            `daily_usage_limit` INT UNSIGNED NOT NULL DEFAULT 0,
            `active` TINYINT(1) NOT NULL DEFAULT 0,
            `last_test_at` INT UNSIGNED NULL,
            `last_test_result` VARCHAR(40) NULL,
            `expires_on` DATE NULL,
            `admin_notes` TEXT NULL,
            `created_by` INT NOT NULL,
            `created_at` INT UNSIGNED NOT NULL,
            `updated_at` INT UNSIGNED NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `lf_profile_name` (`name`),
            KEY `lf_profile_active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /* Which employees may spend which profile. A profile may serve many
           employees; an employee may have a dedicated one. */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_profile_staff` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `profile_id` INT UNSIGNED NOT NULL,
            `staff_id` INT NOT NULL,
            `assigned_by` INT NOT NULL,
            `assigned_at` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `lf_profile_staff` (`profile_id`,`staff_id`),
            KEY `lf_staff` (`staff_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /* ---------------------------------------------------------------
         * §5/§6 — the Prospect Verification Queue.
         *
         * `google_place_id` is UNIQUE. It is the one durable identifier Google
         * gives us and §10 makes it duplicate key number one, so the database
         * enforces it rather than trusting every insert path to remember.
         *
         * `phone_e164` is separate from `phone_raw`: the raw value is what the
         * API returned and what an employee reads; the E.164 form is the
         * duplicate key. Comparing raw values is the defect this module exists
         * not to repeat.
         *
         * Google-provided and staff-verified fields are kept in separate
         * columns, per §18 — `verified_*` is ours and may be retained; the
         * Google columns are subject to the retention rules.
         * ------------------------------------------------------------- */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_prospects` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `google_place_id` VARCHAR(255) NOT NULL,
            `business_name` VARCHAR(255) NOT NULL,
            `category` VARCHAR(160) NULL,
            `address` VARCHAR(500) NULL,
            `city` VARCHAR(120) NULL,
            `state` VARCHAR(120) NULL,
            `pin_code` VARCHAR(20) NULL,
            `latitude` DECIMAL(10,7) NULL,
            `longitude` DECIMAL(10,7) NULL,
            `google_maps_uri` VARCHAR(500) NULL,
            `business_status` VARCHAR(40) NULL,
            `phone_raw` VARCHAR(64) NULL,
            `phone_e164` VARCHAR(24) NULL,
            `phone_weak_key` VARCHAR(24) NULL,
            `website` VARCHAR(500) NULL,
            `website_domain` VARCHAR(255) NULL,
            `verified_email` VARCHAR(190) NULL,
            `verified_contact_name` VARCHAR(160) NULL,
            `verified_designation` VARCHAR(160) NULL,
            `status` VARCHAR(40) NOT NULL,
            `assigned_staff` INT NOT NULL DEFAULT 0,
            `claimed_by` INT NOT NULL DEFAULT 0,
            `claimed_at` INT UNSIGNED NULL,
            `last_touch_at` INT UNSIGNED NULL,
            `search_id` INT UNSIGNED NULL,
            `search_keyword` VARCHAR(255) NULL,
            `search_location` VARCHAR(255) NULL,
            `profile_id` INT UNSIGNED NULL,
            `call_disposition` VARCHAR(40) NULL,
            `interest_level` VARCHAR(20) NULL,
            `verification_notes` TEXT NULL,
            `rejection_reason` VARCHAR(255) NULL,
            `next_followup_at` INT UNSIGNED NULL,
            `details_fetched_at` INT UNSIGNED NULL,
            `google_fetched_at` INT UNSIGNED NULL,
            `coords_purged_at` INT UNSIGNED NULL,
            `converted_lead_id` INT UNSIGNED NULL,
            `dupe_state` VARCHAR(24) NULL,
            `created_at` INT UNSIGNED NOT NULL,
            `verified_at` INT UNSIGNED NULL,
            `purge_after` INT UNSIGNED NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `lf_place` (`google_place_id`),
            KEY `lf_status` (`status`),
            KEY `lf_assigned` (`assigned_staff`),
            KEY `lf_phone` (`phone_e164`),
            KEY `lf_weak_phone` (`phone_weak_key`),
            KEY `lf_domain` (`website_domain`),
            KEY `lf_followup` (`next_followup_at`),
            KEY `lf_purge` (`purge_after`),
            /* The Service Specific Terms §14.3 sweep runs off this: find every
               row whose coordinates are past 30 calendar days and not yet
               cleared. Without the index it is a full scan of the queue on
               every cron tick. */
            KEY `lf_coord_expiry` (`google_fetched_at`,`coords_purged_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /* §7 — complete ownership history. Append-only: no UPDATE path exists
           in the model, and there is no unique key that would tempt one. */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_ownership` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `prospect_id` INT UNSIGNED NOT NULL,
            `event` VARCHAR(40) NOT NULL,
            `from_staff` INT NOT NULL DEFAULT 0,
            `to_staff` INT NOT NULL DEFAULT 0,
            `actor_id` INT NOT NULL,
            `reason` VARCHAR(255) NULL,
            `at` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            KEY `lf_own_prospect` (`prospect_id`,`at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /* §3 — the search itself, kept so a result can be traced to the query,
           the employee and the profile that paid for it. */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_searches` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `staff_id` INT NOT NULL,
            `profile_id` INT UNSIGNED NOT NULL,
            `keyword` VARCHAR(255) NULL,
            `category` VARCHAR(160) NULL,
            `city` VARCHAR(120) NULL,
            `state` VARCHAR(120) NULL,
            `pin_code` VARCHAR(20) NULL,
            `radius_m` INT UNSIGNED NULL,
            `max_results` SMALLINT UNSIGNED NULL,
            `product` VARCHAR(160) NULL,
            `campaign` VARCHAR(160) NULL,
            `language` VARCHAR(16) NULL,
            `query_hash` CHAR(40) NOT NULL,
            `endpoint` VARCHAR(40) NOT NULL,
            `request_class` VARCHAR(16) NOT NULL,
            `result_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `served_from_cache` TINYINT(1) NOT NULL DEFAULT 0,
            `error_code` VARCHAR(60) NULL,
            `created_at` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            KEY `lf_search_staff` (`staff_id`,`created_at`),
            KEY `lf_search_profile` (`profile_id`,`created_at`),
            KEY `lf_search_cache` (`query_hash`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /* §13 — one row per profile per day per request class. The hard stop
           reads this; the dashboards in Phase 2 read the same rows. */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_usage` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `profile_id` INT UNSIGNED NOT NULL,
            `staff_id` INT NOT NULL DEFAULT 0,
            `usage_date` DATE NOT NULL,
            `request_class` VARCHAR(16) NOT NULL,
            `calls` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `lf_usage_slot` (`profile_id`,`staff_id`,`usage_date`,`request_class`),
            KEY `lf_usage_date` (`usage_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /* §2/§12 — configuration changes and every security-relevant event.
           `detail` never receives a key: Leadfinder_secret::scrub() runs on the
           way in and a test asserts it. */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_audit` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `actor_id` INT NOT NULL,
            `event` VARCHAR(60) NOT NULL,
            `object_type` VARCHAR(40) NULL,
            `object_id` INT UNSIGNED NULL,
            `detail` TEXT NULL,
            `ip` VARCHAR(45) NULL,
            `at` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            KEY `lf_audit_actor` (`actor_id`,`at`),
            KEY `lf_audit_event` (`event`,`at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /* Module configuration. A table rather than Perfex options because
           several values are structured (the phone profile) and every one of
           them must be effective-dated and attributable. */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_config` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ckey` VARCHAR(80) NOT NULL,
            `cvalue` TEXT NULL,
            `source_note` VARCHAR(255) NULL,
            `effective_from` INT UNSIGNED NOT NULL,
            `set_by` INT NOT NULL,
            `set_at` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id`),
            KEY `lf_cfg_key` (`ckey`,`effective_from`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ),

    /*
     * Rollback. DROP is correct here and only here: these tables are created by
     * this migration and hold nothing that predates it. Note the order —
     * children before parents — and note that no core table appears, so a
     * rollback cannot touch a lead.
     *
     * An operator rolling back AFTER prospects have been worked will lose that
     * work. The runbook says to export the three data tables first; this file
     * cannot do it for them, so it says so rather than implying the rollback is
     * free.
     */
    'down' => array(
        "DROP TABLE IF EXISTS `{P}payplex_lf_profile_staff`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_ownership`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_usage`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_searches`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_audit`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_config`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_prospects`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_api_profiles`",
    ),

    'down_warning' => 'Rolling back drops worked prospects and their ownership history. '
                    . 'Export payplex_lf_prospects, payplex_lf_ownership and payplex_lf_audit first.',
);
