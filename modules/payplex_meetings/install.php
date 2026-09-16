<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Schema installer.
 *
 * Every statement is idempotent (CREATE TABLE IF NOT EXISTS), so re-activating the module
 * is safe and never destroys data. Rollback lives in uninstall.php and is opt-in: it does
 * NOT run automatically, because dropping meeting history on a deactivation click would be
 * unrecoverable.
 */

$CI = &get_instance();

$prefix  = function_exists('db_prefix') ? db_prefix() : 'tbl';
$charset = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
$engine  = 'ENGINE=InnoDB';

$statements = [];

/* ------------------------------------------------------------------ meetings */
$statements[] = "CREATE TABLE IF NOT EXISTS `{$prefix}payplex_meetings` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `reference_no` VARCHAR(24) NOT NULL,
  `rel_type` VARCHAR(20) NOT NULL DEFAULT 'lead',
  `rel_id` INT(11) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `agenda` TEXT NULL,
  `description` TEXT NULL,
  `meeting_type` VARCHAR(40) NOT NULL DEFAULT 'other',
  `category` VARCHAR(40) NULL,
  `priority` VARCHAR(12) NOT NULL DEFAULT 'medium',
  `language` VARCHAR(10) NOT NULL DEFAULT 'en',
  `tags` VARCHAR(255) NULL,
  `location_type` VARCHAR(16) NOT NULL DEFAULT 'online',
  `location_address` TEXT NULL,
  `platform` VARCHAR(30) NULL,
  `meeting_link` TEXT NULL,
  `link_created_after_booking` TINYINT(1) NOT NULL DEFAULT 0,
  `start_utc` DATETIME NOT NULL,
  `end_utc` DATETIME NOT NULL,
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
  `duration_minutes` SMALLINT(6) NOT NULL DEFAULT 30,
  `status` VARCHAR(24) NOT NULL DEFAULT 'scheduled',
  `outcome` VARCHAR(40) NULL,
  `is_private` TINYINT(1) NOT NULL DEFAULT 0,
  `organizer_staff_id` INT(11) NOT NULL,
  `owner_staff_id` INT(11) NULL,
  `approval_state` VARCHAR(16) NOT NULL DEFAULT 'not_required',
  `approved_by` INT(11) NULL,
  `approved_at` DATETIME NULL,
  `conflict_override_by` INT(11) NULL,
  `conflict_override_reason` TEXT NULL,
  `reschedule_reason` TEXT NULL,
  `cancel_reason` TEXT NULL,
  `parent_meeting_id` INT(11) NULL,
  `created_by` INT(11) NOT NULL,
  `date_created` DATETIME NOT NULL,
  `last_updated` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pm_reference_no` (`reference_no`),
  KEY `pm_rel` (`rel_type`,`rel_id`),
  KEY `pm_start_status` (`start_utc`,`status`),
  KEY `pm_organizer` (`organizer_staff_id`,`start_utc`),
  KEY `pm_status_start` (`status`,`start_utc`)
) {$engine} {$charset};";

/* -------------------------------------------------------------- participants */
$statements[] = "CREATE TABLE IF NOT EXISTS `{$prefix}payplex_meeting_participants` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` INT(11) NOT NULL,
  `party_type` VARCHAR(10) NOT NULL DEFAULT 'staff',
  `staff_id` INT(11) NULL,
  `contact_id` INT(11) NULL,
  `email` VARCHAR(190) NULL,
  `name` VARCHAR(160) NULL,
  `role` VARCHAR(10) NOT NULL DEFAULT 'required',
  `is_summary_recipient` TINYINT(1) NOT NULL DEFAULT 0,
  `is_reminder_recipient` TINYINT(1) NOT NULL DEFAULT 1,
  `response` VARCHAR(12) NOT NULL DEFAULT 'pending',
  `responded_at` DATETIME NULL,
  `added_by` INT(11) NULL,
  `removed_at` DATETIME NULL,
  `date_created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pm_p_meeting` (`meeting_id`),
  KEY `pm_p_staff` (`staff_id`,`meeting_id`),
  KEY `pm_p_email` (`meeting_id`,`email`)
) {$engine} {$charset};";

