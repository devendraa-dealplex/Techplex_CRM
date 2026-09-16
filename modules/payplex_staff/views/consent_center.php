<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-8">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-justify-between tw-items-center tw-mb-4">
                            <h4 class="no-margin"><?php echo html_escape($title); ?></h4>
                            <span class="label label-<?php echo html_escape($status['class']); ?>">
                                <?php echo html_escape($status['label']); ?>
                            </span>
                        </div>

                        <?php if (isset($profile) && $profile): ?>
                            <p class="text-muted">
                                <?php echo html_escape($profile->full_name); ?>
                                <?php if ($profile->employee_code): ?>
                                    &middot; <?php echo html_escape($profile->employee_code); ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>

                        <div class="alert alert-<?php echo $status['may_collect'] ? 'success' : 'info'; ?>">
                            <?php if ($status['state'] === 'granted'): ?>
                                <strong>Consent is active.</strong>
                                Location is captured only between a field check-in and check-out.
                                Given <?php echo html_escape($status['since']); ?>
                                (policy v<?php echo html_escape($status['policy']); ?>).
                            <?php elseif ($status['state'] === 'withdrawn'): ?>
                                <strong>Consent was withdrawn</strong>
                                on <?php echo html_escape($status['since']); ?>.
                                No location is being collected. Visits recorded before that date are kept
                                until the <?php echo (int) $retention; ?>-day retention limit removes them.
                            <?php else: ?>
                                <strong>No consent on record.</strong>
                                Field location tracking is switched off for this staff member and cannot
                                be started until they opt in themselves.
                            <?php endif; ?>
                        </div>

                        <?php
                        /*
                         * The abstract promise, made concrete.
                         *
                         * The notice above already says visits recorded before a
                         * withdrawal are kept until the retention limit removes
                         * them, which is true and is more than most consent
                         * screens manage. What it cannot tell you is the part a
                         * person actually wants to know after withdrawing: how
                         * much is still held about me, and when does it go. Those
                         * are two queries, and leaving them unanswered invites
                         * the reading that withdrawal erased everything.
                         */
                        ?>
                        <?php if (!empty($held) && (int) $held['points'] > 0): ?>
                        <div class="alert alert-default">
                            <strong><?php echo (int) $held['points']; ?> location point<?php echo (int) $held['points'] === 1 ? '' : 's'; ?></strong>
                            from <?php echo (int) $held['sessions']; ?> field
                            session<?php echo (int) $held['sessions'] === 1 ? '' : 's'; ?>
                            <?php echo $status['may_collect'] ? 'are held' : 'are still held'; ?>
                            for this staff member, recorded between
                            <?php echo html_escape((string) $held['oldest']); ?> and
                            <?php echo html_escape((string) $held['newest']); ?>.
                            <?php if ($held['erase_by']): ?>
                                The last of them is deleted automatically on
                                <strong><?php echo html_escape($held['erase_by']); ?></strong>
                                under the <?php echo (int) $held['retention_days']; ?>-day retention policy.
                            <?php endif; ?>
                            <?php if (!$status['may_collect']): ?>
                                Withdrawing consent stopped any further collection; it does not delete
                                what was already recorded.
                            <?php endif; ?>
                        </div>
                        <?php elseif (!empty($held)): ?>
                        <div class="alert alert-default">
                            No location points are held for this staff member.
                        </div>
                        <?php endif; ?>

                        <h5 class="bold">Consent notice (v<?php echo html_escape($policy); ?>)</h5>
                        <pre style="white-space:pre-wrap;background:#f7f7f7;border:1px solid #eee;padding:12px;"><?php echo html_escape($notice); ?></pre>

                        <hr>

                        <?php if ($isSelf && !$status['may_collect']): ?>
                            <?php echo form_open(admin_url('payplex_staff/staff/consent_grant')); ?>
                            <input type="hidden" name="staff_id" value="<?php echo (int) $staffId; ?>">
                            <div class="checkbox checkbox-primary">
                                <input type="checkbox" name="accept" id="accept" value="1">
                                <label for="accept">
                                    I have read the notice above and I consent to location tracking
                                    during field check-in sessions. I understand I can withdraw this at any time.
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary mtop15">Give consent</button>
                            <?php echo form_close(); ?>

                        <?php elseif ($status['may_collect']): ?>
                            <?php echo form_open(admin_url('payplex_staff/staff/consent_withdraw')); ?>
                            <input type="hidden" name="staff_id" value="<?php echo (int) $staffId; ?>">
                            <div class="form-group">
                                <label>Reason (optional)</label>
                                <input type="text" name="reason" class="form-control" maxlength="255">
                            </div>
                            <button type="submit" class="btn btn-danger">Withdraw consent</button>
                            <p class="text-muted mtop10 no-mbot">
                                Withdrawing stops all future collection immediately and closes any open session.
                                Visits already recorded are not deleted.
                            </p>
                            <?php echo form_close(); ?>

                        <?php else: ?>
                            <div class="alert alert-warning no-mbot">
                                Consent can only be given by the staff member signed in as themselves.
                                An administrator cannot opt someone else in.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="panel_s">
                    <div class="panel-body">
                        <h5 class="bold no-mtop">Consent history</h5>
                        <p class="text-muted">Append-only. Nothing here is ever edited or removed.</p>
                        <?php if (!$ledger): ?>
                            <p class="text-muted"><em>No entries yet.</em></p>
                        <?php else: ?>
                            <table class="table table-condensed">
                                <thead>
                                    <tr><th>When</th><th>Action</th><th>Policy</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach (array_reverse($ledger) as $row): ?>
                                    <tr>
                                        <td><?php echo html_escape($row['occurred_at']); ?></td>
                                        <td>
                                            <span class="label label-<?php echo $row['action'] === 'granted' ? 'success' : 'danger'; ?>">
                                                <?php echo html_escape($row['action']); ?>
                                            </span>
                                        </td>
                                        <td>v<?php echo html_escape($row['policy_version']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <hr>
                        <a href="<?php echo admin_url('payplex_staff/staff/field/' . (int) $staffId); ?>" class="btn btn-default btn-sm btn-block">
                            View field sessions
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
