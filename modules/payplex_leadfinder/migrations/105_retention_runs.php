<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');
/**
 * Lead Finder migration 105 — the retention job's lock and its run history.
 *
 * WHY A TABLE RATHER THAN AN OPTION
 * ---------------------------------
 * The §14.3 coordinate sweep needs two things `tblpayplex_lf_config` cannot
 * give it:
 *
 *   - **A lock two processes cannot both take.** The cron fires every five
 *     minutes and a slow sweep can still be running when the next tick arrives.
 *     `UPDATE … WHERE locked_at IS NULL OR locked_at <= ?` plus
 *     `affected_rows()` makes the database the arbiter. A read-then-write
 *     against a config row is the same race this workspace has already paid for
 *     in leadgen_followup, where two cron runs both read "not sent" and the lead
 *     was emailed twice.
 *
 *   - **A history.** "When did retention last run, how many rows did it examine,
 *     how many did it purge, how long did it take, did it fail" is the evidence
 *     that the obligation is being met. A single last-run value can only say
 *     that something happened.
 *
 * `examined` and `purged` are separate columns deliberately. A sweep that
 * examined 500 and purged 0 is a healthy quiet day; one that examined 0 means
 * the query is broken. With only a purge count the two are indistinguishable —
 * which is precisely how a dead compliance job reports success for months.
 *
 * Additive: two new module-owned tables and three config rows. No core table is
 * touched, nothing is dropped, altered or backfilled, and `down` reverses
 * exactly what `up` creates. `coords_max_calendar_days` is NOT re-seeded here —
 * it is already 30 from migration 102, and 30 is Google's term, not this
 * migration's to restate.
 */
return array(
    'id'          => 105,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Retention job: distributed lock and run history',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 102,

    'up' => array(
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_job_locks` (
          `lock_key` VARCHAR(64) NOT NULL,
          `locked_at` INT(10) UNSIGNED NULL,
          `locked_by` VARCHAR(64) NULL,
          `updated_at` INT(10) UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (`lock_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8",

        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_retention_runs` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `started_at` INT(10) UNSIGNED NOT NULL,
          `finished_at` INT(10) UNSIGNED NOT NULL,
          `duration_ms` INT(10) UNSIGNED NOT NULL DEFAULT 0,
          `examined` INT(10) UNSIGNED NOT NULL DEFAULT 0,
          `purged` INT(10) UNSIGNED NOT NULL DEFAULT 0,
          `status` VARCHAR(16) NOT NULL DEFAULT 'ok',
          `mode` VARCHAR(16) NOT NULL DEFAULT 'cron',
          `error` VARCHAR(500) NULL,
          `actor_id` INT(11) NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          KEY `idx_started` (`started_at`),
          KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8",

        "INSERT IGNORE INTO `{P}payplex_lf_job_locks` (`lock_key`,`locked_at`,`locked_by`,`updated_at`)
         VALUES ('retention_coordinates', NULL, NULL, 0)",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('retention_job_interval_seconds','3600','How often the §14.3 coordinate sweep runs. The CRM cron fires every 5 minutes; the sweep does not need to. Operational setting, not a business rate.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('retention_job_lock_ttl_seconds','900','How long a held job lock stays valid. A process that dies mid-sweep leaves its lock behind; without a TTL the job would be wedged for ever and — because it fails quietly by design — nobody would notice until an audit. 15 minutes is longer than a 500-row sweep and short enough that one crash costs at most three cron ticks.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('retention_job_batch_size','500','Rows examined per sweep. Bounded so one pass cannot hold a long transaction on a growing table; the next tick continues where this one stopped.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey IN (
            'retention_job_interval_seconds','retention_job_lock_ttl_seconds','retention_job_batch_size')",
        "DROP TABLE IF EXISTS `{P}payplex_lf_retention_runs`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_job_locks`",
    ),

    /*
     * The rollback drops the retention run history, and that history is the
     * evidence that the §14.3 obligation was being met. Losing it does not
     * breach anything by itself — but it destroys the ability to show, after the
     * fact, that coordinates were being cleared on schedule.
     */
    'down_warning' => 'Rolling back drops the §14.3 retention run history and the job lock. '
                    . 'Export payplex_lf_retention_runs first — it is the record that the '
                    . '30-day coordinate deletion actually ran, and it cannot be reconstructed.',

    'reports' => array(
        "SELECT COUNT(*) AS job_lock_rows FROM `{P}payplex_lf_job_locks`",
        "SELECT COUNT(*) AS retention_run_rows FROM `{P}payplex_lf_retention_runs`",
        "SELECT cvalue AS coords_max_calendar_days FROM `{P}payplex_lf_config` WHERE ckey='coords_max_calendar_days' ORDER BY effective_from DESC LIMIT 1",
    ),
);