/* ------------------------------------------------------------------ reminders */
$statements[] = "CREATE TABLE IF NOT EXISTS `{$prefix}payplex_meeting_reminders` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` INT(11) NOT NULL,
  `participant_id` INT(11) NULL,
  `channel` VARCHAR(12) NOT NULL DEFAULT 'email',
  `offset_minutes` INT(11) NOT NULL,
  `template_slug` VARCHAR(64) NOT NULL DEFAULT 'pm_reminder',
  `scheduled_utc` DATETIME NOT NULL,
  `sent_utc` DATETIME NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'pending',
  `attempts` TINYINT(4) NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `idempotency_key` CHAR(64) NOT NULL,
  `claim_token` CHAR(32) NULL,
  `claimed_at` DATETIME NULL,
  `date_created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pm_r_idem` (`idempotency_key`),
  KEY `pm_r_due` (`status`,`scheduled_utc`),
  KEY `pm_r_claim` (`claim_token`),
  KEY `pm_r_meeting` (`meeting_id`)
) {$engine} {$charset};";

/* ------------------------------------------------------------------- summaries */
$statements[] = "CREATE TABLE IF NOT EXISTS `{$prefix}payplex_meeting_summaries` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` INT(11) NOT NULL,
  `version` SMALLINT(6) NOT NULL DEFAULT 1,
  `internal_body` LONGTEXT NULL,
  `client_body` LONGTEXT NULL,
  `outcome` VARCHAR(40) NULL,
  `key_points` TEXT NULL,
  `requirements` TEXT NULL,
  `objections` TEXT NULL,
  `products_discussed` TEXT NULL,
  `pricing_discussed` TEXT NULL,
  `commitments_company` TEXT NULL,
  `commitments_client` TEXT NULL,
  `decision_maker` TEXT NULL,
  `budget_note` TEXT NULL,
  `expected_close` DATE NULL,
  `sentiment` VARCHAR(16) NULL,
  `conversion_probability` TINYINT(4) NULL,
  `recommended_lead_status` INT(11) NULL,
  `final_lead_status` INT(11) NULL,
  `source` VARCHAR(12) NOT NULL DEFAULT 'manual',
  `ai_provider` VARCHAR(40) NULL,
  `ai_model` VARCHAR(60) NULL,
  `ai_generated_at` DATETIME NULL,
  `created_by` INT(11) NOT NULL,
  `approved_by` INT(11) NULL,
  `approved_at` DATETIME NULL,
  `date_created` DATETIME NOT NULL,
  `last_updated` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `pm_s_meeting` (`meeting_id`,`version`)
) {$engine} {$charset};";

/* ---------------------------------------------------------------- action items */
$statements[] = "CREATE TABLE IF NOT EXISTS `{$prefix}payplex_meeting_action_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` INT(11) NOT NULL,
  `summary_id` INT(11) NULL,
  `title` VARCHAR(255) NOT NULL,
  `detail` TEXT NULL,
  `owner_staff_id` INT(11) NULL,
  `due_date` DATE NULL,
  `is_client_visible` TINYINT(1) NOT NULL DEFAULT 0,
  `converted_task_id` INT(11) NULL,
  `status` VARCHAR(12) NOT NULL DEFAULT 'open',
  `created_by` INT(11) NOT NULL,
  `date_created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pm_ai_meeting` (`meeting_id`)
) {$engine} {$charset};";

/* ---------------------------------------------------------------------- notes */
$statements[] = "CREATE TABLE IF NOT EXISTS `{$prefix}payplex_meeting_notes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` INT(11) NOT NULL,
  `staff_id` INT(11) NOT NULL,
  `body` TEXT NOT NULL,
  `visibility` VARCHAR(10) NOT NULL DEFAULT 'internal',
  `date_created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pm_n_meeting` (`meeting_id`)
) {$engine} {$charset};";

