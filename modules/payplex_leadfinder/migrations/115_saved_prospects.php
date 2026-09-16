<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or defined('PAYPLEX_LF_PREFLIGHT') or exit('No direct script access allowed');
/**
 * Lead Finder migration 115 — the Save shortlist.
 *
 * WHAT "SAVE" ACTUALLY IS, AND WHAT IT IS NOT
 * -------------------------------------------
 * The requirement is that pressing Save on a search result must not create a
 * CRM lead. It does not, and it never could: `tblleads` is written in exactly
 * one place in this module — the approved conversion — and that path needs a
 * verification record, a submission and a second person's approval.
 *
 * So Save is the step before any of that. A search already writes its results
 * into the module's own prospect queue, which is a shared pool: sixty results
 * belonging to nobody. Save is an employee saying "these are the ones I am
 * working", and it is deliberately weaker than Claim:
 *
 *   - Save is a bookmark. Several employees may save the same prospect, because
 *     two people shortlisting the same business is not a conflict — it is two
 *     people who have not spoken yet.
 *   - Claim is exclusive. One employee holds the prospect and the others are
 *     refused.
 *
 * Collapsing them into one action was the alternative, and it is worse in the
 * direction that costs money: if Save claimed, then skimming a result list
 * would silently lock sixty businesses to whoever looked first.
 *
 * WHY IT IS ITS OWN TABLE
 * -----------------------
 * Because it is per-employee. A `saved_by` column on the prospect can hold one
 * staff id, which forces exactly the exclusivity Save is supposed not to have,
 * and the first time two people save the same row one of them silently loses
 * their shortlist entry.
 *
 * SCOPE
 * -----
 * Additive. One new module-owned table. No core table is touched, nothing is
 * dropped, nothing is backfilled, and `down` reverses exactly what `up`
 * creates.
 */
return array(
    'id'          => 115,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Per-employee Save shortlist',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 114,

    'preflight' => array(
        'table_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_lf_saved_prospects'",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. The table already exists and IF NOT EXISTS would skip it silently.',
        ),
        'prospects_table_exists' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_lf_prospects'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 101 has not run against this database.',
        ),
    ),

    'reports' => array(
        'queue_pool_size' => "SELECT COUNT(*) AS unclaimed FROM `{P}payplex_lf_prospects`
                              WHERE `claimed_by` = 0",
    ),

    'up' => array(
        /*
         * No foreign key, deliberately — this module carries none, because a
         * cascade here would delete a shortlist entry as a side effect of an
         * administrative cleanup and the employee would never learn their list
         * had changed. Orphans are swept by the same retention job that clears
         * the prospect, where the removal is recorded.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_saved_prospects` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `prospect_id` INT UNSIGNED NOT NULL,
          `staff_id` INT NOT NULL,
          `saved_at` INT UNSIGNED NOT NULL,
          `note` VARCHAR(255) NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `lf_saved_pair` (`staff_id`,`prospect_id`),
          KEY `lf_saved_prospect` (`prospect_id`),
          KEY `lf_saved_recent` (`staff_id`,`saved_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('save_bulk_max','100','Results one bulk Save may cover. Matched to the bulk waste cap so both buttons behave the same way; a list action that silently handles a different number of rows than the one beside it is how somebody learns the hard way which is which.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey IN ('save_bulk_max')",
        "DROP TABLE IF EXISTS `{P}payplex_lf_saved_prospects`",
    ),

    'down_warning' => 'Rolling back drops every employee shortlist. The prospects themselves are '
                    . 'untouched and stay in the queue, so nothing is lost except who had marked what '
                    . 'as theirs to work — which will look to each employee as though their list was '
                    . 'emptied overnight. Export payplex_lf_saved_prospects first and tell the team.',
);
