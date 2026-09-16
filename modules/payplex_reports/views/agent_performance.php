<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <h4 style="color:#12507F;font-weight:600;margin:0"><?php echo html_escape($title); ?></h4>
      <a class="btn btn-default btn-sm" href="<?php echo admin_url('payplex_reports/reports/agent_performance?' . $export_query); ?>">Export CSV</a>
    </div>
    <?php $this->load->view('payplex_reports/_meta', ['meta' => $meta, 'conversion' => $conversion, 'scoped_to_self' => $scoped_to_self, 'rate' => $rate, 'has_calls' => $has_calls]); ?>
    <div class="table-responsive"><table class="table">
      <thead><tr><th>Agent</th><th>Leads</th><th>Converted</th><th>Conv %</th><th>AI Calls</th><th>Call cost</th></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" style="text-align:center;color:#667085;padding:20px">No agents to show.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?php echo html_escape($r['name']); ?></td>
          <td><?php echo (int) $r['leads']; ?></td>
          <td><?php echo $r['converted'] === null ? '<span class="text-muted">—</span>' : (int) $r['converted']; ?></td>
          <td><?php echo html_escape(Payplex_report_calc::pctLabel($r['conv_pct'])); ?></td>
          <td><?php echo $r['calls'] === null ? '<span class="text-muted">unavailable</span>' : (int) $r['calls']; ?></td>
          <td><?php echo $r['call_cost'] === null ? '<span class="text-muted">unavailable</span>' : app_format_money((float) $r['call_cost'], $currency); ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
    <p class="text-muted" style="font-size:12px;margin-top:8px">
      Call cost is spend. It is not netted against revenue or commission anywhere on this page.
    </p>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
