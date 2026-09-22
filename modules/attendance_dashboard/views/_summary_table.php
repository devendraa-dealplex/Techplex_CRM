<?php defined('BASEPATH') or exit('No direct script access allowed'); // Expects: $rows (name, expected, present, late), $label ?>
<div class="table-responsive">
<table class="table table-condensed">
    <thead><tr><th><?= $label; ?></th><th>Present / Expected</th><th>Late</th><th>Hours</th><th style="width:26%">Attendance</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) { $pct = $r['expected'] ? round($r['present'] / $r['expected'] * 100) : 0; ?>
        <tr>
            <td><?= html_escape($r['name']); ?></td>
            <td><?= (int) $r['present']; ?> / <?= (int) $r['expected']; ?></td>
            <td><?= (int) $r['late']; ?></td>
            <td><?= floor($r['minutes'] / 60) . 'h ' . ($r['minutes'] % 60) . 'm'; ?>
                <?php if ($r['missing']) { ?><br><small class="text-danger"><?= (int) $r['missing']; ?> no check-out</small><?php } ?></td>
            <td><div class="progress" style="margin:0"><div class="progress-bar progress-bar-<?= $pct >= 90 ? 'success' : ($pct >= 70 ? 'warning' : 'danger'); ?>" style="width:<?= $pct; ?>%"><?= $pct; ?>%</div></div></td>
        </tr>
    <?php } if (!$rows) { ?><tr><td colspan="5" class="text-center text-muted">No data.</td></tr><?php } ?>
    </tbody>
</table>
</div>
