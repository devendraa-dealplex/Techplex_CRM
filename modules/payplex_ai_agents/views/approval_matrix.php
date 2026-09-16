<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Approval Matrix</h4>
        <a href="<?php echo admin_url('payplex_ai_agents/command'); ?>" class="btn btn-default btn-sm">Command Centre</a>
      </div>
      <div class="alert alert-info" style="font-size:12px">Maps each action to the authority required to approve it. You can <strong>tighten</strong> a rule (raise the tier) or set a manager limit, but a Chairman-floor action always stays with the Chairman — the classifier never lets a rule weaken it.</div>

      <div class="panel_s" style="background:#f4f8fb"><div class="panel-body">
        <strong>Add / update a rule</strong>
        <?php echo form_open(admin_url('payplex_ai_agents/command/matrix'), array('class'=>'form-inline','style'=>'margin-top:6px')); ?>
          <div class="form-group"><input class="form-control input-sm" name="action_key" placeholder="action key" style="width:220px" required></div>
          <div class="form-group"><select class="form-control input-sm" name="tier"><option value="auto">auto</option><option value="manager">manager</option><option value="chairman" selected>chairman</option></select></div>
          <div class="form-group"><input class="form-control input-sm" type="number" step="0.01" name="manager_limit" placeholder="manager limit (0=none)" style="width:180px"></div>
          <button class="btn btn-primary btn-sm" type="submit">Save rule</button>
        <?php echo form_close(); ?>
      </div></div>

      <div class="table-responsive"><table class="table table-bordered table-striped" style="font-size:12px">
        <thead><tr><th>Action</th><th>Required tier</th><th>Manager limit</th><th>Note</th></tr></thead>
        <tbody>
        <?php if (empty($rows)): ?><tr><td colspan="4" class="text-muted">No rules — defaults apply (unknown actions require the Chairman).</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><code><?php echo html_escape($r->action); ?></code></td>
            <td><span class="label <?php echo $r->tier === 'chairman' ? 'label-primary' : ($r->tier === 'manager' ? 'label-info' : 'label-default'); ?>"><?php echo html_escape($r->tier); ?></span></td>
            <td><?php echo (float) $r->manager_limit > 0 ? number_format((float) $r->manager_limit, 2) : '<span class="text-muted">—</span>'; ?></td>
            <td class="text-muted"><?php echo html_escape((string) $r->note); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
