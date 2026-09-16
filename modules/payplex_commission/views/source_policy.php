<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <div class="tw-flex tw-justify-between tw-items-center tw-mb-4">
                    <h4 class="no-margin"><?php echo html_escape($title); ?></h4>
                    <a href="<?php echo admin_url('payplex_commission/commission/policy_form'); ?>" class="btn btn-primary btn-sm">New policy</a>
                </div>

                <?php if (!$active): ?>
                    <div class="alert alert-danger">
                        <strong>Commission generation is disabled.</strong>
                        No source policy is approved and active, so no transaction is eligible to earn.
                        This is the safe default: until someone states which business events pay commission,
                        the system refuses to guess.
                    </div>
                <?php else: ?>
                    <div class="alert alert-success">
                        <strong>Active policy:</strong>
                        #<?php echo (int) $active['id']; ?> <?php echo html_escape($active['name']); ?>
                        (v<?php echo (int) $active['version']; ?>) —
                        pays on: <?php echo html_escape(implode(', ', (array) $active['events'])); ?>
                    </div>
                    <?php foreach ($warnings as $w): ?>
                        <div class="alert alert-warning"><?php echo html_escape($w); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead><tr>
                            <th>#</th><th>Name</th><th>v</th><th>Events</th>
                            <th>Refunds</th><th>Partials</th><th>Tax</th><th>Status</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$policies): ?>
                            <tr><td colspan="9" class="text-muted"><em>No source policies defined yet.</em></td></tr>
                        <?php else: foreach ($policies as $p): ?>
                            <?php $ev = json_decode((string) $p['events_json'], true) ?: array(); ?>
                            <tr>
                                <td><?php echo (int) $p['id']; ?></td>
                                <td><?php echo html_escape($p['name']); ?></td>
                                <td><?php echo (int) $p['version']; ?></td>
                                <td><small><?php echo html_escape(implode(', ', $ev)); ?></small></td>
                                <td><?php echo (int) $p['exclude_refunded'] ? 'excluded' : '<span class="text-danger">included</span>'; ?></td>
                                <td><?php echo (int) $p['allow_partial'] ? 'paid' : 'not paid'; ?></td>
                                <td><?php echo (int) $p['include_tax'] ? '<span class="text-danger">in base</span>' : 'excluded'; ?></td>
                                <td><span class="label label-<?php echo Payplex_commission_source::statusClass($p['status']); ?>">
                                    <?php echo html_escape($p['status']); ?></span></td>
                                <td>
                                    <a href="<?php echo admin_url('payplex_commission/commission/policy_form/' . (int) $p['id']); ?>"
                                       class="btn btn-default btn-xs">Edit</a>
                                    <?php if ($p['status'] === 'submitted'
                                              && (stripos($p['name'], 'DUMMY') !== false || stripos($p['name'], 'TEST') !== false)
                                              && $this->cmodel->allowsTestArtefacts()): ?>
                                        <?php echo form_open(admin_url('payplex_commission/commission/bootstrap_approve_policy'), array('style' => 'display:inline')); ?>
                                        <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                                        <input type="hidden" name="reason" value="Staging pipeline demonstration">
                                        <button type="submit" class="btn btn-xs btn-warning">bootstrap approve</button>
                                        <?php echo form_close(); ?>
                                    <?php endif; ?>
                                    <?php foreach (Payplex_commission_source::transitions()[$p['status']] as $to): ?>
                                        <?php if ($to === 'approved' && empty($canApprove)) { continue; } ?>
                                        <?php echo form_open(admin_url('payplex_commission/commission/policy_transition'), array('style' => 'display:inline')); ?>
                                        <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>">
                                        <input type="hidden" name="to" value="<?php echo html_escape($to); ?>">
                                        <?php if ($p['status'] === 'submitted' && $to === 'draft'): ?>
                                            <input type="hidden" name="reason" value="Rejected from the policy list">
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
        </div></div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
