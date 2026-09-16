<?php

defined('BASEPATH') or exit('No direct script access allowed');

// NOTE: every string below is written without contractions/apostrophes on
// purpose. A single unescaped apostrophe in exactly this kind of file
// (modules/leadgen_followup/language/english/leadgen_followup_lang.php)
// previously caused a site-wide PHP ParseError that crashed every route on
// staging for several days. If a string below is ever edited to include an
// apostrophe, it must be escaped as \' (inside single quotes) or the string
// switched to double quotes.

$lang['leadgen_control_tower_title'] = 'Control Tower';
$lang['leadgen_control_tower_settings'] = 'Control Tower Settings';
$lang['leadgen_control_tower_audit_logs'] = 'Control Tower Audit Logs';

$lang['leadgen_control_tower_dashboard_placeholder'] = 'The full monitoring dashboard is being built in stages. This page will show live summary cards and charts once Sub-phase B is deployed.';
$lang['leadgen_control_tower_schema_version'] = 'Schema version';

// Sub-phase B - Live Dashboard
$lang['leadgen_control_tower_card_active_leads'] = 'Active Leads';
$lang['leadgen_control_tower_card_new_today'] = 'New Today';
$lang['leadgen_control_tower_card_unassigned'] = 'Unassigned';
$lang['leadgen_control_tower_card_converted_30d'] = 'Converted, Last 30 Days';
$lang['leadgen_control_tower_card_lost'] = 'Lost';
$lang['leadgen_control_tower_card_junk'] = 'Junk / Spam';
$lang['leadgen_control_tower_by_source'] = 'Active Leads By Source';
$lang['leadgen_control_tower_by_status'] = 'Active Leads By Status';
$lang['leadgen_control_tower_no_data'] = 'No data yet';
$lang['leadgen_control_tower_health_snapshot'] = 'Lead Health Snapshot';
$lang['leadgen_control_tower_view_health_monitor'] = 'View Lead Health Monitor';
$lang['leadgen_control_tower_unknown_source'] = 'Unknown Source';
$lang['leadgen_control_tower_unknown_status'] = 'Unknown Status';

// Sub-phase B - Lead Health Monitor
$lang['leadgen_control_tower_lead_health'] = 'Lead Health Monitor';
$lang['leadgen_control_tower_health_green'] = 'Healthy';
$lang['leadgen_control_tower_health_amber'] = 'At Risk';
$lang['leadgen_control_tower_health_red'] = 'Critical';
$lang['leadgen_control_tower_health_grey'] = 'Closed';
$lang['leadgen_control_tower_health_all'] = 'All';
$lang['leadgen_control_tower_recalculate_now'] = 'Recalculate Now';
$lang['leadgen_control_tower_health_recalculated'] = 'Lead health recalculated successfully';
$lang['leadgen_control_tower_col_lead'] = 'Lead';
$lang['leadgen_control_tower_col_company'] = 'Company';
$lang['leadgen_control_tower_col_status'] = 'Status';
$lang['leadgen_control_tower_col_assigned'] = 'Assigned To';
$lang['leadgen_control_tower_col_health'] = 'Health';
$lang['leadgen_control_tower_col_reason'] = 'Reason';
$lang['leadgen_control_tower_col_calculated'] = 'Last Calculated';
$lang['leadgen_control_tower_health_none'] = 'No leads found for this filter. Health data is populated by cron or Recalculate Now.';
$lang['leadgen_control_tower_health_note'] = 'Health is based on elapsed time since last recorded activity (not yet business-hours-aware - that arrives with the full SLA engine in a later sub-phase).';

$lang['leadgen_control_tower_business_hours_start'] = 'Business hours start';
$lang['leadgen_control_tower_business_hours_end'] = 'Business hours end';
$lang['leadgen_control_tower_retention_days_audit'] = 'Audit log retention (days)';
$lang['leadgen_control_tower_settings_saved'] = 'Settings saved successfully';

$lang['leadgen_control_tower_audit_date'] = 'Date';
$lang['leadgen_control_tower_audit_staff'] = 'Staff';
$lang['leadgen_control_tower_audit_action'] = 'Action';
$lang['leadgen_control_tower_audit_subject'] = 'Subject';
$lang['leadgen_control_tower_audit_none'] = 'No audit log entries yet';

