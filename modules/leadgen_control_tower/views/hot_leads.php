<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <h4><?php echo _l('leadgen_control_tower_hot_leads'); ?></h4>
                <hr class="hr-panel-heading" />

                <?php if (!$hot_status_id) { ?>
                    <div class="alert alert-warning"><?php echo _l('leadgen_control_tower_hot_leads_no_status'); ?></div>
                <?php } else { ?>

                <p class="text-muted">
                    <?php echo _l('leadgen_control_tower_hot_assignment_threshold_label'); ?>
                    <?php echo (int) $assignment_threshold; ?>
                    <?php echo _l('leadgen_control_tower_minutes_suffix'); ?>
                    &nbsp;&nbsp;|&nbsp;&nbsp;
                    <?php echo _l('leadgen_control_tower_hot_first_response_threshold_label'); ?>
                    <?php echo (int) $first_response_threshold; ?>
                    <?php echo _l('leadgen_control_tower_minutes_suffix'); ?>
                </p>

                <?php if (empty($leads)) { ?>
                    <div class="alert alert-info"><?php echo _l('leadgen_control_tower_hot_leads_none'); ?></div>
                <?php } else { ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo _l('leadgen_control_tower_col_lead'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_company'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_source'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_assigned'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_hot_assignment_sla'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_hot_first_response_sla'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leads as $row) { ?>
                        <tr <?php echo ($row['assignment_breached'] || $row['first_response_breached']) ? 'style="background:#fdecea;"' : ''; ?>>
                            <td>
                                <a href="<?php echo admin_url('leads/index/' . (int) $row['lead_id']); ?>" target="_blank">
                                    <?php echo html_escape($row['lead_name']); ?>
                                </a>
                            </td>
                            <td><?php echo html_escape($row['company']); ?></td>
                            <td><?php echo !empty($row['source_name']) ? html_escape($row['source_name']) : '-'; ?></td>
                            <td><?php echo trim((string) $row['assigned_name']) !== '' ? html_escape($row['assigned_name']) : '-'; ?></td>
                            <td>
                                <?php if (empty($row['assigned'])) { ?>
                                    <?php if ($row['assignment_breached']) { ?>
                                    <span class="label" style="background:#c0392b;"><?php echo _l('leadgen_control_tower_breached'); ?></span>
                                    <?php } else { ?>
                                    <span class="label" style="background:#f39c12;"><?php echo (int) $row['minutes_since_added']; ?> <?php echo _l('leadgen_control_tower_minutes_suffix'); ?></span>
                                    <?php } ?>
                                <?php } else { ?>
                                    <span class="label" style="background:#26c281;"><?php echo _l('leadgen_control_tower_ok'); ?></span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if (empty($row['assigned'])) { ?>
                                    <span class="text-muted"><?php echo _l('leadgen_control_tower_not_applicable'); ?></span>
                                <?php } elseif ($row['contacted']) { ?>
                                    <span class="label" style="background:#26c281;"><?php echo _l('leadgen_control_tower_ok'); ?></span>
                                <?php } elseif ($row['first_response_breached']) { ?>
                                    <span class="label" style="background:#c0392b;"><?php echo _l('leadgen_control_tower_breached'); ?></span>
                                <?php } else { ?>
                                    <span class="label" style="background:#f39c12;"><?php echo (int) $row['minutes_since_assigned']; ?> <?php echo _l('leadgen_control_tower_minutes_suffix'); ?></span>
                                <?php } ?>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
                <?php } ?>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
