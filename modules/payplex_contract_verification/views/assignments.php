<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * Who is on this contract.
 *
 * Revoked rows are shown, greyed, rather than hidden. A screen that lists only
 * live assignments answers "who is on this contract" correctly and "who was on
 * it in March" not at all, and the second question is the one asked after
 * something has gone wrong.
 */
?>
<?php init_head(); ?>
<?php echo payplex_cv_responsive_table_assets(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s cv-exec"><div class="panel-body">

      <h4 class="no-mtop"><?php echo html_escape((string) $title); ?></h4>
      <p class="text-muted small">
        An assignment grants nothing on its own. The person must also hold the capability; an
        assignment only decides <em>which contracts</em> their capabilities apply to.
      </p>

      <div class="cv-tablewrap"><div class="table-responsive">
      <table class="table table-condensed">
        <thead><tr>
          <th>Staff</th><th>Role</th><th>Granted</th><th>Expires</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($assignments as $a) { ?>
          <tr<?php echo empty($a['is_active']) ? ' class="text-muted"' : ''; ?>>
            <td>staff #<?php echo (int) $a['staff_id']; ?></td>
            <td>
              <?php $r = (string) $a['assignment_role']; ?>
              <?php echo html_escape(isset($roles[$r]) ? $roles[$r]['label'] : $r); ?>
            </td>
            <td><?php echo (int) $a['assigned_at'] > 0 ? date('Y-m-d', (int) $a['assigned_at']) : '—'; ?></td>
            <td><?php echo (int) $a['expires_at'] > 0 ? date('Y-m-d', (int) $a['expires_at']) : 'No expiry'; ?></td>
            <td>
              <?php if (!empty($a['is_active'])) { ?>
                Active
              <?php } else { ?>
                Revoked<?php echo (int) $a['revoked_at'] > 0 ? ' ' . date('Y-m-d', (int) $a['revoked_at']) : ''; ?>
              <?php } ?>
            </td>
            <td>
              <?php if (!empty($a['is_active'])) { ?>
                <?php echo form_open(admin_url('payplex_contract_verification/signing/assignment_revoke/'
                                               . (int) $contract['id']), array('class' => 'dinline')); ?>
                  <input type="hidden" name="assignment_id" value="<?php echo (int) $a['id']; ?>">
                  <input type="text" name="reason" class="form-control input-sm"
                         placeholder="Reason (10+ chars)" minlength="10" required>
                  <button type="submit" class="btn btn-default btn-xs" data-cv-once="1">Revoke</button>
                <?php echo form_close(); ?>
              <?php } ?>
            </td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
      </div><p class="cv-swipe">Swipe to view more</p></div>

      <h5>Add an assignment</h5>
      <?php echo form_open(admin_url('payplex_contract_verification/signing/assignment_save/'
                                     . (int) $contract['id'])); ?>
        <div class="form-group">
          <label for="cv_staff_id">Staff ID</label>
          <input type="number" name="staff_id" id="cv_staff_id" class="form-control" min="1" required>
        </div>
        <div class="form-group">
          <label for="cv_role">Role</label>
          <select name="assignment_role" id="cv_role" class="form-control">
            <?php foreach ($roles as $key => $meta) { ?>
              <option value="<?php echo html_escape($key); ?>">
                <?php echo html_escape((string) $meta['label']); ?>
              </option>
            <?php } ?>
          </select>
        </div>
        <div class="form-group">
          <label for="cv_expires">Expires (unix timestamp, 0 for no expiry)</label>
          <input type="number" name="expires_at" id="cv_expires" class="form-control" value="0" min="0">
        </div>
        <button type="submit" class="btn btn-default btn-sm" data-cv-once="1">Grant</button>
      <?php echo form_close(); ?>

      <p class="mtop20"><a href="<?php echo html_escape((string) $back_url); ?>">Back to execution</a></p>

    </div></div>
  </div></div>
</div></div>
