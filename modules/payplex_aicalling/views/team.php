<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <h4 class="pp-title">AI Calling — Team Performance</h4>
    <div class="table-responsive">
      <table class="table pp-table">
        <thead><tr><th>Staff</th><th>Total calls</th><th>Completed</th><th>Failed</th><th>Answer %</th><th>Cost</th></tr></thead>
        <tbody>
        <?php if (empty($byStaff)): ?>
          <tr><td colspan="6" class="pp-empty">No call data yet.</td></tr>
        <?php else: foreach ($byStaff as $s):
          $total=(int)$s->total; $comp=(int)$s->completed; $pct=$total? round($comp*100/$total):0; ?>
          <tr>
            <td><?php echo $s->staff_id ? get_staff_full_name($s->staff_id) : '—'; ?></td>
            <td><?php echo $total; ?></td>
            <td><?php echo $comp; ?></td>
            <td><?php echo (int)$s->failed; ?></td>
            <td>
              <div style="display:flex;align-items:center;gap:8px">
                <span><?php echo $pct; ?>%</span>
                <span class="bar" style="flex:1;height:7px;background:#EEF2F7;border-radius:20px;overflow:hidden"><i style="display:block;height:100%;width:<?php echo $pct; ?>%;background:#16A97E"></i></span>
              </div>
            </td>
            <td><?php echo app_format_money((float)$s->cost, get_option('payplex_aicalling_currency') ?: 'USD'); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
