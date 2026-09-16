<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$labels = array(
    'move_to_sandbox' => array('Move to Sandbox', 'btn-info'),
    'start_testing'   => array('Start Testing', 'btn-info'),
    'submit'          => array('Submit for Approval', 'btn-warning'),
    'approve'         => array('Approve', 'btn-success'),
    'reject'          => array('Reject', 'btn-danger'),
    'schedule'        => array('Schedule', 'btn-primary'),
    'pause'           => array('Pause', 'btn-warning'),
    'resume'          => array('Resume', 'btn-success'),
    'archive'         => array('Archive', 'btn-default'),
    'restore'         => array('Restore', 'btn-default'),
    'new_version'     => array('Edit as New Version', 'btn-default'),
);
$trans = class_exists('Payplex_agent_lifecycle') ? Payplex_agent_lifecycle::transitions() : array();
$available = array();
foreach ($trans as $act => $fromMap) {
    if (isset($fromMap[$agent->status]) && $act !== 'activate') {
        $available[] = $act;
    }
}
function jlist($v) { return is_array($v) ? implode(', ', $v) : (string) $v; }
?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <?php if (!empty($global_kill)): ?>
      <div class="alert alert-danger"><strong>GLOBAL KILL SWITCH ENGAGED</strong> — no agent can execute.</div>
    <?php endif; ?>
    <?php if ((int) $agent->agent_kill === 1): ?>
      <div class="alert alert-danger"><strong>This agent's kill switch is ENGAGED</strong> — it is stopped.</div>
    <?php endif; ?>

    <div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <div>
          <h4 class="no-margin" style="color:#12507F;font-weight:600"><?php echo html_escape($agent->name); ?></h4>
          <span><?php echo payplex_ai_status_badge($agent->status); ?>
            <span class="label label-<?php echo $agent->mode === 'production' ? 'danger' : 'default'; ?>">mode: <?php echo html_escape($agent->mode); ?></span>
            <span class="text-muted">v<?php echo (int) $agent->version; ?></span>
          </span>
        </div>
        <div>
          <a href="<?php echo admin_url('payplex_ai_agents/agents/edit/' . (int) $agent->id); ?>" class="btn btn-default btn-sm">Edit</a>
          <a href="<?php echo admin_url('payplex_ai_agents/agents/clone_agent/' . (int) $agent->id); ?>" class="btn btn-default btn-sm">Clone</a>
          <a href="<?php echo admin_url('payplex_ai_agents/agents'); ?>" class="btn btn-default btn-sm">Back</a>
        </div>
      </div>

      <hr>
      <strong>Lifecycle:</strong>
      <?php foreach ($available as $act): $lb = $labels[$act]; ?>
        <?php echo form_open(admin_url('payplex_ai_agents/agents/action/' . (int) $agent->id . '/' . $act), array('style' => 'display:inline-block;margin:2px')); ?>
          <button class="btn btn-sm <?php echo $lb[1]; ?>" type="submit"><?php echo $lb[0]; ?></button>
        <?php echo form_close(); ?>
      <?php endforeach; ?>

      <?php if ($agent->status === 'approved' || ($agent->status === 'active' && $agent->mode !== 'production')): ?>
        <?php echo form_open(admin_url('payplex_ai_agents/agents/activate_production/' . (int) $agent->id), array('style' => 'display:inline-block;margin:2px', 'onsubmit' => 'return confirm("Activate this agent in PRODUCTION mode? Real actions will still each require their own approval.");')); ?>
          <button class="btn btn-sm btn-danger" type="submit">Activate in Production</button>
        <?php echo form_close(); ?>
      <?php endif; ?>

      <?php echo form_open(admin_url('payplex_ai_agents/agents/kill/' . (int) $agent->id), array('style' => 'display:inline-block;margin:2px')); ?>
        <button class="btn btn-sm <?php echo (int) $agent->agent_kill === 1 ? 'btn-success' : 'btn-danger'; ?>" type="submit"><?php echo (int) $agent->agent_kill === 1 ? 'Release Kill Switch' : 'KILL (emergency stop)'; ?></button>
      <?php echo form_close(); ?>
    </div></div></div></div>

    <div class="row">
      <div class="col-md-7">
        <div class="panel_s"><div class="panel-body">
          <h5 style="margin-top:0">Configuration</h5>
          <table class="table table-bordered" style="font-size:13px">
            <tr><td width="180"><strong>Department</strong></td><td><?php echo html_escape((string) $agent->department); ?></td></tr>
            <tr><td><strong>Purpose</strong></td><td><?php echo html_escape((string) $agent->purpose); ?></td></tr>
            <tr><td><strong>Provider / Model</strong></td><td><?php echo html_escape($agent->ai_provider . ' / ' . $agent->ai_model); ?></td></tr>
            <tr><td><strong>System prompt</strong></td><td><?php echo nl2br(html_escape((string) $agent->system_prompt)); ?></td></tr>
            <tr><td><strong>Allowed tools</strong></td><td><?php echo html_escape(jlist($agent->allowed_tools)); ?></td></tr>
            <tr><td><strong>Triggers</strong></td><td><?php echo html_escape(jlist($agent->triggers)); ?></td></tr>
            <tr><td><strong>Prohibited actions</strong></td><td><?php echo html_escape(jlist($agent->prohibited_actions)); ?></td></tr>
            <tr><td><strong>Approval-required</strong></td><td><?php echo html_escape(jlist($agent->approval_required_actions)); ?></td></tr>
            <tr><td><strong>Confidence threshold</strong></td><td><?php echo html_escape((string) $agent->confidence_threshold); ?></td></tr>
            <tr><td><strong>Limits</strong></td><td>tokens <?php echo (int) $agent->token_limit; ?> · daily runs <?php echo (int) $agent->daily_execution_limit; ?> · retry <?php echo (int) $agent->retry_limit; ?></td></tr>
            <tr><td><strong>Budget</strong></td><td>$<?php echo html_escape((string) $agent->daily_budget); ?>/day · $<?php echo html_escape((string) $agent->monthly_budget); ?>/mo</td></tr>
            <tr><td><strong>Owner / Reviewer / Approver</strong></td><td><?php echo (int) $agent->owner_id; ?> / <?php echo (int) $agent->reviewer_id; ?> / <?php echo (int) $agent->approver_id; ?></td></tr>
            <tr><td><strong>Created by / Submitted by / Approved by</strong></td><td><?php echo (int) $agent->created_by; ?> / <?php echo (int) $agent->submitted_by; ?> / <?php echo (int) $agent->approved_by; ?></td></tr>
          </table>
        </div></div>
      </div>

      <div class="col-md-5">
        <div class="panel_s"><div class="panel-body">
          <h5 style="margin-top:0">Sandbox Test <span class="label label-info">dry-run</span></h5>
          <p class="text-muted" style="font-size:12px">Runs the pipeline safely: no real calls, messages or CRM changes. Estimates tokens/cost and shows what would need approval.</p>
          <?php echo form_open(admin_url('payplex_ai_agents/agents/test/' . (int) $agent->id)); ?>
            <div class="form-group"><input class="form-control input-sm" name="t_name" placeholder="Sample lead name" value="Test Lead"></div>
            <div class="form-group"><input class="form-control input-sm" name="t_email" placeholder="Email" value="test@example.com"></div>
            <div class="form-group"><input class="form-control input-sm" name="t_phone" placeholder="Phone" value="+910000000000"></div>
            <div class="form-group"><input class="form-control input-sm" name="t_company" placeholder="Company"></div>
            <div class="form-group"><textarea class="form-control input-sm" name="t_message" placeholder="Message / context" rows="2"></textarea></div>
            <button class="btn btn-info btn-sm btn-block" type="submit">Run Sandbox Test</button>
          <?php echo form_close(); ?>
        </div></div>
      </div>
    </div>

    <div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
      <h5 style="margin-top:0">Recent Runs</h5>
      <div class="table-responsive"><table class="table table-striped" style="font-size:12px">
        <thead><tr><th>#</th><th>When</th><th>Mode</th><th>Status</th><th>Model</th><th>Tokens</th><th>Cost</th><th>Conf.</th><th>Simulated</th><th>Blocked</th><th>Escalations</th></tr></thead>
        <tbody>
        <?php if (empty($runs)): ?><tr><td colspan="11" class="text-muted">No runs yet. Try a sandbox test.</td></tr><?php else: foreach ($runs as $r): ?>
          <tr>
            <td><?php echo (int) $r->id; ?></td>
            <td><?php echo html_escape((string) $r->datecreated); ?></td>
            <td><span class="label label-<?php echo $r->mode === 'production' ? 'danger' : 'default'; ?>"><?php echo html_escape($r->mode); ?></span></td>
            <td><?php echo html_escape($r->status); ?></td>
            <td><?php echo html_escape((string) $r->model); ?></td>
            <td><?php echo (int) $r->tokens; ?></td>
            <td>$<?php echo number_format((float) $r->cost, 6); ?></td>
            <td><?php echo html_escape((string) $r->confidence); ?></td>
            <td><?php echo (int) $r->simulated_actions; ?></td>
            <td><?php echo (int) $r->blocked_actions; ?></td>
            <td><?php echo (int) $r->escalations; ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div></div></div></div>

    <div class="row">
      <div class="col-md-6"><div class="panel_s"><div class="panel-body">
        <h5 style="margin-top:0">Version History</h5>
        <table class="table" style="font-size:12px">
          <thead><tr><th>Snap#</th><th>Ver</th><th>Note</th><th>When</th><th></th></tr></thead>
          <tbody>
          <?php if (empty($versions)): ?><tr><td colspan="5" class="text-muted">No snapshots.</td></tr><?php else: foreach ($versions as $v): ?>
            <tr>
              <td><?php echo (int) $v->id; ?></td><td><?php echo (int) $v->version; ?></td>
              <td><?php echo html_escape((string) $v->note); ?></td>
              <td><?php echo html_escape((string) $v->datecreated); ?></td>
              <td>
                <?php echo form_open(admin_url('payplex_ai_agents/agents/rollback/' . (int) $agent->id . '/' . (int) $v->id), array('style' => 'display:inline', 'onsubmit' => 'return confirm("Roll back to this snapshot? Agent resets to draft/sandbox for re-approval.");')); ?>
                  <button class="btn btn-default btn-xs" type="submit">Rollback</button>
                <?php echo form_close(); ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div></div></div>

      <div class="col-md-6"><div class="panel_s"><div class="panel-body">
        <h5 style="margin-top:0">Recent Audit</h5>
        <table class="table" style="font-size:12px">
          <thead><tr><th>When</th><th>Event</th><th>Message</th></tr></thead>
          <tbody>
          <?php if (empty($audit)): ?><tr><td colspan="3" class="text-muted">No audit entries.</td></tr><?php else: foreach ($audit as $e): ?>
            <tr>
              <td><?php echo html_escape((string) $e->datecreated); ?></td>
              <td><span class="label label-default"><?php echo html_escape($e->event_type); ?></span></td>
              <td><?php echo html_escape((string) $e->message); ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        <a href="<?php echo admin_url('payplex_ai_agents/agents/audit/' . (int) $agent->id); ?>" class="btn btn-default btn-xs">Full audit log</a>
      </div></div></div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
