<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');
/**
 * Lead Finder migration 108 — pagination bookkeeping, the simulated-data flag,
 * and the details-fetch ledger.
 *
 * THE FLAG IS THE POINT
 * ---------------------
 * The administrator has no Google key yet, so the search workflow is exercised
 * against a local fixture. A simulated prospect that is indistinguishable from a
 * real one sits in the verification queue looking like a business, and somebody
 * rings it. `is_simulated` makes the distinction a column rather than a habit:
 * the queue can badge it, the reports in Step 9 can exclude it, and the
 * conversion gate in Step 8 can refuse it outright.
 *
 * It defaults to 0, so every row that already exists is correctly recorded as
 * real — there were no simulated rows before this migration, because there was
 * no simulation.
 *
 * WHY DETAIL FETCHES GET THEIR OWN TABLE
 * --------------------------------------
 * `details_fetched_at` on the prospect says a detail call happened. It does not
 * say how many were made, by whom, against which connection, or whether one was
 * refused — which is exactly what an unexpected bill needs to be reconciled
 * against. It is also the only way to prove that the "already held, do not ask
 * again" rule is working rather than merely present.
 *
 * Additive. Two nullable columns on a module table, one new module table, config
 * seeds. No core table is touched, nothing is dropped, and `down` reverses
 * exactly what `up` creates.
 */
return array(
    'id'          => 108,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Pagination, simulated-data marking and the detail-fetch ledger',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 107,

    'up' => array(
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `is_simulated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `dupe_state`",

        "ALTER TABLE `{P}payplex_lf_searches`
           ADD COLUMN `is_simulated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `served_from_cache`",

        "ALTER TABLE `{P}payplex_lf_searches`
           ADD COLUMN `pages_fetched` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `is_simulated`",

        "ALTER TABLE `{P}payplex_lf_searches`
           ADD COLUMN `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `pages_fetched`",

        "CREATE INDEX `lf_simulated` ON `{P}payplex_lf_prospects` (`is_simulated`)",

        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_detail_fetches` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `prospect_id` INT UNSIGNED NOT NULL,
          `staff_id` INT NOT NULL DEFAULT 0,
          `profile_id` INT UNSIGNED NOT NULL,
          `outcome` VARCHAR(32) NOT NULL,
          `http_status` SMALLINT UNSIGNED NULL,
          `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
          `is_simulated` TINYINT(1) NOT NULL DEFAULT 0,
          `error_code` VARCHAR(60) NULL,
          `created_at` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`id`),
          KEY `lf_df_prospect` (`prospect_id`,`created_at`),
          KEY `lf_df_staff` (`staff_id`,`created_at`),
          KEY `lf_df_outcome` (`outcome`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('places_transport','live','Where Places requests go: live sends them to Google, mock answers them from a local fixture. Mock exists so the whole workflow can be exercised before an API key is entered; every record it produces is flagged is_simulated, given a place id in a reserved namespace, and given a phone number in a range that is never allocated to a subscriber. Defaults to live so a simulation can never be the accidental state.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('places_max_attempts','3','Attempts per Places request, including the first. Clamped to 5 in code whatever is entered here: a retry is a billable request on some SKUs, so an outage must not be able to consume a month of allowance in a few seconds.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('places_retry_base_ms','400','First backoff step in milliseconds, doubling each attempt and capped at 8000.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('places_max_pages','3','Maximum pages fetched for one search. Google returns at most 60 results across all pages at 20 per page, so 3 is the documented maximum and each page is a separate billable request.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey IN (
            'places_transport','places_max_attempts','places_retry_base_ms','places_max_pages')",
        "DROP TABLE IF EXISTS `{P}payplex_lf_detail_fetches`",
        "DROP INDEX `lf_simulated` ON `{P}payplex_lf_prospects`",
        "ALTER TABLE `{P}payplex_lf_searches` DROP COLUMN `attempts`",
        "ALTER TABLE `{P}payplex_lf_searches` DROP COLUMN `pages_fetched`",
        "ALTER TABLE `{P}payplex_lf_searches` DROP COLUMN `is_simulated`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `is_simulated`",
    ),

    'down_warning' => 'Rolling back drops is_simulated from the prospect table. Any fixture rows '
                    . 'created while mock mode was on then become indistinguishable from real '
                    . 'businesses in the verification queue, and an employee could call one. '
                    . 'Delete simulated prospects BEFORE rolling this back: they are identifiable '
                    . 'by a google_place_id beginning SIMULATED-NOT-A-REAL-PLACE-. It also drops '
                    . 'the detail-fetch ledger, which is the only per-call record available to '
                    . 'reconcile a Google invoice — export payplex_lf_detail_fetches first. It '
                    . 'holds no key material, only prospect ids, staff ids, outcomes and '
                    . 'timestamps, so the export is safe to keep.',

    'reports' => array(
        "SELECT COUNT(*) AS simulated_prospects FROM `{P}payplex_lf_prospects` WHERE `is_simulated` = 1",
        "SELECT COUNT(*) AS detail_fetch_rows FROM `{P}payplex_lf_detail_fetches`",
        "SELECT cvalue AS places_transport FROM `{P}payplex_lf_config` WHERE ckey='places_transport' ORDER BY effective_from DESC LIMIT 1",
    ),
);
