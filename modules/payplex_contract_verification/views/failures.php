<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * Failures and retries.
 *
 * The column that matters is "Attempted". A failure where the provider was
 * never called is not a provider rejection, and treating it as one would let
 * somebody conclude a request does not exist at the provider when the truth is
 * that we never asked.
 */
?>
<?php init_head(); ?>
<?php echo payplex_cv_responsive_table_assets(); ?>
<div id="wrapper"><div class="content">

  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">

      <h4 class="no-mtop">
        <?php echo html_escape((string) $title); ?>
        <?php if (!empty($contract['subject'])) { ?>
          &mdash; <?php echo html_escape((string) $contract['subject']); ?>
        <?php } ?>
      </h4>

      <p><a href="<?php echo html_escape((string) $back_url); ?>">Back to the execution panel</a></p>

      <?php if (empty($failures)) { ?>
        <p class="text-muted">No unresolved failures.</p>
      <?php } else { ?>
        <div class="cv-tablewrap"><div class="table-responsive">
        <table class="table table-condensed">
          <thead><tr>
            <th>Operation</th><th>What happened</th><th>Attempted</th><th>Next attempt</th><th></th>
          </tr></thead>
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
              <td>
                <?php if (!empty($can_retry) && !empty($row['id'])) { ?>
                  <?php echo form_open(admin_url('payplex_contract_verification/signing/failure_resolve/'
                                                 . (int) $contract['id'])); ?>
                    <input type="hidden" name="failure_id" value="<?php echo (int) $row['id']; ?>">
                    <input type="text" name="resolution" class="form-control input-sm"
                           placeholder="How was this resolved?" minlength="10" required>
                    <button type="submit" class="btn btn-default btn-xs mtop5">Mark resolved</button>
                  <?php echo form_close(); ?>
                <?php } ?>
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
<?php init_tail(); ?>
