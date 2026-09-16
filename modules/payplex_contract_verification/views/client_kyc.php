<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * The KYC & Video KYC tab on a customer profile.
 *
 * Client → Contact/Signer → KYC case → Contract → Evidence, in that order,
 * because that is the order somebody actually asks: "this customer — what
 * verification do we hold on them, and against which agreement".
 *
 * Only cases this reader may see appear, and only evidence types they hold a
 * capability for are listed. Listing a Video KYC recording and refusing on
 * click still tells them a recording exists, which for a recording is itself
 * information about the customer.
 */
?>
<?php init_head(); ?>
<?php echo payplex_cv_responsive_table_assets(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s cv-exec"><div class="panel-body">

      <h4 class="no-mtop">
        <?php echo html_escape((string) $title); ?>
        <?php if ($client_name !== '') { ?>
          &mdash; <?php echo html_escape((string) $client_name); ?>
        <?php } ?>
      </h4>

      <?php if (empty($cases)) { ?>
        <p class="text-muted">
          No verification case on this customer is visible to you. That may mean none exists, or
          that none is assigned to you &mdash; the screen does not distinguish, because saying
          which would itself disclose something.
        </p>
      <?php } else { ?>
        <div class="cv-tablewrap"><div class="table-responsive">
        <table class="table table-condensed">
          <thead><tr>
            <th>Agreement</th><th>Signer</th><th>Status</th><th>Attempt</th><th>Evidence held</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($cases as $row) { ?>
            <tr>
              <td><?php echo html_escape((string) $row['contract_subject']); ?></td>
              <td><?php echo html_escape((string) $row['case']['signer_masked']); ?></td>
              <td><?php echo html_escape((string) $row['case']['state_label']); ?></td>
              <td><?php echo (int) $row['case']['attempt']; ?></td>
              <td>
                <?php if (empty($row['evidence'])) { ?>
                  <span class="text-muted">None you may open</span>
                <?php } else { ?>
                  <?php $names = array(); ?>
                  <?php foreach ($row['evidence'] as $type => $summary) { ?>
                    <?php $names[] = isset($types[$type]) ? $types[$type]['label'] : $type; ?>
                  <?php } ?>
                  <?php echo html_escape(implode(', ', $names)); ?>
                <?php } ?>
              </td>
              <td>
                <a class="btn btn-default btn-xs"
                   href="<?php echo admin_url('payplex_contract_verification/signing/kyc_case/'
                         . (int) $row['contract_id'] . '?kyc_session_id=' . (int) $row['case']['id']); ?>">
                  Open case
                </a>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        </div><p class="cv-swipe">Swipe to view more</p></div>
      <?php } ?>

    </div></div>
  </div></div>
</div></div>
