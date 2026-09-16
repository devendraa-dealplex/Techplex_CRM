<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <h4><?php echo _l('leadgen_control_tower_missed_followups'); ?></h4>
                <hr class="hr-panel-heading" />

                <p class="text-muted"><?php echo sprintf($this->lang->line('leadgen_control_tower_sla_threshold_note'), (int) $threshold_minutes); ?></p>
                <p class="text-muted"><?php echo _l('leadgen_control_tower_missed_followups_note'); ?></p>

                <?php if (empty($leads)) { ?>
                    <div class="alert alert-info"><?php echo _l('leadgen_control_tower_missed_followups_none'); ?></div>
                <?php } else { ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo _l('leadgen_control_tower_col_lead'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_company'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_status'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_assigned'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_last_followup'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leads as $row) { ?>
                        <tr>
                            <td>
                                <a href="<?php echo admin_url('leads/index/' . (int) $row['lead_id']); ?>" target="_blank">
                                    <?php echo html_escape($row['lead_name']); ?>
                                </a>
                            </td>
                            <td><?php echo html_escape($row['company']); ?></td>
                            <td>
                                <?php if (!empty($row['status_name'])) { ?>
                                <span class="label" style="background:<?php echo html_escape($row['status_color'] ? $row['status_color'] : '#7f8c8d'); ?>;">
                                    <?php echo html_escape($row['status_name']); ?>
                                </span>
                                <?php } ?>
                            </td>
                            <td><?php echo trim((string) $row['assigned_name']) !== '' ? html_escape($row['assigned_name']) : '-'; ?></td>
                            <td class="text-muted">
                                <?php echo $row['last_followup'] ? html_escape(_dt($row['last_followup'])) : _l('leadgen_control_tower_never'); ?>
                                (<?php echo (int) $row['minutes_since_followup']; ?> min)
                            </td>
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
