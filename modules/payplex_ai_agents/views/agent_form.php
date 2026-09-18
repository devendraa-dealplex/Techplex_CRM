<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
// Value helpers tolerant of create (null) vs edit (object).
$A = isset($agent) ? $agent : null;
function pv($A, $f, $def = '') { return $A && isset($A->$f) ? $A->$f : $def; }
function pvlist($A, $f) {
    if (!$A || !isset($A->$f)) return '';
    return is_array($A->$f) ? implode("\n", $A->$f) : (string) $A->$f;
}
$isEdit = isset($is_edit) ? (bool) $is_edit : ($A !== null);
$formUrl = isset($form_url) ? $form_url
    : ($isEdit ? admin_url('payplex_ai_agents/agents/edit/' . (int) $A->id) : admin_url('payplex_ai_agents/agents/create'));
?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <?php echo form_open($formUrl, ['id' => 'agent-form']); ?>
    <input type="hidden" name="confirm_duplicate" id="confirm_duplicate_field" value="">
    <?php if (!empty($confirm_duplicate_name)): ?>
      <div class="alert alert-warning">An agent named "<?php echo html_escape($confirm_duplicate_name); ?>" already exists.</div>
    <?php endif; ?>
    <div class="row">
      <div class="col-md-8">
        <div class="panel_s"><div class="panel-body">
          <h4 style="margin-top:0;color:#12507F"><?php echo $isEdit ? 'Edit Agent' : 'Create Agent'; ?></h4>

          <div class="form-group"><label>Name *</label><input class="form-control" name="name" required value="<?php echo html_escape(pv($A,'name')); ?>"></div>
          <div class="form-group"><label>Description</label><textarea class="form-control" name="description" rows="2"><?php echo html_escape(pv($A,'description')); ?></textarea></div>
          <div class="row">
            <div class="col-md-6"><div class="form-group"><label>Department</label><input class="form-control" name="department" value="<?php echo html_escape(pv($A,'department')); ?>"></div></div>
            <div class="col-md-6"><div class="form-group"><label>Customer timezone</label><input class="form-control" name="customer_timezone" placeholder="e.g. Asia/Kolkata" value="<?php echo html_escape(pv($A,'customer_timezone')); ?>"></div></div>
          </div>
          <div class="form-group"><label>Purpose</label><textarea class="form-control" name="purpose" rows="2"><?php echo html_escape(pv($A,'purpose')); ?></textarea></div>

          <div class="row">
            <div class="col-md-6"><div class="form-group"><label>AI provider</label><input class="form-control" name="ai_provider" value="<?php echo html_escape(pv($A,'ai_provider','openai')); ?>"></div></div>
            <div class="col-md-6"><div class="form-group"><label>AI model</label><input class="form-control" name="ai_model" value="<?php echo html_escape(pv($A,'ai_model','gpt-4o-mini')); ?>"></div></div>
          </div>
          <div class="form-group"><label>System prompt</label><textarea class="form-control" name="system_prompt" rows="4"><?php echo html_escape(pv($A,'system_prompt')); ?></textarea></div>

          <p class="text-muted" style="margin-top:10px"><em>Lists below: one item per line (or comma-separated).</em></p>
          <div class="row">
            <div class="col-md-6"><div class="form-group"><label>Knowledge sources</label><textarea class="form-control" name="knowledge_sources" rows="3"><?php echo html_escape(pvlist($A,'knowledge_sources')); ?></textarea></div></div>
            <div class="col-md-6"><div class="form-group"><label>Products / services</label><textarea class="form-control" name="products_services" rows="3"><?php echo html_escape(pvlist($A,'products_services')); ?></textarea></div></div>
          </div>
          <div class="row">
            <div class="col-md-6"><div class="form-group"><label>Lead sources</label><textarea class="form-control" name="lead_sources" rows="3"><?php echo html_escape(pvlist($A,'lead_sources')); ?></textarea></div></div>
            <div class="col-md-6"><div class="form-group"><label>CRM permissions</label><textarea class="form-control" name="crm_permissions" rows="3"><?php echo html_escape(pvlist($A,'crm_permissions')); ?></textarea></div></div>
          </div>
          <div class="row">
            <div class="col-md-6"><div class="form-group"><label>Allowed tools</label><textarea class="form-control" name="allowed_tools" rows="3"><?php echo html_escape(pvlist($A,'allowed_tools')); ?></textarea></div></div>
            <div class="col-md-6"><div class="form-group"><label>Triggers</label><textarea class="form-control" name="triggers" rows="3"><?php echo html_escape(pvlist($A,'triggers')); ?></textarea></div></div>
          </div>
          <div class="row">
            <div class="col-md-6"><div class="form-group"><label>Conditions</label><textarea class="form-control" name="conditions" rows="3"><?php echo html_escape(pvlist($A,'conditions')); ?></textarea></div></div>
            <div class="col-md-6"><div class="form-group"><label>Actions</label><textarea class="form-control" name="actions" rows="3"><?php echo html_escape(pvlist($A,'actions')); ?></textarea></div></div>
          </div>
          <div class="row">
            <div class="col-md-6"><div class="form-group"><label>Prohibited actions</label><textarea class="form-control" name="prohibited_actions" rows="3"><?php echo html_escape(pvlist($A,'prohibited_actions')); ?></textarea></div></div>
            <div class="col-md-6"><div class="form-group"><label>Approval-required actions</label><textarea class="form-control" name="approval_required_actions" rows="3"><?php echo html_escape(pvlist($A,'approval_required_actions')); ?></textarea></div></div>
          </div>
        </div></div>
      </div>

      <div class="col-md-4">
        <div class="panel_s"><div class="panel-body">
          <h5 style="margin-top:0">Safety &amp; limits</h5>
          <div class="form-group"><label>Confidence threshold (0-1)</label><input class="form-control" name="confidence_threshold" type="number" step="0.01" min="0" max="1" value="<?php echo html_escape(pv($A,'confidence_threshold','0.75')); ?>"></div>
          <div class="checkbox"><label><input type="checkbox" name="human_escalation" value="1" <?php echo pv($A,'human_escalation',1) ? 'checked' : ''; ?>> Human escalation enabled</label></div>
          <div class="form-group"><label>Retry limit</label><input class="form-control" name="retry_limit" type="number" value="<?php echo html_escape(pv($A,'retry_limit','2')); ?>"></div>
          <div class="form-group"><label>Daily execution limit</label><input class="form-control" name="daily_execution_limit" type="number" value="<?php echo html_escape(pv($A,'daily_execution_limit','100')); ?>"></div>
          <div class="form-group"><label>Token limit</label><input class="form-control" name="token_limit" type="number" value="<?php echo html_escape(pv($A,'token_limit','100000')); ?>"></div>
          <div class="form-group"><label>Daily budget ($)</label><input class="form-control" name="daily_budget" type="number" step="0.01" value="<?php echo html_escape(pv($A,'daily_budget','5')); ?>"></div>
          <div class="form-group"><label>Monthly budget ($)</label><input class="form-control" name="monthly_budget" type="number" step="0.01" value="<?php echo html_escape(pv($A,'monthly_budget','100')); ?>"></div>
        </div></div>
        <div class="panel_s"><div class="panel-body">
          <h5 style="margin-top:0">Schedule &amp; roles</h5>
          <div class="form-group"><label>Working days</label><input class="form-control" name="working_days" placeholder="Mon-Fri" value="<?php echo html_escape(pv($A,'working_days')); ?>"></div>
          <div class="form-group"><label>Working hours</label><input class="form-control" name="working_hours" placeholder="09:00-18:00" value="<?php echo html_escape(pv($A,'working_hours')); ?>"></div>
          <div class="form-group"><label>Owner staff id</label><input class="form-control" name="owner_id" type="number" value="<?php echo html_escape(pv($A,'owner_id')); ?>"></div>
          <div class="form-group"><label>Reviewer staff id</label><input class="form-control" name="reviewer_id" type="number" value="<?php echo html_escape(pv($A,'reviewer_id')); ?>"></div>
          <div class="form-group"><label>Approver staff id</label><input class="form-control" name="approver_id" type="number" value="<?php echo html_escape(pv($A,'approver_id')); ?>"></div>
          <p class="text-muted" style="font-size:11px">New agents always start in <strong>Sandbox / draft</strong>. The approver must differ from the creator and submitter.</p>
        </div></div>
        <button class="btn btn-primary btn-block" type="submit"><?php echo $isEdit ? 'Save changes' : 'Create agent'; ?></button>
        <a href="<?php echo admin_url('payplex_ai_agents/agents'); ?>" class="btn btn-default btn-block">Cancel</a>
      </div>
    </div>
    <?php echo form_close(); ?>
  </div>
</div>
<?php if (!empty($confirm_duplicate_name)): ?>
<div class="modal fade" id="duplicate-agent-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">Duplicate agent name</h4>
      </div>
      <div class="modal-body">
        <p>An agent named "<?php echo html_escape($confirm_duplicate_name); ?>" already exists. Do you want to create a duplicate agent anyway?</p>
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
<?php if (!empty($confirm_duplicate_name)): ?>
<script>
$(function(){
  $('#duplicate-agent-modal').modal({backdrop: 'static', keyboard: false});
  $('#duplicate-agent-yes').on('click', function(){
    $('#confirm_duplicate_field').val('1');
    $('#agent-form').get(0).submit();
  });
  $('#duplicate-agent-no').on('click', function(){
    $('#duplicate-agent-modal').modal('hide');
  });
});
</script>
<?php endif; ?>
</body>
</html>
