<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payplex AI Calling — install migration.
 * Creates only tbl_<prefix>payplex_* tables. Touches NO Perfex core tables.
 * All tables InnoDB / utf8mb4. Idempotent-ish: uses IF NOT EXISTS.
 *
 * Verified against the live staging install on 2026-09-10: db_prefix() resolves
 * to "tbl" there, read from application/config/app-config.php, and the tables
 * this file creates carry utf8mb4/utf8mb4_unicode_ci as declared below.
 */

$CI = &get_instance();
$prefix = db_prefix(); // e.g. tbl_

$charset = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

// 1. Calls (CRM-side mirror of Sonivo calls)
if (!$CI->db->table_exists($prefix . 'payplex_calls')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_calls` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `crm_lead_id` INT NULL,
        `crm_customer_id` INT NULL,
        `staff_id` INT NOT NULL,
        `direction` ENUM('outbound','inbound') NOT NULL DEFAULT 'outbound',
        `sonivo_call_id` VARCHAR(64) NULL,
        `correlation_id` CHAR(36) NOT NULL,
        `idempotency_key` CHAR(36) NOT NULL,
        `agent_id` VARCHAR(64) NULL,
        `language` VARCHAR(12) NULL,
        `status` VARCHAR(24) NOT NULL DEFAULT 'pending',
        `disposition` VARCHAR(48) NULL,
        `failure_reason` VARCHAR(64) NULL,
        `duration_sec` INT NULL,
        `cost` DECIMAL(12,4) NULL,
        `currency` CHAR(3) NULL,
        `recording_available` TINYINT(1) NOT NULL DEFAULT 0,
        `transcript_available` TINYINT(1) NOT NULL DEFAULT 0,
        `summary_text` TEXT NULL,
        `sentiment` VARCHAR(16) NULL,
        `detected_intent` VARCHAR(64) NULL,
        `objections_json` TEXT NULL,
        `callback_date` DATETIME NULL,
        `consent_ref` VARCHAR(96) NULL,
        `scheduled_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_idempotency` (`idempotency_key`),
        UNIQUE KEY `uq_sonivo_call` (`sonivo_call_id`),
        KEY `ix_lead` (`crm_lead_id`),
        KEY `ix_customer` (`crm_customer_id`),
        KEY `ix_staff_created` (`staff_id`,`created_at`),
        KEY `ix_status` (`status`),
        KEY `ix_correlation` (`correlation_id`)
    ) ENGINE=InnoDB {$charset};");
}

// 2. Call events (append-only, ordering + webhook dedupe)
if (!$CI->db->table_exists($prefix . 'payplex_call_events')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_call_events` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `call_id` BIGINT UNSIGNED NULL,
        `event_id` VARCHAR(64) NOT NULL,
        `event` VARCHAR(48) NOT NULL,
        `sequence` INT NOT NULL DEFAULT 0,
        `occurred_at` DATETIME NULL,
        `correlation_id` CHAR(36) NULL,
        `payload_json` MEDIUMTEXT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_event` (`event_id`),
        KEY `ix_call_seq` (`call_id`,`sequence`)
    ) ENGINE=InnoDB {$charset};");
}

// 3. Webhook inbox (idempotency + replay defence)
if (!$CI->db->table_exists($prefix . 'payplex_webhook_inbox')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_webhook_inbox` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `event_id` VARCHAR(64) NOT NULL,
        `signature_valid` TINYINT(1) NOT NULL DEFAULT 0,
        `timestamp_valid` TINYINT(1) NOT NULL DEFAULT 0,
        `processed` TINYINT(1) NOT NULL DEFAULT 0,
        `raw_body` MEDIUMTEXT NULL,
        `received_at` DATETIME NOT NULL,
        `processed_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_event` (`event_id`),
        KEY `ix_processed` (`processed`,`received_at`)
    ) ENGINE=InnoDB {$charset};");
}

// 4. Outbox (retry + reconciliation)
if (!$CI->db->table_exists($prefix . 'payplex_outbox')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_outbox` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `idempotency_key` CHAR(36) NOT NULL,
        `endpoint` VARCHAR(191) NOT NULL,
        `method` VARCHAR(8) NOT NULL,
        `payload_json` MEDIUMTEXT NULL,
        `status` ENUM('pending','sent','failed','reconciled') NOT NULL DEFAULT 'pending',
        `attempts` INT NOT NULL DEFAULT 0,
        `last_error` VARCHAR(255) NULL,
        `next_retry_at` DATETIME NULL,
        `correlation_id` CHAR(36) NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_idempotency` (`idempotency_key`),
        KEY `ix_status_retry` (`status`,`next_retry_at`)
    ) ENGINE=InnoDB {$charset};");
}

