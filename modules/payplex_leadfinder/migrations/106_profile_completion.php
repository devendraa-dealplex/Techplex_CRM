<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');
/**
 * Lead Finder migration 106 — the three profile fields that were missing, and
 * rotation history.
 *
 * WHAT MIGRATION 101 ALREADY GAVE US
 * ----------------------------------
 * `api_key_fingerprint`, `last_test_at`, `last_test_result`, `expires_on`,
 * `admin_notes`, `created_by`, `created_at`, `updated_at`. Most of the required
 * profile surface already exists. Three things do not:
 *
 *   per_staff_daily_limit   The per-employee ceiling lives in a single global
 *                           config row (`default_staff_detail_limit_daily`), so
 *                           every profile shares one number. An administrator
 *                           who gives one employee a dedicated key cannot give
 *                           that key its own per-employee cap, which is the
 *                           whole point of a dedicated key.
 *
 *   effective_from          `expires_on` says when a profile stops. Nothing said
 *                           when it starts. A key provisioned today for next
 *                           month's campaign had to be left inactive and
 *                           switched on by hand, which is an alarm clock made of
 *                           a person.
 *
 *   rotation history        Rotating a key overwrote `api_key_enc` and left no
 *                           record that a rotation happened. After an incident
 *                           the question is "when was this key replaced, and by
 *                           whom" — and the honest answer was nobody knows.
 *
 * THE ROTATION TABLE HOLDS NO KEY MATERIAL
 * ----------------------------------------
 * Not the old key, not the new one, not a reversible transform of either. Only
 * the fingerprints — which are truncated hashes and cannot be turned back into a
 * key — plus who and when. A rotation log that stored the superseded key would
 * be a worse leak than the thing it audits, because an old key is exactly what
 * an attacker wants after a rotation.
 *
 * Additive: two nullable columns and one new module-owned table. No core table
 * is touched, nothing is dropped or backfilled, and `down` reverses exactly what
 * `up` creates.
 */
return array(
    'id'          => 106,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Profile completion: per-staff ceiling, effective date, rotation history',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 101,

    'up' => array(
        "ALTER TABLE `{P}payplex_lf_api_profiles`
           ADD COLUMN `per_staff_daily_limit` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `daily_usage_limit`",

        "ALTER TABLE `{P}payplex_lf_api_profiles`
           ADD COLUMN `effective_from` DATE NULL AFTER `expires_on`",

        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_key_rotations` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `profile_id` INT UNSIGNED NOT NULL,
          `old_fingerprint` VARCHAR(24) NULL,
          `new_fingerprint` VARCHAR(24) NULL,
          `reason` VARCHAR(191) NULL,
          `rotated_by` INT NOT NULL DEFAULT 0,
          `rotated_at` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`id`),
          KEY `lf_rot_profile` (`profile_id`),
          KEY `lf_rot_when` (`rotated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('profile_select_policy','assigned_only','How a profile is chosen when an employee does not pick one: assigned_only means only profiles explicitly assigned to that employee are eligible, and the choice is the lowest eligible id so it is deterministic and reproducible. There is deliberately no fallback to an unassigned or inactive profile — spending on a key nobody granted you is worse than a refused search.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey = 'profile_select_policy'",
        "DROP TABLE IF EXISTS `{P}payplex_lf_key_rotations`",
        "ALTER TABLE `{P}payplex_lf_api_profiles` DROP COLUMN `effective_from`",
        "ALTER TABLE `{P}payplex_lf_api_profiles` DROP COLUMN `per_staff_daily_limit`",
    ),

    'down_warning' => 'Rolling back drops the key rotation history, which is the only record '
                    . 'of when each API key was replaced and by whom. Export '
                    . 'payplex_lf_key_rotations first — it holds no key material, only '
                    . 'fingerprints, so it is safe to keep.',

    'reports' => array(
        "SELECT COUNT(*) AS profiles_with_per_staff_ceiling FROM `{P}payplex_lf_api_profiles` WHERE `per_staff_daily_limit` > 0",
        "SELECT COUNT(*) AS rotation_rows FROM `{P}payplex_lf_key_rotations`",
        "SELECT cvalue AS profile_select_policy FROM `{P}payplex_lf_config` WHERE ckey='profile_select_policy' ORDER BY effective_from DESC LIMIT 1",
    ),
);
