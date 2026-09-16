<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Knowledge Base <span class="text-muted" style="font-size:13px">(<?php echo count($entries); ?>)</span></h4>
        <div>
          <a href="<?php echo admin_url('payplex_ai_agents/knowledge/create'); ?>" class="btn btn-primary btn-sm">+ Add Knowledge</a>
          <a href="<?php echo admin_url('payplex_ai_agents/agents'); ?>" class="btn btn-default btn-sm">Back to Agents</a>
        </div>
      </div>
      <p class="text-muted" style="font-size:12px">Agents answer <strong>only</strong> from active + indexed entries that are permitted for them. A query with no confident permitted match is escalated to the Review Queue.</p>

      <div class="panel_s" style="background:#f4f8fb"><div class="panel-body">
        <strong>Test: answer from knowledge</strong>
        <?php echo form_open(admin_url('payplex_ai_agents/knowledge/ask'), array('class' => 'form-inline', 'style' => 'margin-top:6px')); ?>
          <div class="form-group"><input class="form-control input-sm" name="agent_id" placeholder="Agent id" style="width:90px"></div>
          <div class="form-group"><input class="form-control input-sm" name="query" placeholder="Customer question..." style="width:340px"></div>
          <button class="btn btn-info btn-sm" type="submit">Ask</button>
        <?php echo form_close(); ?>
      </div></div>

      <div class="table-responsive"><table class="table table-striped table-bordered">
        <thead><tr><th>#</th><th>Title</th><th>Category</th><th>Scope</th><th>Indexing</th><th>Active</th><th>Ver</th><th>Updated</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($entries)): ?>
          <tr><td colspan="9" class="text-muted">No knowledge yet. Add product info, FAQs, pricing, policies or scripts.</td></tr>
        <?php else: foreach ($entries as $e): ?>
          <tr>
            <td><?php echo (int) $e->id; ?></td>
            <td><a href="<?php echo admin_url('payplex_ai_agents/knowledge/edit/' . (int) $e->id); ?>"><?php echo html_escape($e->title); ?></a></td>
            <td><span class="label label-default"><?php echo html_escape((string) $e->category); ?></span></td>
            <td><?php echo ($e->scope === 'all' || $e->scope === '' || $e->scope === null) ? '<span class="text-muted">all agents</span>' : html_escape((string) $e->scope); ?></td>
            <td><?php echo $e->indexing_status === 'indexed' ? '<span class="label label-success">indexed</span>' : '<span class="label label-warning">' . html_escape((string) $e->indexing_status) . '</span>'; ?></td>
            <td><?php echo (int) $e->is_active === 1 ? '<span class="label label-success">yes</span>' : '<span class="label label-default">no</span>'; ?></td>
            <td><?php echo (int) $e->version; ?></td>
            <td style="font-size:12px"><?php echo html_escape((string) $e->lastupdated); ?></td>
            <td style="white-space:nowrap">
              <a href="<?php echo admin_url('payplex_ai_agents/knowledge/edit/' . (int) $e->id); ?>" class="btn btn-default btn-xs">Edit</a>
              <a href="<?php echo admin_url('payplex_ai_agents/knowledge/toggle/' . (int) $e->id); ?>" class="btn btn-default btn-xs"><?php echo (int) $e->is_active === 1 ? 'Disable' : 'Enable'; ?></a>
            </td>
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
