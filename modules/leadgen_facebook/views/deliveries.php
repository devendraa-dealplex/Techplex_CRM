<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <h4 class="no-margin"><?= html_escape($title); ?></h4>
            <p class="text-muted">
              <?= _l('facebook_delivery_log_intro'); ?>
            </p>
            <hr class="hr-panel-heading" />

            <p>
              <a href="<?= admin_url('leadgen_facebook/deliveries'); ?>"
                 class="btn btn-<?= $outcome === '' ? 'primary' : 'default'; ?> btn-sm"><?= _l('facebook_all'); ?></a>
              <?php foreach ($outcomes as $o) { ?>
                <a href="<?= admin_url('leadgen_facebook/deliveries?outcome=' . urlencode($o)); ?>"
                   class="btn btn-<?= $outcome === $o ? 'primary' : 'default'; ?> btn-sm"><?= html_escape($o); ?></a>
              <?php } ?>
            </p>

            <?php if (empty($rows)) { ?>
              <div class="alert alert-info"><?= _l('facebook_no_deliveries'); ?></div>
            <?php } else { ?>
              <div class="table-responsive">
                <table class="table table-striped table-condensed">
                  <thead>
                    <tr>
                      <th><?= _l('facebook_received'); ?></th>
                      <th><?= _l('facebook_request_id'); ?></th>
                      <th><?= _l('facebook_event'); ?></th>
                      <th><?= _l('facebook_outcome'); ?></th>
                      <th>HTTP</th>
                      <th><?= _l('facebook_refs'); ?></th>
                      <th><?= _l('facebook_lead'); ?></th>
                      <th><?= _l('facebook_signature'); ?></th>
                      <th><?= _l('facebook_reason'); ?></th>
                      <th>ms</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r) { ?>
                      <tr>
                        <td class="text-nowrap">
                          <?= html_escape($r['received_at']); ?><br />
                          <small class="text-muted"><?= html_escape($r['timezone']); ?>
                            · <?= (int) $r['received_epoch']; ?></small>
                        </td>
                        <td><code><?= html_escape($r['request_id']); ?></code></td>
                        <td>
                          <?= html_escape($r['http_method']); ?><br />
                          <small class="text-muted"><?= html_escape($r['event_type']); ?></small>
                        </td>
                        <td>
                          <span class="label label-<?= $r['accepted'] ? 'success' : 'danger'; ?>">
                            <?= html_escape($r['outcome']); ?>
                          </span>
                          <?php if (!empty($r['needs_review'])) { ?>
                            <br /><span class="label label-warning"><?= _l('facebook_review_queue'); ?></span>
                          <?php } ?>
                        </td>
                        <td>
                          <?= (int) $r['http_status']; ?>
                          <?php if (!empty($r['retryable'])) { ?>
                            <br /><small class="text-muted"><?= _l('facebook_retryable'); ?></small>
                          <?php } ?>
                        </td>
                        <td>
                          <?php if (!empty($r['page_id'])) { ?>
                            <small>page <?= html_escape($r['page_id']); ?></small><br />
                          <?php } ?>
                          <?php if (!empty($r['form_id'])) { ?>
                            <small>form <?= html_escape($r['form_id']); ?></small><br />
                          <?php } ?>
                          <?php if (!empty($r['leadgen_id'])) { ?>
                            <small>lead ref <?= html_escape($r['leadgen_id']); ?></small>
                          <?php } ?>
                        </td>
                        <td>
                          <?php if (!empty($r['lead_id'])) { ?>
                            <a href="<?= admin_url('leads/index/' . (int) $r['lead_id']); ?>">
                              #<?= (int) $r['lead_id']; ?></a>
                          <?php } else { ?>
                            <span class="text-muted">—</span>
                          <?php } ?>
                          <?php if (!empty($r['assigned_to'])) { ?>
                            <br /><small class="text-muted">staff <?= (int) $r['assigned_to']; ?></small>
                          <?php } ?>
                        </td>
                        <td>
                          <?php if (!empty($r['signature_present'])) { ?>
                            <span class="label label-default"><?= html_escape($r['signature_fp']); ?></span>
                          <?php } else { ?>
                            <span class="text-muted"><?= _l('facebook_absent'); ?></span>
                          <?php } ?>
                        </td>
                        <td><small><?= html_escape($r['failure_reason']); ?></small></td>
                        <td><?= (int) $r['processing_ms']; ?></td>
                      </tr>
                      <?php if (!empty($r['payload_redacted'])) { ?>
                        <tr>
                          <td colspan="10">
                            <small class="text-muted"><?= _l('facebook_payload_redacted_note'); ?></small>
                            <pre style="max-height:160px;overflow:auto;"><?= html_escape($r['payload_redacted']); ?></pre>
                          </td>
                        </tr>
                      <?php } ?>
                    <?php } ?>
                  </tbody>
                </table>
              </div>
            <?php } ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
