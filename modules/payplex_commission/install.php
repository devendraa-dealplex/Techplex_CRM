<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payplex Commission — install migration. Financial-integrity by design:
 *  - rule_versions: rules are versioned; changing a rule never edits history.
 *  - statements: once APPROVED, a statement row is immutable (corrections create
 *    a new statement that supersedes the old one).
 * Touches NO Perfex core tables.
 */
$CI = &get_instance();
$prefix = db_prefix();
$charset = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

if (!$CI->db->table_exists($prefix . 'payplex_commission_rule_versions')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_rule_versions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(191) NOT NULL,
        `scope` ENUM('global','role','region','product','staff') NOT NULL DEFAULT 'global',
        `scope_ref` VARCHAR(64) NULL,
        `rule_json` MEDIUMTEXT NOT NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_by` INT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_scope` (`scope`,`scope_ref`,`active`)
    ) ENGINE=InnoDB {$charset};");
}

if (!$CI->db->table_exists($prefix . 'payplex_commission_statements')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_statements` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `staff_id` INT NOT NULL,
        `period` VARCHAR(7) NOT NULL,
        `rule_version_id` BIGINT UNSIGNED NULL,
        `gross_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `clawback_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `net_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `currency` CHAR(3) NOT NULL DEFAULT 'INR',
        `computed_json` MEDIUMTEXT NULL,
        `status` ENUM('draft','pending_approval','approved','paid','disputed','superseded') NOT NULL DEFAULT 'draft',
        `supersedes_id` BIGINT UNSIGNED NULL,
        `created_by` INT NULL,
        `approved_by` INT NULL,
        `approved_at` DATETIME NULL,
        `paid_at` DATETIME NULL,
        `snapshot_hash` CHAR(64) NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_staff_period` (`staff_id`,`period`,`status`),
        KEY `ix_status` (`status`),
        KEY `ix_staff` (`staff_id`)
    ) ENGINE=InnoDB {$charset};");
}

if (!$CI->db->table_exists($prefix . 'payplex_commission_items')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_items` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `statement_id` BIGINT UNSIGNED NOT NULL,
        `source_type` VARCHAR(32) NOT NULL,
        `source_id` INT NULL,
        `base_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `commission_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `breakdown` TEXT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_statement` (`statement_id`)
    ) ENGINE=InnoDB {$charset};");
}

if (!$CI->db->table_exists($prefix . 'payplex_commission_disputes')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_disputes` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `statement_id` BIGINT UNSIGNED NOT NULL,
        `raised_by` INT NULL,
        `reason` TEXT NULL,
        `status` ENUM('open','resolved','rejected') NOT NULL DEFAULT 'open',
        `resolution` TEXT NULL,
        `created_at` DATETIME NOT NULL,
        `resolved_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `ix_statement` (`statement_id`,`status`)
    ) ENGINE=InnoDB {$charset};");
}

add_option('payplex_commission_currency', 'INR');

/* NO PLACEHOLDER RULE IS SEEDED.
 *
 * This installer previously inserted an ACTIVE global "Default (placeholder)"
 * rule of 5% flat. That single row defeated the whole no-assumed-rates safety
 * guard: computeDraft() returns 'configuration_required' only when no active
 * rule matches, and a global active placeholder always matched. Every staff
 * member therefore silently accrued commission at an unapproved, invented rate.
 *
 * Real rates are a business decision that has not been approved (§2.1), so the
 * correct behaviour with no configured rule is to refuse to calculate. The
 * migration below retires any placeholder a previous install already created.
 */

/* ===================== v0.2.0 — Commission Rule Builder (§2.1) ===================== */

/**
 * Full rule entity. The legacy payplex_commission_rule_versions table is left
 * untouched so existing statements keep resolving their historical rule, but new
 * rules live here where they can carry scope, lifecycle, approval and versioning.
 */
if (!$CI->db->table_exists($prefix . 'payplex_commission_rules')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_rules` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `rule_code` VARCHAR(64) NOT NULL,
        `name` VARCHAR(191) NOT NULL,
        `version` INT NOT NULL DEFAULT 1,
        `supersedes` BIGINT UNSIGNED NULL,

        -- scope (any NULL/empty column means 'applies to all')
        `company` VARCHAR(100) NULL,
        `product` VARCHAR(100) NULL,
        `employee_role` VARCHAR(60) NULL,
        `staff_id` INT NULL,
        `sales_channel` VARCHAR(60) NULL,
        `lead_source` VARCHAR(60) NULL,
        `customer_type` VARCHAR(60) NULL,
        `territory` VARCHAR(100) NULL,

        -- calculation
        `calc_type` VARCHAR(20) NOT NULL,
        `calc_base` VARCHAR(30) NOT NULL,
        `rate` DECIMAL(9,4) NULL,
        `amount` DECIMAL(14,2) NULL,
        `slabs_json` MEDIUMTEXT NULL,
        `min_threshold` DECIMAL(14,2) NULL,
        `max_eligible_amount` DECIMAL(14,2) NULL,
        `accelerator_threshold` DECIMAL(9,4) NULL,
        `accelerator_rate` DECIMAL(9,4) NULL,
        `cap` DECIMAL(14,2) NULL,

        -- lifecycle
        `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
        `priority` INT NOT NULL DEFAULT 100,
        `effective_from` DATE NULL,
        `effective_to` DATE NULL,
        `is_test` TINYINT(1) NOT NULL DEFAULT 0,
        `notes` TEXT NULL,

        `created_by` INT NULL,
        `submitted_by` INT NULL,
        `approved_by` INT NULL,
        `approved_at` DATETIME NULL,
        `activated_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,

        PRIMARY KEY (`id`),
        KEY `ix_status` (`status`),
        KEY `ix_code` (`rule_code`,`version`),
        KEY `ix_scope` (`staff_id`,`employee_role`,`product`),
        KEY `ix_effective` (`effective_from`,`effective_to`)
    ) ENGINE=InnoDB {$charset};");
}

