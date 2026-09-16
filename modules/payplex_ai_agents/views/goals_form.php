<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-8">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">New Objective</h4>
        <a href="<?php echo admin_url('payplex_ai_agents/goals'); ?>" class="btn btn-default btn-sm">Back</a>
      </div>
      <div class="panel_s"><div class="panel-body">
        <?php echo form_open(admin_url('payplex_ai_agents/goals/store')); ?>
          <div class="form-group"><label>Objective <span class="text-danger">*</span></label>
            <input class="form-control" name="title" maxlength="200" required placeholder="e.g. Grow active merchants in Payplex"></div>
          <div class="form-group"><label>Description</label>
            <textarea class="form-control" name="description" rows="3"></textarea></div>
          <div class="row">
            <div class="col-md-4 form-group"><label>Period <span class="text-danger">*</span></label>
              <input class="form-control" name="period" value="<?php echo html_escape($defaultPeriod); ?>" placeholder="2026-Q3 or 2026" required></div>
            <div class="col-md-8 form-group"><label>Company (blank = group-level)</label>
              <select class="form-control" name="company">
                <option value="">— group-level —</option>
                <?php foreach ($companies as $co): ?><option value="<?php echo html_escape($co->code); ?>"><?php echo html_escape($co->name); ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <p class="text-muted" style="font-size:12px">Saved as a <strong>draft</strong>. Add key results, then activate it from the objective page.</p>
          <button class="btn btn-info" type="submit">Create objective</button>
        <?php echo form_close(); ?>
      </div></div>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
