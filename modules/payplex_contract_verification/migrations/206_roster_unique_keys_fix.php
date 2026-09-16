<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT')
    or defined('PAYPLEX_CV_MIGRATION_TEST') or exit('No direct script access allowed');

/**
 * 206 — correct the roster unique keys, which as shipped in 205 made
 * replacement impossible.
 *
 * WHAT WENT WRONG
 * ---------------
 * 205 added:
 *
 *     UNIQUE KEY cv_roster_slot  (contract_id, signing_order)
 *     UNIQUE KEY cv_roster_email (contract_id, email)
 *
 * The intent was right — one signer per slot, one address per contract, enforced
 * by the database so the application's own check could not be raced.
 *
 * The mistake is that a REPLACED signer keeps their row, and keeps their slot
 * and their address with it. So when a replacement tried to take slot 1, the
 * INSERT hit the unique key and the whole replacement transaction rolled back.
 * The application-level duplicate check skips replaced rows and was perfectly
 * happy; the constraint did not, and disagreed silently.
 *
 * Observed on staging: a replacement produced no new row and no audit entry at
 * all, while the screen reported nothing useful.
 *
 * THE FIX
 * -------
 * `replaced_at` becomes NOT NULL DEFAULT 0 — 0 meaning "live" — and the unique
 * keys include it:
 *
 *     UNIQUE (contract_id, signing_order, replaced_at)
 *     UNIQUE (contract_id, email,         replaced_at)
 *
 * Live rows all carry 0, so there is still exactly ONE live signer per slot and
 * ONE live row per address. Replaced rows carry their replacement timestamp, so
 * any number of them can share a slot without colliding.
 *
 * A NULL-based scheme was considered and rejected: MySQL treats NULLs as
 * distinct inside a unique index, so live rows holding NULL would not have been
 * constrained at all — the key would have looked correct and enforced nothing,
 * which is worse than the bug it replaced.
 *
 * RESIDUAL EDGE CASE, STATED RATHER THAN HIDDEN
 * ---------------------------------------------
 * Two signers replaced in the SAME slot within the SAME second would collide on
 * the new key. That requires two replacements of the same slot inside one
 * second by different operators. It fails closed — the second replacement is
 * refused rather than silently overwriting — and it is recorded here so nobody
 * has to rediscover it.
 */

return array(

    'id'          => '206',
    'description' => 'Roster unique keys include replacement state, so replacement can reuse a slot',

    'preflight' => array(

        'roster_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_contract_signers'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 205 has not run, so there are no keys to correct. '
                       . 'This check is also the control: it must return 1, so a predicate that has '
                       . 'stopped matching anything fails the gate instead of passing it.',
        ),

        'old_slot_key_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.STATISTICS
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_contract_signers'
                            AND INDEX_NAME = 'cv_roster_slot'",
            'must_be' => 2,
            'if_not'  => 'DO NOT APPLY. The old two-column slot key is not present in the shape 205 '
                       . 'created (contract_id + signing_order = 2 index rows). Either 206 has '
                       . 'already run, or the table is not the one this migration was written for.',
        ),

        'no_live_slot_conflicts' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM (
                            SELECT contract_id, signing_order
                            FROM `{P}payplex_cv_contract_signers`
                            WHERE replaced_at IS NULL OR replaced_at = 0
                            GROUP BY contract_id, signing_order
                            HAVING COUNT(*) > 1
                          ) AS conflicts",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. Two or more LIVE signers already share a slot on some '
                       . 'contract, so the corrected unique key would be rejected. Resolve those '
                       . 'rows first — do not drop the key to make the migration pass.',
        ),
    ),

    'reports' => array(
        'roster_rows'    => "SELECT COUNT(*) AS rows_before FROM `{P}payplex_cv_contract_signers`",
        'replaced_rows'  => "SELECT COUNT(*) AS replaced_before FROM `{P}payplex_cv_contract_signers`
                             WHERE replaced_at IS NOT NULL AND replaced_at > 0",
    ),

    'up' => array(
        /* Existing live rows hold NULL; they must become 0 before the column
           can be NOT NULL and before the new key can be added. */
        "UPDATE `{P}payplex_cv_contract_signers` SET `replaced_at` = 0 WHERE `replaced_at` IS NULL",

        "ALTER TABLE `{P}payplex_cv_contract_signers`
           MODIFY `replaced_at` INT UNSIGNED NOT NULL DEFAULT 0",

        "ALTER TABLE `{P}payplex_cv_contract_signers` DROP INDEX `cv_roster_slot`",
        "ALTER TABLE `{P}payplex_cv_contract_signers` DROP INDEX `cv_roster_email`",

        "ALTER TABLE `{P}payplex_cv_contract_signers`
           ADD UNIQUE KEY `cv_roster_slot` (`contract_id`,`signing_order`,`replaced_at`)",

        "ALTER TABLE `{P}payplex_cv_contract_signers`
           ADD UNIQUE KEY `cv_roster_email` (`contract_id`,`email`,`replaced_at`)",
    ),

    'down' => array(
        /*
         * Reverting restores the broken constraint, so it is offered only for
         * completeness. Anything replaced while 206 was in force may then
         * conflict — which is precisely the defect 206 exists to remove.
         */
        "ALTER TABLE `{P}payplex_cv_contract_signers` DROP INDEX `cv_roster_slot`",
        "ALTER TABLE `{P}payplex_cv_contract_signers` DROP INDEX `cv_roster_email`",
        "ALTER TABLE `{P}payplex_cv_contract_signers`
           ADD UNIQUE KEY `cv_roster_slot` (`contract_id`,`signing_order`)",
        "ALTER TABLE `{P}payplex_cv_contract_signers`
           ADD UNIQUE KEY `cv_roster_email` (`contract_id`,`email`)",
    ),
);
