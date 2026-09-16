<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');
/**
 * Lead Finder migration 107 — the counters a ceiling can actually be enforced
 * against, the reservation ledger that makes a retry free, and the alert log
 * that fires each band once.
 *
 * WHY THE EXISTING USAGE TABLE IS NOT ENOUGH
 * ------------------------------------------
 * `payplex_lf_usage` holds one row per (profile, staff, day, class). A daily
 * profile ceiling can be checked against a row. A *monthly* one cannot: it is a
 * SUM across up to 31 rows, and you cannot take a row lock on a sum. Neither can
 * an installation-wide ceiling, which spans every profile.
 *
 * That is why the current code checks and then increments: there was nothing to
 * lock. `SELECT SUM(calls) … ; INSERT … ON DUPLICATE KEY UPDATE` is two
 * statements, and between them another request reads the same total. The
 * increment is atomic; the ceiling is not.
 *
 * `payplex_lf_quota_counters` gives every ceiling — installation, profile and
 * employee, daily and monthly — exactly one row to lock. `SELECT … FOR UPDATE`
 * on that row, compare, increment, commit. The comparison and the increment are
 * then the same critical section, which is the only arrangement that actually
 * caps spending.
 *
 * `payplex_lf_usage` is NOT dropped or migrated away. It stays the per-day audit
 * detail and the reporting source for Step 9; the counters table is the
 * enforcement surface. Two tables with different jobs, both written in the same
 * transaction.
 *
 * WHY A RESERVATION LEDGER
 * ------------------------
 * "Idempotency keys" in the brief. Without one, a retried search — a double
 * click, a browser retry, a pagination request replayed after a timeout —
 * spends a second unit for a request the employee made once. The ledger makes
 * the key the unit of spend: same key, same reservation, no second deduction.
 * It also records which counters a reservation moved, so a release gives back
 * exactly what was taken rather than guessing.
 *
 * WHY AN ALERT LOG WITH A UNIQUE KEY
 * ----------------------------------
 * An alert that fires whenever usage is above 80% fires on every request for the
 * rest of the month. The recipient filters it, and then the 100% alert is
 * filtered too. The UNIQUE key on (scope, scope_id, period, class, band) is what
 * makes "once per band per period" a property of the database rather than a
 * promise in the code.
 *
 * Additive. Three new module-owned tables and config seeds. No core table is
 * touched, nothing is dropped, no existing column changes, and `down` reverses
 * exactly what `up` creates.
 */
