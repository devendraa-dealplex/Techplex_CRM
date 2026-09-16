<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <h4 style="color:#12507F;font-weight:600;margin:0">Commission Statements</h4>
      <?php if ($can_compute): ?>
        <?php
          /*
           * This posted straight to the legacy generate() route, which created
           * statements from one crude query and bypassed the source policy,
           * partial collections, tax apportionment and duplicate-payment
           * protection. It now opens the preview for the period instead, so
           * nothing is written until somebody has looked at what would be.
           */
        ?>
        <?php echo form_open(admin_url('payplex_commission/commission/preview'), ['class'=>'form-inline', 'method'=>'get']); ?>
          <input class="form-control input-sm" name="period" placeholder="YYYY-MM" value="<?php echo date('Y-m'); ?>">
          <button class="btn btn-primary btn-sm" type="submit">Review &amp; generate&hellip;</button>
        <?php echo form_close(); ?>
      <?php endif; ?>
    </div>
    <p class="text-muted">Approved statements are immutable. Corrections create a new superseding statement.</p>
    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Staff</th><th>Period</th><th>Gross</th><th>Clawback</th><th>Net</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($statements)): ?><tr><td colspan="7" style="text-align:center;color:#667085;padding:22px">No statements yet.</td></tr>
        <?php else: foreach ($statements as $s): ?>
          <tr>
            <td><?php echo $s->staff_id ? get_staff_full_name($s->staff_id) : '—'; ?></td>
            <td><?php echo html_escape($s->period); ?></td>
            <td><?php echo app_format_money($s->gross_amount, $s->currency); ?></td>
            <td><?php echo app_format_money($s->clawback_amount, $s->currency); ?></td>
            <td><b><?php echo app_format_money($s->net_amount, $s->currency); ?></b></td>
            <td><?php echo html_escape(str_replace('_',' ',$s->status)); ?></td>
            <td><a class="btn btn-xs btn-default" href="<?php echo admin_url('payplex_commission/commission/view/'.$s->id); ?>">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
