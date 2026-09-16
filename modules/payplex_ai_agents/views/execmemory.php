<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $cv = isset($companyView) ? $companyView : '';
$kinds = array('decision_outcome'=>'Decision outcome','lesson'=>'Lesson learned','council_ruling'=>'Council ruling','note'=>'Note'); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-7">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
          <h4 class="no-margin" style="color:#12507F;font-weight:600">Executive Memory</h4>
          <div>
            <a href="<?php echo admin_url('payplex_ai_agents/execknowledge'); ?>" class="btn btn-default btn-sm">Knowledge</a>
            <?php if (!empty($companies)): ?>
            <form method="get" action="<?php echo admin_url('payplex_ai_agents/execknowledge/memory'); ?>" style="display:inline-block">
              <input type="hidden" name="kind" value="<?php echo html_escape($kind); ?>">
              <select class="form-control input-sm" name="company" onchange="this.form.submit()" style="display:inline-block;width:auto">
                <option value="">Group (all)</option>
                <?php foreach ($companies as $c): ?><option value="<?php echo html_escape($c->code); ?>" <?php echo $cv===$c->code?'selected':''; ?>><?php echo html_escape($c->name); ?></option><?php endforeach; ?>
              </select>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <div class="alert alert-info" style="font-size:12px">The institutional memory of the executive system — decision outcomes, lessons and council rulings — so future decisions build on what actually happened. Scoped by company like everything else.</div>

        <?php if (empty($records)): ?><p class="text-muted">No memory recorded yet.</p>
        <?php else: foreach ($records as $r): ?>
          <div class="panel_s"><div class="panel-body">
            <div style="display:flex;justify-content:space-between">
              <strong><?php echo html_escape($r->title); ?></strong>
              <span class="label label-default"><?php echo html_escape(isset($kinds[$r->kind]) ? $kinds[$r->kind] : $r->kind); ?></span>
            </div>
            <p style="font-size:12px;margin:6px 0;white-space:pre-wrap"><?php echo nl2br(html_escape($r->body)); ?></p>
            <p class="text-muted" style="font-size:11px">
              <?php echo $r->company ? html_escape($r->company) : 'group-level'; ?> ·
              conf <?php echo (int) round(((float) $r->confidence) * 100); ?>% ·
              <?php echo $r->source_ref ? html_escape($r->source_ref) . ' · ' : ''; ?>
              <?php echo html_escape((string) $r->datecreated); ?>
              <?php echo $r->decision_id ? ' · <a href="' . admin_url('payplex_ai_agents/command/view/' . (int) $r->decision_id) . '">Decision #' . (int) $r->decision_id . '</a>' : ''; ?>
            </p>
          </div></div>
        <?php endforeach; endif; ?>
      </div>

      <div class="col-md-5">
        <div class="panel_s"><div class="panel-body">
          <h5 style="font-weight:600;margin-top:0">Capture memory</h5>
          <?php if (!$canManage): ?>
            <p class="text-muted" style="font-size:12px">You need the “Manage executive knowledge &amp; memory” permission to add memory.</p>
          <?php else: ?>
          <?php echo form_open(admin_url('payplex_ai_agents/execknowledge/captureMemory')); ?>
            <div class="form-group"><label>Title <span class="text-danger">*</span></label>
              <input class="form-control input-sm" name="title" maxlength="200" required></div>
            <div class="form-group"><label>What happened / what we learned <span class="text-danger">*</span></label>
              <textarea class="form-control input-sm" name="body" rows="4" required></textarea></div>
            <div class="row">
              <div class="col-md-6 form-group"><label>Kind</label>
                <select class="form-control input-sm" name="kind">
                  <?php foreach ($kinds as $k=>$label): ?><option value="<?php echo $k; ?>"><?php echo $label; ?></option><?php endforeach; ?>
                </select></div>
              <div class="col-md-6 form-group"><label>Company</label>
                <select class="form-control input-sm" name="company">
                  <option value="">— group-level —</option>
                  <?php foreach ($companies as $c): ?><option value="<?php echo html_escape($c->code); ?>"><?php echo html_escape($c->name); ?></option><?php endforeach; ?>
                </select></div>
            </div>
            <div class="row">
              <div class="col-md-6 form-group"><label>Confidence (0–1)</label>
                <input class="form-control input-sm" name="confidence" type="number" step="0.05" min="0" max="1" value="0.7"></div>
              <div class="col-md-6 form-group"><label>Decision # (optional)</label>
                <input class="form-control input-sm" name="decision_id" type="number" min="0"></div>
            </div>
            <div class="form-group"><label>Source reference / tags</label>
              <input class="form-control input-sm" name="source_ref" placeholder="e.g. Council #12"></div>
            <button class="btn btn-info btn-sm" type="submit">Capture</button>
          <?php echo form_close(); ?>
          <?php endif; ?>
        </div></div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
