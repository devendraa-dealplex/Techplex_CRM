<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * Provider settings.
 *
 * WHAT THIS SCREEN NEVER SHOWS
 * ----------------------------
 * A credential. Not truncated, not masked in a text input, not "for
 * verification". Every secret is described by a fingerprint; an identifier
 * additionally shows its last four characters, and key material does not even
 * get that. The value is written once and never read back to a browser.
 */
?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>

      <?php if (empty($status['provider']['implemented'])) { ?>
        <div class="alert alert-warning">
          <strong>The signing provider adapter is not implemented.</strong>
          <?php echo html_escape((string) $status['provider']['detail']); ?>
          <br><br>
          Completing it requires:
          <ul style="margin-bottom:0">
            <?php foreach ($status['provider']['requires'] as $r) { ?>
              <li><?php echo html_escape(str_replace('_', ' ', $r)); ?></li>
            <?php } ?>
          </ul>
        </div>
      <?php } ?>

      <?php if (empty($status['webhook_verifier']['available'])) { ?>
        <div class="alert alert-danger">
          <strong>The webhook route authenticates nothing, so it accepts nothing.</strong>
          The provider's signature scheme is undocumented here, so no inbound delivery can be
          verified &mdash; and an unverified delivery must never change a contract. This is the
          correct behaviour for a public route whose authentication is unspecified, not a defect
          to work around.
        </div>
      <?php } ?>

      <p>
        Environment:
        <strong><?php echo html_escape((string) $status['environment']); ?></strong>
        &middot; Enabled: <strong><?php echo !empty($status['enabled']) ? 'yes' : 'no'; ?></strong>
      </p>

      <?php if (!empty($status['missing'])) { ?>
        <p class="text-muted small">
          Not yet configured:
          <?php echo html_escape(implode(', ', array_map(function ($m) {
              return str_replace('_', ' ', $m); }, $status['missing']))); ?>.
        </p>
      <?php } ?>
    </div></div>
  </div></div>

  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h5 class="no-mtop">Connection</h5>

      <?php if (empty($is_admin)) { ?>
        <div class="alert alert-info">
          Only an administrator can change these settings.
        </div>
      <?php } else { ?>
      <?php echo form_open(admin_url('payplex_contract_verification/signing/settings_save')); ?>
        <div class="row">
          <?php foreach ($schema as $key => $meta) {
              if ($key === 'environment' || $key === 'enabled') { continue; }
              $row = isset($stored[$key]) ? $stored[$key] : array();
              $isSecret = !empty($meta['secret']); ?>
            <div class="col-md-6">
              <label class="control-label" for="<?php echo html_escape($key); ?>">
                <?php echo html_escape($meta['label']); ?>
              </label>

              <?php if ($isSecret) { ?>
                <input type="password" class="form-control" autocomplete="off"
                       id="<?php echo html_escape($key); ?>" name="<?php echo html_escape($key); ?>"
                       placeholder="<?php echo !empty($row['configured'])
                           ? 'Stored. Leave blank to keep it.' : 'Not set'; ?>">
                <p class="text-muted small">
                  <?php if (!empty($row['configured'])) { ?>
                    Stored &middot; fingerprint <code><?php echo html_escape((string) $row['fingerprint']); ?></code>
                    <?php if (!empty($row['last_four'])) { ?>
                      &middot; ends <code><?php echo html_escape((string) $row['last_four']); ?></code>
                    <?php } ?>
                  <?php } else { ?>
                    Not set.
                  <?php } ?>
                  <br><?php echo html_escape($meta['means']); ?>
                </p>
              <?php } else { ?>
                <input type="text" class="form-control"
                       id="<?php echo html_escape($key); ?>" name="<?php echo html_escape($key); ?>"
                       value="<?php echo html_escape(isset($row['value']) ? (string) $row['value'] : ''); ?>">
                <p class="text-muted small"><?php echo html_escape($meta['means']); ?></p>
              <?php } ?>
            </div>
          <?php } ?>
        </div>
        <button type="submit" class="btn btn-info">Save settings</button>
        <span class="text-muted small">
          A blank credential field keeps what is stored. Nothing is displayed back after saving.
        </span>
      <?php echo form_close(); ?>
      <?php } ?>
    </div></div>
  </div></div>

  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h5 class="no-mtop">Tests</h5>
      <?php echo form_open(admin_url('payplex_contract_verification/signing/test_connection'),
                           array('style' => 'display:inline')); ?>
        <button type="submit" class="btn btn-default">Test Connection</button>
      <?php echo form_close(); ?>
      <?php echo form_open(admin_url('payplex_contract_verification/signing/sandbox_test'),
                           array('style' => 'display:inline')); ?>
        <button type="submit" class="btn btn-default">Send Sandbox Test</button>
      <?php echo form_close(); ?>
      <p class="text-muted small mtop15">
        Neither test displays any part of a credential. Both record the attempt in the audit trail,
        which is what the production gate reads.
      </p>
    </div></div>
  </div></div>

  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h5 class="no-mtop">Production</h5>
      <p class="text-muted">Every condition below is checked server-side at the moment of the change:</p>
      <ul>
        <?php foreach ($gate as $key => $means) { ?>
          <li><?php echo html_escape($means); ?></li>
        <?php } ?>
      </ul>
      <?php if (!empty($is_admin)) { ?>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/set_environment')); ?>
          <input type="hidden" name="environment" value="production">
          <input type="text" name="confirm_production" class="form-control" style="max-width:280px"
                 placeholder="Type: ENABLE PRODUCTION" autocomplete="off">
          <button type="submit" class="btn btn-danger mtop15"
                  onclick="return confirm('Enable production? Production credentials are entered afterwards, never before, so a half-configured production mode cannot exist.');">
            Enable production
          </button>
        <?php echo form_close(); ?>
      <?php } ?>
    </div></div>
  </div></div>

</div></div>
<?php init_tail(); ?>
</body></html>