/** Immutable audit of every rule action. Append-only; never updated or deleted. */
if (!$CI->db->table_exists($prefix . 'payplex_commission_rule_audit')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_rule_audit` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `rule_id` BIGINT UNSIGNED NULL,
        `rule_code` VARCHAR(64) NULL,
        `action` VARCHAR(40) NOT NULL,
        `from_status` VARCHAR(20) NULL,
        `to_status` VARCHAR(20) NULL,
        `actor_id` INT NULL,
        `reason` VARCHAR(500) NULL,
        `snapshot_json` MEDIUMTEXT NULL,
        `occurred_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_rule` (`rule_id`),
        KEY `ix_action` (`action`)
    ) ENGINE=InnoDB {$charset};");
}

/**
 * Retire any legacy placeholder rule.
 *
 * A previous version of this installer seeded an ACTIVE global 5% rule named
 * "Default (placeholder)". While it exists and is active, no transaction can
 * ever reach the 'configuration_required' state, so unapproved rates are paid
 * silently. Deactivating it is reversible (the row is kept, active flipped to 0)
 * and is recorded in the audit table.
 */
$legacyRules = $prefix . 'payplex_commission_rule_versions';
if ($CI->db->table_exists($legacyRules) && $CI->db->table_exists($prefix . 'payplex_commission_rule_audit')) {
    $placeholders = $CI->db->where('active', 1)
        ->group_start()->like('name', 'placeholder')->or_like('name', 'Default (placeholder)')->group_end()
        ->get($legacyRules)->result();

    foreach ($placeholders as $ph) {
        $CI->db->where('id', $ph->id)->update($legacyRules, array('active' => 0));
        $CI->db->insert($prefix . 'payplex_commission_rule_audit', array(
            'rule_id'       => null,
            'rule_code'     => 'legacy-' . $ph->id,
            'action'        => 'placeholder_retired',
            'from_status'   => 'active',
            'to_status'     => 'inactive',
            'actor_id'      => 0,
            'reason'        => 'Auto-seeded placeholder rule deactivated: unapproved rate, blocked the '
                             . 'configuration_required safety guard (spec 2.1).',
            'snapshot_json' => json_encode($ph),
            'occurred_at'   => date('Y-m-d H:i:s'),
        ));
    }
}

/* ===================== v0.2.0 — Source Policy & generation runs (§2.2) ===================== */

/**
 * Configurable source policy: which business events earn commission, and on what
 * amount. Versioned and approval-gated, exactly like the rule builder, because
 * choosing the source of commission is as consequential as choosing the rate.
 */
if (!$CI->db->table_exists($prefix . 'payplex_commission_source_policies')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_source_policies` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(191) NOT NULL,
        `version` INT NOT NULL DEFAULT 1,
        `supersedes` BIGINT UNSIGNED NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'draft',

        `events_json` MEDIUMTEXT NULL,
        `filters_json` MEDIUMTEXT NULL,

        `exclude_refunded` TINYINT(1) NOT NULL DEFAULT 1,
        `exclude_cancelled` TINYINT(1) NOT NULL DEFAULT 1,
        `allow_partial` TINYINT(1) NOT NULL DEFAULT 0,
        `include_tax` TINYINT(1) NOT NULL DEFAULT 0,
        `include_discount` TINYINT(1) NOT NULL DEFAULT 0,
        `gateway_fee_treatment` VARCHAR(20) NOT NULL DEFAULT 'ignore',
        `min_collected_amount` DECIMAL(14,2) NULL,
        `currencies` VARCHAR(191) NULL,
        `date_from` DATE NULL,
        `date_to` DATE NULL,
        `notes` TEXT NULL,

        `created_by` INT NULL,
        `submitted_by` INT NULL,
        `approved_by` INT NULL,
        `approved_at` DATETIME NULL,
        `activated_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,

        PRIMARY KEY (`id`),
        KEY `ix_status` (`status`)
    ) ENGINE=InnoDB {$charset};");
}

/**
 * Every preview and every real generation is recorded. A preview writes a run row
 * and nothing else — no statements, no items, no ledger entries — so the two can
 * be compared after the fact and a preview can never be mistaken for an earning.
 */
if (!$CI->db->table_exists($prefix . 'payplex_commission_generation_runs')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_generation_runs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `mode` VARCHAR(10) NOT NULL DEFAULT 'preview',
        `period` VARCHAR(7) NULL,
        `policy_id` BIGINT UNSIGNED NULL,
        `policy_version` INT NULL,
        `eligible_count` INT NOT NULL DEFAULT 0,
        `excluded_count` INT NOT NULL DEFAULT 0,
        `config_required_count` INT NOT NULL DEFAULT 0,
        `base_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `commission_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `statements_created` INT NOT NULL DEFAULT 0,
        `result_json` MEDIUMTEXT NULL,
        `actor_id` INT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_mode` (`mode`),
        KEY `ix_period` (`period`)
    ) ENGINE=InnoDB {$charset};");
}

