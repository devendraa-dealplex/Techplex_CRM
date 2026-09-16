<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $cv = isset($companyView) ? $companyView : ''; ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-10">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Knowledge Retrieval</h4>
        <a href="<?php echo admin_url('payplex_ai_agents/execknowledge'); ?>" class="btn btn-default btn-sm">Back</a>
      </div>
      <div class="alert alert-info" style="font-size:12px">Deterministic, grounded retrieval — the same query always returns the same ranked evidence, and <strong>only approved, in-effect entries you are permitted to see</strong> can appear. This is what an agent is allowed to cite.</div>

      <div class="panel_s"><div class="panel-body">
        <form method="get" action="<?php echo admin_url('payplex_ai_agents/execknowledge/search'); ?>" class="form-inline">
          <div class="form-group" style="width:45%"><input class="form-control" name="q" style="width:100%" placeholder="Ask the knowledge base… e.g. refund policy" value="<?php echo html_escape($q); ?>"></div>
          <div class="form-group"><select class="form-control" name="category">
            <option value="">any category</option>
            <?php foreach ($categories as $c): ?><option value="<?php echo $c; ?>" <?php echo $category===$c?'selected':''; ?>><?php echo ucfirst($c); ?></option><?php endforeach; ?>
          </select></div>
          <?php if (!empty($companies)): ?>
          <div class="form-group"><select class="form-control" name="company">
            <option value="">Group (all)</option>
            <?php foreach ($companies as $c): ?><option value="<?php echo html_escape($c->code); ?>" <?php echo $cv===$c->code?'selected':''; ?>><?php echo html_escape($c->name); ?></option><?php endforeach; ?>
          </select></div>
          <?php endif; ?>
          <button class="btn btn-info" type="submit"><i class="fa fa-search"></i> Retrieve</button>
        </form>
      </div></div>

      <?php if ($q !== ''): ?>
        <?php if (empty($results)): ?>
          <div class="alert alert-warning" style="font-size:13px">No grounded knowledge matches “<?php echo html_escape($q); ?>”. An honest agent would escalate here rather than answer from nothing.</div>
        <?php else: ?>
          <?php foreach ($results as $r): $e = $r['item']; ?>
            <div class="panel_s"><div class="panel-body">
              <div style="display:flex;justify-content:space-between">
                <strong><a href="<?php echo admin_url('payplex_ai_agents/execknowledge/view/' . (int) $e->id); ?>"><?php echo html_escape($e->title); ?></a></strong>
                <span class="label label-primary">score <?php echo number_format((float) $r['score'], 2); ?></span>
              </div>
              <p style="font-size:12px;margin:6px 0"><?php echo html_escape(mb_strimwidth((string) $e->body, 0, 220, '…')); ?></p>
              <p class="text-muted" style="font-size:11px"><?php echo html_escape(Payplex_agent_memory::provenance($e)); ?></p>
            </div></div>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
