<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-justify-between tw-items-center tw-mb-4">
                            <h4 class="no-margin"><?php echo html_escape($title); ?></h4>
                            <div>
                                <a href="<?php echo admin_url('payplex_staff/staff/activity/' . (int) $staffId); ?>" class="btn btn-default btn-sm">Activity Timeline</a>
                                <a href="<?php echo admin_url('payplex_staff/staff/performance'); ?>" class="btn btn-default btn-sm">Back to Board</a>
                            </div>
                        </div>

                        <!-- Filter -->
                        <?php echo form_open(admin_url('payplex_staff/staff/performance/' . (int) $staffId), array('method' => 'GET')); ?>
                        <div class="row mbot15">
                            <div class="col-md-3">
                                <label>From</label>
                                <input type="date" name="from" class="form-control" value="<?php echo html_escape($from); ?>">
                            </div>
                            <div class="col-md-3">
                                <label>To</label>
                                <input type="date" name="to" class="form-control" value="<?php echo html_escape($to); ?>">
                            </div>
                            <div class="col-md-3" style="padding-top:24px;">
                                <button type="submit" class="btn btn-info btn-sm">Filter</button>
                            </div>
                        </div>
                        <?php echo form_close(); ?>

                        <?php if (isset($profile) && $profile): ?>
                        <div class="row mbot15">
                            <div class="col-md-6">
                                <table class="table table-condensed">
                                    <tr><th style="width:150px;">Name</th><td><?php echo html_escape($profile->full_name ?: '-'); ?></td></tr>
                                    <tr><th>Type</th><td><?php echo html_escape(Payplex_staff_types::label($profile->employment_type)); ?></td></tr>
                                    <tr><th>Status</th><td><span class="label label-<?php echo Payplex_staff_lifecycle::statusClass($profile->status); ?>"><?php echo html_escape(ucfirst($profile->status)); ?></span></td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <div class="well text-center">
                                    <h1 class="bold no-margin text-<?php echo Payplex_staff_kpi::ratingClass($perf['rating']); ?>"><?php echo number_format((float) $perf['score'], 1); ?></h1>
                                    <span class="label label-lg label-<?php echo Payplex_staff_kpi::ratingClass($perf['rating']); ?>">
                                        <?php echo html_escape(str_replace('_', ' ', ucfirst($perf['rating']))); ?>
                                    </span>
                                    <p class="text-muted mtop5">Role: <?php echo html_escape($perf['role']); ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Score components -->
                        <?php if (!empty($perf['components'])): ?>
                        <h4 class="mbot15">Score Components</h4>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead>
                                    <tr>
                                        <th>Metric</th>
                                        <th>Value</th>
                                        <th>Attainment</th>
                                        <th>Weight</th>
                                        <th>Contribution</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $catalog = Payplex_staff_kpi::metricCatalog(); ?>
                                    <?php foreach ($perf['components'] as $metric => $c): ?>
                                    <tr>
                                        <td><?php echo html_escape(isset($catalog[$metric]) ? $catalog[$metric] : $metric); ?></td>
                                        <td><?php echo number_format((float) $c['value'], 2); ?></td>
                                        <td>
                                            <div class="progress" style="margin:0;height:20px;">
                                                <div class="progress-bar progress-bar-<?php echo $c['attainment'] >= 0.7 ? 'success' : ($c['attainment'] >= 0.4 ? 'warning' : 'danger'); ?>"
                                                    style="width:<?php echo min(100, round($c['attainment'] * 100)); ?>%;">
                                                    <?php echo round($c['attainment'] * 100); ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo number_format((float) $c['weight'], 3); ?></td>
                                        <td><?php echo number_format((float) $c['attainment'] * (float) $c['weight'] * 100, 1); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <!-- Penalty counts -->
                        <?php if (!empty($perf['penalty_counts']) && array_sum($perf['penalty_counts']) > 0): ?>
                        <h4 class="mbot15">Penalty Counts</h4>
                        <div class="row">
                            <?php foreach ($perf['penalty_counts'] as $k => $v): if ($v <= 0) continue; ?>
                            <div class="col-md-3">
                                <div class="panel_s">
                                    <div class="panel-body text-center">
                                        <h3 class="bold text-danger"><?php echo (int) $v; ?></h3>
                                        <p class="text-muted no-margin"><?php echo html_escape(str_replace('_', ' ', ucfirst($k))); ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Activity summary -->
                        <?php if (!empty($summary)): ?>
                        <h4 class="mbot15">Activity Summary</h4>
                        <div class="row">
                            <div class="col-md-3"><strong>Total Events:</strong> <?php echo (int) $summary['total']; ?></div>
                            <div class="col-md-3"><strong>Verified Outcomes:</strong> <span class="text-success"><?php echo (int) $summary['verified_outcomes']; ?></span></div>
                            <div class="col-md-3"><strong>Raw Activity:</strong> <?php echo (int) $summary['raw_activity']; ?></div>
                        </div>
                        <div class="row mtop10">
                            <?php foreach ($summary['by_category'] as $cat => $cnt): ?>
                            <div class="col-md-2 mbot5">
                                <span class="label label-<?php echo Payplex_staff_activity::categoryClass($cat); ?>"><?php echo html_escape($cat); ?>: <?php echo (int) $cnt; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Store snapshot button -->
                        <hr>
                        <?php echo form_open(admin_url('payplex_staff/staff/performance_compute')); ?>
                        <input type="hidden" name="staff_id" value="<?php echo (int) $staffId; ?>">
                        <input type="hidden" name="from" value="<?php echo html_escape($from); ?>">
                        <input type="hidden" name="to" value="<?php echo html_escape($to); ?>">
                        <button type="submit" class="btn btn-info btn-sm"><i class="fa fa-save"></i> Store Performance Snapshot</button>
                        <?php echo form_close(); ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
