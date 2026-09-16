<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
  $val = function ($field, $default = '') use ($posted, $classification) {
      if (is_array($posted) && array_key_exists($field, $posted)) { return (string) $posted[$field]; }
      if (is_array($classification) && array_key_exists($field, $classification)
          && $classification[$field] !== null) { return (string) $classification[$field]; }
      return (string) $default;
  };
  $err = function ($field) use ($errors) { return isset($errors[$field]) ? $errors[$field] : ''; };
  // legacy work_mode carries forward when work_category has never been set
  $wc = $val('work_category');
  if ($wc === '' && is_array($classification) && !empty($classification['work_mode'])) {
      $wc = Workforce_classification::legacyWorkMode($classification['work_mode']);
  }
?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-8 col-md-offset-2">
  <div class="panel_s"><div class="panel-body">

    <h4 style="color:#12507F;font-weight:600">
      Edit Staff Classification &mdash;
      <?php echo html_escape(trim($staff_member->firstname . ' ' . $staff_member->lastname)); ?>
      <small class="text-muted">#<?php echo (int) $staff_id; ?></small>
    </h4>

    <?php if ($gate['code'] === 'inactive'): ?>
      <div class="alert alert-warning" style="font-size:13px">
        <?php echo html_escape($gate['message']); ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger" style="font-size:13px">
        <b>Nothing was saved.</b> <?php echo count($errors); ?> field(s) need correcting. Your other
        entries are kept below.
      </div>
    <?php endif; ?>

    <?php echo form_open(admin_url('staff_classification/edit/' . (int) $staff_id)); ?>

      <div class="form-group<?php echo $err('employment_type') ? ' has-error' : ''; ?>">
        <label>Employment Type <small class="text-muted">&mdash; how this person is engaged</small></label>
        <select class="form-control" name="employment_type">
          <option value="">Not set</option>
          <?php foreach ($employment_types as $k => $label): ?>
            <option value="<?php echo html_escape($k); ?>"<?php echo $val('employment_type') === $k ? ' selected' : ''; ?>>
              <?php echo html_escape($label); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($err('employment_type')): ?><span class="help-block"><?php echo html_escape($err('employment_type')); ?></span><?php endif; ?>
      </div>

      <div class="form-group<?php echo $err('work_category') ? ' has-error' : ''; ?>">
        <label>Work Category <small class="text-muted">&mdash; where the work happens</small></label>
        <select class="form-control" name="work_category">
          <option value="">Not set</option>
          <?php foreach ($work_categories as $k => $label): ?>
            <option value="<?php echo html_escape($k); ?>"<?php echo $wc === $k ? ' selected' : ''; ?>>
              <?php echo html_escape($label); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($err('work_category')): ?><span class="help-block"><?php echo html_escape($err('work_category')); ?></span><?php endif; ?>
      </div>

      <div class="form-group<?php echo $err('department_id') ? ' has-error' : ''; ?>">
        <label>Department</label>
        <select class="form-control" name="department_id">
          <option value="">Not set</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?php echo (int) $d['departmentid']; ?>"<?php echo $val('department_id') === (string) $d['departmentid'] ? ' selected' : ''; ?>>
              <?php echo html_escape($d['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($err('department_id')): ?><span class="help-block"><?php echo html_escape($err('department_id')); ?></span><?php endif; ?>
        <?php if (!empty($classification['department'])): ?>
          <span class="help-block">
            Previously stored as free text: <b><?php echo html_escape($classification['department']); ?></b>.
            Choose the real department above to link it; the old text is kept until you do.
          </span>
        <?php endif; ?>
      </div>

      <div class="form-group<?php echo $err('manager_id') ? ' has-error' : ''; ?>">
        <label>Reporting Manager</label>
        <select class="form-control" name="manager_id">
          <option value="">No manager assigned</option>
          <?php foreach ($managers as $m): ?>
            <option value="<?php echo (int) $m['staffid']; ?>"<?php echo $val('manager_id') === (string) $m['staffid'] ? ' selected' : ''; ?>>
              <?php echo html_escape(trim($m['firstname'] . ' ' . $m['lastname'])); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($err('manager_id')): ?><span class="help-block"><?php echo html_escape($err('manager_id')); ?></span><?php endif; ?>
      </div>

      <?php foreach (array('branch' => 'Branch', 'territory' => 'Territory', 'shift' => 'Shift') as $f => $label): ?>
        <div class="form-group<?php echo $err($f) ? ' has-error' : ''; ?>">
          <label><?php echo $label; ?></label>
          <input type="text" class="form-control" name="<?php echo $f; ?>" maxlength="100"
                 value="<?php echo html_escape($val($f)); ?>">
          <?php if ($err($f)): ?><span class="help-block"><?php echo html_escape($err($f)); ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>

      <button type="submit" class="btn btn-primary">Save</button>
      <a class="btn btn-default" href="<?php echo admin_url('staff_classification'); ?>">Cancel</a>
      <a class="btn btn-link pull-right" href="<?php echo admin_url('staff_classification/history/' . (int) $staff_id); ?>">Change history</a>

    <?php echo form_close(); ?>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
