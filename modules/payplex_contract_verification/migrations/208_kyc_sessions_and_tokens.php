<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT')
    or defined('PAYPLEX_CV_MIGRATION_TEST') or exit('No direct script access allowed');

/**
 * 208 — Video KYC sessions, and the single-use links that invite people to them.
 *
 * WHY TOKENS ARE STORED AS HASHES
 * -------------------------------
 * A verification link is, for its lifetime, the ability to present yourself as a
 * named signer to an identity-verification session. If the raw token were stored
 * here, then anybody who could read this table — a database export, a backup, a
 * support query, a SQL injection three modules away — could open somebody else's
 * KYC session.
 *
 * So only a SHA-256 of the token is kept, for the same reason passwords are
 * hashed: the column is useless to whoever reads it. The raw value exists once,
 * in the outbound message, and is unrecoverable afterwards by anyone including
 * an administrator. Reissuing means a new token, which revokes the old one.
 *
 * WHY THE SESSION TABLE HAS NO `passed_by`
 * ----------------------------------------
 * Deliberately. There is no column for which member of staff marked a
 * verification as passed, because no member of staff may. `decided_by_provider`
 * records the provider's own reference for the decision, and a session with no
 * provider decision has not passed — there is nowhere to record a human saying
 * otherwise, which is the point.
 *
 * WHY LOCATION IS TWO COLUMNS
 * ---------------------------
 * `provider_location` is a location the verification provider determined.
 * `browser_reported_location` is a value the signer's device was willing to
 * report — it can be set to anything by anybody who can open developer tools.
 * Storing both in one column would merge a determination with a self-report, and
 * whichever label the screen chose would be wrong half the time. Separate
 * columns make the weaker one impossible to quote as the stronger one.
 *
 * NO PROVIDER IS CONFIGURED YET
 * -----------------------------
 * This schema is deliberately vendor-neutral: `provider` names whichever vendor
 * is eventually approved, and no column assumes any particular one's vocabulary.
 * Until a vendor exists, rows here are created and simply never decided — which
 * is exactly what a contract stuck at "Signed — Video KYC Pending" should look
 * like in the data.
 */

