<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or defined('PAYPLEX_CV_PREFLIGHT')
    or defined('PAYPLEX_CV_MIGRATION_TEST') or exit('No direct script access allowed');

/**
 * 209 — Outbound delivery attempts, provider health, and the failure queue.
 *
 * WHY DELIVERY IS AUDITED SEPARATELY FROM THE AUDIT TRAIL
 * ------------------------------------------------------
 * "We sent the customer a verification link" is a claim that gets tested in a
 * dispute, and the honest answer is usually more complicated than yes or no: it
 * was sent to an address, the provider accepted it, and whether it arrived is
 * something neither we nor they actually know.
 *
 * So every attempt is recorded with its channel, its masked recipient, the
 * gateway's own reference and what the gateway said — which supports the exact
 * claim the evidence justifies ("sent, accepted by the gateway at 14:02, no
 * bounce recorded") rather than the stronger one nobody can support.
 *
 * RECIPIENTS ARE MASKED IN THIS TABLE
 * -----------------------------------
 * This log is read by more people than the contract is, and it gets exported.
 * `a**@example.com` answers "did it go to the right person" without putting a
 * readable list of customer addresses and mobile numbers into every export of
 * the delivery history. The unmasked address already lives on the signer row,
 * once, where access to it is controlled.
 *
 * WHY PROVIDER HEALTH IS A TABLE AND NOT A CACHE
 * ----------------------------------------------
 * The circuit breaker's counters have to be shared across web requests and cron
 * runs, and they have to survive a restart. In a cache they would be lost at the
 * worst possible moment — a provider outage is exactly when infrastructure gets
 * restarted — and every queued request would then re-discover the outage by
 * timing out, one worker at a time.
 *
 * THE FAILURE QUEUE EXISTS SO RETRIES ARE DELIBERATE
 * --------------------------------------------------
 * A failure that is only a log line gets retried by whoever notices it, by hand,
 * usually by pressing the button again — which is how a duplicate signing
 * request gets created. A failure that is a ROW carries the original operation
 * reference, so the retry is recognisably the same attempt rather than a new
 * one, and `next_attempt_at` means the retry happens on a schedule instead of on
 * an impulse.
 */