/**
 * Idempotency ledger: one row per source record that has EARNED commission.
 * The unique key is what makes "no invoice counted twice" a database guarantee
 * rather than an application convention — a concurrent double-run collides here
 * instead of paying twice.
 */
if (!$CI->db->table_exists($prefix . 'payplex_commission_source_ledger')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_source_ledger` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `idempotency_key` CHAR(40) NOT NULL,
        `source_type` VARCHAR(40) NOT NULL,
        `source_id` INT NULL,
        `payment_id` INT NULL,
        `staff_id` INT NOT NULL,
        `statement_id` BIGINT UNSIGNED NULL,
        `run_id` BIGINT UNSIGNED NULL,
        `policy_id` BIGINT UNSIGNED NULL,
        `policy_version` INT NULL,
        `rule_id` BIGINT UNSIGNED NULL,
        `rule_version` INT NULL,
        `base_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `commission_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_idempotency` (`idempotency_key`),
        KEY `ix_staff` (`staff_id`),
        KEY `ix_statement` (`statement_id`)
    ) ENGINE=InnoDB {$charset};");
}

/* ===================== v0.2.0 — Maker-checker & clawbacks (§2.3, §2.5) ===================== */

/**
 * The statements table carries a legacy ENUM `status` that other code and older
 * screens still read. Rather than alter that ENUM on a financial table, the spec
 * lifecycle lives in a new `workflow_state` column beside it, and both are kept in
 * step. Existing rows are back-filled from their current status, so nothing is
 * lost and nothing that reads `status` breaks.
 */
