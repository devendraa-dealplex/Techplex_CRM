<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * Video KYC configuration.
 *
 * Two things this screen exists to say plainly.
 *
 * First, Leegality does not do Video KYC. Its Face Match, Capture Photo and
 * Liveliness features are security steps inside the signing ceremony, and
 * presenting them here as verification would be relabelling a photo check as a
 * regulated one.
 *
 * Second, retention defaults to references, decisions, timestamps and hashes
 * only. A V-CIP recording is biometric data; storing one needs a lawful basis
 * and an approved period that nobody has supplied.
 */
?>
<?php init_head(); ?>
<?php echo payplex_cv_responsive_table_assets(); ?>
<div id="wrapper"><div class="content">

  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">

      <h4 class="no-mtop"><?php echo html_escape((string) $title); ?></h4>

      <?php if (!empty($missing)) { ?>
        <div class="alert alert-warning">
          <strong>Not installed yet.</strong>
          <?php echo html_escape(implode(', ', $missing)); ?>.
        </div>
      <?php } ?>

      <div class="alert alert-warning">
        <strong>No Video KYC provider is configured.</strong>
        <?php echo html_escape((string) $provider['detail']); ?>
      </div>

      <h5>What a provider would need to offer</h5>
      <div class="cv-tablewrap"><div class="table-responsive">
      <table class="table table-condensed">
        <thead><tr><th>Capability</th><th>What it means</th><th>Available</th></tr></thead>
        <tbody>
        <?php foreach ($known as $key => $means) { ?>
          <tr>
            <td><?php echo html_escape((string) $key); ?></td>
            <td class="small"><?php echo html_escape((string) $means); ?></td>
            <td>
              <?php if (!empty($capabilities[$key])) { ?>
                Yes
              <?php } else { ?>
                <span class="text-muted">No</span>
              <?php } ?>
            </td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
      </div><p class="cv-swipe">Swipe to view more</p></div>

      <h5>Location</h5>
      <p class="text-muted small"><?php echo html_escape((string) $locations['rule']); ?></p>

      <h5>KYC policies available on a workflow</h5>
      <ul>
        <?php foreach ($policies as $key => $label) { ?>
          <li><?php echo html_escape((string) $label); ?></li>
        <?php } ?>
      </ul>

      <h5>Evidence retention</h5>
      <div class="alert alert-info">
        <?php echo html_escape((string) $retention['default_note']); ?>
      </div>

      <?php echo form_open(admin_url('payplex_contract_verification/signing/kyc_config_save')); ?>
        <input type="hidden" name="retain_references" value="1">

        <div class="checkbox">
          <label>
            <input type="checkbox" name="retain_recordings" value="1"
                   <?php echo !empty($retention['retain_recordings']) ? 'checked' : ''; ?>>
            Store raw recordings and identity documents
          </label>
          <span class="help-block">
            Requires an approved retention period below. Without one this is refused, because the
            alternative default is keeping biometric data indefinitely.
          </span>
        </div>

        <div class="form-group">
          <label for="cv_retention_days">Retention period in days</label>
          <input type="number" name="retention_days" id="cv_retention_days" class="form-control"
                 min="0" value="<?php echo (int) $retention['retention_days']; ?>">
        </div>

        <div class="checkbox">
          <label>
            <input type="checkbox" name="legal_hold" value="1"
                   <?php echo !empty($retention['legal_hold']) ? 'checked' : ''; ?>>
            Legal hold active
          </label>
          <span class="help-block">
            A legal hold stops automated deletion regardless of the retention period. Lifting one is
            a compliance decision and is refused from this screen.
          </span>
        </div>

        <button type="submit" class="btn btn-primary">Save retention policy</button>
      <?php echo form_close(); ?>

    </div></div>
  </div></div>

</div></div>
<?php init_tail(); ?>
