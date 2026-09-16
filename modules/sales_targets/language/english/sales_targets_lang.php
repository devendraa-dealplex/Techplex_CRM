<?php

defined('BASEPATH') or exit('No direct script access allowed');

$lang['sales_targets_title'] = 'Sales Targets';
$lang['sales_targets_list_hint'] = 'Set and track staff sales targets based on leads converted.';
$lang['sales_targets_add_new'] = 'Add New Target';
$lang['sales_targets_add_title'] = 'Add Sales Target';
$lang['sales_targets_edit_title'] = 'Edit Sales Target';
$lang['sales_targets_no_records'] = 'No sales targets set yet.';
$lang['sales_targets_col_staff'] = 'Staff Member';
$lang['sales_targets_col_period'] = 'Period';
$lang['sales_targets_col_target'] = 'Target (Leads Converted)';
$lang['sales_targets_col_achieved'] = 'Achieved';
$lang['sales_targets_col_progress'] = 'Progress';
$lang['sales_targets_select_staff'] = '- Select Staff -';
$lang['sales_targets_target_hint'] = 'Number of leads to convert in this period.';
$lang['sales_targets_period_start'] = 'Period Start';
$lang['sales_targets_period_end'] = 'Period End';
$lang['sales_targets_notes'] = 'Notes';
$lang['sales_targets_added'] = 'Sales target added successfully.';
$lang['sales_targets_updated'] = 'Sales target updated successfully.';
$lang['sales_targets_deleted'] = 'Sales target deleted successfully.';

// Phase 2 â granular permission labels (Setup > Roles)
/*
 * The permission labels for view_own, view_revenue, view_commission,
 * export_reports, resolve_disputes and manage_settings were removed with the
 * capabilities themselves: each appeared on the Roles screen and was enforced
 * nowhere. See DROPPED_PERMISSIONS.md. A label left behind for a capability
 * that no longer exists is how a dropped permission quietly comes back.
 */
$lang['sales_targets_perm_view_team'] = 'View Team Targets';
$lang['sales_targets_perm_view_all'] = 'View All Targets';
$lang['sales_targets_perm_create'] = 'Create Targets';
$lang['sales_targets_perm_edit_draft'] = 'Edit Draft Targets';
$lang['sales_targets_perm_submit'] = 'Submit Targets';
$lang['sales_targets_perm_approve_activate'] = 'Approve/Activate Targets';
$lang['sales_targets_perm_revise_active'] = 'Revise Active Targets';
$lang['sales_targets_perm_lock_complete'] = 'Lock/Complete Targets';
$lang['sales_targets_perm_cancel_archive'] = 'Cancel/Archive Targets';

// Phase 4 - optional per-target metric labels (target_revenue_value / target_collected_value)
$lang['sales_targets_col_target_revenue'] = 'Target (Gross Billed Revenue)';
$lang['sales_targets_col_target_collected'] = 'Target (Amount Collected)';
$lang['sales_targets_target_revenue_hint'] = 'Optional. Revenue to bill (invoice total) in this period. Leave blank if not tracking this KPI for this target.';
$lang['sales_targets_target_collected_hint'] = 'Optional. Payments to collect in this period. Leave blank if not tracking this KPI for this target.';