// Sub-phase C - SLA Rules, SLA Monitor, Unassigned/Missed-Followup/Stale-Leads
$lang['leadgen_control_tower_sla_rules'] = 'SLA Rules';
$lang['leadgen_control_tower_sla_monitor'] = 'SLA Monitor';
$lang['leadgen_control_tower_unassigned_leads'] = 'Unassigned Leads';
$lang['leadgen_control_tower_missed_followups'] = 'Missed Follow-Ups';
$lang['leadgen_control_tower_stale_leads'] = 'Stale Leads';

$lang['leadgen_control_tower_sla_add_rule'] = 'Add SLA Rule';
$lang['leadgen_control_tower_sla_edit_rule'] = 'Edit SLA Rule';
$lang['leadgen_control_tower_sla_rule_name'] = 'Rule Name';
$lang['leadgen_control_tower_sla_stage'] = 'Stage';
$lang['leadgen_control_tower_sla_stage_assignment'] = 'Assignment';
$lang['leadgen_control_tower_sla_stage_first_response'] = 'First Response';
$lang['leadgen_control_tower_sla_stage_followup'] = 'Follow-Up';
$lang['leadgen_control_tower_sla_stage_stale'] = 'Stale Lead';
$lang['leadgen_control_tower_sla_threshold_minutes'] = 'Threshold (minutes)';
$lang['leadgen_control_tower_sla_applies_to_status'] = 'Applies Only To Status (optional)';
$lang['leadgen_control_tower_sla_any_status'] = 'Any Status (global rule)';
$lang['leadgen_control_tower_sla_business_hours_only'] = 'Business Hours Only (not yet enforced by the engine)';
$lang['leadgen_control_tower_sla_active'] = 'Active';
$lang['leadgen_control_tower_save'] = 'Save';
$lang['leadgen_control_tower_cancel'] = 'Cancel';
$lang['leadgen_control_tower_edit'] = 'Edit';
$lang['leadgen_control_tower_delete'] = 'Delete';
$lang['leadgen_control_tower_sla_rule_saved'] = 'SLA rule saved successfully';
$lang['leadgen_control_tower_sla_rule_deleted'] = 'SLA rule deleted successfully';
$lang['leadgen_control_tower_sla_invalid_stage'] = 'Please choose a valid stage';
$lang['leadgen_control_tower_sla_invalid_threshold'] = 'Threshold minutes must be a positive number';
$lang['leadgen_control_tower_sla_recalculated'] = 'SLA breaches recalculated successfully';
$lang['leadgen_control_tower_sla_no_rules'] = 'No SLA rules configured yet';
$lang['leadgen_control_tower_sla_no_breaches'] = 'No open SLA breaches for this filter';
$lang['leadgen_control_tower_col_rule'] = 'Rule';
$lang['leadgen_control_tower_col_started'] = 'Started';
$lang['leadgen_control_tower_col_due'] = 'Due';
$lang['leadgen_control_tower_sla_threshold_note'] = 'Current threshold for this screen: %s minutes, from the active global SLA rule for this stage (set on the SLA Rules screen).';
$lang['leadgen_control_tower_unassigned_none'] = 'No unassigned leads right now';
$lang['leadgen_control_tower_missed_followups_none'] = 'No missed follow-ups right now';
$lang['leadgen_control_tower_stale_leads_none'] = 'No stale leads right now';
$lang['leadgen_control_tower_col_waiting_since'] = 'Waiting Since';
$lang['leadgen_control_tower_col_last_followup'] = 'Last Follow-Up';
$lang['leadgen_control_tower_col_stale_since'] = 'Status Unchanged Since';
$lang['leadgen_control_tower_col_source'] = 'Source';
$lang['leadgen_control_tower_never'] = 'Never';
$lang['leadgen_control_tower_missed_followups_note'] = 'Based only on the automated follow-up log used by the existing follow-up module. Manual tasks and reminders are not yet included here.';
$lang['leadgen_control_tower_stale_leads_note'] = 'Leads whose CRM status has not changed for longer than the stale-lead threshold, regardless of contact activity.';

// Sub-phase D - Hot Lead Monitor, Duplicate Monitor
$lang['leadgen_control_tower_hot_leads'] = 'Hot Lead Monitor';
$lang['leadgen_control_tower_hot_leads_none'] = 'No active hot leads right now';
$lang['leadgen_control_tower_hot_leads_no_status'] = 'No lead status literally named Hot is configured on this install. Configure one under Setup, Leads, Statuses, or ask an administrator to confirm the exact status name, then this screen and the related SLA rules will pick it up automatically.';
$lang['leadgen_control_tower_hot_assignment_threshold_label'] = 'Assignment threshold:';
$lang['leadgen_control_tower_hot_first_response_threshold_label'] = 'First response threshold:';
$lang['leadgen_control_tower_minutes_suffix'] = 'minutes';
$lang['leadgen_control_tower_hot_assignment_sla'] = 'Assignment SLA';
$lang['leadgen_control_tower_hot_first_response_sla'] = 'First Response SLA';
$lang['leadgen_control_tower_breached'] = 'Breached';
$lang['leadgen_control_tower_ok'] = 'OK';
$lang['leadgen_control_tower_not_applicable'] = 'N/A';