// 5. Integration health snapshot
if (!$CI->db->table_exists($prefix . 'payplex_integration_health')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_integration_health` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `checked_at` DATETIME NOT NULL,
        `sonivo_reachable` TINYINT(1) NOT NULL DEFAULT 0,
        `last_success_call_at` DATETIME NULL,
        `last_webhook_at` DATETIME NULL,
        `providers_json` TEXT NULL,
        `balance_amount` DECIMAL(12,2) NULL,
        `balance_currency` CHAR(3) NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB {$charset};");
}

// 6. Tamper-evident audit log (hash-chained)
if (!$CI->db->table_exists($prefix . 'payplex_audit')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_audit` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `actor_staff_id` INT NULL,
        `actor_role` VARCHAR(48) NULL,
        `action` VARCHAR(64) NOT NULL,
        `object_type` VARCHAR(48) NULL,
        `object_id` VARCHAR(64) NULL,
        `before_json` TEXT NULL,
        `after_json` TEXT NULL,
        `correlation_id` CHAR(36) NULL,
        `ip` VARCHAR(45) NULL,
        `user_agent` VARCHAR(255) NULL,
        `prev_hash` CHAR(64) NULL,
        `row_hash` CHAR(64) NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_object` (`object_type`,`object_id`),
        KEY `ix_actor_created` (`actor_staff_id`,`created_at`),
        KEY `ix_action_created` (`action`,`created_at`)
    ) ENGINE=InnoDB {$charset};");
}

// 9. Consent / DND ledger (append-only; current state = latest row). Fail-closed authority.
if (!$CI->db->table_exists($prefix . 'payplex_consent')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_consent` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `subject_type` ENUM('lead','customer') NOT NULL,
        `subject_id` INT NOT NULL,
        `channel` VARCHAR(16) NOT NULL DEFAULT 'call',
        `state` ENUM('granted','withdrawn') NOT NULL,
        `dnd` TINYINT(1) NOT NULL DEFAULT 0,
        `source` VARCHAR(64) NULL,
        `evidence_ref` VARCHAR(191) NULL,
        `actor_staff_id` INT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_subject` (`subject_type`,`subject_id`,`channel`,`id`)
    ) ENGINE=InnoDB {$charset};");
}

// 10. Campaigns (CRM-side, approval-gated; execution mirrored from Sonivo).
if (!$CI->db->table_exists($prefix . 'payplex_campaigns')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_campaigns` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(191) NOT NULL,
        `agent_id` VARCHAR(64) NULL,
        `language` VARCHAR(12) NULL,
        `objective` VARCHAR(191) NULL,
        `audience_json` MEDIUMTEXT NULL,
        `status` ENUM('draft','pending_approval','approved','running','paused','completed','rejected') NOT NULL DEFAULT 'draft',
        `sonivo_campaign_id` VARCHAR(64) NULL,
        `total_targets` INT NOT NULL DEFAULT 0,
        `completed_calls` INT NOT NULL DEFAULT 0,
        `created_by` INT NULL,
        `approved_by` INT NULL,
        `approved_at` DATETIME NULL,
        `reject_reason` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `ix_status` (`status`),
        KEY `ix_creator` (`created_by`)
    ) ENGINE=InnoDB {$charset};");
}

// Register module option defaults (encrypted values set via settings screen).
add_option('payplex_aicalling_base_url', '');
add_option('payplex_aicalling_service_jwt', ''); // stored via CI Encryption at write time
add_option('payplex_aicalling_request_secret', '');
add_option('payplex_aicalling_webhook_secret', '');
add_option('payplex_aicalling_timeout_connect', '5');
add_option('payplex_aicalling_timeout_read', '15');
add_option('payplex_aicalling_enabled', '0'); // feature flag OFF by default

/*
 * Permitted calling window (spec §4.3).
 *
 * The timezone default is deliberately EMPTY, not the server's. An empty value
 * makes the gate fall back to the Perfex default timezone and, failing that,
 * refuse the call — because a calling window measured against an unknown clock
 * is not a permitted window. The previous implementation used date('G'), so on
 * a UTC server a 09:00-20:00 rule became 14:30-01:30 India time: calls refused
 * during business hours and permitted at 1am, which inverts the regulation the
 * gate exists to honour.
 */
add_option('payplex_aicalling_timezone', '');
add_option('payplex_aicalling_hours_start', '8');
add_option('payplex_aicalling_hours_end', '19');

/*
 * Hours are measured in the RECIPIENT's local time, derived per lead from
 * their country. This is only the fallback for leads whose zone cannot be
 * derived; empty means those calls refuse rather than being placed against an
 * assumed clock.
 */
add_option('payplex_aicalling_fallback_timezone', '');

/*
 * §4.4 frequency, suppression and cost controls.
 *
 * Frequency limits ship EMPTY and the library supplies a conservative default
 * (3/day, 10/week, 240-minute cooldown, 600-second cap). Defaulting is safe
 * here because any cap is stricter than the previous behaviour, which was no
 * cap at all; the settings screen labels a default as a default so nobody
 * mistakes it for a decision somebody made.
 *
 * Budgets ship EMPTY and the library REFUSES rather than defaulting. A budget
 * is not a safety limit, it is authorisation to spend, and inventing a figure
 * would be inventing a business decision — the same reason this project will
 * not invent a commission rate. Calls stay refused until finance sets one.
 *
 * The recording disclosure ships EMPTY and unconfirmed, and refuses, because
 * recording someone without the disclosure they are owed is not a state to
 * default into.
 */
add_option('payplex_aicalling_max_per_day', '');
add_option('payplex_aicalling_max_per_week', '');
add_option('payplex_aicalling_cooldown_minutes', '');
add_option('payplex_aicalling_max_duration_sec', '');
add_option('payplex_aicalling_agent_budget', '');
add_option('payplex_aicalling_account_budget', '');
add_option('payplex_aicalling_recording_disclosure', '');
add_option('payplex_aicalling_disclosure_confirmed', '0');

/*
 * Which of the backend's own status and disposition values mean "already
 * running", "unusable number" and "a human has this". Empty means the library's
 * built-in defaults apply and are labelled as defaults; it never means "match
 * nothing", because a suppression that can be switched off by clearing a box is
 * the silent no-op this module keeps producing.
 */
add_option('payplex_aicalling_in_flight_statuses', '');
add_option('payplex_aicalling_invalid_dispositions', '');
add_option('payplex_aicalling_human_dispositions', '');
