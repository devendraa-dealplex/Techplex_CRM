<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-8 col-md-offset-2"><div class="panel_s"><div class="panel-body">
      <h4 style="margin-top:0;color:#12507F;font-weight:600">AI Agents — Settings &amp; Kill Switch</h4>
      <?php echo form_open(admin_url('payplex_ai_agents/agents/settings')); ?>

        <div class="alert <?php echo !empty($global_kill) ? 'alert-danger' : 'alert-default'; ?>" style="border:1px solid #e0e0e0">
          <div class="checkbox">
            <label style="font-weight:600">
              <input type="checkbox" name="global_kill_switch" value="1" <?php echo !empty($global_kill) ? 'checked' : ''; ?>>
              GLOBAL EMERGENCY KILL SWITCH
            </label>
          </div>
          <p class="text-muted" style="margin:0">When engaged, every agent is blocked from executing anything (sandbox and production). Use in an emergency.</p>
        </div>

        <div class="row">
          <div class="col-md-6"><div class="form-group"><label>Global daily budget ($)</label><input class="form-control" name="global_daily_budget" type="number" step="0.01" value="<?php echo html_escape((string) $daily_budget); ?>"></div></div>
          <div class="col-md-6"><div class="form-group"><label>Global monthly budget ($)</label><input class="form-control" name="global_monthly_budget" type="number" step="0.01" value="<?php echo html_escape((string) $monthly_budget); ?>"></div></div>
        </div>

        <button class="btn btn-primary" type="submit">Save settings</button>
        <a href="<?php echo admin_url('payplex_ai_agents/agents'); ?>" class="btn btn-default">Back</a>
      <?php echo form_close(); ?>
    </div></div></div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
