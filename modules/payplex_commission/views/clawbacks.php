<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>
                <div class="alert alert-info">
                    A clawback is a separate, immutable record. The original commission entry is never
                    deleted or reduced — a half-recovered ₹10,000 commission still reads as ₹10,000 earned
                    and ₹5,000 recovered, because that is what actually happened.
                </div>

                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead><tr>
                            <th>#</th><th>Staff</th><th>Trigger</th><th>Reference</th><th>Basis</th>
                            <th>Earned</th><th>Recovering</th><th>Status</th><th>Method</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="10" class="text-muted"><em>No clawbacks raised.</em></td></tr>
                        <?php else: foreach ($rows as $c): ?>
                            <tr>
                                <td><?php echo (int) $c['id']; ?></td>
                                <td><?php echo (int) $c['staff_id']; ?>
                                    <?php if ((int) $c['staff_id'] === (int) $me): ?>
                                        <span class="label label-warning">you</span>
                                    <?php endif; ?></td>
                                <td><?php echo html_escape($c['trigger_event']); ?></td>
                                <td><small><?php echo html_escape($c['trigger_ref']); ?></small></td>
                                <td><small><?php echo html_escape($c['basis']); ?></small></td>
                                <td><?php echo number_format((float) $c['original_commission'], 2); ?></td>
                                <td class="bold text-danger"><?php echo number_format((float) $c['amount'], 2); ?></td>
                                <td><span class="label label-<?php echo Payplex_commission_clawback::statusClass($c['status']); ?>">
                                    <?php echo html_escape($c['status']); ?></span></td>
                                <td><small><?php echo html_escape($c['recovery_method']); ?></small></td>
                                <td>
                                    <?php foreach (Payplex_commission_clawback::transitions()[$c['status']] as $to): ?>
                                        <?php echo form_open(admin_url('payplex_commission/commission/decide_clawback'), array('style' => 'display:inline')); ?>
                                        <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                                        <input type="hidden" name="to" value="<?php echo html_escape($to); ?>">
                                        <?php if (in_array($to, array('waived', 'rejected'), true)): ?>
                                            <input type="hidden" name="reason" value="Decided from the clawback list">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-xs btn-<?php echo $to === 'recovered' ? 'success' : 'default'; ?>">
                                            <?php echo html_escape($to); ?>
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
        </div></div>

        <div class="row">
            <div class="col-md-5">
                <div class="panel_s"><div class="panel-body">
                    <h5 class="bold no-mtop">Raise a clawback</h5>
                    <?php echo form_open(admin_url('payplex_commission/commission/raise_clawback')); ?>
                    <div class="form-group"><label>Commission entry</label>
                        <select name="ledger_id" class="form-control">
                            <option value="">-- select an earned entry --</option>
                            <?php foreach ($ledger as $l): ?>
                                <option value="<?php echo (int) $l['id']; ?>">
                                    #<?php echo (int) $l['id']; ?> — staff <?php echo (int) $l['staff_id']; ?>,
                                    <?php echo html_escape($l['source_type']); ?> #<?php echo (int) $l['source_id']; ?>,
                                    earned <?php echo number_format((float) $l['commission_amount'], 2); ?>
                                    on <?php echo number_format((float) $l['base_amount'], 2); ?>
                                </option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label>Trigger</label>
                        <select name="trigger" class="form-control">
                            <?php foreach ($triggers as $k => $label): ?>
                                <option value="<?php echo $k; ?>"><?php echo html_escape($label); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label>Triggering reference <small class="text-muted">(refund id, chargeback ref)</small></label>
                        <input type="text" name="trigger_ref" class="form-control" maxlength="100"></div>
                    <div class="form-group"><label>Amount returned to the customer</label>
                        <input type="text" name="returned_amount" class="form-control"></div>
                    <div class="form-group"><label>Basis</label>
                        <select name="basis" class="form-control">
                            <option value="">use the trigger default</option>
                            <option value="proportional">Proportional to the amount returned</option>
                            <option value="full">Full commission</option>
                            <option value="fixed">Fixed amount</option>
                        </select></div>
                    <div class="form-group"><label>Fixed amount <small class="text-muted">(only when basis is fixed)</small></label>
                        <input type="text" name="fixed_amount" class="form-control"></div>
                    <div class="form-group"><label>Recovery method</label>
                        <select name="recovery_method" class="form-control">
                            <?php foreach ($methods as $k => $label): ?>
                                <option value="<?php echo $k; ?>"><?php echo html_escape($label); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label>Reason</label>
                        <input type="text" name="reason" class="form-control" maxlength="500"></div>
                    <button type="submit" class="btn btn-danger btn-sm">Raise clawback</button>
                    <?php echo form_close(); ?>
                </div></div>
            </div>

            <div class="col-md-7">
                <div class="panel_s"><div class="panel-body">
                    <h5 class="bold no-mtop">Earned commission entries</h5>
                    <p class="text-muted">Each row is one source record that earned commission. These are never modified.</p>
                    <?php if (!$ledger): ?>
                        <p class="text-muted"><em>Nothing has been generated yet.</em></p>
                    <?php else: ?>
                    <div class="table-responsive"><table class="table table-condensed table-striped">
                        <thead><tr><th>#</th><th>Staff</th><th>Source</th><th>Base</th><th>Earned</th><th>Statement</th></tr></thead>
                        <tbody>
                        <?php foreach ($ledger as $l): ?>
                            <tr>
                                <td><?php echo (int) $l['id']; ?></td>
                                <td><?php echo (int) $l['staff_id']; ?></td>
                                <td><small><?php echo html_escape($l['source_type']); ?> #<?php echo (int) $l['source_id']; ?></small></td>
                                <td><?php echo number_format((float) $l['base_amount'], 2); ?></td>
                                <td class="bold"><?php echo number_format((float) $l['commission_amount'], 2); ?></td>
                                <td><?php echo (int) $l['statement_id']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php endif; ?>
                </div></div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
