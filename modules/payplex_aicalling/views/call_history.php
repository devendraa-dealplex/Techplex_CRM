<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <h4 class="pp-title">Call History</h4>
    <div class="pp-filters">
      <a href="<?php echo admin_url('payplex_aicalling/aicalling/history'); ?>" class="pp-chip">All</a>
      <a href="<?php echo admin_url('payplex_aicalling/aicalling/history?status=completed'); ?>" class="pp-chip">Completed</a>
      <a href="<?php echo admin_url('payplex_aicalling/aicalling/history?status=failed'); ?>" class="pp-chip">Failed</a>
      <a href="<?php echo admin_url('payplex_aicalling/aicalling/history?status=scheduled'); ?>" class="pp-chip">Scheduled</a>
    </div>
    <div class="table-responsive">
      <table class="table pp-table">
        <thead><tr><th>When</th><th>Lead</th><th>Dir</th><th>Status</th><th>Disposition</th><th>Dur</th><th>Cost</th><th>Sentiment</th></tr></thead>
        <tbody>
        <?php if (empty($calls)): ?>
          <tr><td colspan="8" class="pp-empty">No calls match this filter.</td></tr>
        <?php else: foreach ($calls as $c): ?>
          <tr>
            <td><?php echo _dt($c->created_at); ?></td>
            <td><?php echo $c->crm_lead_id ? '#'.$c->crm_lead_id : '—'; ?></td>
            <td><?php echo html_escape($c->direction); ?></td>
            <td><span class="pp-badge pp-<?php echo html_escape($c->status); ?>"><?php echo html_escape($c->status); ?></span></td>
            <td><?php echo html_escape($c->disposition ?: '—'); ?></td>
            <td><?php echo $c->duration_sec ? gmdate('i:s',$c->duration_sec) : '—'; ?></td>
            <td><?php echo $c->cost !== null ? app_format_money($c->cost,$c->currency) : '—'; ?></td>
            <td><?php echo html_escape($c->sentiment ?: '—'); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
