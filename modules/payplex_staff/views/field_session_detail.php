<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-4">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-justify-between tw-items-center tw-mb-4">
                            <h4 class="no-margin"><?php echo html_escape($title); ?></h4>
                            <span class="label label-<?php echo Payplex_staff_geo::statusClass($session['status']); ?>">
                                <?php echo html_escape($session['status']); ?>
                            </span>
                        </div>

                        <table class="table table-condensed no-mbot">
                            <tbody>
                                <tr><td class="bold">Staff</td><td>
                                    <?php echo html_escape(isset($profile->full_name) ? $profile->full_name : ('#' . (int) $session['staff_id'])); ?>
                                </td></tr>
                                <tr><td class="bold">Purpose</td><td><?php echo html_escape($session['purpose'] ?: '—'); ?></td></tr>
                                <tr><td class="bold">Started</td><td><?php echo html_escape($session['started_at']); ?></td></tr>
                                <tr><td class="bold">Ended</td><td><?php echo html_escape($session['ended_at'] ?: '—'); ?></td></tr>
                                <tr><td class="bold">Duration</td><td><?php echo html_escape(Payplex_staff_geo::formatDuration($session['duration_s'])); ?></td></tr>
                                <tr><td class="bold">Distance</td><td><?php echo html_escape(Payplex_staff_geo::formatDistance($session['distance_m'])); ?></td></tr>
                                <tr><td class="bold">Points</td><td><?php echo (int) $session['point_count']; ?></td></tr>
                                <?php if (isset($trust) && $trust['verdict'] !== 'unrecorded'): ?>
                                <tr><td class="bold">Legs counted</td><td>
                                    <?php echo (int) $trust['legs']; ?>
                                    <?php if ((int) $trust['rejected'] > 0): ?>
                                        <span class="text-danger">(<?php echo (int) $trust['rejected']; ?> rejected as impossible)</span>
                                    <?php endif; ?>
                                    <?php if ((int) $trust['jitter'] > 0): ?>
                                        <span class="text-muted">(<?php echo (int) $trust['jitter']; ?> stationary)</span>
                                    <?php endif; ?>
                                </td></tr>
                                <?php endif; ?>
                                <tr><td class="bold">Consent ref</td><td>#<?php echo (int) $session['consent_id']; ?></td></tr>
                            </tbody>
                        </table>

                        <?php
                        /*
                         * What the distance is worth, in a sentence.
                         *
                         * The frozen summary cannot be recomputed — that freeze is
                         * what stops a retention purge from moving a figure a claim
                         * was based on — so the only honest thing to do about a
                         * distance the current rules disagree with is to say so
                         * next to it.
                         */
                        ?>
                        <?php if (isset($trust) && $trust['verdict'] !== 'clean'): ?>
                        <div class="alert alert-<?php echo Payplex_staff_geo::trustClass($trust['verdict']); ?> mtop15">
                            <?php echo html_escape(Payplex_staff_geo::trustMessage($trust)); ?>
                        </div>
                        <?php endif; ?>

                        <?php if ((int) $session['points_purged'] !== 1 && (string) $session['status'] !== 'open'
                                  && payplex_staff_can('field_tracking')): ?>
                        <a href="<?php echo admin_url('payplex_staff/staff/field_session_reverify/' . (int) $session['id']); ?>"
                           class="btn btn-default btn-sm">Re-check against current rules</a>
                        <p class="text-muted small mtop10 no-mbot">
                            Compares the stored distance with what today's plausibility rules
                            give for the same points. Reports only — the stored figure is never
                            changed.
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="bold no-mtop">Location trail</h5>

                        <?php if ((int) $session['points_purged'] === 1): ?>
                            <div class="alert alert-default">
                                The raw GPS points for this visit were deleted on
                                <strong><?php echo html_escape($session['purged_at']); ?></strong>
                                under the <?php echo (int) $retention; ?>-day retention policy.
                                The summary on the left was computed before deletion and remains valid
                                for expense and TA-DA purposes.
                            </div>
                        <?php elseif (!$points): ?>
                            <p class="text-muted"><em>No points recorded for this session.</em></p>
                        <?php else: ?>
                            <p class="text-muted">
                                <?php echo count($points); ?> point(s).
                                Oldest is deleted in
                                <?php echo (int) Payplex_staff_geo::daysUntilPurge($points[0]['captured_at']); ?> day(s).
                            </p>
                            <div class="table-responsive">
                                <table class="table table-condensed table-striped">
                                    <thead>
                                        <tr>
                                            <th>Captured</th>
                                            <th>Latitude</th>
                                            <th>Longitude</th>
                                            <th>Accuracy</th>
                                            <th>Source</th>
                                            <th>Deleted in</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($points as $p): ?>
                                        <tr>
                                            <td><?php echo html_escape($p['captured_at']); ?></td>
                                            <td><?php echo html_escape($p['lat']); ?></td>
                                            <td><?php echo html_escape($p['lng']); ?></td>
                                            <td><?php echo $p['accuracy_m'] === null ? '—' : ((int) $p['accuracy_m'] . ' m'); ?></td>
                                            <td><?php echo html_escape($p['source']); ?></td>
                                            <td><?php echo (int) Payplex_staff_geo::daysUntilPurge($p['captured_at']); ?>d</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <a href="<?php echo admin_url('payplex_staff/staff/field/' . (int) $session['staff_id']); ?>" class="btn btn-default btn-sm">
                            Back to sessions
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
