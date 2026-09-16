<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_templates
 *
 * The 13 ready-made, editable agent templates. Pure data so they can be seeded
 * into the DB, cloned into new agents, and asserted in tests. Every template is
 * created in sandbox mode with conservative safety defaults; nothing here can
 * act in production until an admin configures, submits and a different admin
 * approves it.
 */
class Payplex_agent_templates
{
    /**
     * Default safety envelope applied to every template. Kept deliberately
     * strict; the admin loosens it per agent, never the reverse by default.
     */
    private static function defaults()
    {
        return array(
            'mode'                 => 'sandbox',
            'status'               => 'draft',
            'confidence_threshold' => 0.75,
            'human_escalation'     => 1,
            'retry_limit'          => 2,
            'daily_execution_limit' => 100,
            'token_limit'          => 100000,
            'daily_budget'         => 5.0,
            'monthly_budget'       => 100.0,
            'prohibited_actions'   => array(
                'make_payout', 'issue_refund', 'modify_financial_record',
                'delete_crm_record', 'change_user_permission', 'deploy_code',
                'execute_sql', 'execute_server_command',
            ),
            'approval_required_actions' => array(
                'make_real_call', 'send_bulk_message', 'send_live_message',
                'purchase_service', 'activate_paid_service',
            ),
        );
    }

    /**
     * Returns all 13 templates as fully-formed config arrays.
     */
    public static function all()
    {
        $d = self::defaults();
        $t = array();

        $t[] = self::make($d, 'lead_capture', 'Lead Capture Agent', 'lead_management',
            'Capture inbound leads from web forms, ads and messaging, validate and de-duplicate them before they enter the pipeline.',
            'You are a lead-capture assistant. Validate contact details, remove duplicates, attribute the lead source and UTM, then create a clean lead record. Never contact the customer yourself.',
            array('validate_lead', 'deduplicate_lead', 'enrich_lead', 'create_lead', 'attribute_source'),
            array('new_web_lead', 'new_ad_lead', 'inbound_message'));

        $t[] = self::make($d, 'lead_qualification', 'Lead Qualification Agent', 'sales',
            'Qualify new leads against configurable BANT/ICP criteria and tag them qualified / unqualified with a reason.',
            'You qualify leads using the configured criteria. Ask only permitted questions, record answers, and set a qualification outcome with a short justification. Escalate ambiguous cases.',
            array('read_lead', 'update_lead_fields', 'create_task'),
            array('lead_created', 'lead_updated'));

        $t[] = self::make($d, 'lead_scoring', 'Lead Scoring Agent', 'sales',
            'Score leads 0-100 from behaviour, source quality and profile fit using the configured weights.',
            'You compute a lead score from the configured signals and weights only. Output the score and the top contributing factors. Do not invent data.',
            array('read_lead', 'update_lead_score'),
            array('lead_created', 'lead_activity'));

        $t[] = self::make($d, 'lead_assignment', 'Lead Assignment Agent', 'sales',
            'Route qualified leads to the right rep by territory, workload and round-robin rules.',
            'You assign leads to sales staff using the configured territory and workload rules. Balance workload, respect availability, and log the assignment reason.',
            array('read_lead', 'assign_lead', 'read_staff_workload', 'create_task'),
            array('lead_qualified', 'lead_scored'));

        $t[] = self::make($d, 'sales_followup', 'Sales Follow-up Agent', 'sales',
            'Create and remind on timely follow-up tasks so no lead goes cold.',
            'You draft follow-up steps and schedule follow-up tasks per the cadence configured. You may draft messages for human review but must not send live messages without approval.',
            array('read_lead', 'create_task', 'draft_message', 'read_activity'),
            array('lead_assigned', 'no_activity_timer'));

        $t[] = self::make($d, 'ai_calling', 'AI Calling Agent', 'sales',
            'Plan and (after approval) place outbound calls via the AI Calling backend, then log outcomes.',
            'You prepare call scripts and call plans. Real calls require per-run human approval and production mode. In sandbox you only simulate the call and produce a transcript.',
            array('read_lead', 'prepare_call', 'make_real_call', 'log_call_outcome'),
            array('call_scheduled', 'followup_due'));

        $t[] = self::make($d, 'meeting_scheduling', 'Meeting Scheduling Agent', 'sales',
            'Propose meeting slots in the customer timezone and create calendar/CRM events.',
            'You propose meeting times honouring working hours and the customer timezone, then create the meeting record. Confirm before sending any live invite.',
            array('read_lead', 'read_calendar', 'create_meeting', 'draft_message'),
            array('meeting_requested', 'proposal_accepted'));

        $t[] = self::make($d, 'proposal_assistance', 'Proposal Assistance Agent', 'sales',
            'Draft proposals from approved product, pricing and policy knowledge.',
            'You draft proposals using only approved knowledge-base pricing and product content. Flag anything outside the knowledge base for human input. Never invent pricing.',
            array('read_lead', 'read_knowledge', 'draft_proposal'),
            array('proposal_requested'));

        $t[] = self::make($d, 'payment_reminder', 'Payment Reminder Agent', 'finance',
            'Detect due/overdue invoices and prepare polite reminders (send needs approval).',
            'You identify due and overdue invoices and draft reminders. You must never issue refunds, take payments or modify financial records. Sending live reminders requires approval.',
            array('read_invoice', 'draft_message', 'create_task'),
            array('invoice_due', 'invoice_overdue'));

        $t[] = self::make($d, 'stale_lead_monitor', 'Stale Lead Monitoring Agent', 'sales',
            'Watch for leads with no activity past SLA and raise alerts / escalations.',
            'You monitor lead inactivity against the configured SLA and raise alerts and escalation tasks. You do not contact customers directly.',
            array('read_lead', 'read_activity', 'create_task', 'notify_manager'),
            array('sla_timer', 'daily_scan'));

        $t[] = self::make($d, 'sales_manager', 'Sales Manager Agent', 'management',
            'Summarise pipeline health, spot risks, and escalate stuck deals to managers.',
            'You produce pipeline summaries and risk flags from CRM data for managers. You recommend actions but do not execute changes to deals yourself.',
            array('read_pipeline', 'read_lead', 'notify_manager', 'create_task'),
            array('daily_scan', 'weekly_scan'));

        $t[] = self::make($d, 'customer_support', 'Customer Support Agent', 'support',
            'Answer customer questions strictly from approved knowledge and escalate the rest.',
            'You answer only from permitted knowledge-base content. If the answer is not in the knowledge base or confidence is low, escalate to a human. Never reveal confidential data.',
            array('read_knowledge', 'read_ticket', 'draft_reply', 'create_ticket'),
            array('new_ticket', 'customer_message'));

        $t[] = self::make($d, 'daily_summary', 'Daily Management Summary Agent', 'management',
            'Compile a daily summary of leads, conversions, revenue attribution and agent activity.',
            'You compile a factual daily management summary from CRM metrics. Report numbers with their source. Do not make changes to any record.',
            array('read_pipeline', 'read_reports', 'notify_manager'),
            array('daily_schedule'));

        return $t;
    }

