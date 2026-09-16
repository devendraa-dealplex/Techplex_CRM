<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $pct=(int) round($progress*100); $hc=Payplex_agent_goals::healthClass($health);
$bar = $hc==='success'?'progress-bar-success':($hc==='warning'?'progress-bar-warning':($hc==='danger'?'progress-bar-danger':'')); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-10">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600"><?php echo html_escape($o->title); ?></h4>
        <a href="<?php echo admin_url('payplex_ai_agents/goals'); ?>" class="btn btn-default btn-sm">Back</a>
      </div>

      <div class="panel_s"><div class="panel-body">
        <p>
          <span class="label label-default"><?php echo html_escape($o->period); ?></span>
          <span class="label label-<?php echo $o->status==='active'?'primary':($o->status==='achieved'?'success':($o->status==='missed'?'danger':'default')); ?>"><?php echo html_escape($o->status); ?></span>
          <span class="label label-default"><?php echo $o->company ? html_escape($o->company) : 'group-level'; ?></span>
          <span class="label label-<?php echo $hc; ?>"><?php echo str_replace('_',' ',$health); ?></span>
          <span class="text-muted" style="font-size:11px"><?php echo html_escape($bounds[0]); ?> → <?php echo html_escape($bounds[1]); ?></span>
        </p>
        <?php if ($o->description): ?><p style="white-space:pre-wrap"><?php echo nl2br(html_escape($o->description)); ?></p><?php endif; ?>
        <div class="progress" style="height:20px;margin-bottom:2px">
          <div class="progress-bar <?php echo $bar; ?>" style="width:<?php echo $pct; ?>%;min-width:2.5em;line-height:20px"><?php echo $pct; ?>%</div>
        </div>
        <div class="text-muted" style="font-size:11px">Overall progress <?php echo $pct; ?>% · period elapsed <?php echo (int) round($elapsed*100); ?>%</div>

        <?php if ($canManage): ?>
        <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">
          <?php $btn=function($a,$l,$c) use($o){ echo form_open(admin_url('payplex_ai_agents/goals/act/'.(int)$o->id.'/'.$a),array('style'=>'display:inline'));echo '<button class="btn btn-'.$c.' btn-xs" type="submit">'.$l.'</button>';echo form_close(); };
            if ($o->status==='draft') { $btn('activate','Activate','success'); $btn('cancel','Cancel','default'); }
            if ($o->status==='active') { $btn('achieve','Mark achieved','success'); $btn('miss','Mark missed','danger'); $btn('cancel','Cancel','default'); }
            if (in_array($o->status,array('achieved','missed','cancelled'),true)) { $btn('reopen','Reopen','default'); }
          ?>
        </div>
        <?php endif; ?>
      </div></div>

      <div class="panel_s"><div class="panel-body">
        <h5 style="font-weight:600;margin-top:0">Key results <span class="label label-default"><?php echo count($krRows); ?></span></h5>
        <?php if (empty($krRows)): ?><p class="text-muted" style="font-size:12px">No key results yet — add one below to make this objective measurable.</p>
        <?php else: ?>
        <table class="table table-bordered" style="font-size:12px">
          <thead><tr><th>Key result</th><th>Baseline</th><th>Current</th><th>Target</th><th style="width:180px">Progress</th><?php if ($canManage): ?><th>Update</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($krRows as $kr): $k=$kr['kr']; $kp=(int) round($kr['progress']*100);
            $kbar = $kp>=100?'progress-bar-success':($kp>=50?'':'progress-bar-warning'); ?>
            <tr>
              <td><strong><?php echo html_escape($k->title); ?></strong><?php echo $k->unit?' <span class="text-muted">('.html_escape($k->unit).')</span>':''; ?><br><span class="text-muted" style="font-size:11px"><?php echo html_escape((string)$k->direction); ?><?php echo $k->metric?' · '.html_escape($k->metric):''; ?></span></td>
              <td><?php echo rtrim(rtrim(number_format((float)$k->baseline,2),'0'),'.'); ?></td>
              <td><strong><?php echo rtrim(rtrim(number_format((float)$k->current,2),'0'),'.'); ?></strong></td>
              <td><?php echo rtrim(rtrim(number_format((float)$k->target,2),'0'),'.'); ?></td>
              <td><div class="progress" style="margin:0;height:16px"><div class="progress-bar <?php echo $kbar; ?>" style="width:<?php echo $kp; ?>%;min-width:2em"><?php echo $kp; ?>%</div></div></td>
              <?php if ($canManage): ?>
              <td>
                <?php echo form_open(admin_url('payplex_ai_agents/goals/updatekr/'.(int)$o->id),array('class'=>'form-inline','style'=>'margin:0')); ?>
                  <input type="hidden" name="kr_id" value="<?php echo (int)$k->id; ?>">
                  <input class="form-control input-sm" name="current" type="number" step="any" value="<?php echo html_escape((string)$k->current); ?>" style="width:90px">
                  <button class="btn btn-default btn-xs" type="submit">Save</button>
                <?php echo form_close(); ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>

        <?php if ($canManage): ?>
        <h6 style="font-weight:600">Add key result</h6>
        <?php echo form_open(admin_url('payplex_ai_agents/goals/addkr/'.(int)$o->id)); ?>
          <div class="row">
            <div class="col-md-4 form-group"><input class="form-control input-sm" name="title" placeholder="Key result title" required></div>
            <div class="col-md-3 form-group"><input class="form-control input-sm" name="metric" placeholder="metric (e.g. signups)"></div>
            <div class="col-md-2 form-group"><input class="form-control input-sm" name="unit" placeholder="unit"></div>
            <div class="col-md-3 form-group"><select class="form-control input-sm" name="direction"><option value="increase">increase ↑</option><option value="decrease">decrease ↓</option></select></div>
          </div>
          <div class="row">
            <div class="col-md-3 form-group"><input class="form-control input-sm" name="baseline" type="number" step="any" placeholder="baseline" required></div>
            <div class="col-md-3 form-group"><input class="form-control input-sm" name="current" type="number" step="any" placeholder="current (opt)"></div>
            <div class="col-md-3 form-group"><input class="form-control input-sm" name="target" type="number" step="any" placeholder="target" required></div>
            <div class="col-md-3 form-group"><button class="btn btn-info btn-sm" type="submit">Add key result</button></div>
          </div>
        <?php echo form_close(); ?>
        <?php endif; ?>
      </div></div>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
