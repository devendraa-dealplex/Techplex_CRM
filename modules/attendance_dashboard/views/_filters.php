<?php defined('BASEPATH') or exit('No direct script access allowed');
// Expects: $f (filters), $workplaces; optional $showStatus, $showText
$showText   = $showText ?? true;
$showStatus = $showStatus ?? false;
$showLocation = $showLocation ?? true;
$statusOptions = $statusOptions ?? ['' => 'All', 'verified' => 'Verified', 'late' => 'Late', 'failed' => 'Failed'];
?>
<form method="get" class="panel_s">
    <div class="panel-body tw-flex tw-flex-wrap tw-gap-3 tw-items-end">
        <div><label class="control-label">From</label><input type="date" name="from" class="form-control" value="<?= html_escape($f['from']); ?>"></div>
        <div><label class="control-label">To</label><input type="date" name="to" class="form-control" value="<?= html_escape($f['to']); ?>"></div>
        <?php if ($showText) { ?>
        <div><label class="control-label">Employee</label><input type="text" name="q" class="form-control" placeholder="Name or ID" value="<?= html_escape($f['q']); ?>"></div>
        <div><label class="control-label">Role</label><input type="text" name="role" class="form-control" value="<?= html_escape($f['role']); ?>"></div>
        <?php } ?>
        <?php if ($showLocation) { ?>
        <div><label class="control-label">Location</label>
            <select name="workplace_id" class="form-control">
                <option value="">All</option>
                <?php foreach ($workplaces as $w) { ?>
                <option value="<?= (int) $w['id']; ?>" <?= $f['workplace_id'] == $w['id'] ? 'selected' : ''; ?>><?= html_escape($w['name']); ?></option>
                <?php } ?>
            </select>
        </div>
        <?php } ?>
        <?php if ($showStatus) { ?>
        <div><label class="control-label">Status</label>
            <select name="status" class="form-control">
                <?php foreach ($statusOptions as $k => $v) { ?>
                <option value="<?= $k; ?>" <?= $f['status'] === $k ? 'selected' : ''; ?>><?= $v; ?></option>
                <?php } ?>
            </select>
        </div>
        <?php } ?>
        <button class="btn btn-primary">Apply</button>
    </div>
</form>
