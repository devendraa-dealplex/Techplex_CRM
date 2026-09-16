<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Migration 002 — make refusals visible, and make replays harmless.
 *
 * THE TWO THINGS THIS FIXES
 * -------------------------
 * **1. A rejected delivery left no trace.** The module wrote a row only on the
 * success path. So the state of the database after "Meta never called" and
 * after "Meta called eleven times and every one was refused" is the same empty
 * table, and those two have nothing in common as problems. Twelve configuration
 * saves were made across four days on staging and not one delivery was ever
 * recorded — which could have meant either, and there was no way to tell.
 *
 * `leadgen_facebook_deliveries` records every inbound request: accepted,
 * rejected, malformed, or thrown. It is append-only and it is the table the
 * Meta "Recent Deliveries" screen gets compared against.
 *
 * **2. The duplicate window.** See migration 001 for why `KEY fb_ref` is not a
 * constraint. This migration adds `UNIQUE KEY uniq_channel_ref (channel,
 * fb_ref)` to installs that already have the table, and refuses to add it —
 * loudly, in the activity log — if duplicates already exist rather than failing
 * mid-ALTER. Staging has one Facebook row and cannot have duplicates; the check
 * is there because a migration that has only ever been run on a clean database
 * has not been tested.
 *
 * WHAT ELSE IT ADDS, AND WHY EACH
 * -------------------------------
 *   page_id, form_id, campaign_id   Meta sends these and the module discarded
 *                                   them. Without them a lead cannot be traced
 *                                   back to the ad that produced it, which is
 *                                   the whole point of tracking a source.
 *   request_id                      ties the claim row to the delivery-log row
 *   assignment_mode, assigned_to    what the assignment rule decided, recorded
 *                                   at the time, because the configuration will
 *                                   change and the history should not
 *   needs_review, alerted_at        whether a human still has to allocate this
 *                                   lead, and whether anyone has been told
 *
 * SAFETY
 * ------
 * Additive only. No column dropped, renamed or retyped; no row written,
 * deleted or backfilled; **no core table touched** — `touches_core_tables` is
 * false and a test asserts that against the statements rather than against this
 * sentence. Every statement is guarded, so a re-run is a no-op even if the
 * version option is lost.
 */
