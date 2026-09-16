<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * Retention rules and legal holds.
 *
 * Two separate permissions are represented here, and the screen honours the
 * separation visually as well as server-side: someone who may apply a hold but
 * not release one sees no release control at all, rather than a button that
 * refuses. A control that is drawn and then refuses teaches people to click it.
 */
?>
<?php init_head(); ?>
<?php echo payplex_cv_responsive_table_assets(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s cv-exec"><div class="panel-body">

      <h4 class="no-mtop"><?php echo html_escape((string) $title); ?></h4>

      <div class="alert alert-info">
        <strong>Raw recordings and identity documents are not stored by default.</strong>
        Only references, decisions, timestamps and integrity hashes are kept. Storing raw media
        needs an approved retention period <em>and</em> a lawful basis, and neither is something
        this screen can decide on its own.
      </div>

      <h5>Rules</h5>
      <?php if (empty($rules)) { ?>
        <p class="text-muted">
          No explicit rule. The global setting applies as a fallback &mdash; an absent rule is not
          permission to keep evidence indefinitely.
        </p>
      <?php } else { ?>
        <div class="cv-tablewrap"><div class="table-responsive">
        <table class="table table-condensed">
          <thead><tr>
            <th>Scope</th><th>Evidence type</th><th>Days</th><th>Raw media</th><th>Lawful basis</th>
          </tr></thead>
          <tbody>
          <?php foreach ($rules as $r) { ?>
            <tr>
              <td><?php echo html_escape((string) $r['scope']); ?>
                  <?php echo (int) $r['scope_id'] > 0 ? '#' . (int) $r['scope_id'] : ''; ?></td>
              <td><?php echo html_escape((string) ($r['evidence_type'] === null ? 'all' : $r['evidence_type'])); ?></td>
              <td><?php echo (int) $r['retention_days']; ?></td>
              <td><?php echo !empty($r['store_raw_media']) ? 'Stored' : 'Not stored'; ?></td>
              <td class="cv-ref"><?php echo html_escape((string) $r['lawful_basis']); ?></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        </div><p class="cv-swipe">Swipe to view more</p></div>
      <?php } ?>

      <h5>Legal holds</h5>
      <?php if (empty($holds)) { ?>
        <p class="text-muted">No legal hold has been placed.</p>
      <?php } else { ?>
        <div class="cv-tablewrap"><div class="table-responsive">
        <table class="table table-condensed">
          <thead><tr>
            <th>Reference</th><th>Scope</th><th>Applied</th><th>Status</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($holds as $h) { ?>
            <tr>
              <td class="cv-ref"><?php echo html_escape((string) $h['reference']); ?></td>
              <td><?php echo html_escape((string) $h['scope']); ?>
                  <?php echo (int) $h['scope_id'] > 0 ? '#' . (int) $h['scope_id'] : ''; ?></td>
              <td>staff #<?php echo (int) $h['applied_by']; ?></td>
              <td><?php echo !empty($h['is_active']) ? 'Active' : 'Released'; ?></td>
              <td>
                <?php if (!empty($h['is_active']) && !empty($can_release)) { ?>
                  <?php echo form_open(admin_url('payplex_contract_verification/signing/legal_hold_release'),
                                       array('class' => 'dinline')); ?>
                    <input type="hidden" name="hold_id" value="<?php echo (int) $h['id']; ?>">
                    <input type="text" name="reason" class="form-control input-sm"
                           placeholder="Reason (10+ chars)" minlength="10" required>
                    <button type="submit" class="btn btn-default btn-xs" data-cv-once="1">Release</button>
                  <?php echo form_close(); ?>
                <?php } ?>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
        </div><p class="cv-swipe">Swipe to view more</p></div>
      <?php } ?>

      <h5>Set a retention rule</h5>
      <?php echo form_open(admin_url('payplex_contract_verification/signing/retention_save')); ?>
        <div class="form-group">
          <label for="cv_scope">Scope</label>
          <select name="scope" id="cv_scope" class="form-control">
            <option value="global">Global</option>
            <option value="client">Client</option>
            <option value="contract">Contract</option>
            <option value="evidence_type">Evidence type</option>
          </select>
        </div>
        <div class="form-group">
          <label for="cv_scope_id">Scope ID (0 for global)</label>
          <input type="number" name="scope_id" id="cv_scope_id" class="form-control" value="0" min="0">
        </div>
        <div class="form-group">
          <label for="cv_ev_type">Evidence type (blank for all)</label>
          <select name="evidence_type" id="cv_ev_type" class="form-control">
            <option value="">All types</option>
            <?php foreach ($evidence_types as $key => $meta) { ?>
              <option value="<?php echo html_escape($key); ?>">
                <?php echo html_escape((string) $meta['label']); ?>
              </option>
            <?php } ?>
          </select>
        </div>
        <div class="form-group">
          <label for="cv_days">Retention period in days</label>
          <input type="number" name="retention_days" id="cv_days" class="form-control" value="0" min="0">
        </div>
        <div class="form-group">
          <label for="cv_lawful">Lawful basis</label>
          <input type="text" name="lawful_basis" id="cv_lawful" class="form-control">
        </div>
        <div class="checkbox">
          <label>
            <input type="checkbox" name="store_raw_media" value="1">
            Store raw recordings and identity documents (needs a retention period and a lawful basis)
          </label>
        </div>
        <button type="submit" class="btn btn-default btn-sm" data-cv-once="1">Save rule</button>
      <?php echo form_close(); ?>

      <?php if (!empty($can_apply)) { ?>
        <h5 class="mtop20">Apply a legal hold</h5>
        <p class="text-muted small">
          A hold keeps evidence available past its retention date and stops it being deleted.
          Releasing one is a separate permission, and the person who applied a hold cannot release it.
        </p>
        <?php echo form_open(admin_url('payplex_contract_verification/signing/legal_hold_apply')); ?>
          <div class="form-group">
            <label for="cv_hold_scope">Scope</label>
            <select name="scope" id="cv_hold_scope" class="form-control">
              <option value="contract">Contract</option>
              <option value="client">Client</option>
              <option value="kyc_case">KYC case</option>
            </select>
          </div>
          <div class="form-group">
            <label for="cv_hold_scope_id">Scope ID</label>
            <input type="number" name="scope_id" id="cv_hold_scope_id" class="form-control" value="0" min="0">
          </div>
          <div class="form-group">
            <label for="cv_hold_ref">Reference</label>
            <input type="text" name="reference" id="cv_hold_ref" class="form-control" required>
          </div>
          <div class="form-group">
            <label for="cv_hold_reason">Reason (at least ten characters)</label>
            <input type="text" name="reason" id="cv_hold_reason" class="form-control" minlength="10" required>
          </div>
          <button type="submit" class="btn btn-default btn-sm" data-cv-once="1">Apply hold</button>
        <?php echo form_close(); ?>
      <?php } ?>

    </div></div>
  </div></div>
</div></div>