return array(

    'id'          => '209',
    'description' => 'Delivery attempts, provider health and the controlled failure queue',

    'preflight' => array(

        'requests_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_requests'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 201 has not run, so there are no requests for a '
                       . 'failure to belong to. This check is also the control: it must return 1, '
                       . 'so a predicate that has stopped matching anything fails the gate instead '
                       . 'of passing it.',
        ),

        'delivery_tables_absent' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}'
                            AND TABLE_NAME IN ('{P}payplex_cv_deliveries','{P}payplex_cv_provider_health','{P}payplex_cv_failures')",
            'must_be' => 0,
            'if_not'  => 'DO NOT APPLY. One or more of these tables already exists, so this '
                       . 'migration has run.',
        ),

        'webhooks_table_present' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_cv_webhooks'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. Migration 202 has not run. Reconciliation reads the webhook '
                       . 'ledger to work out what it missed; without it, a missed webhook is simply '
                       . 'lost rather than recovered.',
        ),
    ),

    'reports' => array(
        'requests_present' => "SELECT COUNT(*) AS requests FROM `{P}payplex_cv_requests`",
    ),

    'up' => array(

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_deliveries` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `purpose` VARCHAR(40) NOT NULL,
          `channel` VARCHAR(20) NOT NULL,
          `contract_id` INT UNSIGNED NOT NULL,
          `signer_id` BIGINT UNSIGNED NULL,
          `kyc_session_id` BIGINT UNSIGNED NULL,
          `invite_token_id` BIGINT UNSIGNED NULL,
          /* Masked. The readable address lives once, on the signer row. */
          `recipient_masked` VARCHAR(190) NOT NULL,
          `template_key` VARCHAR(60) NOT NULL,
          `attempt` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
          `status` VARCHAR(30) NOT NULL DEFAULT 'queued',
          `gateway` VARCHAR(60) NULL,
          `gateway_reference` VARCHAR(190) NULL,
          `gateway_message` VARCHAR(500) NULL,
          `queued_at` INT UNSIGNED NOT NULL,
          `sent_at` INT UNSIGNED NULL,
          `failed_at` INT UNSIGNED NULL,
          `created_by` INT NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          KEY `cv_delivery_contract` (`contract_id`,`purpose`),
          KEY `cv_delivery_signer` (`signer_id`,`queued_at`),
          KEY `cv_delivery_status` (`status`,`queued_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_provider_health` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `provider` VARCHAR(60) NOT NULL,
          `environment` VARCHAR(20) NOT NULL DEFAULT 'sandbox',
          `consecutive_failures` INT UNSIGNED NOT NULL DEFAULT 0,
          /* 0 means closed. The breaker's state is derived from these, never
             stored separately — a stored state drifts out of step with the
             counters that justify it. */
          `opened_at` INT UNSIGNED NOT NULL DEFAULT 0,
          `half_open_successes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          `last_success_at` INT UNSIGNED NOT NULL DEFAULT 0,
          `last_failure_at` INT UNSIGNED NOT NULL DEFAULT 0,
          `updated_at` INT UNSIGNED NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `cv_health_provider` (`provider`,`environment`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `{P}payplex_cv_failures` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `contract_id` INT UNSIGNED NOT NULL,
          `request_id` BIGINT UNSIGNED NULL,
          `kyc_session_id` BIGINT UNSIGNED NULL,
          `operation` VARCHAR(60) NOT NULL,
          /*
           * The ORIGINAL operation reference, carried so a retry is recognisably
           * the same attempt. Without it a retry is a new request, and a
           * provider with no idempotency key creates a second document.
           */
          `operation_reference` VARCHAR(190) NULL,
          `failure_code` VARCHAR(60) NOT NULL,
          `attempted` TINYINT(1) NOT NULL DEFAULT 1,
          `may_have_succeeded` TINYINT(1) NOT NULL DEFAULT 0,
          `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
          `next_attempt_at` INT UNSIGNED NULL,
          `resolved_at` INT UNSIGNED NULL,
          `resolved_by` INT NOT NULL DEFAULT 0,
          `resolution` VARCHAR(500) NULL,
          `detail` VARCHAR(1000) NULL,
          `created_at` INT UNSIGNED NOT NULL,
          `updated_at` INT UNSIGNED NULL,
          PRIMARY KEY (`id`),
          KEY `cv_failure_contract` (`contract_id`,`resolved_at`),
          KEY `cv_failure_due` (`resolved_at`,`next_attempt_at`),
          KEY `cv_failure_reference` (`operation_reference`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        /*
         * De-duplication for inbound webhooks.
         *
         * The existing `event_key` column carried whatever the provider called
         * an event id. Leegality sends no event id, and its `mac` is constant
         * per document — so keying on either would collapse every event about a
         * document into one, and the second signer's signature would be
         * discarded as a duplicate of the first's.
         *
         * `dedupe_key` is computed by us over document + invitee + action +
         * status. The UNIQUE index is what actually enforces exactly-once:
         * a replayed delivery fails to insert rather than being processed twice.
         */
        "ALTER TABLE `{P}payplex_cv_webhooks`
           ADD COLUMN `dedupe_key` CHAR(64) NULL AFTER `event_key`",

        "ALTER TABLE `{P}payplex_cv_webhooks`
           ADD COLUMN `reconciled_at` INT UNSIGNED NULL AFTER `processed_at`",

        "ALTER TABLE `{P}payplex_cv_webhooks`
           ADD UNIQUE KEY `cv_webhook_dedupe` (`dedupe_key`)",
    ),

    'down' => array(
        "ALTER TABLE `{P}payplex_cv_webhooks` DROP INDEX `cv_webhook_dedupe`",
        "ALTER TABLE `{P}payplex_cv_webhooks` DROP COLUMN `reconciled_at`",
        "ALTER TABLE `{P}payplex_cv_webhooks` DROP COLUMN `dedupe_key`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_failures`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_provider_health`",
        "DROP TABLE IF EXISTS `{P}payplex_cv_deliveries`",
    ),
);
