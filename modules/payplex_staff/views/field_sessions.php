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
                            <?php if (isset($consent) && $consent): ?>
                                <span class="label label-<?php echo html_escape($consent['class']); ?>">
                                    <?php echo html_escape($consent['label']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="alert alert-info">
                            Location is captured <strong>only</strong> between check-in and check-out, and
                            only for staff who have given consent. Raw GPS points are deleted after
                            <?php echo (int) $retention; ?> days; visit summaries below are kept permanently
                            so expense and TA-DA claims stay auditable.
                        </div>

                        <?php if ((int) $staffId > 0): ?>
                            <?php if (isset($consent) && !$consent['may_collect']): ?>
                                <div class="alert alert-warning">
                                    This staff member has not given location consent, so check-in is disabled.
                                    <a href="<?php echo admin_url('payplex_staff/staff/consent/' . (int) $staffId); ?>">Open the consent centre</a>.
                                </div>
                            <?php elseif (!empty($open)): ?>
                                <?php echo form_open(admin_url('payplex_staff/staff/field_checkout')); ?>
                                <input type="hidden" name="staff_id" value="<?php echo (int) $staffId; ?>">
                                <div class="row mbot15">
                                    <div class="col-md-3">
                                        <label>End latitude</label>
                                        <input type="text" name="lat" class="form-control" placeholder="e.g. 19.0760">
                                    </div>
                                    <div class="col-md-3">
                                        <label>End longitude</label>
                                        <input type="text" name="lng" class="form-control" placeholder="e.g. 72.8777">
                                    </div>
                                    <div class="col-md-2">
                                        <label>Accuracy (m)</label>
                                        <input type="number" name="accuracy_m" class="form-control" min="0">
                                    </div>
                                    <div class="col-md-4" style="padding-top:24px;">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            Check out of session #<?php echo (int) $open['id']; ?>
                                        </button>
                                        <span class="text-muted">open since <?php echo html_escape($open['started_at']); ?></span>
                                    </div>
                                </div>
                                <?php echo form_close(); ?>
                            <?php else: ?>
                                <?php echo form_open(admin_url('payplex_staff/staff/field_checkin')); ?>
                                <input type="hidden" name="staff_id" value="<?php echo (int) $staffId; ?>">
                                <div class="row mbot15">
                                    <div class="col-md-2">
                                        <label>Latitude</label>
                                        <input type="text" name="lat" class="form-control" placeholder="19.0760">
                                    </div>
                                    <div class="col-md-2">
                                        <label>Longitude</label>
                                        <input type="text" name="lng" class="form-control" placeholder="72.8777">
                                    </div>
                                    <div class="col-md-2">
                                        <label>Accuracy (m)</label>
                                        <input type="number" name="accuracy_m" class="form-control" min="0">
                                    </div>
                                    <div class="col-md-3">
                                        <label>Purpose</label>
                                        <input type="text" name="purpose" class="form-control" maxlength="60" placeholder="Customer visit">
                                    </div>
                                    <div class="col-md-3" style="padding-top:24px;">
                                        <button type="submit" class="btn btn-primary btn-sm">Check in</button>
                                    </div>
                                </div>
                                <?php echo form_close(); ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php echo form_open(admin_url('payplex_staff/staff/field'), array('method' => 'GET')); ?>
                            <div class="row mbot15">
                                <div class="col-md-4">
                                    <label>Jump to a staff member</label>
                                    <select class="form-control" onchange="if(this.value)window.location='<?php echo admin_url('payplex_staff/staff/field/'); ?>'+this.value;">
                                        <option value="">-- select --</option>
                                        <?php foreach ($profiles as $p): ?>
                                            <option value="<?php echo (int) $p->staff_id; ?>">
                                                <?php echo html_escape($p->full_name ?: ('Staff #' . $p->staff_id)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4" style="padding-top:24px;">
                                    <?php echo form_open(admin_url('payplex_staff/staff/sessions_sweep')); ?>
                                    <button type="submit" class="btn btn-default btn-sm">Close abandoned sessions</button>
                                    <?php echo form_close(); ?>
                                </div>
                            </div>
                            <?php echo form_close(); ?>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Staff</th>
                                        <th>Status</th>
                                        <th>Purpose</th>
                                        <th>Started</th>
                                        <th>Ended</th>
                                        <th>Duration</th>
                                        <th>Distance</th>
                                        <th>Points</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (!$sessions): ?>
                                    <tr><td colspan="10" class="text-muted"><em>No field sessions recorded.</em></td></tr>
                                <?php else: foreach ($sessions as $s): ?>
                                    <tr>
                                        <td><?php echo (int) $s['id']; ?></td>
                                        <td><?php echo (int) $s['staff_id']; ?></td>
                                        <td>
                                            <span class="label label-<?php echo Payplex_staff_geo::statusClass($s['status']); ?>">
                                                <?php echo html_escape($s['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo html_escape($s['purpose'] ?: '—'); ?></td>
                                        <td><?php echo html_escape($s['started_at']); ?></td>
                                        <td><?php echo html_escape($s['ended_at'] ?: '—'); ?></td>
                                        <td><?php echo html_escape(Payplex_staff_geo::formatDuration($s['duration_s'])); ?></td>
                                        <td><?php echo html_escape(Payplex_staff_geo::formatDistance($s['distance_m'])); ?></td>
                                        <td>
                                            <?php echo (int) $s['point_count']; ?>
                                            <?php if ((int) $s['points_purged'] === 1): ?>
                                                <span class="label label-default" title="Raw points deleted under the retention policy">purged</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo admin_url('payplex_staff/staff/field_session/' . (int) $s['id']); ?>" class="btn btn-default btn-xs">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
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
</body>
</html>
