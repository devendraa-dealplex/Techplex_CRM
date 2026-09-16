<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-10 col-md-offset-1">
  <div class="panel_s"><div class="panel-body">
    <h4 style="color:#12507F;font-weight:600">
      Classification history &mdash; <?php echo html_escape(trim($staff_member->firstname . ' ' . $staff_member->lastname)); ?>
    </h4>
    <p class="text-muted">Every field change, with who made it and what it was before. The previous
      version of this module logged only that something had changed.</p>

    <?php if (empty($entries)): ?>
      <div class="alert alert-info" style="font-size:13px">
        <b>No changes recorded yet.</b> This history starts from the day field-level auditing was added;
        classification values set before then have no entry, which is stated here rather than shown as
        &ldquo;never changed&rdquo;.
      </div>
    <?php else: ?>
      <div class="table-responsive"><table class="table">
        <thead><tr><th>When</th><th>Field</th><th>From</th><th>To</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $e): ?>
          <tr>
            <td><?php echo html_escape($e['changed_at']); ?></td>
            <td><code><?php echo html_escape($e['field']); ?></code></td>
            <td><?php echo $e['old_value'] === null ? '<span class="text-muted">empty</span>' : html_escape($e['old_value']); ?></td>
            <td><?php echo $e['new_value'] === null ? '<span class="text-muted">empty</span>' : html_escape($e['new_value']); ?></td>
            <td><?php echo html_escape($e['actor_name'] ?: ('#' . (int) $e['actor_id'])); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <a class="btn btn-default" href="<?php echo admin_url('staff_classification'); ?>">Back</a>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
