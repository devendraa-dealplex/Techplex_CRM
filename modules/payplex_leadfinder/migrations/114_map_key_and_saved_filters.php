<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or defined('PAYPLEX_LF_PREFLIGHT') or exit('No direct script access allowed');
/**
 * Lead Finder migration 114 — the browser map credential and saved filters.
 *
 * WHY THERE IS A SECOND KEY COLUMN AT ALL
 * ---------------------------------------
 * Lead Finder needs two different Google credentials and they cannot be the
 * same one.
 *
 *   - The SERVER key calls Places API (New) from PHP. It is restricted by IP,
 *     enabled for Places only, encrypted at rest, and never appears in an HTTP
 *     response. That is `api_key_enc`, added in migration 101.
 *
 *   - The BROWSER key loads the Maps JavaScript API. The loader is a
 *     `<script src="...&key=...">` tag, so the key is in the page source, and
 *     no amount of care changes that — it is how the API works. Its protection
 *     is different in kind: an HTTP-referrer restriction pinned to this exact
 *     host, the Maps JavaScript API and nothing else, and its own billing
 *     budget so that a key scraped off the page cannot spend the Places
 *     allowance.
 *
 * Putting the server key in the page would hand an attacker a Places key with
 * a large budget and no referrer restriction. So the two are stored, restricted
 * and rendered separately, and there is no code path that substitutes one for
 * the other — in particular there is no fallback to the Perfex core
 * `google_api_key`, which is a third key belonging to unrelated features.
 *
 * WHY THE BROWSER KEY IS STILL ENCRYPTED AT REST
 * ----------------------------------------------
 * It is going to be published in a page, so encrypting it looks like theatre.
 * It is not, for one reason: the database is backed up, exported and read by
 * more people than the page is. Encrypting it keeps it out of a mysqldump
 * sitting in somebody's downloads folder. It is the same treatment for a
 * cheaper secret, not a claim that it is confidential once rendered.
 *
 * BOTH COLUMNS SHIP EMPTY
 * -----------------------
 * Neither key is seeded. An administrator enters each through the CRM interface
 * after creating it with the correct restriction. A migration that carried a
 * key would put it in the repository, the deployment archive and every copy of
 * both.
 *
 * SAVED FILTERS
 * -------------
 * A saved filter belongs to one staff member, is capped at twenty, and stores
 * only the normalised filter values — never a result set, never a row id.
 * Sharing them was considered and dropped: a shared filter that silently spans
 * another employee's territory is an ownership leak wearing a convenience
 * costume, and the queue's scope rules would have to be re-checked on every
 * read of somebody else's saved filter anyway.
 *
 * SCOPE
 * -----
 * Additive. One new module-owned table, columns on the module's own
 * `payplex_lf_api_profiles`, config seeds. No core table is touched, nothing is
 * dropped, nothing is backfilled, and `down` reverses exactly what `up` adds.
 */
