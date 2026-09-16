<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-8">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">New Council Motion</h4>
        <a href="<?php echo admin_url('payplex_ai_agents/councilvote'); ?>" class="btn btn-default btn-sm">Back</a>
      </div>
      <div class="panel_s"><div class="panel-body">
        <?php echo form_open(admin_url('payplex_ai_agents/councilvote/store')); ?>
          <div class="form-group"><label>Motion title <span class="text-danger">*</span></label>
            <input class="form-control" name="title" maxlength="200" required placeholder="e.g. Adopt the FY27 risk-appetite statement"></div>
          <div class="form-group"><label>Description / rationale</label>
            <textarea class="form-control" name="description" rows="4" placeholder="What is being proposed and why. Keep decisions internal — a motion cannot itself email/call/pay/publish; that needs a Decision Packet."></textarea></div>
          <div class="row">
            <div class="col-md-4 form-group"><label>Type</label>
              <select class="form-control" name="motion_type">
                <?php foreach ($types as $ty): ?><option value="<?php echo $ty; ?>"><?php echo ucfirst($ty); ?></option><?php endforeach; ?>
              </select>
              <p class="text-muted" style="font-size:11px">Binding motions always need Chairman ratification.</p></div>
            <div class="col-md-4 form-group"><label>Threshold rule</label>
              <select class="form-control" name="threshold_rule">
                <?php foreach ($rules as $rl): ?><option value="<?php echo $rl; ?>"><?php echo html_escape(Payplex_agent_vote::ruleLabel($rl)); ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-4 form-group"><label>Quorum (fraction of council)</label>
              <input class="form-control" name="quorum" type="number" step="0.05" min="0" max="1" value="0.50">
              <p class="text-muted" style="font-size:11px">0.50 = at least half of eligible agents must vote.</p></div>
          </div>
          <div class="form-group"><label>Proposed by (agent)</label>
            <select class="form-control" name="proposed_by_agent">
              <option value="">— none / group —</option>
              <?php foreach ($agents as $a): ?><option value="<?php echo (int) $a->id; ?>"><?php echo html_escape($a->display_name ? $a->display_name : $a->name); ?><?php echo $a->company?' ('.html_escape($a->company).')':''; ?></option><?php endforeach; ?>
            </select>
            <p class="text-muted" style="font-size:11px">The motion inherits this agent's company for scope. Eligible voters = non-template, non-killed agents in that company (or all, for a group motion).</p></div>
          <p class="text-muted" style="font-size:12px">A motion that names a real external action (email/SMS/call/payment/refund/publish/delete) or is <em>binding</em> is flagged “needs human”. Passing the vote never executes it — the Chairman must ratify and route it through a Decision Packet.</p>
          <button class="btn btn-info" type="submit">Open motion for voting</button>
        <?php echo form_close(); ?>
      </div></div>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