$stmtTable = $prefix . 'payplex_commission_statements';
if ($CI->db->table_exists($stmtTable)) {
    if (!$CI->db->field_exists('workflow_state', $stmtTable)) {
        $CI->db->query("ALTER TABLE `{$stmtTable}`
            ADD COLUMN `workflow_state` VARCHAR(30) NOT NULL DEFAULT 'generated' AFTER `status`,
            ADD COLUMN `edited_since_approval` TINYINT(1) NOT NULL DEFAULT 0 AFTER `workflow_state`,
            ADD COLUMN `reviewed_by` INT NULL AFTER `edited_since_approval`,
            ADD COLUMN `reviewed_at` DATETIME NULL AFTER `reviewed_by`,
            ADD COLUMN `payable_at` DATETIME NULL AFTER `reviewed_at`,
            ADD COLUMN `exported_at` DATETIME NULL AFTER `payable_at`,
            ADD COLUMN `adjustment_total` DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER `exported_at`");

        // back-fill the new column from the historical status
        foreach (array(
            'draft'            => 'generated',
            'pending_approval' => 'under_review',
            'approved'         => 'approved',
            'paid'             => 'paid',
            'disputed'         => 'under_review',
            'superseded'       => 'reversed',
        ) as $legacy => $state) {
            $CI->db->where('status', $legacy)->update($stmtTable, array('workflow_state' => $state));
        }
    }
}

/** Immutable audit of every statement action. Append-only. */
if (!$CI->db->table_exists($prefix . 'payplex_commission_statement_audit')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_statement_audit` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `statement_id` BIGINT UNSIGNED NOT NULL,
        `action` VARCHAR(40) NOT NULL,
        `from_state` VARCHAR(30) NULL,
        `to_state` VARCHAR(30) NULL,
        `actor_id` INT NULL,
        `reason` VARCHAR(500) NULL,
        `snapshot_hash` CHAR(64) NULL,
        `data_json` MEDIUMTEXT NULL,
        `occurred_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_statement` (`statement_id`),
        KEY `ix_action` (`action`)
    ) ENGINE=InnoDB {$charset};");
}

/* -----------------------------------------------------------------------
 * Audit tamper-evidence.
 *
 * The statement audit log was already insert-only in code — no UPDATE and no
 * DELETE anywhere touches it. That constrains this module; it constrains
 * nobody holding a database connection. These two columns chain each row to
 * the one before it, so an edit or a removal made outside the application is
 * detectable rather than merely forbidden.
 *
 * Rows written BEFORE this migration are deliberately left unhashed. Hashing
 * them now would compute a digest of whatever they say today and present it
 * as proof of what they said when they were written — which is exactly the
 * assurance a chain is supposed to provide and exactly what a backfill cannot
 * give. The verifier counts them separately as unattested, and the chain
 * starts at the first row written under it.
 * -------------------------------------------------------------------- */
foreach (array('payplex_commission_statement_audit', 'payplex_commission_rule_audit') as $auditName) {
    $auditTable = $prefix . $auditName;
    if (!$CI->db->table_exists($auditTable)) { continue; }
    if (!$CI->db->field_exists('prev_hash', $auditTable)) {
        $CI->db->query("ALTER TABLE `{$auditTable}` ADD COLUMN `prev_hash` CHAR(64) NULL");
    }
    if (!$CI->db->field_exists('row_hash', $auditTable)) {
        $CI->db->query("ALTER TABLE `{$auditTable}` ADD COLUMN `row_hash` CHAR(64) NULL");
    }
}

/**
 * Adjustments: how a frozen statement gets corrected. An adjustment never
 * modifies the statement it points at.
 */
if (!$CI->db->table_exists($prefix . 'payplex_commission_adjustments')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_adjustments` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `statement_id` BIGINT UNSIGNED NOT NULL,
        `staff_id` INT NOT NULL,
        `type` VARCHAR(20) NOT NULL,
        `amount` DECIMAL(14,2) NOT NULL,
        `reason` VARCHAR(500) NOT NULL,
        `clawback_id` BIGINT UNSIGNED NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'approved',
        `created_by` INT NULL,
        `approved_by` INT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_statement` (`statement_id`),
        KEY `ix_staff` (`staff_id`)
    ) ENGINE=InnoDB {$charset};");
}

/**
 * Clawbacks. The UNIQUE idempotency key is what stops a retried refund webhook
 * from recovering the same money twice.
 */
if (!$CI->db->table_exists($prefix . 'payplex_commission_clawbacks')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_clawbacks` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `idempotency_key` CHAR(40) NOT NULL,
        `original_key` CHAR(40) NULL,
        `original_ledger_id` BIGINT UNSIGNED NULL,
        `statement_id` BIGINT UNSIGNED NULL,
        `staff_id` INT NOT NULL,
        `source_type` VARCHAR(40) NULL,
        `source_id` INT NULL,
        `trigger_event` VARCHAR(40) NOT NULL,
        `trigger_ref` VARCHAR(100) NOT NULL,
        `basis` VARCHAR(20) NOT NULL DEFAULT 'proportional',
        `original_commission` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `original_base` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `returned_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `recovery_method` VARCHAR(30) NOT NULL DEFAULT 'next_payout',
        `reason` VARCHAR(500) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending_review',
        `effective_period` VARCHAR(7) NULL,
        `created_by` INT NULL,
        `reviewed_by` INT NULL,
        `reviewed_at` DATETIME NULL,
        `review_reason` VARCHAR(500) NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_clawback_idempotency` (`idempotency_key`),
        KEY `ix_staff` (`staff_id`),
        KEY `ix_status` (`status`),
        KEY `ix_statement` (`statement_id`)
    ) ENGINE=InnoDB {$charset};");
}

