<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
/* Who is looking. Used below to decide which verification controls are worth
 * showing — the server decides whether they work. */
$pp_actor = function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0;
?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-justify-between tw-items-center tw-mb-4">
                            <h4 class="no-margin"><?php echo html_escape($title); ?></h4>
                            <?php if (isset($profile) && $profile): ?>
                                <span class="label label-<?php echo Payplex_staff_lifecycle::statusClass($profile->status); ?>">
                                    <?php echo html_escape(ucfirst($profile->status)); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Filter form -->
                        <?php echo form_open(admin_url('payplex_staff/staff/activity/' . (int) $staffId), array('method' => 'GET')); ?>
                        <div class="row mbot15">
                            <div class="col-md-3">
                                <label>From</label>
                                <input type="date" name="from" class="form-control" value="<?php echo html_escape($from); ?>">
                            </div>
                            <div class="col-md-3">
                                <label>To</label>
                                <input type="date" name="to" class="form-control" value="<?php echo html_escape($to); ?>">
                            </div>
                            <div class="col-md-3">
                                <label>Period</label>
                                <select name="period" class="form-control">
                                    <?php foreach (array('daily','weekly','monthly') as $opt): ?>
                                    <option value="<?php echo $opt; ?>" <?php echo $period === $opt ? 'selected' : ''; ?>><?php echo ucfirst($opt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3" style="padding-top:24px;">
                                <button type="submit" class="btn btn-info btn-sm">Filter</button>
                                <a href="<?php echo admin_url('payplex_staff/staff/view/' . (int) $staffId); ?>" class="btn btn-default btn-sm">Back to Profile</a>
                            </div>
                        </div>
                        <?php echo form_close(); ?>

                        <!-- Summary cards -->
                        <?php if (!empty($summary)): ?>
                        <div class="row mbot15">
                            <div class="col-md-3">
                                <div class="panel_s">
                                    <div class="panel-body text-center">
                                        <h3 class="bold text-info"><?php echo (int) $summary['total']; ?></h3>
                                        <p class="text-muted no-margin">Total Events</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="panel_s">
                                    <div class="panel-body text-center">
                                        <h3 class="bold text-success"><?php echo (int) $summary['verified_outcomes']; ?></h3>
                                        <p class="text-muted no-margin">Verified Outcomes</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="panel_s">
                                    <div class="panel-body text-center">
                                        <h3 class="bold text-muted"><?php echo (int) $summary['raw_activity']; ?></h3>
                                        <p class="text-muted no-margin">Raw Activity</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="panel_s">
                                    <div class="panel-body text-center">
                                        <h3 class="bold"><?php echo count($summary['by_category']); ?></h3>
                                        <p class="text-muted no-margin">Categories Active</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Timeline buckets -->
                        <?php if (!empty($timeline)): ?>
                        <h4 class="mbot15">Timeline (<?php echo html_escape(ucfirst($period)); ?>)</h4>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered dt-table" data-order='[[0,"desc"]]'>
                                <thead>
                                    <tr>
                                        <th>Period</th>
                                        <th>Events</th>
                                        <th>Verified Outcomes</th>
                                        <th>Categories</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($timeline as $bucket => $info): ?>
                                    <tr>
                                        <td><?php echo html_escape($bucket); ?></td>
                                        <td><?php echo (int) $info['count']; ?></td>
                                        <td>
                                            <span class="text-success bold"><?php echo (int) $info['verified_outcomes']; ?></span>
                                        </td>
                                        <td>
                                            <?php foreach ($info['by_category'] as $cat => $cnt): ?>
                                                <span class="label label-<?php echo Payplex_staff_activity::categoryClass($cat); ?> mright5">
                                                    <?php echo html_escape($cat); ?>: <?php echo (int) $cnt; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <!-- Log new event form -->
                        <?php if (payplex_staff_can('activity')): ?>
                        <hr>
                        <h4 class="mbot15">Log Event</h4>
                        <?php echo form_open(admin_url('payplex_staff/staff/activity_log')); ?>
                        <input type="hidden" name="staff_id" value="<?php echo (int) $staffId; ?>">
                        <div class="row">
                            <div class="col-md-3">
                                <label>Event Type</label>
                                <select name="event_type" class="form-control" required>
                                    <option value="">-- select --</option>
                                    <?php foreach ($eventTypes as $ev => $cat): ?>
                                    <option value="<?php echo html_escape($ev); ?>"><?php echo html_escape($ev); ?> (<?php echo html_escape($cat); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label>Value</label>
                                <input type="number" step="0.01" name="value" class="form-control" placeholder="optional">
                            </div>
                            <?php
                            /*
                             * The dropdown is only offered to someone who could
                             * actually use it. It is not the check — the server
                             * decides in Payplex_staff_activity::verificationDecision()
                             * — but offering a control that always refuses is how
                             * people learn to distrust the screen.
                             */
                            $pp_may_verify = payplex_staff_can('verify') && $pp_actor > 0 && $pp_actor !== (int) $staffId;
                            ?>
                            <?php if ($pp_may_verify): ?>
                            <div class="col-md-2">
                                <label>Verified</label>
                                <select name="verified" class="form-control">
                                    <option value="0">No</option>
                                    <option value="1">Yes</option>
                                </select>
                            </div>
                            <?php else: ?>
                            <div class="col-md-2">
                                <label>Verified</label>
                                <p class="text-muted no-mbot" style="padding-top:7px;">
                                    <i class="fa fa-minus"></i>
                                    <?php echo $pp_actor === (int) $staffId
                                        ? 'Not your own records'
                                        : 'Requires verify permission'; ?>
                                </p>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-3">
                                <label>Occurred At</label>
                                <input type="datetime-local" name="occurred_at" class="form-control">
                            </div>
                            <div class="col-md-2" style="padding-top:24px;">
                                <button type="submit" class="btn btn-primary btn-sm">Log</button>
                            </div>
                        </div>
                        <?php echo form_close(); ?>
                        <?php endif; ?>

                        <!-- Recent events table -->
                        <?php if (!empty($events)): ?>
                        <hr>
                        <h4 class="mbot15">Recent Events (latest <?php echo count($events); ?>)</h4>
                        <div class="table-responsive">
                            <table class="table table-striped dt-table" data-order='[[0,"desc"]]'>
                                <thead>
                                    <tr>
                                        <th>When</th>
                                        <th>Event</th>
                                        <th>Category</th>
                                        <th>Verified</th>
                                        <th>Value</th>
                                        <th>Ref</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($events as $e): ?>
                                    <tr>
                                        <td><?php echo html_escape($e->occurred_at); ?></td>
                                        <td><?php echo html_escape($e->event_type); ?></td>
                                        <td><span class="label label-<?php echo Payplex_staff_activity::categoryClass($e->category); ?>"><?php echo html_escape($e->category); ?></span></td>
                                        <td><?php echo (int) $e->verified ? '<i class="fa fa-check text-success"></i>' : '<i class="fa fa-minus text-muted"></i>'; ?></td>
                                        <td><?php echo $e->value !== null ? number_format((float) $e->value, 2) : '-'; ?></td>
                                        <td><?php echo $e->ref_type ? html_escape($e->ref_type) . '#' . (int) $e->ref_id : '-'; ?></td>
                                        <td>
                                            <?php
                                            /* The checker half: someone other than the
                                             * subject and other than the author confirms
                                             * the record before it can ever score. */
                                            $pp_can_verify_row = !((int) $e->verified)
                                                && payplex_staff_can('verify')
                                                && $pp_actor > 0
                                                && $pp_actor !== (int) $e->staff_id
                                                && $pp_actor !== (int) $e->created_by;
                                            ?>
                                            <?php if ($pp_can_verify_row): ?>
                                            <a href="<?php echo admin_url('payplex_staff/staff/activity_verify/' . (int) $e->id . '?staff_id=' . (int) $staffId); ?>"
                                               class="btn btn-default btn-xs">Verify</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
