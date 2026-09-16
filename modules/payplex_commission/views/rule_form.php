<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s"><div class="panel-body">
                    <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>

                    <?php if ($rule && !Payplex_commission_rule::isEditableInPlace($rule)): ?>
                        <div class="alert alert-warning">
                            This rule is <strong><?php echo html_escape($rule['status']); ?></strong>, so it may already
                            have been used to calculate commission. Saving will create a
                            <strong>new version</strong> in draft rather than changing this one, and the new
                            version must be approved before it can pay.
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-info">
                        Rates are a business decision. Nothing here is pre-filled and there is no default —
                        a rule with no rate or amount will be rejected rather than saved as zero.
                    </div>

                    <?php echo form_open(admin_url('payplex_commission/commission/rule_store')); ?>
                    <input type="hidden" name="id" value="<?php echo $rule ? (int) $rule['id'] : 0; ?>">

                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>Rule code <small class="text-danger">*</small></label>
                            <input type="text" name="rule_code" class="form-control" maxlength="64"
                                   value="<?php echo html_escape($rule['rule_code'] ?? ''); ?>">
                        </div>
                        <div class="col-md-5 form-group">
                            <label>Rule name <small class="text-danger">*</small></label>
                            <input type="text" name="name" class="form-control" maxlength="191"
                                   value="<?php echo html_escape($rule['name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Priority <small class="text-muted">(lower wins)</small></label>
                            <input type="number" name="priority" class="form-control"
                                   value="<?php echo html_escape($rule['priority'] ?? 100); ?>">
                        </div>
                    </div>

                    <h5 class="bold">Calculation</h5>
                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label>Type <small class="text-danger">*</small></label>
                            <select name="calc_type" class="form-control">
                                <option value="">-- select --</option>
                                <?php foreach ($calcTypes as $k => $label): ?>
                                    <option value="<?php echo $k; ?>" <?php echo (($rule['calc_type'] ?? '') === $k) ? 'selected' : ''; ?>>
                                        <?php echo html_escape($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Calculated on <small class="text-danger">*</small></label>
                            <select name="calc_base" class="form-control">
                                <option value="">-- select --</option>
                                <?php foreach ($calcBases as $k => $label): ?>
                                    <option value="<?php echo $k; ?>" <?php echo (($rule['calc_base'] ?? '') === $k) ? 'selected' : ''; ?>>
                                        <?php echo html_escape($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Rate %</label>
                            <input type="text" name="rate" class="form-control"
                                   value="<?php echo html_escape($rule['rate'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Fixed amount</label>
                            <input type="text" name="amount" class="form-control"
                                   value="<?php echo html_escape($rule['amount'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Slab / tier bands <small class="text-muted">JSON, ascending, last may use "upto": null</small></label>
                        <textarea name="slabs_json" class="form-control" rows="3"
                                  placeholder='[{"upto":100000,"rate":3},{"upto":500000,"rate":5},{"upto":null,"rate":7}]'><?php echo html_escape($rule['slabs_json'] ?? ''); ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label>Minimum threshold</label>
                            <input type="text" name="min_threshold" class="form-control"
                                   value="<?php echo html_escape($rule['min_threshold'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Max eligible amount</label>
                            <input type="text" name="max_eligible_amount" class="form-control"
                                   value="<?php echo html_escape($rule['max_eligible_amount'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label>Accel. threshold %</label>
                            <input type="text" name="accelerator_threshold" class="form-control"
                                   value="<?php echo html_escape($rule['accelerator_threshold'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label>Accel. multiplier</label>
                            <input type="text" name="accelerator_rate" class="form-control"
                                   value="<?php echo html_escape($rule['accelerator_rate'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2 form-group">
                            <label>Payout cap</label>
                            <input type="text" name="cap" class="form-control"
                                   value="<?php echo html_escape($rule['cap'] ?? ''); ?>">
                        </div>
                    </div>

                    <h5 class="bold">Scope <small class="text-muted">— leave blank for "applies to all"</small></h5>
                    <div class="row">
                        <?php
                        $scopeFields = array(
                            'staff_id' => 'Specific staff id', 'employee_role' => 'Employee role',
                            'product' => 'Product / service', 'company' => 'Company / brand',
                            'sales_channel' => 'Sales channel', 'lead_source' => 'Lead source',
                            'customer_type' => 'Customer type', 'territory' => 'Territory',
                        );
                        foreach ($scopeFields as $f => $label): ?>
                            <div class="col-md-3 form-group">
                                <label><?php echo html_escape($label); ?></label>
                                <input type="text" name="<?php echo $f; ?>" class="form-control"
                                       value="<?php echo html_escape($rule[$f] ?? ''); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h5 class="bold">Validity</h5>
                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label>Effective from</label>
                            <input type="date" name="effective_from" class="form-control"
                                   value="<?php echo html_escape($rule['effective_from'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 form-group">
                            <label>Effective to</label>
                            <input type="date" name="effective_to" class="form-control"
                                   value="<?php echo html_escape($rule['effective_to'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group" style="padding-top:24px;">
                            <div class="checkbox checkbox-danger">
                                <input type="checkbox" name="is_test" id="is_test" value="1"
                                    <?php echo !empty($rule['is_test']) ? 'checked' : ''; ?>>
                                <label for="is_test">This is a TEST rule — it must never generate production commission</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?php echo html_escape($rule['notes'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">Save as draft</button>
                    <a href="<?php echo admin_url('payplex_commission/commission/rule_builder'); ?>" class="btn btn-default">Cancel</a>
                    <?php echo form_close(); ?>
                </div></div>
            </div>
        </div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
