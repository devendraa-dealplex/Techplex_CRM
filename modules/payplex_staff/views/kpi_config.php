<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="tw-flex tw-justify-between tw-items-center tw-mb-4">
                            <h4 class="no-margin"><?php echo html_escape($title); ?></h4>
                            <a href="<?php echo admin_url('payplex_staff/staff/kpi_seed'); ?>" class="btn btn-default btn-sm"
                               onclick="return confirm('Seed default KPI definitions? (one-time, safe)');">
                                <i class="fa fa-magic"></i> Seed Defaults
                            </a>
                        </div>

                        <!-- Existing definitions -->
                        <?php if (!empty($defs)): ?>
                        <div class="table-responsive mbot20">
                            <table class="table table-striped table-bordered dt-table" data-order='[[1,"asc"]]'>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Metric</th>
                                        <th>Role</th>
                                        <th>Weight</th>
                                        <th>Target</th>
                                        <th>Cap</th>
                                        <th>Penalty</th>
                                        <th>Effective</th>
                                        <th>Active</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($defs as $d): ?>
                                    <tr>
                                        <td><?php echo (int) $d->id; ?></td>
                                        <td>
                                            <strong><?php echo html_escape(isset($catalog[$d->metric]) ? $catalog[$d->metric] : $d->metric); ?></strong>
                                            <br><small class="text-muted"><?php echo html_escape($d->metric); ?></small>
                                        </td>
                                        <td><?php echo $d->role ? html_escape($d->role) : '<em class="text-muted">all</em>'; ?></td>
                                        <td><?php echo number_format((float) $d->weight, 3); ?></td>
                                        <td><?php echo $d->target !== null ? number_format((float) $d->target, 2) : '-'; ?></td>
                                        <td><?php echo $d->cap !== null ? number_format((float) $d->cap, 2) : '-'; ?></td>
                                        <td><?php echo (float) $d->penalty > 0 ? '<span class="text-danger">' . number_format((float) $d->penalty, 2) . '</span>' : '-'; ?></td>
                                        <td>
                                            <?php echo $d->effective_from ? html_escape($d->effective_from) : '∞'; ?>
                                            → <?php echo $d->effective_to ? html_escape($d->effective_to) : '∞'; ?>
                                        </td>
                                        <td><?php echo (int) $d->active ? '<i class="fa fa-check text-success"></i>' : '<i class="fa fa-times text-muted"></i>'; ?></td>
                                        <td>
                                            <a href="<?php echo admin_url('payplex_staff/staff/kpi_delete/' . (int) $d->id); ?>"
                                               class="btn btn-danger btn-xs" onclick="return confirm('Delete this KPI definition?');">
                                                <i class="fa fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">No KPI definitions yet. Click <strong>Seed Defaults</strong> to create a starting scorecard, or add one below.</div>
                        <?php endif; ?>

                        <!-- Add / edit form -->
                        <hr>
                        <h4 class="mbot15">Add KPI Definition</h4>
                        <?php echo form_open(admin_url('payplex_staff/staff/kpi_store')); ?>
                        <div class="row">
                            <div class="col-md-3">
                                <label>Metric *</label>
                                <select name="metric" class="form-control" required>
                                    <option value="">-- select --</option>
                                    <?php foreach ($catalog as $key => $label): ?>
                                    <option value="<?php echo html_escape($key); ?>"><?php echo html_escape($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label>Role (blank = all)</label>
                                <select name="role" class="form-control">
                                    <option value="">All roles</option>
                                    <?php foreach ($roles as $rk => $rl): ?>
                                    <option value="<?php echo html_escape($rk); ?>"><?php echo html_escape($rl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <label>Weight</label>
                                <input type="number" step="0.001" min="0" max="1" name="weight" class="form-control" value="0.200" required>
                            </div>
                            <div class="col-md-2">
                                <label>Target</label>
                                <input type="number" step="0.01" name="target" class="form-control" placeholder="optional">
                            </div>
                            <div class="col-md-2">
                                <label>Cap</label>
                                <input type="number" step="0.01" name="cap" class="form-control" placeholder="optional">
                            </div>
                            <div class="col-md-2">
                                <label>Penalty/unit</label>
                                <input type="number" step="0.01" name="penalty" class="form-control" value="0">
                            </div>
                        </div>
                        <div class="row mtop10">
                            <div class="col-md-3">
                                <label>Effective From</label>
                                <input type="date" name="effective_from" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label>Effective To</label>
                                <input type="date" name="effective_to" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label>Active</label>
                                <select name="active" class="form-control">
                                    <option value="1">Yes</option>
                                    <option value="0">No</option>
                                </select>
                            </div>
                            <div class="col-md-2" style="padding-top:24px;">
                                <button type="submit" class="btn btn-primary btn-sm">Save Definition</button>
                            </div>
                        </div>
                        <?php echo form_close(); ?>

                        <!-- Metric catalog reference -->
                        <hr>
                        <h4 class="mbot15">Metric Catalog Reference</h4>
                        <div class="row">
                            <?php foreach ($catalog as $key => $label): ?>
                            <div class="col-md-3 mbot5">
                                <code><?php echo html_escape($key); ?></code> — <?php echo html_escape($label); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
