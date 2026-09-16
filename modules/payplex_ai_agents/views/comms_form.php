<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-8">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">New Agent Thread</h4>
        <a href="<?php echo admin_url('payplex_ai_agents/comms'); ?>" class="btn btn-default btn-sm">Back</a>
      </div>
      <div class="panel_s"><div class="panel-body">
        <?php echo form_open(admin_url('payplex_ai_agents/comms/store')); ?>
          <div class="form-group"><label>Subject <span class="text-danger">*</span></label>
            <input class="form-control" name="subject" maxlength="200" required placeholder="e.g. KYC review needed for merchant #482"></div>
          <div class="row">
            <div class="col-md-6 form-group"><label>From agent <span class="text-danger">*</span></label>
              <select class="form-control" name="from_agent_id" required>
                <option value="">— select —</option>
                <?php foreach ($agents as $a): ?><option value="<?php echo (int) $a->id; ?>"><?php echo html_escape($a->display_name ? $a->display_name : $a->name); ?><?php echo $a->company?' ('.html_escape($a->company).')':''; ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-6 form-group"><label>To agent <span class="text-danger">*</span></label>
              <select class="form-control" name="to_agent_id" required>
                <option value="">— select —</option>
                <?php foreach ($agents as $a): ?><option value="<?php echo (int) $a->id; ?>"><?php echo html_escape($a->display_name ? $a->display_name : $a->name); ?><?php echo $a->company?' ('.html_escape($a->company).')':''; ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <div class="row">
            <div class="col-md-6 form-group"><label>Type</label>
              <select class="form-control" name="type">
                <?php foreach ($types as $ty): ?><option value="<?php echo $ty; ?>"><?php echo ucfirst($ty); ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-6 form-group"><label>Priority</label>
              <select class="form-control" name="priority">
                <?php foreach ($priorities as $p): ?><option value="<?php echo $p; ?>" <?php echo $p==='normal'?'selected':''; ?>><?php echo ucfirst($p); ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <div class="form-group"><label>Message <span class="text-danger">*</span></label>
            <textarea class="form-control" name="body" rows="4" required placeholder="Keep it to internal coordination. Do not ask an agent to email/call/pay a customer — that needs a Decision Packet."></textarea></div>
          <p class="text-muted" style="font-size:12px">This bus is internal only. If your message asks for a real external action it will be flagged “needs human” and cannot act until approved via a Decision Packet.</p>
          <button class="btn btn-info" type="submit">Open thread</button>
        <?php echo form_close(); ?>
      </div></div>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
