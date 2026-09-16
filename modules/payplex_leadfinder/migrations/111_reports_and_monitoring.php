<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');
/**
 * Lead Finder migration 111 — the export ledger and the alert thresholds.
 *
 * WHY AN EXPORT LEDGER
 * --------------------
 * A report on screen is bounded by the session that opened it. A CSV is a file
 * on somebody's laptop, and the rows in these reports are business contact
 * details and a map of which employee is working which accounts. Recording that
 * a download happened — who, which report, how many rows — costs one insert and
 * is the only thing that makes the question "where did this spreadsheet come
 * from" answerable at all.
 *
 * It records the fact of the export, never its contents. Storing the rows would
 * double the exposure it exists to make traceable.
 *
 * WHY THE THRESHOLDS ARE CONFIGURATION
 * ------------------------------------
 * An alert that fires too often is filtered, and once a recipient filters one
 * alert they filter the channel. The right number is site-specific — five failed
 * calls in a day is noise on a busy install and an emergency on a quiet one — so
 * it is a value an administrator sets rather than a constant somebody has to
 * re-deploy to change.
 *
 * Additive. One new module-owned table and config seeds. No core table is
 * touched, nothing is dropped, and `down` reverses exactly what `up` creates.
 */
return array(
    'id'          => 111,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Report export ledger and monitoring thresholds',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 110,

    'up' => array(
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_report_exports` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `report_id` VARCHAR(60) NOT NULL,
          `staff_id` INT NOT NULL,
          `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
          `created_at` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`id`),
          KEY `lf_export_staff` (`staff_id`,`created_at`),
          KEY `lf_export_report` (`report_id`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('api_error_alert_threshold','5','How many Places API failures on one connection within 24 hours before an administrator is alerted. A single failure is weather; an alert that fires on every transient 503 trains its recipient to ignore the channel, and the thing they would then miss is a revoked key.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('report_max_rows','1000','Rows returned by one report. A monitoring screen that times out is worse than one that says it truncated, and the person running it is usually looking at it because something else is already wrong.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('report_export_max_rows','5000','Rows one CSV export may contain. Larger exports are refused rather than silently truncated: a spreadsheet that quietly stops at the limit is a wrong answer somebody will act on.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey IN (
            'api_error_alert_threshold','report_max_rows','report_export_max_rows')",
        "DROP TABLE IF EXISTS `{P}payplex_lf_report_exports`",
    ),

    'down_warning' => 'Rolling back drops the export ledger, after which there is no record of who '
                    . 'downloaded which report. The reports themselves keep working — they are '
                    . 'queries, not stored data — so nothing stops working; what is lost is the '
                    . 'ability to answer "where did this spreadsheet of contact details come '
                    . 'from". Export payplex_lf_report_exports first; it holds staff ids, report '
                    . 'names, row counts and timestamps, and no customer data at all.',

    'reports' => array(
        "SELECT COUNT(*) AS export_rows FROM `{P}payplex_lf_report_exports`",
        "SELECT cvalue AS api_error_alert_threshold FROM `{P}payplex_lf_config` WHERE ckey='api_error_alert_threshold' ORDER BY effective_from DESC LIMIT 1",
    ),
);
