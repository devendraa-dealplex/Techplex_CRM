<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Migration 004 — a record of credential changes that contains no credentials.
 *
 * WHAT WAS MISSING
 * ----------------
 * Clearing a credential worked and rotating one worked, and neither left a
 * trace. So "the integration stopped working on Tuesday" could not be checked
 * against "somebody replaced the App Secret on Tuesday", which is the first
 * thing worth knowing and was unanswerable.
 *
 * The activity log records that *settings were saved* — it deliberately does
 * not say which credential changed, because that line is written by the same
 * code that must never handle a value. This table closes the gap without
 * reopening that risk.
 *
 * WHAT IT STORES, AND WHAT IT CANNOT
 * ----------------------------------
 *   option_name    which credential
 *   action         set | changed | cleared
 *   fp_before      first 8 hex of SHA-256 of the previous value, or ''
 *   fp_after       first 8 hex of SHA-256 of the new value, or ''
 *   len_before     length of the previous value
 *   len_after      length of the new value
 *   changed_by     staff id
 *   changed_at     when
 *
 * **No column can hold a credential.** `fp_before` and `fp_after` are
 * VARCHAR(8) — eight hex characters is not a truncated secret, it is a
 * fingerprint, and the column is too narrow to store anything else even if
 * some future caller tried. That is a deliberate schema-level guarantee rather
 * than a promise in a comment: a test asserts the width.
 *
 * A length is recorded because a truncated paste is the most common
 * credential failure and is otherwise invisible — 242 chars becoming 60 is the
 * whole diagnosis.
 *
 * SAFETY
 * ------
 * Additive only. One new module-owned table, one option. No column dropped,
 * renamed or retyped; no row written, deleted or backfilled; no core table
 * touched. Guarded, so a re-run is a no-op.
 */
return array(
    'version'             => 4,
    'name'                => '004_credential_events',
    'touches_core_tables' => false,

    'up' => array(
        array(
            'kind'  => 'create_table',
            'table' => 'leadgen_facebook_credential_events',
            'sql'   => 'CREATE TABLE `{T}` (
                          `id` INT(11) NOT NULL AUTO_INCREMENT,
                          `option_name` VARCHAR(64) NOT NULL,
                          `action` VARCHAR(16) NOT NULL,
                          `fp_before` VARCHAR(8) NULL,
                          `fp_after` VARCHAR(8) NULL,
                          `len_before` INT(11) NOT NULL DEFAULT 0,
                          `len_after` INT(11) NOT NULL DEFAULT 0,
                          `changed_by` INT(11) NOT NULL DEFAULT 0,
                          `changed_at` DATETIME NOT NULL,
                          `changed_epoch` BIGINT(20) NOT NULL,
                          PRIMARY KEY (`id`),
                          KEY `idx_option` (`option_name`),
                          KEY `idx_when` (`changed_epoch`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ),
    ),

    /**
     * The retry/error monitor's one setting.
     *
     * Seeded at the library default so the monitor is active from the first
     * delivery rather than from the first visit to the settings page. It only
     * ever affects what the reports screen *says*; no threshold here can cause
     * anything to be sent, deleted or retried.
     */
    'options' => array(
        'facebook_health_window_hours' => '168',
    ),

    'down' => array(
        'DROP TABLE IF EXISTS `{P}leadgen_facebook_credential_events`',
    ),

    'down_warning' => 'Dropping leadgen_facebook_credential_events destroys the '
        . 'record of when each credential was set, changed or cleared — which is '
        . 'the first thing anyone checks when an integration stops working after '
        . 'having worked. It holds fingerprints and lengths only, never a '
        . 'credential, so exporting it is safe and worth doing first.',
);
