<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="_buttons pull-right">
                    <a href="<?php echo admin_url('leadgen_control_tower/lead_health'); ?>" class="btn btn-default">
                        <i class="fa fa-heartbeat"></i> <?php echo _l('leadgen_control_tower_view_health_monitor'); ?>
                    </a>
                    <a href="<?php echo admin_url('leadgen_control_tower/sla_monitor'); ?>" class="btn btn-default">
                        <i class="fa fa-clock-o"></i> <?php echo _l('leadgen_control_tower_sla_monitor'); ?>
                    </a>
                    <a href="<?php echo admin_url('leadgen_control_tower/unassigned_leads'); ?>" class="btn btn-default">
                        <i class="fa fa-user-times"></i> <?php echo _l('leadgen_control_tower_unassigned_leads'); ?>
                    </a>
                    <a href="<?php echo admin_url('leadgen_control_tower/missed_followups'); ?>" class="btn btn-default">
                        <i class="fa fa-envelope-o"></i> <?php echo _l('leadgen_control_tower_missed_followups'); ?>
                    </a>
                    <a href="<?php echo admin_url('leadgen_control_tower/stale_leads'); ?>" class="btn btn-default">
                        <i class="fa fa-hourglass-half"></i> <?php echo _l('leadgen_control_tower_stale_leads'); ?>
                    </a>
                    <?php if (staff_can('manage_settings', 'leadgen_control_tower')) { ?>
                    <a href="<?php echo admin_url('leadgen_control_tower/settings'); ?>" class="btn btn-default">
                        <i class="fa fa-cog"></i> <?php echo _l('leadgen_control_tower_settings'); ?>
                    </a>
                    <?php } ?>
                </div>
                <h4><?php echo _l('leadgen_control_tower_title'); ?></h4>
                <hr class="hr-panel-heading" />
            </div>
        </div>

        <?php
        $cards = array(
            array('label' => _l('leadgen_control_tower_card_active_leads'), 'value' => $summary['active_leads'], 'color' => '#3598dc'),
            array('label' => _l('leadgen_control_tower_card_new_today'), 'value' => $summary['new_today'], 'color' => '#26c281'),
            array('label' => _l('leadgen_control_tower_card_unassigned'), 'value' => $summary['unassigned_active'], 'color' => '#e08283'),
            array('label' => _l('leadgen_control_tower_card_converted_30d'), 'value' => $summary['converted_last_30d'], 'color' => '#7f8c8d'),
            array('label' => _l('leadgen_control_tower_card_lost'), 'value' => $summary['lost_total'], 'color' => '#c0392b'),
            array('label' => _l('leadgen_control_tower_card_junk'), 'value' => $summary['junk_total'], 'color' => '#95a5a6'),
        );
        ?>
        <div class="row">
            <?php foreach ($cards as $card) { ?>
            <div class="col-md-2 col-sm-4 col-xs-6">
                <div class="panel_s" style="border-top:3px solid <?php echo html_escape($card['color']); ?>;padding:15px;text-align:center;">
                    <div style="font-size:26px;font-weight:600;"><?php echo html_escape($card['value']); ?></div>
                    <div class="text-muted" style="font-size:12px;text-transform:uppercase;"><?php echo html_escape($card['label']); ?></div>
                </div>
            </div>
            <?php } ?>
        </div>

        <div class="row" style="margin-top:10px;">
            <div class="col-md-12">
                <div class="panel_s" style="padding:15px;">
                    <h4><?php echo _l('leadgen_control_tower_health_snapshot'); ?></h4>
                    <?php
                    $health_labels = array(
                        'green' => array(_l('leadgen_control_tower_health_green'), '#26c281'),
                        'amber' => array(_l('leadgen_control_tower_health_amber'), '#e87e04'),
                        'red'   => array(_l('leadgen_control_tower_health_red'), '#c0392b'),
                        'grey'  => array(_l('leadgen_control_tower_health_grey'), '#95a5a6'),
                    );
                    ?>
                    <div class="row">
                        <?php foreach ($health_labels as $key => $meta) { ?>
                        <div class="col-md-3 col-sm-6">
                            <a href="<?php echo admin_url('leadgen_control_tower/lead_health?status=' . $key); ?>" style="text-decoration:none;">
                                <div style="border-left:4px solid <?php echo $meta[1]; ?>;padding:8px 12px;margin-bottom:10px;">
                                    <div style="font-size:20px;font-weight:600;color:<?php echo $meta[1]; ?>;">
                                        <?php echo html_escape($health_counts[$key]); ?>
                                    </div>
                                    <div class="text-muted"><?php echo html_escape($meta[0]); ?></div>
                                </div>
                            </a>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row" style="margin-top:10px;">
            <div class="col-md-6">
                <div class="panel_s" style="padding:15px;">
                    <h4><?php echo _l('leadgen_control_tower_by_status'); ?></h4>
                    <?php if (empty($by_status)) { ?>
                        <p class="text-muted"><?php echo _l('leadgen_control_tower_no_data'); ?></p>
                    <?php } else {
                        $max_status = max(array_column($by_status, 'lead_count'));
                        $max_status = $max_status > 0 ? $max_status : 1;
                        foreach ($by_status as $row) {
                            $pct = round(($row['lead_count'] / $max_status) * 100);
                            $bar_color = !empty($row['status_color']) ? $row['status_color'] : '#3598dc';
                            $name = !empty($row['status_name']) ? $row['status_name'] : _l('leadgen_control_tower_unknown_status');
                    ?>
                    <div style="margin-bottom:8px;">
                        <div style="display:flex;justify-content:space-between;font-size:12px;">
                            <span><?php echo html_escape($name); ?></span>
                            <span><?php echo html_escape($row['lead_count']); ?></span>
                        </div>
                        <div style="background:#eef1f5;border-radius:3px;height:8px;">
                            <div style="background:<?php echo html_escape($bar_color); ?>;width:<?php echo $pct; ?>%;height:8px;border-radius:3px;"></div>
                        </div>
                    </div>
                    <?php } } ?>
                </div>
            </div>
            <div class="col-md-6">
                <div class="panel_s" style="padding:15px;">
                    <h4><?php echo _l('leadgen_control_tower_by_source'); ?></h4>
                    <?php if (empty($by_source)) { ?>
                        <p class="text-muted"><?php echo _l('leadgen_control_tower_no_data'); ?></p>
                    <?php } else {
                        $max_source = max(array_column($by_source, 'lead_count'));
                        $max_source = $max_source > 0 ? $max_source : 1;
                        foreach ($by_source as $row) {
                            $pct = round(($row['lead_count'] / $max_source) * 100);
                            $name = !empty($row['source_name']) ? $row['source_name'] : _l('leadgen_control_tower_unknown_source');
                    ?>
                    <div style="margin-bottom:8px;">
                        <div style="display:flex;justify-content:space-between;font-size:12px;">
                            <span><?php echo html_escape($name); ?></span>
                            <span><?php echo html_escape($row['lead_count']); ?></span>
                        </div>
                        <div style="background:#eef1f5;border-radius:3px;height:8px;">
                            <div style="background:#3598dc;width:<?php echo $pct; ?>%;height:8px;border-radius:3px;"></div>
                        </div>
                    </div>
                    <?php } } ?>
                </div>
            </div>
        </div>

        <p class="text-muted" style="margin-top:10px;">
            <?php echo _l('leadgen_control_tower_schema_version'); ?>:
            <strong><?php echo html_escape($schema_version); ?></strong>
        </p>
    </div>
</div>

<?php init_tail(); ?>