/* ---------------------------------------------------------------- attachments */
$statements[] = "CREATE TABLE IF NOT EXISTS `{$prefix}payplex_meeting_attachments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` INT(11) NOT NULL,
  `summary_id` INT(11) NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_type` VARCHAR(60) NULL,
  `size_bytes` INT(11) NULL,
  `is_transcript` TINYINT(1) NOT NULL DEFAULT 0,
  `consent_confirmed_by` INT(11) NULL,
  `consent_confirmed_at` DATETIME NULL,
  `uploaded_by` INT(11) NOT NULL,
  `date_created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pm_at_meeting` (`meeting_id`)
) {$engine} {$charset};";

/* --------------------------------------------------------------- integrations */
$statements[] = "CREATE TABLE IF NOT EXISTS `{$prefix}payplex_meeting_integrations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` INT(11) NOT NULL,
  `provider` VARCHAR(16) NOT NULL,
  `external_event_id` VARCHAR(255) NULL,
  `external_calendar_id` VARCHAR(255) NULL,
  `sync_state` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `last_synced_at` DATETIME NULL,
  `last_error` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `pm_int_meeting` (`meeting_id`,`provider`)
) {$engine} {$charset};";

/* ----------------------------------------------------------------- email logs */
$statements[] = "CREATE TABLE IF NOT EXISTS `{$prefix}payplex_meeting_email_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` INT(11) NOT NULL,
  `reminder_id` INT(11) NULL,
  `recipient_email` VARCHAR(190) NOT NULL,
  `recipient_type` VARCHAR(16) NOT NULL DEFAULT 'staff',
  `template_slug` VARCHAR(64) NOT NULL,
  `subject_rendered` VARCHAR(255) NULL,
  `version_sent` VARCHAR(10) NULL,
  `channel` VARCHAR(12) NOT NULL DEFAULT 'email',
  `scheduled_utc` DATETIME NULL,
  `sent_utc` DATETIME NULL,
  `delivery_status` VARCHAR(12) NOT NULL DEFAULT 'sent',
  `failure_reason` TEXT NULL,
  `retry_count` TINYINT(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `pm_el_meeting` (`meeting_id`,`sent_utc`)
) {$engine} {$charset};";

/* -------------------------------------------------------------- activity logs */
$statements[] = "CREATE TABLE IF NOT EXISTS `{$prefix}payplex_meeting_activity_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` INT(11) NULL,
  `rel_type` VARCHAR(20) NULL,
  `rel_id` INT(11) NULL,
  `staff_id` INT(11) NULL,
  `action` VARCHAR(60) NOT NULL,
  `field` VARCHAR(60) NULL,
  `old_value` TEXT NULL,
  `new_value` TEXT NULL,
  `reason` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `date_created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `pm_log_meeting` (`meeting_id`),
  KEY `pm_log_rel` (`rel_type`,`rel_id`),
  KEY `pm_log_action` (`action`,`date_created`)
) {$engine} {$charset};";

foreach ($statements as $sql) {
    $CI->db->query($sql);
}

/* ------------------------------------------------------------------- defaults */
$defaults = [
    'pm_default_timezone'      => 'Asia/Kolkata',
    'pm_default_duration'      => '30',
    'pm_working_days'          => '1,2,3,4,5,6',
    'pm_working_hours_start'   => '09:00',
    'pm_working_hours_end'     => '20:00',
    'pm_reminder_offsets'      => '1440,120,30',
    'pm_reminder_channels'     => 'email,crm',
    'pm_retry_max'             => '3',
    'pm_retry_backoff_minutes' => '10',
    'pm_missed_window_minutes' => '15',
    'pm_require_link_online'   => '1',
    'pm_allow_link_after'      => '0',
    'pm_conflict_block'        => '1',
    'pm_calendar_feed_enabled' => '1',
    'pm_holidays'              => '',
    'pm_cron_token'            => bin2hex(random_bytes(16)),
];

foreach ($defaults as $key => $value) {
    if (function_exists('get_option') && get_option($key) === '') {
        add_option($key, $value, 0);
    }
}
