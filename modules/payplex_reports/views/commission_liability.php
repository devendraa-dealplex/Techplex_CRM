<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-10 col-md-offset-1">
  <div class="panel_s"><div class="panel-body">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <h4 style="color:#12507F;font-weight:600;margin:0"><?php echo html_escape($title); ?></h4>
      <a class="btn btn-default btn-sm" href="<?php echo admin_url('payplex_reports/reports/commission_liability?' . $export_query); ?>">Export CSV</a>
    </div>
    <?php $this->load->view('payplex_reports/_meta', ['meta' => $meta]); ?>

    <?php if (empty($has_module)): ?>
      <p class="text-muted">Commission module not installed — no statements to report.</p>
    <?php else: ?>

      <div class="row" style="margin-bottom:6px">
        <div class="col-sm-4">
          <div style="border:1px solid #E4E9F0;border-radius:6px;padding:12px">
            <div style="font-size:12px;color:#667085">Commission payable (still owed)</div>
            <div style="font-size:22px;font-weight:600;color:#12507F">
              <?php echo app_format_money($summary['liability'], $currency); ?>
            </div>
            <div style="font-size:11px;color:#667085"><?php echo (int) $summary['buckets']['payable']['count']; ?> statement(s)</div>
          </div>
        </div>
        <div class="col-sm-4">
          <div style="border:1px solid #E4E9F0;border-radius:6px;padding:12px">
            <div style="font-size:12px;color:#667085">Commission paid (already disbursed)</div>
            <div style="font-size:22px;font-weight:600;color:#2F855A">
              <?php echo app_format_money($summary['paid'], $currency); ?>
            </div>
            <div style="font-size:11px;color:#667085"><?php echo (int) $summary['buckets']['paid']['count']; ?> statement(s)</div>
          </div>
        </div>
        <div class="col-sm-4">
          <div style="border:1px solid #E4E9F0;border-radius:6px;padding:12px">
            <div style="font-size:12px;color:#667085">Clawed back (reversed)</div>
            <div style="font-size:22px;font-weight:600;color:#B7791F">
              <?php echo app_format_money($summary['clawed_back'], $currency); ?>
            </div>
            <div style="font-size:11px;color:#667085"><?php echo (int) $summary['buckets']['clawed_back']['count']; ?> statement(s)</div>
          </div>
        </div>
      </div>

      <p class="text-muted" style="font-size:12px">
        These three figures are deliberately <b>not</b> added together. Only the first is a liability:
        commission already paid has left the business, and clawed-back commission was reversed.
      </p>

      <?php if (!empty($summary['has_unrecognised'])): ?>
        <div class="alert alert-warning" style="font-size:13px">
          <b>Some statements have a status this report does not recognise</b>
          (<?php echo html_escape(implode(', ', array_unique($summary['buckets']['other']['statuses']))); ?>).
          They are listed below but excluded from every total, rather than being guessed into one.
        </div>
      <?php endif; ?>

      <div class="table-responsive"><table class="table">
        <thead><tr><th>Category</th><th>Statuses included</th><th>Count</th><th>Amount</th></tr></thead>
        <tbody>
        <?php $any = false; foreach ($summary['buckets'] as $key => $b): ?>
          <?php if ($b['count'] === 0) { continue; } $any = true; ?>
          <tr>
            <td><?php echo html_escape($labels[$key]); ?></td>
            <td><small><?php echo html_escape(implode(', ', array_unique($b['statuses']))); ?></small></td>
            <td><?php echo (int) $b['count']; ?></td>
            <td><?php echo app_format_money((float) $b['amount'], $currency); ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$any): ?>
          <tr><td colspan="4" style="text-align:center;color:#667085;padding:20px">No statements yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table></div>

      <h5 class="bold" style="margin-top:18px">What each money figure means</h5>
      <table class="table table-condensed" style="font-size:12px">
        <tbody>
        <?php foreach ($money_definitions as $k => $desc): ?>
          <tr>
            <td style="width:190px"><b><?php echo html_escape(str_replace('_', ' ', $k)); ?></b></td>
            <td><?php echo html_escape($desc); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
