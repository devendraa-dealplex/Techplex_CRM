<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Rollback for the Payplex AI Agents module. Runs when the module is removed.
 * Drops only this module's own tables — no Perfex core table is touched.
 */
$CI = &get_instance();

$tables = array(
    'payplex_ai_agent_company_access',
    'payplex_ai_agent_companies',
    'payplex_ai_agent_council_reviews',
    'payplex_ai_agent_councils',
    'payplex_ai_agent_approval_matrix',
    'payplex_ai_agent_decisions',
    'payplex_ai_agent_custom_templates',
    'payplex_ai_agent_pipeline_events',
    'payplex_ai_agent_calls',
    'payplex_ai_agent_escalations',
    'payplex_ai_agent_kb_versions',
    'payplex_ai_agent_kb',
    'payplex_ai_agent_runs',
    'payplex_ai_agent_audit',
    'payplex_ai_agent_versions',
    'payplex_ai_agent_settings',
    'payplex_ai_agents',
);

foreach ($tables as $t) {
    $CI->db->query('DROP TABLE IF EXISTS `' . db_prefix() . $t . '`');
}
