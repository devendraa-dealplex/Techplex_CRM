<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <h4 style="color:#12507F;font-weight:600;margin:0">AI Call Cost <small>(last 30 days with activity)</small></h4>
      <a class="btn btn-default btn-sm" href="<?php echo admin_url('payplex_reports/reports/call_cost?' . $export_query); ?>">Export CSV</a>
    </div>
    <?php $this->load->view('payplex_reports/_meta', ['meta' => $meta, 'scoped_to_self' => $scoped_to_self, 'has_calls' => $has_calls]); ?>
    <div class="table-responsive"><table class="table">
      <thead><tr><th>Date</th><th>Calls</th><th>Completed</th><th>Cost</th></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="4" style="text-align:center;color:#667085;padding:20px">No calls yet.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr><td><?php echo html_escape($r['d']); ?></td><td><?php echo (int) $r['calls']; ?></td>
        <td><?php echo (int) $r['completed']; ?></td>
        <td><?php echo app_format_money((float) $r['cost'], $currency); ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
