<?php defined('BASEPATH') or exit('No direct script access allowed'); // Expects: $rows ?>
<div class="table-responsive">
<table class="table table-striped">
    <thead><tr><th>Employee</th><th>Date</th><th>Check-in</th><th>Location</th><th>Verification</th><th>Image</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) { ?>
        <tr>
            <td><?= html_escape($r['full_name']); ?> <small class="text-muted"><?= html_escape($r['emp_code']); ?></small></td>
            <td><?= html_escape($r['att_date']); ?></td>
            <td><?= html_escape(date('H:i:s', strtotime($r['timestamp']))); ?></td>
            <td><?= html_escape($r['workplace_name']); ?>
                <?php if ($r['distance_m'] !== null) { ?><small class="text-muted">(<?= (int) $r['distance_m']; ?> m away)</small><?php } ?></td>
            <td>
                <?php if ($r['verification_status'] === 'verified') { ?>
                    <span class="label label-<?= $r['is_late'] ? 'warning' : 'success'; ?>"><?= $r['is_late'] ? 'Late' : 'Verified'; ?></span>
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
        <tr><td colspan="6" class="text-center text-muted">No records found.</td></tr>
    <?php } ?>
    </tbody>
</table>
</div>
