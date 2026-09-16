<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <?php if (!empty($global_kill)): ?>
      <div class="alert alert-danger"><strong>GLOBAL KILL SWITCH ENGAGED.</strong> All agents are stopped. Release it in Settings.</div>
    <?php endif; ?>
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:14px">
              <h4 class="no-margin" style="color:#12507F;font-weight:600">AI Agents <span class="text-muted" style="font-size:13px">(<?php echo count($agents); ?>)</span></h4>
              <div>
                <a href="<?php echo admin_url('payplex_ai_agents/agents/templates'); ?>" class="btn btn-info btn-sm">From Template</a>
                <a href="<?php echo admin_url('payplex_ai_agents/agents/create'); ?>" class="btn btn-primary btn-sm">+ New Agent</a>
                <a href="<?php echo admin_url('payplex_ai_agents/agents/settings'); ?>" class="btn btn-default btn-sm">Settings</a>
              </div>
            </div>

            <div class="row" style="margin-bottom:12px">
              <div class="col-md-4"><div class="panel_s"><div class="panel-body"><span class="text-muted">Total runs</span><h4 class="no-margin"><?php echo (int) ($cost->runs ?? 0); ?></h4></div></div></div>
              <div class="col-md-4"><div class="panel_s"><div class="panel-body"><span class="text-muted">Tokens used</span><h4 class="no-margin"><?php echo number_format((int) ($cost->tokens ?? 0)); ?></h4></div></div></div>
              <div class="col-md-4"><div class="panel_s"><div class="panel-body"><span class="text-muted">Estimated cost</span><h4 class="no-margin">$<?php echo number_format((float) ($cost->cost ?? 0), 4); ?></h4></div></div></div>
            </div>

            <div class="table-responsive">
              <table class="table table-striped table-bordered">
                <thead><tr>
                  <th>#</th><th>Name</th><th>Department</th><th>Model</th><th>Status</th><th>Mode</th><th>Ver</th><th>Kill</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (empty($agents)): ?>
                  <tr><td colspan="9" class="text-muted">No agents yet. Create one, or start from a template.</td></tr>
                <?php else: foreach ($agents as $a): ?>
                  <tr>
                    <td><?php echo (int) $a->id; ?></td>
                    <td><a href="<?php echo admin_url('payplex_ai_agents/agents/view/' . (int) $a->id); ?>"><?php echo html_escape($a->name); ?></a></td>
                    <td><?php echo html_escape((string) $a->department); ?></td>
                    <td><?php echo html_escape((string) $a->ai_model); ?></td>
                    <td><?php echo payplex_ai_status_badge($a->status); ?></td>
                    <td><span class="label label-<?php echo $a->mode === 'production' ? 'danger' : 'default'; ?>"><?php echo html_escape($a->mode); ?></span></td>
                    <td><?php echo (int) $a->version; ?></td>
                    <td><?php echo ((int) $a->agent_kill === 1) ? '<span class="label label-danger">STOP</span>' : '<span class="text-muted">-</span>'; ?></td>
                    <td><a href="<?php echo admin_url('payplex_ai_agents/agents/view/' . (int) $a->id); ?>" class="btn btn-default btn-xs">Open</a></td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
