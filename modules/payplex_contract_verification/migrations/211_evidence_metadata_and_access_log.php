<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT')
    or defined('PAYPLEX_CV_MIGRATION_TEST') or exit('No direct script access allowed');

/**
 * 211 — evidence metadata, and a log of every attempt to reach it.
 *
 * IT EXTENDS `payplex_cv_documents` RATHER THAN ADDING A SECOND TABLE
 * -------------------------------------------------------------------
 * 202 already created an evidence table: document_type, storage_path, sha256,
 * bytes, retrieved_by. A new `payplex_cv_evidence` table alongside it would
 * mean two places holding the same kind of thing, two access checks, and a
 * guarantee that within a year one of them would be missing a control the other
 * had. The brief said not to duplicate; this is where the temptation was.
 *
 * So the existing table gains the columns the new model needs:
 *
 *   client_id, signer_id, kyc_session_id  — the Client → Signer → Case → Contract
 *                                           → Evidence chain the repository walks
 *   evidence_type                         — the ten-type taxonomy, replacing the
 *                                           three-value document_type for new rows
 *   storage_key                           — the opaque name; see below
 *   is_encrypted, encryption_algo, key_reference
 *   hash_verified_at                      — WHEN the hash last verified, not just
 *                                           what it was
 *   retention_until, legal_hold_id, deleted_at
 *
 * `storage_path` is left in place and untouched. Existing rows keep working;
 * new rows use `storage_key`. Backfilling would mean rewriting rows that point
 * at real files, and the brief rules out backfill without separate approval —
 * correctly, because a half-finished backfill of file locations is unrecoverable.
 *
 * WHY A KEY AND NOT A PATH
 * ------------------------
 * A path in a database row is a map to the file for anyone who reads the row,
 * and it usually spells out something: the client, the document type, a
 * sequential id. `storage_key` is 64 hex characters from random_bytes and
 * carries no information at all; the directory is derived from the key at read
 * time. Move the storage root and no row is stale. Leak a row and you have a
 * name for a file you still cannot find or reach.
 *
 * THE ACCESS LOG IS NOT THE AUDIT TABLE
 * -------------------------------------
 * `payplex_cv_audit` records things that CHANGED. This records every time
 * somebody looked, streamed, downloaded, or was refused — successes and denials
 * both, because a denied attempt to open a Video KYC recording is at least as
 * interesting as a successful one, and a log that only records successes cannot
 * show you somebody trying. It will grow much faster than the audit table,
 * which is the second reason it is separate.
 */

