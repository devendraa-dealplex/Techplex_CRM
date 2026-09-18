<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
// Value helpers tolerant of create (null) vs edit (object) vs "re-showing the
// form after a validation error" (a plain object built from the posted data).
$A = isset($agent) ? $agent : null;
function pv($A, $f, $def = '') { return $A && isset($A->$f) ? $A->$f : $def; }
function pvlist($A, $f) {
    if (!$A || !isset($A->$f)) return '';
    return is_array($A->$f) ? implode("\n", $A->$f) : (string) $A->$f;
}
// $is_edit is passed explicitly when re-showing the form after a validation
// error (see Agents::create()/edit()) so a failed CREATE doesn't get treated
// as an edit just because $A is non-null; falls back to inferring from $A for
// the normal GET case.
$isEdit = isset($is_edit) ? $is_edit : ($A !== null);
$formUrl = ($isEdit && $A && isset($A->id))
    ? admin_url('payplex_ai_agents/agents/edit/' . (int) $A->id)
    : admin_url('payplex_ai_agents/agents/create');
$staff = isset($staff) ? $staff : array();
?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <?php echo form_open($formUrl); ?>
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
            <div class="col-md-6"><div class="form-group"><label>AI provider</label>
              <input class="form-control" name="ai_provider" list="ai_provider_options" value="<?php echo html_escape(pv($A,'ai_provider','openai')); ?>">
              <datalist id="ai_provider_options">
                <option value="openai"><option value="anthropic">
              </datalist>
            </div></div>
            <div class="col-md-6"><div class="form-group"><label>AI model</label>
              <input class="form-control" name="ai_model" list="ai_model_options" value="<?php echo html_escape(pv($A,'ai_model','gpt-4o-mini')); ?>">
              <datalist id="ai_model_options">
                <?php foreach (array_keys(Payplex_agent_sandbox::modelPrices()) as $m) {
                    if ($m === 'default') { continue; }
                    echo '<option value="' . html_escape($m) . '">';
                } ?>
              </datalist>
              <p class="text-muted" style="font-size:11px;margin-bottom:0">Cost estimates only recognise the suggested models above; anything else falls back to a generic rate.</p>
            </div></div>
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
          <?php
            // staff_model->get('', ...) (no numeric id) returns plain arrays
            // (result_array()), not objects - staff_model->get($id) with a
            // numeric id is the one that returns an object. Array access here.
            $staffOptions = array();
            foreach ($staff as $s) {
                $staffOptions[(int) $s['staffid']] = $s['firstname'] . ' ' . $s['lastname'];
            }
            asort($staffOptions);
            function staff_select($name, $label, $staffOptions, $selected) { ?>
              <div class="form-group"><label><?php echo html_escape($label); ?></label>
                <select class="form-control" name="<?php echo $name; ?>">
                  <option value="0">- None -</option>
                  <?php foreach ($staffOptions as $sid => $sname) { ?>
                    <option value="<?php echo (int) $sid; ?>" <?php echo (int) $selected === $sid ? 'selected' : ''; ?>><?php echo html_escape($sname); ?></option>
                  <?php } ?>
                </select>
              </div>
            <?php }
            staff_select('owner_id', 'Owner', $staffOptions, (int) pv($A,'owner_id'));
            staff_select('reviewer_id', 'Reviewer', $staffOptions, (int) pv($A,'reviewer_id'));
            staff_select('approver_id', 'Approver', $staffOptions, (int) pv($A,'approver_id'));
          ?>
          <p class="text-muted" style="font-size:11px">
            New agents always start in <strong>Sandbox / draft</strong>. The approver must always differ from the creator and submitter.
            <strong>Owner</strong> defaults to you if left as "- None -"; it is informational only.
            <strong>Reviewer</strong> is informational only (not yet enforced anywhere).
            If <strong>Approver</strong> is set, only that staff member may approve this agent - leave it as "- None -" to allow any eligible staff member to approve.
          </p>
        </div></div>
        <button class="btn btn-primary btn-block" type="submit"><?php echo $isEdit ? 'Save changes' : 'Create agent'; ?></button>
        <a href="<?php echo admin_url('payplex_ai_agents/agents'); ?>" class="btn btn-default btn-block">Cancel</a>
      </div>
    </div>
    <?php echo form_close(); ?>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
