<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4><?php echo _l('leadgen_control_tower_audit_logs'); ?></h4>
                        <hr class="hr-panel-heading" />

                        <table class="table dt-table">
                            <thead>
                                <tr>
                                    <th><?php echo _l('leadgen_control_tower_audit_date'); ?></th>
                                    <th><?php echo _l('leadgen_control_tower_audit_staff'); ?></th>
                                    <th><?php echo _l('leadgen_control_tower_audit_action'); ?></th>
                                    <th><?php echo _l('leadgen_control_tower_audit_subject'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logs)) { ?>
                                <tr>
                                    <td colspan="4" class="text-muted"><?php echo _l('leadgen_control_tower_audit_none'); ?></td>
                                </tr>
                                <?php } ?>
                                <?php foreach ($logs as $log) { ?>
                                <tr>
                                    <td><?php echo _dt($log['date_created']); ?></td>
                                    <td><?php echo html_escape($log['staff_name']); ?></td>
                                    <td><?php echo html_escape($log['action']); ?></td>
                                    <td>
                                        <?php if (!empty($log['subject_type'])) { ?>
                                            <?php echo html_escape($log['subject_type']); ?>
                                            <?php if (!empty($log['subject_id'])) { ?>
                                                #<?php echo (int) $log['subject_id']; ?>
                                            <?php } ?>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
