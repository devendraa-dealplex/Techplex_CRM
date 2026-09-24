<?php defined('BASEPATH') or exit('No direct script access allowed'); init_head();
$badge = ['pending' => 'default', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'default'];
$label = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
?>
<div id="wrapper"><div class="content">
    <h4 class="tw-font-semibold tw-mb-3">Leave Requests
        <button class="btn btn-primary pull-right" onclick="attLeaveApply()">Apply for Leave</button></h4>

    <?php if (!$self) { ?>
    <form method="get" class="panel_s"><div class="panel-body tw-flex tw-flex-wrap tw-gap-3 tw-items-end">
        <div><label>Employee</label><select name="employee_id" class="form-control"><option value="">All</option>
            <?php foreach ($employees as $e) { ?><option value="<?= (int) $e['id']; ?>" <?= $f['employee_id'] == $e['id'] ? 'selected' : ''; ?>><?= html_escape($e['full_name']); ?></option><?php } ?>
        </select></div>
        <div><label>Status</label><select name="status" class="form-control">
            <?php foreach (['' => 'All'] + $label as $k => $v) { ?><option value="<?= $k; ?>" <?= $f['status'] === $k ? 'selected' : ''; ?>><?= $v; ?></option><?php } ?>
        </select></div>
        <button class="btn btn-primary">Filter</button>
    </div></form>
    <?php } elseif (!$me) { ?>
    <div class="alert alert-warning">Your login is not linked to an active employee record. Ask an administrator.</div>
    <?php } ?>

    <div class="panel_s"><div class="panel-body table-responsive">
    <table class="table table-striped">
        <thead><tr>
            <?php if (!$self) { ?><th>Employee</th><?php } ?>
            <th>Type</th><th>From</th><th>To</th><th>Days</th><th>Half day</th><th>Reason</th><th>Status</th><th>Reviewed</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r) { ?>
            <tr>
                <?php if (!$self) { ?><td><?= html_escape($r['full_name']); ?> <small class="text-muted"><?= html_escape($r['emp_code']); ?></small></td><?php } ?>
                <td><?= html_escape($types[$r['leave_type']] ?? $r['leave_type']); ?></td>
                <td><?= html_escape($r['from_date']); ?></td>
                <td><?= html_escape($r['to_date']); ?></td>
                <td><?= rtrim(rtrim(number_format((float) $r['days'], 1), '0'), '.'); ?></td>
                <td><?php if ($r['is_half_day']) { ?><span class="label label-info"><?= $r['half_session'] === 'second' ? 'Afternoon' : 'Morning'; ?></span><?php } else { echo '-'; } ?></td>
                <td><?= html_escape($r['reason']); ?></td>
                <td><span class="label label-<?= $badge[$r['status']] ?? 'default'; ?>"><?= $label[$r['status']] ?? $r['status']; ?></span></td>
                <td>
                    <?php if ($r['reviewed_at']) { ?>
                        <small class="text-muted"><?= html_escape(date('Y-m-d', strtotime($r['reviewed_at']))); ?><?php if ($r['review_note']) { ?><br><?= html_escape($r['review_note']); ?><?php } ?></small>
                    <?php } else { echo '-'; } ?>
                </td>
                <td style="white-space:nowrap">
                    <?php if ($r['status'] === 'pending' && $canReview) { ?>
                        <?= form_open(admin_url(ATT_MODULE . '/leave_review/' . $r['id']), ['style' => 'display:inline']); ?>
                            <input type="hidden" name="decision" value="approved">
                            <button class="btn btn-success btn-xs" onclick="return attLeaveNote(this)">Approve</button>
                        <?= form_close(); ?>
                        <?= form_open(admin_url(ATT_MODULE . '/leave_review/' . $r['id']), ['style' => 'display:inline']); ?>
                            <input type="hidden" name="decision" value="rejected">
                            <button class="btn btn-danger btn-xs" onclick="return attLeaveNote(this)">Reject</button>
                        <?= form_close(); ?>
                    <?php } elseif ($r['status'] === 'pending' && $self) { ?>
                        <?= form_open(admin_url(ATT_MODULE . '/leave_cancel/' . $r['id']), ['style' => 'display:inline', 'onsubmit' => "return confirm('Cancel this leave request?')"]); ?>
                            <button class="btn btn-default btn-xs">Cancel</button>
                        <?= form_close(); ?>
                    <?php } else { echo '-'; } ?>
                </td>
            </tr>
        <?php } if (!$rows) { ?>
            <tr><td colspan="<?= $self ? 8 : 9; ?>" class="text-center text-muted">No leave requests.</td></tr>
        <?php } ?>
        </tbody>
    </table>
    </div></div>
