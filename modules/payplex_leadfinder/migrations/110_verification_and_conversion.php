<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');
/**
 * Lead Finder migration 110 — the verification fields that were missing, and
 * the conversion ledger the maker-checker gate is made of.
 *
 * WHAT THE PROSPECT TABLE ALREADY HAD
 * -----------------------------------
 * `call_disposition`, `interest_level`, `verification_notes`, `rejection_reason`,
 * `next_followup_at`, `verified_contact_name`, `verified_designation`,
 * `verified_email`, `verified_at`, `converted_lead_id`. Most of the surface.
 *
 * WHAT IT DID NOT HAVE, AND WHY EACH MATTERS
 * ------------------------------------------
 *   verified_by     `verified_at` recorded WHEN a prospect was verified and
 *                   nothing recorded BY WHOM. The approver's whole job is to
 *                   check somebody else's work, and they could not see whose it
 *                   was. It is also half of the maker-checker rule: without it
 *                   there is no submitter to compare an approver against.
 *
 *   phone_verified  "There is a phone number on the row" and "somebody rang it
 *   email_verified  and it was right" are different facts. The conversion gate
 *                   was reading the first and calling it the second.
 *
 *   requirement     What the business actually asked for. Without it a lead
 *                   arrives in the CRM with an interest level and no subject,
 *                   and whoever picks it up has to ring back and start again.
 *
 * WHY CONVERSIONS GET A TABLE RATHER THAN A COLUMN
 * ------------------------------------------------
 * A `converted_lead_id` column can hold the outcome. It cannot hold who
 * submitted, who approved, when, on what evidence, or that a rejection ever
 * happened — and a maker-checker control whose history is a single nullable
 * column is a control nobody can audit. The UNIQUE key on `prospect_id` is what
 * makes "one conversion per prospect" a property of the database rather than a
 * promise, and `idem_key` makes a retried approval find the existing decision
 * instead of creating a second lead.
 *
 * Additive. Four nullable columns, one new module table. No core table is
 * touched, nothing is dropped, and `down` reverses exactly what `up` creates.
 */
return array(
    'id'          => 110,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Verification authorship, verified flags, and the conversion ledger',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 109,

    'up' => array(
        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `verified_by` INT NOT NULL DEFAULT 0 AFTER `verified_at`",

        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `phone_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `phone_weak_key`",

        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `email_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `verified_email`",

        "ALTER TABLE `{P}payplex_lf_prospects`
           ADD COLUMN `requirement` VARCHAR(500) NULL AFTER `verified_designation`",

        "CREATE INDEX `lf_verified_by` ON `{P}payplex_lf_prospects` (`verified_by`,`verified_at`)",

        /*
         * `prospect_id` is UNIQUE, not merely indexed.
         *
         * One prospect, one conversion. Without the constraint, two approvers
         * acting at once — or one approver double-clicking — would each insert a
         * row and each create a lead, and the CRM would hold two leads for one
         * business with no way to tell which was meant. The constraint is what
         * the claim-first insert depends on.
         *
         * `lead_id` is NULL until an approval actually creates one, so a
         * rejected or pending conversion cannot be mistaken for a converted one.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_conversions` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `prospect_id` INT UNSIGNED NOT NULL,
          `idem_key` CHAR(64) NOT NULL,
          `state` VARCHAR(12) NOT NULL DEFAULT 'pending',
          `submitted_by` INT NOT NULL,
          `submitted_at` INT UNSIGNED NOT NULL,
          `decided_by` INT NOT NULL DEFAULT 0,
          `decided_at` INT UNSIGNED NULL,
          `decision_reason` VARCHAR(500) NULL,
          `lead_id` INT UNSIGNED NULL,
          `evidence` TEXT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `lf_conv_prospect` (`prospect_id`),
          UNIQUE KEY `lf_conv_idem` (`idem_key`),
          KEY `lf_conv_state` (`state`,`submitted_at`),
          KEY `lf_conv_submitter` (`submitted_by`,`submitted_at`),
          KEY `lf_conv_approver` (`decided_by`,`decided_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('conversion_requires_independent_approver','1','Whether a prospect submitted for conversion must be approved by somebody other than the submitter. 1 and not configurable to 0 through the interface: it is the control the whole conversion gate exists to provide, and an administrator who also makes calls holds both capabilities, so without it a two-person control silently becomes a one-person one.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('conversion_lead_source_name','Lead Finder','The value written to the CRM lead source when a conversion is approved, so converted prospects can be told apart from leads entered by hand or arriving from Facebook.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey IN (
            'conversion_requires_independent_approver','conversion_lead_source_name')",
        "DROP TABLE IF EXISTS `{P}payplex_lf_conversions`",
        "DROP INDEX `lf_verified_by` ON `{P}payplex_lf_prospects`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `requirement`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `email_verified`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `phone_verified`",
        "ALTER TABLE `{P}payplex_lf_prospects` DROP COLUMN `verified_by`",
    ),

    'down_warning' => 'Rolling back DROPS THE ENTIRE CONVERSION HISTORY: who submitted each '
                    . 'prospect, who approved or rejected it, when, on what evidence, and which '
                    . 'CRM lead resulted. That history is the only evidence the maker-checker '
                    . 'control was ever applied, and leads already created in tblleads are NOT '
                    . 'removed — so after this rollback the CRM holds leads whose approval cannot '
                    . 'be demonstrated. Export payplex_lf_conversions first and keep it with the '
                    . 'audit records; it holds no key material. It also drops verified_by, after '
                    . 'which no prospect records who verified it, and a re-submission could be '
                    . 'approved by the person who made the call.',

    'reports' => array(
        "SELECT state, COUNT(*) AS c FROM `{P}payplex_lf_conversions` GROUP BY state",
        "SELECT COUNT(*) AS converted_with_lead FROM `{P}payplex_lf_conversions` WHERE `lead_id` IS NOT NULL",
        "SELECT COUNT(*) AS self_approved FROM `{P}payplex_lf_conversions` WHERE `decided_by` = `submitted_by` AND `decided_by` <> 0",
    ),
);
