<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
  $cls = array('draft'=>'default','submitted'=>'info','manager_review'=>'info','finance_review'=>'info',
               'approved'=>'success','rejected'=>'danger','payout_processing'=>'warning',
               'paid'=>'success','failed'=>'danger','reversed'=>'danger','cancelled'=>'default');
  $bcls = array('under_3d'=>'default','3_7d'=>'info','7_14d'=>'warning','over_14d'=>'danger');
?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">

    <h4 style="color:#12507F;font-weight:600;margin:0 0 4px">Expense Claims</h4>
    <p class="text-muted" style="font-size:13px">
      <?php echo $view_all ? 'Every claim in the workforce.' : 'Your claims.'; ?>
      A claim needs a manager review, a finance review and an approval, and no two of those
      may be the same person &mdash; nor the claimant, administrators included.
    </p>

    <?php if (!$payable_hidden = false): ?>
      <div class="alert alert-<?php echo $bank['ok'] ? 'success' : 'warning'; ?>" style="font-size:13px">
        <b>Your bank details:</b> <?php echo $bank['ok'] ? 'verified and payable.' : html_escape($bank['reason']); ?>
        <?php if (!$bank['ok']): ?>
          <a href="<?php echo admin_url('payplex_staff/staff/bank'); ?>">Record them</a> &mdash;
          a claim can be approved without them, but it cannot be paid.
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($view_all && $ageing['total_waiting'] > 0): ?>
      <h5 style="font-weight:600;margin-top:18px">Waiting for a decision</h5>
      <p class="text-muted" style="font-size:12px;margin-top:-6px">
        A pending list sorted by date does not say who is holding things up. This does.
      </p>
      <p>
        <?php foreach ($ageing['buckets'] as $b => $n): ?>
          <span class="label label-<?php echo $bcls[$b]; ?>" style="font-size:12px;margin-right:6px">
            <?php echo html_escape(Workforce_expense::ageingBuckets()[$b]); ?>: <b><?php echo (int) $n; ?></b>
          </span>
        <?php endforeach; ?>
      </p>
      <table class="table" style="font-size:12px">
        <thead><tr><th>Claim</th><th>Claimant</th><th>Amount</th><th>Waiting on</th><th>Days</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($ageing['rows'], 0, 10) as $r): ?>
          <tr>
            <td><a href="<?php echo admin_url('payplex_staff/staff/expense/' . (int) $r['id']); ?>">
                <?php echo html_escape($r['claim_ref']); ?></a></td>
            <td>#<?php echo (int) $r['claimant_id']; ?></td>
            <td><?php echo number_format((float) $r['amount'], 2); ?></td>
            <td><?php echo html_escape($r['ageing']['with']); ?></td>
            <td><span class="label label-<?php echo $bcls[$r['ageing']['bucket']]; ?>">
                <?php echo (int) $r['ageing']['days']; ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($can['submit']): ?>
      <h5 style="font-weight:600;margin-top:20px">Raise a claim</h5>
      <form method="post" class="form-inline" action="<?php echo admin_url('payplex_staff/staff/expense_new'); ?>">
        <?php echo form_hidden($this->security->get_csrf_token_name(), $this->security->get_csrf_hash()); ?>
        <select name="category" class="form-control input-sm">
          <?php foreach (Workforce_expense::categories() as $k => $v): ?>
            <option value="<?php echo html_escape($k); ?>"><?php echo html_escape($v); ?></option>
          <?php endforeach; ?>
        </select>
        <select name="payable_kind" class="form-control input-sm">
          <?php foreach (Workforce_expense::payableKinds() as $k => $v): ?>
            <option value="<?php echo html_escape($k); ?>"><?php echo html_escape($v['label']); ?></option>
          <?php endforeach; ?>
        </select>
        <input type="number" step="0.01" name="amount" class="form-control input-sm" style="width:110px" placeholder="Amount">
        <input type="number" step="0.01" name="tax_amount" class="form-control input-sm" style="width:90px" placeholder="Tax">
        <input type="date" name="expense_date" class="form-control input-sm" title="Date the money was spent">
        <input type="text" name="purpose" class="form-control input-sm" style="width:300px" placeholder="What was it for?">
        <?php if ($view_all): ?>
          <input type="number" name="claimant_id" class="form-control input-sm" style="width:110px" placeholder="For staff #">
        <?php endif; ?>
        <button class="btn btn-primary btn-sm">Create draft</button>
      </form>
    <?php endif; ?>

    <h5 style="font-weight:600;margin-top:22px">Claims</h5>
    <table class="table table-striped" style="font-size:13px">
      <thead><tr>
        <th>Ref</th><th>Claimant</th><th>Category</th><th>Kind</th><th>Date</th>
        <th>Amount</th><th>State</th><th>Waiting</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$claims): ?>
        <tr><td colspan="9" class="text-muted">No claims yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($claims as $c): $a = Workforce_expense::ageing($c); ?>
        <tr>
          <td><a href="<?php echo admin_url('payplex_staff/staff/expense/' . (int) $c['id']); ?>">
              <?php echo html_escape($c['claim_ref']); ?></a></td>
          <td>#<?php echo (int) $c['claimant_id']; ?><?php echo (int) $c['claimant_id'] === (int) $me ? ' <span class="text-muted">(you)</span>' : ''; ?></td>
          <td><?php echo html_escape(Workforce_expense::categories()[$c['category']] ?? $c['category']); ?></td>
          <td><?php echo html_escape(Workforce_expense::payableKinds()[$c['payable_kind']]['label'] ?? $c['payable_kind']); ?></td>
          <td><?php echo html_escape($c['expense_date']); ?></td>
          <td><b><?php echo number_format((float) $c['amount'], 2); ?></b></td>
          <td><span class="label label-<?php echo $cls[$c['state']] ?? 'default'; ?>">
              <?php echo html_escape(Workforce_expense::label($c['state'])); ?></span></td>
          <td><?php echo $a['waiting'] ? ((int) $a['days'] . 'd &middot; ' . html_escape($a['with'])) : '&mdash;'; ?></td>
          <td><a class="btn btn-default btn-xs"
                 href="<?php echo admin_url('payplex_staff/staff/expense/' . (int) $c['id']); ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

  </div></div>
</div></div></div>
<?php init_tail(); ?>
