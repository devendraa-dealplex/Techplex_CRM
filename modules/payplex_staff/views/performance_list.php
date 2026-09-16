<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-margin mbot15"><?php echo html_escape($title); ?></h4>

                        <!-- Filter -->
                        <?php echo form_open(admin_url('payplex_staff/staff/performance'), array('method' => 'GET')); ?>
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

                        <div class="table-responsive">
                            <table class="table table-striped table-bordered dt-table" data-order='[[3,"desc"]]'>
                                <thead>
                                    <tr>
                                        <th>Staff ID</th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Score</th>
                                        <th>Rating</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($board as $row): $p = $row['profile']; $perf = $row['perf']; ?>
                                    <tr>
                                        <td><?php echo (int) $p->staff_id; ?></td>
                                        <td><?php echo html_escape($p->full_name ?: '-'); ?></td>
                                        <td><?php echo html_escape(Payplex_staff_types::label($p->employment_type)); ?></td>
                                        <td class="bold"><?php echo number_format((float) $perf['score'], 1); ?></td>
                                        <td>
                                            <span class="label label-<?php echo Payplex_staff_kpi::ratingClass($perf['rating']); ?>">
                                                <?php echo html_escape(str_replace('_', ' ', ucfirst($perf['rating']))); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="label label-<?php echo Payplex_staff_lifecycle::statusClass($p->status); ?>">
                                                <?php echo html_escape(ucfirst($p->status)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="<?php echo admin_url('payplex_staff/staff/performance/' . (int) $p->staff_id); ?>" class="btn btn-default btn-xs" title="Detail"><i class="fa fa-bar-chart"></i></a>
                                            <a href="<?php echo admin_url('payplex_staff/staff/activity/' . (int) $p->staff_id); ?>" class="btn btn-default btn-xs" title="Activity"><i class="fa fa-list"></i></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
