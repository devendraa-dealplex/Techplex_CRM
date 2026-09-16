<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * The KYC & Video KYC tab, rendered inside the customer profile.
 *
 * WHY THIS FRAGMENT LOADS ITS OWN DATA
 * ------------------------------------
 * Perfex renders a profile tab from inside the Clients controller, which knows
 * nothing about this module and cannot be asked to fetch its data. So the
 * fragment asks the module's own model, with the SAME capability and assignment
 * checks the standalone page uses — `clientKycCases()` takes the actor's held
 * capabilities and filters every case and every evidence type through
 * Contract_authz. There is no second, looser path to this data.
 *
 * It is a fragment, not a page: no init_head(), no wrapper, because it is
 * rendered inside chrome the customer profile has already opened.
 */
$CI = &get_instance();

if (!isset($CI->contract_verification_model)) {
    $CI->load->model('payplex_contract_verification/contract_verification_model', 'cv_tab');
    $cvTab = $CI->cv_tab;
} else {
    $cvTab = $CI->contract_verification_model;
}

$cvClientId = isset($client) && is_array($client) && isset($client['userid'])
    ? (int) $client['userid']
    : (int) $CI->input->get('id');

$cvHeld = array();

foreach (Contract_caps::all() as $cvCap) {
    if (has_permission(Contract_caps::FEATURE, '', $cvCap)) { $cvHeld[] = $cvCap; }
}

$cvCases = $cvClientId > 0
    ? $cvTab->clientKycCases($cvClientId, $cvHeld, get_staff_user_id(),
                             function_exists('is_admin') && is_admin(), time())
    : array();

$cvTypes = Contract_evidence_types::catalogue();
?>
<?php echo payplex_cv_responsive_table_assets(); ?>
<div class="cv-exec">

  <h4 class="no-mtop">KYC &amp; Video KYC</h4>

  <?php if (empty($cvCases)) { ?>
    <p class="text-muted">
      No verification case on this customer is visible to you. That may mean none exists, or that
      none is assigned to you &mdash; this screen does not distinguish, because saying which would
      itself disclose something about the customer.
    </p>
  <?php } else { ?>
    <div class="cv-tablewrap"><div class="table-responsive">
    <table class="table table-condensed">
      <thead><tr>
        <th>Agreement</th><th>Signer</th><th>Status</th><th>Attempt</th><th>Evidence held</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($cvCases as $cvRow) { ?>
        <tr>
          <td><?php echo html_escape((string) $cvRow['contract_subject']); ?></td>
          <td><?php echo html_escape((string) $cvRow['case']['signer_masked']); ?></td>
          <td><?php echo html_escape((string) $cvRow['case']['state_label']); ?></td>
          <td><?php echo (int) $cvRow['case']['attempt']; ?></td>
          <td>
            <?php if (empty($cvRow['evidence'])) { ?>
              <span class="text-muted">None you may open</span>
            <?php } else { ?>
              <?php $cvNames = array(); ?>
              <?php foreach ($cvRow['evidence'] as $cvType => $cvSummary) { ?>
                <?php $cvNames[] = isset($cvTypes[$cvType]) ? $cvTypes[$cvType]['label'] : $cvType; ?>
              <?php } ?>
              <?php echo html_escape(implode(', ', $cvNames)); ?>
            <?php } ?>
          </td>
          <td>
            <a class="btn btn-default btn-xs"
               href="<?php echo admin_url('payplex_contract_verification/signing/kyc_case/'
                     . (int) $cvRow['contract_id'] . '?kyc_session_id=' . (int) $cvRow['case']['id']); ?>">
              Open case
            </a>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
    </div><p class="cv-swipe">Swipe to view more</p></div>
  <?php } ?>

</div>
