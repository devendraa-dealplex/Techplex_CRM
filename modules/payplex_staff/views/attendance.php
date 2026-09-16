<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php
  $v = $att['verdict']; $req = $att['required']; $w = $att['window']; $p = $att['paired'];
  $badge = array('complete'=>'success','late'=>'warning','early_departure'=>'warning',
                 'short_day'=>'warning','incomplete'=>'info','no_record'=>'default','not_required'=>'default');
  $cls = isset($badge[$v['verdict']]) ? $badge[$v['verdict']] : 'default';
?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-10 col-md-offset-1">
  <div class="panel_s"><div class="panel-body">

    <h4 style="color:#12507F;font-weight:600;margin:0">
      Attendance &mdash; <?php echo html_escape(trim($member->firstname . ' ' . $member->lastname)); ?>
      <small class="text-muted">#<?php echo (int) $att['staff_id']; ?></small>
    </h4>
    <p class="text-muted" style="margin:4px 0 14px"><?php echo html_escape($att['day']); ?></p>

    <div class="alert alert-<?php echo $req['required'] ? 'info' : 'default'; ?>" style="font-size:13px;background:#F7F9FC;border:1px solid #E4E9F0;color:#4A5568">
      <b><?php echo $req['required'] ? 'Attendance is required.' : 'Attendance is not required.'; ?></b>
      <?php echo html_escape($req['reason']); ?>
      <br><small><b>Source:</b> <?php echo html_escape($req['source']); ?> &middot;
      <b>Work category:</b> <?php echo html_escape($att['work_category'] ?: 'not classified'); ?></small>
      <br><small><?php echo html_escape($w['note']); ?></small>
    </div>

    <p>
      <span class="label label-<?php echo $cls; ?>" style="font-size:13px">
        <?php echo html_escape(str_replace('_', ' ', $v['verdict'])); ?>
      </span>
      &nbsp;<b><?php echo number_format($v['minutes'] / 60, 2); ?> h</b> recorded
      <?php if ($v['late_minutes']): ?> &middot; <span class="text-warning"><?php echo (int) $v['late_minutes']; ?> min late</span><?php endif; ?>
      <?php if ($v['early_minutes']): ?> &middot; <span class="text-warning">left <?php echo (int) $v['early_minutes']; ?> min early</span><?php endif; ?>
    </p>
    <?php foreach ($v['notes'] as $n): ?>
      <p class="text-muted" style="font-size:13px;margin:2px 0"><?php echo html_escape($n); ?></p>
    <?php endforeach; ?>

    <?php if ($is_self && $att['day'] === date('Y-m-d')): ?>
      <p style="margin-top:14px">
        <a class="btn btn-primary" href="<?php echo admin_url('payplex_staff/staff/attendance_mark/in'); ?>">Check in</a>
        <a class="btn btn-default" href="<?php echo admin_url('payplex_staff/staff/attendance_mark/out'); ?>">Check out</a>
        <small class="text-muted" style="margin-left:8px">Self-recorded, and logged unverified &mdash;
          a person asserting their own arrival is a claim, not evidence.</small>
      </p>
    <?php endif; ?>

    <h5 style="color:#12507F;font-weight:600;margin-top:22px">Sessions</h5>
    <?php if (empty($p['sessions'])): ?>
      <p class="text-muted">Nothing recorded for this day.</p>
    <?php else: ?>
      <div class="table-responsive"><table class="table">
        <thead><tr><th>In</th><th>Out</th><th>Duration</th></tr></thead>
        <tbody>
        <?php foreach ($p['sessions'] as $s): ?>
          <tr>
            <td><?php echo html_escape($s['in']); ?></td>
            <td><?php echo $s['out'] === null ? '<span class="label label-info">still open</span>' : html_escape($s['out']); ?></td>
            <td><?php echo $s['minutes'] === null ? '<span class="text-muted">&mdash;</span>' : number_format($s['minutes'] / 60, 2) . ' h'; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>

    <?php if (!empty($p['problems'])): ?>
      <h5 style="color:#B37E00;font-weight:600;margin-top:18px">Things this day does not add up to</h5>
      <?php foreach ($p['problems'] as $pr): ?>
        <div class="alert alert-warning" style="font-size:13px">
          <b><?php echo html_escape(str_replace('_', ' ', $pr['code'])); ?></b>
          <?php if (!empty($pr['message'])): ?> &mdash; <?php echo html_escape($pr['message']); ?><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
