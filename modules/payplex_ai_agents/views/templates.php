<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s"><div class="panel-body">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h4 class="no-margin" style="color:#12507F;font-weight:600">Ready Templates <span class="text-muted" style="font-size:13px">(<?php echo count($templates); ?>)</span></h4>
            <a href="<?php echo admin_url('payplex_ai_agents/agents'); ?>" class="btn btn-default btn-sm">Back to Agents</a>
          </div>
          <p class="text-muted">Each template is editable. Create an agent from a template — it starts in <strong>Sandbox</strong> mode as a <strong>draft</strong> and must be submitted and approved by a different admin before it can go live.</p>
          <?php if (!empty($confirm_duplicate_name)): ?>
            <div class="alert alert-warning">An agent named "<?php echo html_escape($confirm_duplicate_name); ?>" already exists.</div>
          <?php endif; ?>
          <div class="row">
            <?php foreach ($templates as $t): ?>
              <div class="col-md-4" style="margin-bottom:16px">
                <div class="panel_s" style="height:100%"><div class="panel-body">
                  <h4 style="margin-top:0;color:#12507F"><?php echo html_escape($t->name); ?></h4>
                  <p><span class="label label-default"><?php echo html_escape((string) $t->department); ?></span></p>
                  <p class="text-muted" style="min-height:52px"><?php echo html_escape((string) $t->purpose); ?></p>
                  <p style="font-size:12px"><strong>Model:</strong> <?php echo html_escape((string) $t->ai_model); ?></p>
                  <a href="<?php echo admin_url('payplex_ai_agents/agents/use_template/' . html_escape((string) $t->template_slug)); ?>" class="btn btn-primary btn-sm btn-block">Create Agent</a>
                </div></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div></div>
      </div>
    </div>
  </div>
</div>
<?php if (!empty($confirm_duplicate_slug)): ?>
<div class="modal fade" id="duplicate-agent-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">Duplicate agent name</h4>
      </div>
      <div class="modal-body">
        <p>An agent named "<?php echo html_escape($confirm_duplicate_name); ?>" already exists. Do you want to create a duplicate agent from this template anyway?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" id="duplicate-agent-no">No</button>
        <button type="button" class="btn btn-warning" id="duplicate-agent-yes">Yes</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php init_tail(); ?>
<?php if (!empty($confirm_duplicate_slug)): ?>
<script>
$(function(){
  $('#duplicate-agent-modal').modal({backdrop: 'static', keyboard: false});
  $('#duplicate-agent-yes').on('click', function(){
    window.location.href = <?php echo json_encode(
        admin_url('payplex_ai_agents/agents/use_template/' . $confirm_duplicate_slug) . '?confirm_duplicate=1'
    ); ?>;
  });
  $('#duplicate-agent-no').on('click', function(){
    $('#duplicate-agent-modal').modal('hide');
  });
});
</script>
<?php endif; ?>
</body>
</html>
