<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <div class="pp-dash-head">
      <h4 class="pp-title">Failed Sync &amp; Reconciliation</h4>
      <?php if (is_admin() || staff_can('reconcile_run','payplex_aicalling')): ?>
        <?php echo form_open(admin_url('payplex_aicalling/reconciliation/run')); ?>
          <button class="btn btn-primary btn-sm" type="submit">Run reconciliation now</button>
        <?php echo form_close(); ?>
      <?php endif; ?>
    </div>

    <h5 class="pp-sub">Outbound queue (pending / failed / abandoned)</h5>
    <p class="text-muted" style="font-size:12px">
      <b>Abandoned</b> means the retry policy will not try this again on its own — a 4xx rejection,
      or the attempt ceiling was reached. It stays here until a human requeues or the underlying
      cause is otherwise resolved.
    </p>
    <div class="table-responsive">
      <table class="table pp-table">
        <thead><tr><th>Endpoint</th><th>Status</th><th>Attempts</th><th>Last error</th><th>Next retry</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($outbox)): ?><tr><td colspan="7" class="pp-empty">Nothing queued — all synced.</td></tr>
        <?php else: foreach ($outbox as $o): ?>
          <tr>
            <td class="mini"><?php echo html_escape($o->method.' '.$o->endpoint); ?></td>
            <td><span class="pp-badge pp-<?php echo $o->status==='abandoned'?'failed':($o->status==='failed'?'failed':'scheduled'); ?>"><?php echo html_escape($o->status); ?></span></td>
            <td><?php echo (int)$o->attempts; ?></td>
            <td class="mini"><?php echo html_escape($o->last_error ?: '—'); ?></td>
            <td class="mini"><?php echo $o->next_retry_at ? _dt($o->next_retry_at) : '—'; ?></td>
            <td class="mini"><?php echo _dt($o->created_at); ?></td>
            <td>
              <?php if ($o->status === 'abandoned' && (is_admin() || staff_can('reconcile_run','payplex_aicalling'))): ?>
                <?php echo form_open(admin_url('payplex_aicalling/reconciliation/retry_outbox_item/'.$o->id), ['style'=>'display:inline']); ?>
                  <button class="btn btn-xs btn-default" type="submit">Retry anyway</button>
                <?php echo form_close(); ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <h5 class="pp-sub">Webhook issues (invalid signature / unprocessed)</h5>
    <div class="table-responsive">
      <table class="table pp-table">
        <thead><tr><th>Event ID</th><th>Signature</th><th>Processed</th><th>Received</th></tr></thead>
        <tbody>
        <?php if (empty($badWebhooks)): ?><tr><td colspan="4" class="pp-empty">No webhook issues.</td></tr>
        <?php else: foreach ($badWebhooks as $w): ?>
          <tr>
            <td class="mini"><?php echo html_escape($w->event_id); ?></td>
            <td><?php echo ((int)$w->signature_valid===1)?'<span class="pp-badge pp-completed">valid</span>':'<span class="pp-badge pp-failed">invalid</span>'; ?></td>
            <td><?php echo ((int)$w->processed===1)?'yes':'<span class="pp-badge pp-scheduled">no</span>'; ?></td>
            <td class="mini"><?php echo _dt($w->received_at); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
