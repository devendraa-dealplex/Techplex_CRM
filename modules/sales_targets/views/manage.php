<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
<div class="content">
<div class="row">
<div class="col-md-8 col-md-offset-2">
<div class="panel_s">
<div class="panel-body">
<h4><?php echo $title; ?></h4>
<hr>
<?php echo form_open(current_url()); ?>
<div class="form-group">
<label for="staff_id"><?php echo _l('sales_targets_col_staff'); ?></label>
<select name="staff_id" id="staff_id" class="form-control selectpicker" required>
<option value=""><?php echo _l('sales_targets_select_staff'); ?></option>
<?php foreach ($staff as $member) { ?>
<option value="<?php echo $member['staffid']; ?>" <?php echo (isset($target['staff_id']) && $target['staff_id'] == $member['staffid']) ? 'selected' : ''; ?>>
<?php echo e($member['firstname'] . ' ' . $member['lastname']); ?>
</option>
<?php } ?>
</select>
</div>
<div class="form-group">
<label for="target_value"><?php echo _l('sales_targets_col_target'); ?></label>
<input type="number" min="1" name="target_value" id="target_value" class="form-control" value="<?php echo isset($target['target_value']) ? (int) $target['target_value'] : ''; ?>" required>
<span class="help-block"><?php echo _l('sales_targets_target_hint'); ?></span>
</div>
<div class="form-group">
<label for="target_revenue_value"><?php echo _l('sales_targets_col_target_revenue'); ?></label>
<input type="number" min="0" step="0.01" name="target_revenue_value" id="target_revenue_value" class="form-control" value="<?php echo isset($metrics['gross_billed_revenue']) ? $metrics['gross_billed_revenue'] : ''; ?>">
<span class="help-block"><?php echo _l('sales_targets_target_revenue_hint'); ?></span>
</div>
<div class="form-group">
<label for="target_collected_value"><?php echo _l('sales_targets_col_target_collected'); ?></label>
<input type="number" min="0" step="0.01" name="target_collected_value" id="target_collected_value" class="form-control" value="<?php echo isset($metrics['amount_collected']) ? $metrics['amount_collected'] : ''; ?>">
<span class="help-block"><?php echo _l('sales_targets_target_collected_hint'); ?></span>
</div>
<div class="row">
<div class="col-md-6">
<div class="form-group">
<label for="period_start"><?php echo _l('sales_targets_period_start'); ?></label>
<input type="date" name="period_start" id="period_start" class="form-control" value="<?php echo isset($target['period_start']) ? $target['period_start'] : ''; ?>" required>
</div>
</div>
<div class="col-md-6">
<div class="form-group">
<label for="period_end"><?php echo _l('sales_targets_period_end'); ?></label>
<input type="date" name="period_end" id="period_end" class="form-control" value="<?php echo isset($target['period_end']) ? $target['period_end'] : ''; ?>" required>
</div>
</div>
</div>
<div class="form-group">
<label for="notes"><?php echo _l('sales_targets_notes'); ?></label>
<textarea name="notes" id="notes" class="form-control" rows="2"><?php echo isset($target['notes']) ? e($target['notes']) : ''; ?></textarea>
</div>
<hr>
<button type="submit" class="btn btn-primary"><?php echo _l('submit'); ?></button>
<a href="<?php echo admin_url('sales_targets'); ?>" class="btn btn-default"><?php echo _l('cancel'); ?></a>
<?php echo form_close(); ?>
</div>
</div>
</div>
</div>
</div>
</div>
<?php init_tail(); ?>
