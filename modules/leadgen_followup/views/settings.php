<?php
defined('BASEPATH') or exit('No direct script access allowed');
init_head();
?>
<div id="wrapper">
<div class="content">
<div class="row">
<div class="col-md-8">
<div class="panel_s">
<div class="panel-body">
<h4><?php echo $title; ?></h4>
<hr class="hr-panel-heading" />
<?php echo form_open(admin_url('leadgen_followup')); ?>
<div class="checkbox checkbox-primary">
<input type="checkbox" name="enabled" id="enabled" value="1" <?php echo ($enabled == '1') ? 'checked' : ''; ?> />
<label for="enabled"><?php echo _l('leadgen_followup_enabled'); ?></label>
</div>
<?php
/*
 * The outbound gate is a second, independent switch, and it is administrator-only.
 *
 * Arming the engine and letting it send are different decisions. While this is
 * off the engine still runs, still selects a stage, still records everything it
 * would have done — and sends nothing. That is what makes the interval and
 * suppression rules testable on staging without writing to a single lead.
 *
 * It is rendered only for administrators because every other control on this
 * page changes what a message says, while this one decides whether messages
 * leave the building at all.
 */
if (is_admin()) { ?>
<div class="checkbox checkbox-primary">
<input type="checkbox" name="outbound_enabled" id="outbound_enabled" value="1" <?php echo ($outbound_enabled == '1') ? 'checked' : ''; ?> />
<label for="outbound_enabled"><?php echo _l('leadgen_followup_outbound_enabled'); ?></label>
</div>
<p class="text-muted"><?php echo _l('leadgen_followup_outbound_help'); ?></p>
<?php if ($outbound_enabled != '1') { ?>
<div class="alert alert-warning"><?php echo _l('leadgen_followup_outbound_off_notice'); ?></div>
<?php } ?>
<?php } ?>
<hr />
<h5><?php echo _l('leadgen_followup_stages_heading'); ?></h5>
<p class="text-muted"><?php echo _l('leadgen_followup_stages_help'); ?></p>
<div class="row">
<div class="col-md-4">
<div class="form-group">
<label for="stage1"><?php echo _l('leadgen_followup_stage1'); ?></label>
<input type="number" min="1" class="form-control" name="stage1" id="stage1" value="<?php echo htmlspecialchars($stage1); ?>" />
</div>
</div>
<div class="col-md-4">
<div class="form-group">
<label for="stage2"><?php echo _l('leadgen_followup_stage2'); ?></label>
<input type="number" min="1" class="form-control" name="stage2" id="stage2" value="<?php echo htmlspecialchars($stage2); ?>" />
</div>
</div>
<div class="col-md-4">
<div class="form-group">
<label for="stage3"><?php echo _l('leadgen_followup_stage3'); ?></label>
<input type="number" min="1" class="form-control" name="stage3" id="stage3" value="<?php echo htmlspecialchars($stage3); ?>" />
</div>
</div>
</div>
<hr />
<h5><?php echo _l('leadgen_followup_actions_heading'); ?></h5>
<div class="checkbox checkbox-primary">
<input type="checkbox" name="remind_agent" id="remind_agent" value="1" <?php echo ($remind_agent == '1') ? 'checked' : ''; ?> />
<label for="remind_agent"><?php echo _l('leadgen_followup_remind_agent'); ?></label>
</div>
<div class="checkbox checkbox-primary">
<input type="checkbox" name="email_lead" id="email_lead" value="1" <?php echo ($email_lead == '1') ? 'checked' : ''; ?> />
<label for="email_lead"><?php echo _l('leadgen_followup_email_lead'); ?></label>
</div>
<div class="checkbox checkbox-primary">
<input type="checkbox" name="create_task" id="create_task" value="1" <?php echo ($create_task == '1') ? 'checked' : ''; ?> />
<label for="create_task"><?php echo _l('leadgen_followup_create_task'); ?></label>
</div>
<div class="form-group">
<label for="task_due_days"><?php echo _l('leadgen_followup_task_due_days'); ?></label>
<input type="number" min="1" class="form-control" style="max-width:150px;" name="task_due_days" id="task_due_days" value="<?php echo htmlspecialchars($task_due_days); ?>" />
</div>
<hr />
<h5><?php echo _l('leadgen_followup_email_heading'); ?></h5>
<div class="form-group">
<label for="email_subject"><?php echo _l('leadgen_followup_email_subject'); ?></label>
<input type="text" class="form-control" name="email_subject" id="email_subject" value="<?php echo htmlspecialchars($email_subject); ?>" />
</div>
<div class="form-group">
<label for="email_body"><?php echo _l('leadgen_followup_email_body'); ?></label>
<textarea class="form-control" rows="4" name="email_body" id="email_body"><?php echo htmlspecialchars($email_body); ?></textarea>
<span class="text-muted"><?php echo _l('leadgen_followup_email_body_help'); ?></span>
</div>
<hr />
<button type="submit" class="btn btn-info"><?php echo _l('submit'); ?></button>
<?php echo form_close(); ?>
</div>
</div>
</div>
<div class="col-md-4">
<div class="panel_s">
<div class="panel-body">
<h4><?php echo _l('leadgen_followup_recent_log'); ?></h4>
<hr class="hr-panel-heading" />
<?php if (empty($recent_log)) { ?>
<p class="text-muted"><?php echo _l('leadgen_followup_no_log'); ?></p>
<?php } else { ?>
<table class="table">
<tr>
<th><?php echo _l('leadgen_followup_log_lead'); ?></th>
<th><?php echo _l('leadgen_followup_log_stage'); ?></th>
<th><?php echo _l('leadgen_followup_log_agent'); ?></th>
<th><?php echo _l('leadgen_followup_log_action'); ?></th>
<th><?php echo _l('leadgen_followup_log_result'); ?></th>
<th><?php echo _l('leadgen_followup_log_date'); ?></th>
</tr>
<?php foreach ($recent_log as $row) {
    /*
     * `action_taken` says what was attempted; `send_result` says what happened.
     * They were one column, and a log that cannot tell a send from a refusal by
     * the mail server is not evidence of anything. A simulated row is labelled,
     * so a dry run can never be mistaken for a delivery.
     */
    $result = isset($row['send_result']) ? $row['send_result'] : '';
    $mode   = isset($row['run_mode']) ? $row['run_mode'] : '';
    $class  = 'label-default';
    if ($result === 'sent')                              { $class = 'label-success'; }
    elseif ($result === 'failed')                        { $class = 'label-danger'; }
    elseif ($result === 'partial')                       { $class = 'label-warning'; }
    elseif ($result === 'simulated' || $mode === 'simulated') { $class = 'label-info'; }
?>
<tr>
<td><?php echo htmlspecialchars($row['lead_name']); ?></td>
<td><?php echo htmlspecialchars($row['stage_day']); ?></td>
<td><?php echo htmlspecialchars($row['firstname'] . ' ' . $row['lastname']); ?></td>
<td><?php echo htmlspecialchars($row['action_taken']); ?></td>
<td>
<?php if ($result !== '') { ?><span class="label <?php echo $class; ?>"><?php echo htmlspecialchars($result); ?></span><?php } ?>
<?php if ($mode === 'simulated') { ?><br /><small class="text-muted"><?php echo _l('leadgen_followup_log_simulated'); ?></small><?php } ?>
<?php if (!empty($row['suppression_reason'])) { ?><br /><small class="text-muted"><?php echo htmlspecialchars($row['suppression_reason']); ?></small><?php } ?>
</td>
<td><?php echo _dt($row['date_sent']); ?></td>
</tr>
<?php } ?>
</table>
<?php } ?>
</div>
</div>
</div>
</div>
</div>
</div>
<?php init_tail(); ?>
