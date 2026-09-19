<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Shared by /admin/video-kyc/dashboard ($show = 'dashboard': metric cards + latest 10)
 * and /admin/video-kyc/requests    ($show = 'requests':  filters + full paginated log).
 * All data is loaded by assets/js/videokyc.js from the JSON endpoints.
 */
$isDashboard = ($show === 'dashboard');
init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">

      <div class="kyc-head">
        <h4 class="no-margin kyc-title"><i class="fa fa-video-camera"></i> <?php echo $isDashboard ? 'Video KYC Dashboard' : 'Video KYC Requests / Logs'; ?></h4>
        <div>
          <?php if ($can['generate']): ?>
            <button type="button" class="btn btn-primary" id="kyc-open-generate"><i class="fa fa-link"></i> Generate New KYC Link</button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($isDashboard): ?>
      <!-- ---------------------------------------------------------- metrics -->
      <div class="row kyc-metrics" id="kyc-metrics">
        <div class="col-sm-6 col-md-3"><div class="kyc-card kyc-card-total">
          <div class="kyc-card-label">Total KYC Requests</div><div class="kyc-card-value" data-metric="total">—</div>
          <div class="kyc-card-sub"><span data-metric="awaiting_customer">—</span> awaiting customer</div></div></div>
        <div class="col-sm-6 col-md-3"><div class="kyc-card kyc-card-pending">
          <div class="kyc-card-label">Pending Approvals</div><div class="kyc-card-value" data-metric="pending_approval">—</div>
          <div class="kyc-card-sub">videos waiting for review</div></div></div>
        <div class="col-sm-6 col-md-3"><div class="kyc-card kyc-card-approved">
          <div class="kyc-card-label">Approved KYC</div><div class="kyc-card-value" data-metric="approved">—</div>
          <div class="kyc-card-sub">verified customers</div></div></div>
        <div class="col-sm-6 col-md-3"><div class="kyc-card kyc-card-rejected">
          <div class="kyc-card-label">Rejected KYC</div><div class="kyc-card-value" data-metric="rejected">—</div>
          <div class="kyc-card-sub"><span data-metric="expired">—</span> links expired</div></div></div>
      </div>
      <?php endif; ?>

      <!-- ------------------------------------------------------------ table -->
      <div class="panel_s"><div class="panel-body">
        <div class="kyc-toolbar">
          <h5 class="no-margin"><?php echo $isDashboard ? 'Live Video KYC Verifications' : 'All requests'; ?></h5>
          <div class="kyc-filters">
            <?php if (!$isDashboard): ?>
            <select id="kyc-filter-status" class="form-control input-sm">
              <option value="">All statuses</option>
              <option value="pending">Pending (link sent)</option>
              <option value="in_progress">Verification in progress</option>
              <option value="submitted">Awaiting review</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="expired">Expired</option>
            </select>
            <?php endif; ?>
            <input type="search" id="kyc-filter-q" class="form-control input-sm" placeholder="Search name, email or phone">
            <button class="btn btn-default btn-sm" id="kyc-refresh" title="Refresh"><i class="fa fa-refresh"></i></button>
            <?php if ($isDashboard): ?><a class="btn btn-default btn-sm" href="<?php echo admin_url('video-kyc/requests'); ?>">View all</a><?php endif; ?>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-hover kyc-table" id="kyc-table" data-per-page="<?php echo $isDashboard ? 10 : 25; ?>">
            <thead><tr>
              <th>Customer / Lead</th><th>Generated</th><th>Status</th><th>Channels</th><th class="text-right">Actions</th>
            </tr></thead>
            <tbody><tr><td colspan="5" class="text-muted">Loading…</td></tr></tbody>
          </table>
        </div>
        <div class="kyc-pager" id="kyc-pager"></div>
      </div></div>

    </div></div>
  </div>
</div>

