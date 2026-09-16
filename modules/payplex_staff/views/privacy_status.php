<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>
                        <p class="text-muted">
                            Policy v<?php echo html_escape($policy); ?> &middot;
                            explicit opt-in, revocable &middot;
                            capture only between check-in and check-out &middot;
                            raw points deleted after <?php echo (int) $retention; ?> days.
                        </p>

                        <div class="row mtop15">
                            <div class="col-md-3">
                                <div class="panel_s"><div class="panel-body text-center">
                                    <h3 class="no-margin"><?php echo (int) $stats['points_total']; ?></h3>
                                    <span class="text-muted">raw points held</span>
                                </div></div>
                            </div>
                            <div class="col-md-3">
                                <div class="panel_s"><div class="panel-body text-center">
                                    <h3 class="no-margin <?php echo $stats['points_due'] > 0 ? 'text-danger' : ''; ?>">
                                        <?php echo (int) $stats['points_due']; ?>
                                    </h3>
                                    <span class="text-muted">due for deletion</span>
                                </div></div>
                            </div>
                            <div class="col-md-3">
                                <div class="panel_s"><div class="panel-body text-center">
                                    <h3 class="no-margin"><?php echo (int) $stats['consent_active']; ?></h3>
                                    <span class="text-muted">staff with active consent</span>
                                </div></div>
                            </div>
                            <div class="col-md-3">
                                <div class="panel_s"><div class="panel-body text-center">
                                    <h3 class="no-margin"><?php echo (int) $stats['sessions_open']; ?></h3>
                                    <span class="text-muted">sessions open now</span>
                                </div></div>
                            </div>
                        </div>

                        <table class="table table-condensed">
                            <tbody>
                                <tr><td class="bold">Retention window</td><td><?php echo (int) $stats['retention_days']; ?> days</td></tr>
                                <tr><td class="bold">Current cutoff</td><td>points captured before <?php echo html_escape($stats['cutoff']); ?> are deleted</td></tr>
                                <tr><td class="bold">Oldest point held</td><td><?php echo html_escape($stats['oldest_point'] ?: '—'); ?></td></tr>
                                <tr><td class="bold">Sessions total</td><td><?php echo (int) $stats['sessions_total']; ?> (<?php echo (int) $stats['sessions_purged']; ?> with points already purged)</td></tr>
                            </tbody>
                        </table>

                        <?php echo form_open(admin_url('payplex_staff/staff/purge_run')); ?>
                        <button type="submit" class="btn btn-danger">
                            Run retention purge now
                        </button>
                        <span class="text-muted mleft10">
                            Deletes raw points older than <?php echo (int) $stats['retention_days']; ?> days.
                            Visit summaries (start, end, duration, distance) are preserved.
                        </span>
                        <?php echo form_close(); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="bold no-mtop">Privacy operations log</h5>
                        <p class="text-muted">Immutable. Every purge and every access to a person's location record is recorded here.</p>
                        <div class="table-responsive">
                            <table class="table table-condensed table-striped">
                                <thead>
                                    <tr><th>When</th><th>Event</th><th>Staff</th><th>Actor</th><th>Rows</th><th>Cutoff</th><th>Message</th></tr>
                                </thead>
                                <tbody>
                                <?php if (!$log): ?>
                                    <tr><td colspan="7" class="text-muted"><em>No entries yet.</em></td></tr>
                                <?php else: foreach ($log as $e): ?>
                                    <tr>
                                        <td><?php echo html_escape($e->occurred_at); ?></td>
                                        <td><span class="label label-info"><?php echo html_escape($e->event_type); ?></span></td>
                                        <td><?php echo $e->staff_id ? ('#' . (int) $e->staff_id) : '—'; ?></td>
                                        <td>#<?php echo (int) $e->actor_id; ?></td>
                                        <td><?php echo (int) $e->affected_rows; ?></td>
                                        <td><?php echo html_escape($e->cutoff_date ?: '—'); ?></td>
                                        <td><?php echo html_escape($e->message); ?></td>
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