    private static function make($d, $slug, $name, $department, $purpose, $systemPrompt, $allowedTools, $triggers)
    {
        return array(
            'template_slug'       => $slug,
            'name'                => $name,
            'department'          => $department,
            'purpose'             => $purpose,
            'description'         => $purpose,
            'system_prompt'       => $systemPrompt,
            'ai_provider'         => 'openai',
            'ai_model'            => 'gpt-4o-mini',
            'allowed_tools'       => $allowedTools,
            'triggers'            => $triggers,
            'mode'                => $d['mode'],
            'status'              => $d['status'],
            'confidence_threshold' => $d['confidence_threshold'],
            'human_escalation'    => $d['human_escalation'],
            'retry_limit'         => $d['retry_limit'],
            'daily_execution_limit' => $d['daily_execution_limit'],
            'token_limit'         => $d['token_limit'],
            'daily_budget'        => $d['daily_budget'],
            'monthly_budget'      => $d['monthly_budget'],
            'prohibited_actions'  => $d['prohibited_actions'],
            'approval_required_actions' => $d['approval_required_actions'],
            'is_template'         => 1,
        );
    }

    /** Look up one template by slug. */
    public static function bySlug($slug)
    {
        foreach (self::all() as $tpl) {
            if ($tpl['template_slug'] === $slug) {
                return $tpl;
            }
        }
        return null;
    }
}
