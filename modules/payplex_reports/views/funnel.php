<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-10 col-md-offset-1">
  <div class="panel_s"><div class="panel-body">
    <h4 style="color:#12507F;font-weight:600"><?php echo html_escape($title); ?></h4>
    <?php $this->load->view('payplex_reports/_meta', ['meta' => $meta, 'conversion' => $conversion, 'rate' => $rate]); ?>
    <?php foreach ($stages as $s): ?>
      <?php $w = $s['pct_of_top'] === null ? 0 : max(2, $s['pct_of_top']); ?>
      <div style="margin-bottom:14px">
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span><b><?php echo html_escape($s['label']); ?></b> &mdash; <?php echo (int) $s['count']; ?></span>
          <span style="color:#667085">
            <?php echo html_escape(Payplex_report_calc::pctLabel($s['pct_of_top'])); ?> of top &middot;
            <?php echo html_escape(Payplex_report_calc::pctLabel($s['pct_of_prev'])); ?> of prev
          </span>
        </div>
        <div style="height:16px;background:#EEF2F7;border-radius:20px;overflow:hidden;margin-top:4px">
          <i style="display:block;height:100%;width:<?php echo $w; ?>%;background:#1E6FB8"></i>
        </div>
      </div>
    <?php endforeach; ?>
    <p class="text-muted">Lost leads: <b><?php echo (int) $lost; ?></b></p>
    <?php if ($converted_aside !== null): ?>
      <p class="text-muted">Leads that converted during this period:
        <b><?php echo (int) $converted_aside; ?></b>
        &mdash; shown separately because they are not the same set of leads as the bars
        above; see the note.</p>
    <?php endif; ?>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
