<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or defined('PAYPLEX_LF_PREFLIGHT') or exit('No direct script access allowed');
/**
 * Lead Finder migration 112 — the waste decision, recorded on the prospect.
 *
 * WHAT THIS ADDS AND WHY IT LIVES HERE
 * ------------------------------------
 * Marking a prospect as waste is a judgement somebody made, at a time, for a
 * stated reason, and — for a bounded window — can take back. Every one of those
 * four facts has to survive on the row itself, because the row is what an
 * employee is looking at when they ask "who killed this and why".
 *
 * The alternative design was a separate decisions table joined on every list
 * query. Rejected: the search screen filters on "not waste" on every page load,
 * and a filter that needs a join to answer the commonest question on the
 * commonest screen is a filter that will eventually be got wrong.
 *
 * WHY THE UNDO DEADLINE IS STORED, NOT COMPUTED
 * ---------------------------------------------
 * `undo_deadline` is written when the waste decision is taken, rather than
 * derived at read time from `wasted_at` plus the configured window. If the
 * window is later shortened, a decision taken under the old window keeps the
 * window it was taken under. Computing it at read time silently retracts an
 * undo right somebody already had — and it does so invisibly, which is worse
 * than doing it loudly.
 *
 * WHY `review_flag` IS NOT A WASTE STATE
 * --------------------------------------
 * The specification is explicit that an AI score may annotate but must never
 * remove. So the advisory path gets its own column and never touches
 * `waste_reason`: a row can be flagged `Review Required — Possible Waste` and
 * still be worked normally. Only a deterministic rule or a person writes
 * `waste_reason`. Keeping them in one column would have made "suggested" and
 * "decided" indistinguishable after the fact.
 *
 * WHY `suppression_kind` IS HERE AS WELL AS ON THE TOMBSTONE
 * ---------------------------------------------------------
 * Do Not Contact is not a kind of waste. It is a separate, permanent policy
 * that happens to share the same removal mechanics. Recording the kind on the
 * prospect means the queue can refuse a DNC row the ordinary waste treatment
 * (expiry, undo, deletion) without joining to the suppression table, and
 * without anybody having to remember that `do_not_contact` is special.
 *
 * SCOPE
 * -----
 * Additive. Columns and indexes on `payplex_lf_prospects`, which this module
 * owns, plus config seeds. No core table is touched — `tblleads` is not read,
 * written or named. Nothing is dropped, nothing is backfilled, and `down`
 * reverses exactly what `up` adds.
 */
