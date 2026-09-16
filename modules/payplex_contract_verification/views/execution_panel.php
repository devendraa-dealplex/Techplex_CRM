<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * Execution & Video KYC panel.
 *
 * WHAT THIS SCREEN IS FOR
 * -----------------------
 * Answering, in one place, the question somebody actually has: why is this
 * contract not finished, and what can I do about it?
 *
 * So the blockers come FIRST, above the timeline and above the buttons, each
 * spelled out as a sentence. A panel that shows a grey "Completed" button with
 * no explanation is a panel that gets escalated, and the escalation ends with
 * somebody being given a way to override the gate.
 *
 * WHAT IT DELIBERATELY DOES NOT SHOW
 * ----------------------------------
 *   - no API token, salt or webhook secret, in any form, ever;
 *   - no verification link — those are single-use bearer credentials and are
 *     unrecoverable by design, including by an administrator;
 *   - no unmasked recipient in the delivery history;
 *   - no control that sets a KYC outcome. There is no such control anywhere,
 *     and this is where one would be added if it were ever going to be.
 */
?>
<?php init_head(); ?>
<?php echo payplex_cv_responsive_table_assets(); ?>
<div id="wrapper"><div class="content">

  <div class="row"><div class="col-md-12">
    <div class="panel_s cv-exec"><div class="panel-body">


      <h4 class="no-mtop">
        <?php echo html_escape((string) $title); ?>
        <?php if (!empty($contract['subject'])) { ?>
          &mdash; <?php echo html_escape((string) $contract['subject']); ?>
        <?php } ?>
      </h4>

      <?php /* ---- the lifecycle, and what it means ---- */ ?>
      <p>
        Status:
        <strong><?php echo html_escape((string) $lifecycle_label); ?></strong>
        <span class="label label-default"><?php echo html_escape((string) $environment); ?></span>
      </p>
      <p class="text-muted small"><?php echo html_escape((string) $lifecycle_means); ?></p>

      <?php /* ---- WHY IT IS NOT FINISHED. Above everything else. ---- */ ?>
      <?php if (!empty($completion_blockers)) { ?>
        <div class="alert alert-warning">
          <strong>This agreement is not complete.</strong>
          <ul class="mtop10 mbot0">
            <?php foreach ($completion_blockers as $blocker) { ?>
              <li><?php echo html_escape((string) $blocker['message']); ?></li>
            <?php } ?>
          </ul>
        </div>
      <?php } else { ?>
        <div class="alert alert-success">
          <strong>Every completion condition is satisfied.</strong>
          Signatures, verification and evidence are all in and hash-verified.
        </div>
      <?php } ?>

      <?php /* ---- provider health ---- */ ?>
      <?php if (empty($provider_health['healthy'])) { ?>
        <div class="alert alert-info">
          <strong>Signing provider: <?php echo html_escape((string) $provider_health['label']); ?>.</strong>
          <?php echo html_escape((string) $provider_health['detail']); ?>
        </div>
      <?php } ?>

      <?php if (empty($kyc_provider['implemented'])) { ?>
        <div class="alert alert-warning">
          <strong>Video KYC is not available.</strong>
          <?php echo html_escape((string) $kyc_provider['detail']); ?>
        </div>
      <?php } ?>

      <?php /* ---- the workflow this contract is bound to ---- */ ?>
      <h5>Signing workflow</h5>
      <?php if (empty($profile)) { ?>
        <p class="text-danger">
          No Leegality workflow is configured for this contract, so nothing can be sent.
          An administrator has to set one before this contract can go out.
        </p>
      <?php } else { ?>
        <div class="cv-tablewrap"><div class="table-responsive">
        <table class="table table-condensed">
          <tr><td>Workflow</td>
              <td><?php echo html_escape((string) $profile['label']); ?></td></tr>
          <tr><td>Signing order</td>
              <td><?php echo html_escape((string) $profile['ordering_mode']); ?></td></tr>
          <tr><td>Authentication</td>
              <td>
                <?php echo html_escape((string) $profile['auth_method']); ?>
                <?php if (!empty($auth_is_provider_owned)) { ?>
                  <span class="text-muted small">
                    &mdash; run by the signing provider. This module does not run its own.
                  </span>
                <?php } ?>
              </td></tr>
          <tr><td>Video KYC policy</td>
              <td><?php echo html_escape((string) $profile['kyc_policy']); ?></td></tr>
          <tr><td>Validated</td>
              <td>
                <?php if (empty($profile['validated_at'])) { ?>
                  <span class="text-warning">
                    Never validated. The workflow ID has been entered but never proven to work.
                  </span>
                <?php } else { ?>
                  <?php echo html_escape((string) $profile_validated_on); ?>
                <?php } ?>
              </td></tr>
        </table>
        </div><p class="cv-swipe">Swipe to view more</p></div>
      <?php } ?>

      <?php /* ---- can this be sent at all? ---- */ ?>
      <?php if (!empty($mapping_blockers)) { ?>
        <div class="alert alert-danger">
          <strong>The roster does not match this workflow, so it cannot be sent.</strong>
          <p class="small mtop10">
            The workflow decides the signing order and the request cannot override it. Sending a
            roster that does not match would not fail &mdash; it would quietly ask the wrong person
            to sign first.
          </p>
          <ul class="mbot0">
            <?php foreach ($mapping_blockers as $blocker) { ?>
              <li><?php echo html_escape((string) $blocker['message']); ?></li>
            <?php } ?>
          </ul>
        </div>
      <?php } ?>

      <?php /* ---- document integrity ---- */ ?>
      <h5>Document</h5>
      <div class="cv-tablewrap"><div class="table-responsive">
      <table class="table table-condensed">
        <tr><td>Approved version</td>
            <td><?php echo html_escape((string) $document['version']); ?></td></tr>
        <tr><td>Approved hash</td>
            <td><code><?php echo html_escape((string) $document['sha256_short']); ?></code></td></tr>
        <tr><td>Unchanged since approval</td>
            <td>
              <?php if (!empty($document['unchanged'])) { ?>
                Yes
              <?php } else { ?>
                <span class="text-danger">
                  No &mdash; what was signed is not what was approved.
                </span>
              <?php } ?>
            </td></tr>
      </table>
      </div><p class="cv-swipe">Swipe to view more</p></div>

      <?php /* ---- signers ---- */ ?>
      <h5>Signers</h5>
      <div class="cv-tablewrap"><div class="table-responsive">
      <table class="table table-condensed">
        <thead><tr>
          <th>#</th><th>Name</th><th>Role</th><th>Invitation</th>
          <th>Authentication</th><th>Signature</th><th>Video KYC</th>
        </tr></thead>
        <tbody>
        <?php foreach ($signer_rows as $row) { ?>
          <tr>
            <td><?php echo html_escape((string) $row['signing_order']); ?></td>
            <td>
              <?php echo html_escape((string) $row['full_name']); ?>
              <div class="text-muted small"><?php echo html_escape((string) $row['email_masked']); ?></div>
            </td>
            <td><?php echo html_escape((string) $row['role']); ?></td>
            <td><?php echo html_escape((string) $row['invitation_label']); ?></td>
            <td><?php echo html_escape((string) $row['auth_label']); ?></td>
            <td><?php echo html_escape((string) $row['signature_label']); ?></td>
            <td>
              <?php echo html_escape((string) $row['kyc_label']); ?>
              <?php if (!empty($row['kyc_reject_reason'])) { ?>
                <div class="text-muted small">
                  <?php echo html_escape((string) $row['kyc_reject_reason']); ?>
                </div>
              <?php } ?>
            </td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
      </div><p class="cv-swipe">Swipe to view more</p></div>

      <?php /* ---- KYC delivery. Masked, and no link anywhere. ---- */ ?>
      <?php if (!empty($deliveries)) { ?>
        <h5>Verification invitations sent</h5>
        <p class="text-muted small">
          The link itself is not shown. It is single-use and stored only as a hash, so it cannot be
          recovered by anyone &mdash; including an administrator. Resending issues a new link and
          revokes the previous one.
        </p>
        <div class="cv-tablewrap"><div class="table-responsive">
        <table class="table table-condensed">
          <thead><tr>
            <th>When</th><th>Channel</th><th>To</th><th>Result</th><th>Expires</th>
          </tr></thead>
          <tbody>
          <?php foreach ($deliveries as $row) { ?>
            <tr>
              <td><?php echo html_escape((string) $row['queued_on']); ?></td>
              <td><?php echo html_escape((string) $row['channel']); ?></td>
              <td><?php echo html_escape((string) $row['recipient_masked']); ?></td>
              <td><?php echo html_escape((string) $row['status_label']); ?></td>
              <td><?php echo html_escape((string) $row['expires_on']); ?></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        </div><p class="cv-swipe">Swipe to view more</p></div>
      <?php } ?>

      <?php /* ---- unresolved failures ---- */ ?>
      <?php if (!empty($failures)) { ?>
        <h5>Unresolved failures</h5>
        <div class="cv-tablewrap"><div class="table-responsive">
        <table class="table table-condensed">
          <thead><tr><th>Operation</th><th>What happened</th><th>Attempted</th><th>Next attempt</th></tr></thead>
          <tbody>
          <?php foreach ($failures as $row) { ?>
            <tr>
              <td><?php echo html_escape((string) $row['operation']); ?></td>
              <td><?php echo html_escape((string) $row['operator_message']); ?></td>
              <td>
                <?php if (empty($row['attempted'])) { ?>
                  <span class="text-muted">
                    Not sent &mdash; the provider was never asked, so nothing was duplicated.
                  </span>
                <?php } else { ?>
                  Yes
                <?php } ?>
              </td>
              <td><?php echo html_escape((string) $row['next_attempt_on']); ?></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        </div><p class="cv-swipe">Swipe to view more</p></div>
      <?php } ?>

      <?php /* ---- evidence ---- */ ?>
      <h5>Evidence</h5>
      <div class="cv-tablewrap"><div class="table-responsive">
      <table class="table table-condensed">
        <?php foreach ($evidence_rows as $row) { ?>
          <tr>
            <td><?php echo html_escape((string) $row['label']); ?></td>
            <td>
              <?php if (empty($row['stored'])) { ?>
                <span class="text-warning">Not retrieved</span>
              <?php } elseif (empty($row['verified'])) { ?>
                <span class="text-warning">Stored, hash not verified</span>
              <?php } else { ?>
                Stored and hash-verified
                <code><?php echo html_escape((string) $row['sha256_short']); ?></code>
              <?php } ?>
            </td>
            <td>
              <?php if (!empty($row['stored']) && !empty($can_evidence)) { ?>
                <a href="<?php echo html_escape((string) $row['download_url']); ?>">Download</a>
              <?php } ?>
            </td>
          </tr>
        <?php } ?>
      </table>
      </div><p class="cv-swipe">Swipe to view more</p></div>

      <?php /* ---- the timeline ---- */ ?>
      <h5>Timeline</h5>
      <div class="cv-tablewrap"><div class="table-responsive">
      <table class="table table-condensed">
        <tbody>
        <?php foreach ($timeline as $row) { ?>
          <tr>
            <td class="cv-when"><?php echo html_escape((string) $row['when']); ?></td>
            <td><?php echo html_escape((string) $row['what']); ?></td>
            <td class="text-muted small"><?php echo html_escape((string) $row['who']); ?></td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
      </div><p class="cv-swipe">Swipe to view more</p></div>

      <?php /* ---- actions, every one permission-gated server-side ---- */ ?>
      <h5>Actions</h5>
      <p class="text-muted small">
        Hiding a button is not a permission check. Every action below is re-checked on the server,
        against this specific contract, when it is used. A button is shown only when its route
        exists, the lifecycle permits it, and this person holds the capability.
      </p>

      <?php if (!empty($can_settings)) { ?>
        <a class="btn btn-default btn-sm"
           href="<?php echo admin_url('payplex_contract_verification/signing/profile_map'); ?>">
          Workflow mapping
        </a>
        <a class="btn btn-default btn-sm"
           href="<?php echo admin_url('payplex_contract_verification/signing/kyc_config'); ?>">
          Video KYC configuration
        </a>
      <?php } ?>

      <?php if (!empty($can_send) && !empty($may_prepare)) { ?>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/prepare_request/'
                                       . (int) $contract['id']), array('class' => 'dinline')); ?>
          <button type="submit" class="btn btn-default btn-sm" data-cv-once="1">
            Prepare signing request
          </button>
        <?php echo form_close(); ?>
      <?php } ?>

      <?php if (!empty($can_send) && !empty($may_send)) { ?>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/send_for_signature/'
                                       . (int) $contract['id']), array('class' => 'dinline')); ?>
          <button type="submit" class="btn btn-primary btn-sm" data-cv-once="1"
                  onclick="return confirm('Send this contract to the signing provider? This issues signing links to the signers.');">
            Send for signature
          </button>
        <?php echo form_close(); ?>
      <?php } ?>

      <?php if (!empty($can_send) && !empty($may_resend_invitation)) { ?>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/resend_invitation/'
                                       . (int) $contract['id']), array('class' => 'dinline')); ?>
          <input type="hidden" name="reference"
                 value="<?php echo html_escape((string) $resend_reference); ?>">
          <button type="submit" class="btn btn-default btn-sm" data-cv-once="1">
            Resend invitation
          </button>
        <?php echo form_close(); ?>
      <?php } ?>

      <?php if (!empty($can_view)) { ?>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/reconcile/'
                                       . (int) $contract['id']), array('class' => 'dinline')); ?>
          <button type="submit" class="btn btn-default btn-sm" data-cv-once="1">
            Reconcile with provider
          </button>
        <?php echo form_close(); ?>
      <?php } ?>

      <?php if (!empty($can_view)) { ?>
        <a class="btn btn-default btn-sm"
           href="<?php echo admin_url('payplex_contract_verification/signing/failures/'
                                      . (int) $contract['id']); ?>">
          Failures &amp; retries
        </a>
      <?php } ?>

      <?php /* ---- Video KYC, per session ---- */ ?>
      <?php if (!empty($kyc_sessions)) { ?>
        <h5 class="mtop20">Video KYC sessions</h5>
        <div class="cv-tablewrap"><div class="table-responsive">
        <table class="table table-condensed">
          <thead><tr><th>Signer</th><th>Attempt</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($kyc_sessions as $session) { ?>
            <tr>
              <td><?php echo html_escape((string) $session['signer_masked']); ?></td>
              <td><?php echo html_escape((string) $session['attempt']); ?></td>
              <td><?php echo html_escape((string) $session['state_label']); ?></td>
              <td>
                <?php if (!empty($can_kyc_review) && !empty($session['may_invite'])) { ?>
                  <?php echo form_open(admin_url('payplex_contract_verification/signing/kyc_invite/'
                                                 . (int) $contract['id']), array('class' => 'dinline')); ?>
                    <input type="hidden" name="kyc_session_id"
                           value="<?php echo (int) $session['id']; ?>">
                    <input type="hidden" name="channel" value="email">
                    <button type="submit" class="btn btn-default btn-xs" data-cv-once="1">
                      Send KYC link
                    </button>
                  <?php echo form_close(); ?>
                <?php } ?>

                <?php if (!empty($can_kyc_retry) && !empty($session['may_retry'])) { ?>
                  <?php echo form_open(admin_url('payplex_contract_verification/signing/kyc_retry/'
                                                 . (int) $contract['id']), array('class' => 'dinline')); ?>
                    <input type="hidden" name="kyc_session_id"
                           value="<?php echo (int) $session['id']; ?>">
                    <input type="hidden" name="reason" value="staff requested another attempt">
                    <button type="submit" class="btn btn-default btn-xs" data-cv-once="1">Retry</button>
                  <?php echo form_close(); ?>
                <?php } ?>

                <?php if (!empty($can_kyc_review) && !empty($session['may_review'])) { ?>
                  <?php echo form_open(admin_url('payplex_contract_verification/signing/kyc_manual_review/'
                                                 . (int) $contract['id']), array('class' => 'dinline')); ?>
                    <input type="hidden" name="kyc_session_id"
                           value="<?php echo (int) $session['id']; ?>">
                    <input type="hidden" name="reason" value="referred by staff">
                    <button type="submit" class="btn btn-default btn-xs" data-cv-once="1">
                      Refer for review
                    </button>
                  <?php echo form_close(); ?>
                <?php } ?>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        </div><p class="cv-swipe">Swipe to view more</p></div>
      <?php } ?>

      <?php if (!empty($can_kyc_review) && !empty($may_create_kyc_session)) { ?>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/kyc_session/'
                                       . (int) $contract['id']), array('class' => 'mtop15')); ?>
          <div class="form-group">
            <label for="cv_kyc_signer">Open a Video KYC session for</label>
            <select name="signer_id" id="cv_kyc_signer" class="form-control">
              <?php foreach ($kyc_candidates as $candidate) { ?>
                <option value="<?php echo (int) $candidate['id']; ?>">
                  <?php echo html_escape((string) $candidate['label']); ?>
                </option>
              <?php } ?>
            </select>
          </div>
          <button type="submit" class="btn btn-default btn-sm" data-cv-once="1">
            Create verification session
          </button>
        <?php echo form_close(); ?>
      <?php } ?>

      <?php /* ---- local cancellation ---- */ ?>
      <?php if (!empty($can_cancel) && !empty($may_cancel)) { ?>
        <hr>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/cancel_local/'
                                       . (int) $contract['id'])); ?>
          <div class="form-group">
            <label for="cv_cancel_reason">Reason for cancelling (at least ten characters)</label>
            <input type="text" name="reason" id="cv_cancel_reason" class="form-control"
                   minlength="10" required>
          </div>
          <button type="submit" class="btn btn-danger btn-sm" data-cv-once="1"
                  onclick="return confirm('Cancel this contract here? The provider record is kept so its audit trail survives.');">
            Cancel locally
          </button>
        <?php echo form_close(); ?>
        <p class="text-muted small mtop10">
          Cancelling stops this contract here and issues nothing further. The document is left with
          the provider so its audit trail survives &mdash; the provider has no withdraw operation,
          and its delete operation destroys the audit trail permanently.
        </p>
      <?php } ?>

    </div></div>
  </div></div>

</div></div>
<script>
/* Duplicate-submission guard.
   A second click on a submit button is the cheapest way to create two signing
   requests. The database claim in the model is what actually prevents that;
   this only spares the user the confusing round trip. No user input is read
   here, and nothing is built into a URL. */
(function () {
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.querySelector) { return; }
    var btn = form.querySelector('[data-cv-once]');
    if (!btn) { return; }
    if (btn.disabled) { e.preventDefault(); return; }
    btn.disabled = true;
    btn.textContent = 'Working\u2026';
  }, true);
})();

</script>
<?php init_tail(); ?>
