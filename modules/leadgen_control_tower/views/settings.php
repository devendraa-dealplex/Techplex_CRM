<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-8">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4><?php echo _l('leadgen_control_tower_settings'); ?></h4>
                        <hr class="hr-panel-heading" />

                        <?php echo form_open(admin_url('leadgen_control_tower/settings')); ?>

                        <div class="row">
                            <div class="col-md-6">
                                <?php echo render_input('business_hours_start', 'leadgen_control_tower_business_hours_start', $business_hours_start, 'time'); ?>
                            </div>
                            <div class="col-md-6">
                                <?php echo render_input('business_hours_end', 'leadgen_control_tower_business_hours_end', $business_hours_end, 'time'); ?>
                            </div>
                        </div>

                        <?php echo render_input('retention_days_audit', 'leadgen_control_tower_retention_days_audit', $retention_days_audit, 'number'); ?>

                        <button type="submit" class="btn btn-primary"><?php echo _l('submit'); ?></button>

                        <?php echo form_close(); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>
