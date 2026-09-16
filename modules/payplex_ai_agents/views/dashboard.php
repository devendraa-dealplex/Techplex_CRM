<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">AI Agents - Cost Dashboard</h4>
        <div>
          <a href="<?php echo admin_url('payplex_ai_agents/dashboard/queue'); ?>" class="btn btn-default btn-sm">Review Queue <span class="badge"><?php echo (int) $openQueue; ?></span></a>
          <?php echo form_open(admin_url('payplex_ai_agents/dashboard/enforce'), array('style' => 'display:inline')); ?>
            <button class="btn btn-warning btn-sm" type="submit">Run budget check (auto-pause)</button>
          <?php echo form_close(); ?>
        </div>
      </div>

      <div class="row">
        <div class="col-md-3"><div class="panel_s"><div class="panel-body"><span class="text-muted">Total runs</span><h4 class="no-margin"><?php echo (int) ($total->runs ?? 0); ?></h4></div></div></div>
        <div class="col-md-3"><div class="panel_s"><div class="panel-body"><span class="text-muted">Tokens</span><h4 class="no-margin"><?php echo number_format((int) ($total->tokens ?? 0)); ?></h4></div></div></div>
        <div class="col-md-3"><div class="panel_s"><div class="panel-body"><span class="text-muted">Total cost (est.)</span><h4 class="no-margin">$<?php echo number_format((float) ($total->cost ?? 0), 4); ?></h4></div></div></div>
        <div class="col-md-3"><div class="panel_s"><div class="panel-body"><span class="text-muted">Global budget</span><h4 class="no-margin" style="font-size:16px">$<?php echo html_escape((string) $global_daily); ?>/d · $<?php echo html_escape((string) $global_monthly); ?>/mo</h4></div></div></div>
      </div>
    </div></div></div></div>

    <div class="row">
      <div class="col-md-7"><div class="panel_s"><div class="panel-body">
        <h5 style="margin-top:0">Cost by agent · month-to-date budget status</h5>
        <div class="table-responsive"><table class="table table-striped" style="font-size:12px">
          <thead><tr><th>Agent</th><th>Mode</th><th>Status</th><th>MTD Tokens</th><th>MTD Cost</th><th>Budget use</th></tr></thead>
          <tbody>
          <?php if (empty($budgets)): ?><tr><td colspan="6" class="text-muted">No agents.</td></tr><?php else: foreach ($budgets as $b): $a = $b['agent']; $st = $b['status']; $u = $b['usage'];
            $lvl = $st['level']; $cls = $lvl === 'exceeded' ? 'danger' : ($lvl === 'warning' ? 'warning' : 'success'); ?>
            <tr>
              <td><a href="<?php echo admin_url('payplex_ai_agents/agents/view/' . (int) $a->id); ?>"><?php echo html_escape($a->name); ?></a></td>
              <td><span class="label label-<?php echo $a->mode === 'production' ? 'danger' : 'default'; ?>"><?php echo html_escape($a->mode); ?></span></td>
              <td><?php echo html_escape($a->status); ?></td>
              <td><?php echo number_format((int) $u->tokens); ?></td>
              <td>$<?php echo number_format((float) $u->cost, 6); ?></td>
              <td><span class="label label-<?php echo $cls; ?>"><?php echo round($st['pct'] * 100); ?>% <?php echo html_escape($lvl); ?></span></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table></div>
        <p class="text-muted" style="font-size:11px">"Run budget check" pauses any active agent whose month-to-date usage is over its hard token/budget limit and files a review item.</p>
      </div></div></div>

      <div class="col-md-5"><div class="panel_s"><div class="panel-body">
        <h5 style="margin-top:0">Cost by model</h5>
        <div class="table-responsive"><table class="table table-striped" style="font-size:12px">
          <thead><tr><th>Model</th><th>Runs</th><th>Tokens</th><th>Cost</th></tr></thead>
          <tbody>
          <?php if (empty($byModel)): ?><tr><td colspan="4" class="text-muted">No runs yet.</td></tr><?php else: foreach ($byModel as $mrow): ?>
            <tr><td><?php echo html_escape((string) $mrow->model); ?></td><td><?php echo (int) $mrow->runs; ?></td><td><?php echo number_format((int) $mrow->tokens); ?></td><td>$<?php echo number_format((float) $mrow->cost, 6); ?></td></tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </div></div></div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
