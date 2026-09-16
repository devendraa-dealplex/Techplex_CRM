<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">

    <?php if (!empty($alerts)) { ?>
      <div class="row"><div class="col-md-12">
        <?php foreach ($alerts as $a) {
            $cls = $a['severity'] === 'critical' ? 'danger' : ($a['severity'] === 'warning' ? 'warning' : 'info'); ?>
          <div class="alert alert-<?= $cls; ?>">
            <strong><?= strtoupper(html_escape($a['severity'])); ?></strong>
            — <?= html_escape($a['message']); ?>
          </div>
        <?php } ?>
      </div></div>
    <?php } else { ?>
      <div class="row"><div class="col-md-12">
        <div class="alert alert-success"><?= _l('facebook_reports_no_alerts'); ?></div>
      </div></div>
    <?php } ?>

    <div class="row">
      <div class="col-md-12">
        <div class="panel_s"><div class="panel-body">
          <h4 class="no-margin"><?= html_escape($title); ?></h4>
          <p class="text-muted">
            <?= _l('facebook_reports_intro'); ?>
            <?= _l('facebook_reports_window'); ?>: <strong><?= (int) $hours; ?>h</strong>
          </p>
          <hr class="hr-panel-heading" />

          <div class="row">
            <?php
            $tiles = array(
              'created'   => array('facebook_bucket_created',   'success'),
              'rejected'  => array('facebook_bucket_rejected',  'danger'),
              'failed'    => array('facebook_bucket_failed',     'danger'),
              'duplicate' => array('facebook_bucket_duplicate', 'info'),
            );

            foreach ($tiles as $key => $meta) { ?>
              <div class="col-md-3">
                <div class="panel_s"><div class="panel-body text-center">
                  <h2 class="bold text-<?= $meta[1]; ?> no-margin"><?= (int) $summary['buckets'][$key]; ?></h2>
                  <span class="text-muted"><?= _l($meta[0]); ?></span>
                </div></div>
              </div>
            <?php } ?>
          </div>

          <table class="table table-condensed">
            <tbody>
              <tr><td><?= _l('facebook_reports_total'); ?></td><td><strong><?= (int) $summary['total']; ?></strong></td></tr>
              <tr><td><?= _l('facebook_reports_accepted'); ?></td><td><?= (int) $summary['accepted']; ?></td></tr>
              <tr><td><?= _l('facebook_reports_not_accepted'); ?></td><td><?= (int) $summary['not_accepted']; ?></td></tr>
              <tr><td><?= _l('facebook_bucket_matched'); ?></td><td><?= (int) $summary['buckets']['matched']; ?></td></tr>
              <tr><td><?= _l('facebook_bucket_review'); ?></td><td><?= (int) $summary['buckets']['review']; ?></td></tr>
              <tr><td><?= _l('facebook_bucket_no_action'); ?></td><td><?= (int) $summary['buckets']['no_action']; ?></td></tr>
              <tr><td><?= _l('facebook_bucket_verify'); ?></td><td><?= (int) $summary['buckets']['verify']; ?></td></tr>
              <tr>
                <td><?= _l('facebook_retry_pending'); ?></td>
                <td><strong class="<?= $retry_pending > 0 ? 'text-warning' : ''; ?>"><?= (int) $retry_pending; ?></strong></td>
              </tr>
              <tr>
                <td><?= _l('facebook_unowned_leads'); ?></td>
                <td><strong class="<?= $review_count > 0 ? 'text-warning' : ''; ?>"><?= (int) $review_count; ?></strong></td>
              </tr>
              <tr>
                <td><?= _l('facebook_last_accepted'); ?></td>
                <td><?= $last_accepted ? html_escape(date('Y-m-d H:i:s', (int) $last_accepted))
                        . ' <small class="text-muted">(epoch ' . (int) $last_accepted . ')</small>'
                        : '<span class="text-muted">' . _l('facebook_never') . '</span>'; ?></td>
              </tr>
            </tbody>
          </table>

          <?php if (!empty($summary['unknown'])) { ?>
            <div class="alert alert-warning">
              <?= _l('facebook_reports_unknown_outcome'); ?>:
              <strong><?= html_escape(implode(', ', $summary['unknown'])); ?></strong>
            </div>
          <?php } ?>

          <a href="<?= admin_url('leadgen_facebook/deliveries'); ?>" class="btn btn-default btn-sm"><?= _l('facebook_view_deliveries'); ?></a>
          <a href="<?= admin_url('leadgen_facebook/review'); ?>" class="btn btn-default btn-sm"><?= _l('facebook_view_review_queue'); ?></a>
          <a href="<?= admin_url('leadgen_facebook'); ?>" class="btn btn-default btn-sm"><?= _l('leadgen_facebook_settings'); ?></a>
        </div></div>
      </div>
    </div>

    <div class="row">
      <div class="col-md-6">
        <div class="panel_s"><div class="panel-body">
          <h5 class="no-margin"><?= _l('facebook_by_outcome'); ?></h5>
          <hr class="hr-panel-heading" />
          <?php if (empty($totals)) { ?>
            <p class="text-muted"><?= _l('facebook_no_deliveries'); ?></p>
          <?php } else { ?>
            <table class="table table-condensed">
              <thead><tr>
                <th><?= _l('facebook_outcome'); ?></th>
                <th><?= _l('facebook_count'); ?></th>
                <th><?= _l('facebook_retryable'); ?></th>
                <th><?= _l('facebook_last_seen'); ?></th>
              </tr></thead>
              <tbody>
                <?php foreach ($totals as $t) { ?>
                  <tr>
                    <td>
                      <span class="label label-<?= $t['accepted'] ? 'success' : 'danger'; ?>">
                        <?= html_escape($t['outcome']); ?>
                      </span>
                      <br /><small class="text-muted"><?= html_escape(Facebook_health::bucketFor($t['outcome'])); ?></small>
                    </td>
                    <td><?= (int) $t['n']; ?></td>
                    <td><?= $t['retryable'] ? _l('facebook_yes_retry') : '—'; ?></td>
                    <td><small><?= html_escape($t['last_seen']); ?></small></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          <?php } ?>
        </div></div>
      </div>

      <div class="col-md-6">
        <div class="panel_s"><div class="panel-body">
          <h5 class="no-margin"><?= _l('facebook_retry_monitor'); ?></h5>
          <p class="text-muted"><small><?= _l('facebook_retry_monitor_hint'); ?></small></p>
          <hr class="hr-panel-heading" />
          <?php if (empty($retryable)) { ?>
            <p class="text-muted"><?= _l('facebook_retry_none'); ?></p>
          <?php } else { ?>
            <table class="table table-condensed">
              <thead><tr>
                <th><?= _l('facebook_received'); ?></th>
                <th><?= _l('facebook_request_id'); ?></th>
                <th><?= _l('facebook_outcome'); ?></th>
                <th><?= _l('facebook_reason'); ?></th>
              </tr></thead>
              <tbody>
                <?php foreach ($retryable as $r) { ?>
                  <tr>
                    <td class="text-nowrap"><small><?= html_escape($r['received_at']); ?></small></td>
                    <td><code><?= html_escape($r['request_id']); ?></code></td>
                    <td><small><?= html_escape($r['outcome']); ?> · <?= (int) $r['http_status']; ?></small></td>
                    <td><small><?= html_escape($r['failure_reason']); ?></small></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          <?php } ?>
        </div></div>
      </div>
    </div>

    <div class="row">
      <div class="col-md-12">
        <div class="panel_s"><div class="panel-body">
          <h5 class="no-margin"><?= _l('facebook_credential_history'); ?></h5>
          <p class="text-muted"><small><?= _l('facebook_credential_history_hint'); ?></small></p>
          <hr class="hr-panel-heading" />

          <table class="table table-condensed">
            <tbody>
              <?php foreach ($credential_state as $name => $line) { ?>
                <tr>
                  <td><code><?= html_escape($name); ?></code></td>
                  <td><strong><?= html_escape($line); ?></strong></td>
                </tr>
              <?php } ?>
            </tbody>
          </table>

          <?php if (empty($credential_events)) { ?>
            <p class="text-muted"><?= _l('facebook_credential_history_empty'); ?></p>
          <?php } else { ?>
            <table class="table table-striped table-condensed">
              <thead><tr>
                <th><?= _l('facebook_when'); ?></th>
                <th><?= _l('facebook_credential'); ?></th>
                <th><?= _l('facebook_action'); ?></th>
                <th><?= _l('facebook_fingerprint_change'); ?></th>
                <th><?= _l('facebook_length_change'); ?></th>
                <th><?= _l('facebook_by'); ?></th>
              </tr></thead>
              <tbody>
                <?php foreach ($credential_events as $e) { ?>
                  <tr>
                    <td class="text-nowrap"><small><?= html_escape($e['changed_at']); ?></small></td>
                    <td><code><?= html_escape($e['option_name']); ?></code></td>
                    <td>
                      <span class="label label-<?= $e['action'] === 'cleared' ? 'warning' : 'default'; ?>">
                        <?= html_escape($e['action']); ?>
                      </span>
                    </td>
                    <td>
                      <small>
                        <?= $e['fp_before'] === '' || $e['fp_before'] === null ? '—' : html_escape($e['fp_before']); ?>
                        &rarr;
                        <?= $e['fp_after'] === '' || $e['fp_after'] === null ? '—' : html_escape($e['fp_after']); ?>
                      </small>
                    </td>
                    <td>
                      <small><?= (int) $e['len_before']; ?> &rarr; <?= (int) $e['len_after']; ?></small>
                      <?php if ((int) $e['len_before'] > 0 && (int) $e['len_after'] > 0
                                && (int) $e['len_after'] < (int) $e['len_before'] / 2) { ?>
                        <br /><span class="label label-warning"><?= _l('facebook_much_shorter'); ?></span>
                      <?php } ?>
                    </td>
                    <td><small>staff <?= (int) $e['changed_by']; ?></small></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          <?php } ?>
        </div></div>
      </div>
    </div>

  </div>
</div>
<?php init_tail(); ?>
