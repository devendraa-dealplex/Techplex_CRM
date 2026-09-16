<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">

            <h4 class="no-margin"><?= _l('facebook_duplicates_title'); ?></h4>
            <hr class="hr-panel-heading" />

            <p class="help-block"><?= _l('facebook_duplicates_hint'); ?></p>
            <div class="alert alert-info"><?= _l('facebook_duplicates_no_merge_notice'); ?></div>

            <?php
            $open = 0;

            foreach ($rows as $r) {
                if ($r['status'] === 'open') {
                    $open++;
                }
            }
            ?>

            <?php if ($open === 0) { ?>
              <div class="alert alert-success"><?= _l('facebook_duplicates_none'); ?></div>
            <?php } ?>

            <table class="table table-striped">
              <thead>
                <tr>
                  <th><?= _l('facebook_duplicates_lead'); ?></th>
                  <th><?= _l('facebook_duplicates_other'); ?></th>
                  <th><?= _l('facebook_duplicates_matched_on'); ?></th>
                  <th><?= _l('facebook_when'); ?></th>
                  <th><?= _l('facebook_duplicates_decision'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r) { ?>
                  <tr>
                    <td>
                      <a href="<?= admin_url('leads/index/' . (int) $r['lead_id']); ?>">#<?= (int) $r['lead_id']; ?></a>
                      <?php if (!empty($r['fb_ref'])) { ?>
                        <br /><small class="text-muted"><code><?= html_escape($r['fb_ref']); ?></code></small>
                      <?php } ?>
                    </td>
                    <td>
                      <a href="<?= admin_url('leads/index/' . (int) $r['other_lead_id']); ?>">#<?= (int) $r['other_lead_id']; ?></a>
                      <?php if (!empty($r['other_fb_ref'])) { ?>
                        <br /><small class="text-muted"><code><?= html_escape($r['other_fb_ref']); ?></code></small>
                      <?php } ?>
                    </td>
                    <td>
                      <span class="label label-warning"><?= html_escape($r['match_type']); ?></span>
                      <?php
                      /*
                       * The matching VALUE is deliberately not shown, and not
                       * stored: the column holds a salted hash. Both leads are
                       * one click away and carry the real value; a second copy
                       * of every lead's email and phone in a review table is a
                       * liability with no reader.
                       */
                      ?>
                      <br /><small class="text-muted"><?= _l('facebook_duplicates_value_hidden'); ?></small>
                    </td>
                    <td><?= _dt(date('Y-m-d H:i:s', (int) $r['created_epoch'])); ?></td>
                    <td>
                      <?php if ($r['status'] !== 'open') { ?>
                        <span class="label label-<?= $r['status'] === 'confirmed' ? 'danger' : 'success'; ?>">
                          <?= html_escape($r['status']); ?>
                        </span>
                        <br /><small class="text-muted">
                          <?= _l('facebook_by'); ?> #<?= (int) $r['decided_by']; ?>:
                          <?= html_escape($r['decided_reason']); ?>
                        </small>
                      <?php } else { ?>
                        <?php echo form_open(admin_url('leadgen_facebook/duplicates')); ?>
                          <input type="hidden" name="duplicate_id" value="<?= (int) $r['id']; ?>" />
                          <input type="text" name="reason" class="form-control input-sm" required
                                 maxlength="255"
                                 placeholder="<?= _l('facebook_duplicates_reason_placeholder'); ?>" />
                          <select name="decision" class="form-control input-sm" style="margin-top:4px;">
                            <option value="confirmed"><?= _l('facebook_duplicates_confirm'); ?></option>
                            <option value="not_duplicate"><?= _l('facebook_duplicates_reject'); ?></option>
                          </select>
                          <button type="submit" class="btn btn-primary btn-sm" style="margin-top:4px;">
                            <?= _l('facebook_duplicates_record'); ?>
                          </button>
                        <?php echo form_close(); ?>
                      <?php } ?>
                    </td>
                  </tr>
                <?php } ?>
                <?php if (empty($rows)) { ?>
                  <tr><td colspan="5" class="text-muted"><?= _l('facebook_duplicates_empty'); ?></td></tr>
                <?php } ?>
              </tbody>
            </table>

            <a href="<?= admin_url('leadgen_facebook'); ?>" class="btn btn-default">
              <?= _l('leadgen_facebook_settings'); ?>
            </a>
            <a href="<?= admin_url('leadgen_facebook/quarantine'); ?>" class="btn btn-default">
              <?= _l('facebook_quarantine_title'); ?>
            </a>

          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
