<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$t = isset($template) ? $template : null;
$isEdit = $t && isset($t->id) && (int) $t->id > 0;
$val = function ($k, $d = '') use ($t) { return $t && isset($t->$k) ? html_escape((string) $t->$k) : $d; };
$action = $isEdit ? admin_url('payplex_ai_agents/templates/update/' . (int) $t->id) : admin_url('payplex_ai_agents/templates/store');
?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
          <h4 class="no-margin" style="color:#12507F;font-weight:600"><?php echo $isEdit ? 'Edit Template' : 'Create Template'; ?></h4>
          <a href="<?php echo admin_url('payplex_ai_agents/templates'); ?>" class="btn btn-default btn-sm">Back to Templates</a>
        </div>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-warning"><ul style="margin:0;padding-left:18px"><?php foreach ($errors as $e): ?><li><?php echo html_escape($e); ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <div class="alert alert-info" style="font-size:12px">
          Templates create reusable AI agent/employee blueprints. An agent made from a template always starts in <strong>Sandbox</strong> mode as a <strong>draft</strong> and must be submitted and approved by a different admin before it can go live. The hard safety envelope (no payouts, refunds, deletes, deploys, SQL/shell; real calls/messages/payments approval-gated) is always enforced and cannot be loosened here. Do not impersonate a real individual — use a generic role and persona.
        </div>

        <?php echo form_open($action, array('id' => 'pp-template-form')); ?>
        <div class="row">
          <div class="col-md-8">
            <div class="panel_s"><div class="panel-body">
              <h5 style="font-weight:600;margin-top:0">Identity</h5>
              <div class="row">
                <div class="form-group col-md-6">
                  <label>Template / Agent name <span class="text-danger">*</span></label>
                  <input class="form-control" name="name" value="<?php echo $val('name'); ?>" placeholder="e.g. Group Finance Executive" required>
                </div>
                <div class="form-group col-md-6">
                  <label>System role <span class="text-danger">*</span></label>
                  <input class="form-control" name="system_role" value="<?php echo $val('system_role'); ?>" placeholder="e.g. AI Chief Financial Officer" required>
                </div>
              </div>
              <div class="row">
                <div class="form-group col-md-4">
                  <label>Short name</label>
                  <input class="form-control" name="short_name" value="<?php echo $val('short_name'); ?>" placeholder="e.g. Arth">
                </div>
                <div class="form-group col-md-4">
                  <label>Agent ID prefix</label>
                  <input class="form-control" name="agent_ref_prefix" value="<?php echo $val('agent_ref_prefix', 'AI-AGT'); ?>" placeholder="AI-CFO">
                </div>
                <div class="form-group col-md-4">
                  <label>Company / brand</label>
                  <input class="form-control" name="company" value="<?php echo $val('company'); ?>" placeholder="TechPlex / Payplex / ...">
                </div>
              </div>
              <div class="row">
                <div class="form-group col-md-4">
                  <label>Department</label>
                  <input class="form-control" name="department" value="<?php echo $val('department'); ?>" placeholder="finance">
                </div>
                <div class="form-group col-md-4">
                  <label>Persona (generic)</label>
                  <input class="form-control" name="persona" value="<?php echo $val('persona'); ?>" placeholder="disciplined finance leader">
                </div>
                <div class="form-group col-md-4">
                  <label>Expertise tags</label>
                  <input class="form-control" name="expertise_tags" value="<?php echo $val('expertise_tags'); ?>" placeholder="cashflow, budgeting, unit-economics">
                </div>
              </div>

              <h5 style="font-weight:600">Behaviour</h5>
              <div class="form-group">
                <label>Objective / purpose</label>
                <textarea class="form-control" name="purpose" rows="2" placeholder="What this agent is responsible for"><?php echo $val('purpose'); ?></textarea>
              </div>
              <div class="form-group">
                <label>System prompt <span class="text-danger">*</span></label>
                <textarea class="form-control" name="system_prompt" rows="5" placeholder="Instructions defining how the agent reasons and what it must never do..." required><?php echo $val('system_prompt'); ?></textarea>
              </div>
              <div class="row">
                <div class="form-group col-md-6">
                  <label>Allowed tools <span class="text-muted">(comma separated)</span></label>
                  <input class="form-control" name="allowed_tools" value="<?php echo $val('allowed_tools'); ?>" placeholder="read_reports, draft_decision_brief, create_task">
                </div>
                <div class="form-group col-md-6">
                  <label>Triggers <span class="text-muted">(comma separated)</span></label>
                  <input class="form-control" name="triggers" value="<?php echo $val('triggers'); ?>" placeholder="daily_scan, weekly_scan">
                </div>
              </div>
            </div></div>
          </div>

          <div class="col-md-4">
            <div class="panel_s"><div class="panel-body">
              <h5 style="font-weight:600;margin-top:0">Governance & limits</h5>
              <div class="form-group">
                <label>Approval tier (material actions)</label>
                <select class="form-control" name="approval_tier">
                  <?php $curTier = $val('approval_tier', 'chairman'); foreach ($tiers as $tier): ?>
                    <option value="<?php echo $tier; ?>" <?php echo $curTier === $tier ? 'selected' : ''; ?>><?php echo ucfirst($tier); ?></option>
                  <?php endforeach; ?>
                </select>
                <span class="text-muted" style="font-size:11px">Chairman = final authority for high-risk actions.</span>
              </div>
              <div class="form-group">
                <label>Confidence threshold</label>
                <input class="form-control" type="number" step="0.01" min="0.5" max="0.99" name="confidence_threshold" value="<?php echo $val('confidence_threshold', '0.80'); ?>">
              </div>
              <div class="form-group">
                <label>Token limit</label>
                <input class="form-control" type="number" name="token_limit" value="<?php echo $val('token_limit', '100000'); ?>">
              </div>
              <div class="row">
                <div class="form-group col-md-6">
                  <label>Daily budget</label>
                  <input class="form-control" type="number" step="0.01" name="daily_budget" value="<?php echo $val('daily_budget', '5.00'); ?>">
                </div>
                <div class="form-group col-md-6">
                  <label>Monthly budget</label>
                  <input class="form-control" type="number" step="0.01" name="monthly_budget" value="<?php echo $val('monthly_budget', '100.00'); ?>">
                </div>
              </div>
              <div class="form-group">
                <label>AI model</label>
                <input class="form-control" name="ai_model" value="<?php echo $val('ai_model', 'gpt-4o-mini'); ?>">
              </div>
              <div class="form-group">
                <label>Fallback model</label>
                <input class="form-control" name="fallback_model" value="<?php echo $val('fallback_model', 'gpt-4o-mini'); ?>">
              </div>
              <div class="checkbox">
                <label><input type="checkbox" name="is_executive" value="1" <?php echo $val('is_executive') === '1' ? 'checked' : ''; ?>> Executive (C-suite) template</label>
              </div>
              <hr>
              <button type="submit" class="btn btn-primary btn-block"><?php echo $isEdit ? 'Save changes' : 'Create template'; ?></button>
            </div></div>
          </div>
        </div>
        <?php echo form_close(); ?>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
