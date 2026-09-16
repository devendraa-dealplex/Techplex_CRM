<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or defined('PAYPLEX_LF_PREFLIGHT') or exit('No direct script access allowed');
/**
 * Lead Finder migration 113 — suppression tombstones, the DNC register,
 * the purge queue and the re-key ledger.
 *
 * THE PROBLEM THIS SOLVES
 * -----------------------
 * A rejected business has to stop coming back. But the way it stops coming back
 * cannot be "keep its phone number on a list", because the same retention rules
 * that made us purge the prospect apply to the list. So the purge leaves a
 * tombstone: HMAC-SHA256 keys of the identifiers, under a server-side pepper
 * that lives outside the document root and outside this database.
 *
 * WHY HMAC AND NOT A HASH
 * -----------------------
 * An Indian mobile number is ten digits beginning 6-9. That is about four
 * billion candidates, which a plain `sha256(phone)` gives up in minutes on
 * ordinary hardware. A tombstone built that way would claim to have purged the
 * number while, in every sense that matters, still holding it. The pepper is
 * what makes the digest un-searchable, and it is why none of these columns is
 * ever populated from a code path that cannot read the keyring.
 *
 * WHY EVERY ROW CARRIES ITS PEPPER VERSION
 * ----------------------------------------
 * Rotation adds a version; it never replaces one. A tombstone written under v1
 * is only ever matched against v1, because re-deriving it under v2 is
 * impossible once the plaintext is gone. `pepper_version` is therefore part of
 * every unique key and every lookup, not metadata beside them. Retiring a
 * version is refused while any row still references it.
 *
 * WHY UNIQUENESS IS SCOPED THE WAY IT IS
 * --------------------------------------
 * The requirement is that repeated tombstones for one business are prevented
 * while genuinely distinct contacts are not. So:
 *
 *   - place id and source ref are UNIQUE, per pepper version and per
 *     suppression kind. Those identifiers are 1:1 with a listing by
 *     construction — two rows with the same Google place id are the same place.
 *   - phone and email are INDEXED, never unique. A shopping centre, a
 *     franchise group and a shared switchboard all put one number on many real
 *     businesses. A unique key there would refuse the second genuine tombstone
 *     and quietly leave that business unsuppressed, which is the exact failure
 *     the whole table exists to prevent. This is also why a phone match is
 *     treated as `possible` rather than `suppress` at read time.
 *   - the kind is part of the key on purpose. One business may hold BOTH a
 *     waste tombstone and a DNC tombstone. They expire differently and are
 *     deleted under different rules; collapsing them into one row would mean
 *     the waste expiry took the DNC promise with it.
 *
 * MySQL treats NULLs in a unique index as distinct, which is what makes a
 * partial tombstone — a place id but no phone — legal without a sentinel.
 *
 * WHY DNC HAS ITS OWN REGISTER
 * ----------------------------
 * The tombstone answers "is this business suppressed". It deliberately cannot
 * answer "who told us to stop, when, and how we know" — that is provenance, it
 * is the thing somebody will be asked for, and it must survive the purge, the
 * expiry sweep and a pepper rotation. So it is a separate table keyed to the
 * suppression, holding no contact details of its own.
 *
 * SCOPE
 * -----
 * Additive. Four new module-owned tables, two job-lock rows, config seeds. No
 * core table is touched, nothing is dropped, nothing is backfilled, and `down`
 * reverses exactly what `up` creates.
 */
