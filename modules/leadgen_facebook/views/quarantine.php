<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">

            <h4 class="no-margin"><?= _l('facebook_quarantine_title'); ?></h4>
            <hr class="hr-panel-heading" />

            <p class="help-block"><?= _l('facebook_quarantine_hint'); ?></p>

            <?php
            $open = 0;

            foreach ($rows as $r) {
                if ((int) $r['released_lead_id'] === 0) {
                    $open++;
                }
            }
            ?>

            <?php if ($open === 0) { ?>
              <div class="alert alert-success"><?= _l('facebook_quarantine_none'); ?></div>
            <?php } else { ?>
              <div class="alert alert-warning">
                <strong><?= (int) $open; ?></strong> <?= _l('facebook_quarantine_open'); ?>
              </div>
            <?php } ?>

            <table class="table table-striped">
              <thead>
                <tr>
                  <th><?= _l('facebook_quarantine_ref'); ?></th>
                  <th><?= _l('facebook_quarantine_name'); ?></th>
                  <th><?= _l('facebook_quarantine_reason'); ?></th>
                  <th><?= _l('facebook_quarantine_references'); ?></th>
                  <th><?= _l('facebook_when'); ?></th>
                  <th><?= _l('facebook_quarantine_action'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r) { ?>
                  <tr>
                    <td><code><?= html_escape($r['fb_ref']); ?></code></td>
                    <td><?= html_escape($r['display_name']); ?></td>
                    <td><span class="label label-default"><?= html_escape($r['reason']); ?></span></td>
                    <td>
                      <small>
                        <?= _l('facebook_field_facebook_page_id'); ?>: <?= html_escape($r['page_id']); ?><br />
                        <?= _l('facebook_field_facebook_form_id'); ?>: <?= html_escape($r['form_id']); ?><br />
                        <?= _l('facebook_field_facebook_campaign_id'); ?>: <?= html_escape($r['campaign_id']); ?>
                      </small>
                    </td>
                    <td><?= _dt(date('Y-m-d H:i:s', (int) $r['created_epoch'])); ?></td>
                    <td>
                      <?php if ((int) $r['released_lead_id'] > 0) { ?>
                        <a href="<?= admin_url('leads/index/' . (int) $r['released_lead_id']); ?>"
                           class="btn btn-success btn-sm">
                          <?= _l('facebook_quarantine_released_as'); ?> #<?= (int) $r['released_lead_id']; ?>
                        </a>
                      <?php } else { ?>
                        <?php echo form_open(admin_url('leadgen_facebook/quarantine')); ?>
                          <input type="hidden" name="quarantine_id" value="<?= (int) $r['id']; ?>" />
                          <input type="email" name="email" class="form-control input-sm"
                                 placeholder="<?= _l('facebook_quarantine_email_placeholder'); ?>" />
                          <input type="text" name="phone" class="form-control input-sm"
                                 placeholder="<?= _l('facebook_quarantine_phone_placeholder'); ?>" />
                          <button type="submit" class="btn btn-primary btn-sm" style="margin-top:4px;">
                            <?= _l('facebook_quarantine_release'); ?>
                          </button>
                        <?php echo form_close(); ?>
                      <?php } ?>
                    </td>
                  </tr>
                  <?php if (!empty($r['field_summary'])) { ?>
                    <tr>
                      <td colspan="6">
                        <small class="text-muted"><?= _l('facebook_quarantine_payload'); ?>:</small>
                        <pre style="max-height:140px;overflow:auto;"><?= html_escape($r['field_summary']); ?></pre>
                      </td>
                    </tr>
                  <?php } ?>
                <?php } ?>
                <?php if (empty($rows)) { ?>
                  <tr><td colspan="6" class="text-muted"><?= _l('facebook_quarantine_empty'); ?></td></tr>
                <?php } ?>
              </tbody>
            </table>

            <a href="<?= admin_url('leadgen_facebook'); ?>" class="btn btn-default">
              <?= _l('leadgen_facebook_settings'); ?>
            </a>
            <a href="<?= admin_url('leadgen_facebook/duplicates'); ?>" class="btn btn-default">
              <?= _l('facebook_duplicates_title'); ?>
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