return array(
    'id'          => 112,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Waste decision, undo window and advisory review flag',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 111,

    /*
     * Read-only preflight. Run with:
     *   php migrations/preflight.php --migration=112 --dsn=... --user=...
     *
     * Every one of these must come back as stated before the ALTERs are
     * applied. They exist because ADD COLUMN fails outright on a name that is
     * already there, and a migration that half-applies against a table this
     * size is a worse morning than one that refused to start.
     */
    'preflight' => array(
        'prospects_table_exists' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_lf_prospects'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 101 has not run, or the prefix is wrong. '
                       . 'Applying 112 first would fail on a missing table and leave the chain in an unknown state.',
        ),
        'waste_columns_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_lf_prospects'
                            AND COLUMN_NAME IN ('waste_reason','waste_notes','waste_source','waste_rule',
                                                'suppression_kind','wasted_at','wasted_by','undo_deadline',
                                                'undone_at','undone_by','review_flag','review_rule',
                                                'pii_purged_at','tombstone_id')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. One or more of the columns 112 adds already exists. '
                       . 'Establish whether 112 was partially applied before, and reconcile by hand. '
                       . 'Re-running is not safe: ADD COLUMN is not idempotent.',
        ),
        'index_names_free' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.STATISTICS
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_lf_prospects'
                            AND INDEX_NAME IN ('lf_waste_state','lf_undo_deadline','lf_review_flag','lf_pii_purge')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. An index name 112 uses is already taken on this table.',
        ),
        'config_keys_free' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM `{P}payplex_lf_config`
                          WHERE ckey IN ('waste_undo_window_seconds','auto_waste_enabled',
                                         'auto_waste_ai_advisory_only','waste_bulk_max')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. A config key 112 seeds is already present. A second INSERT would '
                       . 'give the same key two rows and the reader would pick one arbitrarily.',
        ),
    ),

    /*
     * Read-only reports. These do not gate anything; they tell the person
     * applying the migration what they are about to change the shape of.
     */
    'reports' => array(
        'prospects_by_status' => "SELECT `status`, COUNT(*) AS rows_now
                                  FROM `{P}payplex_lf_prospects` GROUP BY `status` ORDER BY rows_now DESC",
        'existing_rejections' => "SELECT COUNT(*) AS already_rejected
                                  FROM `{P}payplex_lf_prospects`
                                  WHERE `rejection_reason` IS NOT NULL AND `rejection_reason` <> ''",
        'prospects_total'     => "SELECT COUNT(*) AS total FROM `{P}payplex_lf_prospects`",
    ),

    'up' => array(
        /* The decision itself. Nullable throughout: a row that has never been
           wasted holds NULL, not a sentinel, so "was this ever wasted" is
           answerable without knowing which sentinel was chosen. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `waste_reason` VARCHAR(40) NULL",

        /* Free text, bounded, and required only for the `other` reason. The
           length matches Leadfinder_waste::NOTES_MAX; a longer note would be
           silently truncated by MySQL in a non-strict configuration, which is
           how a stated justification quietly becomes half a sentence. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `waste_notes` VARCHAR(500) NULL",

        /* 'manual' or 'automatic'. Kept apart from the reason because the
           question "did a person decide this" has to be answerable later, and
           after an automatic decision is undone and re-taken by hand the reason
           alone no longer says. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `waste_source` VARCHAR(16) NULL",

        /* Which deterministic rule fired, when the source was automatic. Only
           the six keys in Leadfinder_autowaste::deterministicRules() are ever
           written here. An advisory signal never reaches this column. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `waste_rule` VARCHAR(60) NULL",

        /* 'waste' or 'dnc'. See the header: DNC is a different policy, not a
           waste reason, and the queue has to be able to tell without a join. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `suppression_kind` VARCHAR(8) NULL",

        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `wasted_at` INT UNSIGNED NULL",

        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `wasted_by` INT NOT NULL DEFAULT 0",

        /* Stored, not derived. See the header. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `undo_deadline` INT UNSIGNED NULL",

        /* An undo is not an erasure. The row keeps the fact that it was wasted
           and taken back, because a prospect wasted and restored four times by
           three people is a pattern somebody should be able to see. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `undone_at` INT UNSIGNED NULL",

        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `undone_by` INT NOT NULL DEFAULT 0",

        /* Advisory only. Never set by a person, never blocks an action, never
           removes a row. Its entire effect is a badge on the list. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `review_flag` TINYINT(1) NOT NULL DEFAULT 0",

        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `review_rule` VARCHAR(60) NULL",

        /* When the contact details on this row were cleared. Distinct from
           `coords_purged_at`, which is the narrower Google coordinate sweep
           from migration 101 and runs on a different clock for a different
           reason. Conflating them would have made the §14.3 sweep look like a
           retention purge in every report. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `pii_purged_at` INT UNSIGNED NULL",

        /* Set once the tombstone exists. The ordering matters and is enforced
           in the purge job, not here: PII is cleared only after the tombstone
           that will recognise this business again has been written and
           committed. A purge that runs first destroys the only thing that could
           have made the suppression, and nothing detects it afterwards. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `tombstone_id` BIGINT UNSIGNED NULL",

        /* The queue screen's default filter is "wasted_at IS NULL OR undone_at
           IS NOT NULL" — the live rows. This is the index that serves it. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD INDEX `lf_waste_state` (`wasted_at`,`undone_at`)",

        /* The undo banner asks "which of my rows can still be taken back", and
           the purge job asks "which deadlines have passed". Same index. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD INDEX `lf_undo_deadline` (`undo_deadline`)",

        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD INDEX `lf_review_flag` (`review_flag`)",

        /* The purge sweep: wasted, past its deadline, not yet purged. Without
           this it is a full scan of the prospect table on every tick. */
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD INDEX `lf_pii_purge` (`wasted_at`,`pii_purged_at`)",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('waste_undo_window_seconds','900','How long a waste decision can be taken back. Operational, not a business rule: it is the length of an interruption, not a retention period. 15 minutes covers the realistic case — wrong row, phone rang, came back — without holding the purge queue open long enough to matter. Bounded in code to 60s..86400s; a configured value outside that range is refused rather than clamped silently.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('auto_waste_enabled','1','Whether deterministic automatic waste rules may remove a result without a person. Only the six rules in Leadfinder_autowaste::deterministicRules() ever qualify — an exact duplicate, a permanently closed business, an explicit DNC match, a simulated fixture outside UAT, or a result already rejected inside its suppression period. Each is a fact, not an estimate.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('auto_waste_ai_advisory_only','1','An AI or heuristic waste score may annotate a result and may never remove one. This key records the policy so a later change is a deliberate, dated, attributable edit rather than a quiet behaviour change. The code does not read it as permission: the advisory path has no removal branch to enable.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('waste_bulk_max','100','Rows one bulk waste action may cover. A bulk mistake is a bulk undo, and an undo covering thousands of rows across several owners is not a thing an interface can present honestly inside a 15-minute window.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey IN (
            'waste_undo_window_seconds','auto_waste_enabled',
            'auto_waste_ai_advisory_only','waste_bulk_max')",

        "ALTER TABLE `{P}payplex_lf_prospects` DROP INDEX `lf_pii_purge`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP INDEX `lf_review_flag`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP INDEX `lf_undo_deadline`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP INDEX `lf_waste_state`",

        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `tombstone_id`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `pii_purged_at`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `review_rule`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `review_flag`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `undone_by`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `undone_at`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `undo_deadline`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `wasted_by`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `wasted_at`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `suppression_kind`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `waste_rule`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `waste_source`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `waste_notes`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `waste_reason`",
    ),

    'down_warning' => 'Rolling back discards every waste decision, its stated reason, its author '
                    . 'and its undo history — the columns are dropped, so the data goes with them. '
                    . 'The prospects themselves survive, but they come back looking as though nobody '
                    . 'ever rejected them, and the next search will re-offer every one. '
                    . 'Export payplex_lf_prospects in full before rolling back, and note that any '
                    . 'suppression tombstones written by migration 113 will outlive these columns and '
                    . 'keep suppressing rows whose local decision record no longer exists.',
);