return array(
    'version'             => 2,
    'name'                => '002_delivery_log_and_idempotency',
    'touches_core_tables' => false,

    'up' => array(
        /* ---- the idempotency constraint ---- */
        array(
            'kind'    => 'add_unique_index',
            'table'   => 'leadgen_facebook_messages',
            'index'   => 'uniq_channel_ref',
            'columns' => array('channel', 'fb_ref'),
            'sql'     => 'ALTER TABLE `{T}` ADD UNIQUE KEY `uniq_channel_ref` (`channel`, `fb_ref`)',
        ),

        /* ---- provenance on the claim row ---- */
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_facebook_messages',
            'column' => 'page_id',
            'sql'    => 'ALTER TABLE `{T}` ADD COLUMN `page_id` VARCHAR(64) NULL AFTER `fb_ref`',
        ),
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_facebook_messages',
            'column' => 'form_id',
            'sql'    => 'ALTER TABLE `{T}` ADD COLUMN `form_id` VARCHAR(64) NULL AFTER `page_id`',
        ),
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_facebook_messages',
            'column' => 'campaign_id',
            'sql'    => 'ALTER TABLE `{T}` ADD COLUMN `campaign_id` VARCHAR(64) NULL AFTER `form_id`',
        ),
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_facebook_messages',
            'column' => 'request_id',
            'sql'    => 'ALTER TABLE `{T}` ADD COLUMN `request_id` VARCHAR(32) NULL AFTER `campaign_id`',
        ),
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_facebook_messages',
            'column' => 'assignment_mode',
            'sql'    => "ALTER TABLE `{T}` ADD COLUMN `assignment_mode` VARCHAR(32) NOT NULL DEFAULT '' AFTER `request_id`",
        ),
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_facebook_messages',
            'column' => 'assigned_to',
            'sql'    => 'ALTER TABLE `{T}` ADD COLUMN `assigned_to` INT(11) NOT NULL DEFAULT 0 AFTER `assignment_mode`',
        ),
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_facebook_messages',
            'column' => 'needs_review',
            'sql'    => 'ALTER TABLE `{T}` ADD COLUMN `needs_review` TINYINT(1) NOT NULL DEFAULT 0 AFTER `assigned_to`',
        ),
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_facebook_messages',
            'column' => 'alerted_at',
            'sql'    => 'ALTER TABLE `{T}` ADD COLUMN `alerted_at` DATETIME NULL AFTER `needs_review`',
        ),

        /* ---- the inbound delivery log ---- */
        array(
            'kind'  => 'create_table',
            'table' => 'leadgen_facebook_deliveries',
            'sql'   => 'CREATE TABLE `{T}` (
                          `id` INT(11) NOT NULL AUTO_INCREMENT,
                          `request_id` VARCHAR(32) NOT NULL,
                          `received_at` DATETIME NOT NULL,
                          `received_epoch` BIGINT(20) NOT NULL,
                          `timezone` VARCHAR(64) NOT NULL,
                          `http_method` VARCHAR(10) NOT NULL,
                          `event_type` VARCHAR(32) NOT NULL,
                          `outcome` VARCHAR(48) NOT NULL,
                          `accepted` TINYINT(1) NOT NULL DEFAULT 0,
                          `http_status` SMALLINT(6) NOT NULL,
                          `retryable` TINYINT(1) NOT NULL DEFAULT 0,
                          `failure_reason` VARCHAR(191) NULL,
                          `page_id` VARCHAR(64) NULL,
                          `form_id` VARCHAR(64) NULL,
                          `leadgen_id` VARCHAR(64) NULL,
                          `lead_id` INT(11) NULL,
                          `signature_present` TINYINT(1) NOT NULL DEFAULT 0,
                          `signature_fp` VARCHAR(16) NULL,
                          `payload_bytes` INT(11) NOT NULL DEFAULT 0,
                          `payload_redacted` MEDIUMTEXT NULL,
                          `headers_safe` TEXT NULL,
                          `assignment_mode` VARCHAR(32) NULL,
                          `assigned_to` INT(11) NOT NULL DEFAULT 0,
                          `needs_review` TINYINT(1) NOT NULL DEFAULT 0,
                          `processing_ms` INT(11) NOT NULL DEFAULT 0,
                          PRIMARY KEY (`id`),
                          UNIQUE KEY `uniq_request` (`request_id`),
                          KEY `idx_received` (`received_epoch`),
                          KEY `idx_outcome` (`outcome`),
                          KEY `idx_accepted` (`accepted`),
                          KEY `idx_leadgen` (`leadgen_id`),
                          KEY `idx_lead` (`lead_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ),
    ),

    /**
     * Options this migration seeds.
     *
     * Every one is inert or off. `add_option()` never overwrites an existing
     * value, so seeding these on staging — which already has four of them set —
     * changes nothing there.
     *
     * `facebook_assignment_mode` starts at 'unassigned': a lead that arrives
     * before an administrator has chosen an assignment rule goes to the review
     * queue with an alert, which is visible and recoverable. Guessing an
     * assignee would not be.
     *
     * `facebook_default_lead_status` is seeded EMPTY on purpose. The staging
     * value is 38, which is "Cold" there and does not exist as "New Lead"
     * anywhere; an empty value makes Facebook_status resolve by name instead,
     * which is the behaviour that is correct on both installs.
     *
     * Outbound messaging, AI calling and drip are not touched by this module and
     * no option here enables anything that sends.
     */
    'options' => array(
        'facebook_page_id'              => '',
        'facebook_page_access_token'    => '',
        'facebook_app_secret'           => '',
        'facebook_verify_token'         => '',
        'facebook_default_lead_status'  => '',
        'facebook_lead_status_name'     => 'New Lead',
        'facebook_lead_source_name'     => 'Facebook Lead Ads',
        'facebook_assignment_mode'      => 'unassigned',
        'facebook_default_assignee'     => '',
        'facebook_round_robin_pool'     => '',
        'facebook_round_robin_last'     => '',
        'facebook_messenger_enabled'    => '0',
        'facebook_schema_version'       => '0',
    ),

    /**
     * Rollback. Drops only what `up` created.
     *
     * The index drop is listed first so the table is back to its pre-migration
     * shape if only part of the rollback is wanted, and the message table
     * itself is NOT dropped here — that belongs to migration 001.
     */
    'down' => array(
        'ALTER TABLE `{P}leadgen_facebook_messages` DROP INDEX `uniq_channel_ref`',
        'ALTER TABLE `{P}leadgen_facebook_messages` DROP COLUMN `alerted_at`',
        'ALTER TABLE `{P}leadgen_facebook_messages` DROP COLUMN `needs_review`',
        'ALTER TABLE `{P}leadgen_facebook_messages` DROP COLUMN `assigned_to`',
        'ALTER TABLE `{P}leadgen_facebook_messages` DROP COLUMN `assignment_mode`',
        'ALTER TABLE `{P}leadgen_facebook_messages` DROP COLUMN `request_id`',
        'ALTER TABLE `{P}leadgen_facebook_messages` DROP COLUMN `campaign_id`',
        'ALTER TABLE `{P}leadgen_facebook_messages` DROP COLUMN `form_id`',
        'ALTER TABLE `{P}leadgen_facebook_messages` DROP COLUMN `page_id`',
        'DROP TABLE IF EXISTS `{P}leadgen_facebook_deliveries`',
    ),

    'down_warning' => 'Dropping leadgen_facebook_deliveries destroys the inbound '
        . 'delivery history, which is the only record of refused deliveries and '
        . 'the evidence the Meta Recent Deliveries screen is reconciled against. '
        . 'Export it first. Dropping uniq_channel_ref reopens the duplicate-lead '
        . 'window described in migration 001.',
);
