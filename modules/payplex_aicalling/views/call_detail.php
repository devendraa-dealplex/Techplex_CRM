<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-10 col-md-offset-1">
  <div class="panel_s"><div class="panel-body">
    <div class="pp-dash-head">
      <h4 class="pp-title">Call #<?php echo (int) $call->id; ?></h4>
      <div>
        <?php if ($call->recording_available && $can_view_recording): ?>
          <a class="btn btn-default btn-sm" href="<?php echo admin_url('payplex_aicalling/aicalling/recording/' . (int) $call->id); ?>">&#9654; Recording</a>
        <?php endif; ?>
        <?php if ($call->transcript_available && $can_view_transcript): ?>
          <a class="btn btn-default btn-sm" href="<?php echo admin_url('payplex_aicalling/aicalling/transcript/' . (int) $call->id); ?>">Transcript</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="row" style="margin-bottom:16px;">
      <div class="col-sm-3"><b>Lead</b><br><?php echo $call->crm_lead_id ? '#' . (int) $call->crm_lead_id : '—'; ?></div>
      <div class="col-sm-3"><b>Status</b><br><span class="pp-badge pp-<?php echo html_escape($call->status); ?>"><?php echo html_escape($call->status); ?></span></div>
      <div class="col-sm-3"><b>Disposition</b><br><?php echo html_escape($call->disposition ?: '—'); ?></div>
      <div class="col-sm-3"><b>Duration</b><br><?php echo $call->duration_sec ? gmdate('i:s', $call->duration_sec) : '—'; ?></div>
    </div>
    <div class="row" style="margin-bottom:16px;">
      <div class="col-sm-3"><b>Cost</b><br><?php echo $call->cost !== null ? app_format_money($call->cost, $call->currency) : '—'; ?></div>
      <div class="col-sm-3"><b>Sentiment</b><br><?php echo html_escape($call->sentiment ?: '—'); ?></div>
      <div class="col-sm-3"><b>Detected intent</b><br><?php echo html_escape($call->detected_intent ?: '—'); ?></div>
      <div class="col-sm-3"><b>Agent</b><br><?php echo html_escape($call->agent_id ?: '—'); ?></div>
    </div>
    <?php if (!empty($call->failure_reason)): ?>
      <div class="alert alert-danger" style="font-size:13px">Failure reason: <?php echo html_escape($call->failure_reason); ?></div>
    <?php endif; ?>
    <?php if (!empty($call->callback_date)): ?>
      <div class="alert alert-warning" style="font-size:13px"><b>Callback requested:</b> <?php echo _dt($call->callback_date); ?> <span class="text-muted">(a reminder was created on the lead)</span></div>
    <?php endif; ?>
    <?php if (!empty($next_action)): ?>
      <div class="alert alert-info" style="font-size:13px"><b>Suggested next action:</b> <?php echo html_escape($next_action); ?></div>
    <?php endif; ?>
    <?php if (!empty($call->objections_json)): ?>
      <?php $obj = json_decode($call->objections_json, true); ?>
      <?php if ($obj): ?>
        <p><b>Objections:</b> <?php echo html_escape(implode(', ', array_map('strval', $obj))); ?></p>
      <?php endif; ?>
    <?php endif; ?>

    <h5 class="pp-sub">Event timeline</h5>
    <div class="table-responsive">
      <table class="table pp-table">
        <thead><tr><th>Seq</th><th>Event</th><th>Occurred at</th><th>Recorded at</th></tr></thead>
        <tbody>
        <?php if (empty($events)): ?>
          <tr><td colspan="4" class="pp-empty">No events recorded for this call yet.</td></tr>
        <?php else: foreach ($events as $e): ?>
          <tr>
            <td><?php echo (int) $e->sequence; ?></td>
            <td><?php echo html_escape($e->event); ?></td>
            <td><?php echo $e->occurred_at ? _dt($e->occurred_at) : '—'; ?></td>
            <td><?php echo _dt($e->created_at); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <p style="margin-top:16px;">
      <a href="<?php echo admin_url('payplex_aicalling/aicalling/history'); ?>" class="btn btn-default btn-sm">&larr; Back to call history</a>
    </p>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
