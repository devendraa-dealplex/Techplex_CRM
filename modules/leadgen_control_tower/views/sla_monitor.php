<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <?php if ($can_recalculate) { ?>
                <div class="_buttons pull-right">
                    <?php echo form_open(admin_url('leadgen_control_tower/sla_recalculate')); ?>
                    <button type="submit" class="btn btn-default">
                        <i class="fa fa-refresh"></i> <?php echo _l('leadgen_control_tower_recalculate_now'); ?>
                    </button>
                    <?php echo form_close(); ?>
                </div>
                <?php } ?>
                <h4><?php echo _l('leadgen_control_tower_sla_monitor'); ?></h4>
                <hr class="hr-panel-heading" />

                <?php
                $stage_labels = array(
                    ''                => array(_l('leadgen_control_tower_health_all'), array_sum($stage_counts)),
                    'assignment'      => array(_l('leadgen_control_tower_sla_stage_assignment'), $stage_counts['assignment']),
                    'first_response'  => array(_l('leadgen_control_tower_sla_stage_first_response'), $stage_counts['first_response']),
                    'followup'        => array(_l('leadgen_control_tower_sla_stage_followup'), $stage_counts['followup']),
                    'stale'           => array(_l('leadgen_control_tower_sla_stage_stale'), $stage_counts['stale']),
                );
                ?>
                <div style="margin-bottom:15px;">
                    <?php foreach ($stage_labels as $key => $meta) {
                        $is_active = ($stage_filter === null && $key === '') || ($stage_filter === $key && $key !== '');
                    ?>
                    <a href="<?php echo admin_url('leadgen_control_tower/sla_monitor' . ($key !== '' ? '?stage=' . $key : '')); ?>"
                       class="btn btn-sm <?php echo $is_active ? 'btn-primary' : 'btn-default'; ?>">
                        <?php echo html_escape($meta[0]); ?> (<?php echo html_escape($meta[1]); ?>)
                    </a>
                    <?php } ?>
                </div>

                <?php if (empty($breaches)) { ?>
                    <div class="alert alert-info"><?php echo _l('leadgen_control_tower_sla_no_breaches'); ?></div>
                <?php } else { ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo _l('leadgen_control_tower_col_lead'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_rule'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_sla_stage'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_status'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_assigned'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_started'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_due'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($breaches as $row) { ?>
                        <tr>
                            <td>
                                <a href="<?php echo admin_url('leads/index/' . (int) $row['lead_id']); ?>" target="_blank">
                                    <?php echo html_escape($row['lead_name']); ?>
                                </a>
                            </td>
                            <td><?php echo html_escape($row['rule_name']); ?></td>
                            <td><?php echo html_escape(_l('leadgen_control_tower_sla_stage_' . $row['stage'])); ?></td>
                            <td>
                                <?php if (!empty($row['status_name'])) { ?>
                                <span class="label" style="background:<?php echo html_escape($row['status_color'] ? $row['status_color'] : '#7f8c8d'); ?>;">
                                    <?php echo html_escape($row['status_name']); ?>
                                </span>
                                <?php } ?>
                            </td>
                            <td><?php echo trim((string) $row['assigned_name']) !== '' ? html_escape($row['assigned_name']) : '-'; ?></td>
                            <td class="text-muted"><?php echo html_escape(_dt($row['date_started'])); ?></td>
                            <td class="text-danger"><?php echo html_escape(_dt($row['date_due'])); ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
