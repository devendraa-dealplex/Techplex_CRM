<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <?php if ($can_recalculate) { ?>
                <div class="_buttons pull-right">
                    <?php echo form_open(admin_url('leadgen_control_tower/lead_health_recalculate')); ?>
                    <button type="submit" class="btn btn-default">
                        <i class="fa fa-refresh"></i> <?php echo _l('leadgen_control_tower_recalculate_now'); ?>
                    </button>
                    <?php echo form_close(); ?>
                </div>
                <?php } ?>
                <h4><?php echo _l('leadgen_control_tower_lead_health'); ?></h4>
                <hr class="hr-panel-heading" />

                <p class="text-muted"><?php echo _l('leadgen_control_tower_health_note'); ?></p>

                <?php
                $filters = array(
                    ''      => array(_l('leadgen_control_tower_health_all'), '#7f8c8d', array_sum($health_counts)),
                    'red'   => array(_l('leadgen_control_tower_health_red'), '#c0392b', $health_counts['red']),
                    'amber' => array(_l('leadgen_control_tower_health_amber'), '#e87e04', $health_counts['amber']),
                    'green' => array(_l('leadgen_control_tower_health_green'), '#26c281', $health_counts['green']),
                    'grey'  => array(_l('leadgen_control_tower_health_grey'), '#95a5a6', $health_counts['grey']),
                );
                ?>
                <div style="margin-bottom:15px;">
                    <?php foreach ($filters as $key => $meta) {
                        $is_active = ($status_filter === null && $key === '') || ($status_filter === $key && $key !== '');
                    ?>
                    <a href="<?php echo admin_url('leadgen_control_tower/lead_health' . ($key !== '' ? '?status=' . $key : '')); ?>"
                       class="btn btn-sm <?php echo $is_active ? 'btn-default' : 'btn-link'; ?>"
                       style="<?php echo $is_active ? 'border:1px solid ' . $meta[1] . ';color:' . $meta[1] . ';' : 'color:' . $meta[1] . ';'; ?>">
                        <?php echo html_escape($meta[0]); ?> (<?php echo html_escape($meta[2]); ?>)
                    </a>
                    <?php } ?>
                </div>

                <?php if (empty($leads)) { ?>
                    <div class="alert alert-info"><?php echo _l('leadgen_control_tower_health_none'); ?></div>
                <?php } else { ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo _l('leadgen_control_tower_col_lead'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_company'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_status'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_assigned'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_health'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_reason'); ?></th>
                            <th><?php echo _l('leadgen_control_tower_col_calculated'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $badge_colors = array('green' => '#26c281', 'amber' => '#e87e04', 'red' => '#c0392b', 'grey' => '#95a5a6');
                        $badge_labels = array(
                            'green' => _l('leadgen_control_tower_health_green'),
                            'amber' => _l('leadgen_control_tower_health_amber'),
                            'red'   => _l('leadgen_control_tower_health_red'),
                            'grey'  => _l('leadgen_control_tower_health_grey'),
                        );
                        foreach ($leads as $row) {
                            $color = isset($badge_colors[$row['health_status']]) ? $badge_colors[$row['health_status']] : '#7f8c8d';
                            $label = isset($badge_labels[$row['health_status']]) ? $badge_labels[$row['health_status']] : $row['health_status'];
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo admin_url('leads/index/' . $row['lead_id']); ?>" target="_blank">
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
                            <td>
                                <span class="label" style="background:<?php echo $color; ?>;">
                                    <?php echo html_escape($label); ?>
                                </span>
                            </td>
                            <td class="text-muted"><?php echo html_escape($row['reason_text']); ?></td>
                            <td class="text-muted"><?php echo html_escape(_dt($row['date_calculated'])); ?></td>
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
