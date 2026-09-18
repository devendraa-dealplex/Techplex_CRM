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
      <div class="form-group"><label>Objective / Script *</label>
        <input class="form-control" name="objective" placeholder="e.g. Re-engage cold leads" required></div>
      <div class="row">
        <div class="col-sm-6 form-group"><label>Lead status</label>
          <select class="form-control" name="status_id" id="pp-status">
            <option value="0">Any</option>
            <?php foreach ($statuses as $s): ?>
              <option value="<?php echo (int) $s['id']; ?>"><?php echo html_escape($s['name']); ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="col-sm-6 form-group"><label>Lead source</label>
          <select class="form-control" name="source_id" id="pp-source">
            <option value="0">Any</option>
            <?php foreach ($sources as $s): ?>
              <option value="<?php echo (int) $s['id']; ?>"><?php echo html_escape($s['name']); ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <p><b><span id="pp-aud-count">—</span></b> lead(s) match this audience.</p>
      <div class="pp-webhook-hint">Consent/DND/calling-hours are re-checked per call at run time — contacts failing any check are skipped automatically.</div>
      <button class="btn btn-primary" type="submit">Submit for approval</button>
      <a class="btn btn-link" href="<?php echo admin_url('payplex_aicalling/campaigns'); ?>">Cancel</a>
    <?php echo form_close(); ?>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?>
<script>
window.PP_AUD_URL = "<?php echo admin_url('payplex_aicalling/campaigns/audience_count'); ?>";
(function ($) {
  var audCount = null;

  function refresh() {
    $.getJSON(window.PP_AUD_URL, { status_id: $('#pp-status').val(), source_id: $('#pp-source').val() })
      .done(function (r) { audCount = r.count; $('#pp-aud-count').text(r.count); });
  }
  $('#pp-status, #pp-source').on('change', refresh);
  $(refresh);

  $('form').on('submit', function (e) {
    if (!$.trim($('input[name="objective"]').val())) {
      e.preventDefault();
      alert('Please enter a call script/objective before submitting.');
      return;
    }
    if (audCount !== null && audCount <= 0) {
      e.preventDefault();
      alert('No leads match the selected Status/Source filter. Choose a different filter before submitting.');
    }
  });
})(jQuery);
</script>
</body></html>
