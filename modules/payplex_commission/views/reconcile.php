<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>
                <?php $t = $result['totals']; ?>
                <div class="alert alert-<?php echo $result['clean'] ? 'success' : 'danger'; ?>">
                    <?php if ($result['clean']): ?>
                        Everything reconciles: <?php echo (int) $t['matched']; ?> item(s) matched exactly.
                    <?php else: ?>
                        <strong>Reconciliation is not clean.</strong>
                        <?php echo (int) $t['matched']; ?> matched,
                        <?php echo (int) $t['mismatched']; ?> with a different amount,
                        <?php echo (int) $t['missing']; ?> with no bank record,
                        <?php echo (int) $t['unexpected']; ?> bank record(s) with no matching item.
                    <?php endif; ?>
                </div>

                <?php if ($result['mismatched']): ?>
                <h5 class="bold text-danger">Amount mismatches</h5>
                <table class="table table-condensed table-striped">
                    <thead><tr><th>Staff</th><th>Reference</th><th>Expected</th><th>Actual</th><th>Difference</th></tr></thead>
                    <tbody>
                    <?php foreach ($result['mismatched'] as $m): ?>
                        <tr><td><?php echo (int) $m['staff_id']; ?></td>
                            <td><?php echo html_escape($m['bank_reference']); ?></td>
                            <td><?php echo number_format((float) $m['expected'], 2); ?></td>
                            <td><?php echo number_format((float) $m['actual'], 2); ?></td>
                            <td class="bold text-danger"><?php echo number_format((float) $m['difference'], 2); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if ($result['missing']): ?>
                <h5 class="bold">Items with no bank record</h5>
                <table class="table table-condensed table-striped">
                    <thead><tr><th>Staff</th><th>Net expected</th><th>Note</th></tr></thead>
                    <tbody>
                    <?php foreach ($result['missing'] as $m): ?>
                        <tr><td><?php echo (int) $m['staff_id']; ?></td>
                            <td><?php echo number_format((float) $m['net_payable'], 2); ?></td>
                            <td><small><?php echo html_escape($m['recon_note']); ?></small></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if ($result['unexpected']): ?>
                <h5 class="bold">Bank records with no matching item</h5>
                <table class="table table-condensed table-striped">
                    <thead><tr><th>Reference</th><th>Amount</th><th>Note</th></tr></thead>
                    <tbody>
                    <?php foreach ($result['unexpected'] as $u): ?>
                        <tr><td><?php echo html_escape($u['bank_reference']); ?></td>
                            <td><?php echo number_format((float) $u['amount'], 2); ?></td>
                            <td><small><?php echo html_escape($u['recon_note']); ?></small></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <a href="<?php echo admin_url('payplex_commission/commission/payout_detail/' . (int) $batch['id']); ?>"
                   class="btn btn-default btn-sm">Back to the batch</a>
            </div></div>
        </div></div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
