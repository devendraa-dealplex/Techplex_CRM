<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop">
        <?php echo html_escape($title); ?>
        <?php if (!empty($contract['subject'])) { ?>
          &mdash; <?php echo html_escape((string) $contract['subject']); ?>
        <?php } ?>
      </h4>

      <?php if (empty($status['provider']['implemented'])) { ?>
        <div class="alert alert-warning">
          <strong>Sending is not available.</strong>
          <?php echo html_escape((string) $status['provider']['detail']); ?>
          Preparation, verification, field placement and internal approval all work and are
          recorded; only the send step is blocked.
        </div>
      <?php } ?>

      <?php if ($request) { ?>
        <p>
          Status:
          <strong><?php echo html_escape(isset($states[$request['state']])
              ? $states[$request['state']]['label'] : (string) $request['state']); ?></strong>
          <?php if (!empty($request['environment'])) { ?>
            <span class="label label-default"><?php echo html_escape((string) $request['environment']); ?></span>
          <?php } ?>
        </p>
        <p class="text-muted small">
          <?php echo html_escape(isset($states[$request['state']])
              ? $states[$request['state']]['means'] : ''); ?>
        </p>

        <?php if ($execution) { ?>
          <?php if (!empty($execution['executed'])) { ?>
            <div class="alert alert-success">
              <strong>Fully executed.</strong> Every mandatory signer completed, and the signed
              document and completion certificate are both stored and hash-verified.
            </div>
          <?php } else { ?>
            <div class="alert alert-info">
              <strong>Not yet fully executed.</strong>
              <?php echo html_escape(str_replace('_', ' ', (string) $execution['reason'])); ?>.
              <?php if (!empty($execution['missing'])) { ?>
                Outstanding: <?php echo html_escape(implode(', ', $execution['missing'])); ?>.
              <?php } ?>
              <br><span class="text-muted small">
                "Completed" is the provider's view of its workflow. "Fully executed" additionally
                requires the signed PDF and the certificate to be retrieved and verified.
              </span>
            </div>
          <?php } ?>
        <?php } ?>
      <?php } else { ?>
        <div class="alert alert-default" style="border:1px solid #eee">
          No signing request has been prepared for this contract.
        </div>
      <?php } ?>
    </div></div>
  </div></div>

  <div class="row"><div class="col-md-6">
    <div class="panel_s"><div class="panel-body">
      <h5 class="no-mtop">Preparation</h5>

      <?php if (!empty($can_prepare)) { ?>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/verify_customer/'
                                       . (int) $contract['id']), array('class' => 'mbot15')); ?>
          <select name="verification_type" class="form-control" style="max-width:180px;display:inline-block">
            <option value="pan">PAN</option>
            <option value="gst">GST</option>
            <option value="aadhaar">Aadhaar</option>
            <option value="business">Business</option>
          </select>
          <select name="outcome" class="form-control" style="max-width:130px;display:inline-block">
            <option value="passed">Passed</option>
            <option value="failed">Failed</option>
            <option value="pending">Pending</option>
          </select>
          <input type="text" name="masked_reference" class="form-control"
                 style="max-width:160px;display:inline-block" maxlength="40"
                 placeholder="Masked reference">
          <button type="submit" class="btn btn-default">Record verification</button>
          <p class="text-muted small">
            Record a masked reference only &mdash; the last few characters. A complete identity
            number is refused rather than truncated.
          </p>
        <?php echo form_close(); ?>

        <?php echo form_open(admin_url('payplex_contract_verification/signing/record_video_kyc/'
                                       . (int) $contract['id']), array('class' => 'mbot15')); ?>
          <input type="hidden" name="outcome" value="passed">
          <button type="submit" class="btn btn-default">Manual Video KYC Attestation</button>
          <p class="text-muted small">
            This records a staff declaration only; it does not independently verify identity.
            No video session takes place, nothing is recorded, and no liveness or document check
            is performed. It is not completed Video KYC, and it must not be presented as one.
          </p>
        <?php echo form_close(); ?>

        <a class="btn btn-info"
           href="<?php echo admin_url('payplex_contract_verification/signing/signers/'
                                      . (int) $contract['id']); ?>">Signers</a>

        <a class="btn btn-info"
           href="<?php echo admin_url('payplex_contract_verification/signing/fields_editor/'
                                      . (int) $contract['id']); ?>">Place signature fields</a>

        <a class="btn btn-default"
           href="<?php echo admin_url('payplex_contract_verification/signing/preview_fields/'
                                      . (int) $contract['id']); ?>">Preview signature fields</a>

        <?php echo form_open(admin_url('payplex_contract_verification/signing/submit_for_approval/'
                                       . (int) $contract['id']), array('style' => 'display:inline')); ?>
          <button type="submit" class="btn btn-info">Submit for internal approval</button>
        <?php echo form_close(); ?>
      <?php } else { ?>
        <p class="text-muted">You do not have permission to prepare contracts for signing.</p>
      <?php } ?>
    </div></div>
  </div>

  <div class="col-md-6">
    <div class="panel_s"><div class="panel-body">
      <h5 class="no-mtop">Approval and sending</h5>

      <?php if (!empty($can_approve) && $request) { ?>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/approve_for_signing/'
                                       . (int) $contract['id']), array('style' => 'display:inline')); ?>
          <button type="submit" class="btn btn-default">Approve for signing</button>
        <?php echo form_close(); ?>
        <p class="text-muted small">
          Whoever submitted this contract cannot also approve it &mdash; including an administrator.
        </p>
      <?php } ?>

      <?php if (!empty($can_send) && $request) { ?>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/send/'
                                       . (int) $contract['id'])); ?>
          <input type="hidden" name="confirm_send" value="SEND">
          <button type="submit" class="btn btn-info"
                  onclick="return confirm('Send this contract for signature? The customer will receive a signing link.');">
            Send through the provider
          </button>
        <?php echo form_close(); ?>
      <?php } ?>

      <?php if ($request && !empty($is_admin)) { ?>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/sync_status/'
                                       . (int) $contract['id']), array('style' => 'display:inline')); ?>
          <button type="submit" class="btn btn-default">Sync status from provider</button>
        <?php echo form_close(); ?>
      <?php } ?>

      <?php if (!empty($can_cancel) && $request) { ?>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/cancel/'
                                       . (int) $contract['id']), array('class' => 'mtop15')); ?>
          <input type="hidden" name="confirm_cancel" value="CANCEL">
          <input type="text" name="reason" class="form-control" maxlength="500"
                 placeholder="Reason for cancelling (required)">
          <button type="submit" class="btn btn-danger mtop15"
                  onclick="return confirm('Cancel this signing request? It cannot be revived; a new request would have to be created.');">
            Cancel signing request
          </button>
        <?php echo form_close(); ?>
      <?php } ?>
    </div></div>
  </div></div>

  <?php if (!empty($can_evidence) && $request) { ?>
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h5 class="no-mtop">Evidence</h5>
      <a class="btn btn-default"
         href="<?php echo admin_url('payplex_contract_verification/signing/signer_timeline/'
                                    . (int) $contract['id']); ?>">View signer timeline</a>
      <a class="btn btn-default"
         href="<?php echo admin_url('payplex_contract_verification/signing/download_signed/'
                                    . (int) $contract['id']); ?>">Download signed agreement</a>
      <a class="btn btn-default"
         href="<?php echo admin_url('payplex_contract_verification/signing/download_certificate/'
                                    . (int) $contract['id']); ?>">Download completion certificate</a>
      <p class="text-muted small mtop15">
        Evidence files are stored outside the web root and served only through these
        permission-checked actions. Every download is recorded.
      </p>
    </div></div>
  </div></div>
  <?php } ?>

  <?php if ($signers) { ?>
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h5 class="no-mtop">Signers</h5>
      <div class="table-responsive">
        <table class="table table-striped">
          <thead><tr>
            <th>Name</th><th class="hidden-xs">Role</th><th class="hidden-xs">Order</th>
            <th>Status</th><th class="hidden-xs">Completed</th>
          </tr></thead>
          <tbody>
          <?php foreach ($signers as $s) { ?>
            <tr>
              <td><?php echo html_escape((string) $s['full_name']); ?></td>
              <td class="hidden-xs"><?php echo html_escape((string) $s['role']); ?></td>
              <td class="hidden-xs"><?php echo (int) $s['signing_order']; ?></td>
              <td><?php echo html_escape((string) $s['state']); ?></td>
              <td class="hidden-xs">
                <?php echo $s['completed_at']
                      ? html_escape(_dt(date('Y-m-d H:i:s', (int) $s['completed_at']))) : '&mdash;'; ?>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
    </div></div>
  </div></div>
  <?php } ?>

</div></div>
<?php init_tail(); ?>
</body></html>
