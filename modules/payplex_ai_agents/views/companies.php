<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
init_head();
$grantedCodes = array();
foreach ($grants as $g) { $grantedCodes[$g->company_code] = $g; }
?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Companies &amp; Access</h4>
        <a href="<?php echo admin_url('payplex_ai_agents/command'); ?>" class="btn btn-default btn-sm">Command Centre</a>
      </div>
      <div class="alert alert-info" style="font-size:12px">Row-level scoping: agents, decisions and templates carry a company. A staff member sees only the companies they are granted; admins see the whole group. A <strong>cross-company</strong> grant lets one person see across companies. Group-level records (no company) are visible to anyone with access.</div>

      <div class="row">
        <div class="col-md-6">
          <div class="panel_s"><div class="panel-body">
            <h5 style="font-weight:600;margin-top:0">Plex Group companies <span class="label label-primary"><?php echo count($companies); ?></span></h5>
            <?php if ($canManage): ?>
              <?php echo form_open(admin_url('payplex_ai_agents/companies'), array('class'=>'form-inline','style'=>'margin-bottom:10px')); ?>
                <div class="form-group"><input class="form-control input-sm" name="company_name" placeholder="Company name" required></div>
                <div class="form-group"><input class="form-control input-sm" name="company_code" placeholder="code (optional)" style="width:130px"></div>
                <button class="btn btn-primary btn-sm" type="submit">Add / update</button>
              <?php echo form_close(); ?>
            <?php endif; ?>
            <table class="table table-bordered" style="font-size:12px">
              <thead><tr><th>Company</th><th>Code</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
              <tbody>
              <?php foreach ($companies as $c): ?>
                <tr>
                  <td><strong><?php echo html_escape($c->name); ?></strong></td>
                  <td><code><?php echo html_escape($c->code); ?></code></td>
                  <td><?php echo $c->active ? '<span class="label label-success">active</span>' : '<span class="label label-default">inactive</span>'; ?></td>
                  <?php if ($canManage): ?><td><a class="btn btn-default btn-xs" href="<?php echo admin_url('payplex_ai_agents/companies/toggle/' . (int) $c->id); ?>"><?php echo $c->active ? 'Deactivate' : 'Activate'; ?></a></td><?php endif; ?>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div></div>
        </div>

        <div class="col-md-6">
          <div class="panel_s"><div class="panel-body">
            <h5 style="font-weight:600;margin-top:0">Staff company access</h5>
            <?php echo form_open(admin_url('payplex_ai_agents/companies'), array('method'=>'get','class'=>'form-inline','style'=>'margin-bottom:10px')); ?>
              <div class="form-group">
                <select class="form-control input-sm" name="staff" onchange="this.form.submit()">
                  <option value="0">— pick a staff member —</option>
                  <?php foreach ($staff as $s): ?>
                    <option value="<?php echo (int) $s->staffid; ?>" <?php echo $selStaff === (int) $s->staffid ? 'selected' : ''; ?>>
                      <?php echo html_escape($s->firstname . ' ' . $s->lastname); ?><?php echo (int) $s->admin === 1 ? ' (admin — sees all)' : ''; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php echo form_close(); ?>

            <?php if ($selStaff && $canManage): ?>
              <table class="table table-bordered" style="font-size:12px">
                <thead><tr><th>Company</th><th>Granted</th><th>Cross</th></tr></thead>
                <tbody>
                <?php foreach ($companies as $c):
                  $g = isset($grantedCodes[$c->code]) ? $grantedCodes[$c->code] : null; ?>
                  <tr>
                    <td><?php echo html_escape($c->name); ?></td>
                    <td>
                      <?php echo form_open(admin_url('payplex_ai_agents/companies/access'), array('style'=>'display:inline')); ?>
                        <input type="hidden" name="staff_id" value="<?php echo (int) $selStaff; ?>">
                        <input type="hidden" name="company_code" value="<?php echo html_escape($c->code); ?>">
                        <?php if ($g): ?>
                          <input type="hidden" name="op" value="revoke">
                          <button class="btn btn-danger btn-xs" type="submit">Revoke</button>
                        <?php else: ?>
                          <input type="hidden" name="op" value="grant">
                          <button class="btn btn-success btn-xs" type="submit">Grant</button>
                        <?php endif; ?>
                      <?php echo form_close(); ?>
                    </td>
                    <td>
                      <?php if ($g): ?>
                        <?php echo form_open(admin_url('payplex_ai_agents/companies/access'), array('style'=>'display:inline')); ?>
                          <input type="hidden" name="staff_id" value="<?php echo (int) $selStaff; ?>">
                          <input type="hidden" name="company_code" value="<?php echo html_escape($c->code); ?>">
                          <input type="hidden" name="op" value="grant">
                          <input type="hidden" name="can_cross" value="<?php echo (int) $g->can_cross === 1 ? '0' : '1'; ?>">
                          <button class="btn btn-xs <?php echo (int) $g->can_cross === 1 ? 'btn-warning' : 'btn-default'; ?>" type="submit"><?php echo (int) $g->can_cross === 1 ? 'cross ON' : 'cross off'; ?></button>
                        <?php echo form_close(); ?>
                      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php elseif ($selStaff): ?>
              <p class="text-muted" style="font-size:12px">You need the “Manage companies &amp; access” permission to change grants.</p>
            <?php else: ?>
              <p class="text-muted" style="font-size:12px">Pick a staff member to grant or revoke company access.</p>
            <?php endif; ?>
          </div></div>
        </div>
      </div>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
