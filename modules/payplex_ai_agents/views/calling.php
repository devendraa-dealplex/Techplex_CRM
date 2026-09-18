<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">AI Call Log</h4>
        <a href="<?php echo admin_url('payplex_ai_agents/agents'); ?>" class="btn btn-default btn-sm">Back to Agents</a>
      </div>

      <div class="alert <?php echo $allow_real ? 'alert-danger' : 'alert-info'; ?>">
        <strong>AI Calling backend:</strong> <?php echo $module_present ? '<span class="text-success">detected</span>' : '<span class="text-muted">not detected</span>'; ?> ·
        <strong>Real calls master switch:</strong> <?php echo $allow_real ? '<span class="label label-danger">ON</span>' : '<span class="label label-success">OFF</span>'; ?>
        <?php echo form_open(admin_url('payplex_ai_agents/calling/toggle_real'), array('style' => 'display:inline;margin-left:8px', 'onsubmit' => 'return confirm("Toggle the global real-calls master switch? Even when ON, calls still require production mode + agent approval + per-run approval, and this build never auto-dials.");')); ?>
          <button class="btn btn-xs <?php echo $allow_real ? 'btn-success' : 'btn-danger'; ?>" type="submit"><?php echo $allow_real ? 'Disable real calls' : 'Enable real calls'; ?></button>
        <?php echo form_close(); ?>
        <div class="text-muted" style="font-size:11px;margin-top:4px">Sandbox agents always simulate (no dialing). A real call needs: production mode + approved agent + per-run approval + this switch ON — and is then queued for operator dispatch, never auto-dialed.</div>
      </div>

      <div class="panel_s" style="background:#f4f8fb"><div class="panel-body">
        <strong>Test: simulate a call (sandbox)</strong>
        <?php echo form_open(admin_url('payplex_ai_agents/calling/test'), array('class' => 'form-inline', 'style' => 'margin-top:6px', 'id' => 'call-sim-form')); ?>
          <div class="form-group"><input class="form-control input-sm" name="agent_id" id="call-sim-agent-id" placeholder="Agent id" style="width:90px"></div>
          <div class="form-group"><input class="form-control input-sm" name="c_name" id="call-sim-lead-name" placeholder="Lead name" value="Test Lead" style="width:140px"></div>
          <div class="form-group">
            <input class="form-control input-sm" name="c_phone" id="call-sim-phone" placeholder="Phone" value="+910000000000" style="width:150px">
          </div>
          <div class="form-group"><input class="form-control input-sm" name="c_script" placeholder="Script" style="width:220px"></div>
          <button class="btn btn-info btn-sm" type="submit">Simulate</button>
          <div id="call-sim-phone-error" class="text-danger" style="display:none;width:100%;font-size:12px;margin-top:4px">Enter a valid 10-digit Indian mobile number (starting 6-9), e.g. +910000000000.</div>
        <?php echo form_close(); ?>
      </div></div>

      <div class="row" style="margin:6px 0 10px">
        <?php foreach ($summary as $s): $cls = $s->decision === 'blocked' ? 'danger' : ($s->decision === 'place_real' ? 'warning' : 'default'); ?>
          <div class="col-md-3"><div class="panel_s"><div class="panel-body">
            <span class="label label-<?php echo $cls; ?>"><?php echo html_escape($s->decision); ?></span>
            <h4 class="no-margin"><?php echo (int) $s->n; ?></h4>
            <span class="text-muted" style="font-size:11px">est. $<?php echo number_format((float) $s->cost, 4); ?></span>
          </div></div></div>
        <?php endforeach; ?>
      </div>

      <div class="table-responsive"><table class="table table-striped table-bordered" style="font-size:12px">
        <thead><tr><th>#</th><th>When</th><th>Agent</th><th>Lead</th><th>Mode</th><th>Decision</th><th>Reason</th><th>Outcome</th><th>Dur</th><th>Cost</th></tr></thead>
        <tbody>
        <?php if (empty($calls)): ?><tr><td colspan="10" class="text-muted">No calls yet. Try a sandbox simulation above.</td></tr><?php else: foreach ($calls as $c):
          $dc = $c->decision === 'blocked' ? 'danger' : ($c->decision === 'place_real' ? 'warning' : 'info'); ?>
          <tr>
            <td><?php echo (int) $c->id; ?></td>
            <td><?php echo html_escape((string) $c->datecreated); ?></td>
            <td>#<?php echo (int) $c->agent_id; ?></td>
            <td><?php echo $c->lead_id ? '#' . (int) $c->lead_id : '-'; ?></td>
            <td><span class="label label-<?php echo $c->mode === 'production' ? 'danger' : 'default'; ?>"><?php echo html_escape($c->mode); ?></span></td>
            <td><span class="label label-<?php echo $dc; ?>"><?php echo html_escape($c->decision); ?></span></td>
            <td><?php echo html_escape((string) $c->reason); ?></td>
            <td><?php echo html_escape((string) $c->outcome); ?></td>
            <td><?php echo (int) $c->duration; ?>s</td>
            <td>$<?php echo number_format((float) $c->cost, 4); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div></div></div></div>
  </div>
</div>
<?php init_tail(); ?>
<script>
$(function(){
  // Same rule enforced server-side in Calling::isValidPhone() - Indian mobiles only.
  var IN_RE = /^(?:\+91|91|0)?[6-9]\d{9}$/;
  var SAMPLE_PHONE = '+910000000000'; // the form's own default value - let through as-is

  function isValidPhone(raw) {
    var normalized = String(raw || '').replace(/[\s\-.()]/g, '');
    if (!normalized) { return false; }
    if (normalized === SAMPLE_PHONE) { return true; }
    return IN_RE.test(normalized);
  }

  $('#call-sim-form').on('submit', function(e){
    var agentIdRaw = $('#call-sim-agent-id').val().trim();
    var leadName    = $('#call-sim-lead-name').val().trim();
    var val         = $('#call-sim-phone').val().trim();

    if (agentIdRaw === '' || leadName === '' || val === '') {
      e.preventDefault();
      alert('Please fill out all required fields (Agent ID, Lead, and Phone Number) before simulating a call.');
      return false;
    }
    if (!/^\d+$/.test(agentIdRaw) || Number(agentIdRaw) <= 0) {
      e.preventDefault();
      alert('Please enter a valid Agent ID.');
      return false;
    }
    if (!isValidPhone(val)) {
      e.preventDefault();
      $('#call-sim-phone-error').show();
      $('#call-sim-phone').addClass('has-error');
      alert_float('warning', 'Enter a valid 10-digit Indian mobile number (starting 6-9), e.g. +910000000000.');
      return false;
    }
    $('#call-sim-phone-error').hide();
  });

  $('#call-sim-phone').on('input', function(){
    $('#call-sim-phone-error').hide();
  });
});
</script>
</body>
</html>
