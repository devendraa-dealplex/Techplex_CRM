<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">

            <h4 class="no-margin"><?= _l('facebook_enrichment_title'); ?></h4>
            <hr class="hr-panel-heading" />

            <p class="help-block"><?= _l('facebook_enrichment_hint'); ?></p>

            <?php
            $pending = 0;
            $exhausted = 0;

            foreach ($rows as $r) {
                if ((int) $r['resolved'] === 0) {
                    $pending++;

                    if (!empty($r['exhausted'])) {
                        $exhausted++;
                    }
                }
            }
            ?>

            <?php if ($pending === 0) { ?>
              <div class="alert alert-success"><?= _l('facebook_enrichment_none'); ?></div>
            <?php } else { ?>
              <div class="alert alert-warning">
                <strong><?= (int) $pending; ?></strong> <?= _l('facebook_enrichment_pending'); ?>
                <?php if ($exhausted > 0) { ?>
                  <br /><strong class="text-danger"><?= (int) $exhausted; ?></strong>
                  <?= _l('facebook_enrichment_exhausted'); ?>
                <?php } ?>
              </div>

              <?php echo form_open(admin_url('leadgen_facebook/enrichment')); ?>
                <button type="submit" class="btn btn-primary">
                  <?= _l('facebook_enrichment_retry'); ?>
                </button>
              <?php echo form_close(); ?>
              <br />
            <?php } ?>

            <table class="table table-striped">
              <thead>
                <tr>
                  <th><?= _l('facebook_enrichment_lead'); ?></th>
                  <th><?= _l('facebook_enrichment_missing'); ?></th>
                  <th><?= _l('facebook_enrichment_attempts'); ?></th>
                  <th><?= _l('facebook_enrichment_status'); ?></th>
                  <th><?= _l('facebook_when'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r) { ?>
                  <tr>
                    <td>
                      <a href="<?= admin_url('leads/index/' . (int) $r['lead_id']); ?>">
                        #<?= (int) $r['lead_id']; ?>
                      </a>
                    </td>
                    <td><code><?= html_escape($r['failures']); ?></code></td>
                    <td>
                      <?= (int) $r['attempts']; ?>
                      <?php if ((int) $max_attempts > 0) { ?>
                        / <?= (int) $max_attempts; ?>
                      <?php } ?>
                    </td>
                    <td>
                      <?php if ((int) $r['resolved'] === 1) { ?>
                        <span class="label label-success"><?= _l('facebook_enrichment_resolved'); ?></span>
                      <?php } elseif (!empty($r['exhausted'])) { ?>
                        <span class="label label-danger"><?= _l('facebook_enrichment_stopped'); ?></span>
                      <?php } else { ?>
                        <span class="label label-warning"><?= _l('facebook_enrichment_waiting'); ?></span>
                      <?php } ?>
                      <?php if (!empty($r['last_error'])) { ?>
                        <br /><small class="text-muted"><?= html_escape($r['last_error']); ?></small>
                      <?php } ?>
                    </td>
                    <td>
                      <?php
                      /*
                       * Rendered from the stored UTC epoch, not from a
                       * formatted datetime. This install's CRM timezone and its
                       * database session timezone are 12h30m apart, so anything
                       * counted or displayed from a formatted column is wrong
                       * by half a day and says so quietly.
                       */
                      echo _dt(date('Y-m-d H:i:s', (int) $r['created_epoch']));
                      ?>
                    </td>
                  </tr>
                <?php } ?>
                <?php if (empty($rows)) { ?>
                  <tr><td colspan="5" class="text-muted"><?= _l('facebook_enrichment_empty'); ?></td></tr>
                <?php } ?>
              </tbody>
            </table>

            <a href="<?= admin_url('leadgen_facebook'); ?>" class="btn btn-default">
              <?= _l('leadgen_facebook_settings'); ?>
            </a>
            <a href="<?= admin_url('leadgen_facebook/reports'); ?>" class="btn btn-default">
              <?= _l('facebook_view_reports'); ?>
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
