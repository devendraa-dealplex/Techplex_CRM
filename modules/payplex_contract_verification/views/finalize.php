<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php echo payplex_cv_responsive_table_assets(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">

            <h4 class="no-mtop">
              Finalize <small>&mdash; <?php echo html_escape($contract['subject']); ?></small>
            </h4>

            <?php if (!empty($readiness['ok'])) { ?>
              <div class="alert alert-success">
                <strong>Ready to send.</strong> Every check below passed.
              </div>
            <?php } else { ?>
              <div class="alert alert-warning">
                <strong>Not ready to send yet.</strong>
                <ul class="mtop10 mbot0">
                  <?php foreach ($readiness['blockers'] as $b) { ?>
                    <li><?php echo html_escape($b['message']); ?></li>
                  <?php } ?>
                </ul>
              </div>
            <?php } ?>

            <div class="row">
              <div class="col-md-7">
                <h5>Document preview</h5>
                <div class="cv-tablewrap">
                  <iframe
                    src="<?php echo admin_url('contracts/pdf/' . (int) $contract['id'] . '?output_type=I'); ?>"
                    style="width:100%;height:640px;border:1px solid #e5e5e5;border-radius:4px;"></iframe>
                </div>
              </div>

              <div class="col-md-5">
                <p class="text-muted small">
                  Every live, mandatory invitee below is emailed their own single-use signing link
                  when you click Send. Links go out at the same time and can be completed in any
                  order; the contract is marked fully signed once every mandatory signer has
                  completed.
                </p>

                <h5>Invitees <span class="label label-default"><?php echo count($signers); ?></span></h5>
                <?php if (!$signers) { ?>
                  <p class="text-muted">No invitees on this contract yet.</p>
                <?php } else { ?>
                  <div class="cv-tablewrap"><div class="table-responsive">
                  <table class="table table-condensed">
                    <thead><tr>
                      <th>#</th><th>Name</th><th>Type</th><th>Status</th><th>Signature</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($signers as $s) { ?>
                      <tr>
                        <td><?php echo (int) $s['signing_order']; ?></td>
                        <td>
                          <?php echo html_escape($s['full_name']); ?><br>
                          <small class="text-muted"><?php echo html_escape($s['email_masked']); ?></small>
                        </td>
                        <td>
                          <?php echo (string) $s['role'] === 'reviewer'
                                ? '<span class="label label-default">reviewer</span>'
                                : '<span class="label label-primary">signer</span>'; ?>
                        </td>
                        <td><?php echo html_escape($s['invitation_label']); ?></td>
                        <td><?php echo html_escape($s['signature_label']); ?></td>
                      </tr>
                    <?php } ?>
                    </tbody>
                  </table>
                  </div><p class="cv-swipe">Swipe to view more</p></div>
                <?php } ?>

                <a class="btn btn-default"
                   href="<?php echo admin_url('payplex_contract_verification/signing/signers/'
                                              . (int) $contract['id']); ?>">View invitees</a>

                <?php if (!empty($can_send) && $request) { ?>
                  <?php echo form_open(admin_url('payplex_contract_verification/signing/send/'
                                                 . (int) $contract['id'])); ?>
                    <input type="hidden" name="confirm_send" value="SEND">
                    <button type="submit" class="btn btn-success" <?php echo empty($readiness['ok']) ? 'disabled' : ''; ?>
                            onclick="return confirm('Send this contract for signature? Invitees will receive a signing link.');">
                      Send
                    </button>
                  <?php echo form_close(); ?>
                <?php } elseif (!empty($can_send) && !$request) { ?>
                  <p class="text-muted small mtop15">
                    This contract has not been prepared for signing yet (no signing request exists).
                    <a href="<?php echo admin_url('payplex_contract_verification/signing/contract/'
                                                  . (int) $contract['id']); ?>">Go to Contract Verification</a>
                    to prepare and approve it first.
                  </p>
                <?php } ?>
              </div>
            </div>

            <hr>
            <a class="btn btn-default"
               href="<?php echo admin_url('payplex_contract_verification/signing/signers/'
                                          . (int) $contract['id']); ?>">Back to Invite</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
