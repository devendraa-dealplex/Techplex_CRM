<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Payplex AI Agents
Description: Admin-side Advanced AI Agent Builder - create, configure, clone, test (sandbox), submit, approve, activate, pause and archive AI agents with maker-checker approval, safety controls, budgets, kill switches and full audit logging. M2: knowledge base, cost dashboard, review queue, auto-pause. M3: AI calling decision layer (fail-closed, sandbox-first) and lead-automation pipeline (validate/dedupe/score/assign/follow-up, recommendations only). M4: 18 executive (C-suite) role templates, admin Create-Template form + custom-template CRUD, executive identity fields, save-as-template — all sandbox-first with the safety envelope enforced. M5: Chairman Command Centre, Decision Inbox, decision packets, and a configurable approval matrix (maker != approver, no blind approvals, high-risk actions always require the Chairman). M6: AI Executive Council cross-review (CFO/Risk/Legal/CISO/Audit reviews, Group CEO consolidation) with dissent preserved, conflict detection and hard-blockers — the Chairman still makes the final decision.
Version: 1.10.0
Requires at least: 2.3.*
Author: Payplex
*/

define('PAYPLEX_AI_AGENTS_MODULE', 'payplex_ai_agents');

/**
 * Activation: create the module's own tables (idempotent) and seed the 13
 * ready templates. No Perfex core table is touched.
 */
register_activation_hook(PAYPLEX_AI_AGENTS_MODULE, 'payplex_ai_agents_activate');
function payplex_ai_agents_activate()
{
    require __DIR__ . '/install.php';
    $CI = &get_instance();
    $CI->load->model('payplex_ai_agents/payplex_ai_agents_model');
    $CI->payplex_ai_agents_model->seedTemplates();
    $CI->payplex_ai_agents_model->setSetting('schema_version', defined('PAYPLEX_AI_SCHEMA_VERSION') ? PAYPLEX_AI_SCHEMA_VERSION : 2);
}

/**
 * Uninstall: drop this module's tables (documented rollback).
 */
register_uninstall_hook(PAYPLEX_AI_AGENTS_MODULE, 'payplex_ai_agents_uninstall');
function payplex_ai_agents_uninstall()
{
    require_once __DIR__ . '/uninstall.php';
}

hooks()->add_action('admin_init', 'payplex_ai_agents_migrate');
hooks()->add_action('admin_init', 'payplex_ai_agents_permissions');
hooks()->add_action('admin_init', 'payplex_ai_agents_menu');

// Lead-pipeline hooks. Fully guarded + OFF by default so the live lead flow is
// never affected until an admin explicitly enables auto-run in Settings.
/*
 * These were registered on 'after_lead_added' and
 * 'after_lead_converted_to_customer'. Neither hook exists.
 *
 * Searched every file under application/models, controllers, libraries, helpers
 * and core on this install: zero occurrences of either name. So the pipeline's
 * only automatic trigger has never fired once, and nothing errored — the fifth
 * silent no-op found on this project.
 *
 * What core actually fires, verified the same way:
 *   lead_created                — Leads_model, Cron_model, Forms
 *   lead_converted_to_customer  — controllers/admin/Leads.php
 *
 * The names differ only by a prefix, which is why it read as correct.
 */
hooks()->add_action('lead_created', 'payplex_ai_agents_lead_added');
hooks()->add_action('lead_converted_to_customer', 'payplex_ai_agents_lead_converted');

/**
 * Handle a newly created lead: run the pipeline in SANDBOX (dry-run, no writes)
 * and log recommendations. Never throws; a failure here must not break lead
 * creation. Does nothing unless the admin turned auto-run ON.
 */
function payplex_ai_agents_lead_added($leadId)
{
    payplex_ai_agents_run_pipeline_safe($leadId, 'lead_created');
}

function payplex_ai_agents_lead_converted($leadId)
{
    payplex_ai_agents_run_pipeline_safe($leadId, 'lead_converted');
}

function payplex_ai_agents_run_pipeline_safe($leadId, $eventType)
{
    try {
        $CI = &get_instance();
        $CI->load->model('payplex_ai_agents/payplex_ai_agents_model');
        if ((int) $CI->payplex_ai_agents_model->getSetting('pipeline_auto_run', 0) !== 1) {
            return; // disabled by default — no effect on the live lead flow
        }
        $CI->payplex_ai_agents_model->runPipelineForLead((int) $leadId, $eventType, 0);
    } catch (\Throwable $e) {
        // Swallow: pipeline logging must never disrupt CRM lead operations.
        if (function_exists('log_activity')) {
            // best-effort, non-fatal
            @log_activity('Payplex AI Agents pipeline hook error: ' . $e->getMessage());
        }
    }
}

/**
 * Lightweight schema migration. Runs the idempotent installer once when the
 * stored schema_version is behind the code, so an already-active module gains
 * new tables (M2: knowledge base + escalations) without a reinstall.
 */
define('PAYPLEX_AI_SCHEMA_VERSION', 11);
function payplex_ai_agents_migrate()
{
    $CI = &get_instance();
    $CI->load->model('payplex_ai_agents/payplex_ai_agents_model');
    $current = (int) $CI->payplex_ai_agents_model->getSetting('schema_version', 1);
    if ($current < PAYPLEX_AI_SCHEMA_VERSION) {
        require __DIR__ . '/install.php';   // all statements are CREATE TABLE IF NOT EXISTS
        $CI->payplex_ai_agents_model->setSetting('schema_version', PAYPLEX_AI_SCHEMA_VERSION);
    }
}

/**
 * Granular RBAC capabilities so roles can be granted view/create/edit/test/
 * submit/approve/activate/pause/budgets/logs/knowledge independently.
 */
