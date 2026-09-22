<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * /admin/video-kyc/employees — Employee Video KYC.
 *   HR / admin: see every active employee, send them a KYC link, review videos.
 *   A manager:  sees only the employees who report to them and reviews their videos.
 * Expects: $can, $can_send, $employees (Videokyc_model::employeeRows), $ttl_hours.
 */
$labels = ['pending' => ['Link sent', 'default'], 'in_progress' => ['Recording', 'info'], 'submitted' => ['Awaiting review', 'warning'],
    'approved' => ['Approved', 'success'], 'rejected' => ['Rejected', 'danger'], 'resubmit' => ['Asked to resubmit', 'warning'], 'expired' => ['Link expired', 'default']];
init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">

      <div class="kyc-head">
        <h4 class="no-margin kyc-title"><i class="fa fa-id-badge"></i> Employee Video KYC</h4>
      </div>

      <!-- ------------------------------------------------------ employees -->
      <div class="panel_s"><div class="panel-body">
        <div class="kyc-toolbar">
          <h5 class="no-margin"><?php echo $can_send ? 'Send a KYC link' : 'Your team'; ?></h5>
          <?php if ($can_send) { ?>
          <div class="kyc-filters">
            <label class="mright10"><input type="checkbox" class="emp-ch" value="email" checked> Email</label>
            <label class="mright10"><input type="checkbox" class="emp-ch" value="sms"> SMS</label>
            <label class="mright10"><input type="checkbox" class="emp-ch" value="whatsapp"> WhatsApp</label>
            <button type="button" class="btn btn-default btn-sm" id="emp-pick-pending">Select all not yet verified</button>
            <button type="button" class="btn btn-primary btn-sm" id="emp-send"><i class="fa fa-paper-plane"></i> Send link to selected</button>
          </div>
          <?php } ?>
        </div>
        <div id="emp-msg" class="mtop10"></div>

        <div class="table-responsive mtop10">
          <table class="table table-hover" id="emp-table">
            <thead><tr>
              <?php if ($can_send) { ?><th style="width:30px"></th><?php } ?>
              <th>Employee</th><th>Email</th><th>KYC status</th>
            </tr></thead>
            <tbody>
            <?php if (empty($employees)) { ?>
              <tr><td colspan="<?php echo $can_send ? 4 : 3; ?>" class="text-muted">No employees to show.</td></tr>
            <?php } else { foreach ($employees as $e) {
                $st    = $e->request_status;
                $label = $st && isset($labels[$st]) ? $labels[$st] : ['Not started', 'default'];
                $done  = in_array($st, ['approved', 'submitted'], true); ?>
              <tr>
                <?php if ($can_send) { ?>
                  <td><input type="checkbox" class="emp-pick" value="<?php echo (int) $e->staffid; ?>" <?php echo $done ? 'disabled' : ''; ?>></td>
                <?php } ?>
                <td><strong><?php echo html_escape($e->name); ?></strong></td>
                <td><?php echo html_escape($e->email); ?></td>
                <td><span class="label label-<?php echo $label[1]; ?>"><?php echo html_escape($label[0]); ?></span></td>
              </tr>
            <?php } } ?>
            </tbody>
          </table>
        </div>
      </div></div>

      <!-- -------------------------------------------------------- requests -->
      <div class="panel_s"><div class="panel-body">
        <div class="kyc-toolbar">
          <h5 class="no-margin">Submitted videos and requests</h5>
          <div class="kyc-filters">
            <select id="kyc-filter-status" class="form-control input-sm">
              <option value="">All statuses</option>
              <option value="pending">Pending (link sent)</option>
              <option value="in_progress">Recording</option>
              <option value="submitted">Awaiting review</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="resubmit">Asked to resubmit</option>
              <option value="expired">Expired</option>
            </select>
            <input type="search" id="kyc-filter-q" class="form-control input-sm" placeholder="Search name or email">
            <button type="button" class="btn btn-default btn-sm" id="kyc-refresh" title="Refresh"><i class="fa fa-refresh"></i></button>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover kyc-table" id="kyc-table" data-per-page="15">
            <thead><tr>
              <th>Employee</th><th>Generated</th><th>Status</th><th>Channels</th><th class="text-right">Actions</th>
            </tr></thead>
            <tbody><tr><td colspan="5" class="text-muted">Loading…</td></tr></tbody>
          </table>
        </div>
        <div class="kyc-pager" id="kyc-pager"></div>
      </div></div>

    </div></div>
  </div>
</div>

<?php $this->load->view('payplex_videokyc/review_modal', ['can' => $can]); ?>

<script>
window.KYC = {
  base: <?php echo json_encode(admin_url('video-kyc')); ?>,
  can: <?php echo json_encode($can); ?>,
  kind: 'employee',
  languages: <?php echo json_encode($languages, JSON_UNESCAPED_UNICODE); ?>,
  isDashboard: false
};
</script>
<?php init_tail(); ?>
<?php /* after init_tail(): jQuery is only loaded there, so this script must come after it */ ?>
<?php if ($can_send) { ?>
<script>
(function ($) {
  function msg(type, text) { $('#emp-msg').html('<div class="alert alert-' + type + '">' + $('<div>').text(text).html() + '</div>'); }

  $('#emp-pick-pending').on('click', function () {
    $('.emp-pick:not(:disabled)').prop('checked', true);
  });

  $('#emp-send').on('click', function () {
    var ids = $('.emp-pick:checked').map(function () { return this.value; }).get();
    var ch  = $('.emp-ch:checked').map(function () { return this.value; }).get();
    if (!ids.length) { msg('warning', 'Tick at least one employee first.'); return; }
    if (!ch.length) { msg('warning', 'Choose at least one delivery channel.'); return; }
    if (!window.confirm('Send a Video KYC link to ' + ids.length + ' employee(s)?')) { return; }
    var btn = $(this).prop('disabled', true);
    $.post(KYC.base + '/employee_send', { staff_ids: ids, channels: ch }, null, 'json')
      .done(function (r) {
        var t = r.message + (r.skipped && r.skipped.length ? ' Skipped: ' + r.skipped.join('; ') : '');
        msg(r.sent ? 'success' : 'warning', t);
        setTimeout(function () { window.location.reload(); }, 2500);
      })
      .fail(function (xhr) {
        var m = (xhr.responseJSON && xhr.responseJSON.message) || 'Could not send. Please try again.';
        msg('danger', m);
      })
      .always(function () { btn.prop('disabled', false); });
  });
})(window.jQuery);
</script>
<?php } ?>
