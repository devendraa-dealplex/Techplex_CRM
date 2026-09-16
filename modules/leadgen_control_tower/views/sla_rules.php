<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <h4><?php echo _l('leadgen_control_tower_sla_rules'); ?></h4>
                <hr class="hr-panel-heading" />

                <div class="row">
                    <div class="col-md-5">
                        <div class="panel_s" style="padding:15px;">
                            <h4><?php echo $edit_rule ? _l('leadgen_control_tower_sla_edit_rule') : _l('leadgen_control_tower_sla_add_rule'); ?></h4>
                            <?php echo form_open(admin_url('leadgen_control_tower/sla_rule_save')); ?>
                            <?php if ($edit_rule) { ?>
                                <input type="hidden" name="id" value="<?php echo (int) $edit_rule['id']; ?>">
                            <?php } ?>

                            <div class="form-group">
                                <label><?php echo _l('leadgen_control_tower_sla_rule_name'); ?></label>
                                <input type="text" class="form-control" name="name" required
                                    value="<?php echo $edit_rule ? html_escape($edit_rule['name']) : ''; ?>">
                            </div>

                            <div class="form-group">
                                <label><?php echo _l('leadgen_control_tower_sla_stage'); ?></label>
                                <?php
                                $stages = array(
                                    'assignment' => _l('leadgen_control_tower_sla_stage_assignment'),
                                    'first_response' => _l('leadgen_control_tower_sla_stage_first_response'),
                                    'followup' => _l('leadgen_control_tower_sla_stage_followup'),
                                    'stale' => _l('leadgen_control_tower_sla_stage_stale'),
                                );
                                $current_stage = $edit_rule ? $edit_rule['stage'] : '';
                                ?>
                                <select class="form-control selectpicker" name="stage" required>
                                    <?php foreach ($stages as $key => $label) { ?>
                                    <option value="<?php echo $key; ?>" <?php echo $current_stage === $key ? 'selected' : ''; ?>>
                                        <?php echo html_escape($label); ?>
                                    </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label><?php echo _l('leadgen_control_tower_sla_threshold_minutes'); ?></label>
                                <input type="number" min="1" class="form-control" name="threshold_minutes" required
                                    value="<?php echo $edit_rule ? (int) $edit_rule['threshold_minutes'] : ''; ?>">
                            </div>

                            <div class="form-group">
                                <label><?php echo _l('leadgen_control_tower_sla_applies_to_status'); ?></label>
                                <select class="form-control selectpicker" name="applies_to_status">
                                    <option value=""><?php echo _l('leadgen_control_tower_sla_any_status'); ?></option>
                                    <?php foreach ($statuses as $status) {
                                        $selected = $edit_rule && (int) $edit_rule['applies_to_id'] === (int) $status['id'] && $edit_rule['applies_to_type'] === 'status';
                                    ?>
                                    <option value="<?php echo (int) $status['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                        <?php echo html_escape($status['name']); ?>
                                    </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="checkbox checkbox-primary">
                                <input type="checkbox" name="business_hours_only" id="business_hours_only"
                                    <?php echo ($edit_rule && $edit_rule['business_hours_only']) ? 'checked' : ''; ?>>
                                <label for="business_hours_only"><?php echo _l('leadgen_control_tower_sla_business_hours_only'); ?></label>
                            </div>

                            <div class="checkbox checkbox-primary">
                                <input type="checkbox" name="active" id="active"
                                    <?php echo (!$edit_rule || $edit_rule['active']) ? 'checked' : ''; ?>>
                                <label for="active"><?php echo _l('leadgen_control_tower_sla_active'); ?></label>
                            </div>

                            <button type="submit" class="btn btn-primary"><?php echo _l('leadgen_control_tower_save'); ?></button>
                            <?php if ($edit_rule) { ?>
                            <a href="<?php echo admin_url('leadgen_control_tower/sla_rules'); ?>" class="btn btn-default">
                                <?php echo _l('leadgen_control_tower_cancel'); ?>
                            </a>
                            <?php } ?>
                            <?php echo form_close(); ?>
                        </div>
                    </div>

                    <div class="col-md-7">
                        <div class="panel_s" style="padding:15px;">
                            <?php if (empty($rules)) { ?>
                                <div class="alert alert-info"><?php echo _l('leadgen_control_tower_sla_no_rules'); ?></div>
                            <?php } else { ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th><?php echo _l('leadgen_control_tower_sla_rule_name'); ?></th>
                                        <th><?php echo _l('leadgen_control_tower_sla_stage'); ?></th>
                                        <th><?php echo _l('leadgen_control_tower_sla_threshold_minutes'); ?></th>
                                        <th><?php echo _l('leadgen_control_tower_col_status'); ?></th>
                                        <th><?php echo _l('leadgen_control_tower_sla_active'); ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rules as $rule) { ?>
                                    <tr>
                                        <td><?php echo html_escape($rule['name']); ?></td>
                                        <td><?php echo html_escape(_l('leadgen_control_tower_sla_stage_' . $rule['stage'])); ?></td>
                                        <td><?php echo (int) $rule['threshold_minutes']; ?></td>
                                        <td><?php echo !empty($rule['status_name']) ? html_escape($rule['status_name']) : _l('leadgen_control_tower_sla_any_status'); ?></td>
                                        <td>
                                            <?php if ($rule['active']) { ?>
                                            <span class="label" style="background:#26c281;">Yes</span>
                                            <?php } else { ?>
                                            <span class="label" style="background:#95a5a6;">No</span>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo admin_url('leadgen_control_tower/sla_rules?edit=' . (int) $rule['id']); ?>" class="btn btn-xs btn-default">
                                                <?php echo _l('leadgen_control_tower_edit'); ?>
                                            </a>
                                                            <?php /* Deletion posts with a CSRF token; it was a GET anchor. The
                                                                   confirm() is a courtesy for the operator -- the control is the
                                                                   POST verb plus the token, which a cross-site request cannot supply. */ ?>
                                                            <?php echo form_open(
                                                                    admin_url('leadgen_control_tower/sla_rule_delete/' . (int) $rule['id']),
                                                                    array('class' => 'display-inline',
                                                                          'onsubmit' => "return confirm('" . addslashes(_l('confirm_action_prompt')) . "');")
                                                                  ); ?>
                                                                <button type="submit" class="btn btn-xs btn-danger">
                                                                    <?php echo _l('leadgen_control_tower_delete'); ?>
                                                                </button>
                                                            <?php echo form_close(); ?>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
