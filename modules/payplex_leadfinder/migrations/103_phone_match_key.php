<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or defined('PAYPLEX_LF_PREFLIGHT') or exit('No direct script access allowed');
/**
 * Lead Finder migration 103 — the duplicate keys Conflict 1 needs.
 *
 * APPROVED SCOPE, AND ITS LIMITS
 * ------------------------------
 * Decision 1: add a nullable `phone_match_key` to `tblleads` and the missing
 * unique key to the Lead Finder side; run a read-only preflight first; **do not
 * delete, merge or overwrite existing leads.**
 *
 * So this migration does exactly three things and nothing else:
 *   1. ADD COLUMN `phone_match_key` — nullable, no default, no backfill.
 *   2. ADD a non-unique INDEX on it.
 *   3. ADD a UNIQUE KEY to `tblleadgen_google_leads(google_ref, source_type)`.
 *
 * It writes no lead data. It reads none. A lead that exists before this runs is
 * byte-identical after it.
 *
 * WHY THE COLUMN IS NULLABLE WITH NO BACKFILL
 * -------------------------------------------
 * Backfilling would mean normalising every existing phone number and writing
 * the result — a write to `tblleads` across the whole table, which is precisely
 * what "do not overwrite existing leads" rules out. NULL means "not yet
 * normalised", and the duplicate checker treats NULL as *unknown*, never as
 * *no match*. Backfill is a separate, reversible, reported operation for later,
 * not a side effect of adding a column.
 *
 * WHY THE UNIQUE KEY CAN DECLINE
 * ------------------------------
 * `tblleadgen_google_leads` has no unique key today, so duplicates may already
 * be in it. `ALTER TABLE ... ADD UNIQUE` fails on existing duplicates, and a
 * migration that fails halfway is worse than one that declines cleanly. So the
 * preflight in §2 below must be run FIRST and must return zero, following the
 * in-tree precedent at `leadgen_followup.php:83-94`, which counts duplicate
 * pairs and declines rather than failing.
 *
 * **This migration must not be applied until the preflight reports zero
 * collisions on the unique pair.** The operator checks; nothing here can check
 * for them, and pretending otherwise would be a green tick over an unknown.
 *
 * TOUCHES CORE TABLES — DELIBERATELY, AND THE ONLY ONE THAT DOES
 * --------------------------------------------------------------
 * 101 and 102 declare `touches_core_tables => false`. This one declares TRUE.
 * That flag exists so a reviewer can sort the migration that needs their full
 * attention from the two that do not.
 */
