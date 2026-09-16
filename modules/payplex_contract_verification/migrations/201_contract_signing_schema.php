<?php
defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT') or exit('No direct script access allowed');
/**
 * Contract verification migration 201 — the core signing schema.
 *
 * THE ARCHITECTURAL DECISION THIS MIGRATION ENCODES
 * -------------------------------------------------
 * This module does not touch `tblcontracts`. Not a column, not an index, not a
 * value. Everything it knows lives in its own tables, keyed by `contract_id`.
 *
 * Two reasons, and the second is the one that made the decision:
 *
 *   1. The standing rule is that Perfex core is not modified, and a core TABLE
 *      is core however the change is made — an ALTER survives an upgrade about
 *      as reliably as an edited file does.
 *
 *   2. I cannot currently read the deployed schema. cPanel is unauthenticated,
 *      so `SHOW COLUMNS FROM tblcontracts` is not available to me. I know from
 *      the live admin UI that contracts exist and which fields the form posts,
 *      and that is ALL I know for certain. Writing an ALTER against remembered
 *      column names would be exactly the "decision taken from an inferred
 *      context" that has caused every serious defect in this programme.
 *
 * Owning our own tables makes that uncertainty harmless: the only thing this
 * module needs from core is `tblcontracts.id`, and a contract id is a contract
 * id in every version of Perfex there has ever been.
 *
 * NUMBERING
 * ---------
 * The 200 series, so it cannot collide with Lead Finder's 101-116 or anything
 * else in the estate.
 *
 * SCOPE
 * -----
 * Additive. Three new module-owned tables. No core table is read, written,
 * altered or named. Nothing is dropped, nothing is backfilled, and `down`
 * reverses exactly what `up` creates.
 */
