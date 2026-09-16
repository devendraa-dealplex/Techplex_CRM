<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <?php $o = $breakdown['overall']; ?>

        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <div class="tw-flex tw-justify-between tw-items-center tw-mb-4">
                    <h4 class="no-margin">
                        Target #<?php echo (int) $target['id']; ?>
                        <?php if ((int) ($target['version'] ?? 1) > 1): ?>
                            <small>v<?php echo (int) $target['version']; ?></small>
                        <?php endif; ?>
                    </h4>
                    <span class="label label-<?php echo Target_workflow::statusClass($target['status']); ?>">
                        <?php echo html_escape($target['status']); ?>
                    </span>
                </div>

                <p class="text-muted">
                    Staff #<?php echo (int) $target['staff_id']; ?> &middot;
                    <?php echo html_escape($target['period_start']); ?> to <?php echo html_escape($target['period_end']); ?>
                    &middot; <?php echo round(((float) $breakdown['progress']) * 100); ?>% through the period
                    &middot; refreshed <?php echo html_escape($breakdown['refreshed']); ?>
                    <?php if ($is_own): ?>
                        &middot; <span class="label label-warning">this is your target</span>
                    <?php endif; ?>
                </p>

                <div class="alert alert-<?php echo $o['complete'] ? 'success' : 'warning'; ?>">
                    <h4 class="no-mtop bold"><?php echo number_format((float) $o['overall_pct'], 2); ?>%</h4>
                    <?php echo html_escape($breakdown['summary']); ?>
                    <?php if (!$o['complete']): ?>
                        <div class="mtop10">
                            <strong>Unmeasured weighting:</strong>
                            <ul class="no-mbot">
                            <?php foreach ($o['unmeasured'] as $u): ?>
                                <li><?php echo html_escape($u['kpi_key']); ?>
                                    (<?php echo (float) $u['weight']; ?>%) —
                                    <?php echo html_escape($u['reason']); ?></li>
                            <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>

                <p class="text-muted">
                    Over-achievement is <?php echo $breakdown['cap_over'] ? 'capped at 100% per KPI' : 'NOT capped'; ?>
                    for scoring. True achievement is always shown in full below.
                </p>
            </div></div>
        </div></div>

        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <h5 class="bold no-mtop">KPI breakdown</h5>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead><tr>
                            <th>KPI</th><th>Source</th><th>Target</th><th>Actual</th>
                            <th>Achievement</th><th>Scored</th><th>Weight</th><th>Weighted</th>
                            <th>Remaining</th><th>Pace</th><th>Forecast</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($breakdown['metrics'] as $m): ?>
                            <tr>
                                <td>
                                    <strong><?php echo html_escape($m['label']); ?></strong>
                                    <?php if ($m['measurable'] && !$m['qualified']): ?>
                                        <br><span class="label label-warning">below threshold</span>
                                    <?php endif; ?>
                                    <?php if (!empty($m['stretch_met'])): ?>
                                        <br><span class="label label-success">stretch met</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="label label-<?php echo Kpi_catalog::statusClass($m['kpi_status']); ?>">
                                    <?php echo html_escape($m['kpi_status']); ?></span></td>
                                <td><?php echo $m['target_value'] === null ? '—' : number_format((float) $m['target_value'], 2); ?></td>
                                <td><?php echo $m['achieved'] === null ? '—' : number_format((float) $m['achieved'], 2); ?>
                                    <?php if ((int) $m['record_count'] > 0): ?>
                                        <br><small class="text-muted"><?php echo (int) $m['record_count']; ?> record(s)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $m['achievement_pct'] === null
                                        ? '<span class="text-muted">not measurable</span>'
                                        : number_format((float) $m['achievement_pct'], 2) . '%'; ?></td>
                                <td><?php echo $m['capped_pct'] === null ? '—' : number_format((float) $m['capped_pct'], 2) . '%'; ?></td>
                                <td><?php echo (float) $m['weight']; ?>%</td>
                                <td class="bold"><?php echo $m['weighted'] === null ? '—' : number_format((float) $m['weighted'], 2); ?></td>
                                <td><?php echo $m['remaining'] === null ? '—' : number_format((float) $m['remaining'], 2); ?></td>
                                <td><span class="label label-<?php echo Kpi_weighting::paceClass($m['pace']); ?>">
                                    <?php echo html_escape(str_replace('_', ' ', $m['pace'])); ?></span></td>
                                <td><?php echo $m['forecast'] === null ? '—' : number_format((float) $m['forecast'], 2); ?></td>
                            </tr>
                            <?php if (!$m['measurable'] || $m['reason'] !== ''): ?>
                                <tr><td colspan="11" style="background:#fffbf0;">
                                    <small><strong>Why:</strong> <?php echo html_escape($m['reason']); ?></small>
                                </td></tr>
                            <?php endif; ?>
                            <?php if (!empty($m['definition'])): ?>
                                <tr><td colspan="11" style="background:#fafafa;">
                                    <small><strong>How this is counted:</strong><br>
                                    <?php echo nl2br(html_escape($m['definition'])); ?></small>
                                </td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div></div>
        </div></div>

        <div class="row">
            <div class="col-md-6">
                <div class="panel_s"><div class="panel-body">
                    <h5 class="bold no-mtop">Lifecycle</h5>
                    <?php if ($is_own): ?>
                        <div class="alert alert-info">
                            This target is set for you, so you cannot change its status.
                        </div>
                    <?php elseif (!$can_manage): ?>
                        <p class="text-muted"><em>You do not have permission to change this target.</em></p>
                    <?php else: ?>
                        <?php foreach (Target_workflow::transitions()[$target['status']] ?? array() as $to): ?>
                            <?php echo form_open(admin_url('sales_targets/transition'), array('class' => 'form-inline mbot10')); ?>
                            <input type="hidden" name="id" value="<?php echo (int) $target['id']; ?>">
                            <input type="hidden" name="to" value="<?php echo html_escape($to); ?>">
                            <?php if (in_array($to, array('Rejected', 'Draft', 'Active'), true)): ?>
                                <input type="text" name="reason" class="form-control input-sm" placeholder="reason">
                            <?php endif; ?>
                            <button type="submit" class="btn btn-sm btn-<?php echo $to === 'Rejected' ? 'danger' : 'info'; ?>">
                                <?php echo html_escape($to); ?>
                            </button>
                            <?php echo form_close(); ?>
                        <?php endforeach; ?>
                        <p class="text-muted mtop15">
                            Approval requires weights totalling 100%, and cannot be done by whoever
                            created the target or by anyone it is set for.
                        </p>
                    <?php endif; ?>
                </div></div>
            </div>

            <div class="col-md-6">
                <div class="panel_s"><div class="panel-body">
                    <h5 class="bold no-mtop">History</h5>
                    <table class="table table-condensed">
                        <tbody>
                        <?php if (!$audit): ?>
                            <tr><td class="text-muted"><em>No history.</em></td></tr>
                        <?php else: foreach ($audit as $a): ?>
                            <tr><td>
                                <small class="text-muted"><?php echo html_escape($a->date_created); ?></small>
                                <span class="label label-default"><?php echo html_escape($a->action); ?></span>
                                by #<?php echo (int) $a->staff_id; ?>
                                <?php if ($a->description): ?>
                                    <br><small><?php echo html_escape($a->description); ?></small>
                                <?php endif; ?>
                            </td></tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div></div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
