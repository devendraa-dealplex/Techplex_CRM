<?php defined('BASEPATH') or exit('No direct script access allowed'); init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-6">
    <h4 class="tw-font-semibold tw-mb-3">Attendance Settings</h4>
    <div class="panel_s"><div class="panel-body">
        <?= form_open(admin_url(ATT_MODULE . '/settings')); ?>
        <div class="form-group"><label>Minutes before shift start that check-in opens</label>
            <input type="number" min="0" max="240" name="early_minutes" class="form-control" value="<?= (int) $s['early_minutes']; ?>"></div>
        <div class="form-group"><label>Grace minutes after start before "late"</label>
            <input type="number" min="0" max="120" name="late_grace_minutes" class="form-control" value="<?= (int) $s['late_grace_minutes']; ?>"></div>
        <div class="form-group"><label>Maximum GPS error accepted (metres)</label>
            <input type="number" min="10" max="1000" name="max_accuracy_m" class="form-control" value="<?= (int) $s['max_accuracy_m']; ?>"></div>
        <div class="checkbox checkbox-primary"><input type="checkbox" id="dup" name="allow_duplicate" value="1" <?= $s['allow_duplicate'] ? 'checked' : ''; ?>>
            <label for="dup">Allow more than one verified attendance per employee per day</label></div>
        <button class="btn btn-primary">Save</button>
        <?= form_close(); ?>
    </div></div>
</div></div></div></div>
<?php init_tail(); ?>
</body></html>
