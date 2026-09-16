<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <?php if ($can_detect) { ?>
                <div class="_buttons pull-right">
                    <?php echo form_open(admin_url('leadgen_control_tower/duplicate_detect')); ?>
                    <button type="submit" class="btn btn-default">
                        <i class="fa fa-refresh"></i> <?php echo _l('leadgen_control_tower_duplicate_detect_now'); ?>
                    </button>
                    <?php echo form_close(); ?>
                </div>
                <?php } ?>
                <h4><?php echo _l('leadgen_control_tower_duplicate_monitor'); ?></h4>
                <hr class="hr-panel-heading" />

                <p class="text-muted"><?php echo _l('leadgen_control_tower_duplicate_note'); ?></p>

                <?php
                $status_labels = array(
                    ''         => array(_l('leadgen_control_tower_health_all'), array_sum($status_counts)),
                    'pending'  => array(_l('leadgen_control_tower_duplicate_status_pending'), $status_counts['pending']),
                    'merged'   => array(_l('leadgen_control_tower_duplicate_status_merged'), $status_counts['merged']),
                    'rejected' => array(_l('leadgen_control_tower_duplicate_status_rejected'), $status_counts['rejected']),
                );
                ?>
                <div style="margin-bottom:15px;">
                    <?php foreach ($status_labels as $key => $meta) {
                        $is_active = ($status_filter === null && $key === '') || ($status_filter === $key && $key !== '');
                    ?>
                    <a href="<?php echo admin_url('leadgen_control_tower/duplicate_monitor' . ($key !== '' ? '?status=' . $key : '')); ?>"
                       class="btn btn-sm <?php echo $is_active ? 'btn-primary' : 'btn-default'; ?>">
                        <?php echo html_escape($meta[0]); ?> (<?php echo html_escape($meta[1]); ?>)
                    </a>
                    <?php } ?>
                </div>

                <?php if (empty($candidates)) { ?>
                    <div class="alert alert-info"><?php echo _l('leadgen_control_tower_duplicate_none'); ?></div>
                <?php } else { ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo _l('leadgen_control_tower_duplicate_lead_a'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_duplicate_lead_b'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_duplicate_reason'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_duplicate_confidence'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_status'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidates as $row) { ?>
                        <tr>
                            <td>
                                <a href="<?php echo admin_url('leads/index/' . (int) $row['lead_id_a']); ?>" target="_blank">
                                    <?php echo html_escape($row['lead_a_name']); ?>
                                </a>
                                <?php if ((int) $row['master_lead_id'] === (int) $row['lead_id_a']) { ?>
                                <span class="label" style="background:#26c281;"><?php echo _l('leadgen_control_tower_duplicate_master'); ?></span>
                                <?php } ?>
                                <br><span class="text-muted"><?php echo html_escape($row['lead_a_company']); ?></span>
                                <br><span class="text-muted"><?php echo html_escape($row['lead_a_phone']); ?> <?php echo html_escape($row['lead_a_email']); ?></span>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('leads/index/' . (int) $row['lead_id_b']); ?>" target="_blank">
                                    <?php echo html_escape($row['lead_b_name']); ?>
                                </a>
                                <?php if ((int) $row['master_lead_id'] === (int) $row['lead_id_b']) { ?>
                                <span class="label" style="background:#26c281;"><?php echo _l('leadgen_control_tower_duplicate_master'); ?></span>
                                <?php } ?>
                                <br><span class="text-muted"><?php echo html_escape($row['lead_b_company']); ?></span>
                                <br><span class="text-muted"><?php echo html_escape($row['lead_b_phone']); ?> <?php echo html_escape($row['lead_b_email']); ?></span>
                            </td>
                            <td>
                                <?php echo html_escape(_l('leadgen_control_tower_duplicate_reason_' . $row['match_reason'])); ?>
                            </td>
                            <td><?php echo html_escape($row['confidence_score']); ?>%</td>
                            <td>
                                <?php
                                $status_colors = array('pending' => '#f39c12', 'merged' => '#26c281', 'rejected' => '#95a5a6');
                                $color = isset($status_colors[$row['status']]) ? $status_colors[$row['status']] : '#7f8c8d';
                                ?>
                                <span class="label" style="background:<?php echo $color; ?>;">
                                    <?php echo html_escape(_l('leadgen_control_tower_duplicate_status_' . $row['status'])); ?>
                                </span>
                                <?php if ($row['status'] !== 'pending') { ?>
                                <br><span class="text-muted">
                                    <?php echo trim((string) $row['reviewed_by_name']) !== '' ? html_escape($row['reviewed_by_name']) : '-'; ?>
                                    <?php echo $row['date_reviewed'] ? html_escape(_dt($row['date_reviewed'])) : ''; ?>
                                </span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if ($can_merge && $row['status'] === 'pending') { ?>
                                <button type="button" class="btn btn-xs btn-primary" data-toggle="modal" data-target="#dupe-merge-<?php echo (int) $row['id']; ?>">
                                    <?php echo _l('leadgen_control_tower_duplicate_merge'); ?>
                                </button>
                                <?php echo form_open(admin_url('leadgen_control_tower/duplicate_resolve'), array('style' => 'display:inline-block;')); ?>
                                <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                <input type="hidden" name="decision" value="rejected">
                                <button type="submit" class="btn btn-xs btn-default">
                                    <?php echo _l('leadgen_control_tower_duplicate_reject'); ?>
                                </button>
                                <?php echo form_close(); ?>

                                <div class="modal fade" id="dupe-merge-<?php echo (int) $row['id']; ?>" tabindex="-1" role="dialog">
                                    <div class="modal-dialog" role="document">
                                        <div class="modal-content">
                                            <?php echo form_open(admin_url('leadgen_control_tower/duplicate_resolve')); ?>
                                            <div class="modal-header">
                                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                <h4 class="modal-title"><?php echo _l('leadgen_control_tower_duplicate_merge'); ?></h4>
                                            </div>
                                            <div class="modal-body">
                                                <p><?php echo _l('leadgen_control_tower_duplicate_choose_master'); ?></p>
                                                <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                <input type="hidden" name="decision" value="merged">
                                                <div class="radio radio-primary">
                                                    <input type="radio" name="master_lead_id" id="master-a-<?php echo (int) $row['id']; ?>"
                                                        value="<?php echo (int) $row['lead_id_a']; ?>" checked>
                                                    <label for="master-a-<?php echo (int) $row['id']; ?>">
                                                        <?php echo _l('leadgen_control_tower_duplicate_keep'); ?> <?php echo html_escape($row['lead_a_name']); ?>
                                                    </label>
                                                </div>
                                                <div class="radio radio-primary">
                                                    <input type="radio" name="master_lead_id" id="master-b-<?php echo (int) $row['id']; ?>"
                                                        value="<?php echo (int) $row['lead_id_b']; ?>">
                                                    <label for="master-b-<?php echo (int) $row['id']; ?>">
                                                        <?php echo _l('leadgen_control_tower_duplicate_keep'); ?> <?php echo html_escape($row['lead_b_name']); ?>
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo _l('leadgen_control_tower_cancel'); ?></button>
                                                <button type="submit" class="btn btn-primary"><?php echo _l('leadgen_control_tower_save'); ?></button>
                                            </div>
                                            <?php echo form_close(); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php } ?>
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
