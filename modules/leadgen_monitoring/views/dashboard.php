<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
<div class="content">
<div class="row">
<div class="col-md-12">
<div class="panel_s">
<div class="panel-body">
<h4 class="text-muted"><?php echo _l('leadgen_monitoring_dashboard_title'); ?></h4>
<hr />

<h5><strong>Lead Volume by Channel</strong></h5>
<div class="row">
<div class="col-md-2 col-sm-4">
<div class="panel tw-bg-neutral-50" style="padding:15px;text-align:center;border:1px solid #eee;border-radius:4px;">
<h3 style="margin:0;"><?php echo (int) $total_leads; ?></h3>
<small class="text-muted">Total Leads</small>
</div>
</div>
<div class="col-md-2 col-sm-4">
<div class="panel" style="padding:15px;text-align:center;border:1px solid #eee;border-radius:4px;">
<h3 style="margin:0;"><?php echo (int) $whatsapp_leads; ?></h3>
<small class="text-muted">WhatsApp</small>
</div>
</div>
<div class="col-md-2 col-sm-4">
<div class="panel" style="padding:15px;text-align:center;border:1px solid #eee;border-radius:4px;">
<h3 style="margin:0;"><?php echo (int) $facebook_leads; ?></h3>
<small class="text-muted">Facebook</small>
</div>
</div>
<div class="col-md-2 col-sm-4">
<div class="panel" style="padding:15px;text-align:center;border:1px solid #eee;border-radius:4px;">
<h3 style="margin:0;"><?php echo (int) $google_leads; ?></h3>
<small class="text-muted">Google</small>
</div>
</div>
<div class="col-md-2 col-sm-4">
<div class="panel" style="padding:15px;text-align:center;border:1px solid #eee;border-radius:4px;">
<h3 style="margin:0;"><?php echo (int) $other_leads; ?></h3>
<small class="text-muted">Other / Manual</small>
</div>
</div>
</div>

<?php if (!empty($facebook_by_channel)) { ?>
<div class="row" style="margin-top:15px;">
<div class="col-md-6">
<strong>Facebook — by channel</strong>
<table class="table table-condensed">
<thead><tr><th>Channel</th><th>Leads</th></tr></thead>
<tbody>
<?php foreach ($facebook_by_channel as $row) { ?>
<tr><td><?php echo htmlspecialchars($row->channel); ?></td><td><?php echo (int) $row->cnt; ?></td></tr>
<?php } ?>
</tbody>
</table>
</div>
<?php if (!empty($google_by_source)) { ?>
<div class="col-md-6">
<strong>Google — by source</strong>
<table class="table table-condensed">
<thead><tr><th>Source</th><th>Leads</th></tr></thead>
<tbody>
<?php foreach ($google_by_source as $row) { ?>
<tr><td><?php echo htmlspecialchars($row->source_type); ?></td><td><?php echo (int) $row->cnt; ?></td></tr>
<?php } ?>
</tbody>
</table>
</div>
<?php } ?>
</div>
<?php } ?>

<hr />
<h5><strong>Distribution Overview</strong>
<?php if ($distribution_enabled == '1') { ?>
<span class="label label-success">Enabled</span>
<?php } else { ?>
<span class="label label-default">Disabled</span>
<?php } ?>
</h5>
<p class="text-muted">Total auto-assignments so far: <strong><?php echo (int) $distribution_total; ?></strong></p>

<div class="row">
<div class="col-md-6">
<strong>Assignments by Agent</strong>
<table class="table table-condensed">
<thead><tr><th>Agent</th><th>Assignments</th></tr></thead>
<tbody>
<?php if (!empty($distribution_by_agent)) { foreach ($distribution_by_agent as $row) { ?>
<tr><td><?php echo htmlspecialchars($row->staff_name); ?></td><td><?php echo (int) $row->cnt; ?></td></tr>
<?php } } else { ?>
<tr><td colspan="2" class="text-muted">No assignments yet</td></tr>
<?php } ?>
</tbody>
</table>
</div>
<div class="col-md-6">
<strong>Recent Auto-Assignments</strong>
<table class="table table-condensed">
<thead><tr><th>Date</th><th>Lead</th><th>Assigned To</th></tr></thead>
<tbody>
<?php if (!empty($distribution_recent)) { foreach ($distribution_recent as $row) { ?>
<tr>
<td><?php echo htmlspecialchars($row->date_assigned); ?></td>
<td><?php echo htmlspecialchars($row->lead_name); ?></td>
<td><?php echo htmlspecialchars($row->staff_name); ?></td>
</tr>
<?php } } else { ?>
<tr><td colspan="3" class="text-muted">No recent activity</td></tr>
<?php } ?>
</tbody>
</table>
</div>
</div>

<hr />
<h5><strong>Follow-Up Overview</strong>
<?php if ($followup_enabled == '1') { ?>
<span class="label label-success">Enabled</span>
<?php } else { ?>
<span class="label label-default">Disabled</span>
<?php } ?>
</h5>
<p class="text-muted">Total follow-up log entries: <strong><?php echo (int) $followup_total; ?></strong></p>

<div class="row">
<div class="col-md-4">
<strong>By Stage (day)</strong>
<table class="table table-condensed">
<thead><tr><th>Stage Day</th><th>Entries</th></tr></thead>
<tbody>
<?php if (!empty($followup_by_stage)) { foreach ($followup_by_stage as $row) { ?>
<tr><td><?php echo (int) $row->stage_day; ?></td><td><?php echo (int) $row->cnt; ?></td></tr>
<?php } } else { ?>
<tr><td colspan="2" class="text-muted">No follow-ups yet</td></tr>
<?php } ?>
</tbody>
</table>
</div>
<div class="col-md-4">
<strong>Actions Taken</strong>
<table class="table table-condensed">
<tbody>
<tr><td>Agent reminders sent</td><td><?php echo (int) ($followup_action_counts->agent_reminder_count ?? 0); ?></td></tr>
<tr><td>Lead emails sent</td><td><?php echo (int) ($followup_action_counts->lead_email_count ?? 0); ?></td></tr>
<tr><td>Tasks created</td><td><?php echo (int) ($followup_action_counts->task_created_count ?? 0); ?></td></tr>
</tbody>
</table>
</div>
<div class="col-md-4">
<strong>Recent Follow-Ups</strong>
<table class="table table-condensed">
<thead><tr><th>Date</th><th>Lead</th></tr></thead>
<tbody>
<?php if (!empty($followup_recent)) { foreach ($followup_recent as $row) { ?>
<tr><td><?php echo htmlspecialchars($row->date_sent); ?></td><td><?php echo htmlspecialchars($row->lead_name); ?></td></tr>
<?php } } else { ?>
<tr><td colspan="2" class="text-muted">No follow-ups yet</td></tr>
<?php } ?>
</tbody>
</table>
</div>
</div>

</div>
</div>
</div>
</div>
</div>
</div>
<?php init_tail(); ?>
