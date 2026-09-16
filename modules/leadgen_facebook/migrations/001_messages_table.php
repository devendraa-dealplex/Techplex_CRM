<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Migration 001 — the module's own message/claim table.
 *
 * This table already exists on staging, created by a `CREATE TABLE IF NOT
 * EXISTS` inside the activation hook. That is why this migration is written as
 * a guarded create rather than a fresh one: on staging it is a no-op, on
 * production it is the first thing that runs.
 *
 * WHAT CHANGED IN THE DEFINITION
 * ------------------------------
 * The staging table has `KEY fb_ref (fb_ref)` — an index, not a constraint. An
 * index makes the duplicate *check* fast; it does nothing to stop a duplicate
 * being written. The check that was relying on it was:
 *
 *     SELECT ... WHERE fb_ref = ? AND channel = 'leadgen'   -- nothing found?
 *     ... then create the lead and insert the row
 *
 * Two deliveries of the same lead arriving together — which is exactly what
 * Meta's retry does when a response is slow — both run the SELECT before either
 * runs the INSERT, both find nothing, and both create a lead. The window is
 * small and it is real, and it is the one failure the "repeat the delivery and
 * prove no second lead" test is meant to catch.
 *
 * A fresh install therefore gets `UNIQUE KEY uniq_channel_ref (channel,
 * fb_ref)` from the start, and migration 002 adds it to installs that already
 * have the table. With the constraint in place the claim becomes a single
 * atomic INSERT whose success or failure *is* the answer, and the SELECT stops
 * being load-bearing.
 *
 * `channel` is part of the key, not `fb_ref` alone: a Messenger PSID and a
 * leadgen id are different namespaces and a collision between them, however
 * unlikely, must not silently drop a lead.
 *
 * Additive. Creates one module-owned table. No core table is touched.
 */
return array(
    'version'             => 1,
    'name'                => '001_messages_table',
    'touches_core_tables' => false,

    'up' => array(
        array(
            'kind'  => 'create_table',
            'table' => 'leadgen_facebook_messages',
            'sql'   => 'CREATE TABLE `{T}` (
                          `id` INT(11) NOT NULL AUTO_INCREMENT,
                          `lead_id` INT(11) DEFAULT NULL,
                          `channel` VARCHAR(20) NOT NULL,
                          `fb_ref` VARCHAR(64) NOT NULL,
                          `message_body` TEXT,
                          `raw_payload` LONGTEXT,
                          `date_created` DATETIME NOT NULL,
                          PRIMARY KEY (`id`),
                          UNIQUE KEY `uniq_channel_ref` (`channel`, `fb_ref`),
                          KEY `lead_id` (`lead_id`),
                          KEY `fb_ref` (`fb_ref`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ),
    ),

    'down' => array(
        'DROP TABLE IF EXISTS `{P}leadgen_facebook_messages`',
    ),

    'down_warning' => 'Dropping this table destroys the idempotency record. Every '
        . 'delivery Meta has already sent would be treated as new if it were '
        . 'replayed. Export it before any rollback that includes this step.',
);
