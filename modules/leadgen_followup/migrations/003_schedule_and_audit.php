<?php
defined('BASEPATH') or defined('LEADGEN_FU_TEST') or exit('No direct script access allowed');

/**
 * Migration 003 — columns and tables the corrected scheduler needs.
 *
 * WHAT IT ADDS, AND WHY EACH ONE
 * ------------------------------
 * The log as it stands records `leadid, stage_day, staffid, action_taken,
 * date_sent` and nothing else. That is enough to know *that* something was sent
 * and not enough to answer the question this module is being fixed for: **was
 * it allowed to be sent, and when did it become allowed?** Four columns close
 * that:
 *
 *   anchor_at           the lead-creation time the decision was measured from
 *   eligible_at         when that stage became due
 *   send_result         what actually happened, separate from what was attempted
 *   suppression_reason  why nothing was sent, when nothing was
 *
 * `tblleadgen_followup_decisions` records every evaluation, including the ones
 * that sent nothing. Without it, "this lead was correctly suppressed" leaves no
 * trace at all and the UAT can only show absence, which proves nothing.
 *
 * `tblleadgen_followup_suppression` holds do-not-contact, invalid, rejected and
 * unsubscribed markers. They live here rather than as four new columns on
 * `tblleads` because that is a core table and a module does not get to add
 * policy columns to it unilaterally. If you would rather these were core lead
 * fields, that is a separate, approvable change.
 *
 * SAFETY
 * ------
 * Additive only. No column is dropped, renamed, retyped or reordered; no row is
 * written, deleted or backfilled. Every statement is guarded so re-running is a
 * no-op, and `down()` names the exact reversal for each one.
 *
 * `tblleadgen_followup_log` and both new tables are module-owned. **No core
 * table is touched by this migration** — `touches_core_tables` is false and a
 * test asserts that against the statements rather than against this sentence.
 */
return array(
    'version'             => 3,
    'name'                => '003_schedule_and_audit',
    'touches_core_tables' => false,

    /**
     * Guarded, additive statements. Each entry is a check plus the statement to
     * run when the check says it is missing, so the runner never issues an ALTER
     * that would fail on a second pass.
     */
    'up' => array(
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_followup_log',
            'column' => 'anchor_at',
            'sql'    => 'ALTER TABLE `{T}` ADD COLUMN `anchor_at` DATETIME NULL AFTER `stage_day`',
        ),
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_followup_log',
            'column' => 'eligible_at',
            'sql'    => 'ALTER TABLE `{T}` ADD COLUMN `eligible_at` DATETIME NULL AFTER `anchor_at`',
        ),
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_followup_log',
            'column' => 'send_result',
            'sql'    => "ALTER TABLE `{T}` ADD COLUMN `send_result` VARCHAR(32) NOT NULL DEFAULT '' AFTER `action_taken`",
        ),
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_followup_log',
            'column' => 'suppression_reason',
            'sql'    => 'ALTER TABLE `{T}` ADD COLUMN `suppression_reason` VARCHAR(64) NULL AFTER `send_result`',
        ),
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_followup_log',
            'column' => 'run_mode',
            'sql'    => "ALTER TABLE `{T}` ADD COLUMN `run_mode` VARCHAR(16) NOT NULL DEFAULT 'live' AFTER `suppression_reason`",
        ),
        array(
            'kind'  => 'create_table',
            'table' => 'leadgen_followup_decisions',
            'sql'   => 'CREATE TABLE `{T}` (
                          `id` INT(11) NOT NULL AUTO_INCREMENT,
                          `run_id` VARCHAR(32) NOT NULL,
                          `leadid` INT(11) NOT NULL,
                          `stage_day` INT(11) NULL,
                          `decision` VARCHAR(32) NOT NULL,
                          `suppression_reason` VARCHAR(64) NULL,
                          `anchor_at` DATETIME NULL,
                          `eligible_at` DATETIME NULL,
                          `evaluated_at` DATETIME NOT NULL,
                          `timezone` VARCHAR(64) NOT NULL,
                          PRIMARY KEY (`id`),
                          KEY `idx_run` (`run_id`),
                          KEY `idx_lead` (`leadid`),
                          KEY `idx_decision` (`decision`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
        ),
        array(
            'kind'  => 'create_table',
            'table' => 'leadgen_followup_suppression',
            'sql'   => 'CREATE TABLE `{T}` (
                          `id` INT(11) NOT NULL AUTO_INCREMENT,
                          `leadid` INT(11) NOT NULL,
                          `category` VARCHAR(32) NOT NULL,
                          `reason` VARCHAR(191) NULL,
                          `added_by` INT(11) NOT NULL DEFAULT 0,
                          `added_at` DATETIME NOT NULL,
                          PRIMARY KEY (`id`),
                          UNIQUE KEY `uniq_lead_cat` (`leadid`, `category`),
                          KEY `idx_cat` (`category`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8',
        ),
    ),

    /**
     * Options this migration seeds.
     *
     * `outbound_enabled` is '0' on purpose and stays '0' until the staging UAT
     * has proved every interval and suppression rule. `armed_at` is stamped once
     * at migration time and is what makes "no bulk catch-up after deployment"
     * exact: any stage that came due before this instant is skipped permanently
     * rather than fired into a backlog.
     */
    'options' => array(
        'leadgen_followup_outbound_enabled'    => '0',
        'leadgen_followup_batch_limit'         => '25',
        'leadgen_followup_min_stage_gap_hours' => '24',
        'leadgen_followup_max_catchup_hours'   => '48',
        'leadgen_followup_customer_status_ids' => '',
        'leadgen_followup_armed_at'            => '{NOW}',
    ),

    /**
     * Rollback. Drops only what `up` created; the two pre-existing columns and
     * the uniq_lead_stage index from migration 002 are untouched.
     */
    'down' => array(
        'ALTER TABLE `{P}leadgen_followup_log` DROP COLUMN `run_mode`',
        'ALTER TABLE `{P}leadgen_followup_log` DROP COLUMN `suppression_reason`',
        'ALTER TABLE `{P}leadgen_followup_log` DROP COLUMN `send_result`',
        'ALTER TABLE `{P}leadgen_followup_log` DROP COLUMN `eligible_at`',
        'ALTER TABLE `{P}leadgen_followup_log` DROP COLUMN `anchor_at`',
        'DROP TABLE IF EXISTS `{P}leadgen_followup_decisions`',
        'DROP TABLE IF EXISTS `{P}leadgen_followup_suppression`',
    ),
);
