<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT')
    or defined('PAYPLEX_CV_MIGRATION_TEST') or exit('No direct script access allowed');

/**
 * 207 — Leegality workflow profiles, and the mapping from a CRM contract to one.
 *
 * WHY THIS TABLE HAS TO EXIST
 * ---------------------------
 * Leegality's `profileId` selects a WORKFLOW, and the workflow — not the API
 * request — decides signer order, eSign types and the security steps each
 * invitee passes through. The documented create call carries no per-invitee
 * order field at all.
 *
 * So our roster's `signing_order` has nothing to bind to on the wire. Post the
 * invitees in our order and the provider applies the workflow's order instead:
 * the request succeeds, the links go out, and the wrong party is asked to sign
 * first. Nothing errors. That is the defect this table prevents, by recording
 * what the workflow is declared to do so the roster can be checked against it
 * before anything is sent.
 *
 * WHY IT IS DECLARED BY A HUMAN RATHER THAN DISCOVERED
 * ----------------------------------------------------
 * Leegality documents no API that lists workflows or fetches one by id. That was
 * verified against their documentation before this file was written, not
 * assumed. The shape of a workflow therefore cannot be read from the provider —
 * it has to be entered by an administrator who is looking at it in the
 * dashboard.
 *
 * Which makes every row here a CLAIM ABOUT SOMEBODY ELSE'S CONFIGURATION. It can
 * be wrong on the day it is entered and it can go stale afterwards, silently,
 * because a workflow edited in the Leegality dashboard sends us no notification.
 * Three things follow, and all three are in the schema:
 *
 *   - who declared it and when, so a stale row has an owner;
 *   - `validated_at` / `validated_by`, separate from creation, because entering
 *     a value and proving it works are different events;
 *   - `is_active`, so a profile can be retired without deleting the history of
 *     contracts sent through it.
 *
 * VALIDATION IS NOT FREE, AND THE SCHEMA SAYS SO
 * ----------------------------------------------
 * With no read-only endpoint, the only way to prove a profileId is accepted is
 * to create a request with it — which creates a REAL document in the account.
 * So validation is sandbox-only and `validation_note` records that a genuine
 * sandbox document was produced and left to expire. It is never deleted,
 * because the delete operation destroys audit trails.
 *
 * NOTHING SECRET LIVES HERE
 * -------------------------
 * A profileId is a configuration identifier, not a credential — it is useless
 * without the auth token, which stays in `payplex_cv_settings` under the
 * existing secret handling. Storing it in plain text here is deliberate: it has
 * to be readable on screen for an administrator to check it against the
 * dashboard, and encrypting a non-secret would only make that harder while
 * protecting nothing.
 */

return array(

    'id'          => '207',
    'description' => 'Leegality workflow profiles and the contract-to-workflow mapping',

    'preflight' => array(

        'settings_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_settings'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 203 has not run, so there is no settings table for '
                       . 'the provider credentials these profiles are used with. This check is also '
                       . 'the control: it must return 1, so a predicate that has stopped matching '
                       . 'anything fails the gate instead of passing it.',
        ),

        'profiles_table_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_profiles'",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. The profiles table already exists, so this migration has run. '
                       . 'A migration that cannot tell whether it has already run is the one that '
                       . 'eventually runs twice against something that matters.',
        ),

        'audit_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_audit'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 202 has not run. Every change to a workflow mapping '
                       . 'is an audited event — it decides who signs in what order — and without the '
                       . 'audit table those changes would leave no history.',
        ),
    ),

    'reports' => array(
        'contracts_present' => "SELECT COUNT(*) AS contracts FROM `{P}contracts`",
    ),

    'up' => array(

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_profiles` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `label` VARCHAR(190) NOT NULL,
          `environment` VARCHAR(20) NOT NULL DEFAULT 'sandbox',
          `profile_id` VARCHAR(190) NOT NULL,
          `contract_template_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `signer_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
          `signer_roles` VARCHAR(500) NOT NULL DEFAULT '',
          `ordering_mode` VARCHAR(20) NOT NULL DEFAULT 'sequential',
          `auth_method` VARCHAR(20) NOT NULL DEFAULT 'otp',
          `kyc_policy` VARCHAR(30) NOT NULL DEFAULT 'none',
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `validated_at` INT UNSIGNED NULL,
          `validated_by` INT NOT NULL DEFAULT 0,
          `validation_note` VARCHAR(500) NULL,
          `created_by` INT NOT NULL DEFAULT 0,
          `created_at` INT UNSIGNED NOT NULL,
          `updated_by` INT NOT NULL DEFAULT 0,
          `updated_at` INT UNSIGNED NULL,
          PRIMARY KEY (`id`),
          /*
           * A profileId is unique per environment, not globally: sandbox and
           * production are separate accounts with separate ids, and the same
           * string could legitimately appear in both.
           */
          UNIQUE KEY `cv_profile_env_id` (`environment`,`profile_id`),
          /*
           * One ACTIVE profile per template per environment. `is_active` is part
           * of the key so retired rows can share a template without colliding —
           * the same lesson migration 206 taught about replaced roster signers,
           * applied before it could bite a second time.
           */
          UNIQUE KEY `cv_profile_template` (`environment`,`contract_template_id`,`is_active`),
          KEY `cv_profile_active` (`is_active`,`environment`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /*
         * Which profile a request was actually sent through, recorded on the
         * request rather than looked up later. A profile can be edited or
         * retired after a contract goes out; the contract's own history must not
         * change when that happens.
         */
        "ALTER TABLE `{P}payplex_cv_requests`
           ADD COLUMN `profile_id` VARCHAR(190) NULL AFTER `provider`",

        "ALTER TABLE `{P}payplex_cv_requests`
           ADD COLUMN `profile_row_id` BIGINT UNSIGNED NULL AFTER `profile_id`",

        /*
         * The roster snapshot hash. Taken at send, over the promoted signers, so
         * a later edit to the roster is detectable rather than merely
         * disapproved of.
         */
        "ALTER TABLE `{P}payplex_cv_requests`
           ADD COLUMN `roster_sha256` CHAR(64) NULL AFTER `original_sha256`",
    ),

    'down' => array(
        /*
         * Dropping this destroys no signature and no evidence. It loses the
         * workflow declarations, which are configuration, and the record of
         * which profile each request used — so the columns go last, after
         * anybody reading this has had the chance to export them.
         */
        "ALTER TABLE `{P}payplex_cv_requests` DROP COLUMN `roster_sha256`",
        "ALTER TABLE `{P}payplex_cv_requests` DROP COLUMN `profile_row_id`",
        "ALTER TABLE `{P}payplex_cv_requests` DROP COLUMN `profile_id`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_profiles`",
    ),
);
