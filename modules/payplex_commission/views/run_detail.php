<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <div class="tw-flex tw-justify-between tw-items-center tw-mb-4">
                    <h4 class="no-margin"><?php echo html_escape($title); ?></h4>
                    <span class="label label-<?php echo $run->mode === 'generate' ? 'success' : 'default'; ?>">
                        <?php echo html_escape($run->mode); ?>
                    </span>
                </div>
                <table class="table table-condensed">
                    <tbody>
                        <tr><td class="bold">Period</td><td><?php echo html_escape($run->period); ?></td></tr>
                        <tr><td class="bold">Source policy</td>
                            <td>#<?php echo (int) $run->policy_id; ?> v<?php echo (int) $run->policy_version; ?></td></tr>
                        <tr><td class="bold">Eligible</td><td><?php echo (int) $run->eligible_count; ?></td></tr>
                        <tr><td class="bold">Needed configuration</td><td><?php echo (int) $run->config_required_count; ?></td></tr>
                        <tr><td class="bold">Excluded</td><td><?php echo (int) $run->excluded_count; ?></td></tr>
                        <tr><td class="bold">Base total</td><td><?php echo number_format((float) $run->base_total, 2); ?></td></tr>
                        <tr><td class="bold">Commission total</td><td class="bold"><?php echo number_format((float) $run->commission_total, 2); ?></td></tr>
                        <tr><td class="bold">Statements created</td><td><?php echo (int) $run->statements_created; ?></td></tr>
                        <tr><td class="bold">Run at</td><td><?php echo html_escape($run->created_at); ?></td></tr>
                    </tbody>
                </table>
                <?php if ($run->mode === 'preview'): ?>
                    <div class="alert alert-info no-mbot">
                        This was a preview. No statements, items or ledger entries were written.
                    </div>
                <?php endif; ?>
                <a href="<?php echo admin_url('payplex_commission/commission/preview?period=' . urlencode((string) $run->period)); ?>"
                   class="btn btn-default btn-sm mtop15">Back to preview</a>
            </div></div>
        </div></div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
