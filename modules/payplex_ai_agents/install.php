<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Schema installer for the Payplex AI Agents module.
 * Idempotent: every statement is CREATE TABLE IF NOT EXISTS, so re-activation
 * or upgrade never destroys data. Rollback lives in uninstall.php.
 */

$CI = &get_instance();
$charset = 'DEFAULT CHARSET=' . $CI->db->char_set;
$prefix  = db_prefix();

// ---- Agents ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agents')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agents` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(191) NOT NULL,
        `description` TEXT NULL,
        `department` VARCHAR(100) NULL,
        `purpose` TEXT NULL,
        `template_slug` VARCHAR(100) NULL,
        `ai_provider` VARCHAR(100) NULL,
        `ai_model` VARCHAR(100) NULL,
        `system_prompt` MEDIUMTEXT NULL,
        `knowledge_sources` MEDIUMTEXT NULL,
        `products_services` MEDIUMTEXT NULL,
        `lead_sources` MEDIUMTEXT NULL,
        `crm_permissions` MEDIUMTEXT NULL,
        `allowed_tools` MEDIUMTEXT NULL,
        `triggers` MEDIUMTEXT NULL,
        `conditions` MEDIUMTEXT NULL,
        `actions` MEDIUMTEXT NULL,
        `prohibited_actions` MEDIUMTEXT NULL,
        `approval_required_actions` MEDIUMTEXT NULL,
        `working_days` VARCHAR(100) NULL,
        `working_hours` VARCHAR(100) NULL,
        `customer_timezone` VARCHAR(100) NULL,
        `confidence_threshold` DECIMAL(4,2) NOT NULL DEFAULT 0.75,
        `human_escalation` TINYINT(1) NOT NULL DEFAULT 1,
        `retry_limit` INT(11) NOT NULL DEFAULT 2,
        `daily_execution_limit` INT(11) NOT NULL DEFAULT 100,
        `token_limit` INT(11) NOT NULL DEFAULT 100000,
        `daily_budget` DECIMAL(12,2) NOT NULL DEFAULT 5.00,
        `monthly_budget` DECIMAL(12,2) NOT NULL DEFAULT 100.00,
        `mode` VARCHAR(20) NOT NULL DEFAULT "sandbox",
        `status` VARCHAR(20) NOT NULL DEFAULT "draft",
        `version` INT(11) NOT NULL DEFAULT 1,
        `is_template` TINYINT(1) NOT NULL DEFAULT 0,
        `agent_kill` TINYINT(1) NOT NULL DEFAULT 0,
        `owner_id` INT(11) NULL,
        `reviewer_id` INT(11) NULL,
        `approver_id` INT(11) NULL,
        `created_by` INT(11) NULL,
        `submitted_by` INT(11) NULL,
        `approved_by` INT(11) NULL,
        `datecreated` DATETIME NULL,
        `lastupdated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `status` (`status`),
        KEY `is_template` (`is_template`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Version history (prompt/config versions + rollback source) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_versions')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_versions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `agent_id` INT(11) NOT NULL,
        `version` INT(11) NOT NULL DEFAULT 1,
        `config_json` LONGTEXT NULL,
        `note` VARCHAR(255) NULL,
        `created_by` INT(11) NULL,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `agent_id` (`agent_id`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Audit trail (secrets masked before insert) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_audit')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_audit` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `agent_id` INT(11) NULL,
        `event_type` VARCHAR(50) NOT NULL,
        `actor_id` INT(11) NULL,
        `message` VARCHAR(500) NULL,
        `data_json` LONGTEXT NULL,
        `ip` VARCHAR(60) NULL,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `agent_id` (`agent_id`),
        KEY `event_type` (`event_type`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Runs (sandbox + production execution records, cost/tokens) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_runs')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_runs` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `agent_id` INT(11) NOT NULL,
        `mode` VARCHAR(20) NOT NULL DEFAULT "sandbox",
        `status` VARCHAR(20) NOT NULL,
        `model` VARCHAR(100) NULL,
        `tokens` INT(11) NOT NULL DEFAULT 0,
        `cost` DECIMAL(12,6) NOT NULL DEFAULT 0,
        `confidence` DECIMAL(4,2) NULL,
        `simulated_actions` INT(11) NOT NULL DEFAULT 0,
        `blocked_actions` INT(11) NOT NULL DEFAULT 0,
        `escalations` INT(11) NOT NULL DEFAULT 0,
        `transcript_json` LONGTEXT NULL,
        `triggered_by` INT(11) NULL,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `agent_id` (`agent_id`),
        KEY `mode` (`mode`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Key/value settings (global kill switch, global budgets) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_settings')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_settings` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `skey` VARCHAR(100) NOT NULL,
        `svalue` TEXT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `skey` (`skey`)
    ) ENGINE=InnoDB ' . $charset . ';');

    $CI->db->insert(db_prefix() . 'payplex_ai_agent_settings', array('skey' => 'global_kill_switch', 'svalue' => '0'));
    $CI->db->insert(db_prefix() . 'payplex_ai_agent_settings', array('skey' => 'global_daily_budget', 'svalue' => '50'));
    $CI->db->insert(db_prefix() . 'payplex_ai_agent_settings', array('skey' => 'global_monthly_budget', 'svalue' => '1000'));
}