return array(
    'id'          => 107,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Atomic quota counters, reservation ledger and alert log',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 106,

    'up' => array(
        /*
         * One row per ceiling. `used` is the only mutable column; everything
         * else is identity.
         *
         * The UNIQUE key is what `INSERT IGNORE` uses to create the row exactly
         * once under concurrency, and what `SELECT … FOR UPDATE` uses to take a
         * single-row lock rather than a gap or a table lock.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_quota_counters` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `scope` VARCHAR(16) NOT NULL,
          `scope_id` INT NOT NULL DEFAULT 0,
          `period_kind` VARCHAR(8) NOT NULL,
          `period_key` VARCHAR(10) NOT NULL,
          `request_class` VARCHAR(16) NOT NULL,
          `used` INT UNSIGNED NOT NULL DEFAULT 0,
          `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `lf_qc_slot` (`scope`,`scope_id`,`period_kind`,`period_key`,`request_class`),
          KEY `lf_qc_period` (`period_kind`,`period_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /*
         * `idem_key` is UNIQUE, and that constraint is the whole idempotency
         * guarantee. A retry inserts nothing, finds the existing row, and is
         * answered from it.
         *
         * `counters` holds the lock keys this reservation incremented, as a
         * newline-separated list. A release decrements exactly those, so a
         * ceiling that was added or removed between the reserve and the release
         * cannot cause a counter to be given back a unit it never took.
         *
         * `state`: held | committed | released.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_reservations` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `idem_key` CHAR(64) NOT NULL,
          `profile_id` INT UNSIGNED NOT NULL,
          `staff_id` INT NOT NULL DEFAULT 0,
          `request_class` VARCHAR(16) NOT NULL,
          `units` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
          `state` VARCHAR(12) NOT NULL DEFAULT 'held',
          `counters` TEXT NULL,
          `http_status` SMALLINT UNSIGNED NULL,
          `created_at` INT UNSIGNED NOT NULL,
          `settled_at` INT UNSIGNED NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `lf_res_idem` (`idem_key`),
          KEY `lf_res_profile` (`profile_id`,`created_at`),
          KEY `lf_res_staff` (`staff_id`,`created_at`),
          KEY `lf_res_state` (`state`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_quota_alerts` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `scope` VARCHAR(16) NOT NULL,
          `scope_id` INT NOT NULL DEFAULT 0,
          `period_kind` VARCHAR(8) NOT NULL,
          `period_key` VARCHAR(10) NOT NULL,
          `request_class` VARCHAR(16) NOT NULL,
          `band` SMALLINT UNSIGNED NOT NULL,
          `used_at_raise` INT UNSIGNED NOT NULL DEFAULT 0,
          `limit_at_raise` INT UNSIGNED NOT NULL DEFAULT 0,
          `raised_at` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `lf_qa_once` (`scope`,`scope_id`,`period_kind`,`period_key`,`request_class`,`band`),
          KEY `lf_qa_when` (`raised_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /*
         * Installation-wide ceilings. Seeded at 0 — "no ceiling at this scope" —
         * because inventing an installation limit would be inventing a business
         * decision. The profile's monthly limit is the mandatory one, and it is
         * already enforced.
         */
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('global_daily_search_limit','0','Installation-wide daily ceiling on search calls across every API connection. 0 means no ceiling at this scope, which is safe because each connection still carries a mandatory monthly ceiling of its own.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('global_daily_contact_limit','0','Installation-wide daily ceiling on detail (contact) calls across every API connection. 0 means no ceiling at this scope.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('global_monthly_search_limit','0','Installation-wide monthly ceiling on search calls. 0 means no ceiling at this scope.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('global_monthly_contact_limit','0','Installation-wide monthly ceiling on detail (contact) calls. 0 means no ceiling at this scope.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('default_staff_search_limit_daily','0','Per-employee daily ceiling on SEARCH calls. Separate from the detail ceiling on purpose: before this existed, a search request was measured against default_staff_detail_limit_daily, so an employee who had used their detail allowance could not search either. 0 means no ceiling at this scope.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('staff_monthly_detail_limit','0','Per-employee monthly ceiling on detail (contact) calls. 0 means no ceiling at this scope.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('staff_monthly_search_limit','0','Per-employee monthly ceiling on search calls. 0 means no ceiling at this scope.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        /*
         * Rates, in the smallest currency unit, 0 = not configured.
         *
         * Google's list prices are NOT seeded here. They change, this
         * environment cannot read them, and a stale rate displayed on a screen
         * labelled "estimated cost" would be believed. Until an administrator
         * enters the rate their own contract gives them, the screen says the
         * rate is not configured rather than showing a number.
         */
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('rate_minor_units_search','0','Cost of one search call in the smallest currency unit (paise). 0 means not configured, and the estimated-cost display then says so rather than showing zero. Deliberately not seeded with a Google list price: prices change and a wrong number on a spending screen is worse than no number.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('rate_minor_units_contact','0','Cost of one detail (contact) call in the smallest currency unit (paise). 0 means not configured.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('quota_currency','INR','Currency label for the estimated-cost display. A label only: no conversion is performed anywhere, and the rates are entered in this currency.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('reservation_stale_seconds','900','How long a held reservation may sit unsettled before the sweeper releases it. A process that dies between reserving and calling Google would otherwise hold that unit until the period rolls over. 900s is long enough that a slow Google call is never reclaimed underneath itself.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey IN (
            'global_daily_search_limit','global_daily_contact_limit',
            'global_monthly_search_limit','global_monthly_contact_limit',
            'default_staff_search_limit_daily',
            'staff_monthly_detail_limit','staff_monthly_search_limit',
            'rate_minor_units_search','rate_minor_units_contact','quota_currency',
            'reservation_stale_seconds')",
        "DROP TABLE IF EXISTS `{P}payplex_lf_quota_alerts`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_reservations`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_quota_counters`",
    ),

    'down_warning' => 'Rolling back removes the enforcement counters, so ceilings fall back to the '
                    . 'pre-107 check-then-increment path, which cannot cap spending under concurrent '
                    . 'requests. It also drops the reservation ledger, after which a retried request '
                    . 'spends a second unit. Export payplex_lf_quota_counters and '
                    . 'payplex_lf_reservations first — neither holds key material, only counts, '
                    . 'timestamps and staff ids. payplex_lf_usage is untouched by this rollback and '
                    . 'retains the per-day audit detail.',

    'reports' => array(
        "SELECT COUNT(*) AS counter_rows FROM `{P}payplex_lf_quota_counters`",
        "SELECT COUNT(*) AS reservation_rows FROM `{P}payplex_lf_reservations`",
        "SELECT COUNT(*) AS alert_rows FROM `{P}payplex_lf_quota_alerts`",
        "SELECT COUNT(*) AS new_config_rows FROM `{P}payplex_lf_config` WHERE ckey IN ('global_daily_search_limit','rate_minor_units_search','reservation_stale_seconds')",
    ),
);
