<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <h4><?php echo _l('leadgen_control_tower_unassigned_leads'); ?></h4>
                <hr class="hr-panel-heading" />

                <p class="text-muted"><?php echo sprintf($this->lang->line('leadgen_control_tower_sla_threshold_note'), (int) $threshold_minutes); ?></p>

                <?php if (empty($leads)) { ?>
                    <div class="alert alert-info"><?php echo _l('leadgen_control_tower_unassigned_none'); ?></div>
                <?php } else { ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo _l('leadgen_control_tower_col_lead'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_company'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_status'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_source'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_waiting_since'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leads as $row) { ?>
                        <tr <?php echo $row['breached'] ? 'style="background:#fdecea;"' : ''; ?>>
                            <td>
                                <a href="<?php echo admin_url('leads/index/' . (int) $row['lead_id']); ?>" target="_blank">
                                    <?php echo html_escape($row['lead_name']); ?>
                                </a>
                                <?php if ($row['breached']) { ?>
                                <span class="label" style="background:#c0392b;">SLA</span>
                                <?php } ?>
                            </td>
                            <td><?php echo html_escape($row['company']); ?></td>
                            <td>
                                <?php if (!empty($row['status_name'])) { ?>
                                <span class="label" style="background:<?php echo html_escape($row['status_color'] ? $row['status_color'] : '#7f8c8d'); ?>;">
                                    <?php echo html_escape($row['status_name']); ?>
                                </span>
                                <?php } ?>
                            </td>
                            <td><?php echo !empty($row['source_name']) ? html_escape($row['source_name']) : '-'; ?></td>
                            <td class="text-muted"><?php echo html_escape(_dt($row['dateadded'])); ?> (<?php echo (int) $row['minutes_waiting']; ?> min)</td>
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
