<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <h4 style="color:#12507F;font-weight:600">Staff Audit Log</h4>
    <div class="alert alert-info" style="font-size:12px">Immutable record of classification, lifecycle, approval, template and backfill events. Bank fields are never stored here.</div>
    <div class="table-responsive"><table class="table table-bordered" style="font-size:12px">
      <thead><tr><th>When</th><th>Event</th><th>Staff</th><th>Actor</th><th>Message</th></tr></thead>
      <tbody>
      <?php if(empty($rows)): ?><tr><td colspan="5" class="text-muted">No events.</td></tr><?php else: foreach($rows as $r): ?>
        <tr><td class="text-muted"><?php echo html_escape($r->datecreated); ?></td>
        <td><span class="label label-default"><?php echo html_escape($r->event_type); ?></span></td>
        <td><?php echo $r->staff_id?'#'.(int)$r->staff_id:'—'; ?></td>
        <td>#<?php echo (int)$r->actor_id; ?></td>
        <td><?php echo html_escape($r->message); ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
