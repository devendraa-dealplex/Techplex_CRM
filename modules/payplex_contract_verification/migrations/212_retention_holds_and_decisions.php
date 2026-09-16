<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT')
    or defined('PAYPLEX_CV_MIGRATION_TEST') or exit('No direct script access allowed');

/**
 * 212 — retention rules, legal holds, and the history of KYC decisions.
 *
 * WHY RETENTION MOVES OUT OF SETTINGS
 * -----------------------------------
 * Retention lives in `payplex_cv_settings` today as three global keys:
 * kyc_retain_recordings, kyc_retention_days, kyc_legal_hold. One number for
 * every client, every evidence type, every jurisdiction — and a legal hold that
 * is on or off for the entire system.
 *
 * That is unusable the first time one client's contract is disputed: the only
 * available hold stops deletion for everybody, forever, because nobody dares
 * lift a global switch. So a hold becomes a row scoped to a client, a contract
 * or a case, and retention becomes a rule that can differ by evidence type —
 * a signed agreement is a business record kept for years; a Video KYC recording
 * is biometric data kept for as short a time as the law allows.
 *
 * The settings keys are NOT removed. They stay as the fallback for anything
 * with no explicit rule, so this migration adds capability without changing the
 * answer for a single existing row. Migrating the values across would be a
 * backfill, and backfill needs separate approval.
 *
 * WHY A DECISION HISTORY AND NOT A DECISION COLUMN
 * ------------------------------------------------
 * `payplex_cv_kyc_sessions` records the decision that stands: one decision,
 * one timestamp, overwritten if it changes. "Was this case ever failed before
 * it passed, and who changed it" is exactly the question asked when something
 * has gone wrong, and a single column answers it with the last answer only.
 * Every decision appends a row here; nothing is updated in place.
 *
 * NOTHING IS DELETED AND NOTHING IS BACKFILLED
 * --------------------------------------------
 * No DELETE, no UPDATE, no INSERT in this migration. It creates three tables
 * and stops. A deletion sweep driven by these rules is a separate change that
 * needs its own approval, and it is deliberately not written here — the table
 * that describes when evidence may be destroyed should not arrive in the same
 * deployment as the code that destroys it.
 */