return array(

    'id'          => '208',
    'description' => 'Video KYC sessions and single-use invitation tokens',

    'preflight' => array(

        'request_signers_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_signers'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 201 has not run, so there are no request signers '
                       . 'for a verification session to belong to. This check is also the control: '
                       . 'it must return 1, so a predicate that has stopped matching anything fails '
                       . 'the gate instead of passing it.',
        ),

        'kyc_tables_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}'
                            AND TABLE_NAME IN ('{P}payplex_cv_kyc_sessions','{P}payplex_cv_invite_tokens')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. One or both tables already exist, so this migration has run. '
                       . 'Re-running would be harmless here, but a migration that cannot tell '
                       . 'whether it has already run is the one that eventually runs twice.',
        ),

        'audit_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_audit'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 202 has not run. Creating a session, sending a '
                       . 'link and recording a decision are all audited events; without the audit '
                       . 'table an identity verification would leave no history of how it happened.',
        ),
    ),

    'reports' => array(
        'request_signers_present' => "SELECT COUNT(*) AS signers FROM `{P}payplex_cv_signers`",
    ),

    'up' => array(

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_kyc_sessions` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `request_id` BIGINT UNSIGNED NOT NULL,
          `contract_id` INT UNSIGNED NOT NULL,
          `signer_id` BIGINT UNSIGNED NOT NULL,
          `signer_email` VARCHAR(190) NOT NULL,
          `provider` VARCHAR(60) NOT NULL DEFAULT 'unconfigured',
          `environment` VARCHAR(20) NOT NULL DEFAULT 'sandbox',
          `session_reference` VARCHAR(190) NULL,
          `state` VARCHAR(40) NOT NULL DEFAULT 'pending',
          `attempt` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
          `required_reason` VARCHAR(60) NOT NULL DEFAULT 'profile_policy',
          /* The decision, and only ever the provider's. */
          `decision` VARCHAR(30) NULL,
          `decided_at` INT UNSIGNED NULL,
          `decided_by_provider` VARCHAR(190) NULL,
          `reject_reason` VARCHAR(500) NULL,
          `manual_review_at` INT UNSIGNED NULL,
          /* Two locations, never merged. See the header. */
          `provider_location` VARCHAR(190) NULL,
          `browser_reported_location` VARCHAR(190) NULL,
          `evidence_sha256` CHAR(64) NULL,
          `evidence_stored_at` INT UNSIGNED NULL,
          `evidence_retention_until` INT UNSIGNED NULL,
          `evidence_deleted_at` INT UNSIGNED NULL,
          `last_failure` VARCHAR(60) NULL,
          `last_synced_at` INT UNSIGNED NULL,
          `expires_at` INT UNSIGNED NULL,
          `created_at` INT UNSIGNED NOT NULL,
          `updated_at` INT UNSIGNED NULL,
          PRIMARY KEY (`id`),
          /*
           * One LIVE session per signer per attempt. `attempt` is in the key so
           * a retry creates a new row rather than overwriting the failed one —
           * a verification history with the failures removed is not a history.
           */
          UNIQUE KEY `cv_kyc_signer_attempt` (`signer_id`,`attempt`),
          UNIQUE KEY `cv_kyc_session_ref` (`provider`,`session_reference`),
          KEY `cv_kyc_request` (`request_id`,`state`),
          KEY `cv_kyc_contract` (`contract_id`),
          KEY `cv_kyc_sync` (`state`,`last_synced_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_invite_tokens` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `purpose` VARCHAR(30) NOT NULL DEFAULT 'video_kyc',
          `kyc_session_id` BIGINT UNSIGNED NULL,
          `contract_id` INT UNSIGNED NOT NULL,
          `signer_id` BIGINT UNSIGNED NOT NULL,
          /*
           * The hash, never the token. A reader of this table cannot open
           * anything. The UNIQUE key also makes a hash collision a write error
           * rather than a silent overwrite.
           */
          `token_hash` CHAR(64) NOT NULL,
          `token_algo` VARCHAR(20) NOT NULL DEFAULT 'sha256',
          `issued_at` INT UNSIGNED NOT NULL,
          `expires_at` INT UNSIGNED NOT NULL,
          `consumed_at` INT UNSIGNED NULL,
          `consumed_ip_hash` CHAR(64) NULL,
          `revoked_at` INT UNSIGNED NULL,
          `revoked_reason` VARCHAR(60) NULL,
          `resend_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          `last_sent_at` INT UNSIGNED NULL,
          `created_by` INT NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `cv_token_hash` (`token_hash`),
          KEY `cv_token_session` (`kyc_session_id`),
          KEY `cv_token_signer` (`signer_id`,`revoked_at`),
          KEY `cv_token_expiry` (`expires_at`,`consumed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /*
         * The signer's own verification state, denormalised onto the request
         * signer so the send screen and the completion gate do not have to join
         * to a session that may not exist yet.
         */
        "ALTER TABLE `{P}payplex_cv_signers`
           ADD COLUMN `kyc_required` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_mandatory`",

        "ALTER TABLE `{P}payplex_cv_signers`
           ADD COLUMN `kyc_state` VARCHAR(40) NOT NULL DEFAULT 'not_required' AFTER `kyc_required`",

        "ALTER TABLE `{P}payplex_cv_signers`
           ADD COLUMN `kyc_passed_at` INT UNSIGNED NULL AFTER `kyc_state`",
    ),

    'down' => array(
        /*
         * Dropping these destroys identity-verification history, which is the
         * one category of record in this module that cannot be reconstructed
         * from the provider afterwards — a KYC vendor's retention is its own and
         * is usually shorter than ours.
         *
         * So this `down` is for a rollback that happens within minutes of the
         * `up`, before any real session exists. Anything later needs an export
         * first, and that is a decision for a person, not a migration.
         */
        "ALTER TABLE `{P}payplex_cv_signers` DROP COLUMN `kyc_passed_at`",
        "ALTER TABLE `{P}payplex_cv_signers` DROP COLUMN `kyc_state`",
        "ALTER TABLE `{P}payplex_cv_signers` DROP COLUMN `kyc_required`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_invite_tokens`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_kyc_sessions`",
    ),
);