$lang['leadgen_control_tower_duplicate_monitor'] = 'Duplicate Monitor';
$lang['leadgen_control_tower_duplicate_detect_now'] = 'Detect Now';
$lang['leadgen_control_tower_duplicate_detected'] = 'Duplicate detection run completed successfully';
$lang['leadgen_control_tower_duplicate_none'] = 'No duplicate candidates for this filter';
$lang['leadgen_control_tower_duplicate_status_pending'] = 'Pending';
$lang['leadgen_control_tower_duplicate_status_merged'] = 'Merged';
$lang['leadgen_control_tower_duplicate_status_rejected'] = 'Rejected';
$lang['leadgen_control_tower_duplicate_confidence'] = 'Confidence';
$lang['leadgen_control_tower_duplicate_reason'] = 'Match Reason';
$lang['leadgen_control_tower_duplicate_reason_phone_match'] = 'Phone Number';
$lang['leadgen_control_tower_duplicate_reason_email_match'] = 'Email Address';
$lang['leadgen_control_tower_duplicate_lead_a'] = 'Lead A';
$lang['leadgen_control_tower_duplicate_lead_b'] = 'Lead B';
$lang['leadgen_control_tower_duplicate_merge'] = 'Mark As Duplicate (Merge)';
$lang['leadgen_control_tower_duplicate_reject'] = 'Not A Duplicate';
$lang['leadgen_control_tower_duplicate_choose_master'] = 'Choose which lead to keep as the master record. This only records the decision - it does not move any data between the two leads; consolidate them manually in the Leads screen afterward.';
$lang['leadgen_control_tower_duplicate_keep'] = 'Keep';
$lang['leadgen_control_tower_duplicate_resolved'] = 'Duplicate candidate updated successfully';
$lang['leadgen_control_tower_duplicate_invalid_decision'] = 'Please choose a valid decision';
$lang['leadgen_control_tower_duplicate_invalid_master'] = 'Please choose one of the two leads in this pair as the master record';
$lang['leadgen_control_tower_duplicate_reviewed_by'] = 'Reviewed By';
$lang['leadgen_control_tower_duplicate_master'] = 'Master Record';
$lang['leadgen_control_tower_duplicate_note'] = 'Detected by matching phone number or email address across active leads. Marking a pair Merged records the decision and which lead to keep as master - it does not automatically move data between leads; consolidate them manually in the Leads screen. Not yet optimized for very large lead volumes.';

// Permission labels (Roles / Permissions screen)
$lang['leadgen_control_tower_perm_view_dashboard'] = 'View';
$lang['leadgen_control_tower_perm_view_all_leads'] = 'View All Leads';
$lang['leadgen_control_tower_perm_view_team_leads'] = 'View Team Leads';
$lang['leadgen_control_tower_perm_view_own_leads'] = 'View Own Leads';
$lang['leadgen_control_tower_perm_view_employee_performance'] = 'View Employee Performance';
$lang['leadgen_control_tower_perm_view_source_costs'] = 'View Source Costs';
$lang['leadgen_control_tower_perm_view_revenue'] = 'View Revenue';
$lang['leadgen_control_tower_perm_manage_sla'] = 'Manage SLA Rules';
$lang['leadgen_control_tower_perm_manage_alerts'] = 'Manage Alert Rules';
$lang['leadgen_control_tower_perm_assign_leads'] = 'Assign / Reassign Leads';
$lang['leadgen_control_tower_perm_merge_duplicates'] = 'Merge Duplicates';
$lang['leadgen_control_tower_perm_export_reports'] = 'Export Reports';
$lang['leadgen_control_tower_perm_view_ai_insights'] = 'View AI Insights';
$lang['leadgen_control_tower_perm_approve_ai_actions'] = 'Approve AI Actions';
$lang['leadgen_control_tower_perm_view_audit_logs'] = 'View Audit Logs';
$lang['leadgen_control_tower_perm_manage_settings'] = 'Manage Settings';