return array(
    'id'          => 113,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Suppression tombstones, DNC register, purge queue and re-key ledger',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 112,

    'preflight' => array(
        'tables_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}'
                            AND TABLE_NAME IN ('{P}payplex_lf_suppressions','{P}payplex_lf_dnc_register',
                                               '{P}payplex_lf_purge_queue','{P}payplex_lf_pepper_rekeys')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. A table 113 creates already exists. CREATE TABLE IF NOT EXISTS '
                       . 'would skip it silently and leave a table of the wrong shape in place, which '
                       . 'surfaces later as a missing-column error in the purge job. Inspect the existing '
                       . 'table and reconcile by hand.',
        ),
        'job_lock_keys_free' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM `{P}payplex_lf_job_locks`
                          WHERE lock_key IN ('waste_purge','pepper_rekey')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY WITHOUT CHECKING. A lock row of that name already exists. If it is '
                       . 'held, a job is running or died holding it; clear it deliberately rather than '
                       . 'letting the INSERT IGNORE hide the situation.',
        ),
        'config_keys_free' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM `{P}payplex_lf_config`
                          WHERE ckey IN ('waste_suppression_days','waste_pii_retention_days',
                                         'dnc_never_expires','suppression_requires_keyring',
                                         'purge_batch_size','purge_job_interval_seconds',
                                         'purge_job_lock_ttl_seconds')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. A config key 113 seeds is already present; a second row for the '
                       . 'same key makes the effective value arbitrary.',
        ),
        'keyring_reachable' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM `{P}payplex_lf_config` WHERE ckey = 'no_non_google_map'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 102 has not run. This query is a proxy for the chain '
                       . 'being intact; it does not and cannot test the keyring, which lives on the '
                       . 'filesystem. Confirm the keyring separately with the module health check BEFORE '
                       . 'any tombstone is written — a tombstone written without a readable pepper is '
                       . 'refused at runtime, but a schema in place with no keyring behind it is a trap.',
        ),
    ),

    'reports' => array(
        'prospects_wasted_now'  => "SELECT COUNT(*) AS wasted FROM `{P}payplex_lf_prospects`
                                    WHERE `wasted_at` IS NOT NULL AND `undone_at` IS NULL",
        'prospects_dnc_now'     => "SELECT COUNT(*) AS dnc FROM `{P}payplex_lf_prospects`
                                    WHERE `suppression_kind` = 'dnc'",
        'prospects_total'       => "SELECT COUNT(*) AS total FROM `{P}payplex_lf_prospects`",
    ),

    'up' => array(
        /*
         * The tombstone.
         *
         * The key columns are ASCII and binary-collated on purpose. They hold
         * 64 hex characters; under the table's utf8mb4 default a 64-character
         * column costs 256 bytes in every index it appears in, and a
         * case-insensitive collation would make `A1B2` and `a1b2` the same key
         * — harmless for hex in practice, and exactly the kind of "harmless in
         * practice" that stops being true when a comparison moves into SQL.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_suppressions` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `suppression_kind` VARCHAR(8) NOT NULL,
          `source_type` VARCHAR(40) NOT NULL,
          `pepper_version` VARCHAR(8) NOT NULL,
          `rekey_state` VARCHAR(32) NOT NULL DEFAULT 'none',
          `source_ref_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
          `place_id_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
          `phone_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
          `email_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
          `business_name_hint` VARCHAR(60) NULL,
          `city` VARCHAR(120) NULL,
          `state` VARCHAR(120) NULL,
          `waste_reason` VARCHAR(40) NULL,
          `decided_at` INT UNSIGNED NOT NULL,
          `decided_by` INT NOT NULL DEFAULT 0,
          `purge_state` VARCHAR(16) NOT NULL DEFAULT 'pending',
          `purged_at` INT UNSIGNED NULL,
          `prospect_id` INT UNSIGNED NULL,
          `created_at` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `lf_sup_place` (`pepper_version`,`suppression_kind`,`place_id_key`),
          UNIQUE KEY `lf_sup_source` (`pepper_version`,`suppression_kind`,`source_type`,`source_ref_key`),
          KEY `lf_sup_phone` (`pepper_version`,`phone_key`),
          KEY `lf_sup_email` (`pepper_version`,`email_key`),
          KEY `lf_sup_kind` (`suppression_kind`,`decided_at`),
          KEY `lf_sup_purge` (`purge_state`,`decided_at`),
          KEY `lf_sup_rekey` (`rekey_state`,`pepper_version`),
          KEY `lf_sup_prospect` (`prospect_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /*
         * The DNC provenance register.
         *
         * `evidence_note` is a description of the request, not a copy of it. No
         * number, address or email belongs in this column; the whole point of
         * the table is that it survives the purge, and a free-text field that
         * outlives a purge is a retention hole with a friendly name. The
         * controller strips anything phone- or email-shaped before it is
         * written, and the suite asserts that it does.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_dnc_register` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `suppression_id` BIGINT UNSIGNED NOT NULL,
          `channel` VARCHAR(24) NOT NULL,
          `requested_on` DATE NOT NULL,
          `recorded_by` INT NOT NULL,
          `recorded_at` INT UNSIGNED NOT NULL,
          `evidence_note` VARCHAR(500) NULL,
          `pepper_version` VARCHAR(8) NOT NULL,
          `business_name_hint` VARCHAR(60) NULL,
          PRIMARY KEY (`id`),
          KEY `lf_dnc_sup` (`suppression_id`),
          KEY `lf_dnc_recorded` (`recorded_at`),
          KEY `lf_dnc_requested` (`requested_on`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /*
         * The purge queue.
         *
         * A prospect appears at most once — the unique key is the whole
         * concurrency story. Two requests racing to queue the same row end with
         * one insert and one duplicate-key error, which the caller treats as
         * success, instead of two jobs racing to purge the same PII and both
         * reporting they did it.
         *
         * `attempts` and `last_error` exist because a purge that fails must
         * fail loudly and stay queued. A queue that drops its failures is a
         * queue that reports an empty backlog while holding data it promised to
         * destroy.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_purge_queue` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `prospect_id` INT UNSIGNED NOT NULL,
          `suppression_id` BIGINT UNSIGNED NULL,
          `due_at` INT UNSIGNED NOT NULL,
          `state` VARCHAR(16) NOT NULL DEFAULT 'pending',
          `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
          `last_error` VARCHAR(255) NULL,
          `queued_at` INT UNSIGNED NOT NULL,
          `purged_at` INT UNSIGNED NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `lf_purge_prospect` (`prospect_id`),
          KEY `lf_purge_due` (`state`,`due_at`),
          KEY `lf_purge_sup` (`suppression_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /*
         * The re-key ledger.
         *
         * Version labels only. No pepper, no fingerprint of a pepper, no key
         * material of any kind is written to the database by this module, and
         * this table is the one place somebody would be tempted to put one
         * "just for reference".
         *
         * `impossible` counts the tombstones that could not be re-keyed because
         * their source identifiers were already purged. That number is not a
         * failure of the run — it is the permanent, expected residue of having
         * done the purge properly, and it is recorded so that nobody
         * investigates it twice.
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_lf_pepper_rekeys` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `from_version` VARCHAR(8) NOT NULL,
          `to_version` VARCHAR(8) NOT NULL,
          `started_at` INT UNSIGNED NOT NULL,
          `finished_at` INT UNSIGNED NULL,
          `examined` INT UNSIGNED NOT NULL DEFAULT 0,
          `rekeyed` INT UNSIGNED NOT NULL DEFAULT 0,
          `impossible` INT UNSIGNED NOT NULL DEFAULT 0,
          `status` VARCHAR(16) NOT NULL DEFAULT 'running',
          `error` VARCHAR(500) NULL,
          `actor_id` INT NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          KEY `lf_rekey_versions` (`from_version`,`to_version`),
          KEY `lf_rekey_started` (`started_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "INSERT IGNORE INTO `{P}payplex_lf_job_locks` (`lock_key`,`locked_at`,`locked_by`,`updated_at`)
         VALUES ('waste_purge', NULL, NULL, 0)",

        "INSERT IGNORE INTO `{P}payplex_lf_job_locks` (`lock_key`,`locked_at`,`locked_by`,`updated_at`)
         VALUES ('pepper_rekey', NULL, NULL, 0)",

        /*
         * BLANK ON PURPOSE. How long a rejected business stays suppressed is a
         * commercial and compliance decision, not something a migration gets to
         * invent. Blank fails closed: with no configured period the suppression
         * never expires and the tombstone is never deletable, so the failure
         * mode of forgetting to set it is "we kept our word for too long".
         */
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('waste_suppression_days','','NOT SET. How many days a waste tombstone continues to suppress a re-imported business. This is a business decision about how long one employee judgement should bind future searches, and it has not been stated. While blank the module treats suppression as non-expiring and refuses to delete waste tombstones, which is the safe direction. An administrator must set it before the first suppression review.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('waste_pii_retention_days','','NOT SET. How long the contact details of a wasted prospect are kept before the destructive purge clears them. A data-protection decision with a legal dimension; it is not guessed here. While blank no automatic PII purge runs at all. Note the asymmetry with the key above: leaving this blank is NOT the safe direction indefinitely, because it means holding contact details for rejected businesses with no stated basis. It must be set.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('dnc_never_expires','1','A Do Not Contact suppression has no expiry and is never deleted by the waste sweep. The business has not asked again. This key records the policy; the code does not read it as permission to do the opposite, because Leadfinder_tombstone::mayDeleteTombstone refuses a DNC row outright and refuses anything it does not positively recognise as expirable waste.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('suppression_requires_keyring','1','Tombstone creation and destructive purge both require a readable keyring with a known active version. If the keyring is missing, unreadable or names a version the file does not contain, both are refused, an administrator alert is raised and a redacted error is recorded. There is no fallback to plain SHA-256: a fallback would produce keys that look right, match nothing, and fail silently for months.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('purge_batch_size','200','Prospects purged per sweep. Bounded so one pass cannot hold a long write transaction across the prospect table; the next tick continues from the queue.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('purge_job_interval_seconds','3600','How often the waste purge sweep runs. Hourly is well inside any sensible retention period and keeps the queue short enough to read.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('purge_job_lock_ttl_seconds','900','How long a held purge lock stays valid. A process that dies mid-purge leaves its lock behind; without a TTL the job wedges permanently and, because it fails quietly by design, nobody notices until an audit.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey IN (
            'waste_suppression_days','waste_pii_retention_days','dnc_never_expires',
            'suppression_requires_keyring','purge_batch_size','purge_job_interval_seconds',
            'purge_job_lock_ttl_seconds')",

        "DELETE FROM `{P}payplex_lf_job_locks` WHERE lock_key IN ('waste_purge','pepper_rekey')",

        "DROP TABLE IF EXISTS `{P}payplex_lf_pepper_rekeys`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_purge_queue`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_dnc_register`",
        "DROP TABLE IF EXISTS `{P}payplex_lf_suppressions`",
    ),

    'down_warning' => 'THIS ROLLBACK DESTROYS THE DO NOT CONTACT LIST. Dropping '
                    . 'payplex_lf_suppressions and payplex_lf_dnc_register removes every record of which '
                    . 'businesses asked not to be contacted, and the identifiers those records were built '
                    . 'from were deliberately purged and cannot be recovered from anywhere. The next import '
                    . 'will re-offer those businesses and somebody will call them. Export '
                    . 'payplex_lf_suppressions, payplex_lf_dnc_register, payplex_lf_purge_queue and '
                    . 'payplex_lf_pepper_rekeys in full before rolling back, keep the export for as long as '
                    . 'the keyring version it references is retained, and treat rebuilding the DNC list from '
                    . 'call logs and written requests as part of the rollback rather than as a later task.',
);
