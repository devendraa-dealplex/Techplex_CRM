<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Migration 006 — the queue for tag and reference writes that failed.
 *
 * WHY THIS EXISTS
 * ---------------
 * Tagging is deliberately best-effort: a lead that exists but is missing its
 * campaign id is a lead somebody can still call, while a lead rolled back
 * because the tag table hiccuped is a lead that is gone. So an individual tag or
 * custom-field write reports failure instead of aborting the delivery.
 *
 * That trade is only defensible if the failure is *visible*. "Best-effort" and
 * "fails silently" are the same thing from the outside, and this module exists
 * because of a failure nobody could see. So every metadata failure now leaves
 * three marks:
 *
 *   1. a row here — redacted, retryable, with the lead it belongs to;
 *   2. a line in the activity log naming what failed;
 *   3. an alert on the module's own reports screen while anything is pending.
 *
 * WHAT IS STORED, AND WHAT IS NOT
 * -------------------------------
 * Field slugs and tag names — the labels, never the lead's contact details. The
 * error text is truncated and redacted before it is written, because a database
 * driver's exception message can quote the row it was inserting.
 *
 * SAFETY
 * ------
 * Additive: one new module table, no core table, no existing row touched. The
 * rollback drops only this table, and a pending item lost to a rollback costs a
 * label on a lead that still exists — which is why the warning says to drain
 * the queue first rather than treating the drop as free.
 */
return array(
    'version'             => 6,
    'name'                => '006_enrichment_queue',
    'touches_core_tables' => false,

    'up' => array(
        array(
            'kind'  => 'create_table',
            'table' => 'leadgen_facebook_enrichment',
            'sql'   => "CREATE TABLE IF NOT EXISTS `{P}leadgen_facebook_enrichment` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `lead_id` INT(11) NOT NULL,
                `request_id` VARCHAR(40) NOT NULL DEFAULT '',
                `channel` VARCHAR(32) NOT NULL DEFAULT '',
                `fb_ref` VARCHAR(191) NOT NULL DEFAULT '',
                `failures` TEXT NULL,
                `last_error` VARCHAR(255) NOT NULL DEFAULT '',
                `attempts` INT(11) NOT NULL DEFAULT 0,
                `resolved` TINYINT(1) NOT NULL DEFAULT 0,
                `created_epoch` BIGINT(20) NOT NULL DEFAULT 0,
                `last_attempt_epoch` BIGINT(20) NOT NULL DEFAULT 0,
                `resolved_epoch` BIGINT(20) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ),
        array(
            'kind'    => 'add_index',
            'table'   => 'leadgen_facebook_enrichment',
            'index'   => 'idx_pending',
            'columns' => array('resolved', 'created_epoch'),
            'sql'     => "ALTER TABLE `{P}leadgen_facebook_enrichment`
                          ADD INDEX `idx_pending` (`resolved`, `created_epoch`)",
        ),
        array(
            'kind'    => 'add_index',
            'table'   => 'leadgen_facebook_enrichment',
            'index'   => 'idx_lead',
            'columns' => array('lead_id'),
            'sql'     => "ALTER TABLE `{P}leadgen_facebook_enrichment`
                          ADD INDEX `idx_lead` (`lead_id`)",
        ),
    ),

    /**
     * How many automatic retries an item gets before it stops being retried and
     * starts being a thing a human has to look at. Seeded as an option so it is
     * tunable without a code change, and low on purpose: an item that has failed
     * five times is not going to succeed on the sixth, and a queue that retries
     * forever is a queue nobody reads.
     */
    'options' => array(
        'facebook_enrichment_max_attempts' => '5',
    ),

    'down' => array(
        'DROP TABLE IF EXISTS `{P}leadgen_facebook_enrichment`',
    ),

    'down_warning' => 'Dropping this table discards any pending metadata repairs. '
        . 'The LEADS are unaffected — they were saved successfully; what is lost is the '
        . 'record that some of them are missing a tag or a Facebook reference, and the '
        . 'ability to retry it. Drain the queue from Leads > Facebook Leads > Enrichment '
        . 'before rolling back, or export this table first.',
);
