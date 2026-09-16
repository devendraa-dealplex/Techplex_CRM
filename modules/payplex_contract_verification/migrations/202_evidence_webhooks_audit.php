<?php
defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT') or exit('No direct script access allowed');
/**
 * Contract verification migration 202 — evidence, webhook deliveries,
 * verification records and the audit trail.
 *
 * WHAT IS AND IS NOT STORED, AND WHY
 * ----------------------------------
 * These four tables are where a careless schema leaks personal data, so each
 * one is shaped by what it must NOT hold.
 *
 *   - The evidence table stores a PATH and a DIGEST. The files live outside the
 *     document root and are served only through a permission-checked action. A
 *     signed agreement reachable by URL is a signed agreement anyone can read.
 *
 *   - The webhook table stores a DIGEST of the body, not the body. The payload
 *     is the provider's copy of the signer's personal data; keeping it makes a
 *     second, unmanaged store of exactly what the contract record already holds
 *     under proper controls. The digest proves what arrived without being it.
 *     The signature header and the secret are never stored at all.
 *
 *   - The verification table stores a RESULT and a masked reference — never an
 *     Aadhaar number, never a PAN number, never a full document. What may be
 *     retained as identity evidence under Indian rules is a question for the
 *     client's counsel, and the schema deliberately makes the permissive
 *     answer unavailable until somebody decides it on purpose.
 *
 *   - The audit table records WHO did WHAT to WHICH record and WHEN. Its
 *     `detail` column is written through a redactor; the forbidden-field list
 *     is in Contract_evidence::neverInAudit() and the suite asserts the schema
 *     has no column named after any of them.
 *
 * SCOPE
 * -----
 * Additive. Four new module-owned tables. No core table is read, written,
 * altered or named. `down` reverses exactly what `up` creates.
 */
return array(
    'id'          => 202,
    'module'      => 'payplex_contract_verification',
    'title'       => 'Evidence documents, webhook deliveries, verification records and audit',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 201,

    'preflight' => array(
        'tables_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}'
                            AND TABLE_NAME IN ('{P}payplex_cv_documents','{P}payplex_cv_webhooks',
                                               '{P}payplex_cv_verifications','{P}payplex_cv_audit')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. A table 202 creates already exists.',
        ),
        'requests_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_requests'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 201 has not run. This is also the control check: '
                       . 'it must return 1, so a predicate matching nothing fails the gate.',
        ),
    ),

    'reports' => array(
        'request_count' => "SELECT COUNT(*) AS signing_requests FROM `{P}payplex_cv_requests`",
    ),

    'up' => array(
        /*
         * `storage_path` is relative to a configured root that sits OUTSIDE the
         * document root. Storing an absolute path would bake one deployment's
         * filesystem layout into the data and break on a move; storing a URL
         * would be storing the thing that must not exist.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_documents` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `request_id` BIGINT UNSIGNED NOT NULL,
          `contract_id` INT UNSIGNED NOT NULL,
          `document_type` VARCHAR(32) NOT NULL,
          `storage_path` VARCHAR(500) NOT NULL,
          `content_type` VARCHAR(100) NULL,
          `bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
          `sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `verified_at` INT UNSIGNED NULL,
          `retrieved_at` INT UNSIGNED NOT NULL,
          `retrieved_by` INT NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `cv_doc_one_per_type` (`request_id`,`document_type`),
          KEY `cv_doc_contract` (`contract_id`,`document_type`),
          KEY `cv_doc_digest` (`sha256`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /*
         * `event_key` is UNIQUE. That single constraint is the whole
         * idempotency mechanism: a duplicate delivery loses the insert race and
         * is acknowledged without doing any work. Doing it with a SELECT first
         * would leave a window in which two concurrent deliveries both find
         * nothing and both process.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_webhooks` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `event_key` VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `event_type` VARCHAR(80) NULL,
          `external_request_id` VARCHAR(190) NULL,
          `request_id` BIGINT UNSIGNED NULL,
          `body_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `received_at` INT UNSIGNED NOT NULL,
          `processed_at` INT UNSIGNED NULL,
          `result` VARCHAR(60) NOT NULL DEFAULT 'received',
          `http_status` SMALLINT UNSIGNED NOT NULL DEFAULT 200,
          `state_before` VARCHAR(32) NULL,
          `state_after` VARCHAR(32) NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `cv_hook_event` (`event_key`),
          KEY `cv_hook_request` (`request_id`,`received_at`),
          KEY `cv_hook_result` (`result`,`received_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /*
         * No identity NUMBER column exists here, and that is the point. There is
         * a type, a masked reference, a result and who recorded it. Adding a
         * column for the number would be a decision with a legal dimension, and
         * it is not made by default in a migration.
         *
         * `video_kyc` is a kind of verification rather than its own table: the
         * evidence shape is the same and splitting it would mean two places to
         * check before a contract may be sent.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_verifications` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `contract_id` INT UNSIGNED NOT NULL,
          `signer_reference` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
          `verification_type` VARCHAR(32) NOT NULL,
          `outcome` VARCHAR(20) NOT NULL,
          `masked_reference` VARCHAR(40) NULL,
          `evidence_path` VARCHAR(500) NULL,
          `evidence_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
          `provider_name` VARCHAR(60) NULL,
          `recorded_by` INT NOT NULL,
          `recorded_at` INT UNSIGNED NOT NULL,
          `expires_on` DATE NULL,
          `notes` VARCHAR(500) NULL,
          PRIMARY KEY (`id`),
          KEY `cv_ver_contract` (`contract_id`,`verification_type`),
          KEY `cv_ver_signer` (`signer_reference`),
          KEY `cv_ver_outcome` (`outcome`,`recorded_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_audit` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `actor_id` INT NOT NULL DEFAULT 0,
          `event` VARCHAR(60) NOT NULL,
          `contract_id` INT UNSIGNED NULL,
          `request_id` BIGINT UNSIGNED NULL,
          `signer_reference` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
          `detail` TEXT NULL,
          `ip` VARCHAR(45) NULL,
          `at` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`id`),
          KEY `cv_audit_contract` (`contract_id`,`at`),
          KEY `cv_audit_actor` (`actor_id`,`at`),
          KEY `cv_audit_event` (`event`,`at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ),

    'down' => array(
        "DROP TABLE IF EXISTS `{P}payplex_cv_audit`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_verifications`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_webhooks`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_documents`",
    ),

    'down_warning' => 'Rolling back drops the evidence index, the webhook delivery log, the identity '
                    . 'verification records and the audit trail. The FILES are not deleted — they live '
                    . 'outside the document root and outside the database — but the record of which '
                    . 'file is which, what its hash was, and who retrieved it is lost, which makes them '
                    . 'unidentifiable bytes. It also destroys the record of who approved and sent each '
                    . 'contract. Export all four tables before rolling back and keep the export with '
                    . 'the documents; for any executed contract these tables ARE the execution evidence.',
);
