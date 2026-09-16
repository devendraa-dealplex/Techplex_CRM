<?php
/* Its Facebook and WhatsApp siblings carry this guard; this one did not, so the
 * file could be requested directly over HTTP. Nothing sensitive is in it, which
 * is why it is a warning rather than a finding — but a PHP file under the
 * webroot that runs on request is a habit worth not having. */
defined('BASEPATH') or exit('No direct script access allowed');
$lang['leadgen_followup_settings'] = 'Follow-Up Settings';
$lang['leadgen_followup_enabled'] = 'Enable automated follow-up';
$lang['leadgen_followup_stages_heading'] = 'Follow-Up Stages (days since last contact)';
$lang['leadgen_followup_stages_help'] = 'Enter the number of days of no contact after which each follow-up stage fires. Leave a field blank to disable that stage.';
$lang['leadgen_followup_stage1'] = 'Stage 1 (days)';
$lang['leadgen_followup_stage2'] = 'Stage 2 (days)';
$lang['leadgen_followup_stage3'] = 'Stage 3 (days)';
$lang['leadgen_followup_actions_heading'] = 'Follow-Up Actions';
$lang['leadgen_followup_remind_agent'] = 'Remind the assigned agent (in-CRM notification + email)';
$lang['leadgen_followup_email_lead'] = 'Email the lead directly';
$lang['leadgen_followup_create_task'] = 'Log a CRM task for the assigned agent';
$lang['leadgen_followup_task_due_days'] = 'Task due in (days)';
$lang['leadgen_followup_email_heading'] = 'Lead Follow-Up Email';
$lang['leadgen_followup_email_subject'] = 'Email subject';
$lang['leadgen_followup_email_body'] = 'Email body';
$lang['leadgen_followup_email_body_help'] = 'Use {lead_name} as a placeholder for the lead\'s name.';
$lang['leadgen_followup_recent_log'] = 'Recent Follow-Ups';
$lang['leadgen_followup_no_log'] = 'No follow-ups have been sent yet.';
$lang['leadgen_followup_log_lead'] = 'Lead';
$lang['leadgen_followup_log_stage'] = 'Stage (day)';
$lang['leadgen_followup_log_agent'] = 'Agent';
$lang['leadgen_followup_log_action'] = 'Action(s) taken';
$lang['leadgen_followup_log_date'] = 'Date sent';
$lang['leadgen_followup_notification'] = 'Lead "%s" needs a follow-up (Day %s) - no contact logged yet.';
$lang['leadgen_followup_perm_view'] = 'View';
$lang['leadgen_followup_perm_edit'] = 'Edit';

/* Added with the corrected scheduler (module 1.1.0). */
$lang['leadgen_followup_outbound_enabled'] = 'Allow this module to send (outbound gate)';
$lang['leadgen_followup_outbound_help'] = 'Separate from the switch above. While this is off the engine still runs, still selects one stage per lead and still records every decision — but no email, notification or task leaves the system. Leave it off until the interval and suppression rules have been verified.';
$lang['leadgen_followup_outbound_off_notice'] = 'Outbound sending is OFF. Follow-ups are being simulated and recorded, not sent.';
$lang['leadgen_followup_log_result'] = 'Result';
$lang['leadgen_followup_log_simulated'] = 'simulated — nothing was sent';
