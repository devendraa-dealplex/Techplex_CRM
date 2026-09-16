<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * One KYC case.
 *
 * WHAT THIS SCREEN DELIBERATELY DOES NOT SHOW
 * -------------------------------------------
 *   - a storage key or a file path, in any attribute, hidden field or link;
 *   - an evidence type the reader does not hold the capability for. Listing a
 *     Video KYC recording and refusing on click still tells them a recording
 *     exists, and for a recording that is information about the customer;
 *   - any control that sets a verification outcome directly. The decision form
 *     posts to a route that hands everything to Kyc_decision_service, which
 *     refuses without a provider result and verified evidence.
 */
?>
<?php init_head(); ?>
<?php echo payplex_cv_responsive_table_assets(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s cv-exec"><div class="panel-body">

      <h4 class="no-mtop"><?php echo html_escape((string) $title); ?></h4>

      <p>
        Status: <strong><?php echo html_escape((string) $case['state_label']); ?></strong>
        <span class="label label-default"><?php echo html_escape((string) $case['environment']); ?></span>
      </p>

      <h5>Case</h5>
      <div class="cv-tablewrap"><div class="table-responsive">
      <table class="table table-condensed">
        <tr><td>Signer</td><td><?php echo html_escape((string) $case['signer_masked']); ?></td></tr>
        <tr><td>Attempt</td><td><?php echo (int) $case['attempt']; ?></td></tr>
        <tr><td>Why verification is required</td>
            <td><?php echo html_escape((string) $case['required_reason']); ?></td></tr>
        <tr><td>Provider</td><td><?php echo html_escape((string) $case['provider']); ?></td></tr>
        <tr><td>Decision</td>
            <td>
              <?php if ($case['decision'] === '') { ?>
                <span class="text-muted">Not decided</span>
              <?php } else { ?>
                <?php echo html_escape((string) $case['decision']); ?>
              <?php } ?>
            </td></tr>
      </table>
      </div><p class="cv-swipe">Swipe to view more</p></div>

      <h5>Evidence you may open</h5>
      <?php if (empty($visible_evidence)) { ?>
        <p class="text-muted">
          You do not hold a capability for any evidence type on this case. That is the whole
          list &mdash; nothing is hidden behind a refusal message.
        </p>
      <?php } else { ?>
        <div class="cv-tablewrap"><div class="table-responsive">
        <table class="table table-condensed">
          <thead><tr><th>Type</th><th>What it is</th><th>Stored</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($visible_evidence as $type => $meta) { ?>
            <tr>
              <td><?php echo html_escape((string) $meta['label']); ?></td>
              <td class="text-muted small"><?php echo html_escape((string) $meta['means']); ?></td>
              <td>
                <?php if (empty($available[$type])) { ?>
                  <span class="text-muted">Nothing stored</span>
                <?php } else { ?>
                  Stored
                <?php } ?>
              </td>
              <td>
                <?php if (!empty($available[$type]) && $type !== 'video_kyc_recording') { ?>
                  <a class="btn btn-default btn-xs"
                     href="<?php echo admin_url('payplex_contract_verification/signing/evidence_view/'
                           . (int) $contract['id'] . '?type=' . rawurlencode($type)
                           . '&amp;kyc_session_id=' . (int) $case['id']); ?>">Open</a>
                <?php } ?>
                <?php if (!empty($available[$type]) && $type === 'video_kyc_recording' && !empty($can_video)) { ?>
                  <a class="btn btn-default btn-xs"
                     href="<?php echo admin_url('payplex_contract_verification/signing/evidence_view/'
                           . (int) $contract['id'] . '?type=' . rawurlencode($type)
                           . '&amp;kyc_session_id=' . (int) $case['id']); ?>">Watch</a>
                <?php } ?>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        </div><p class="cv-swipe">Swipe to view more</p></div>
      <?php } ?>

      <h5>Decision history</h5>
      <?php if (empty($decisions)) { ?>
        <p class="text-muted">No decision has been recorded for this case.</p>
      <?php } else { ?>
        <div class="cv-tablewrap"><div class="table-responsive">
        <table class="table table-condensed">
          <thead><tr><th>#</th><th>Decision</th><th>From</th><th>To</th><th>Recorded by</th></tr></thead>
          <tbody>
          <?php foreach ($decisions as $d) { ?>
            <tr>
              <td><?php echo (int) $d['sequence']; ?></td>
              <td><?php echo html_escape((string) $d['decision']); ?></td>
              <td><?php echo html_escape((string) $d['state_from']); ?></td>
              <td><?php echo html_escape((string) $d['state_to']); ?></td>
              <td>staff #<?php echo (int) $d['decided_by_staff']; ?></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        </div><p class="cv-swipe">Swipe to view more</p></div>
      <?php } ?>

      <?php if (!empty($can_approve) || !empty($can_reject)) { ?>
        <h5>Record a decision</h5>
        <p class="text-muted small">
          A decision needs a verification result from an approved provider and evidence whose hash
          verifies. With no provider configured this will refuse, and say so &mdash; it will not
          record a pass nobody checked.
        </p>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/kyc_decide/'
                                       . (int) $contract['id'])); ?>
          <input type="hidden" name="kyc_session_id" value="<?php echo (int) $case['id']; ?>">
          <div class="form-group">
            <label for="cv_decision">Decision</label>
            <select name="decision" id="cv_decision" class="form-control">
              <?php if (!empty($can_approve)) { ?><option value="passed">Verification passed</option><?php } ?>
              <?php if (!empty($can_reject)) { ?><option value="failed">Verification failed</option><?php } ?>
              <option value="manual_review">Refer for manual compliance review</option>
            </select>
          </div>
          <div class="form-group">
            <label for="cv_decision_reason">Reason (at least ten characters)</label>
            <input type="text" name="reason" id="cv_decision_reason" class="form-control" minlength="10">
          </div>
          <button type="submit" class="btn btn-default btn-sm" data-cv-once="1">Record decision</button>
        <?php echo form_close(); ?>
      <?php } ?>

      <p class="mtop20"><a href="<?php echo html_escape((string) $back_url); ?>">Back to execution</a></p>

    </div></div>
  </div></div>
</div></div>
