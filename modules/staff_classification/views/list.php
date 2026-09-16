<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">

    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
      <div>
        <h4 style="color:#12507F;font-weight:600;margin:0"><?php echo html_escape($title); ?></h4>
        <p class="text-muted" style="margin:4px 0 0">
          Employment type, work category, department, reporting manager, branch, territory and shift.
          <b>Employment type</b> is how someone is engaged; <b>work category</b> is where the work happens.
          They are separate on purpose.
        </p>
      </div>
      <div>
        <?php if ($include_inactive): ?>
          <a class="btn btn-default btn-sm" href="<?php echo admin_url('staff_classification'); ?>">Active staff only</a>
        <?php else: ?>
          <a class="btn btn-default btn-sm" href="<?php echo admin_url('staff_classification?inactive=1'); ?>">
            Include inactive (<?php echo (int) $inactive_count; ?>)
          </a>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$include_inactive && $inactive_count > 0): ?>
      <div class="alert alert-info" style="font-size:13px;margin-top:12px">
        <b><?php echo (int) $inactive_count; ?> deactivated staff member(s) are not shown.</b>
        They still hold classification data and their records are still reachable, so they are one
        click away rather than hidden.
      </div>
    <?php endif; ?>

    <div class="table-responsive" style="margin-top:12px">
      <table class="table table-striped">
        <thead><tr>
          <th>Name</th><th>Status</th><th>Employment Type</th><th>Work Category</th>
          <th>Department</th><th>Reporting Manager</th><th>Branch</th><th>Territory</th><th>Shift</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($records)): ?>
          <tr><td colspan="10" class="text-center text-muted" style="padding:20px">No staff members.</td></tr>
        <?php else: foreach ($records as $r): ?>
          <?php
            $et = (string) $r['employment_type'];
            $wc = (string) $r['work_category'];
            if ($wc === '' && $r['work_mode'] !== null && $r['work_mode'] !== '') {
                $wc = Workforce_classification::legacyWorkMode($r['work_mode']);
            }
            $dept = $r['department_name'] !== null && $r['department_name'] !== ''
                ? $r['department_name']
                : ($r['department'] !== null && $r['department'] !== '' ? $r['department'] . ' (unlinked text)' : '');
          ?>
          <tr<?php echo (int) $r['active'] === 0 ? ' style="opacity:.6"' : ''; ?>>
            <td><?php echo html_escape(trim($r['firstname'] . ' ' . $r['lastname'])); ?></td>
            <td>
              <?php if ((int) $r['active'] === 1): ?>
                <span class="label label-success">Active</span>
              <?php else: ?>
                <span class="label label-default">Inactive</span>
              <?php endif; ?>
            </td>
            <td><?php echo html_escape(Workforce_classification::label($employment_types, $et)); ?></td>
            <td><?php echo html_escape(Workforce_classification::label($work_categories, $wc)); ?></td>
            <td><?php echo $dept === '' ? '<span class="text-muted">Not set</span>' : html_escape($dept); ?></td>
            <td>
              <?php if ($r['manager_firstname']): ?>
                <?php echo html_escape(trim($r['manager_firstname'] . ' ' . $r['manager_lastname'])); ?>
                <?php if ((int) $r['manager_active'] === 0): ?>
                  <span class="label label-warning" title="This manager's account is deactivated">inactive</span>
                <?php endif; ?>
              <?php elseif ($r['manager_id']): ?>
                <span class="label label-danger" title="manager_id <?php echo (int) $r['manager_id']; ?> has no staff record">missing #<?php echo (int) $r['manager_id']; ?></span>
              <?php else: ?>
                <span class="text-muted">No manager assigned</span>
              <?php endif; ?>
            </td>
            <td><?php echo $r['branch'] ? html_escape($r['branch']) : '<span class="text-muted">Not set</span>'; ?></td>
            <td><?php echo $r['territory'] ? html_escape($r['territory']) : '<span class="text-muted">Not set</span>'; ?></td>
            <td><?php echo $r['shift'] ? html_escape($r['shift']) : '<span class="text-muted">Not set</span>'; ?></td>
            <td class="text-right" style="white-space:nowrap">
              <?php if ($can_edit): ?>
                <a class="btn btn-default btn-sm" href="<?php echo admin_url('staff_classification/edit/' . (int) $r['staffid']); ?>"><i class="fa fa-pencil-square-o"></i></a>
              <?php endif; ?>
              <a class="btn btn-default btn-sm" href="<?php echo admin_url('staff_classification/history/' . (int) $r['staffid']); ?>" title="Change history"><i class="fa fa-history"></i></a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