return array(
    'id'          => 103,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Duplicate keys: phone_match_key on leads, unique pair on leadgen_google',
    'destructive' => false,
    'touches_core_tables' => true,
    'depends_on'  => 101,

    /*
     * Read-only. Run these first; every one must return 0 before `up` is
     * applied. They write nothing and lock nothing.
     */
    'preflight' => array(
        'leadgen_google_duplicate_pairs' => array(
            'sql' => "SELECT COUNT(*) AS collisions FROM (
                        SELECT google_ref, source_type
                        FROM `{P}leadgen_google_leads`
                        WHERE google_ref IS NOT NULL AND google_ref <> ''
                        GROUP BY google_ref, source_type
                        HAVING COUNT(*) > 1
                      ) d",
            'must_be'  => 0,
            'if_not'   => 'DO NOT APPLY. Duplicate (google_ref, source_type) pairs already exist, '
                        . 'so ADD UNIQUE will fail. Export the duplicates with the report query '
                        . 'below and decide what to keep. This is a data decision, not a migration step.',
        ),
        'phone_match_key_column_absent' => array(
            'sql' => "SELECT COUNT(*) AS present FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = '{P}leads'
                        AND COLUMN_NAME = 'phone_match_key'",
            'must_be' => 0,
            'if_not'  => 'Column already exists. Applying again is harmless but means an earlier '
                       . 'run partially completed — check the index too before proceeding.',
        ),
    ),

    /*
     * The collision report. Read-only, returns rows rather than a count, and is
     * the artefact the approval asked for. It is listed here rather than in a
     * separate file so it can never drift from the migration it gates.
     */
    'reports' => array(
        'leadgen_google_collisions' =>
            "SELECT google_ref, source_type, COUNT(*) AS copies,
                    GROUP_CONCAT(id ORDER BY id) AS row_ids,
                    GROUP_CONCAT(DISTINCT lead_id ORDER BY lead_id) AS lead_ids
             FROM `{P}leadgen_google_leads`
             WHERE google_ref IS NOT NULL AND google_ref <> ''
             GROUP BY google_ref, source_type
             HAVING COUNT(*) > 1
             ORDER BY copies DESC, google_ref",

        /*
         * Existing duplicate leads by phone, under the NEW normalisation, shown
         * WITHOUT changing anything. This is the number that tells you how bad
         * the exact-string comparison has been. It normalises in SQL only far
         * enough to group — the authoritative normalisation is Payplex_phone,
         * and this is a report, not a write.
         */
        'existing_phone_duplicates' =>
            "SELECT digits, COUNT(*) AS leads, GROUP_CONCAT(id ORDER BY id) AS lead_ids
             FROM (
               SELECT id,
                      RIGHT(REGEXP_REPLACE(COALESCE(phonenumber,''), '[^0-9]', ''), 10) AS digits
               FROM `{P}leads`
               WHERE phonenumber IS NOT NULL AND phonenumber <> ''
             ) x
             WHERE LENGTH(digits) = 10
             GROUP BY digits
             HAVING COUNT(*) > 1
             ORDER BY leads DESC
             LIMIT 500",

        'existing_phone_duplicate_total' =>
            "SELECT COUNT(*) AS duplicate_groups, SUM(c) - COUNT(*) AS redundant_leads
             FROM (
               SELECT RIGHT(REGEXP_REPLACE(COALESCE(phonenumber,''), '[^0-9]', ''), 10) AS digits,
                      COUNT(*) AS c
               FROM `{P}leads`
               WHERE phonenumber IS NOT NULL AND phonenumber <> ''
               GROUP BY digits
               HAVING COUNT(*) > 1 AND LENGTH(digits) = 10
             ) y",
    ),

    'up' => array(
        /* Nullable, no default, no backfill. Adding a nullable column is an
           in-place metadata change on InnoDB and does not rewrite the table. */
        "ALTER TABLE `{P}leads`
            ADD COLUMN `phone_match_key` VARCHAR(24) NULL DEFAULT NULL",

        /* Non-unique. A UNIQUE key here would refuse the legitimate case of two
           contacts at one switchboard, and would turn every future insert into
           a possible hard failure. Duplicate detection is a decision, not a
           constraint — the constraint belongs on the place id, where identity
           really is one-to-one. */
        "ALTER TABLE `{P}leads`
            ADD INDEX `payplex_phone_match_key` (`phone_match_key`)",

        /* The key that should always have been here. Gated by the preflight
           above: apply only when it reports zero. */
        "ALTER TABLE `{P}leadgen_google_leads`
            ADD UNIQUE KEY `leadgen_google_ref_pair` (`google_ref`, `source_type`)",
    ),

    'down' => array(
        "ALTER TABLE `{P}leadgen_google_leads` DROP INDEX `leadgen_google_ref_pair`",
        "ALTER TABLE `{P}leads` DROP INDEX `payplex_phone_match_key`",
        "ALTER TABLE `{P}leads` DROP COLUMN `phone_match_key`",
    ),

    'down_warning' => 'Rollback drops a column this module writes to. No lead row is deleted and '
                    . 'no pre-existing lead field is altered — phone_match_key is derived data and '
                    . 'can be recomputed. Export it first only if you want the normalisation results '
                    . 'preserved for analysis.',
);
