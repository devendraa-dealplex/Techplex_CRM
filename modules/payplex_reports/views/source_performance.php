<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <h4 style="color:#12507F;font-weight:600;margin:0"><?php echo html_escape($title); ?></h4>
      <a class="btn btn-default btn-sm" href="<?php echo admin_url('payplex_reports/reports/source_performance?' . $export_query); ?>">Export CSV</a>
    </div>
    <?php $this->load->view('payplex_reports/_meta', ['meta' => $meta, 'conversion' => $conversion, 'rate' => $rate]); ?>
    <div class="table-responsive"><table class="table">
      <thead><tr><th>Source</th><th>Leads</th><th>Converted</th><th>Conv %</th></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="4" style="text-align:center;color:#667085;padding:20px">No leads yet.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?php echo html_escape($r['source']); ?></td>
          <td><?php echo (int) $r['leads']; ?></td>
          <td><?php echo $r['converted'] === null ? '<span class="text-muted">—</span>' : (int) $r['converted']; ?></td>
          <td><?php echo html_escape(Payplex_report_calc::pctLabel($r['conv_pct'])); ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
