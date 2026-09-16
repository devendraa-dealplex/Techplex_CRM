<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row">
  <div class="col-md-5">
    <div class="panel_s"><div class="panel-body">
      <h4 style="color:#12507F;font-weight:600">New rule version</h4>
      <p class="text-muted">Saving creates a NEW version. Old versions stay attached to their historical statements.</p>
      <?php echo form_open(admin_url('payplex_commission/commission/rules')); ?>
        <div class="form-group"><label>Name</label><input class="form-control" name="name"></div>
        <div class="form-group"><label>Scope</label>
          <select class="form-control" name="scope">
            <option value="global">Global</option><option value="role">Role</option>
            <option value="region">Region</option><option value="product">Product</option><option value="staff">Staff</option>
          </select></div>
        <div class="form-group"><label>Scope ref (id/name, optional)</label><input class="form-control" name="scope_ref"></div>
        <div class="form-group"><label>Rule JSON</label>
          <textarea class="form-control" name="rule_json" rows="6">{"type":"slab","slabs":[{"upto":100000,"rate":3},{"upto":500000,"rate":5},{"upto":null,"rate":7}]}</textarea>
          <span class="text-muted">Types: fixed / percentage / slab. Optional: accelerator, cap. (Values are placeholders until BD-02.)</span></div>
        <button class="btn btn-primary" type="submit">Save rule version</button>
      <?php echo form_close(); ?>
    </div></div>
  </div>
  <div class="col-md-7">
    <div class="panel_s"><div class="panel-body">
      <h4 style="color:#12507F;font-weight:600">Rule versions</h4>
      <table class="table">
        <thead><tr><th>#</th><th>Name</th><th>Scope</th><th>Rule</th><th>Active</th></tr></thead>
        <tbody>
        <?php foreach ($rules as $r): ?>
          <tr>
            <td><?php echo (int)$r->id; ?></td>
            <td><?php echo html_escape($r->name); ?></td>
            <td class="mini"><?php echo html_escape($r->scope . ($r->scope_ref ? ':'.$r->scope_ref : '')); ?></td>
            <td class="mini" style="color:#667085"><?php echo html_escape($r->rule_json); ?></td>
            <td><?php echo ((int)$r->active===1)?'yes':'no'; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div></div></div>
<?php init_tail(); ?></body></html>
