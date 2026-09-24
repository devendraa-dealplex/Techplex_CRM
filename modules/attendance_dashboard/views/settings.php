<?php defined('BASEPATH') or exit('No direct script access allowed'); init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-6">
    <h4 class="tw-font-semibold tw-mb-3">Attendance Settings</h4>
    <div class="panel_s"><div class="panel-body">
        <?= form_open(admin_url(ATT_MODULE . '/settings')); ?>
        <h5 class="tw-font-semibold">Official working hours</h5>
        <p class="text-muted">Used for every new employee. Individual hours can still be changed on the Employees page.</p>
        <div class="row">
            <div class="col-sm-6 form-group"><label>Work start</label><input type="time" name="work_start" class="form-control" value="<?= html_escape($def['start']); ?>" required></div>
            <div class="col-sm-6 form-group"><label>Work end</label><input type="time" name="work_end" class="form-control" value="<?= html_escape($def['end']); ?>" required></div>
        </div>
        <div class="form-group"><label>Working days</label><div>
            <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $n => $d) { ?>
                <label class="checkbox-inline"><input type="checkbox" name="work_days[]" value="<?= $n; ?>" <?= in_array($n, $def['days']) ? 'checked' : ''; ?>> <?= $d; ?></label>
            <?php } ?></div></div>
        <div class="checkbox checkbox-danger">
            <input type="checkbox" id="applyall" name="apply_all" value="1">
            <label for="applyall"><strong>Apply these hours to ALL existing employees now</strong>
                <span class="text-muted">(replaces any individual hours already set)</span></label>
        </div>
        <hr>
        <h5 class="tw-font-semibold">Attendance rules</h5>
        <div class="form-group"><label>Minutes before shift start that check-in opens</label>
            <input type="number" min="0" max="240" name="early_minutes" class="form-control" value="<?= (int) $s['early_minutes']; ?>"></div>
        <div class="form-group"><label>Grace minutes after start before "late"</label>
            <input type="number" min="0" max="120" name="late_grace_minutes" class="form-control" value="<?= (int) $s['late_grace_minutes']; ?>"></div>
        <div class="form-group"><label>Minutes after shift end that check-out stays open</label>
            <input type="number" min="0" max="1440" name="checkout_late_minutes" class="form-control" value="<?= (int) $s['checkout_late_minutes']; ?>"></div>
        <div class="form-group"><label>Maximum GPS error accepted (metres)</label>
            <input type="number" min="10" max="1000" name="max_accuracy_m" class="form-control" value="<?= (int) $s['max_accuracy_m']; ?>"></div>
        <div class="checkbox checkbox-primary"><input type="checkbox" id="dup" name="allow_duplicate" value="1" <?= $s['allow_duplicate'] ? 'checked' : ''; ?>>
            <label for="dup">Allow more than one verified check-in / check-out per employee per day</label></div>
        <button class="btn btn-primary" onclick="if (document.getElementById('applyall').checked) { return confirm('Replace the working hours of ALL employees with these? This overwrites individual hours.'); }">Save</button>
        <?= form_close(); ?>
    </div></div>
</div></div></div></div>
<?php init_tail(); ?>
</body></html>
