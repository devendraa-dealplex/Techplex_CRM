<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * Leegality workflow mapping.
 *
 * Leegality documents no API that lists workflows or fetches one by id, so this
 * screen is where a human transcribes what the dashboard shows. Every row is
 * therefore a CLAIM about somebody else's configuration — it can be wrong when
 * entered and can go stale silently afterwards, because a workflow edited in the
 * Leegality dashboard sends us no notification.
 *
 * The screen is built around that fact: it says where the value comes from, it
 * shows whether the claim has ever been proven, and it warns before validating
 * because validating is not free.
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
          These migrations have not been applied:
          <?php echo html_escape(implode(', ', $missing)); ?>.
        </div>
      <?php } ?>

      <p>
        Environment:
        <span class="label label-default"><?php echo html_escape((string) $environment); ?></span>
      </p>

      <div class="alert alert-info">
        <strong>Why this has to be typed in.</strong>
        <?php echo html_escape((string) $validation['why']); ?>
        <p class="mtop10 mbot0"><?php echo html_escape((string) $validation['therefore']); ?></p>
      </div>

      <?php if (empty($may_validate['allowed'])) { ?>
        <div class="alert alert-warning">
          <strong>Validation is refused in this environment.</strong>
          It creates a real document in the account, so it is permitted in sandbox only.
        </div>
      <?php } ?>

      <h5>Mapped workflows</h5>
      <?php if (empty($profiles)) { ?>
        <p class="text-muted">
          No workflow has been mapped yet, so no contract can be sent.
        </p>
      <?php } else { ?>
        <div class="cv-tablewrap"><div class="table-responsive">
        <table class="table table-condensed">
          <thead><tr>
            <th>Name</th><th>Workflow ID</th><th>Signers</th><th>Order</th>
            <th>Authentication</th><th>Video KYC</th><th>Validated</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($profiles as $profile) { ?>
            <tr>
              <td><?php echo html_escape((string) $profile['label']); ?></td>
              <td><code><?php echo html_escape((string) $profile['profile_id']); ?></code></td>
              <td><?php echo html_escape((string) $profile['signer_count']); ?></td>
              <td><?php echo html_escape((string) $profile['ordering_mode']); ?></td>
              <td><?php echo html_escape((string) $profile['auth_method']); ?></td>
              <td><?php echo html_escape((string) $profile['kyc_policy']); ?></td>
              <td>
                <?php if (empty($profile['validated_at'])) { ?>
                  <span class="text-warning">Never</span>
                <?php } else { ?>
                  Yes
                <?php } ?>
              </td>
              <td>
                <?php if (!empty($may_validate['allowed'])) { ?>
                  <?php echo form_open(admin_url('payplex_contract_verification/signing/profile_validate'),
                                       array('class' => 'dinline')); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $profile['id']; ?>">
                    <button type="submit" class="btn btn-default btn-xs"
                            onclick="return confirm('Validating creates a REAL document in the sandbox account, which is then left to expire. Continue?');">
                      Validate
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

      <h5>Map a workflow</h5>
      <?php echo form_open(admin_url('payplex_contract_verification/signing/profile_save')); ?>

        <div class="form-group">
          <label for="cv_label">Name staff will recognise</label>
          <input type="text" name="label" id="cv_label" class="form-control" required>
        </div>

        <div class="form-group">
          <label for="cv_profile_id">Leegality Workflow (profile) ID</label>
          <input type="text" name="profile_id" id="cv_profile_id" class="form-control" required>
          <span class="help-block">
            Copy it from the Leegality dashboard. There is no API that lists workflows, so this
            cannot be looked up for you.
          </span>
        </div>

        <div class="form-group">
          <label for="cv_template">Contract template ID (0 for any)</label>
          <input type="number" name="contract_template_id" id="cv_template"
                 class="form-control" value="0" min="0">
        </div>

        <div class="form-group">
          <label for="cv_signer_count">How many signers does this workflow define?</label>
          <input type="number" name="signer_count" id="cv_signer_count"
                 class="form-control" min="1" max="20" required>
        </div>

        <div class="form-group">
          <label for="cv_roles">Roles, in signing order, comma separated</label>
          <input type="text" name="signer_roles" id="cv_roles" class="form-control"
                 placeholder="borrower, lender" required>
          <span class="help-block">
            One per signer. A roster whose roles do not match these positions is refused, because
            the workflow decides who signs first and the request cannot override it.
          </span>
        </div>

        <div class="form-group">
          <label for="cv_ordering">Signing order</label>
          <select name="ordering_mode" id="cv_ordering" class="form-control">
            <?php foreach ($ordering_modes as $key => $label) { ?>
              <option value="<?php echo html_escape((string) $key); ?>">
                <?php echo html_escape((string) $label); ?>
              </option>
            <?php } ?>
          </select>
        </div>

        <div class="form-group">
          <label for="cv_auth">Authentication</label>
          <select name="auth_method" id="cv_auth" class="form-control">
            <?php foreach ($auth_methods as $key => $label) { ?>
              <option value="<?php echo html_escape((string) $key); ?>">
                <?php echo html_escape((string) $label); ?>
              </option>
            <?php } ?>
          </select>
          <span class="help-block">
            Where the provider owns the ceremony, this module records its verified result and runs
            no competing one of its own.
          </span>
        </div>

        <div class="form-group">
          <label for="cv_kyc">Video KYC policy</label>
          <select name="kyc_policy" id="cv_kyc" class="form-control">
            <?php foreach ($kyc_policies as $key => $label) { ?>
              <option value="<?php echo html_escape((string) $key); ?>">
                <?php echo html_escape((string) $label); ?>
              </option>
            <?php } ?>
          </select>
        </div>

        <button type="submit" class="btn btn-primary">Save workflow</button>
      <?php echo form_close(); ?>

    </div></div>
  </div></div>

</div></div>
<?php init_tail(); ?>
