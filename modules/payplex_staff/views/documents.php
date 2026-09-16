<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
  $c       = $compliance;
  $sum     = $c['summary'];
  $types   = Workforce_documents::types();
  $byType  = array();
  foreach ($c['documents'] as $d) { $byType[$d['doc_type']] = $d; }
  $stateCls = array('valid'=>'success','expiring_soon'=>'warning','expired'=>'danger',
                    'no_expiry'=>'warning','not_applicable'=>'default');
  $statusCls = array('verified'=>'success','rejected'=>'danger','pending'=>'warning');
?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-11 col-md-offset-1">
  <div class="panel_s"><div class="panel-body">

    <h4 style="color:#12507F;font-weight:600;margin:0">
      Documents &mdash; <?php echo html_escape(trim($member->firstname . ' ' . $member->lastname)); ?>
      <small class="text-muted">#<?php echo (int) $staff_id; ?></small>
    </h4>
    <p class="text-muted" style="margin:4px 0 14px">
      Engagement: <b><?php echo html_escape($c['employment_type'] ?: 'not classified'); ?></b>
      <?php if ($c['provisional']): ?>
        &middot; <span class="text-warning">the required list below is provisional &mdash;
        nobody has classified this person, so the common set applies</span>
      <?php endif; ?>
    </p>

    <?php if (!$storage['safe'] || !$storage['ready']): ?>
      <div class="alert alert-danger" style="font-size:13px">
        <b>Document storage is not usable.</b> <?php echo html_escape($storage['message']); ?>
        Uploads are refused until this is fixed &mdash; storing identity documents somewhere
        reachable over the web would be worse than not storing them.
      </div>
    <?php endif; ?>

    <div class="row" style="margin-bottom:16px">
      <div class="col-md-3"><div style="border:1px solid #E4E9F0;border-radius:4px;padding:12px;background:#F7F9FC">
        <div style="font-size:26px;font-weight:600;color:#12507F"><?php echo (int) $sum['percent']; ?>%</div>
        <div class="text-muted" style="font-size:12px">verified against requirement</div>
      </div></div>
      <div class="col-md-3"><div style="border:1px solid #E4E9F0;border-radius:4px;padding:12px">
        <div style="font-size:26px;font-weight:600"><?php echo count($sum['missing']); ?></div>
        <div class="text-muted" style="font-size:12px">not supplied</div>
      </div></div>
      <div class="col-md-3"><div style="border:1px solid #E4E9F0;border-radius:4px;padding:12px">
        <div style="font-size:26px;font-weight:600"><?php echo count($sum['unverified']); ?></div>
        <div class="text-muted" style="font-size:12px">supplied, not yet verified</div>
      </div></div>
      <div class="col-md-3"><div style="border:1px solid #E4E9F0;border-radius:4px;padding:12px">
        <div style="font-size:26px;font-weight:600"><?php echo count($sum['rejected']); ?></div>
        <div class="text-muted" style="font-size:12px">rejected</div>
      </div></div>
    </div>
    <p class="text-muted" style="font-size:12px;margin-top:-8px">
      Only <b>verified</b> documents count toward the percentage. An uploaded but unchecked
      document is a claim, and counting claims as compliance is how a file looks complete
      and is not.
    </p>

    <h5 style="font-weight:600;margin-top:20px">Required for this engagement</h5>
    <table class="table table-striped" style="font-size:13px">
      <thead><tr>
        <th>Document</th><th>Status</th><th>Expiry</th><th>Uploaded</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($c['required'] as $slug): $d = isset($byType[$slug]) ? $byType[$slug] : null; ?>
        <tr>
          <td>
            <?php echo html_escape(Workforce_documents::label($slug)); ?>
            <?php if (Workforce_documents::isSensitive($slug)): ?>
              <span class="label label-default" style="font-size:10px">sensitive</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$d): ?>
              <span class="label label-default">not supplied</span>
            <?php else: $st = $d['status']; ?>
              <span class="label label-<?php echo isset($statusCls[$st]) ? $statusCls[$st] : 'default'; ?>">
                <?php echo html_escape($st); ?></span>
              <?php if ($st === 'rejected' && $d['rejection_reason']): ?>
                <br><small class="text-danger"><?php echo html_escape($d['rejection_reason']); ?></small>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($d && isset($c['expiry'][$slug])): $e = $c['expiry'][$slug]; ?>
              <span class="label label-<?php echo isset($stateCls[$e['state']]) ? $stateCls[$e['state']] : 'default'; ?>">
                <?php echo html_escape(str_replace('_', ' ', $e['state'])); ?></span>
              <br><small class="text-muted"><?php echo html_escape($e['message']); ?></small>
            <?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?>
          </td>
          <td>
            <?php if ($d): ?>
              <small><?php echo html_escape($d['uploaded_at']); ?><br>
              <?php echo html_escape($d['original_name']); ?>
              (<?php echo number_format($d['bytes'] / 1024, 0); ?> KB)</small>
            <?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?>
          </td>
          <td>
            <?php if ($d): ?>
              <a class="btn btn-default btn-xs"
                 href="<?php echo admin_url('payplex_staff/staff/documents_download/' . (int) $d['id']); ?>">Download</a>
              <?php if ($can_verify && (int) $d['staff_id'] !== (int) get_staff_user_id() && $d['status'] === 'pending'): ?>
                <form method="post" style="display:inline"
                      action="<?php echo admin_url('payplex_staff/staff/documents_decide/' . (int) $d['id']); ?>">
                  <?php echo form_hidden($this->security->get_csrf_token_name(), $this->security->get_csrf_hash()); ?>
                  <input type="hidden" name="decision" value="verified">
                  <button class="btn btn-success btn-xs">Verify</button>
                </form>
              <?php elseif ($can_verify && $d['status'] === 'pending'): ?>
                <small class="text-muted">you cannot verify your own</small>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php
      /*
       * Documents held that the engagement does not require.
       *
       * Found by testing this screen on staging: a signed contract uploaded for
       * a freelancer sat in the database with no expiry date recorded and
       * appeared nowhere on their page, because the table above walks the
       * REQUIRED list. A lapsed contract nobody is required to hold is still a
       * lapsed contract, and a screen that hides it is how it stays lapsed.
       */
      $extra = array();
      foreach ($c['documents'] as $d) {
          if (!in_array($d['doc_type'], $c['required'], true)) { $extra[] = $d; }
      }
    ?>
    <?php if ($extra): ?>
      <h5 style="font-weight:600;margin-top:22px">Also on file</h5>
      <p class="text-muted" style="font-size:12px;margin-top:-6px">
        Not required for this engagement, so these do not count toward the percentage above &mdash;
        but they are held, and an expiry on one still matters.
      </p>
      <table class="table" style="font-size:13px">
        <thead><tr><th>Document</th><th>Status</th><th>Expiry</th><th>Uploaded</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($extra as $d): $e = isset($c['expiry'][$d['doc_type']]) ? $c['expiry'][$d['doc_type']] : null; ?>
          <tr>
            <td><?php echo html_escape(Workforce_documents::label($d['doc_type'])); ?></td>
            <td><span class="label label-<?php echo isset($statusCls[$d['status']]) ? $statusCls[$d['status']] : 'default'; ?>">
                <?php echo html_escape($d['status']); ?></span></td>
            <td>
              <?php if ($e): ?>
                <span class="label label-<?php echo isset($stateCls[$e['state']]) ? $stateCls[$e['state']] : 'default'; ?>">
                  <?php echo html_escape(str_replace('_', ' ', $e['state'])); ?></span>
                <br><small class="text-muted"><?php echo html_escape($e['message']); ?></small>
              <?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?>
            </td>
            <td><small><?php echo html_escape($d['uploaded_at']); ?><br>
                <?php echo html_escape($d['original_name']); ?></small></td>
            <td><a class="btn btn-default btn-xs"
                   href="<?php echo admin_url('payplex_staff/staff/documents_download/' . (int) $d['id']); ?>">Download</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($can_verify): ?>
      <h5 style="font-weight:600;margin-top:20px">Reject a document</h5>
      <form method="post" class="form-inline" style="margin-bottom:18px"
            action="<?php echo admin_url('payplex_staff/staff/documents_decide/0'); ?>"
            onsubmit="this.action=this.action.replace(/\/0$/,'/'+this.doc.value)">
        <?php echo form_hidden($this->security->get_csrf_token_name(), $this->security->get_csrf_hash()); ?>
        <input type="hidden" name="decision" value="rejected">
        <select name="doc" class="form-control input-sm">
          <?php foreach ($c['documents'] as $d): ?>
            <option value="<?php echo (int) $d['id']; ?>">
              #<?php echo (int) $d['id']; ?> &middot; <?php echo html_escape(Workforce_documents::label($d['doc_type'])); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="reason" class="form-control input-sm" style="width:320px"
               placeholder="Why (required) — e.g. the scan is unreadable">
        <button class="btn btn-danger btn-sm">Reject</button>
      </form>
    <?php endif; ?>

    <h5 style="font-weight:600;margin-top:20px">Upload</h5>
    <form method="post" enctype="multipart/form-data" class="form-inline"
          action="<?php echo admin_url('payplex_staff/staff/documents_upload'); ?>">
      <?php echo form_hidden($this->security->get_csrf_token_name(), $this->security->get_csrf_hash()); ?>
      <input type="hidden" name="staff_id" value="<?php echo (int) $staff_id; ?>">
      <select name="doc_type" class="form-control input-sm">
        <?php foreach ($types as $slug => $t): ?>
          <option value="<?php echo html_escape($slug); ?>"><?php echo html_escape($t['label']); ?></option>
        <?php endforeach; ?>
      </select>
      <input type="file" name="document" class="form-control input-sm">
      <input type="date" name="issued_on" class="form-control input-sm" title="Issued on">
      <input type="date" name="expires_on" class="form-control input-sm" title="Expires on">
      <button class="btn btn-primary btn-sm" <?php echo $storage['ready'] ? '' : 'disabled'; ?>>Upload</button>
    </form>
    <p class="text-muted" style="font-size:12px;margin-top:6px">
      PDF, JPG or PNG, up to <?php echo (int) (Workforce_documents::MAX_BYTES / 1048576); ?> MB.
      Files are stored outside the web root and can only be reached through this screen;
      every download is logged. Replacing a document keeps the previous version rather than
      overwriting it.
    </p>

    <?php
      $superseded = array();
      foreach ($history as $h) { if ((int) $h['is_current'] === 0) { $superseded[] = $h; } }
    ?>
    <?php if ($superseded): ?>
      <h5 style="font-weight:600;margin-top:24px">Superseded versions</h5>
      <table class="table" style="font-size:12px">
        <thead><tr><th>#</th><th>Type</th><th>Uploaded</th><th>Was</th><th>Replaced by</th></tr></thead>
        <tbody>
        <?php foreach ($superseded as $h): ?>
          <tr>
            <td><?php echo (int) $h['id']; ?></td>
            <td><?php echo html_escape(Workforce_documents::label($h['doc_type'])); ?></td>
            <td><?php echo html_escape($h['uploaded_at']); ?></td>
            <td><?php echo html_escape($h['status']); ?></td>
            <td>#<?php echo (int) $h['replaced_by_id']; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($log): ?>
      <h5 style="font-weight:600;margin-top:24px">Access log</h5>
      <p class="text-muted" style="font-size:12px;margin-top:-6px">
        Written before the file is served, and before a refusal returns &mdash; a log written
        afterwards is missing exactly the reads that went wrong.
      </p>
      <table class="table table-striped" style="font-size:12px">
        <thead><tr><th>When</th><th>Document</th><th>Action</th><th>By</th><th>IP</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($log as $l): ?>
          <tr>
            <td><?php echo html_escape($l['occurred_at']); ?></td>
            <td>#<?php echo (int) $l['document_id']; ?></td>
            <td><span class="label label-<?php echo $l['action'] === 'refused' ? 'danger' : 'default'; ?>">
                <?php echo html_escape($l['action']); ?></span></td>
            <td>#<?php echo (int) $l['actor_id']; ?></td>
            <td><?php echo html_escape($l['ip']); ?></td>
            <td><?php echo html_escape($l['detail']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

  </div></div>
</div></div></div>
<?php init_tail(); ?>