function payplex_ai_agents_permissions()
{
    if (!function_exists('register_staff_capabilities')) {
        return;
    }
    $caps = array(
        'view'     => _l('permission_view'),
        'create'   => _l('permission_create'),
        'edit'     => _l('permission_edit'),
        'test'     => 'Test (sandbox)',
        'submit'   => 'Submit for approval',
        'approve'  => 'Approve / reject',
        'activate' => 'Activate / pause',
        'budgets'  => 'Manage budgets',
        'logs'     => 'View logs',
        'knowledge' => 'Manage knowledge',
        'decisions' => 'Submit decision packets',
        'chairman'  => 'Chairman final approval',
        'companies' => 'Manage companies & access',
        'exec_knowledge'         => 'Manage executive knowledge & memory',
        'exec_knowledge_approve' => 'Approve executive knowledge',
        'goals'                  => 'Manage goals & OKRs',
        'comms'                  => 'Agent communication',
        'council_vote'           => 'Council voting (propose & vote)',
        'ratify'                 => 'Chairman ratify / veto motions',
    );
    register_staff_capabilities(PAYPLEX_AI_AGENTS_MODULE, array('capabilities' => $caps), 'Payplex AI Agents');
}

/**
 * Sidebar menu.
 */
function payplex_ai_agents_menu()
{
    $CI = &get_instance();
    if (!isset($CI->app_menu) || !$CI->app_menu) {
        return;
    }
    if (!payplex_ai_agents_can('view')) {
        return;
    }

    $CI->app_menu->add_sidebar_menu_item('payplex-ai-agents', array(
        'name'     => 'AI Agents',
        'icon'     => 'fa fa-robot',
        'href'     => admin_url('payplex_ai_agents/agents'),
        'position' => 26,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-list',
        'name'     => 'Agents',
        'href'     => admin_url('payplex_ai_agents/agents'),
        'position' => 1,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-templates',
        'name'     => 'Templates',
        'href'     => admin_url('payplex_ai_agents/templates'),
        'position' => 2,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-exec',
        'name'     => 'Executive Council',
        'href'     => admin_url('payplex_ai_agents/templates?view=executive'),
        'position' => 3,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-command',
        'name'     => 'Chairman Command Centre',
        'href'     => admin_url('payplex_ai_agents/command'),
        'position' => 4,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-inbox',
        'name'     => 'Decision Inbox',
        'href'     => admin_url('payplex_ai_agents/command/inbox'),
        'position' => 5,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-companies',
        'name'     => 'Companies & Access',
        'href'     => admin_url('payplex_ai_agents/companies'),
        'position' => 6,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-execknowledge',
        'name'     => 'Executive Knowledge',
        'href'     => admin_url('payplex_ai_agents/execknowledge'),
        'position' => 7,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-execmemory',
        'name'     => 'Executive Memory',
        'href'     => admin_url('payplex_ai_agents/execknowledge/memory'),
        'position' => 8,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-goals',
        'name'     => 'Goals & OKRs',
        'href'     => admin_url('payplex_ai_agents/goals'),
        'position' => 9,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-performance',
        'name'     => 'Agent Performance',
        'href'     => admin_url('payplex_ai_agents/goals/performance'),
        'position' => 10,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-comms',
        'name'     => 'Agent Messages',
        'href'     => admin_url('payplex_ai_agents/comms'),
        'position' => 11,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-councilvote',
        'name'     => 'Council Votes',
        'href'     => admin_url('payplex_ai_agents/councilvote'),
        'position' => 12,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-dashboard',
        'name'     => 'Cost Dashboard',
        'href'     => admin_url('payplex_ai_agents/dashboard'),
        'position' => 3,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-kb',
        'name'     => 'Knowledge Base',
        'href'     => admin_url('payplex_ai_agents/knowledge'),
        'position' => 4,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-queue',
        'name'     => 'Review Queue',
        'href'     => admin_url('payplex_ai_agents/dashboard/queue'),
        'position' => 5,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-calls',
        'name'     => 'AI Call Log',
        'href'     => admin_url('payplex_ai_agents/calling'),
        'position' => 6,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-pipeline',
        'name'     => 'Pipeline Activity',
        'href'     => admin_url('payplex_ai_agents/pipeline'),
        'position' => 7,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-audit',
        'name'     => 'Audit Log',
        'href'     => admin_url('payplex_ai_agents/agents/audit'),
        'position' => 8,
    ));
    $CI->app_menu->add_sidebar_children_item('payplex-ai-agents', array(
        'slug'     => 'payplex-ai-agents-settings',
        'name'     => 'Settings & Kill Switch',
        'href'     => admin_url('payplex_ai_agents/agents/settings'),
        'position' => 9,
    ));
}

/**
 * Small Bootstrap-label helper for a lifecycle status, shared by the views.
 */
function payplex_ai_status_badge($status)
{
    $map = array('draft' => 'default', 'sandbox' => 'info', 'testing' => 'info', 'submitted' => 'warning',
        'approved' => 'primary', 'scheduled' => 'primary', 'active' => 'success', 'paused' => 'warning', 'archived' => 'default');
    $c = isset($map[$status]) ? $map[$status] : 'default';
    return '<span class="label label-' . $c . '">' . htmlspecialchars((string) $status) . '</span>';
}

/**
 * Permission helper. Admins always pass; otherwise check the granular capability
 * via whichever Perfex API is available on this install.
 */
function payplex_ai_agents_can($cap)
{
    if (function_exists('is_admin') && is_admin()) {
        return true;
    }
    if (function_exists('staff_can')) {
        return staff_can($cap, PAYPLEX_AI_AGENTS_MODULE);
    }
    if (function_exists('has_permission')) {
        return has_permission(PAYPLEX_AI_AGENTS_MODULE, '', $cap);
    }
    return false;
}