// ============================================================
// M2 tables (Knowledge Base, KB versions, Escalation queue).
// Added as CREATE TABLE IF NOT EXISTS so an already-active v1 install
// gains them via the schema migration in the bootstrap.
// ============================================================

// ---- Knowledge base ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_kb')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_kb` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(191) NOT NULL,
        `category` VARCHAR(50) NULL,
        `content` MEDIUMTEXT NULL,
        `keywords` TEXT NULL,
        `scope` MEDIUMTEXT NULL,
        `permission` VARCHAR(50) NULL,
        `indexing_status` VARCHAR(20) NOT NULL DEFAULT "indexed",
        `version` INT(11) NOT NULL DEFAULT 1,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_by` INT(11) NULL,
        `datecreated` DATETIME NULL,
        `lastupdated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `category` (`category`),
        KEY `indexing_status` (`indexing_status`),
        KEY `is_active` (`is_active`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- KB version history ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_kb_versions')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_kb_versions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `kb_id` INT(11) NOT NULL,
        `version` INT(11) NOT NULL DEFAULT 1,
        `content_json` LONGTEXT NULL,
        `note` VARCHAR(255) NULL,
        `created_by` INT(11) NULL,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `kb_id` (`kb_id`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Human-review / escalation queue ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_escalations')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_escalations` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `agent_id` INT(11) NOT NULL,
        `run_id` INT(11) NULL,
        `reason` VARCHAR(100) NOT NULL,
        `detail` TEXT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT "open",
        `resolved_by` INT(11) NULL,
        `resolution_note` VARCHAR(500) NULL,
        `datecreated` DATETIME NULL,
        `resolved_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `agent_id` (`agent_id`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ============================================================
// M3 tables (AI Calling log, lead-pipeline events).
// ============================================================

// ---- AI Calling attempts (simulated / blocked / real) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_calls')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_calls` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `agent_id` INT(11) NULL,
        `lead_id` INT(11) NULL,
        `mode` VARCHAR(20) NOT NULL DEFAULT "sandbox",
        `decision` VARCHAR(30) NOT NULL,
        `reason` VARCHAR(60) NULL,
        `outcome` VARCHAR(30) NULL,
        `duration` INT(11) NOT NULL DEFAULT 0,
        `cost` DECIMAL(12,4) NOT NULL DEFAULT 0,
        `transcript_json` LONGTEXT NULL,
        `triggered_by` INT(11) NULL,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `agent_id` (`agent_id`),
        KEY `decision` (`decision`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Lead-pipeline events (recommendations logged, never mutations) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_pipeline_events')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_pipeline_events` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `agent_id` INT(11) NULL,
        `lead_id` INT(11) NULL,
        `event_type` VARCHAR(50) NOT NULL,
        `score` INT(11) NULL,
        `valid` TINYINT(1) NULL,
        `duplicates` VARCHAR(255) NULL,
        `assign_staff` INT(11) NULL,
        `escalated` TINYINT(1) NOT NULL DEFAULT 0,
        `result_json` LONGTEXT NULL,
        `mode` VARCHAR(20) NOT NULL DEFAULT "sandbox",
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `lead_id` (`lead_id`),
        KEY `event_type` (`event_type`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// M3 default settings (both OFF by default — safety).
if ($CI->db->table_exists(db_prefix() . 'payplex_ai_agent_settings')) {
    foreach (array('allow_real_calls' => '0', 'pipeline_auto_run' => '0') as $k => $v) {
        $CI->db->where('skey', $k);
        if ((int) $CI->db->count_all_results(db_prefix() . 'payplex_ai_agent_settings') === 0) {
            $CI->db->insert(db_prefix() . 'payplex_ai_agent_settings', array('skey' => $k, 'svalue' => $v));
        }
    }
}

/* ===================== M4: Executive Leadership System ===================== */

// ---- Executive identity columns on the agents table (idempotent) ----
$agentsTbl = db_prefix() . 'payplex_ai_agents';
if ($CI->db->table_exists($agentsTbl)) {
    $execCols = array(
        'system_role'         => "VARCHAR(150) NULL AFTER `name`",
        'display_name'        => "VARCHAR(191) NULL AFTER `system_role`",
        'short_name'          => "VARCHAR(100) NULL AFTER `display_name`",
        'agent_ref'           => "VARCHAR(50) NULL AFTER `short_name`",
        'company'             => "VARCHAR(100) NULL AFTER `department`",
        'persona'             => "VARCHAR(150) NULL",
        'communication_style' => "VARCHAR(100) NULL",
        'primary_language'    => "VARCHAR(50) NULL",
        'extra_languages'     => "VARCHAR(191) NULL",
        'tone'                => "VARCHAR(100) NULL",
        'expertise_tags'      => "VARCHAR(255) NULL",
        'avatar'              => "VARCHAR(255) NULL",
        'fallback_model'      => "VARCHAR(100) NULL",
        'approval_tier'       => "VARCHAR(20) NOT NULL DEFAULT 'chairman'",
        'is_executive'        => "TINYINT(1) NOT NULL DEFAULT 0",
    );
    foreach ($execCols as $col => $ddl) {
        if (!$CI->db->field_exists($col, $agentsTbl)) {
            $CI->db->query('ALTER TABLE `' . $agentsTbl . '` ADD COLUMN `' . $col . '` ' . $ddl);
        }
    }
}

// ---- Admin-created custom templates (Create Template form persists here) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_custom_templates')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_custom_templates` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `template_slug` VARCHAR(100) NOT NULL,
        `system_role` VARCHAR(150) NOT NULL,
        `name` VARCHAR(150) NOT NULL,
        `short_name` VARCHAR(100) NULL,
        `agent_ref_prefix` VARCHAR(50) NULL,
        `department` VARCHAR(100) NULL,
        `company` VARCHAR(100) NULL,
        `persona` VARCHAR(150) NULL,
        `communication_style` VARCHAR(100) NULL,
        `primary_language` VARCHAR(50) NULL,
        `extra_languages` VARCHAR(191) NULL,
        `tone` VARCHAR(100) NULL,
        `expertise_tags` VARCHAR(255) NULL,
        `purpose` TEXT NULL,
        `description` TEXT NULL,
        `system_prompt` MEDIUMTEXT NULL,
        `ai_provider` VARCHAR(100) NULL,
        `ai_model` VARCHAR(100) NULL,
        `fallback_model` VARCHAR(100) NULL,
        `allowed_tools` MEDIUMTEXT NULL,
        `triggers` MEDIUMTEXT NULL,
        `prohibited_actions` MEDIUMTEXT NULL,
        `approval_required_actions` MEDIUMTEXT NULL,
        `approval_tier` VARCHAR(20) NOT NULL DEFAULT "chairman",
        `confidence_threshold` DECIMAL(4,2) NOT NULL DEFAULT 0.80,
        `human_escalation` TINYINT(1) NOT NULL DEFAULT 1,
        `token_limit` INT(11) NOT NULL DEFAULT 100000,
        `daily_budget` DECIMAL(12,2) NOT NULL DEFAULT 5.00,
        `monthly_budget` DECIMAL(12,2) NOT NULL DEFAULT 100.00,
        `is_executive` TINYINT(1) NOT NULL DEFAULT 0,
        `created_by` INT(11) NULL,
        `datecreated` DATETIME NULL,
        `lastupdated` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `template_slug` (`template_slug`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

/* ===================== M5: Chairman Command Centre ===================== */

// ---- Decision Packets submitted to the Chairman ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_decisions')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_decisions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(191) NOT NULL,
        `requesting_agent_id` INT(11) NULL,
        `company` VARCHAR(100) NULL,
        `objective` TEXT NULL,
        `recommended_action` VARCHAR(150) NULL,
        `action_key` VARCHAR(100) NULL,
        `reason` TEXT NULL,
        `evidence` MEDIUMTEXT NULL,
        `source_links` MEDIUMTEXT NULL,
        `assumptions` MEDIUMTEXT NULL,
        `confidence` DECIMAL(4,2) NULL,
        `alternatives` MEDIUMTEXT NULL,
        `financial_impact` VARCHAR(255) NULL,
        `expected_revenue` DECIMAL(14,2) NULL,
        `expected_cost` DECIMAL(14,2) NULL,
        `roi` VARCHAR(100) NULL,
        `amount` DECIMAL(14,2) NULL,
        `legal_impact` TEXT NULL,
        `security_impact` TEXT NULL,
        `people_impact` TEXT NULL,
        `risk_rating` VARCHAR(20) NULL,
        `reversibility` VARCHAR(50) NULL,
        `rollback_method` TEXT NULL,
        `execution_owner` VARCHAR(150) NULL,
        `deadline` DATE NULL,
        `other_opinions` MEDIUMTEXT NULL,
        `audit_verification` TEXT NULL,
        `required_tier` VARCHAR(20) NOT NULL DEFAULT "chairman",
        `tier_reason` VARCHAR(100) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT "draft",
        `decided_by` INT(11) NULL,
        `decision_note` TEXT NULL,
        `decided_at` DATETIME NULL,
        `created_by` INT(11) NULL,
        `datecreated` DATETIME NULL,
        `lastupdated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `status` (`status`),
        KEY `required_tier` (`required_tier`),
        KEY `company` (`company`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Configurable approval matrix (action -> required tier) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_approval_matrix')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_approval_matrix` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `action` VARCHAR(100) NOT NULL,
        `tier` VARCHAR(20) NOT NULL DEFAULT "chairman",
        `manager_limit` DECIMAL(14,2) NOT NULL DEFAULT 0,
        `note` VARCHAR(255) NULL,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `action` (`action`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// Seed the approval matrix with the built-in defaults (idempotent).
// NOTE: no table_exists() guard here — right after CREATE TABLE, CodeIgniter's
// cached table list is stale and table_exists() can wrongly return false,
// which would skip the seed. The INSERTs below don't rely on that cache.
require_once __DIR__ . '/libraries/Payplex_agent_approval_matrix.php';
$mtx = db_prefix() . 'payplex_ai_agent_approval_matrix';
foreach (Payplex_agent_approval_matrix::defaultRows() as $row) {
    $exists = $CI->db->where('action', $row['action'])->get($mtx)->row();
    if (!$exists) {
        $CI->db->insert($mtx, array(
            'action'      => $row['action'],
            'tier'        => $row['tier'],
            'manager_limit' => 0,
            'note'        => 'default',
            'datecreated' => date('Y-m-d H:i:s'),
        ));
    }
}

// M5 default settings.
if ($CI->db->table_exists(db_prefix() . 'payplex_ai_agent_settings')) {
    foreach (array('delegate_limit' => '0') as $k => $v) {
        $CI->db->where('skey', $k);
        if ((int) $CI->db->count_all_results(db_prefix() . 'payplex_ai_agent_settings') === 0) {
            $CI->db->insert(db_prefix() . 'payplex_ai_agent_settings', array('skey' => $k, 'svalue' => $v));
        }
    }
}

/* ===================== M6: Executive Council ===================== */

// ---- Council sessions (one per decision under cross-review) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_councils')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_councils` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `decision_id` INT(11) NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT "open",
        `recommendation` VARCHAR(30) NULL,
        `conflict` TINYINT(1) NOT NULL DEFAULT 0,
        `council_confidence` DECIMAL(4,2) NULL,
        `avg_confidence` DECIMAL(4,2) NULL,
        `brief` MEDIUMTEXT NULL,
        `result_json` MEDIUMTEXT NULL,
        `convened_by` INT(11) NULL,
        `consolidated_by` INT(11) NULL,
        `consolidated_at` DATETIME NULL,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `decision_id` (`decision_id`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Individual reviewer submissions ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_council_reviews')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_council_reviews` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `council_id` INT(11) NOT NULL,
        `decision_id` INT(11) NOT NULL,
        `reviewer_role` VARCHAR(30) NOT NULL,
        `reviewer_agent_id` INT(11) NULL,
        `stance` VARCHAR(20) NOT NULL DEFAULT "abstain",
        `confidence` DECIMAL(4,2) NULL,
        `dimension_impact` MEDIUMTEXT NULL,
        `comments` MEDIUMTEXT NULL,
        `is_dissent` TINYINT(1) NOT NULL DEFAULT 0,
        `actor_id` INT(11) NULL,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `council_role` (`council_id`, `reviewer_role`),
        KEY `decision_id` (`decision_id`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

/* ===================== M7: Multi-company + RBAC ===================== */

// ---- Plex Group companies registry ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_companies')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_companies` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(60) NOT NULL,
        `name` VARCHAR(150) NOT NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `code` (`code`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// Seed the 6 Plex Group companies (no stale table_exists() cache dependency).
require_once __DIR__ . '/libraries/Payplex_agent_rbac.php';
$coTbl = db_prefix() . 'payplex_ai_agent_companies';
foreach (Payplex_agent_rbac::defaultCompanies() as $co) {
    $exists = $CI->db->where('code', $co['code'])->get($coTbl)->row();
    if (!$exists) {
        $CI->db->insert($coTbl, array(
            'code'        => $co['code'],
            'name'        => $co['name'],
            'active'      => 1,
            'datecreated' => date('Y-m-d H:i:s'),
        ));
    }
}

// ---- Per-staff company access grants ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_company_access')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_company_access` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `staff_id` INT(11) NOT NULL,
        `company_code` VARCHAR(60) NOT NULL,
        `can_cross` TINYINT(1) NOT NULL DEFAULT 0,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `staff_company` (`staff_id`, `company_code`),
        KEY `staff_id` (`staff_id`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

/* ===================== M8: Executive Memory & Knowledge ===================== */

// ---- Executive Knowledge Base (governed, citable, company-scoped) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_exec_knowledge')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_exec_knowledge` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(200) NOT NULL,
        `body` MEDIUMTEXT NOT NULL,
        `category` VARCHAR(40) NOT NULL DEFAULT "general",
        `company` VARCHAR(60) NULL,
        `source` VARCHAR(255) NULL,
        `url` VARCHAR(500) NULL,
        `confidence` DECIMAL(4,3) NOT NULL DEFAULT 0.500,
        `status` VARCHAR(20) NOT NULL DEFAULT "draft",
        `tags` VARCHAR(500) NULL,
        `effective_from` DATE NULL,
        `effective_to` DATE NULL,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `updated_by` INT(11) NOT NULL DEFAULT 0,
        `approved_by` INT(11) NULL,
        `datecreated` DATETIME NULL,
        `dateupdated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `status` (`status`),
        KEY `company` (`company`),
        KEY `category` (`category`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Executive Memory (decision outcomes, lessons, council rulings) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_exec_memory')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_exec_memory` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `company` VARCHAR(60) NULL,
        `agent_id` INT(11) NULL,
        `decision_id` INT(11) NULL,
        `kind` VARCHAR(30) NOT NULL DEFAULT "note",
        `title` VARCHAR(200) NOT NULL,
        `body` MEDIUMTEXT NOT NULL,
        `tags` VARCHAR(500) NULL,
        `confidence` DECIMAL(4,3) NOT NULL DEFAULT 0.500,
        `source_ref` VARCHAR(120) NULL,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `company` (`company`),
        KEY `kind` (`kind`),
        KEY `decision_id` (`decision_id`),
        KEY `agent_id` (`agent_id`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

/* ===================== M9: Goals / OKRs / KPIs ===================== */

// ---- Objectives (company-scoped, per period) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_objectives')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_objectives` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(200) NOT NULL,
        `description` MEDIUMTEXT NULL,
        `company` VARCHAR(60) NULL,
        `period` VARCHAR(10) NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT "draft",
        `owner_agent_id` INT(11) NULL,
        `owner_staff` INT(11) NULL,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `datecreated` DATETIME NULL,
        `dateupdated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `company` (`company`),
        KEY `period` (`period`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Key results (measurable KPIs under an objective) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_keyresults')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_keyresults` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `objective_id` INT(11) NOT NULL,
        `title` VARCHAR(200) NOT NULL,
        `metric` VARCHAR(150) NULL,
        `unit` VARCHAR(40) NULL,
        `direction` VARCHAR(10) NOT NULL DEFAULT "increase",
        `baseline` DECIMAL(16,4) NOT NULL DEFAULT 0,
        `target` DECIMAL(16,4) NOT NULL DEFAULT 0,
        `current` DECIMAL(16,4) NOT NULL DEFAULT 0,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `datecreated` DATETIME NULL,
        `dateupdated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `objective_id` (`objective_id`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

/* ===================== M10: Agent-to-Agent Communication ===================== */

// ---- Threads (a coordination conversation between agents) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_threads')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_threads` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `subject` VARCHAR(200) NOT NULL,
        `company` VARCHAR(60) NULL,
        `kind` VARCHAR(20) NOT NULL DEFAULT "info",
        `status` VARCHAR(20) NOT NULL DEFAULT "open",
        `opened_by_agent` INT(11) NULL,
        `requires_human` TINYINT(1) NOT NULL DEFAULT 0,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `datecreated` DATETIME NULL,
        `dateupdated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `company` (`company`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Messages (internal only; external-action phrasing is flagged) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_messages')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_messages` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `thread_id` INT(11) NOT NULL,
        `from_agent_id` INT(11) NOT NULL,
        `to_agent_id` INT(11) NOT NULL,
        `body` MEDIUMTEXT NOT NULL,
        `msg_type` VARCHAR(20) NOT NULL DEFAULT "info",
        `priority` VARCHAR(10) NOT NULL DEFAULT "normal",
        `status` VARCHAR(20) NOT NULL DEFAULT "sent",
        `requires_human` TINYINT(1) NOT NULL DEFAULT 0,
        `external_flags` VARCHAR(500) NULL,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `datecreated` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `thread_id` (`thread_id`),
        KEY `from_agent_id` (`from_agent_id`),
        KEY `to_agent_id` (`to_agent_id`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

/* ===================== M11: Executive Council Voting ===================== */

// ---- Motions (a proposal put to the council for a weighted vote) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_motions')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_motions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(200) NOT NULL,
        `description` MEDIUMTEXT NULL,
        `company` VARCHAR(60) NULL,
        `motion_type` VARCHAR(20) NOT NULL DEFAULT "advisory",
        `threshold_rule` VARCHAR(30) NOT NULL DEFAULT "simple_majority",
        `quorum` DECIMAL(5,2) NOT NULL DEFAULT 0.50,
        `status` VARCHAR(20) NOT NULL DEFAULT "open",
        `proposed_by_agent` INT(11) NULL,
        `decision_id` INT(11) NULL,
        `requires_human` TINYINT(1) NOT NULL DEFAULT 0,
        `external_flags` VARCHAR(500) NULL,
        `result` VARCHAR(20) NULL,
        `result_json` MEDIUMTEXT NULL,
        `resolution_note` TEXT NULL,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `ratified_by` INT(11) NULL,
        `datecreated` DATETIME NULL,
        `dateupdated` DATETIME NULL,
        `closed_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `company` (`company`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

// ---- Motion votes (one weighted ballot per voting agent) ----
if (!$CI->db->table_exists(db_prefix() . 'payplex_ai_agent_motion_votes')) {
    $CI->db->query('CREATE TABLE `' . $prefix . 'payplex_ai_agent_motion_votes` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `motion_id` INT(11) NOT NULL,
        `voter_agent_id` INT(11) NOT NULL,
        `vote` VARCHAR(10) NOT NULL DEFAULT "abstain",
        `weight` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
        `rationale` TEXT NULL,
        `created_by` INT(11) NOT NULL DEFAULT 0,
        `datecreated` DATETIME NULL,
        `dateupdated` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `motion_voter` (`motion_id`, `voter_agent_id`),
        KEY `motion_id` (`motion_id`),
        KEY `voter_agent_id` (`voter_agent_id`)
    ) ENGINE=InnoDB ' . $charset . ';');
}
