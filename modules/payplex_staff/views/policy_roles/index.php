<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
  <div class="panel_s"><div class="panel-body">
    <h4 class="no-margin">Workforce Policy Roles</h4>
    <p class="text-muted">
      Assignments recorded here feed the <strong>shadow</strong> policy only.
      The emergency guard remains the live authorization decision, and no cutover
      happens until every persona has passed its own UAT.
    </p>

    <?php if (empty($ready)) : ?>
      <div class="alert alert-warning">
        The <code>payplex_staff_policy_roles</code> table does not exist yet.
        Apply migration <code>012_policy_roles</code> first. Nothing on this
        screen will record anything until then.
      </div>
    <?php endif; ?>

    <?php if (!empty($conflicts)) : ?>
      <div class="alert alert-danger">
        <strong>Configuration conflict.</strong> These staff members hold more than
        one live policy role. The resolver fails closed to <code>employee</code> for
        each of them until exactly one role remains.
        <ul class="no-mbot">
        <?php foreach ($conflicts as $sid => $roles) : ?>
          <li>Staff #<?php echo (int) $sid; ?> —
            <?php echo html_escape(implode(', ', $roles)); ?>
            (<a href="<?php echo admin_url('payplex_staff/policy_roles/staff/' . (int) $sid); ?>">resolve</a>)
          </li>
        <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!empty($warnings)) : ?>
      <div class="alert alert-warning">
        <strong>Unresolved role configuration.</strong>
        <ul class="no-mbot">
        <?php foreach ($warnings as $w) : ?>
          <li>Staff #<?php echo (int) $w['staff_id']; ?> — <code><?php echo html_escape($w['code']); ?></code>
            <?php echo html_escape($w['detail']); ?></li>
        <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <h5>Live assignments</h5>
    <table class="table table-striped">
      <thead><tr>
        <th>Staff</th><th>Role</th><th>Entity</th><th>Branch</th>
        <th>Department</th><th>Region</th><th>Expires</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (empty($live)) : ?>
        <tr><td colspan="8" class="text-muted">
          No live assignments. HR and finance roles are deliberately not
          backfilled from the old labels — each one is assigned explicitly.
        </td></tr>
      <?php else : foreach ($live as $r) : ?>
        <tr>
          <td>#<?php echo (int) $r['staff_id']; ?></td>
          <td><code><?php echo html_escape($r['policy_role']); ?></code>
              <?php if (in_array($r['policy_role'], $sensitive, true)) : ?>
                <span class="label label-warning">sensitive</span>
              <?php endif; ?></td>
          <td><?php echo html_escape((string) $r['business_entity_id']); ?></td>
          <td><?php echo html_escape((string) $r['branch']); ?></td>
          <td><?php echo html_escape((string) $r['department']); ?></td>
          <td><?php echo html_escape((string) $r['region']); ?></td>
          <td><?php echo $r['expires_at'] ? html_escape($r['expires_at']) : '<span class="text-muted">permanent</span>'; ?></td>
          <td><a href="<?php echo admin_url('payplex_staff/policy_roles/staff/' . (int) $r['staff_id']); ?>">open</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>

    <h5>Staff</h5>
    <table class="table table-condensed">
      <tbody>
      <?php foreach ($staff as $s) : ?>
        <tr>
          <td>#<?php echo (int) $s['staffid']; ?></td>
          <td><?php echo html_escape(trim($s['firstname'] . ' ' . $s['lastname'])); ?></td>
          <td><?php echo ((int) $s['active'] === 1)
                ? '<span class="label label-success">active</span>'
                : '<span class="label label-default">inactive — cannot hold a policy role</span>'; ?></td>
          <td><a href="<?php echo admin_url('payplex_staff/policy_roles/staff/' . (int) $s['staffid']); ?>">manage</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</div></div>
<?php init_tail(); ?>
