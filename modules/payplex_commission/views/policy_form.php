<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
    <div class="content">
        <div class="row"><div class="col-md-12">
            <div class="panel_s"><div class="panel-body">
                <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>

                <?php $sel = $policy ? (array) $policy['events'] : array(); ?>
                <?php $f = $policy && isset($policy['filters']) ? (array) $policy['filters'] : array(); ?>

                <div class="alert alert-info">
                    Choose the events that genuinely earn commission. Events marked
                    <strong>billing only</strong> pay before money is received, which creates a clawback
                    if the invoice is never collected.
                </div>

                <?php echo form_open(admin_url('payplex_commission/commission/policy_store')); ?>
                <input type="hidden" name="id" value="<?php echo $policy ? (int) $policy['id'] : 0; ?>">

                <div class="form-group">
                    <label>Policy name <small class="text-danger">*</small></label>
                    <input type="text" name="name" class="form-control" maxlength="191"
                           value="<?php echo html_escape($policy['name'] ?? ''); ?>">
                </div>

                <h5 class="bold">Eligible events <small class="text-danger">*</small></h5>
                <div class="row">
                    <?php foreach ($events as $k => $label): ?>
                        <div class="col-md-4">
                            <div class="checkbox checkbox-primary">
                                <input type="checkbox" name="events[]" id="ev_<?php echo $k; ?>" value="<?php echo $k; ?>"
                                    <?php echo in_array($k, $sel, true) ? 'checked' : ''; ?>>
                                <label for="ev_<?php echo $k; ?>">
                                    <?php echo html_escape($label); ?>
                                    <?php if (!Payplex_commission_source::isCollectionEvent($k)): ?>
                                        <span class="label label-warning">billing only</span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <h5 class="bold mtop15">Money treatment</h5>
                <div class="row">
                    <div class="col-md-3">
                        <div class="checkbox checkbox-primary">
                            <input type="checkbox" name="exclude_refunded" id="er" value="1"
                                <?php echo (!$policy || (int) $policy['exclude_refunded']) ? 'checked' : ''; ?>>
                            <label for="er">Exclude refunded</label>
                        </div>
                        <div class="checkbox checkbox-primary">
                            <input type="checkbox" name="exclude_cancelled" id="ec" value="1"
                                <?php echo (!$policy || (int) $policy['exclude_cancelled']) ? 'checked' : ''; ?>>
                            <label for="ec">Exclude cancelled</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="checkbox checkbox-primary">
                            <input type="checkbox" name="allow_partial" id="ap" value="1"
                                <?php echo ($policy && (int) $policy['allow_partial']) ? 'checked' : ''; ?>>
                            <label for="ap">Pay on partial payments</label>
                        </div>
                        <div class="checkbox checkbox-danger">
                            <input type="checkbox" name="include_tax" id="it" value="1"
                                <?php echo ($policy && (int) $policy['include_tax']) ? 'checked' : ''; ?>>
                            <label for="it">Include tax in the base</label>
                        </div>
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Gateway fees</label>
                        <select name="gateway_fee_treatment" class="form-control">
                            <?php foreach ($gateways as $k => $label): ?>
                                <option value="<?php echo $k; ?>"
                                    <?php echo (($policy['gateway_fee_treatment'] ?? 'ignore') === $k) ? 'selected' : ''; ?>>
                                    <?php echo html_escape($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Minimum collected amount</label>
                        <input type="text" name="min_collected_amount" class="form-control"
                               value="<?php echo html_escape($policy['min_collected_amount'] ?? ''); ?>">
                    </div>
                </div>

                <h5 class="bold">Scope <small class="text-muted">— blank means no restriction; comma-separate multiples</small></h5>
                <div class="row">
                    <?php foreach (array('company' => 'Company', 'product' => 'Product', 'customer_type' => 'Customer type',
                                         'territory' => 'Territory', 'sales_channel' => 'Sales channel', 'lead_source' => 'Lead source') as $k => $label): ?>
                        <div class="col-md-2 form-group">
                            <label><?php echo $label; ?></label>
                            <input type="text" name="filter_<?php echo $k; ?>" class="form-control"
                                   value="<?php echo html_escape(is_array($f[$k] ?? '') ? implode(',', $f[$k]) : ($f[$k] ?? '')); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="row">
                    <div class="col-md-3 form-group">
                        <label>Currencies</label>
                        <input type="text" name="currencies" class="form-control" placeholder="INR"
                               value="<?php echo html_escape($policy['currencies'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Date from</label>
                        <input type="date" name="date_from" class="form-control"
                               value="<?php echo html_escape($policy['date_from'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3 form-group">
                        <label>Date to</label>
                        <input type="date" name="date_to" class="form-control"
                               value="<?php echo html_escape($policy['date_to'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?php echo html_escape($policy['notes'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary">Save as draft</button>
                <a href="<?php echo admin_url('payplex_commission/commission/source_policy'); ?>" class="btn btn-default">Cancel</a>
                <?php echo form_close(); ?>
            </div></div>
        </div></div>
    </div>
</div>
<?php init_tail(); ?>
</body>
</html>