</div></div>

<div class="modal fade" id="attLeaveModal"><div class="modal-dialog modal-sm"><div class="modal-content">
<?= form_open(admin_url(ATT_MODULE . '/leave_apply'), ['id' => 'attLeaveForm']); ?>
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">Apply for Leave</h4></div>
    <div class="modal-body">
        <?php if ($self) { ?>
            <input type="hidden" name="employee_id" value="<?= (int) $me; ?>">
        <?php } else { ?>
            <div class="form-group"><label>Employee *</label>
                <select name="employee_id" class="form-control" required><option value="">Select...</option>
                <?php foreach ($employees as $e) { ?><option value="<?= (int) $e['id']; ?>"><?= html_escape($e['full_name'] . ' (' . $e['emp_code'] . ')'); ?></option><?php } ?>
                </select></div>
        <?php } ?>
        <div class="form-group"><label>Leave type *</label>
            <select name="leave_type" class="form-control" required>
                <?php foreach ($types as $k => $v) { ?><option value="<?= $k; ?>"><?= html_escape($v); ?></option><?php } ?>
            </select></div>
        <div class="row">
            <div class="col-sm-6 form-group"><label>From *</label><input type="date" id="attLvFrom" name="from_date" class="form-control" required></div>
            <div class="col-sm-6 form-group"><label>To *</label><input type="date" id="attLvTo" name="to_date" class="form-control" required></div>
        </div>
        <div class="checkbox checkbox-primary">
            <input type="checkbox" id="attLvHalf" name="half_day" value="1">
            <label for="attLvHalf">Half day (single date only)</label>
        </div>
        <div class="form-group" id="attLvSessionWrap" style="display:none">
            <label>Which half?</label>
            <select name="half_session" id="attLvSession" class="form-control">
                <option value="first">Morning (first half)</option>
                <option value="second">Afternoon (second half)</option>
            </select>
        </div>
        <div class="form-group"><label>Reason</label><textarea name="reason" class="form-control" rows="2" maxlength="500"></textarea></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Submit</button></div>
<?= form_close(); ?>
</div></div></div>

<?php init_tail(); ?>
<script>
function attLeaveApply() {
    $('#attLeaveForm')[0].reset();
    $('#attLvSessionWrap').hide();
    $('#attLvTo').prop('readonly', false);
    $('#attLeaveModal').modal('show');
}
$('#attLvHalf').on('change', function () {
    $('#attLvSessionWrap').toggle(this.checked);
    if (this.checked) { $('#attLvTo').val($('#attLvFrom').val()).prop('readonly', true); }
    else { $('#attLvTo').prop('readonly', false); }
});
$('#attLvFrom').on('change', function () {
    if ($('#attLvHalf').is(':checked')) { $('#attLvTo').val(this.value); }
});
function attLeaveNote(btn) {
    var note = prompt('Optional note for this decision:', '');
    if (note === null) { return false; } // cancelled
    var f = btn.closest('form');
    var i = document.createElement('input'); i.type = 'hidden'; i.name = 'note'; i.value = note;
    f.appendChild(i);
    return confirm((f.decision.value === 'approved' ? 'Approve' : 'Reject') + ' this leave request?');
}
</script>
</body></html>