/* ===================== v0.2.0 — Payout batches & export (§2.4) ===================== */

/**
 * Payout batches. Note what is NOT here: no stored full account number, and no
 * "payment" table that a background job could write to. Settlement is recorded
 * by a person entering a real bank reference, and nothing in this module can
 * move money.
 */
if (!$CI->db->table_exists($prefix . 'payplex_commission_payout_batches')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_payout_batches` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `reference` VARCHAR(40) NOT NULL,
        `period` VARCHAR(7) NOT NULL,
        `currency` CHAR(3) NOT NULL DEFAULT 'INR',
        `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
        `item_count` INT NOT NULL DEFAULT 0,
        `gross_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `tds_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `net_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `approval_reference` VARCHAR(40) NULL,
        `notes` TEXT NULL,
        `created_by` INT NULL,
        `submitted_by` INT NULL,
        `approved_by` INT NULL,
        `approved_at` DATETIME NULL,
        `exported_by` INT NULL,
        `exported_at` DATETIME NULL,
        `settled_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_reference` (`reference`),
        KEY `ix_status` (`status`),
        KEY `ix_period` (`period`)
    ) ENGINE=InnoDB {$charset};");
}

/**
 * Payout items. `masked_account` is exactly that — the column is sized for a
 * masked value and the application never writes a full number into it.
 */
if (!$CI->db->table_exists($prefix . 'payplex_commission_payout_items')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_payout_items` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `batch_id` BIGINT UNSIGNED NOT NULL,
        `statement_id` BIGINT UNSIGNED NULL,
        `staff_id` INT NOT NULL,
        `beneficiary_name` VARCHAR(191) NULL,
        `masked_account` VARCHAR(40) NULL,
        `account_ref` VARCHAR(64) NULL,
        `bank_name` VARCHAR(120) NULL,
        `ifsc` VARCHAR(20) NULL,
        `bank_verified` TINYINT(1) NOT NULL DEFAULT 0,
        `gross_payable` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `tds_rate` DECIMAL(6,3) NULL,
        `tds_exempt` TINYINT(1) NOT NULL DEFAULT 0,
        `tds_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `other_deductions` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `net_payable` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `bank_reference` VARCHAR(80) NULL,
        `paid_amount` DECIMAL(14,2) NULL,
        `paid_at` DATETIME NULL,
        `failure_reason` VARCHAR(40) NULL,
        `failure_notes` VARCHAR(500) NULL,
        `details_corrected` TINYINT(1) NOT NULL DEFAULT 0,
        `attempt` INT NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_batch` (`batch_id`),
        KEY `ix_staff` (`staff_id`),
        KEY `ix_status` (`status`),
        KEY `ix_bankref` (`bank_reference`)
    ) ENGINE=InnoDB {$charset};");
}

