<?php
defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT') or exit('No direct script access allowed');
/**
 * Contract verification migration 203 — the settings store and its seeds.
 *
 * WHY SETTINGS GET THEIR OWN TABLE RATHER THAN `tbloptions`
 * ---------------------------------------------------------
 * Three reasons, in order of how much they matter:
 *
 *   1. `tbloptions` is a core table. Writing encrypted provider credentials
 *      into it means module data living in core storage, which is exactly the
 *      arrangement that made the `google_api_key` exposure possible earlier in
 *      this programme — a value in `tbloptions` is reachable by anything that
 *      reads an option, including code that has no idea it is handling a secret.
 *
 *   2. A module-owned table can carry the columns a credential needs and a
 *      generic option row cannot: whether it is secret, its fingerprint, who
 *      set it and when.
 *
 *   3. Uninstalling this module should take its secrets with it. Rows in a core
 *      table outlive the module that wrote them.
 *
 * SEEDED EMPTY, AND THAT IS THE DELIVERABLE
 * -----------------------------------------
 * Every credential row is created with an empty value. No key, no secret, no
 * URL, no workflow id is placed here, in a fixture, or in any file in this
 * module. An administrator enters them through the protected settings screen
 * after sandbox testing — which is the instruction, and is also the only
 * arrangement in which the credentials were never in a git history.
 *
 * The environment is seeded to `sandbox` and `enabled` to 0, so installing the
 * module changes nothing about how the CRM behaves until somebody decides it
 * should.
 *
 * SCOPE
 * -----
 * Additive. One new module-owned table and its seed rows. No core table is
 * read, written, altered or named. No credential value is seeded.
 */
return array(
    'id'          => 203,
    'module'      => 'payplex_contract_verification',
    'title'       => 'Provider settings store, seeded empty and disabled',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 202,

    'preflight' => array(
        'table_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_settings'",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. The settings table already exists; IF NOT EXISTS would skip '
                       . 'it and leave a table of unknown shape in place.',
        ),
        'audit_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_audit'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 202 has not run. Also the control check.',
        ),
        'no_plaintext_credential_column' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_settings'
                            AND COLUMN_NAME IN ('api_key','api_secret','webhook_secret','private_key')",
            'must_be' => 0,
            'if_not'  => 'STOP AND REPORT. A settings table exists with a column named after a '
                       . 'credential. Credentials are rows with an encrypted value, never columns — '
                       . 'a column named api_secret is a schema that invites plaintext.',
        ),
    ),

    'reports' => array(
        'module_tables' => "SELECT COUNT(*) AS cv_tables FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME LIKE '{P}payplex\\_cv\\_%'",
    ),

    'up' => array(
        /*
         * `svalue` holds ciphertext for a secret row and plaintext for an
         * ordinary one; `is_secret` says which, and the model refuses to return
         * `svalue` for a secret row through any path that reaches a screen.
         *
         * `fingerprint` is what the settings screen displays instead. `last_four`
         * is populated only for identifier-type credentials — never for key
         * material. See Contract_signing_settings::describe().
         */
        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_settings` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `skey` VARCHAR(60) NOT NULL,
          `svalue` TEXT NULL,
          `is_secret` TINYINT(1) NOT NULL DEFAULT 0,
          `fingerprint` VARCHAR(24) NULL,
          `last_four` VARCHAR(8) NULL,
          `set_by` INT NOT NULL DEFAULT 0,
          `set_at` INT UNSIGNED NULL,
          `note` VARCHAR(500) NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `cv_settings_key` (`skey`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /* Non-secret operating settings, with safe defaults. */
        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('enabled','0',0,'Off by default. Enabling this does not make the integration live; the environment setting and its gate do that.',UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('environment','sandbox',0,'Sandbox until every acceptance test has passed and an administrator has confirmed. Production is refused by Contract_signing_settings::productionGate() until then.',UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('request_timeout_seconds','30',0,'Bounded 5-120. A signing request that hangs holds a member of staff in front of a spinner.',UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('max_retries','2',0,'Bounded 0-5. Every retry reuses the same operation reference, so a retry can never become a second signing request.',UNIX_TIMESTAMP())",

        /*
         * NOT SET, and not defaulted. Each of these comes either from the
         * account documentation or from an administrator. A plausible default
         * here would be a guess wearing the clothes of a decision.
         */
        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('api_base_url','',0,'NOT SET. Comes from the API documentation for this account. HTTPS only; credentials never appear in this URL.',UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('auth_type','',0,'NOT SET. The permitted values come from the account documentation, which has not been obtained. No value validates until that list exists.',UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('workflow_id','',0,'NOT SET. Validated against the provider before first use, not on save: a wrong workflow id is only detectable by asking.',UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('callback_url','',0,'NOT SET. HTTPS, on this host. Shown on the settings screen so it can be pasted into the provider dashboard.',UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('link_expiry_hours','',0,'NOT SET. How long a signing link stays usable. Blank is refused rather than defaulted: how long a customer has to sign is a business decision.',UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('default_signing_method','',0,'NOT SET. Only methods confirmed ENABLED on this account, which is not the same as everything the API offers.',UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('default_invitation_method','',0,'NOT SET. As above.',UNIX_TIMESTAMP())",

        /*
         * The four credential rows. Created so the settings screen has
         * somewhere to write, and EMPTY so that nothing in this repository,
         * this migration or any commit has ever held a credential.
         */
        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('api_key','',1,'NOT SET. Entered by an authorised administrator through the settings screen. Never committed, never logged, never returned by any endpoint. Last four characters may be displayed.',UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('api_secret','',1,'NOT SET. Key material: a fingerprint is displayed and nothing else. Showing the last four characters of an HMAC secret leaks key material permanently for no operational gain.',UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('webhook_secret','',1,'NOT SET. Verifies inbound deliveries. Without it the webhook route authenticates nothing and therefore accepts nothing.',UNIX_TIMESTAMP())",

        "INSERT INTO `{P}payplex_cv_settings` (skey,svalue,is_secret,note,set_at)
         VALUES ('private_key','',1,'NOT SET and optional. Only if the documentation for this account requires one.',UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DROP TABLE IF EXISTS `{P}payplex_cv_settings`",
    ),

    'down_warning' => 'Rolling back drops the settings table, taking the encrypted provider '
                    . 'credentials with it. That is the intended behaviour — a module that has been '
                    . 'removed should not leave its secrets behind — but it means the credentials have '
                    . 'to be entered again from the provider dashboard afterwards. They are not '
                    . 'recoverable from a database export in any useful form and should not be: '
                    . 're-enter them, and treat the rollback as the moment to confirm the key '
                    . 'restrictions are still correct.',
);
