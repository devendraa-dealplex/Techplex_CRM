<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
  $cls = array('draft'=>'default','submitted'=>'info','manager_review'=>'info','finance_review'=>'info',
               'approved'=>'success','rejected'=>'danger','payout_processing'=>'warning',
               'paid'=>'success','failed'=>'danger','reversed'=>'danger','cancelled'=>'default');
?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-11 col-md-offset-1">
  <div class="panel_s"><div class="panel-body">

    <h4 style="color:#12507F;font-weight:600;margin:0">
      <?php echo html_escape($claim['claim_ref']); ?>
      <span class="label label-<?php echo $cls[$claim['state']] ?? 'default'; ?>" style="font-size:12px">
        <?php echo html_escape(Workforce_expense::label($claim['state'])); ?></span>
    </h4>
    <p class="text-muted" style="margin:4px 0 14px">
      <?php echo html_escape(trim(($claimant->firstname ?? '') . ' ' . ($claimant->lastname ?? ''))); ?>
      (#<?php echo (int) $claim['claimant_id']; ?>)
      <?php if ((int) $claim['claimant_id'] === (int) $me): ?>
        &middot; <b>this is your own claim, so you cannot review, approve or pay it</b>
      <?php endif; ?>
      <?php if ($ageing['waiting']): ?>
        &middot; waiting <?php echo (int) $ageing['days']; ?> day(s) on <?php echo html_escape($ageing['with']); ?>
      <?php endif; ?>
    </p>

    <div class="row" style="margin-bottom:14px">
      <div class="col-md-3"><div style="border:1px solid #E4E9F0;border-radius:4px;padding:12px;background:#F7F9FC">
        <div style="font-size:24px;font-weight:600;color:#12507F">
          <?php echo number_format((float) $claim['amount'], 2); ?></div>
        <div class="text-muted" style="font-size:12px"><?php echo html_escape($claim['currency']); ?>
          <?php if ((float) $claim['tax_amount'] > 0): ?>
            (incl. tax <?php echo number_format((float) $claim['tax_amount'], 2); ?>)
          <?php endif; ?>
        </div>
      </div></div>
      <div class="col-md-3"><div style="border:1px solid #E4E9F0;border-radius:4px;padding:12px">
        <div style="font-weight:600"><?php echo html_escape(Workforce_expense::categories()[$claim['category']] ?? $claim['category']); ?></div>
        <div class="text-muted" style="font-size:12px">spent <?php echo html_escape($claim['expense_date']); ?></div>
      </div></div>
      <div class="col-md-3"><div style="border:1px solid #E4E9F0;border-radius:4px;padding:12px">
        <div style="font-weight:600"><?php echo html_escape(Workforce_expense::payableKinds()[$claim['payable_kind']]['label'] ?? $claim['payable_kind']); ?></div>
        <div class="text-muted" style="font-size:12px">
          TDS: <?php echo Workforce_expense::tdsTreatment($claim['payable_kind']) === 'not_applicable'
                ? 'not applicable — repaying a cost is not income'
                : 'as configured for this person'; ?>
        </div>
      </div></div>
      <div class="col-md-3"><div style="border:1px solid #E4E9F0;border-radius:4px;padding:12px">
        <div style="font-weight:600;color:<?php echo $bank['ok'] ? '#2E7D32' : '#B26A00'; ?>">
          <?php echo $bank['ok'] ? 'Payable' : 'Not payable'; ?></div>
        <div class="text-muted" style="font-size:12px"><?php echo html_escape($bank['ok'] ? 'bank details verified' : $bank['reason']); ?></div>
      </div></div>
    </div>

    <p><b>Purpose:</b> <?php echo html_escape($claim['purpose']); ?></p>
    <?php if ($claim['decided_reason']): ?>
      <div class="alert alert-warning" style="font-size:13px">
        <b>Last decision:</b> <?php echo html_escape($claim['decided_reason']); ?>
      </div>
    <?php endif; ?>

    <p class="text-muted" style="font-size:12px">
      <b>Chain so far:</b>
      manager <?php echo $claim['manager_reviewer_id'] ? '#' . (int) $claim['manager_reviewer_id'] : '&mdash;'; ?> &middot;
      finance <?php echo $claim['finance_reviewer_id'] ? '#' . (int) $claim['finance_reviewer_id'] : '&mdash;'; ?> &middot;
      approver <?php echo $claim['approver_id'] ? '#' . (int) $claim['approver_id'] : '&mdash;'; ?>
      <?php if (!$bill['allowed']): ?>
        <br><b>Bill:</b> <?php echo html_escape($bill['reason']); ?>
      <?php endif; ?>
    </p>

    <h5 style="font-weight:600;margin-top:20px">What can happen next</h5>
    <?php if (!$moves): ?>
      <p class="text-muted">Nothing. This claim is in a terminal state.</p>
    <?php endif; ?>
    <table class="table" style="font-size:13px">
      <tbody>
      <?php foreach ($moves as $to => $g): ?>
        <tr>
          <td style="width:230px"><b><?php echo html_escape(Workforce_expense::label($to)); ?></b></td>
          <td>
            <?php if ($g['allowed']): ?>
              <form method="post" class="form-inline"
                    action="<?php echo admin_url('payplex_staff/staff/expense_transition/' . (int) $claim['id']); ?>">
                <?php echo form_hidden($this->security->get_csrf_token_name(), $this->security->get_csrf_hash()); ?>
                <input type="hidden" name="to" value="<?php echo html_escape($to); ?>">
                <?php if (Workforce_expense::needsReason($claim['state'], $to)): ?>
                  <input type="text" name="reason" class="form-control input-sm" style="width:340px"
                         placeholder="Why (required)">
                <?php endif; ?>
                <button class="btn btn-primary btn-sm">Move to <?php echo html_escape(Workforce_expense::label($to)); ?></button>
              </form>
            <?php else: ?>
              <span class="label label-default"><?php echo html_escape($g['code']); ?></span>
              <small class="text-muted"> <?php echo html_escape($g['reason']); ?></small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="text-muted" style="font-size:12px">
      Every refusal above is the same check the server applies to a direct request. The buttons
      that are missing are not the control &mdash; the control is that the server says no.
    </p>

    <h5 style="font-weight:600;margin-top:22px">Audit trail</h5>
    <p class="text-muted" style="font-size:12px;margin-top:-6px">
      Insert-only. Refusals are recorded as well as decisions: an approval that never happened
      leaves no other trace.
    </p>
    <table class="table table-striped" style="font-size:12px">
      <thead><tr><th>When</th><th>Action</th><th>From</th><th>To</th><th>By</th><th>Amount</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach ($events as $e): ?>
        <tr>
          <td><?php echo html_escape($e['occurred_at']); ?></td>
          <td><span class="label label-<?php echo $e['action'] === 'refused' ? 'danger' : 'default'; ?>">
              <?php echo html_escape($e['action']); ?></span></td>
          <td><?php echo html_escape($e['from_state'] ?: '—'); ?></td>
          <td><?php echo html_escape($e['to_state'] ?: '—'); ?></td>
          <td>#<?php echo (int) $e['actor_id']; ?></td>
          <td><?php echo $e['amount_after'] !== null ? number_format((float) $e['amount_after'], 2) : '—'; ?></td>
          <td><?php echo html_escape($e['reason'] ?: ''); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

  </div></div>
</div></div></div>
<?php init_tail(); ?>
