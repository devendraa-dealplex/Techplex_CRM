<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Migration 003 — what the rate limiter needs in order to count.
 *
 * WHY A THIRD MIGRATION RATHER THAN AN EDIT TO 002
 * ------------------------------------------------
 * Migration 002 has not run anywhere yet, so editing it would have been
 * simpler and nobody would have noticed. It is still the wrong move: 002's
 * bytes are already published in a deployment manifest with a SHA-256 beside
 * them, and a migration file whose contents change after its hash has been
 * recorded is a migration nobody can verify afterwards. Numbered files are
 * append-only or they are not a record.
 *
 * WHAT IT ADDS
 * ------------
 *   source_key   a salted, truncated SHA-256 of the caller's address. The
 *                delivery log is the rate limiter's counter — there is no
 *                Redis on this host and no second store worth introducing for
 *                this — so counting "requests from this source in the last
 *                minute" needs a column to group by.
 *
 *                The raw address is NOT stored. Hashing an IPv4 address with
 *                no salt is reversible by anyone willing to compute four
 *                billion hashes, so the salt is required and is generated once
 *                per install; see facebook_ip_salt below.
 *
 * The two options are the limiter's configuration and its salt. The salt is
 * seeded EMPTY on purpose: `add_option()` cannot generate a random value, and
 * a salt shipped in a migration file would be the same on every install, which
 * is the same as having none. The code generates it on first use and stores it.
 *
 * SAFETY
 * ------
 * Additive only. One nullable column on a module-owned table, one index, two
 * options. No column dropped, renamed or retyped; no row written, deleted or
 * backfilled; **no core table touched**. Guarded, so a re-run is a no-op.
 */
return array(
    'version'             => 3,
    'name'                => '003_source_key_and_rate_limit',
    'touches_core_tables' => false,

    'up' => array(
        array(
            'kind'   => 'add_column',
            'table'  => 'leadgen_facebook_deliveries',
            'column' => 'source_key',
            'sql'    => 'ALTER TABLE `{T}` ADD COLUMN `source_key` VARCHAR(32) NULL AFTER `timezone`',
        ),
        array(
            'kind'    => 'add_index',
            'table'   => 'leadgen_facebook_deliveries',
            'index'   => 'idx_source_window',
            'columns' => array('source_key', 'received_epoch'),
            'sql'     => 'ALTER TABLE `{T}` ADD KEY `idx_source_window` (`source_key`, `received_epoch`)',
        ),
    ),

    /**
     * `facebook_rate_limit_per_minute` is seeded at the library default rather
     * than left empty, so the limiter is on from the first delivery rather than
     * from the first time somebody visits the settings page.
     *
     * 120 a minute is roughly two orders of magnitude above the busiest real
     * lead-ads volume this CRM has seen, and low enough that a stranger cannot
     * make the server compute HMACs all day. It is per source, so Meta is not
     * competing with the abuser for the same budget.
     */
    'options' => array(
        'facebook_rate_limit_per_minute' => '120',
        'facebook_ip_salt'               => '',
    ),

    'down' => array(
        'ALTER TABLE `{P}leadgen_facebook_deliveries` DROP INDEX `idx_source_window`',
        'ALTER TABLE `{P}leadgen_facebook_deliveries` DROP COLUMN `source_key`',
    ),

    'down_warning' => 'Dropping source_key disables per-source rate limiting: the '
        . 'limiter has no column to group by and every caller shares one budget, '
        . 'which lets one abusive source throttle Meta. Export the delivery log '
        . 'before any rollback that includes this step.',
);
