<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT')
    or defined('PAYPLEX_CV_MIGRATION_TEST') or exit('No direct script access allowed');

/**
 * 205 — the signer roster: who will sign, decided before anything is sent.
 *
 * WHY A SECOND SIGNER TABLE
 * -------------------------
 * `payplex_cv_signers` hangs off a REQUEST. A request is the record of one
 * attempt to get a document signed, and it does not exist until somebody sends
 * — which is far too late to be deciding who the signers are.
 *
 * That ordering problem is already visible in the build: the placement editor
 * has to offer "signer slot 1, signer slot 2" because there is nobody to name,
 * and an approved placement carries `signer_reference = NULL` with a warning on
 * screen. The mapper then refuses it at send. Correct, but useless.
 *
 * This table is the roster: signers attached to the CONTRACT, edited freely
 * while preparing, and copied into `payplex_cv_signers` when a request is
 * created. It is the same draft/approved split already used for placements, for
 * the same reason — the record the provider acts on must not be editable by
 * whoever is still deciding.
 *
 * `signing_order` is the slot number the placement editor already stores, so the
 * two features line up without either having to know about the other's tables.
 *
 * WHAT THE TWO UNIQUE KEYS ARE FOR
 * --------------------------------
 * Phase 5 asks for duplicate signer prevention. Doing that in PHP alone is a
 * check that races: two tabs, two saves, two rows. These are database
 * constraints, so the second one fails whatever the application believes.
 *
 *   - one signer per slot on a contract
 *   - one row per email address on a contract
 *
 * Email is the natural key because it is what the provider delivers to. Two
 * rows with the same address are either a mistake or an attempt to make one
 * person sign twice, and neither should be stored.
 *
 * REPLACEMENT IS A COLUMN, NOT A DELETE
 * -------------------------------------
 * A signer who is replaced is kept, marked, and points at their replacement.
 * Deleting them would erase the fact that the contract was once addressed to
 * somebody else — which is exactly what an auditor asks about.
 */

return array(

    'id'          => '205',
    'description' => 'Contract signer roster, prepared before a request exists',

    'preflight' => array(

        'request_signers_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_signers'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 201 has not run, so there is no request-signer '
                       . 'table for the roster to be promoted into. This check is also the control: '
                       . 'it must return 1, so a predicate that has stopped matching anything fails '
                       . 'the gate instead of passing it.',
        ),

        'roster_table_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_contract_signers'",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. The roster table already exists. Re-running would be '
                       . 'harmless here, but a migration that cannot tell whether it has already '
                       . 'run is the one that eventually runs twice against something that matters.',
        ),

        'audit_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_audit'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 202 has not run. Adding, replacing and removing '
                       . 'a signer are all audited events; without the audit table this feature '
                       . 'would change who signs a contract leaving no history.',
        ),
    ),

    'reports' => array(
        'contracts_present' => "SELECT COUNT(*) AS contracts FROM `{P}contracts`",
    ),

    'up' => array(

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_contract_signers` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `contract_id` INT UNSIGNED NOT NULL,
          `signing_order` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
          `full_name` VARCHAR(190) NOT NULL,
          `email` VARCHAR(190) NOT NULL,
          `mobile_e164` VARCHAR(20) NULL,
          `designation` VARCHAR(120) NULL,
          `party` VARCHAR(20) NOT NULL DEFAULT 'customer',
          `role` VARCHAR(20) NOT NULL DEFAULT 'signer',
          `is_mandatory` TINYINT(1) NOT NULL DEFAULT 1,
          `is_authorised_signatory` TINYINT(1) NOT NULL DEFAULT 0,
          `auth_method` VARCHAR(40) NULL,
          `kyc_required` TINYINT(1) NOT NULL DEFAULT 0,
          `signing_deadline_at` INT UNSIGNED NULL,
          `reminder_interval_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          `email_verified_at` INT UNSIGNED NULL,
          `mobile_verified_at` INT UNSIGNED NULL,
          `replaced_by_id` BIGINT UNSIGNED NULL,
          `replaced_at` INT UNSIGNED NULL,
          `replacement_reason` VARCHAR(500) NULL,
          `created_by` INT NOT NULL DEFAULT 0,
          `created_at` INT UNSIGNED NOT NULL,
          `updated_by` INT NOT NULL DEFAULT 0,
          `updated_at` INT UNSIGNED NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `cv_roster_slot` (`contract_id`,`signing_order`),
          UNIQUE KEY `cv_roster_email` (`contract_id`,`email`),
          KEY `cv_roster_contract` (`contract_id`),
          KEY `cv_roster_replaced` (`replaced_by_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ),

    'down' => array(
        /*
         * Dropping the roster destroys no signature and no evidence: nothing has
         * been sent from it, request signers live in `payplex_cv_signers`, and
         * every add, replace and removal is already recorded in
         * `payplex_cv_audit`. Preparation work is the only thing lost.
         */
        "DROP TABLE IF EXISTS `{P}payplex_cv_contract_signers`",
    ),
);
