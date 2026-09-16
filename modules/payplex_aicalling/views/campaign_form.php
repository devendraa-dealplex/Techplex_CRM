<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-8 col-md-offset-2">
  <div class="panel_s"><div class="panel-body">
    <h4 class="pp-title">New AI Calling Campaign</h4>
    <p class="text-muted">This creates the campaign in <b>pending approval</b>. A different user must approve it before it runs.</p>
    <?php echo form_open(admin_url('payplex_aicalling/campaigns/create')); ?>
      <div class="form-group"><label>Campaign name *</label>
        <input class="form-control" name="name" required></div>
      <div class="row">
        <div class="col-sm-6 form-group"><label>AI Agent</label>
          <select class="form-control" name="agent_id">
            <option value="voice_agent_en_sales">Sales — English</option>
            <option value="voice_agent_hi_sales">Sales — Hindi</option>
          </select></div>
        <div class="col-sm-6 form-group"><label>Language</label>
          <select class="form-control" name="language">
            <option value="en-IN">English (IN)</option>
            <option value="hi-IN">Hindi</option>
          </select></div>
      </div>
      <div class="form-group"><label>Objective</label>
        <input class="form-control" name="objective" placeholder="e.g. Re-engage cold leads"></div>
      <div class="form-group"><label>Audience filter</label>
        <input class="form-control" name="audience" placeholder="e.g. status=cold AND source=website"></div>
      <div class="form-group"><label>Estimated targets</label>
        <input class="form-control" name="total_targets" type="number" min="0" value="0"></div>
      <div class="pp-webhook-hint">Consent/DND/calling-hours are re-checked per call at run time — contacts failing any check are skipped automatically.</div>
      <button class="btn btn-primary" type="submit">Submit for approval</button>
      <a class="btn btn-link" href="<?php echo admin_url('payplex_aicalling/campaigns'); ?>">Cancel</a>
    <?php echo form_close(); ?>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
