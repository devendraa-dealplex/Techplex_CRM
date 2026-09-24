<?php defined('BASEPATH') or exit('No direct script access allowed'); init_head();
$fmt   = function ($m) { return $m > 0 ? floor($m / 60) . 'h ' . str_pad($m % 60, 2, '0', STR_PAD_LEFT) . 'm' : '-'; };
$admin = is_admin();
$badge = ['Present' => 'success', 'Late' => 'warning', 'Working' => 'info', 'Incomplete' => 'warning', 'Absent' => 'danger', 'Pending' => 'default', 'On Leave' => 'primary'];
$thumb = function ($id) {
    if (!$id) {
        return '<span class="text-muted">-</span>';
    }
    $u = admin_url(ATT_MODULE . '/image/record/' . (int) $id);

    return '<a href="' . $u . '" target="_blank"><img src="' . $u . '" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:6px"></a>';
};
?>
<div id="wrapper"><div class="content">
    <h4 class="tw-font-semibold tw-mb-3"><?= $self ? 'My Attendance' : 'Attendance Dashboard'; ?>
        <a class="btn btn-default pull-right" href="<?= admin_url(ATT_MODULE . '?' . http_build_query(array_filter([
            'from' => $f['from'], 'to' => $f['to'], 'q' => $f['q'], 'role' => $f['role'], 'workplace_id' => $f['workplace_id'], 'status' => $f['status'], 'export' => 1,
        ], 'strlen'))); ?>"><i class="fa-solid fa-file-csv"></i> Export CSV</a></h4>
    <?php $this->load->view('attendance_dashboard/_filters', ['f' => $f, 'workplaces' => $workplaces, 'showStatus' => true, 'showText' => !$self, 'showLocation' => !$self,
        'statusOptions' => ['' => 'All', 'present' => 'Present', 'late' => 'Late', 'working' => 'Working', 'incomplete' => 'Incomplete', 'absent' => 'Absent', 'leave' => 'On Leave']]); ?>

    <?php if (!$self) { ?>
    <div class="row">
    <?php foreach ([
        ['Total employees', $today['total'], 'default'],
        ['Present today', $today['present'], 'success'],
        ['Absent today', $today['absent'], 'danger'],
        ['Late today', $today['late'], 'warning'],
        ['Not checked out', $today['not_out'], 'default'],
        ['On leave today', $today['on_leave'], 'primary'],
        ['Attendance % (range)', $pct . '%', 'info'],
    ] as $c) { ?>
        <div class="col-md-2 col-sm-4 col-xs-6" style="width:14.28%;min-width:130px">
            <div class="panel_s"><div class="panel-body">
                <div class="text-muted"><?= $c[0]; ?></div>
                <div class="tw-text-3xl tw-font-bold text-<?= $c[2]; ?>"><?= $c[1]; ?></div>
            </div></div>
        </div>
    <?php } ?>
    </div>
    <?php } ?>

    <div class="panel_s"><div class="panel-body table-responsive">
    <table class="table table-striped">
        <thead><tr>
            <th>Employee</th><th>Check-in / Check-out</th><th>Date</th><th>Location</th><th>Status</th>
            <th>Clock in</th><th>Clock out</th><th>Late</th><th>Early</th><th>Overtime</th>
            <?php if ($admin) { ?><th>Action</th><?php } ?>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r) { ?>
            <tr>
                <td><?= html_escape($r['name']); ?><div class="text-muted"><small><?= html_escape($r['code']); ?></small></div></td>
                <td style="white-space:nowrap"><?= $thumb($r['in_id']); ?> <?= $thumb($r['out_id']); ?></td>
                <td><?= html_escape($r['date']); ?></td>
                <td><?= $r['workplace'] ? html_escape($r['workplace']) : '-'; ?></td>
                <td><span class="label label-<?= $badge[$r['status']]; ?>"><?= $r['status']; ?></span>
                    <?php if ($r['edited']) { ?><div class="text-muted"><small>edited</small></div><?php } ?></td>
                <td><?= $r['in_ts'] ? date('H:i', strtotime($r['in_ts'])) : '-'; ?></td>
                <td><?= $r['out_ts'] ? date('H:i', strtotime($r['out_ts'])) : '-'; ?></td>
                <td class="<?= $r['late'] ? 'text-warning' : ''; ?>"><?= $fmt($r['late']); ?></td>
                <td class="<?= $r['early'] ? 'text-warning' : ''; ?>"><?= $fmt($r['early']); ?></td>
                <td class="<?= $r['ot'] ? 'text-success' : ''; ?>"><?= $fmt($r['ot']); ?></td>
                <?php if ($admin) { ?>
                <td style="white-space:nowrap">
                    <?php if ($r['in_ts']) { ?>
                    <button class="btn btn-default btn-xs" onclick="attEdit(this)"
                        data-emp="<?= (int) $r['employee_id']; ?>" data-date="<?= html_escape($r['date']); ?>" data-name="<?= html_escape($r['name']); ?>"
                        data-in="<?= $r['in_ts'] ? date('H:i', strtotime($r['in_ts'])) : ''; ?>" data-out="<?= $r['out_ts'] ? date('H:i', strtotime($r['out_ts'])) : ''; ?>">Edit</button>
                    <?= form_open(admin_url(ATT_MODULE . '/record_delete'), ['style' => 'display:inline', 'onsubmit' => "return confirm('Delete this attendance (check-in and check-out)?')"]); ?>
                        <input type="hidden" name="employee_id" value="<?= (int) $r['employee_id']; ?>"><input type="hidden" name="date" value="<?= html_escape($r['date']); ?>">
                        <button class="btn btn-danger btn-xs">Delete</button>
                    <?= form_close(); } else { echo '<span class="text-muted">-</span>'; } ?>
                </td>
                <?php } ?>
            </tr>
        <?php } if (!$rows) { ?>
            <tr><td colspan="<?= $admin ? 11 : 10; ?>" class="text-center text-muted">No attendance for this selection.</td></tr>
        <?php } ?>
        </tbody>
    </table>
    </div></div>
</div></div>

<?php if ($admin) { ?>
<div class="modal fade" id="attEditModal"><div class="modal-dialog modal-sm"><div class="modal-content">
<?= form_open(admin_url(ATT_MODULE . '/record_edit'), ['id' => 'attEditForm']); ?>
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">Correct attendance</h4></div>
    <div class="modal-body">
        <p><strong id="attEditWho"></strong> <span class="text-muted" id="attEditDate"></span></p>
        <input type="hidden" name="employee_id"><input type="hidden" name="date">
        <div class="form-group"><label>Clock in</label><input type="time" name="in_time" class="form-control"></div>
        <div class="form-group"><label>Clock out</label><input type="time" name="out_time" class="form-control"></div>
        <div class="form-group"><label>Reason for correction *</label><input name="note" class="form-control" maxlength="255" required></div>
        <p class="text-muted"><small>Only the times of an existing verified check-in/out can be changed. Attendance cannot be added manually. Corrections are logged.</small></p>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
<?= form_close(); ?>
</div></div></div>
<?php } ?>

<?php init_tail(); ?>
<script>
function attEdit(b) {
    var f = $('#attEditForm')[0], d = b.dataset;
    f.employee_id.value = d.emp; f.date.value = d.date; f.in_time.value = d.in; f.out_time.value = d.out; f.note.value = '';
    f.out_time.disabled = !d.out; // no check-out exists: cannot be created manually
    $('#attEditWho').text(d.name); $('#attEditDate').text(d.date);
    $('#attEditModal').modal('show');
}
</script>
</body></html>
