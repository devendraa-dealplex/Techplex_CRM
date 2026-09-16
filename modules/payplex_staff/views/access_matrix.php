<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <h4 style="color:#12507F;font-weight:600;margin:0">Access matrix</h4>
    <p class="text-muted" style="margin:4px 0 14px">
      Who holds what, and which grants nobody could see. Read-only &mdash; this page changes nothing.
    </p>

    <?php
      $labels = array(
        'ungoverned_grants'   => 'Capabilities with no role',
        'grants_on_inactive'  => 'Grants on deactivated accounts',
        'grants_on_non_staff' => 'Grants on non-staff accounts',
        'role_without_grants' => 'Role assigned, no capabilities',
      );
    ?>
    <div class="row" style="margin-bottom:14px">
      <?php foreach ($labels as $code => $label): ?>
        <?php $n = isset($counts[$code]) ? (int) $counts[$code] : 0; ?>
        <div class="col-md-3">
          <div style="border:1px solid <?php echo $n ? '#E0B44A' : '#D8E0EA'; ?>;border-radius:6px;padding:12px;text-align:center">
            <div style="font-size:26px;font-weight:600;color:<?php echo $n ? '#B37E00' : '#12507F'; ?>"><?php echo $n; ?></div>
            <div style="font-size:12px;color:#667085"><?php echo $label; ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="table-responsive">
      <table class="table table-striped">
        <thead><tr><th>#</th><th>Name</th><th>Account</th><th>CRM role</th><th>Capabilities</th><th>Findings</th></tr></thead>
        <tbody>
        <?php foreach ($staff as $s): ?>
          <tr>
            <td><?php echo (int) $s['staffid']; ?></td>
            <td><?php echo html_escape($s['nm']); ?></td>
            <td>
              <?php if ((int) $s['admin'] === 1): ?><span class="label label-primary">administrator</span>
              <?php endif; ?>
              <?php if ((int) $s['active'] === 1): ?><span class="label label-success">active</span>
              <?php else: ?><span class="label label-default">inactive</span><?php endif; ?>
              <?php if ((int) $s['is_not_staff'] === 1): ?><span class="label label-warning">not a staff member</span><?php endif; ?>
            </td>
            <td><?php echo $s['role_name'] ? html_escape($s['role_name']) : '<span class="text-muted">none</span>'; ?></td>
            <td><?php echo (int) $s['caps']; ?></td>
            <td>
              <?php if (empty($s['anomalies'])): ?>
                <span class="text-muted"><small>&mdash;</small></span>
              <?php else: foreach ($s['anomalies'] as $a): ?>
                <div style="margin-bottom:4px">
                  <span class="label label-<?php echo $a['severity'] === 'high' ? 'danger' : 'warning'; ?>">
                    <?php echo html_escape($labels[$a['code']] ?? $a['code']); ?>
                  </span>
                  <small style="color:#667085"><?php echo html_escape($a['message']); ?></small>
                </div>
              <?php endforeach; endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h5 style="color:#12507F;font-weight:600;margin-top:24px">Capabilities that reach nobody</h5>
    <?php if (empty($unreached)): ?>
      <p class="text-muted">Every registered capability is held by at least one person.</p>
    <?php else: ?>
      <div class="table-responsive"><table class="table">
        <thead><tr><th>Module</th><th>Unreached</th><th>What that means</th></tr></thead>
        <tbody>
        <?php foreach ($unreached as $feature => $u): ?>
          <tr>
            <td><code><?php echo html_escape($feature); ?></code></td>
            <td><?php echo count($u['unreached']); ?> of <?php echo (int) $u['total']; ?></td>
            <td><small><?php echo html_escape($u['message']); ?><br>
              <span class="text-muted"><?php echo html_escape(implode(', ', $u['unreached'])); ?></span></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>

  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
