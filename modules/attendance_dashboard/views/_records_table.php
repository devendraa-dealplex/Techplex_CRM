<?php defined('BASEPATH') or exit('No direct script access allowed'); // Expects: $rows ?>
<div class="table-responsive">
<table class="table table-striped">
    <thead><tr><th>Employee</th><th>Date</th><th>Type</th><th>Time</th><th>Location</th><th>Verification</th><th>Image</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) { ?>
        <tr>
            <td><?= html_escape($r['full_name']); ?> <small class="text-muted"><?= html_escape($r['emp_code']); ?></small></td>
            <td><?= html_escape($r['att_date']); ?></td>
            <td><?= $r['att_type'] === 'out' ? 'Check-out' : 'Check-in'; ?></td>
            <td><?= html_escape(date('H:i:s', strtotime($r['timestamp']))); ?>
                <?php if ($r['att_type'] === 'out' && $r['verification_status'] === 'verified' && $r['in_ts']) { $m = max(0, (int) round((strtotime($r['timestamp']) - strtotime($r['in_ts'])) / 60)); ?>
                    <div class="text-muted"><small>Worked <?= floor($m / 60) . 'h ' . ($m % 60) . 'm'; ?></small></div>
                <?php } ?></td>
            <td><?= html_escape($r['workplace_name']); ?>
                <?php if ($r['distance_m'] !== null) { ?><small class="text-muted">(<?= (int) $r['distance_m']; ?> m away)</small><?php } ?></td>
            <td>
                <?php if ($r['verification_status'] === 'verified') { ?>
                    <?php $flag = $r['att_type'] === 'out' ? ($r['is_early_out'] ? 'Left early' : '') : ($r['is_late'] ? 'Late' : ''); ?>
                    <span class="label label-<?= $flag ? 'warning' : 'success'; ?>"><?= $flag ?: 'Verified'; ?></span>
                <?php } else { ?>
                    <span class="label label-danger">Failed</span>
                <?php } ?>
                <div class="text-muted"><small><?= html_escape($r['verification_reason']); ?></small></div>
            </td>
            <td>
                <?php if ($r['captured_image']) { $u = admin_url(ATT_MODULE . '/image/record/' . $r['id']); ?>
                    <a href="<?= $u; ?>" target="_blank"><img src="<?= $u; ?>" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:6px"></a>
                <?php } else { echo '-'; } ?>
            </td>
        </tr>
    <?php } if (!$rows) { ?>
        <tr><td colspan="7" class="text-center text-muted">No records found.</td></tr>
    <?php } ?>
    </tbody>
</table>
</div>
