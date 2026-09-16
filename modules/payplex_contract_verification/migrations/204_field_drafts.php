<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT')
    or defined('PAYPLEX_CV_MIGRATION_TEST') or exit('No direct script access allowed');

/**
 * 204 — a working surface for signature placement, kept apart from the
 * authoritative one.
 *
 * WHY A SECOND TABLE AND NOT A STATUS COLUMN
 * ------------------------------------------
 * `payplex_cv_fields` is what `fields()` reads and what `mappedFields()` maps,
 * and therefore what a contract is eventually SENT with. Adding a `status`
 * column to it would mean every existing reader had to remember to filter, and
 * the day one of them forgot, a half-drawn draft would be sent to a customer.
 * A reader that forgets a filter fails silently and in the worst direction.
 *
 * With a separate table that mistake is not available: the send path cannot see
 * a draft, because drafts are not in the table it reads. Approval is the only
 * thing that moves a placement across, and it is a deliberate, audited act.
 *
 * The cost is one copy step at approval. That is a good trade for making the
 * dangerous mistake structurally impossible rather than merely discouraged.
 *
 * WHY `signer_slot` AND NOT `signer_reference`
 * --------------------------------------------
 * Signers live in `payplex_cv_signers`, which hangs off a REQUEST — and no
 * request exists while someone is still drawing boxes on a page. So a draft
 * cannot name a signer reference; there is nothing to name yet.
 *
 * A draft therefore records a slot: "the first signer", "the second signer".
 * Approval resolves slots to references when a request exists, and leaves
 * `signer_reference` NULL when one does not. A NULL reference is refused later
 * by `Contract_field_mapper::validateField()`, which is the correct outcome —
 * the placement is recorded, and the send still cannot happen until there is a
 * real signer to attach it to. Fail closed, with the work preserved.
 */

return array(

    'id'          => '204',
    'description' => 'Signature placement drafts, separate from approved placements',

    'preflight' => array(

        'fields_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_fields'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 201 has not run, so there is no approved '
                       . 'placement table for drafts to be promoted into. This check is also the '
                       . 'control: it must return 1, so a predicate that has stopped matching '
                       . 'anything fails the gate instead of passing it.',
        ),

        'draft_table_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_field_drafts'",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. The draft table already exists. Re-running would be a '
                       . 'no-op here, but a migration that cannot tell whether it has already run '
                       . 'is the one that eventually runs twice against something that matters.',
        ),

        'audit_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_audit'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 202 has not run. Placement changes are recorded '
                       . 'in the existing audit table rather than a new one, so without it this '
                       . 'feature would edit signing material leaving no history.',
        ),
    ),

    'reports' => array(
        'approved_placements' => "SELECT COUNT(*) AS approved_rows_before FROM `{P}payplex_cv_fields`",
    ),

    'up' => array(

        /*
         * Column shapes are copied from `payplex_cv_fields` deliberately, not
         * re-invented. Approval is a row-for-row copy, and two tables that
         * disagree about the width of a DECIMAL are how a coordinate silently
         * changes value on the way across.
         *
         * `editor_scale` travels with the row because the scale the operator
         * drew at is part of the measurement, not a display preference. The
         * mapper divides by it at send time.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_field_drafts` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `contract_id` INT UNSIGNED NOT NULL,
          `contract_version` VARCHAR(40) NOT NULL,
          `signer_slot` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
          `page_number` SMALLINT UNSIGNED NOT NULL,
          `x` DECIMAL(10,3) NOT NULL,
          `y` DECIMAL(10,3) NOT NULL,
          `width` DECIMAL(10,3) NOT NULL,
          `height` DECIMAL(10,3) NOT NULL,
          `editor_scale` DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
          `field_type` VARCHAR(20) NOT NULL,
          `is_required` TINYINT(1) NOT NULL DEFAULT 1,
          `created_by` INT NOT NULL DEFAULT 0,
          `created_at` INT UNSIGNED NOT NULL,
          `updated_by` INT NOT NULL DEFAULT 0,
          `updated_at` INT UNSIGNED NULL,
          PRIMARY KEY (`id`),
          KEY `cv_draft_contract` (`contract_id`,`contract_version`),
          KEY `cv_draft_page` (`contract_id`,`page_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ),

    'down' => array(
        /*
         * Dropping drafts destroys no approved placement and no evidence: the
         * approved rows live in `payplex_cv_fields` and the history of every
         * change lives in `payplex_cv_audit`, neither of which is touched here.
         * Unfinished drawing is the only thing lost.
         */
        "DROP TABLE IF EXISTS `{P}payplex_cv_field_drafts`",
    ),
);
