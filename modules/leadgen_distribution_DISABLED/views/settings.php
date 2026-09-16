<?php init_head(); ?>
<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div id="wrapper"><div class="content">
<?php echo form_open(admin_url('leadgen_distribution')); ?>
<div class="row">
<div class="col-md-6">
<div class="checkbox checkbox-primary">
<input type="checkbox" id="leadgen_distribution_enabled" name="leadgen_distribution_enabled" value="1" <?php if ($enabled == '1') { echo 'checked'; } ?>>
<label for="leadgen_distribution_enabled"><?= _l('leadgen_distribution_enable'); ?></label>
</div>
<hr />
<h4><?= _l('leadgen_distribution_agents_heading'); ?></h4>
<p class="text-muted"><?= _l('leadgen_distribution_agents_hint'); ?></p>
<?php if (!empty($staff)) { foreach ($staff as $member) { ?>
<div class="checkbox checkbox-primary">
<input type="checkbox" id="agent_<?= $member['staffid']; ?>" name="leadgen_distribution_agents[]" value="<?= $member['staffid']; ?>" <?php if (in_array($member['staffid'], $selected_agents)) { echo 'checked'; } ?>>
<label for="agent_<?= $member['staffid']; ?>"><?= $member['firstname'] . ' ' . $member['lastname']; ?></label>
</div>
<?php } } ?>
<hr />
<button type="submit" class="btn btn-primary"><?= _l('submit'); ?></button>
</div>
<div class="col-md-6">
<div class="alert alert-info">
<strong><?= _l('leadgen_distribution_how_it_works'); ?></strong>
<p><?= _l('leadgen_distribution_how_it_works_text'); ?></p>
</div>
<h4><?= _l('leadgen_distribution_recent_heading'); ?></h4>
<?php if (!empty($recent_log)) { ?>
<table class="table table-striped">
<thead><tr><th><?= _l('leadgen_distribution_lead'); ?></th><th><?= _l('leadgen_distribution_agent'); ?></th><th><?= _l('leadgen_distribution_date'); ?></th></tr></thead>
<tbody>
<?php foreach ($recent_log as $row) { ?>
<tr><td><?= !empty($row['lead_name']) ? $row['lead_name'] : ('#' . $row['lead_id']); ?></td><td><?= $row['firstname'] . ' ' . $row['lastname']; ?></td><td><?= $row['date_assigned']; ?></td></tr>
<?php } ?>
</tbody>
</table>
<?php } else { ?>
<p class="text-muted"><?= _l('leadgen_distribution_no_log'); ?></p>
<?php } ?>
</div>
</div>
<?php echo form_close(); ?>
</div></div>
<?php init_tail(); ?>