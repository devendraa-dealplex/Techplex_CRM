<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">New Decision Packet</h4>
        <a href="<?php echo admin_url('payplex_ai_agents/command/inbox'); ?>" class="btn btn-default btn-sm">Back to Inbox</a>
      </div>
      <div class="alert alert-info" style="font-size:12px">The required approval tier is computed automatically from the action key (and amount). High-risk, irreversible or unknown actions require the Chairman — you can't lower that here.</div>

      <?php echo form_open(admin_url('payplex_ai_agents/command/store')); ?>
      <div class="row">
        <div class="col-md-8">
          <div class="panel_s"><div class="panel-body">
            <div class="row">
              <div class="form-group col-md-8"><label>Decision title <span class="text-danger">*</span></label><input class="form-control" name="title" required></div>
              <div class="form-group col-md-4"><label>Company / brand</label><input class="form-control" name="company" placeholder="Payplex"></div>
            </div>
            <div class="row">
              <div class="form-group col-md-6"><label>Requesting agent id</label><input class="form-control" name="requesting_agent_id" placeholder="e.g. 14"></div>
              <div class="form-group col-md-6"><label>Action key <span class="text-danger">*</span></label><input class="form-control" name="action_key" placeholder="e.g. paid_marketing_activation" required></div>
            </div>
            <div class="form-group"><label>Business objective</label><textarea class="form-control" name="objective" rows="2"></textarea></div>
            <div class="form-group"><label>Recommended action</label><input class="form-control" name="recommended_action" placeholder="what to do, in words"></div>
            <div class="form-group"><label>Reason</label><textarea class="form-control" name="reason" rows="2"></textarea></div>
            <div class="form-group"><label>Evidence</label><textarea class="form-control" name="evidence" rows="2" placeholder="figures + where they came from"></textarea></div>
            <div class="row">
              <div class="form-group col-md-6"><label>Source-data links</label><input class="form-control" name="source_links"></div>
              <div class="form-group col-md-6"><label>Assumptions</label><input class="form-control" name="assumptions"></div>
            </div>
            <div class="form-group"><label>Alternatives considered</label><textarea class="form-control" name="alternatives" rows="2"></textarea></div>
          </div></div>
        </div>
        <div class="col-md-4">
          <div class="panel_s"><div class="panel-body">
            <div class="form-group"><label>Confidence (0–1) <span class="text-danger">*</span></label><input class="form-control" type="number" step="0.01" min="0" max="1" name="confidence" value="0.80"></div>
            <div class="form-group"><label>Amount (if any)</label><input class="form-control" type="number" step="0.01" name="amount" placeholder="drives manager-limit escalation"></div>
            <div class="row">
              <div class="form-group col-md-6"><label>Expected revenue</label><input class="form-control" type="number" step="0.01" name="expected_revenue"></div>
              <div class="form-group col-md-6"><label>Expected cost</label><input class="form-control" type="number" step="0.01" name="expected_cost"></div>
            </div>
            <div class="form-group"><label>ROI / payback</label><input class="form-control" name="roi"></div>
            <div class="form-group"><label>Financial impact</label><input class="form-control" name="financial_impact"></div>
            <div class="form-group"><label>Risk rating <span class="text-danger">*</span></label>
              <select class="form-control" name="risk_rating"><option>low</option><option selected>medium</option><option>high</option><option>critical</option></select>
            </div>
            <div class="form-group"><label>Reversibility <span class="text-danger">*</span></label>
              <select class="form-control" name="reversibility"><option>reversible</option><option>partially reversible</option><option>irreversible</option></select>
            </div>
            <div class="form-group"><label>Rollback method <span class="text-danger">*</span></label><input class="form-control" name="rollback_method"></div>
            <div class="form-group"><label>Execution owner <span class="text-danger">*</span></label><input class="form-control" name="execution_owner" placeholder="human who performs it"></div>
            <div class="form-group"><label>Deadline</label><input class="form-control" type="date" name="deadline"></div>
          </div></div>
        </div>
      </div>
      <div class="panel_s"><div class="panel-body">
        <div class="row">
          <div class="form-group col-md-4"><label>Legal / compliance impact</label><textarea class="form-control" name="legal_impact" rows="2"></textarea></div>
          <div class="form-group col-md-4"><label>Security impact</label><textarea class="form-control" name="security_impact" rows="2"></textarea></div>
          <div class="form-group col-md-4"><label>Employee / customer impact</label><textarea class="form-control" name="people_impact" rows="2"></textarea></div>
        </div>
        <div class="row">
          <div class="form-group col-md-6"><label>Other agents' opinions</label><textarea class="form-control" name="other_opinions" rows="2"></textarea></div>
          <div class="form-group col-md-6"><label>Audit-agent verification</label><textarea class="form-control" name="audit_verification" rows="2"></textarea></div>
        </div>
        <button type="submit" class="btn btn-primary">Create packet (draft)</button>
      </div></div>
      <?php echo form_close(); ?>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
