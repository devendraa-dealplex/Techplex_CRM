<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Migration 007 — quarantine for uncontactable deliveries, and a duplicate
 * review queue that never merges anything by itself.
 *
 * WHY QUARANTINE
 * --------------
 * A delivery that carries neither an email nor a phone number produces nothing
 * anybody can act on. Creating a lead from it puts a row on the Leads list that
 * a salesperson opens, finds empty, and closes — and the third time that
 * happens they stop trusting the list. Dropping it instead is worse: the
 * delivery really happened, Meta really charged for it, and silence is how this
 * whole engagement started.
 *
 * So it is kept, in full, with its Page, Form, Campaign and Facebook lead id,
 * and it is kept OUT of the Leads list until somebody adds a way to contact the
 * person.
 *
 * WHY THE DUPLICATE QUEUE EXISTS INSTEAD OF MERGING
 * ------------------------------------------------
 * The module used to attach a delivery whose email or phone matched an existing
 * lead to that lead, creating nothing new. Two colleagues filling the same form
 * from one switchboard number are two leads and two commissions, and under that
 * rule the second one disappeared with no record that it ever arrived.
 *
 * Now every distinct Facebook lead id gets its own lead, and a match produces a
 * row here for a person to judge. Nothing is merged automatically, nothing is
 * discarded, and a decision carries the name of whoever made it and their
 * reason.
 *
 * WHAT IS STORED, AND WHAT IS NOT
 * -------------------------------
 * The duplicate rows store a salted hash of the matching identifier, never the
 * identifier. A reviewer needs to know that two leads matched on a phone
 * number; they can read the number on either lead. A second unprotected copy of
 * every lead's contact details in a side table is a liability, not a feature.
 *
 * SAFETY
 * ------
 * Two new module tables. No core table, no ALTER, no existing row changed.
 */
return array(
    'version'             => 7,
    'name'                => '007_quarantine_and_duplicates',
    'touches_core_tables' => false,

    'up' => array(
        array(
            'kind'  => 'create_table',
            'table' => 'leadgen_facebook_quarantine',
            'sql'   => "CREATE TABLE IF NOT EXISTS `{P}leadgen_facebook_quarantine` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `channel` VARCHAR(32) NOT NULL DEFAULT '',
                `fb_ref` VARCHAR(191) NOT NULL DEFAULT '',
                `request_id` VARCHAR(40) NOT NULL DEFAULT '',
                `page_id` VARCHAR(64) NOT NULL DEFAULT '',
                `form_id` VARCHAR(64) NOT NULL DEFAULT '',
                `campaign_id` VARCHAR(64) NOT NULL DEFAULT '',
                `display_name` VARCHAR(191) NOT NULL DEFAULT '',
                `reason` VARCHAR(64) NOT NULL DEFAULT '',
                `field_summary` TEXT NULL,
                `released_lead_id` INT(11) NOT NULL DEFAULT 0,
                `reviewed_by` INT(11) NOT NULL DEFAULT 0,
                `reviewed_epoch` BIGINT(20) NOT NULL DEFAULT 0,
                `created_epoch` BIGINT(20) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ),
        array(
            /*
             * Idempotency for quarantined deliveries, by the same rule that
             * governs leads: one Facebook lead id, one record. A replayed
             * uncontactable delivery must not stack up rows any more than a
             * replayed contactable one may create a second lead.
             */
            'kind'    => 'add_unique_index',
            'table'   => 'leadgen_facebook_quarantine',
            'index'   => 'uniq_quarantine_ref',
            'columns' => array('channel', 'fb_ref'),
            'sql'     => "ALTER TABLE `{P}leadgen_facebook_quarantine`
                          ADD UNIQUE KEY `uniq_quarantine_ref` (`channel`, `fb_ref`)",
        ),
        array(
            'kind'    => 'add_index',
            'table'   => 'leadgen_facebook_quarantine',
            'index'   => 'idx_quarantine_open',
            'columns' => array('released_lead_id', 'created_epoch'),
            'sql'     => "ALTER TABLE `{P}leadgen_facebook_quarantine`
                          ADD INDEX `idx_quarantine_open` (`released_lead_id`, `created_epoch`)",
        ),
        array(
            'kind'  => 'create_table',
            'table' => 'leadgen_facebook_duplicates',
            'sql'   => "CREATE TABLE IF NOT EXISTS `{P}leadgen_facebook_duplicates` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `lead_id` INT(11) NOT NULL,
                `other_lead_id` INT(11) NOT NULL,
                `match_type` VARCHAR(16) NOT NULL DEFAULT '',
                `match_key` VARCHAR(32) NOT NULL DEFAULT '',
                `fb_ref` VARCHAR(191) NOT NULL DEFAULT '',
                `other_fb_ref` VARCHAR(191) NOT NULL DEFAULT '',
                `status` VARCHAR(16) NOT NULL DEFAULT 'open',
                `decided_by` INT(11) NOT NULL DEFAULT 0,
                `decided_reason` VARCHAR(255) NOT NULL DEFAULT '',
                `decided_epoch` BIGINT(20) NOT NULL DEFAULT 0,
                `created_epoch` BIGINT(20) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ),
        array(
            /*
             * One marker per pair per reason. Without this, a lead matching an
             * older one on both email and phone, re-examined on a retry, would
             * accumulate markers and the queue would grow faster than anybody
             * could clear it.
             */
            'kind'    => 'add_unique_index',
            'table'   => 'leadgen_facebook_duplicates',
            'index'   => 'uniq_pair_type',
            'columns' => array('lead_id', 'other_lead_id', 'match_type'),
            'sql'     => "ALTER TABLE `{P}leadgen_facebook_duplicates`
                          ADD UNIQUE KEY `uniq_pair_type` (`lead_id`, `other_lead_id`, `match_type`)",
        ),
        array(
            'kind'    => 'add_index',
            'table'   => 'leadgen_facebook_duplicates',
            'index'   => 'idx_open_status',
            'columns' => array('status', 'created_epoch'),
            'sql'     => "ALTER TABLE `{P}leadgen_facebook_duplicates`
                          ADD INDEX `idx_open_status` (`status`, `created_epoch`)",
        ),
    ),

    /**
     * Seeded empty and conservative.
     *
     * `facebook_shared_numbers` is a list an administrator maintains; nobody can
     * know another business's switchboard numbers in advance, and inventing some
     * would suppress real duplicate markers.
     *
     * The threshold is the automatic half of the same rule: a number already on
     * three leads is a company's number whatever anybody remembered to type.
     */
    'options' => array(
        'facebook_shared_numbers'          => '',
        'facebook_shared_number_threshold' => '3',
        'facebook_duplicate_detection'     => '1',
    ),

    'down' => array(
        'DROP TABLE IF EXISTS `{P}leadgen_facebook_quarantine`',
        'DROP TABLE IF EXISTS `{P}leadgen_facebook_duplicates`',
    ),

    'down_warning' => 'Dropping the quarantine table discards every delivery that arrived '
        . 'without a usable email or phone number — those records exist NOWHERE else, because '
        . 'no lead was created for them. Export it first. Dropping the duplicates table loses '
        . 'the review markers and every decision a reviewer recorded against them; the LEADS '
        . 'themselves are untouched by either drop, since nothing here ever merged or deleted '
        . 'a lead.',
);
