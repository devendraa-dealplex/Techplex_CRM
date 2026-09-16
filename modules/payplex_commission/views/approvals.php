<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row"><div class="col-md-8">
            <div class="panel_s"><div class="panel-body">
                <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>
                <div class="alert alert-info">
                    You cannot approve a batch you generated, and you cannot approve a batch you earn
                    from — even as an administrator. Both checks run on the server, so a hidden button
                    is not what stops it.
                </div>

                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead><tr>
                            <th>#</th><th>Staff</th><th>Period</th><th>Gross</th><th>Clawed</th>
                            <th>Net</th><th>State</th><th>Maker</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="9" class="text-muted"><em>Nothing awaiting a decision.</em></td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <?php $st = $r['workflow_state'] ?: 'generated'; ?>
                            <tr>
                                <td><a href="<?php echo admin_url('payplex_commission/commission/statement_detail/' . (int) $r['id']); ?>">
                                    <?php echo (int) $r['id']; ?></a></td>
                                <td><?php echo (int) $r['staff_id']; ?>
                                    <?php if ((int) $r['staff_id'] === (int) $me): ?>
                                        <span class="label label-warning">you</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo html_escape($r['period']); ?></td>
                                <td><?php echo number_format((float) $r['gross_amount'], 2); ?></td>
                                <td><?php echo number_format((float) $r['clawback_amount'], 2); ?></td>
                                <td class="bold"><?php echo number_format((float) $r['net_amount'], 2); ?></td>
                                <td><span class="label label-<?php echo Payplex_commission_workflow::stateClass($st); ?>">
                                    <?php echo html_escape($st); ?></span>
                                    <?php if (!empty($r['edited_since_approval'])): ?>
                                        <span class="label label-danger">edited</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) $r['created_by']; ?>
                                    <?php if ((int) $r['created_by'] === (int) $me): ?>
                                        <span class="label label-warning">you</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach (Payplex_commission_workflow::transitions()[$st] as $to): ?>
                                        <?php echo form_open(admin_url('payplex_commission/commission/statement_transition'), array('style' => 'display:inline')); ?>
                                        <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                        <input type="hidden" name="to" value="<?php echo html_escape($to); ?>">
                                        <?php if ($to === 'rejected'): ?>
                                            <input type="hidden" name="reason" value="Rejected from the approval queue">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-xs btn-<?php echo $to === 'rejected' ? 'danger' : 'info'; ?>">
                                            <?php echo html_escape(str_replace('_', ' ', $to)); ?>
                                        </button>
                                        <?php echo form_close(); ?>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div></div>
        </div>

        <div class="col-md-4">
            <div class="panel_s"><div class="panel-body">
                <h5 class="bold no-mtop">Checker</h5>
                <?php
                $ap = isset($approvers) ? $approvers : array('holders' => array(), 'admins' => array());
                $holders = isset($ap['holders']) ? $ap['holders'] : array();
                ?>
                <?php if ($holders): ?>
                    <p class="text-success"><i class="fa fa-user-check"></i>
                        <strong><?php echo count($holders); ?></strong> staff member<?php echo count($holders) === 1 ? '' : 's'; ?>
                        hold the approve permission:</p>
                    <ul class="list-unstyled">
                        <?php foreach ($holders as $h): ?>
                            <li><i class="fa fa-user text-muted"></i>
                                <?php echo html_escape(trim($h['firstname'] . ' ' . $h['lastname'])); ?>
                                <?php if (!empty($h['admin'])): ?>
                                    <small class="text-muted">(administrator)</small>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="alert alert-warning" style="padding:8px 10px;margin-bottom:10px;">
                        <i class="fa fa-user-times"></i> <strong>No checker is assigned.</strong><br>
                        <small>Nobody holds the commission <em>approve</em> permission, so every approval
                        below would be made by an administrator. The <strong>Finance Officer</strong> role
                        exists with the right permissions &mdash; assign a staff member to it under
                        <em>Setup &rarr; Staff</em> to complete the maker-checker split.</small>
                    </div>
                    <?php if (!empty($ap['admins'])): ?>
                        <p class="text-muted"><small>Administrators who can currently reach this queue:
                        <?php
                        $names = array();
                        foreach ($ap['admins'] as $a) { $names[] = trim($a['firstname'] . ' ' . $a['lastname']); }
                        echo html_escape(implode(', ', $names));
                        ?>. They are still refused on any statement they generated or earn from.</small></p>
                    <?php endif; ?>
                <?php endif; ?>

                <hr>
                <h5 class="bold">Statement audit</h5>
                <?php
                /*
                 * This used to read "Append-only", which is a promise about the
                 * module's own code and says nothing about anyone editing the
                 * table directly. What follows is the result of re-hashing the
                 * stored log, so the screen reports a fact instead.
                 */
                $c = isset($chain) ? $chain : array('available' => false);
                ?>
                <?php if (empty($c['available'])): ?>
                    <p class="text-muted"><i class="fa fa-question-circle"></i>
                        Tamper-evidence not installed yet &mdash; run the module upgrade.</p>
                <?php elseif (!empty($c['ok'])): ?>
                    <p class="text-success"><i class="fa fa-lock"></i>
                        <strong>Verified</strong> &mdash; <?php echo (int) $c['checked']; ?>
                        entr<?php echo (int) $c['checked'] === 1 ? 'y' : 'ies'; ?> hash-chained and unaltered.
                    </p>
                    <?php if (empty($c['tail_proof'])): ?>
                        <p class="text-muted"><small>The newest entries cannot yet be proved complete
                        &mdash; the reference hash is recorded from the next entry onwards.</small></p>
                    <?php endif; ?>
                    <?php if (!empty($c['unchained'])): ?>
                        <p class="text-muted"><small><?php echo (int) $c['unchained']; ?> earlier
                        entr<?php echo (int) $c['unchained'] === 1 ? 'y was' : 'ies were'; ?> written
                        before tamper-evidence was added and <?php echo (int) $c['unchained'] === 1 ? 'is' : 'are'; ?>
                        not covered by the chain.</small></p>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-danger" style="padding:8px 10px;margin-bottom:10px;">
                        <i class="fa fa-exclamation-triangle"></i>
                        <strong>Audit log integrity check FAILED.</strong><br>
                        <small><?php echo html_escape($c['reason']); ?></small><br>
                        <small>Treat the affected records as unverified and escalate before approving
                        or paying anything further.</small>
                    </div>
                <?php endif; ?>
                <table class="table table-condensed">
                    <tbody>
                    <?php if (empty($can_audit)): ?>
                        <tr><td class="text-muted"><em>You do not hold the audit-trail
                        permission, so the entries are not shown.</em></td></tr>
                    <?php elseif (!$audit): ?>
                        <tr><td class="text-muted"><em>No entries.</em></td></tr>
                    <?php else: foreach ($audit as $a): ?>
                        <tr><td>
                            <small class="text-muted"><?php echo html_escape($a->occurred_at); ?></small><br>
                            <span class="label label-default"><?php echo html_escape($a->action); ?></span>
                            stmt #<?php echo (int) $a->statement_id; ?>
                            <?php if ($a->from_state || $a->to_state): ?>
                                <small><?php echo html_escape($a->from_state ?: '—'); ?>
                                &rarr; <?php echo html_escape($a->to_state ?: '—'); ?></small>
                            <?php endif; ?>
                            <?php if ($a->reason): ?><br><small><?php echo html_escape($a->reason); ?></small><?php endif; ?>
                        </td></tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div></div>
        </div></div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