/** Every payout action, append-only. */
if (!$CI->db->table_exists($prefix . 'payplex_commission_payout_events')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_commission_payout_events` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `batch_id` BIGINT UNSIGNED NULL,
        `item_id` BIGINT UNSIGNED NULL,
        `action` VARCHAR(40) NOT NULL,
        `from_state` VARCHAR(20) NULL,
        `to_state` VARCHAR(20) NULL,
        `actor_id` INT NULL,
        `reason` VARCHAR(500) NULL,
        `data_json` MEDIUMTEXT NULL,
        `occurred_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_batch` (`batch_id`),
        KEY `ix_item` (`item_id`)
    ) ENGINE=InnoDB {$charset};");
}

/* -------------------------------------------------------------------------
 * v6 — reconcile the two status fields.
 *
 * A statement carries BOTH `workflow_state` (the eight-state lifecycle) and a
 * legacy `status` enum. statementTransition() writes the pair together, but the
 * retired approve()/markPaid() wrote `status` alone — so the two could drift,
 * and different screens read different fields. An employee's My Commission page
 * read `status`, so a statement approved only in `status` appeared APPROVED to
 * them while the workflow still held it as a draft under review.
 *
 * workflow_state is the source of truth, so `status` is rewritten from it
 * wherever the two disagree. The reverse is never done: `status` has fewer
 * states and cannot say whether an approved statement is payable, exported or
 * paid, so deriving workflow_state from it would lose information.
 *
 * Idempotent — a second run finds nothing to change. It only touches rows where
 * the mirror is actually wrong, and never invents a workflow_state.
 * ---------------------------------------------------------------------- */
if ($CI->db->table_exists($prefix . 'payplex_commission_statements')
    && $CI->db->field_exists('workflow_state', $prefix . 'payplex_commission_statements')
    && $CI->db->field_exists('status', $prefix . 'payplex_commission_statements')) {

    require_once __DIR__ . '/libraries/Payplex_commission_workflow.php';

    $rows = $CI->db->select('id, status, workflow_state')
        ->get($prefix . 'payplex_commission_statements')->result_array();

    $fixed = 0;
    foreach ($rows as $row) {
        $ws = trim((string) $row['workflow_state']);
        if ($ws === '') { continue; }   // nothing authoritative to mirror from
        $shouldBe = Payplex_commission_workflow::toLegacyStatus($ws);
        if ((string) $row['status'] !== $shouldBe) {
            $CI->db->where('id', (int) $row['id'])
                ->update($prefix . 'payplex_commission_statements', array(
                    'status'     => $shouldBe,
                    'updated_at' => date('Y-m-d H:i:s'),
                ));
            $fixed++;
        }
    }
    if ($fixed > 0 && function_exists('log_activity')) {
        @log_activity('Payplex Commission: reconciled the legacy status mirror on '
            . $fixed . ' statement(s) from workflow_state.');
    }
}

/* -----------------------------------------------------------------------
 * The Finance Officer role (checker).
 *
 * Maker-checker only works if the checker is somebody else. Right now nobody
 * on this install holds a single payplex_commission capability — the module is
 * reachable only by administrators — so "assign an approver" has nothing to
 * assign. This creates the role so that the remaining step is one assignment.
 *
 * Least privilege, and deliberately so:
 *   view_all  — an approver must see what they are approving
 *   review    — open the approval queue
 *   approve   — the decision itself
 *
 * NOT granted, on purpose:
 *   compute   — the approver must not be able to generate the statements they
 *               later approve. The runtime gate already refuses maker-approver;
 *               withholding the capability means the situation cannot arise in
 *               the first place, which is the stronger control.
 *   pay, payout, payout_approve, clawback, rules*, source_policy*
 *             — releasing money, changing the rules and approving a batch are
 *               separate duties. Which of them belongs to this role is a
 *               business decision, and inventing one here would quietly
 *               recombine the duties this module exists to separate.
 *
 * Additive and idempotent: an existing role of this name is left exactly as it
 * is, and no other role is ever modified. Assigning a person to the role stays
 * an administrator's action — a module that hands itself an approver would be
 * defeating its own control.
 * -------------------------------------------------------------------- */
if ($CI->db->table_exists($prefix . 'roles')) {
    $roleName = 'Finance Officer';

    /*
     * The decided grant set, named by the decision that created this role:
     *
     *   statement.view   -> view_all      see the statements being decided
     *   statement.approve-> approve       the approval itself
     *   statement.reject -> reject        the rejection, now its own capability
     *   audit.view       -> audit_view    read the decision trail
     *
     * Deliberately absent: compute (generation), pay, payout, payout_approve,
     * clawback, rules*, source_policy* — and review, which permits re-opening a
     * rejected statement and is therefore broader than "may reject".
     */
    $financeOfficerCaps = array('view_all', 'approve', 'reject', 'audit_view');

    $existing = $CI->db->where('name', $roleName)->get($prefix . 'roles')->row();

    if (!$existing) {
        $CI->db->insert($prefix . 'roles', array(
            'name'        => $roleName,
            'permissions' => serialize(array('payplex_commission' => $financeOfficerCaps)),
        ));
        $roleId = $CI->db->insert_id();
        if ($roleId && function_exists('log_activity')) {
            @log_activity('Payplex Commission: created the "' . $roleName . '" role (#' . $roleId
                . ') with ' . implode(', ', $financeOfficerCaps) . '. Generating statements, paying'
                . ' and re-opening a rejection are deliberately excluded. Assign a staff member to'
                . ' it to activate maker-checker.');
        }
    } else {
        /*
         * An earlier build created this role with view_all, review and approve —
         * a set that predates the decision above and grants re-opening while
         * missing the audit trail. Converge it once.
         *
         * Once only, and only the payplex_commission key: a migration that
         * rewrites a role on every run fights the administrator who deliberately
         * adjusted it, and one that rewrites the whole blob would silently
         * discard permissions belonging to other modules.
         */
        $marker = 'payplex_commission_finance_officer_grantset';
        $applied = function_exists('get_option') ? trim((string) get_option($marker)) : '';

        if ($applied === '') {
            $perm = @unserialize((string) $existing->permissions);
            if (!is_array($perm)) { $perm = array(); }
            $before = isset($perm['payplex_commission']) ? $perm['payplex_commission'] : array();

            if (array_values((array) $before) !== $financeOfficerCaps) {
                $perm['payplex_commission'] = $financeOfficerCaps;
                $CI->db->where('roleid', (int) $existing->roleid)
                    ->update($prefix . 'roles', array('permissions' => serialize($perm)));

                if (function_exists('log_activity')) {
                    @log_activity('Payplex Commission: set the "' . $roleName . '" role (#'
                        . (int) $existing->roleid . ') commission permissions to '
                        . implode(', ', $financeOfficerCaps) . ' (was: '
                        . (implode(', ', (array) $before) ?: 'none') . '). Permissions belonging to'
                        . ' other modules in this role were left untouched.');
                }
            }
            if (function_exists('add_option')) { add_option($marker, date('Y-m-d H:i:s'), 0); }
            elseif (function_exists('update_option')) { update_option($marker, date('Y-m-d H:i:s')); }
        }
    }
}
