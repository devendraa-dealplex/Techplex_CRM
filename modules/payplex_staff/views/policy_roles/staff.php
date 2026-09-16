<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
  <div class="panel_s"><div class="panel-body">
    <h4 class="no-margin">Policy Roles — Staff #<?php echo (int) $staff_id; ?></h4>

    <div class="alert alert-info">
      <strong>Resolved role:</strong> <code><?php echo html_escape($resolved); ?></code>
      &nbsp;<strong>source:</strong> <code><?php echo html_escape($role_source); ?></code><br>
      <?php if ($role_source === 'default' || $role_source === 'unmapped' || $role_source === 'conflict') : ?>
        <span class="text-danger">
          This person has no usable assignment, so the policy treats them as an
          ordinary employee. Any shadow comparison logged for them says nothing
          about the role they actually hold.
        </span>
      <?php endif; ?>
    </div>

    <?php if (!empty($warnings)) : ?>
      <div class="alert alert-warning"><ul class="no-mbot">
        <?php foreach ($warnings as $w) : ?>
          <li><code><?php echo html_escape($w['code']); ?></code> <?php echo html_escape($w['detail']); ?></li>
        <?php endforeach; ?>
      </ul></div>
    <?php endif; ?>

    <h5>Assignment history</h5>
    <p class="text-muted">Revocation stamps a row; nothing is ever removed.</p>
    <table class="table table-striped">
      <thead><tr><th>#</th><th>Role</th><th>Scope</th><th>Window</th>
        <th>State</th><th>Assigned by</th><th>Reason</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r) : ?>
        <tr class="<?php echo !empty($r['__live']) ? 'success' : ''; ?>">
          <td><?php echo (int) $r['id']; ?></td>
          <td><code><?php echo html_escape($r['policy_role']); ?></code></td>
          <td><?php
              $sc = array_filter(array(
                'entity' => $r['business_entity_id'], 'branch' => $r['branch'],
                'dept' => $r['department'], 'region' => $r['region']));
              echo $sc ? html_escape(json_encode($sc)) : '<span class="text-danger">company-wide</span>'; ?></td>
          <td><?php echo html_escape(($r['effective_from'] ?: 'now') . ' → ' . ($r['expires_at'] ?: 'permanent')); ?></td>
          <td><?php echo !empty($r['__live'])
                ? '<span class="label label-success">live</span>'
                : '<span class="label label-default">' . html_escape($r['__reason']) . '</span>';
              if ($r['approval_state'] === 'pending') : ?>
                <span class="label label-warning">pending approval</span>
              <?php endif; ?></td>
          <td>#<?php echo (int) $r['assigned_by']; ?></td>
          <td><?php echo html_escape((string) $r['assignment_reason']); ?></td>
          <td>
            <?php if ($r['approval_state'] === 'pending') : ?>
              <?php echo form_open(admin_url('payplex_staff/policy_roles/approve')); ?>
                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                <input type="hidden" name="staff_id" value="<?php echo (int) $staff_id; ?>">
                <button class="btn btn-xs btn-success">Approve</button>
              <?php echo form_close(); ?>
            <?php endif; ?>
            <?php if (!empty($r['__live'])) : ?>
              <?php echo form_open(admin_url('payplex_staff/policy_roles/revoke')); ?>
                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                <input type="hidden" name="staff_id" value="<?php echo (int) $staff_id; ?>">
                <input type="text" name="revocation_reason" class="form-control input-sm"
                       placeholder="Reason (required)" required minlength="5">
                <button class="btn btn-xs btn-danger">Revoke</button>
              <?php echo form_close(); ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rows)) : ?>
        <tr><td colspan="8" class="text-muted">No assignments recorded.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>

    <h5>New assignment</h5>
    <?php echo form_open(admin_url('payplex_staff/policy_roles/assign')); ?>
      <input type="hidden" name="staff_id" value="<?php echo (int) $staff_id; ?>">
      <div class="form-group"><label>Policy role</label>
        <select name="policy_role" class="form-control" required>
          <option value="">— select —</option>
          <?php foreach ($vocabulary as $v) : ?>
            <option value="<?php echo html_escape($v); ?>"><?php echo html_escape($v); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Entity</label><input name="business_entity_id" class="form-control"></div>
      <div class="form-group"><label>Branch</label><input name="branch" class="form-control"></div>
      <div class="form-group"><label>Department</label><input name="department" class="form-control"></div>
      <div class="form-group"><label>Region</label><input name="region" class="form-control"></div>
      <div class="form-group"><label>Effective from</label><input type="datetime-local" name="effective_from" class="form-control"></div>
      <div class="form-group"><label>Expires at <span class="text-muted">(blank = permanent)</span></label>
        <input type="datetime-local" name="expires_at" class="form-control"></div>
      <div class="form-group"><label>Assignment reason</label>
        <input name="assignment_reason" class="form-control" required minlength="5"></div>
      <p class="text-muted">
        Leaving every scope field blank means <strong>company-wide</strong>, which
        is treated as sensitive and will be held pending a second approver.
      </p>
      <button class="btn btn-primary">Record assignment</button>
    <?php echo form_close(); ?>

    <h5 class="mtop20">Expected access under the new policy</h5>
    <p class="text-muted">
      Computed live from the policy engine for the resolved role — a preview, not
      a stored copy, so it cannot drift from the engine it describes. The
      emergency guard, not this table, is what currently decides.
    </p>
    <table class="table table-condensed">
      <thead><tr><th>Route</th><th>Domain</th><th>Action</th><th>Would be</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($access as $route => $a) : ?>
        <tr>
          <td><code><?php echo html_escape($route); ?></code></td>
          <td><?php echo html_escape($a['domain']); ?></td>
          <td><?php echo html_escape($a['action']); ?></td>
          <td><?php echo $a['verdict'] === 'allow'
                ? '<span class="label label-success">allow</span>'
                : ($a['verdict'] === 'deny' ? '<span class="label label-default">deny</span>' : '—'); ?></td>
          <td class="text-muted"><?php echo html_escape(isset($a['reason']) ? $a['reason'] : ''); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</div></div>
<?php init_tail(); ?>