return array(
    'id'          => 114,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Browser map key storage and per-staff saved filters',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 113,

    'preflight' => array(
        'saved_filters_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_lf_saved_filters'",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. The table already exists and IF NOT EXISTS would skip it, '
                       . 'leaving a table of unknown shape behind a migration that claims to have made it.',
        ),
        'map_key_columns_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_lf_api_profiles'
                            AND COLUMN_NAME IN ('browser_map_key_enc','browser_map_key_fingerprint',
                                                'browser_map_referrers','browser_map_key_set_at',
                                                'browser_map_key_set_by')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. A browser map key column already exists. ADD COLUMN is not '
                       . 'idempotent and a partial re-run would abort mid-ALTER.',
        ),
        'server_key_column_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_lf_api_profiles'
                            AND COLUMN_NAME = 'api_key_enc'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 101 has not run against this database.',
        ),
        'no_plaintext_key_column' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_lf_api_profiles'
                            AND COLUMN_NAME IN ('api_key','browser_map_key','google_api_key')",
            'must_be' => 0,
            'if_not'  => 'STOP AND REPORT. A plaintext key column exists on this table. That is a live '
                       . 'credential exposure and it is not something this migration should paper over. '
                       . 'Export nothing, tell the administrator, and deal with the column before '
                       . 'continuing the chain.',
        ),
        'config_keys_free' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM `{P}payplex_lf_config`
                          WHERE ckey IN ('map_provider','map_default_zoom','map_cluster_min_markers',
                                         'list_page_size_default','browser_map_key_restriction',
                                         'server_places_key_restriction','saved_filters_max_per_staff')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. A config key 114 seeds is already present.',
        ),
    ),

    'reports' => array(
        'profiles_configured' => "SELECT COUNT(*) AS profiles,
                                         SUM(CASE WHEN `api_key_enc` IS NOT NULL AND `api_key_enc` <> '' THEN 1 ELSE 0 END) AS with_server_key,
                                         SUM(CASE WHEN `active` = 1 THEN 1 ELSE 0 END) AS active_profiles
                                  FROM `{P}payplex_lf_api_profiles`",
        'profile_names'       => "SELECT `id`, `name`, `active`, `expires_on` FROM `{P}payplex_lf_api_profiles` ORDER BY `id`",
    ),

    'up' => array(
        /* Ciphertext, same treatment as the server key. See the header for why
           a key destined for a page is still encrypted in the database. */
        "ALTER TABLE `{P}payplex_lf_api_profiles`
           ADD COLUMN `browser_map_key_enc` TEXT NULL",

        /* A short digest, so an administrator can confirm which key is in place
           and whether it changed, without the interface ever displaying the
           key. Every screen that discusses a credential shows this and a
           length; none of them shows a value. */
        "ALTER TABLE `{P}payplex_lf_api_profiles`
           ADD COLUMN `browser_map_key_fingerprint` VARCHAR(24) NULL",

        /* The referrer patterns this key is supposed to be restricted to, as
           the administrator entered them in Google Cloud Console. Recorded so
           the settings screen can state the intended restriction next to the
           key. It is a note, not an enforcement point: only Google can enforce
           a referrer restriction, and a column that looked like enforcement
           would be worse than no column. */
        "ALTER TABLE `{P}payplex_lf_api_profiles`
           ADD COLUMN `browser_map_referrers` VARCHAR(500) NULL",

        "ALTER TABLE `{P}payplex_lf_api_profiles`
           ADD COLUMN `browser_map_key_set_at` INT UNSIGNED NULL",

        "ALTER TABLE `{P}payplex_lf_api_profiles`
           ADD COLUMN `browser_map_key_set_by` INT NOT NULL DEFAULT 0",

        /*
         * Saved filters.
         *
         * The unique key is (staff, name): one employee cannot have two filters
         * called "Pune dentists", and two employees can each have one. The
         * payload is JSON of normalised filter values only — it is re-validated
         * through Leadfinder_ui::normaliseFilters() on read, because a row
         * written by an older version of the code is untrusted input by the
         * time a newer version reads it.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_saved_filters` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `staff_id` INT NOT NULL,
          `name` VARCHAR(60) NOT NULL,
          `payload` TEXT NOT NULL,
          `created_at` INT UNSIGNED NOT NULL,
          `updated_at` INT UNSIGNED NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `lf_saved_owner_name` (`staff_id`,`name`),
          KEY `lf_saved_staff` (`staff_id`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('map_provider','google','Places results are displayed on a Google map. This is a term of the Places API, not a preference: results obtained from Places may not be drawn on another provider basemap, and the attribution must stay visible. Leadfinder_retention::rendersNonGoogleMap() returns false and this key records why.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('map_default_zoom','12','Opening zoom for a city-level search. Operational default; the map fits to the result bounds as soon as there are results, so this is only what an empty map shows.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('map_cluster_min_markers','8','Below this many markers, clustering hides more than it helps: the user loses the ability to click the one pin they came for. A selected marker is never clustered at any count.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('list_page_size_default','25','Rows per page on the results list. Paging is server-side; the offered sizes are 25, 50 and 100 and anything else is refused rather than clamped.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('browser_map_key_restriction','http_referrer','The restriction the browser map key MUST carry in Google Cloud Console: HTTP referrer, pinned to this host, Maps JavaScript API only, on its own billing budget. The key is rendered into the page by design and this restriction is the only thing standing between that and somebody else spending the budget. Recorded here so the settings screen can state the requirement and an audit can check it was stated.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('server_places_key_restriction','ip','The restriction the server Places key MUST carry: IP address, limited to the application server, Places API (New) only. This key is never rendered into a page, never logged and never returned by an endpoint.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('saved_filters_max_per_staff','20','How many saved filters one employee may keep. A cap exists because the list is rendered in a menu and an uncapped per-user list is a slow query somebody else pays for.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey IN (
            'map_provider','map_default_zoom','map_cluster_min_markers','list_page_size_default',
            'browser_map_key_restriction','server_places_key_restriction','saved_filters_max_per_staff')",

        "DROP TABLE IF EXISTS `{P}payplex_lf_saved_filters`",

        "ALTER TABLE `{P}payplex_lf_api_profiles` DROP COLUMN `browser_map_key_set_by`",
        "ALTER TABLE `{P}payplex_lf_api_profiles` DROP COLUMN `browser_map_key_set_at`",
        "ALTER TABLE `{P}payplex_lf_api_profiles` DROP COLUMN `browser_map_referrers`",
        "ALTER TABLE `{P}payplex_lf_api_profiles` DROP COLUMN `browser_map_key_fingerprint`",
        "ALTER TABLE `{P}payplex_lf_api_profiles` DROP COLUMN `browser_map_key_enc`",
    ),

    'down_warning' => 'Rolling back drops every saved filter and discards the stored browser map key. '
                    . 'The map stops rendering until an administrator enters the key again; the server '
                    . 'Places key is untouched, so searching still works and the results list still shows '
                    . 'them. Export payplex_lf_saved_filters first if the filters matter. Do not attempt '
                    . 'to recover the browser key from a database export — re-enter it from Google Cloud '
                    . 'Console, and take the rollback as the moment to confirm its referrer restriction '
                    . 'is still correct.',
);
