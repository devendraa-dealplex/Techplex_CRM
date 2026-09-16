<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-10 col-md-offset-1">
  <div class="panel_s"><div class="panel-body">

    <h4 style="color:#12507F;font-weight:600;margin:0">
      Bank Details &mdash; <?php echo html_escape(trim($member->firstname . ' ' . $member->lastname)); ?>
      <small class="text-muted">#<?php echo (int) $staff_id; ?></small>
    </h4>
    <p class="text-muted" style="margin:4px 0 14px;font-size:13px">
      The account number is encrypted at rest and only ever shown masked &mdash; to everybody,
      including the person it belongs to. Recording details and confirming them are separate acts,
      and nobody may verify their own.
    </p>

    <div class="alert alert-<?php echo $payable['ok'] ? 'success' : 'warning'; ?>" style="font-size:13px">
      <b><?php echo $payable['ok'] ? 'Payable.' : 'Not payable.'; ?></b>
      <?php echo html_escape($payable['ok'] ? 'Verified by a second person.' : $payable['reason']); ?>
    </div>

    <?php if ($account): ?>
      <table class="table" style="font-size:13px">
        <tbody>
          <tr><td style="width:200px">Beneficiary</td><td><b><?php echo html_escape($account['beneficiary_name']); ?></b></td></tr>
          <tr><td>Account</td><td><code><?php echo html_escape($account['masked_account']); ?></code></td></tr>
          <tr><td>IFSC</td><td><?php echo html_escape($account['ifsc']); ?></td></tr>
          <tr><td>Bank</td><td><?php echo html_escape($account['bank_name']); ?>
              (<?php echo html_escape($account['account_type']); ?>)</td></tr>
          <tr><td>Verification</td><td>
            <span class="label label-<?php echo $account['verification_state'] === 'verified' ? 'success'
                                          : ($account['verification_state'] === 'rejected' ? 'danger' : 'warning'); ?>">
              <?php echo html_escape($account['verification_state']); ?></span>
            <?php if ($account['verified_at']): ?>
              <small class="text-muted"><?php echo html_escape($account['verified_at']); ?></small>
            <?php endif; ?>
          </td></tr>
        </tbody>
      </table>

      <?php if ($can_verify && $account['verification_state'] !== 'verified'): ?>
        <form method="post" class="form-inline" style="margin-bottom:8px"
              action="<?php echo admin_url('payplex_staff/staff/bank_decide/' . (int) $account_id); ?>">
          <?php echo form_hidden($this->security->get_csrf_token_name(), $this->security->get_csrf_hash()); ?>
          <input type="hidden" name="decision" value="verified">
          <button class="btn btn-success btn-sm">Verify these details</button>
        </form>
      <?php endif; ?>
      <?php if ($can_verify): ?>
        <form method="post" class="form-inline"
              action="<?php echo admin_url('payplex_staff/staff/bank_decide/' . (int) $account_id); ?>">
          <?php echo form_hidden($this->security->get_csrf_token_name(), $this->security->get_csrf_hash()); ?>
          <input type="hidden" name="decision" value="rejected">
          <input type="text" name="reason" class="form-control input-sm" style="width:320px"
                 placeholder="Why (required)">
          <button class="btn btn-danger btn-sm">Reject</button>
        </form>
      <?php endif; ?>
      <?php if ($is_self && $can_verify): ?>
        <p class="text-muted" style="font-size:12px">
          These are your own details, so the server will refuse if you press either button.
        </p>
      <?php endif; ?>
    <?php else: ?>
      <p class="text-muted">No bank account on file.</p>
    <?php endif; ?>

    <?php if ($can_manage): ?>
      <h5 style="font-weight:600;margin-top:22px"><?php echo $account ? 'Replace these details' : 'Record bank details'; ?></h5>
      <form method="post" action="<?php echo admin_url('payplex_staff/staff/bank_save'); ?>">
        <?php echo form_hidden($this->security->get_csrf_token_name(), $this->security->get_csrf_hash()); ?>
        <input type="hidden" name="staff_id" value="<?php echo (int) $staff_id; ?>">
        <div class="row">
          <div class="col-md-4"><label style="font-size:12px">Beneficiary name (as the bank holds it)</label>
            <input type="text" name="beneficiary_name" class="form-control input-sm"></div>
          <div class="col-md-4"><label style="font-size:12px">Bank name</label>
            <input type="text" name="bank_name" class="form-control input-sm"></div>
          <div class="col-md-4"><label style="font-size:12px">Account type</label>
            <select name="account_type" class="form-control input-sm">
              <?php foreach (Workforce_bank::accountTypes() as $k => $v): ?>
                <option value="<?php echo html_escape($k); ?>"><?php echo html_escape($v); ?></option>
              <?php endforeach; ?>
            </select></div>
        </div>
        <div class="row" style="margin-top:8px">
          <div class="col-md-4"><label style="font-size:12px">Account number</label>
            <input type="text" name="account_number" class="form-control input-sm" autocomplete="off"></div>
          <div class="col-md-4"><label style="font-size:12px">Account number again</label>
            <input type="text" name="account_number_confirm" class="form-control input-sm" autocomplete="off"></div>
          <div class="col-md-4"><label style="font-size:12px">IFSC</label>
            <input type="text" name="ifsc" class="form-control input-sm"></div>
        </div>
        <p class="text-muted" style="font-size:12px;margin-top:8px">
          Typed twice on purpose. A mistyped account number is the most expensive typo in this
          system and no format check catches it, because the wrong number is usually still valid.
          Saving replaces the current details and leaves them <b>unverified</b> &mdash; a verified
          account whose digits were edited afterwards is an unverified account.
        </p>
        <button class="btn btn-primary btn-sm">Save</button>
      </form>
    <?php endif; ?>

    <?php if (count($history) > 1): ?>
      <h5 style="font-weight:600;margin-top:24px">Superseded accounts</h5>
      <table class="table" style="font-size:12px">
        <thead><tr><th>#</th><th>Beneficiary</th><th>Account</th><th>IFSC</th><th>Was</th><th>Recorded</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): if ((int) $h['is_current'] === 1) { continue; } ?>
          <tr>
            <td><?php echo (int) $h['id']; ?></td>
            <td><?php echo html_escape($h['beneficiary_name']); ?></td>
            <td><code><?php echo html_escape($h['masked_account']); ?></code></td>
            <td><?php echo html_escape($h['ifsc']); ?></td>
            <td><?php echo html_escape($h['verification_state']); ?></td>
            <td><?php echo html_escape($h['created_at']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="text-muted" style="font-size:12px">
        Kept, not overwritten, so "which account was this payment actually sent to" stays answerable.
      </p>
    <?php endif; ?>

  </div></div>
</div></div></div>
<?php init_tail(); ?>
