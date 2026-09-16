<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');
/**
 * Lead Finder migration 109 — source references, the duplicate verdict as data,
 * and the indexes a paged queue needs.
 *
 * WHY `source_ref`
 * ----------------
 * The duplicate rules name "source reference" first, and there was no column to
 * put one in. A prospect that arrives from somewhere other than a Places search
 * — a Facebook lead, an import, a prior CRM record — carries an id from that
 * system, and two records with the same one are the same record arriving twice.
 * Without the column, the only identity available was the Google place id, which
 * a non-Google record does not have, so every import looked new.
 *
 * NULL means "this did not come from a referenced source". It is not a value
 * that matches other NULLs, and the comparison enforces that: treating unknown
 * as a match would merge every imported record with every other one.
 *
 * WHY THE VERDICT IS STORED, NOT JUST THE STATE
 * ---------------------------------------------
 * `dupe_state` said *what* the classifier concluded. It did not say *which*
 * record it matched or *why*, so "possible duplicate" was a badge with nothing
 * behind it — an employee could see the warning and had no way to act on it.
 * `dupe_matched_id` and `dupe_keys` make the verdict inspectable: this row,
 * matched on these keys.
 *
 * WHY THE INDEXES
 * ---------------
 * The queue was `LIMIT 500` with no offset, so it never sorted more than one
 * page. Paging it means `ORDER BY` over the whole scoped set on every page
 * load, which without an index is a filesort of the entire queue each time.
 *
 * Additive. Three nullable columns, three indexes, config seeds. No core table
 * is touched, nothing is dropped, and `down` reverses exactly what `up` creates.
 */
return array(
    'id'          => 109,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Source references, duplicate evidence and queue pagination indexes',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 108,

    'up' => array(
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `source_ref` VARCHAR(190) NULL AFTER `google_place_id`",

        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `source_system` VARCHAR(40) NULL AFTER `source_ref`",

        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `dupe_matched_id` INT UNSIGNED NULL AFTER `dupe_state`",

        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `dupe_keys` VARCHAR(255) NULL AFTER `dupe_matched_id`",

        /*
         * NOT a UNIQUE key.
         *
         * A source reference is unique per source system, not globally: two
         * different systems can both issue "1001". Making this unique would
         * refuse a legitimate import the first time two systems collided, and
         * the failure would look like a duplicate-detection success.
         */
        "CREATE INDEX `lf_source_ref` ON `{P}payplex_lf_prospects` (`source_system`,`source_ref`)",

        "CREATE INDEX `lf_queue_page` ON `{P}payplex_lf_prospects` (`status`,`created_at`,`id`)",

        "CREATE INDEX `lf_owner_page` ON `{P}payplex_lf_prospects` (`claimed_by`,`created_at`,`id`)",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('shared_switchboard_numbers','','Numbers that several businesses legitimately share — a business park reception line, a franchise national number. Comma, semicolon or newline separated, in E.164. A match on one of these is downgraded from an exact duplicate to a possible one, because two tenants answering the same switchboard are two businesses. Empty by default: the list is site-specific and inventing entries would suppress real duplicates.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('shared_phone_business_threshold','3','How many DISTINCT businesses must already carry a number before it is treated as a shared switchboard automatically. Catches the lines nobody has put on the list. Counts distinct businesses rather than rows, because ten rows for one business is a different problem and must not suppress the match that should fire. 0 disables the automatic rule and leaves only the explicit list.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('queue_default_per_page','50','Rows per page in the Prospect Verification Queue. Clamped to 10-200 in code. Before this the queue was a hard LIMIT 500 with no offset, so beyond 500 rows the rest of the queue could not be reached at all.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('bulk_action_max_rows','200','Largest number of prospects one bulk action may touch. Every row is still authorised individually; this bounds the request, not the rules.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey IN (
            'shared_switchboard_numbers','shared_phone_business_threshold',
            'queue_default_per_page','bulk_action_max_rows')",
        "DROP INDEX `lf_owner_page` ON `{P}payplex_lf_prospects`",
        "DROP INDEX `lf_queue_page` ON `{P}payplex_lf_prospects`",
        "DROP INDEX `lf_source_ref` ON `{P}payplex_lf_prospects`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `dupe_keys`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `dupe_matched_id`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `source_system`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `source_ref`",
    ),

    'down_warning' => 'Rolling back drops source_ref, so any prospect imported from another '
                    . 'system loses the only identity that distinguishes it from a new record, '
                    . 'and a re-import will create a second copy. It also drops the stored '
                    . 'duplicate evidence, after which a prospect badged "possible duplicate" no '
                    . 'longer says what it matched. Export payplex_lf_prospects first — it holds '
                    . 'no key material, but it does hold business contact details, so keep the '
                    . 'export where customer data is kept.',

    'reports' => array(
        "SELECT COUNT(*) AS prospects_with_source_ref FROM `{P}payplex_lf_prospects` WHERE `source_ref` IS NOT NULL",
        "SELECT dupe_state, COUNT(*) AS c FROM `{P}payplex_lf_prospects` GROUP BY dupe_state",
        "SELECT cvalue AS shared_phone_threshold FROM `{P}payplex_lf_config` WHERE ckey='shared_phone_business_threshold' ORDER BY effective_from DESC LIMIT 1",
    ),
);
