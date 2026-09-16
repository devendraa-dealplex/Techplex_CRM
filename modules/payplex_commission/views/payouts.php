<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row"><div class="col-md-8">
            <div class="panel_s"><div class="panel-body">
                <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>
                <div class="alert alert-warning">
                    <strong>No payment is made from this screen.</strong> There is no bank integration, so
                    payout is an export-and-record workflow: the batch produces a masked file for finance,
                    and an item becomes "paid" only when a person enters the real bank reference after the
                    bank has actually settled. Nothing here simulates a payment.
                </div>

                <?php echo form_open(admin_url('payplex_commission/commission/create_payout'), array('class' => 'form-inline mbot15')); ?>
                <label>Period</label>
                <input type="text" name="period" class="form-control" value="<?php echo html_escape($period); ?>" placeholder="YYYY-MM">
                <button type="submit" class="btn btn-primary btn-sm">
                    Assemble batch from <?php echo count($payable); ?> payable statement(s)
                </button>
                <?php echo form_close(); ?>

                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead><tr>
                            <th>Reference</th><th>Period</th><th>Items</th><th>Gross</th>
                            <th>TDS</th><th>Net</th><th>Status</th><th>Preparer</th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$batches): ?>
                            <tr><td colspan="8" class="text-muted"><em>No payout batches yet.</em></td></tr>
                        <?php else: foreach ($batches as $b): ?>
                            <tr>
                                <td><a href="<?php echo admin_url('payplex_commission/commission/payout_detail/' . (int) $b['id']); ?>">
                                    <?php echo html_escape($b['reference']); ?></a></td>
                                <td><?php echo html_escape($b['period']); ?></td>
                                <td><?php echo (int) $b['item_count']; ?></td>
                                <td><?php echo number_format((float) $b['gross_total'], 2); ?></td>
                                <td><?php echo number_format((float) $b['tds_total'], 2); ?></td>
                                <td class="bold"><?php echo number_format((float) $b['net_total'], 2); ?></td>
                                <td><span class="label label-<?php echo Payplex_commission_payout::statusClass($b['status']); ?>">
                                    <?php echo html_escape($b['status']); ?></span></td>
                                <td>#<?php echo (int) $b['created_by']; ?>
                                    <?php if ((int) $b['created_by'] === (int) $me): ?>
                                        <span class="label label-warning">you</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <h5 class="bold">Payable statements for <?php echo html_escape($period); ?></h5>
                <?php if (!$payable): ?>
                    <p class="text-muted"><em>Nothing payable. A statement must be approved and then marked
                       payable in the approval queue before it can be paid.</em></p>
                <?php else: ?>
                <table class="table table-condensed">
                    <thead><tr><th>Statement</th><th>Staff</th><th>Net</th></tr></thead>
                    <tbody>
                    <?php foreach ($payable as $s): ?>
                        <tr><td>#<?php echo (int) $s['id']; ?></td>
                            <td><?php echo (int) $s['staff_id']; ?></td>
                            <td><?php echo number_format((float) $s['net_amount'], 2); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div></div>
        </div>

        <div class="col-md-4">
            <div class="panel_s"><div class="panel-body">
                <h5 class="bold no-mtop">Payout activity</h5>
                <table class="table table-condensed">
                    <tbody>
                    <?php if (!$events): ?>
                        <tr><td class="text-muted"><em>No activity.</em></td></tr>
                    <?php else: foreach ($events as $e): ?>
                        <tr><td>
                            <small class="text-muted"><?php echo html_escape($e->occurred_at); ?></small><br>
                            <span class="label label-default"><?php echo html_escape($e->action); ?></span>
                            <?php if ($e->batch_id): ?>batch #<?php echo (int) $e->batch_id; ?><?php endif; ?>
                            <?php if ($e->reason): ?><br><small><?php echo html_escape($e->reason); ?></small><?php endif; ?>
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