<?php if ($can['generate']): ?>
<!-- ================================================== Generate-link modal -->
<div class="modal fade" id="kyc-generate-modal" tabindex="-1" role="dialog" aria-labelledby="kyc-generate-title">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
      <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
      <h4 class="modal-title" id="kyc-generate-title">Generate New KYC Link</h4>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>Who is being verified?</label>
        <div>
          <label class="radio-inline"><input type="radio" name="kyc_subject_type" value="lead" checked> Lead</label>
          <label class="radio-inline"><input type="radio" name="kyc_subject_type" value="customer"> Customer</label>
        </div>
        <input type="search" class="form-control mtop10" id="kyc-subject-q" placeholder="Search by name, email or phone…" autocomplete="off">
        <div class="kyc-subject-results" id="kyc-subject-results"></div>
        <div class="kyc-subject-chosen" id="kyc-subject-chosen" style="display:none"></div>
      </div>

      <div class="row">
        <div class="col-sm-6 form-group">
          <label for="kyc-template">Script template</label>
          <select class="form-control" id="kyc-template">
            <?php foreach ($templates as $t): ?>
              <option value="<?php echo (int) $t->id; ?>"
                      data-body="<?php echo html_escape($t->body); ?>"
                      data-body-hi="<?php echo html_escape((string) $t->body_hi); ?>"
                      data-body-mr="<?php echo html_escape((string) $t->body_mr); ?>"
                      <?php echo $t->is_default ? 'selected' : ''; ?>><?php echo html_escape($t->name); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6 form-group">
          <label for="kyc-language">Script Language</label>
          <select class="form-control" id="kyc-language" name="script_language">
            <?php foreach ($languages as $code => $label): ?>
              <option value="<?php echo html_escape($code); ?>" <?php echo $code === 'en' ? 'selected' : ''; ?>><?php echo html_escape($label); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="help-block no-margin">The customer reads the script, and receives the link message, in this language.</div>
        </div>
      </div>
      <div class="form-group">
        <label>Script preview <span class="label label-default" id="kyc-preview-lang">English</span></label>
        <div class="kyc-script-preview" id="kyc-script-preview" lang="en">Select a customer to preview the script.</div>
        <div class="text-warning kyc-lang-note" id="kyc-lang-note" style="display:none"></div>
      </div>
      <div class="row">
        <div class="col-sm-6 form-group">
          <label for="kyc-expiry">Link expires after (hours)</label>
          <input type="number" min="1" max="336" class="form-control" id="kyc-expiry" value="<?php echo (int) $ttl_hours; ?>">
          <div class="kyc-presets">
            <a href="#" data-hours="24">24h</a> · <a href="#" data-hours="48">48h</a> · <a href="#" data-hours="72">3d</a> · <a href="#" data-hours="168">7d</a>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label>Send the link by</label>
        <div id="kyc-channels">
          <label class="checkbox-inline"><input type="checkbox" value="email" checked> <i class="fa fa-envelope"></i> Email</label>
          <label class="checkbox-inline"><input type="checkbox" value="sms" checked> <i class="fa fa-commenting"></i> SMS</label>
          <label class="checkbox-inline"><input type="checkbox" value="whatsapp" checked> <i class="fa fa-whatsapp"></i> WhatsApp</label>
        </div>
        <p class="help-block" id="kyc-channel-hint"></p>
      </div>
      <div id="kyc-generate-result"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      <button type="button" class="btn btn-primary" id="kyc-generate-submit"><i class="fa fa-paper-plane"></i> Generate &amp; send</button>
    </div>
  </div></div>
</div>
<?php endif; ?>

<!-- ======================================================= Video review modal -->
<div class="modal fade" id="kyc-review-modal" tabindex="-1" role="dialog" aria-labelledby="kyc-review-title">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
      <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span>&times;</span></button>
      <h4 class="modal-title" id="kyc-review-title">Review Video KYC</h4>
    </div>
    <div class="modal-body">
      <div class="row">
        <div class="col-md-7">
          <div class="kyc-video-wrap" id="kyc-video-wrap"></div>
          <div class="kyc-video-meta" id="kyc-video-meta"></div>
        </div>
        <div class="col-md-5">
          <h5 class="kyc-sec">Customer</h5>
          <dl class="kyc-dl" id="kyc-customer"></dl>

          <h5 class="kyc-sec">Script they were asked to read <span class="label label-default" id="kyc-script-lang"></span></h5>
          <blockquote class="kyc-script" id="kyc-script"></blockquote>

          <div id="kyc-decision-block">
            <h5 class="kyc-sec">Verification checks</h5>
            <div id="kyc-checklist">
              <label><input type="checkbox" data-check="face_visible"> Face is clearly visible and matches the customer</label>
              <label><input type="checkbox" data-check="script_read"> Customer read the full script accurately</label>
              <label><input type="checkbox" data-check="audio_clear"> Audio is clear and understandable</label>
              <label><input type="checkbox" data-check="single_person"> Only one person is in the frame</label>
              <label><input type="checkbox" data-check="live_person"> Looks like a live person (not a photo, screen or recording)</label>
            </div>
            <div class="form-group mtop10">
              <label for="kyc-notes">Review notes <small class="text-muted">(required when rejecting)</small></label>
              <textarea id="kyc-notes" class="form-control" rows="3" maxlength="2000"></textarea>
            </div>
          </div>
          <div id="kyc-decided" style="display:none"></div>
          <div id="kyc-review-msg"></div>

          <h5 class="kyc-sec">Delivery log</h5>
          <ul class="kyc-log" id="kyc-notif-log"></ul>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      <?php if ($can['review']): ?>
      <span id="kyc-review-actions">
        <button type="button" class="btn btn-danger" id="kyc-reject"><i class="fa fa-times"></i> Reject</button>
        <button type="button" class="btn btn-success" id="kyc-approve"><i class="fa fa-check"></i> Approve</button>
      </span>
      <?php endif; ?>
    </div>
  </div></div>
</div>

<script>
window.KYC = {
  base: <?php echo json_encode(admin_url('video-kyc')); ?>,
  can: <?php echo json_encode($can); ?>,
  ttlHours: <?php echo (int) $ttl_hours; ?>,
  company: <?php echo json_encode((string) get_option('companyname')); ?>,
  languages: <?php echo json_encode($languages, JSON_UNESCAPED_UNICODE); ?>,
  langDefaults: <?php echo json_encode($lang_defaults, JSON_UNESCAPED_UNICODE); ?>,
  langMonths: <?php echo json_encode($lang_months, JSON_UNESCAPED_UNICODE); ?>,
  today: <?php echo json_encode(['d' => (int) date('j'), 'm' => (int) date('n'), 'y' => (int) date('Y')]); ?>,
  isDashboard: <?php echo $isDashboard ? 'true' : 'false'; ?>
};
</script>
<?php init_tail(); ?>