return array(
    'id'          => 201,
    'module'      => 'payplex_contract_verification',
    'title'       => 'Signing requests, signers and signature fields',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => null,

    'preflight' => array(
        'tables_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}'
                            AND TABLE_NAME IN ('{P}payplex_cv_requests','{P}payplex_cv_signers',
                                               '{P}payplex_cv_fields')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. A table 201 creates already exists. CREATE TABLE IF NOT EXISTS '
                       . 'would skip it silently and leave a table of unknown shape behind a migration '
                       . 'that claims to have made it.',
        ),
        'contracts_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}contracts'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. The Perfex contracts table is not present under this prefix. '
                       . 'This check is also the control: it must return 1, so a predicate that has '
                       . 'stopped matching anything fails the gate instead of passing it.',
        ),
    ),

    'reports' => array(
        /* Read-only, and deliberately only the id and a count. This module does
           not need to know what is in a contract to install its own tables. */
        'contract_count' => "SELECT COUNT(*) AS contracts_present FROM `{P}contracts`",
    ),

    'up' => array(
        /*
         * One signing request per attempt, not per contract. A contract that
         * expires and is re-sent has two rows, and the history of the first is
         * not overwritten by the second — which is what makes "why did this
         * take three weeks" answerable.
         *
         * `operation_reference` is the idempotency key. It is generated before
         * the provider is called and reused on every retry of that same logical
         * attempt, so a timeout followed by a retry cannot create a second
         * signing request and send the customer two links.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_requests` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `contract_id` INT UNSIGNED NOT NULL,
          `contract_version` VARCHAR(40) NOT NULL,
          `operation_reference` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `external_request_id` VARCHAR(190) NULL,
          `provider` VARCHAR(40) NOT NULL DEFAULT 'leegality',
          `environment` VARCHAR(12) NOT NULL DEFAULT 'sandbox',
          `state` VARCHAR(32) NOT NULL DEFAULT 'created',
          `ordering_mode` VARCHAR(12) NOT NULL DEFAULT 'parallel',
          `original_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
          `signed_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
          `certificate_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
          `submitted_by` INT NOT NULL DEFAULT 0,
          `submitted_at` INT UNSIGNED NULL,
          `approved_by` INT NOT NULL DEFAULT 0,
          `approved_at` INT UNSIGNED NULL,
          `sent_by` INT NOT NULL DEFAULT 0,
          `sent_at` INT UNSIGNED NULL,
          `completed_at` INT UNSIGNED NULL,
          `executed_at` INT UNSIGNED NULL,
          `cancelled_by` INT NOT NULL DEFAULT 0,
          `cancelled_at` INT UNSIGNED NULL,
          `cancel_reason` VARCHAR(500) NULL,
          `expires_at` INT UNSIGNED NULL,
          `last_failure` VARCHAR(60) NULL,
          `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
          `last_synced_at` INT UNSIGNED NULL,
          `created_at` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `cv_req_operation` (`operation_reference`),
          UNIQUE KEY `cv_req_external` (`provider`,`external_request_id`),
          KEY `cv_req_contract` (`contract_id`,`created_at`),
          KEY `cv_req_state` (`state`,`created_at`),
          KEY `cv_req_sync` (`state`,`last_synced_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /*
         * `reference` is the opaque public token. It is UNIQUE across the whole
         * table, not per request, so a token can be resolved without first
         * knowing which request it belongs to — which is exactly the lookup a
         * public signing URL needs to do, and doing it any other way means
         * accepting a request id from the caller.
         *
         * No contact detail is duplicated here that the contract does not
         * already hold, and the signing link is NOT stored: it is a bearer
         * credential for that person's identity, and a link at rest in a
         * database is a link in every backup of it.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_signers` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `request_id` BIGINT UNSIGNED NOT NULL,
          `reference` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          `full_name` VARCHAR(190) NOT NULL,
          `email` VARCHAR(190) NOT NULL,
          `mobile_e164` VARCHAR(20) NULL,
          `role` VARCHAR(20) NOT NULL,
          `signing_order` INT UNSIGNED NOT NULL DEFAULT 0,
          `is_mandatory` TINYINT(1) NOT NULL DEFAULT 1,
          `signature_method` VARCHAR(40) NULL,
          `consent_language` VARCHAR(12) NULL,
          `state` VARCHAR(32) NOT NULL DEFAULT 'created',
          `invited_at` INT UNSIGNED NULL,
          `viewed_at` INT UNSIGNED NULL,
          `completed_at` INT UNSIGNED NULL,
          `declined_at` INT UNSIGNED NULL,
          `decline_reason` VARCHAR(500) NULL,
          `link_expires_at` INT UNSIGNED NULL,
          `created_at` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `cv_signer_reference` (`reference`),
          UNIQUE KEY `cv_signer_order` (`request_id`,`signing_order`),
          KEY `cv_signer_request` (`request_id`),
          KEY `cv_signer_state` (`state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /*
         * Fields are stored in EDITOR space, exactly as drawn, plus the version
         * they were drawn against. The PDF-space rectangle is computed at send
         * time by Contract_field_mapper.
         *
         * Storing the computed rectangle instead was the alternative and it is
         * worse: it freezes one interpretation of the page geometry, so a fix
         * to the rotation or CropBox handling cannot be applied to fields that
         * already exist. Keeping the input means the transform stays correctable.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_fields` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `contract_id` INT UNSIGNED NOT NULL,
          `contract_version` VARCHAR(40) NOT NULL,
          `signer_reference` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
          `page_number` SMALLINT UNSIGNED NOT NULL,
          `x` DECIMAL(10,3) NOT NULL,
          `y` DECIMAL(10,3) NOT NULL,
          `width` DECIMAL(10,3) NOT NULL,
          `height` DECIMAL(10,3) NOT NULL,
          `editor_scale` DECIMAL(8,4) NOT NULL DEFAULT 1.0000,
          `field_type` VARCHAR(20) NOT NULL,
          `is_required` TINYINT(1) NOT NULL DEFAULT 1,
          `signing_order` INT UNSIGNED NOT NULL DEFAULT 0,
          `created_by` INT NOT NULL DEFAULT 0,
          `created_at` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`id`),
          KEY `cv_field_contract` (`contract_id`,`contract_version`),
          KEY `cv_field_signer` (`signer_reference`),
          KEY `cv_field_page` (`contract_id`,`page_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ),

    'down' => array(
        "DROP TABLE IF EXISTS `{P}payplex_cv_fields`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_signers`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_requests`",
    ),

    'down_warning' => 'Rolling back drops every signing request, every signer record and every '
                    . 'signature field placement. Perfex contracts themselves are untouched — this '
                    . 'module never writes to tblcontracts — so no contract is lost, but the record '
                    . 'of who was sent what, when, and where they signed is. If any contract has been '
                    . 'executed through this module, export payplex_cv_requests, payplex_cv_signers '
                    . 'and payplex_cv_fields first, and keep the export with the signed documents: '
                    . 'those tables are part of the execution evidence.',
);
