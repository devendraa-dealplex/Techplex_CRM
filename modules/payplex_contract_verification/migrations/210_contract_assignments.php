<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT')
    or defined('PAYPLEX_CV_MIGRATION_TEST') or exit('No direct script access allowed');

/**
 * 210 — explicit contract assignments.
 *
 * WHY THIS TABLE EXISTS
 * ---------------------
 * Perfex answers "which contracts may this person see" with two options: all of
 * them, or the ones they created. There is no third answer, so a KYC reviewer
 * who needs four cases this month gets "all" — because "none" stops them
 * working — and the permission stops meaning anything on exactly the accounts
 * it was written for.
 *
 * This is the third answer. A row here says "this person, this contract, this
 * role, until this date". It grants nothing on its own: the actor must also
 * hold the capability. Both doors, every time.
 *
 * ONE TABLE, NOT TWO
 * ------------------
 * The brief asked for contract assignments and KYC reviewer assignments. They
 * are the same shape — person, record, role, validity — and splitting them
 * would mean two tables to query, two to keep in step, and two places for the
 * expiry rule to be implemented slightly differently. `assignment_role`
 * distinguishes them, and `Contract_assignment::roles()` is the single list.
 *
 * REVOCATION IS A COLUMN, NOT A DELETE
 * ------------------------------------
 * "Who could see this customer's identity documents in March" gets asked after
 * something has gone wrong, and a deleted row answers it with silence. Nothing
 * here is ever deleted in normal operation: `revoked_at`, `revoked_by` and
 * `revocation_reason` retire a row and leave the history intact. Changing a
 * contract's owner does not rewrite it either — the history records who HAD
 * access, not who has it now.
 *
 * NO FOREIGN KEY TO tblstaff OR tblcontracts
 * ------------------------------------------
 * Deliberate, and the same decision the rest of this module made. A cascading
 * delete on a core table would silently erase the record of who had access to a
 * contract at the moment somebody deletes a staff member — which is the single
 * worst time to lose it. The ids are indexed and validated in the model.
 */

return array(

    'id'          => '210',
    'description' => 'Explicit per-contract staff assignments with roles, expiry and revocation',

    'preflight' => array(

        'contracts_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}contracts'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. There are no contracts to assign anybody to. This check is '
                       . 'also the control: its required answer is non-zero, so a predicate that '
                       . 'has stopped matching anything fails the gate instead of sailing through '
                       . 'it with the same 0 that means "clear to apply".',
        ),

        'staff_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}staff'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Assignments name staff members; without the staff table '
                       . 'every row would point at nothing.',
        ),

        'assignments_table_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_assignments'",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. The assignments table already exists, so this migration has '
                       . 'run. Applying it again would not error — CREATE TABLE IF NOT EXISTS skips '
                       . 'silently — it would leave a table of unknown shape in place while '
                       . 'reporting success.',
        ),

        'audit_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_audit'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 202 has not run. Granting and revoking access is '
                       . 'an audited event and there would be nowhere to record it.',
        ),
    ),

    'reports' => array(
        'contracts_present' => "SELECT COUNT(*) AS contracts FROM `{P}contracts`",
        'active_staff'      => "SELECT COUNT(*) AS staff FROM `{P}staff` WHERE active = 1",
    ),

    'up' => array(

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_assignments` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `contract_id` INT UNSIGNED NOT NULL,
          `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
          `staff_id` INT NOT NULL,
          `assignment_role` VARCHAR(40) NOT NULL,
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `assigned_by` INT NOT NULL DEFAULT 0,
          `assigned_at` INT UNSIGNED NOT NULL,
          `expires_at` INT UNSIGNED NULL,
          `revoked_at` INT UNSIGNED NULL,
          `revoked_by` INT NOT NULL DEFAULT 0,
          `revocation_reason` VARCHAR(500) NULL,
          `note` VARCHAR(500) NULL,
          PRIMARY KEY (`id`),
          /*
           * One ACTIVE assignment per person per role per contract. `is_active`
           * is part of the key so a revoked row can coexist with a fresh grant
           * of the same role — the lesson migration 206 taught about replaced
           * roster signers, applied before it could bite a third time.
           */
          UNIQUE KEY `cv_assign_unique` (`contract_id`,`staff_id`,`assignment_role`,`is_active`),
          /* The lookup the authorization service makes on every request. */
          KEY `cv_assign_lookup` (`staff_id`,`contract_id`,`is_active`),
          /* \"Who is on this contract\" and \"what does this person hold\". */
          KEY `cv_assign_contract` (`contract_id`,`is_active`),
          KEY `cv_assign_client` (`client_id`,`staff_id`,`is_active`),
          /* The expiry sweep, and the reason a nightly job can be cheap. */
          KEY `cv_assign_expiry` (`is_active`,`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ),

    'down' => array(
        /*
         * Dropping this removes every record of who was granted access to what
         * and when it was taken away. It destroys no contract and no evidence,
         * but the access history is not reconstructible from anything else, so
         * export it before running this if the deployment has been live for
         * more than a few minutes.
         */
        "DROP TABLE IF EXISTS `{P}payplex_cv_assignments`",
    ),

    'down_warning' =>
        'Rolling 210 back drops the assignments table. Every record of who was granted access to '
        . 'which contract, in which role, by whom, and when it was revoked, is lost — and it cannot '
        . 'be reconstructed from any other table, because nothing else records it. Access itself '
        . 'reverts to Perfex\'s all-or-own behaviour, which means anyone relying on an assignment '
        . 'immediately loses sight of their contracts and anyone with view-all keeps everything. '
        . 'Intended only for an immediate rollback, within minutes of a failed deployment.',
);
