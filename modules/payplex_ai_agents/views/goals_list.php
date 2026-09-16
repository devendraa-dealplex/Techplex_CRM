<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $cv = isset($companyView) ? $companyView : ''; ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Goals &amp; OKRs</h4>
        <div>
          <a href="<?php echo admin_url('payplex_ai_agents/goals/performance'); ?>" class="btn btn-default btn-sm"><i class="fa fa-line-chart"></i> Agent Performance</a>
          <?php if ($canManage): ?><a href="<?php echo admin_url('payplex_ai_agents/goals/create'); ?>" class="btn btn-info btn-sm"><i class="fa fa-plus"></i> New Objective</a><?php endif; ?>
        </div>
      </div>
      <div class="alert alert-info" style="font-size:12px">Objectives are made measurable by <strong>key results</strong> (baseline → current → target). Progress and health are computed automatically — “on track / at risk / off track” compares progress against how much of the period has elapsed. Scoped by company.</div>

      <form method="get" action="<?php echo admin_url('payplex_ai_agents/goals'); ?>" class="form-inline" style="margin-bottom:12px">
        <div class="form-group"><label style="font-size:12px">Period </label>
          <input class="form-control input-sm" name="period" placeholder="2026-Q3" value="<?php echo html_escape($period); ?>" style="width:110px"></div>
        <div class="form-group"><select class="form-control input-sm" name="status">
          <option value="">any status</option>
          <?php foreach (array('draft','active','achieved','missed','cancelled') as $s): ?><option value="<?php echo $s; ?>" <?php echo $status===$s?'selected':''; ?>><?php echo ucfirst($s); ?></option><?php endforeach; ?>
        </select></div>
        <?php if (!empty($companies)): ?>
        <div class="form-group"><select class="form-control input-sm" name="company">
          <option value="">Group (all)</option>
          <?php foreach ($companies as $c): ?><option value="<?php echo html_escape($c->code); ?>" <?php echo $cv===$c->code?'selected':''; ?>><?php echo html_escape($c->name); ?></option><?php endforeach; ?>
        </select></div>
        <?php endif; ?>
        <button class="btn btn-default btn-sm" type="submit">Filter</button>
      </form>

      <?php if (empty($rows)): ?><p class="text-muted">No objectives. Create one to start tracking OKRs.</p>
      <?php else: foreach ($rows as $r): $o=$r['o']; $pct=(int) round($r['progress']*100); $hc=Payplex_agent_goals::healthClass($r['health']);
        $bar = $hc==='success'?'progress-bar-success':($hc==='warning'?'progress-bar-warning':($hc==='danger'?'progress-bar-danger':'')); ?>
        <div class="panel_s"><div class="panel-body">
          <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px">
            <div>
              <a href="<?php echo admin_url('payplex_ai_agents/goals/view/' . (int) $o->id); ?>" style="font-weight:600"><?php echo html_escape($o->title); ?></a>
              <span class="label label-default"><?php echo html_escape($o->period); ?></span>
              <span class="label label-<?php echo $o->status==='active'?'primary':($o->status==='achieved'?'success':($o->status==='missed'?'danger':'default')); ?>"><?php echo html_escape($o->status); ?></span>
              <span class="label label-default"><?php echo $o->company ? html_escape($o->company) : 'group'; ?></span>
              <span class="text-muted" style="font-size:11px"><?php echo (int) $r['kr_count']; ?> key results</span>
            </div>
            <span class="label label-<?php echo $hc; ?>"><?php echo str_replace('_',' ',$r['health']); ?></span>
          </div>
          <div class="progress" style="margin:8px 0 2px;height:16px">
            <div class="progress-bar <?php echo $bar; ?>" style="width:<?php echo $pct; ?>%;min-width:2em"><?php echo $pct; ?>%</div>
          </div>
          <div class="text-muted" style="font-size:11px">Period elapsed: <?php echo (int) round($r['elapsed']*100); ?>%</div>
        </div></div>
      <?php endforeach; endif; ?>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
