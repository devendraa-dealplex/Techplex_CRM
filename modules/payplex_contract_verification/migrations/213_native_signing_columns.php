<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT')
    or defined('PAYPLEX_CV_MIGRATION_TEST') or exit('No direct script access allowed');

/**
 * 213 — native per-signer completion, replacing the Leegality-only columns
 * this table was never going to fill.
 *
 * WHY THIS EXISTS
 * ----------------
 * `payplex_cv_signers` already had everything a provider-driven flow needed
 * to know who a signer was and what state the provider reported
 * (state, invited_at, viewed_at, completed_at, declined_at). What it never
 * had is anywhere to put the RESULT of a native signing attempt, because
 * that was always going to be the provider's problem: the signature image,
 * proof an OTP was verified, or a self-declared Aadhaar number.
 *
 * This module no longer integrates any external eSign provider. Signing
 * happens in this CRM, so the evidence of it has to live in this database.
 *
 * WHY ON THIS TABLE AND NOT A NEW ONE
 * ------------------------------------
 * One row per signer per request already exists here, already carries the
 * completion timestamp that decides whether a contract is done, and is
 * already what the roster gets promoted into at send time
 * (snapshotRosterOntoRequest()). A second table would just be this table's
 * columns split across a join for no reason.
 *
 * THE AADHAAR NUMBER IS ENCRYPTED, AND IT IS UNVERIFIED
 * -------------------------------------------------------
 * `aadhaar_number_enc` stores whatever the signer typed, encrypted the same
 * way this module already encrypts provider secrets (Contract_verification_
 * model::encrypt()/decrypt()). `aadhaar_last4` exists only so a screen can
 * show "...1234" without decrypting anything to render a list. Neither
 * column is proof of identity — nothing here calls UIDAI or any verification
 * service — and every place that displays this data must say so.
 */

return array(

    'id'          => '213',
    'description' => 'Native per-signer completion columns on the signer snapshot table',

    'preflight' => array(

        'signers_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_signers'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 201 has not run, so there is no signer table to '
                       . 'extend. This is also the control: its required answer is non-zero, so a '
                       . 'broken predicate fails the gate rather than returning the 0 that means '
                       . '"clear to apply".',
        ),

        'signature_image_column_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_signers'
                            AND COLUMN_NAME = 'signature_image'",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. This migration has already run. ALTER TABLE ADD COLUMN is not '
                       . 'idempotent -- it errors on the second run rather than skipping, which is why '
                       . 'this gate is checked rather than relying on the statement to be safe.',
        ),
    ),

    'reports' => array(
        'existing_signers' => "SELECT COUNT(*) AS n FROM `{P}payplex_cv_signers`",
    ),

    'up' => array(

        "ALTER TABLE `{P}payplex_cv_signers`
           ADD COLUMN `signature_image` VARCHAR(190) NULL AFTER `link_expires_at`",

        "ALTER TABLE `{P}payplex_cv_signers`
           ADD COLUMN `signed_ip` VARCHAR(45) NULL AFTER `signature_image`",

        "ALTER TABLE `{P}payplex_cv_signers`
           ADD COLUMN `otp_hash` CHAR(64) NULL AFTER `signed_ip`",

        "ALTER TABLE `{P}payplex_cv_signers`
           ADD COLUMN `otp_expires_at` INT UNSIGNED NULL AFTER `otp_hash`",

        "ALTER TABLE `{P}payplex_cv_signers`
           ADD COLUMN `otp_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `otp_expires_at`",

        "ALTER TABLE `{P}payplex_cv_signers`
           ADD COLUMN `otp_verified_at` INT UNSIGNED NULL AFTER `otp_attempts`",

        "ALTER TABLE `{P}payplex_cv_signers`
           ADD COLUMN `aadhaar_number_enc` TEXT NULL AFTER `otp_verified_at`",

        "ALTER TABLE `{P}payplex_cv_signers`
           ADD COLUMN `aadhaar_last4` CHAR(4) NULL AFTER `aadhaar_number_enc`",
    ),

    'down' => array(
        "ALTER TABLE `{P}payplex_cv_signers` DROP COLUMN `aadhaar_last4`",
        "ALTER TABLE `{P}payplex_cv_signers` DROP COLUMN `aadhaar_number_enc`",
        "ALTER TABLE `{P}payplex_cv_signers` DROP COLUMN `otp_verified_at`",
        "ALTER TABLE `{P}payplex_cv_signers` DROP COLUMN `otp_attempts`",
        "ALTER TABLE `{P}payplex_cv_signers` DROP COLUMN `otp_expires_at`",
        "ALTER TABLE `{P}payplex_cv_signers` DROP COLUMN `otp_hash`",
        "ALTER TABLE `{P}payplex_cv_signers` DROP COLUMN `signed_ip`",
        "ALTER TABLE `{P}payplex_cv_signers` DROP COLUMN `signature_image`",
    ),

    'down_warning' =>
        'Rolling 213 back drops every native signature image reference, OTP verification record and '
      . 'self-declared Aadhaar number captured through native signing. Any contract signed natively '
      . 'loses the evidence that it was signed at all -- completed_at stays, but what was signed with '
      . 'does not. Export this table before rolling back.',
);