return array(

    'id'          => '212',
    'description' => 'Per-scope retention rules, scoped legal holds and an append-only KYC decision history',

    'preflight' => array(

        'settings_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_settings'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 203 has not run. The settings table holds the '
                       . 'fallback retention values these rules sit above, and without it the new '
                       . 'tables would be the only source — which would change behaviour for '
                       . 'existing evidence rather than adding to it. This is also the control: its '
                       . 'required answer is non-zero, so a predicate that has stopped matching '
                       . 'fails the gate instead of returning the 0 that means "clear to apply".',
        ),

        'kyc_sessions_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_kyc_sessions'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 208 has not run; there are no KYC cases for a '
                       . 'decision history to be about.',
        ),

        'documents_extended' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_documents'
                            AND COLUMN_NAME = 'legal_hold_id'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 211 has not run, so evidence rows have nowhere to '
                       . 'record which hold covers them and a hold created here would apply to '
                       . 'nothing.',
        ),

        'new_tables_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}'
                            AND TABLE_NAME IN ('{P}payplex_cv_retention_rules',
                                               '{P}payplex_cv_legal_holds',
                                               '{P}payplex_cv_kyc_decisions')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. One or more of these tables already exists, so this '
                       . 'migration has run. CREATE TABLE IF NOT EXISTS would skip them silently '
                       . 'and report success over a schema of unknown shape.',
        ),
    ),

    'reports' => array(
        'existing_kyc_cases'    => "SELECT COUNT(*) AS cases FROM `{P}payplex_cv_kyc_sessions`",
        'existing_evidence'     => "SELECT COUNT(*) AS documents FROM `{P}payplex_cv_documents`",
        'global_retention_keys' => "SELECT COUNT(*) AS n FROM `{P}payplex_cv_settings`
                                    WHERE skey IN ('kyc_retain_recordings','kyc_retention_days','kyc_legal_hold')",
    ),

    'up' => array(

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_retention_rules` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          /* 'global' | 'client' | 'contract' | 'evidence_type'. The narrowest
             matching rule wins; with none, the settings fallback applies. */
          `scope` VARCHAR(20) NOT NULL DEFAULT 'global',
          `scope_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `evidence_type` VARCHAR(40) NULL,
          `retention_days` INT UNSIGNED NOT NULL DEFAULT 0,
          /* Off by default. A recording is biometric data and storing one needs
             a lawful basis, which is a compliance decision and not a default. */
          `store_raw_media` TINYINT(1) NOT NULL DEFAULT 0,
          `lawful_basis` VARCHAR(190) NULL,
          `consent_wording_reference` VARCHAR(190) NULL,
          `approved_by` INT NOT NULL DEFAULT 0,
          `approved_at` INT UNSIGNED NULL,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `created_by` INT NOT NULL DEFAULT 0,
          `created_at` INT UNSIGNED NOT NULL,
          `updated_by` INT NOT NULL DEFAULT 0,
          `updated_at` INT UNSIGNED NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `cv_retention_scope` (`scope`,`scope_id`,`evidence_type`,`is_active`),
          KEY `cv_retention_active` (`is_active`,`scope`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_legal_holds` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `scope` VARCHAR(20) NOT NULL DEFAULT 'contract',
          `scope_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `contract_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `kyc_session_id` BIGINT UNSIGNED NULL,
          `reference` VARCHAR(190) NOT NULL,
          `reason` VARCHAR(1000) NOT NULL,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `applied_by` INT NOT NULL DEFAULT 0,
          `applied_at` INT UNSIGNED NOT NULL,
          /* Separate from applied_by, and the authorization service refuses a
             release by the person who applied it where segregation is required.
             A hold one person can place and lift alone protects nothing from
             that person. */
          `released_by` INT NOT NULL DEFAULT 0,
          `released_at` INT UNSIGNED NULL,
          `release_reason` VARCHAR(1000) NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `cv_hold_reference` (`reference`,`is_active`),
          KEY `cv_hold_contract` (`contract_id`,`is_active`),
          KEY `cv_hold_client` (`client_id`,`is_active`),
          KEY `cv_hold_case` (`kyc_session_id`,`is_active`),
          KEY `cv_hold_scope` (`scope`,`scope_id`,`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_kyc_decisions` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `kyc_session_id` BIGINT UNSIGNED NOT NULL,
          `contract_id` INT UNSIGNED NOT NULL,
          `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `signer_id` BIGINT UNSIGNED NULL,
          `sequence` INT UNSIGNED NOT NULL DEFAULT 1,
          `decision` VARCHAR(30) NOT NULL,
          `state_from` VARCHAR(40) NOT NULL,
          `state_to` VARCHAR(40) NOT NULL,
          /* Two columns, never one. The provider's reference is what the
             provider concluded; decided_by_staff is which human recorded it.
             Collapsed into a single column, a staff referral later reads as a
             provider verification. */
          `decided_by_provider` VARCHAR(190) NULL,
          `decided_by_staff` INT NOT NULL DEFAULT 0,
          `segregation_checked` TINYINT(1) NOT NULL DEFAULT 1,
          `reason` VARCHAR(1000) NULL,
          `evidence_sha256` CHAR(64) NULL,
          `evidence_verified` TINYINT(1) NOT NULL DEFAULT 0,
          `decided_at` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`id`),
          /* Append-only: one row per attempt, and the same attempt cannot be
             written twice. Nothing in the model updates this table. */
          UNIQUE KEY `cv_decision_sequence` (`kyc_session_id`,`sequence`),
          KEY `cv_decision_case` (`kyc_session_id`,`decided_at`),
          KEY `cv_decision_contract` (`contract_id`,`decided_at`),
          KEY `cv_decision_client` (`client_id`,`decided_at`),
          KEY `cv_decision_staff` (`decided_by_staff`,`decided_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ),

    'down' => array(
        /*
         * Decision history first: it is the one whose loss matters most, so it
         * is the one an interrupted rollback is least likely to reach. The
         * settings fallback is untouched throughout, so retention behaviour
         * after a rollback is exactly what it was before 212.
         */
        "DROP TABLE IF EXISTS `{P}payplex_cv_kyc_decisions`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_legal_holds`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_retention_rules`",
    ),

    'down_warning' =>
        'Rolling 212 back drops the KYC decision history, every scoped legal hold, and every '
        . 'per-scope retention rule. The decision history is append-only and is the only record of '
        . 'a case that was decided more than once — it cannot be reconstructed from the session row, '
        . 'which holds the latest decision only. More seriously, dropping the holds table removes '
        . 'the thing preventing evidence deletion: any evidence preserved by a scoped hold reverts '
        . 'to the global on/off setting, and if that setting is off the evidence becomes eligible '
        . 'for deletion. Export both tables before rolling back, and do not roll back at all while '
        . 'any hold is active.',
);
