<?php defined('BASEPATH') or exit('No direct script access allowed'); init_head();
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun']; ?>
<div id="wrapper"><div class="content">
    <h4 class="tw-font-semibold tw-mb-3">Employees
        <?php if (staff_can('create', ATT_MODULE)) { ?><button class="btn btn-primary pull-right" onclick="attEmp()">Add Individual</button><?php } ?></h4>

    <form method="get" class="panel_s"><div class="panel-body tw-flex tw-flex-wrap tw-gap-3 tw-items-end">
        <div><label>Search</label><input name="q" class="form-control" placeholder="Name or ID" value="<?= html_escape($f['q']); ?>"></div>
        <div><label>Role</label><input name="role" class="form-control" value="<?= html_escape($f['role']); ?>"></div>
        <div><label>Location</label><select name="workplace_id" class="form-control"><option value="">All</option>
            <?php foreach ($workplaces as $w) { ?><option value="<?= (int) $w['id']; ?>" <?= $f['workplace_id'] == $w['id'] ? 'selected' : ''; ?>><?= html_escape($w['name']); ?></option><?php } ?>
        </select></div>
        <button class="btn btn-primary">Filter</button>
    </div></form>

    <div class="panel_s"><div class="panel-body table-responsive">
    <table class="table table-striped">
        <thead><tr><th></th><th>ID</th><th>Name</th><th>Role / Dept</th><th>Workplace</th><th>Hours</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $e) { ?>
            <tr>
                <td><?php if ($e['photo']) { ?><img src="<?= admin_url(ATT_MODULE . '/image/photo/' . $e['id']); ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover"><?php } ?></td>
                <td><?= html_escape($e['emp_code']); ?></td>
                <td><?= html_escape($e['full_name']); ?><div class="text-muted"><small><?= html_escape($e['phone']); ?></small></div></td>
                <td><?= html_escape($e['role']); ?><div class="text-muted"><small><?= html_escape($e['department']); ?></small></div></td>
                <td><?= $e['workplace_name'] ? html_escape($e['workplace_name']) : '<span class="label label-warning">Not assigned</span>'; ?></td>
                <td><?= substr($e['start_time'], 0, 5) . '-' . substr($e['end_time'], 0, 5); ?>
                    <div class="text-muted"><small><?= implode(',', array_map(function ($d) use ($dayNames) { return $dayNames[$d] ?? ''; }, explode(',', (string) $e['working_days']))); ?></small></div></td>
                <td><span class="label label-<?= $e['active'] ? 'success' : 'default'; ?>"><?= $e['active'] ? 'Active' : 'Inactive'; ?></span></td>
                <td class="text-right">
                    <?php if (staff_can('edit', ATT_MODULE)) { ?><button class="btn btn-default btn-xs" data-emp='<?= html_escape(json_encode($e)); ?>' onclick="attEmp(this)">Edit</button><?php } ?>
                    <?php if (staff_can('delete', ATT_MODULE)) { echo form_open(admin_url(ATT_MODULE . '/employee_delete/' . $e['id']), ['style' => 'display:inline', 'onsubmit' => "return confirm('Delete this employee?')"]); ?>
                        <button class="btn btn-danger btn-xs">Delete</button><?= form_close(); } ?>
                </td>
            </tr>
        <?php } if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted">No employees. Add a workplace first, then add employees.</td></tr><?php } ?>
        </tbody>
    </table>
    </div></div>
</div></div>

<div class="modal fade" id="attEmpModal"><div class="modal-dialog"><div class="modal-content">
<?= form_open_multipart(admin_url(ATT_MODULE . '/employee_save'), ['id' => 'attEmpForm']); ?>
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">Employee</h4></div>
    <div class="modal-body">
        <input type="hidden" name="id">
        <div class="row">
            <div class="col-sm-6 form-group"><label>Full name *</label><input name="full_name" class="form-control" required></div>
            <div class="col-sm-6 form-group"><label>Employee / ID number *</label><input name="emp_code" class="form-control" required></div>
            <div class="col-sm-6 form-group"><label>Role / designation</label><input name="role" class="form-control"></div>
            <div class="col-sm-6 form-group"><label>Department</label><input name="department" class="form-control"></div>
            <div class="col-sm-12 form-group"><label>Place of work (geofence) *</label>
                <select name="workplace_id" class="form-control" required><option value="">Select workplace</option>
                <?php foreach ($workplaces as $w) { ?><option value="<?= (int) $w['id']; ?>"><?= html_escape($w['name']); ?> (<?= (int) $w['radius_m']; ?> m)</option><?php } ?></select></div>
            <div class="col-sm-6 form-group"><label>Work start *</label><input type="time" name="start_time" class="form-control" value="<?= html_escape($defaults['start']); ?>" required></div>
            <div class="col-sm-6 form-group"><label>Work end *</label><input type="time" name="end_time" class="form-control" value="<?= html_escape($defaults['end']); ?>" required></div>
            <div class="col-sm-12 form-group"><label>Working days *</label><div>
                <?php foreach ($dayNames as $n => $d) { ?><label class="checkbox-inline"><input type="checkbox" name="working_days[]" value="<?= $n; ?>" <?= in_array($n, $defaults['days']) ? 'checked' : ''; ?>> <?= $d; ?></label><?php } ?></div></div>
            <div class="col-sm-6 form-group"><label>Phone</label><input name="phone" class="form-control"></div>
            <div class="col-sm-6 form-group"><label>Email</label><input type="email" name="email" class="form-control"></div>
            <div class="col-sm-12 form-group"><label>Profile photo (JPG/PNG, max 2 MB)</label><input type="file" name="photo" accept="image/jpeg,image/png" class="form-control"></div>
            <div class="col-sm-12 form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
            <div class="col-sm-12"><label class="checkbox-inline"><input type="checkbox" name="inactive" value="1"> Inactive (cannot mark attendance)</label></div>
        </div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
<?= form_close(); ?>
</div></div></div>

<?php init_tail(); ?>
<script>
function attEmp(btn) {
    var f = $('#attEmpForm')[0], e = btn && btn.dataset.emp ? JSON.parse(btn.dataset.emp) : null;
    f.reset();
    f.id.value = e ? e.id : '';
    if (e) {
        ['full_name', 'emp_code', 'role', 'department', 'workplace_id', 'phone', 'email', 'notes'].forEach(function (k) { f[k].value = e[k] || ''; });
        f.start_time.value = (e.start_time || '09:00').substr(0, 5);
        f.end_time.value = (e.end_time || '18:00').substr(0, 5);
        var days = (e.working_days || '').split(',');
        $(f).find('[name="working_days[]"]').each(function () { this.checked = days.indexOf(this.value) > -1; });
        f.inactive.checked = e.active == 0;
    }
    $('#attEmpModal').modal('show');
}
</script>
</body></html>
