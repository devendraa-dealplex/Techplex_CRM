<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">

        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>

                <?php echo form_open(admin_url('payplex_commission/commission/preview'), array('class' => 'form-inline mbot15')); ?>
                <label>Period</label>
                <input type="text" name="period" class="form-control" value="<?php echo html_escape($period); ?>" placeholder="YYYY-MM">
                <button type="submit" class="btn btn-info btn-sm">Preview</button>
                <?php echo form_close(); ?>

                <?php $t = $evaluation['totals']; ?>

                <?php if (!$policy): ?>
                    <div class="alert alert-danger">
                        <strong>Generation is disabled.</strong> No source policy is approved and active, so
                        none of the <?php echo (int) $sourceCount; ?> transaction(s) found in this period are
                        eligible. Approve a source policy first.
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-3"><div class="panel_s"><div class="panel-body text-center">
                        <h3 class="no-margin"><?php echo (int) $sourceCount; ?></h3>
                        <span class="text-muted">transactions found</span>
                    </div></div></div>
                    <div class="col-md-3"><div class="panel_s"><div class="panel-body text-center">
                        <h3 class="no-margin text-success"><?php echo (int) $t['eligible_count']; ?></h3>
                        <span class="text-muted">eligible</span>
                    </div></div></div>
                    <div class="col-md-3"><div class="panel_s"><div class="panel-body text-center">
                        <h3 class="no-margin <?php echo (int) $t['config_required_count'] > 0 ? 'text-danger' : ''; ?>">
                            <?php echo (int) $t['config_required_count']; ?></h3>
                        <span class="text-muted">need configuration</span>
                    </div></div></div>
                    <div class="col-md-3"><div class="panel_s"><div class="panel-body text-center">
                        <h3 class="no-margin"><?php echo number_format((float) $t['commission'], 2); ?></h3>
                        <span class="text-muted">commission if generated</span>
                    </div></div></div>
                </div>

                <div class="alert alert-info">
                    This preview ran the <strong>same evaluation</strong> that generation uses and wrote
                    <strong>no</strong> statements, items or ledger entries. The figure above is what would
                    be generated, not an estimate of it.
                </div>

                <?php if ($policy && (int) $t['eligible_count'] > 0): ?>
                    <?php echo form_open(admin_url('payplex_commission/commission/generate_v2')); ?>
                    <input type="hidden" name="period" value="<?php echo html_escape($period); ?>">
                    <button type="submit" class="btn btn-primary">
                        Generate <?php echo (int) $t['eligible_count']; ?> transaction(s) — <?php echo number_format((float) $t['commission'], 2); ?>
                    </button>
                    <span class="text-muted mleft10">
                        Creates statements pending approval. Records already generated are skipped, so
                        running this twice cannot pay twice.
                    </span>
                    <?php echo form_close(); ?>
                <?php endif; ?>
            </div></div>
        </div></div>

        <?php if (!empty($evaluation['config_required'])): ?>
        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <h5 class="bold no-mtop text-danger">Needs configuration — will NOT be paid</h5>
                <p class="text-muted">These transactions are eligible under the policy, but no approved rule
                   governs them (or the rule's calculation base is unavailable). They are reported rather than
                   paid at a guessed rate.</p>
                <div class="table-responsive"><table class="table table-condensed table-striped">
                    <thead><tr><th>Source</th><th>Staff</th><th>Collected</th><th>Reason</th></tr></thead>
                    <tbody>
                    <?php foreach ($evaluation['config_required'] as $r): ?>
                        <tr>
                            <td><?php echo html_escape($r['source_type']); ?> #<?php echo (int) $r['source_id']; ?></td>
                            <td><?php echo (int) $r['staff_id']; ?></td>
                            <td><?php echo number_format((float) ($r['amount_collected'] ?? 0), 2); ?></td>
                            <td><?php echo html_escape($r['exclusion_reason']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div></div>
        </div></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <div class="panel_s"><div class="panel-body">
                    <h5 class="bold no-mtop text-success">Eligible</h5>
                    <?php if (empty($evaluation['eligible'])): ?>
                        <p class="text-muted"><em>Nothing eligible in this period.</em></p>
                    <?php else: ?>
                    <div class="table-responsive"><table class="table table-condensed table-striped">
                        <thead><tr><th>Source</th><th>Staff</th><th>Rule</th><th>Base</th><th>Commission</th></tr></thead>
                        <tbody>
                        <?php foreach ($evaluation['eligible'] as $r): ?>
                            <tr>
                                <td><?php echo html_escape($r['source_type']); ?> #<?php echo (int) $r['source_id']; ?></td>
                                <td><?php echo (int) $r['staff_id']; ?></td>
                                <td><small><?php echo html_escape($r['rule_name']); ?> v<?php echo (int) $r['rule_version']; ?></small></td>
                                <td><?php echo number_format((float) $r['base_amount'], 2); ?></td>
                                <td class="bold"><?php echo number_format((float) $r['commission_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php endif; ?>
                </div></div>
            </div>

            <div class="col-md-6">
                <div class="panel_s"><div class="panel-body">
                    <h5 class="bold no-mtop">Excluded — and why</h5>
                    <?php if (empty($evaluation['excluded'])): ?>
                        <p class="text-muted"><em>Nothing excluded.</em></p>
                    <?php else: ?>
                    <div class="table-responsive"><table class="table table-condensed table-striped">
                        <thead><tr><th>Source</th><th>Staff</th><th>Reason</th></tr></thead>
                        <tbody>
                        <?php foreach ($evaluation['excluded'] as $r): ?>
                            <tr>
                                <td><?php echo html_escape($r['source_type'] ?? '?'); ?> #<?php echo (int) ($r['source_id'] ?? 0); ?></td>
                                <td><?php echo (int) ($r['staff_id'] ?? 0); ?></td>
                                <td><small><?php echo html_escape($r['exclusion_reason']); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php endif; ?>
                </div></div>
            </div>
        </div>

        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <h5 class="bold no-mtop">Recent runs</h5>
                <table class="table table-condensed">
                    <thead><tr><th>#</th><th>Mode</th><th>Period</th><th>Eligible</th><th>Config</th>
                               <th>Excluded</th><th>Commission</th><th>Statements</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ($runs as $r): ?>
                        <tr>
                            <td><a href="<?php echo admin_url('payplex_commission/commission/run_detail/' . (int) $r->id); ?>">
                                <?php echo (int) $r->id; ?></a></td>
                            <td><span class="label label-<?php echo $r->mode === 'generate' ? 'success' : 'default'; ?>">
                                <?php echo html_escape($r->mode); ?></span></td>
                            <td><?php echo html_escape($r->period); ?></td>
                            <td><?php echo (int) $r->eligible_count; ?></td>
                            <td><?php echo (int) $r->config_required_count; ?></td>
                            <td><?php echo (int) $r->excluded_count; ?></td>
                            <td><?php echo number_format((float) $r->commission_total, 2); ?></td>
                            <td><?php echo (int) $r->statements_created; ?></td>
                            <td><small><?php echo html_escape($r->created_at); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div></div>
        </div></div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
