<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Lead Pipeline Activity</h4>
        <a href="<?php echo admin_url('payplex_ai_agents/agents'); ?>" class="btn btn-default btn-sm">Back to Agents</a>
      </div>

      <div class="alert <?php echo $auto_run ? 'alert-warning' : 'alert-info'; ?>">
        <strong>Auto-run on new leads:</strong> <?php echo $auto_run ? '<span class="label label-warning">ON</span>' : '<span class="label label-success">OFF</span>'; ?>
        <?php echo form_open(admin_url('payplex_ai_agents/pipeline/toggle_auto'), array('style' => 'display:inline;margin-left:8px')); ?>
          <button class="btn btn-xs <?php echo $auto_run ? 'btn-default' : 'btn-primary'; ?>" type="submit"><?php echo $auto_run ? 'Disable auto-run' : 'Enable auto-run'; ?></button>
        <?php echo form_close(); ?>
        <div class="text-muted" style="font-size:11px;margin-top:4px">When ON, each new/converted lead is run through the pipeline in <strong>sandbox</strong> (validate → dedupe → score → assign → follow-up) and the recommendations are logged. It never changes the lead. OFF by default so the live lead flow is untouched.</div>
      </div>

      <div class="panel_s" style="background:#f4f8fb"><div class="panel-body">
        <strong>Test: run pipeline on a lead (sandbox)</strong>
        <?php echo form_open(admin_url('payplex_ai_agents/pipeline/test'), array('class' => 'form-inline', 'style' => 'margin-top:6px')); ?>
          <div class="form-group"><input class="form-control input-sm" name="lead_id" placeholder="Lead id (e.g. 1192)" style="width:180px"></div>
          <button class="btn btn-info btn-sm" type="submit">Run pipeline</button>
        <?php echo form_close(); ?>
      </div></div>

      <div class="table-responsive"><table class="table table-striped table-bordered" style="font-size:12px">
        <thead><tr><th>#</th><th>When</th><th>Lead</th><th>Event</th><th>Valid</th><th>Score</th><th>Duplicates</th><th>Suggest staff</th><th>Escalated</th></tr></thead>
        <tbody>
        <?php if (empty($events)): ?><tr><td colspan="9" class="text-muted">No pipeline activity yet. Run a test above, or enable auto-run.</td></tr><?php else: foreach ($events as $e): ?>
          <tr>
            <td><?php echo (int) $e->id; ?></td>
            <td><?php echo html_escape((string) $e->datecreated); ?></td>
            <td>#<?php echo (int) $e->lead_id; ?></td>
            <td><span class="label label-default"><?php echo html_escape($e->event_type); ?></span></td>
            <td><?php echo (int) $e->valid === 1 ? '<span class="label label-success">yes</span>' : '<span class="label label-danger">no</span>'; ?></td>
            <td><strong><?php echo (int) $e->score; ?></strong>/100</td>
            <td><?php echo $e->duplicates ? '<span class="label label-warning">#' . html_escape($e->duplicates) . '</span>' : '<span class="text-muted">none</span>'; ?></td>
            <td><?php echo $e->assign_staff ? '#' . (int) $e->assign_staff : '-'; ?></td>
            <td><?php echo (int) $e->escalated === 1 ? '<span class="label label-warning">yes</span>' : 'no'; ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div></div></div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