return array(

    'id'          => '211',
    'description' => 'Evidence metadata on the existing documents table, plus an evidence access log',

    'preflight' => array(

        'documents_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_documents'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 202 has not run, so there is no evidence table to '
                       . 'extend. This is also the control: its required answer is non-zero, so a '
                       . 'broken predicate fails the gate rather than returning the same 0 that '
                       . 'means "clear to apply".',
        ),

        'storage_key_column_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_documents'
                            AND COLUMN_NAME = 'storage_key'",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. The columns are already present, so this migration has run. '
                       . 'ALTER TABLE ADD COLUMN is not idempotent — it errors on the second run '
                       . 'rather than skipping, which is why this gate is checked rather than '
                       . 'relying on the statement to be safe.',
        ),

        'access_log_table_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_evidence_access_log'",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. The access log already exists.',
        ),

        'kyc_sessions_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_kyc_sessions'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 208 has not run. Evidence rows point at KYC cases '
                       . 'and there would be nothing to point at.',
        ),

        'assignments_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_assignments'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 210 has not run. Evidence access is decided partly '
                       . 'by assignment, and without that table every access check would fall back '
                       . 'to all-or-own — which is the behaviour this work exists to replace.',
        ),
    ),

    'reports' => array(
        'existing_documents' => "SELECT COUNT(*) AS documents FROM `{P}payplex_cv_documents`",
        'existing_kyc_cases' => "SELECT COUNT(*) AS cases FROM `{P}payplex_cv_kyc_sessions`",
    ),

    'up' => array(

        /* ---- the chain the client repository walks ---- */

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD COLUMN `client_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `contract_id`",

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD COLUMN `signer_id` BIGINT UNSIGNED NULL AFTER `client_id`",

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD COLUMN `kyc_session_id` BIGINT UNSIGNED NULL AFTER `signer_id`",

        /* ---- the ten-type taxonomy ---- */

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD COLUMN `evidence_type` VARCHAR(40) NULL AFTER `document_type`",

        /* ---- the opaque name, and the encryption envelope ---- */

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD COLUMN `storage_key` CHAR(64) NULL AFTER `storage_path`",

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD COLUMN `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `storage_key`",

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD COLUMN `encryption_algo` VARCHAR(40) NULL AFTER `is_encrypted`",

        /*
         * A REFERENCE to the key, never the key. If this column ever holds key
         * material, the encryption is decorative: the ciphertext and the key
         * would sit in the same backup.
         */
        "ALTER TABLE `{P}payplex_cv_documents`
           ADD COLUMN `key_reference` VARCHAR(190) NULL AFTER `encryption_algo`",

        /* ---- integrity, retention, holds ---- */

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD COLUMN `hash_verified_at` INT UNSIGNED NULL AFTER `verified_at`",

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD COLUMN `retention_until` INT UNSIGNED NULL AFTER `hash_verified_at`",

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD COLUMN `legal_hold_id` BIGINT UNSIGNED NULL AFTER `retention_until`",

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD COLUMN `deleted_at` INT UNSIGNED NULL AFTER `legal_hold_id`",

        /*
         * UNIQUE on the storage key. Two rows pointing at one file means
         * deleting one row orphans or destroys the other's evidence. NULL
         * repeats freely in MySQL, so the existing rows without a key are
         * unaffected.
         */
        "ALTER TABLE `{P}payplex_cv_documents`
           ADD UNIQUE KEY `cv_doc_storage_key` (`storage_key`)",

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD KEY `cv_doc_client_chain` (`client_id`,`contract_id`,`evidence_type`)",

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD KEY `cv_doc_case` (`kyc_session_id`,`evidence_type`)",

        "ALTER TABLE `{P}payplex_cv_documents`
           ADD KEY `cv_doc_retention` (`deleted_at`,`retention_until`)",

        /* ---- every look, stream, download and refusal ---- */

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_evidence_access_log` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `actor_id` INT NOT NULL DEFAULT 0,
          `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `contract_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `kyc_session_id` BIGINT UNSIGNED NULL,
          `document_id` BIGINT UNSIGNED NULL,
          `evidence_type` VARCHAR(40) NOT NULL,
          `action` VARCHAR(20) NOT NULL,
          `outcome` VARCHAR(20) NOT NULL,
          `refusal_reason` VARCHAR(190) NULL,
          `via` VARCHAR(40) NULL,
          `access_reason` VARCHAR(500) NULL,
          `bytes_served` BIGINT UNSIGNED NULL,
          `hash_verified` TINYINT(1) NOT NULL DEFAULT 0,
          /*
           * A HASH of the address, not the address. The question this answers is
           * \"was it the same place as last time\", which a hash answers, and it
           * is not a reason to keep a log of everybody's IP addresses.
           */
          `ip_hash` CHAR(64) NULL,
          `user_agent_hash` CHAR(64) NULL,
          `session_token_hash` CHAR(64) NULL,
          `started_at` INT UNSIGNED NOT NULL,
          `completed_at` INT UNSIGNED NULL,
          PRIMARY KEY (`id`),
          KEY `cv_evlog_contract` (`contract_id`,`started_at`),
          KEY `cv_evlog_actor` (`actor_id`,`started_at`),
          KEY `cv_evlog_client` (`client_id`,`started_at`),
          KEY `cv_evlog_type` (`evidence_type`,`outcome`,`started_at`),
          KEY `cv_evlog_document` (`document_id`,`started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ),

    'down' => array(
        /*
         * The access log goes first and the columns after, so an interrupted
         * rollback leaves the evidence rows intact rather than half-stripped.
         * The indexes are dropped before their columns because MySQL refuses
         * the other order.
         */
        "DROP TABLE IF EXISTS `{P}payplex_cv_evidence_access_log`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP INDEX `cv_doc_retention`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP INDEX `cv_doc_case`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP INDEX `cv_doc_client_chain`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP INDEX `cv_doc_storage_key`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP COLUMN `deleted_at`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP COLUMN `legal_hold_id`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP COLUMN `retention_until`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP COLUMN `hash_verified_at`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP COLUMN `key_reference`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP COLUMN `encryption_algo`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP COLUMN `is_encrypted`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP COLUMN `storage_key`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP COLUMN `evidence_type`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP COLUMN `kyc_session_id`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP COLUMN `signer_id`",
        "ALTER TABLE `{P}payplex_cv_documents` DROP COLUMN `client_id`",
    ),

    'down_warning' =>
        'Rolling 211 back drops the evidence access log — every record of who looked at, streamed, '
        . 'downloaded or was refused a piece of evidence — and it cannot be reconstructed from '
        . 'anywhere else. It also drops `storage_key` from the documents table, which is how new '
        . 'evidence files are located: any file stored after 211 was applied becomes unreachable, '
        . 'because the only pointer to it was that column. Rows created before 211 still have '
        . '`storage_path` and are unaffected. Intended only for an immediate rollback, before any '
        . 'evidence has been written under the new scheme.',
);
