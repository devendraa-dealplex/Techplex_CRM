<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">

        <?php if (!empty($legacyActive)): ?>
        <div class="row"><div class="col-md-12">
            <div class="alert alert-danger">
                <h4 class="bold no-mtop">Legacy rules are still active</h4>
                <p>
                    These rows live in the old <code>rule_versions</code> table and bypass the approval
                    workflow entirely. While any of them is active, a transaction can be paid at an
                    unapproved rate and the "configuration required" safety guard can never fire.
                </p>
                <ul>
                    <?php foreach ($legacyActive as $lr): ?>
                        <li>
                            <strong>#<?php echo (int) $lr->id; ?> <?php echo html_escape($lr->name); ?></strong>
                            — <code><?php echo html_escape($lr->rule_json); ?></code>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php echo form_open(admin_url('payplex_commission/commission/retire_test_rules')); ?>
                <button type="submit" class="btn btn-danger btn-sm">
                    Deactivate placeholder &amp; TEST rules
                </button>
                <span class="text-muted mleft10">Reversible — rows are kept, only the active flag is cleared.</span>
                <?php echo form_close(); ?>
            </div>
        </div></div>
        <?php endif; ?>

        <?php if (isset($resolution)): ?>
        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <h4 class="no-mtop">Rule resolution test <small>(read-only — nothing was created)</small></h4>
                <?php if ($resolution['status'] !== 'ok'): ?>
                    <div class="alert alert-warning">
                        <strong>Configuration required.</strong>
                        <?php echo html_escape($resolution['reason']); ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success">
                        Rule <strong>#<?php echo (int) $resolution['rule']['id']; ?>
                        <?php echo html_escape($resolution['rule']['name']); ?></strong>
                        (v<?php echo (int) $resolution['rule']['version']; ?>) would govern this transaction.
                    </div>
                    <?php if (!empty($outcome)): ?>
                        <p class="bold">
                            Base <?php echo html_escape(number_format((float) $base, 2)); ?>
                            &rarr; commission <?php echo html_escape(number_format((float) $outcome['amount'], 2)); ?>
                        </p>
                        <ul>
                            <?php foreach ($outcome['breakdown'] as $line): ?>
                                <li><?php echo html_escape($line); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if (!empty($resolution['conflicts'])): ?>
                        <div class="alert alert-warning">
                            <?php foreach ($resolution['conflicts'] as $cf): ?>
                                <div><?php echo html_escape($cf['message']); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div></div>
        </div></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8">
                <div class="panel_s"><div class="panel-body">
                    <div class="tw-flex tw-justify-between tw-items-center tw-mb-4">
                        <h4 class="no-margin"><?php echo html_escape($title); ?></h4>
                        <a href="<?php echo admin_url('payplex_commission/commission/rule_form'); ?>" class="btn btn-primary btn-sm">
                            New rule
                        </a>
                    </div>

                    <?php $env = $this->cmodel->environment(); ?>
                    <div class="alert alert-<?php echo $env === 'staging' ? 'warning' : 'info'; ?>">
                        <strong>Environment: <?php echo strtoupper($env); ?>.</strong>
                        <?php if ($env === 'staging'): ?>
                            Rules flagged TEST may be used here so the pipeline can be demonstrated. They are
                            refused outright on any install not declared as staging, so a dummy rate cannot
                            follow this database into production.
                        <?php else: ?>
                            Rules flagged TEST are refused. This is the default whenever the environment has
                            not been explicitly declared.
                        <?php endif; ?>
                        <?php echo form_open(admin_url('payplex_commission/commission/set_environment'), array('class' => 'form-inline mtop10')); ?>
                        <select name="environment" class="form-control input-sm">
                            <option value="production" <?php echo $env === 'production' ? 'selected' : ''; ?>>production</option>
                            <option value="staging" <?php echo $env === 'staging' ? 'selected' : ''; ?>>staging</option>
                        </select>
                        <button type="submit" class="btn btn-default btn-sm">Set environment</button>
                        <?php echo form_close(); ?>
                        <?php if ($env === 'staging'): ?>
                            <?php echo form_open(admin_url('payplex_commission/commission/seed_dummy'), array('class' => 'mtop10')); ?>
                            <button type="submit" class="btn btn-warning btn-sm">Seed DUMMY rules &amp; policy</button>
                            <span class="text-muted mleft10">Clearly labelled placeholders, not commercial rates.</span>
                            <?php echo form_close(); ?>
                        <?php endif; ?>
                    </div>

                    <div class="alert alert-info">
                        Only rules that are <strong>approved and then activated</strong> can generate commission.
                        A rule cannot be approved by the person who wrote it, and editing an approved rule
                        creates a new version rather than changing commissions already calculated.
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>#</th><th>Code</th><th>Name</th><th>v</th>
                                    <th>Type</th><th>Base</th><th>Scope</th>
                                    <th>Effective</th><th>Pri</th><th>Status</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$rules): ?>
                                <tr><td colspan="11" class="text-muted">
                                    <em>No rules defined. Until an approved, active rule exists, commission
                                    generation will report "configuration required" rather than paying a
                                    default rate.</em>
                                </td></tr>
                            <?php else: foreach ($rules as $r): ?>
                                <?php
                                    $scope = array();
                                    foreach (array('staff_id','employee_role','product','company','sales_channel','lead_source','customer_type','territory') as $d) {
                                        if (!empty($r[$d])) { $scope[] = $d . '=' . $r[$d]; }
                                    }
                                ?>
                                <tr>
                                    <td><?php echo (int) $r['id']; ?></td>
                                    <td><?php echo html_escape($r['rule_code']); ?></td>
                                    <td>
                                        <?php echo html_escape($r['name']); ?>
                                        <?php if ((int) $r['is_test'] === 1): ?>
                                            <span class="label label-danger">TEST</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo (int) $r['version']; ?></td>
                                    <td><?php echo html_escape($r['calc_type']); ?></td>
                                    <td><?php echo html_escape($r['calc_base']); ?></td>
                                    <td><small><?php echo $scope ? html_escape(implode(', ', $scope)) : '<em>all</em>'; ?></small></td>
                                    <td><small>
                                        <?php echo html_escape($r['effective_from'] ?: '—'); ?>
                                        &rarr; <?php echo html_escape($r['effective_to'] ?: '—'); ?>
                                    </small></td>
                                    <td><?php echo (int) $r['priority']; ?></td>
                                    <td>
                                        <span class="label label-<?php echo Payplex_commission_rule::statusClass($r['status']); ?>">
                                            <?php echo html_escape($r['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="<?php echo admin_url('payplex_commission/commission/rule_form/' . (int) $r['id']); ?>"
                                           class="btn btn-default btn-xs">Edit</a>
                                        <?php if ($r['status'] === 'submitted' && (int) $r['is_test'] === 1
                                                  && $this->cmodel->allowsTestArtefacts()): ?>
                                            <?php echo form_open(admin_url('payplex_commission/commission/bootstrap_approve_rule'), array('style' => 'display:inline')); ?>
                                            <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                            <input type="hidden" name="reason" value="Staging pipeline demonstration">
                                            <button type="submit" class="btn btn-xs btn-warning"
                                                    title="Staging bootstrap — recorded as such, not a real approval">
                                                bootstrap approve
                                            </button>
                                            <?php echo form_close(); ?>
                                        <?php endif; ?>
                                        <?php foreach (Payplex_commission_rule::transitions()[$r['status']] as $to): ?>
                                            <?php if ($to === 'approved' && empty($canApprove)) { continue; } ?>
                                            <?php echo form_open(admin_url('payplex_commission/commission/rule_transition'), array('style' => 'display:inline')); ?>
                                            <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                            <input type="hidden" name="to" value="<?php echo html_escape($to); ?>">
                                            <?php if ($r['status'] === 'submitted' && $to === 'draft'): ?>
                                                <input type="hidden" name="reason" value="Rejected from the rule list">
                                            <?php endif; ?>
                                            <button type="submit" class="btn btn-xs btn-<?php echo $to === 'archived' ? 'default' : 'info'; ?>">
                                                <?php echo html_escape($to === 'draft' ? 'reject' : $to); ?>
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
                    <h5 class="bold no-mtop">Test a transaction</h5>
                    <p class="text-muted">Shows which rule would apply and what it would pay. Creates nothing.</p>
                    <?php echo form_open(admin_url('payplex_commission/commission/rule_test')); ?>
                    <div class="form-group"><label>Base amount</label>
                        <input type="text" name="ctx_base" class="form-control" value="100000"></div>
                    <div class="form-group"><label>Target attainment %</label>
                        <input type="text" name="ctx_achieved_pct" class="form-control" value="0"></div>
                    <div class="form-group"><label>Staff id</label>
                        <input type="text" name="ctx_staff_id" class="form-control"></div>
                    <div class="form-group"><label>Role</label>
                        <input type="text" name="ctx_employee_role" class="form-control"></div>
                    <div class="form-group"><label>Product</label>
                        <input type="text" name="ctx_product" class="form-control"></div>
                    <div class="form-group"><label>Territory</label>
                        <input type="text" name="ctx_territory" class="form-control"></div>
                    <div class="form-group"><label>As at date</label>
                        <input type="date" name="ctx_date" class="form-control"></div>
                    <button type="submit" class="btn btn-info btn-sm btn-block">Resolve</button>
                    <?php echo form_close(); ?>
                </div></div>

                <div class="panel_s"><div class="panel-body">
                    <h5 class="bold no-mtop">Rule audit</h5>
                    <?php
                    /*
                     * "Append-only" is a promise about this module's code and
                     * says nothing about anyone editing the table directly.
                     * What follows is the result of re-hashing the stored log.
                     */
                    $c = isset($chain) ? $chain : array('available' => false);
                    ?>
                    <?php if (empty($c['available'])): ?>
                        <p class="text-muted"><i class="fa fa-question-circle"></i>
                            Tamper-evidence not installed yet &mdash; run the module upgrade.</p>
                    <?php elseif (!empty($c['ok']) && (int) $c['checked'] === 0 && !empty($c['unchained'])): ?>
                        <?php
                        /*
                         * Nothing here is covered yet, and that must not read as
                         * a clean bill of health. This branch is the live state
                         * of the rule log: twenty entries, all written before
                         * the chain columns existed, so nothing can be verified
                         * — which the previous layout announced as a green
                         * padlock reading "Verified — 0 entries".
                         */
                        ?>
                        <div class="alert alert-warning" style="padding:8px 10px;margin-bottom:10px;">
                            <i class="fa fa-unlock-alt"></i>
                            <strong>Not yet covered by tamper-evidence.</strong><br>
                            <small>All <?php echo (int) $c['unchained']; ?> entries in this log were
                            written before hash-chaining was added, so none of them can be verified.
                            Nothing here has been shown to be altered &mdash; but nothing has been
                            shown to be intact either. Entries written from now on are chained.</small>
                        </div>
                    <?php elseif (!empty($c['ok'])): ?>
                        <p class="text-success"><i class="fa fa-lock"></i>
                            <strong>Verified</strong> &mdash; <?php echo (int) $c['checked']; ?>
                            entr<?php echo (int) $c['checked'] === 1 ? 'y' : 'ies'; ?> hash-chained and unaltered.</p>
                        <?php if (!empty($c['unchained'])): ?>
                            <p class="text-warning"><small><?php echo (int) $c['unchained']; ?> earlier
                            entr<?php echo (int) $c['unchained'] === 1 ? 'y was' : 'ies were'; ?> written before
                            tamper-evidence was added and <?php echo (int) $c['unchained'] === 1 ? 'is' : 'are'; ?>
                            <strong>not covered</strong> by the chain.</small></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-danger" style="padding:8px 10px;margin-bottom:10px;">
                            <i class="fa fa-exclamation-triangle"></i>
                            <strong>Rule audit integrity check FAILED.</strong><br>
                            <small><?php echo html_escape($c['reason']); ?></small><br>
                            <small>Treat the affected rules as unverified and escalate before
                            approving, activating or paying on them.</small>
                        </div>
                    <?php endif; ?>
                    <table class="table table-condensed">
                        <tbody>
                        <?php if (!$audit): ?>
                            <tr><td class="text-muted"><em>No entries.</em></td></tr>
                        <?php else: foreach ($audit as $a): ?>
                            <tr>
                                <td>
                                    <small class="text-muted"><?php echo html_escape($a->occurred_at); ?></small><br>
                                    <span class="label label-default"><?php echo html_escape($a->action); ?></span>
                                    <?php if ($a->rule_id): ?> rule #<?php echo (int) $a->rule_id; ?><?php endif; ?>
                                    <?php if ($a->from_status || $a->to_status): ?>
                                        <small><?php echo html_escape($a->from_status ?: '—'); ?>
                                        &rarr; <?php echo html_escape($a->to_status ?: '—'); ?></small>
                                    <?php endif; ?>
                                    <?php if ($a->reason): ?>
                                        <br><small><?php echo html_escape($a->reason); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
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
