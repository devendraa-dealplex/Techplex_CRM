<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $cp=$commissionProfile; ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-7">
    <h4 style="color:#12507F;font-weight:600">Permission Templates</h4>
    <div class="alert alert-info" style="font-size:12px">Admins create additional least-privilege permission templates here with no code changes. Allowed and prohibited capabilities are comma-separated; overlaps are rejected.</div>
    <div class="panel_s"><div class="panel-body">
      <?php echo form_open(admin_url('payplex_staff/staff/template_store')); ?>
        <div class="row">
          <div class="col-md-6 form-group"><label>Template name <span class="text-danger">*</span></label><input class="form-control" name="name" required></div>
          <div class="col-md-6 form-group"><label>Base role (optional)</label><select class="form-control" name="base_role"><option value="">—</option><?php foreach($roles as $r): ?><option value="<?php echo $r; ?>"><?php echo html_escape($r); ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label>Allowed capabilities (comma-separated)</label><textarea class="form-control" name="allowed" rows="2" placeholder="view_own_dashboard, view_own_tasks_targets"></textarea></div>
        <div class="form-group"><label>Prohibited capabilities (comma-separated)</label><textarea class="form-control" name="prohibited" rows="2" placeholder="change_roles, export_bulk_customers"></textarea></div>
        <button class="btn btn-info btn-sm" type="submit">Save template</button>
      <?php echo form_close(); ?>
    </div></div>

    <div class="panel_s"><div class="panel-body">
      <h6 style="font-weight:600;margin-top:0">Saved templates</h6>
      <table class="table table-bordered" style="font-size:12px"><thead><tr><th>Name</th><th>Base</th><th># allow</th><th># deny</th><th></th></tr></thead><tbody>
      <?php if(empty($templates)): ?><tr><td colspan="5" class="text-muted">None yet.</td></tr><?php else: foreach($templates as $t): $al=json_decode($t->allowed_json,true)?:array();$pr=json_decode($t->prohibited_json,true)?:array(); ?>
        <tr><td><strong><?php echo html_escape($t->name); ?></strong></td><td><?php echo html_escape($t->base_role?:'—'); ?></td><td><?php echo count($al); ?></td><td><?php echo count($pr); ?></td>
        <td><?php echo form_open(admin_url('payplex_staff/staff/template_delete/'.(int)$t->id),array('style'=>'display:inline','onsubmit'=>'return confirm("Delete template?")')); echo '<button class="btn btn-danger btn-xs">Delete</button>'; echo form_close(); ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody></table>
    </div></div>
  </div>

  <div class="col-md-5">
    <div class="panel_s"><div class="panel-body">
      <h6 style="font-weight:600;margin-top:0">Built-in: Commission Employee (least privilege)</h6>
      <p class="text-muted" style="font-size:12px">Enforced server-side on UI, API, exports and direct URLs.</p>
      <strong style="font-size:12px;color:#5cb85c">Allowed</strong>
      <ul style="font-size:12px">
        <?php foreach($cp['allowed'] as $a): ?><li><?php echo html_escape(str_replace('_',' ',$a)); ?></li><?php endforeach; ?>
      </ul>
      <strong style="font-size:12px;color:#d9534f">Prohibited</strong>
      <ul style="font-size:12px">
        <?php foreach($cp['prohibited'] as $a): ?><li><?php echo html_escape(str_replace('_',' ',$a)); ?></li><?php endforeach; ?>
      </ul>
    </div></div>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
