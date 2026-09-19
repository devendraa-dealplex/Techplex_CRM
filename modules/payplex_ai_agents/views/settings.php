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

        <h5 style="margin-top:20px">Lead pipeline assignment</h5>
        <div class="form-group">
          <label>Suggest staff whose role contains</label>
          <input class="form-control" name="pipeline_assign_roles" value="<?php echo html_escape((string) $assign_roles); ?>" placeholder="sales">
          <p class="text-muted" style="font-size:11px;margin-bottom:0">Comma-separated role-name keywords (e.g. <code>sales, business development</code>). The lead pipeline only suggests active staff with a matching role. Leave blank to allow every active staff member.</p>
        </div>

        <h5 style="margin-top:20px">LLM provider (OpenRouter)</h5>
        <p class="text-muted" style="font-size:12px">Used by the Sandbox Test when the message matches permitted knowledge. Only that message text and the matched knowledge entry are sent. Free models are limited (about 50 requests/day). An <code>OPENROUTER_API_KEY</code> environment variable on the server, if set, takes precedence over this key.</p>
        <div class="row">
          <div class="col-md-6"><div class="form-group"><label>API key <?php echo !empty($llm_key_set) ? '<span class="label label-success">configured</span>' : '<span class="label label-default">not set</span>'; ?></label>
            <input class="form-control" name="openrouter_api_key" type="password" autocomplete="new-password" placeholder="<?php echo !empty($llm_key_set) ? 'Leave blank to keep the current key' : 'sk-or-v1-...'; ?>"></div></div>
          <div class="col-md-6"><div class="form-group"><label>Model</label>
            <input class="form-control" name="openrouter_model" value="<?php echo html_escape((string) $llm_model); ?>">
            <p class="text-muted" style="font-size:11px;margin-bottom:0"><code>openrouter/free</code> auto-picks an available free model.</p></div></div>
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
