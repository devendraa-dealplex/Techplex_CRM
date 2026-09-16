<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
  <div class="row pp-kpis">
    <div class="col-md-3 col-sm-4"><div class="pp-kpi"><div class="pp-kpi-l">My calls</div><div class="pp-kpi-v"><?php echo (int)($stats->total ?? 0); ?></div></div></div>
    <div class="col-md-3 col-sm-4"><div class="pp-kpi"><div class="pp-kpi-l">Completed</div><div class="pp-kpi-v"><?php echo (int)($stats->completed ?? 0); ?></div></div></div>
    <div class="col-md-3 col-sm-4"><div class="pp-kpi"><div class="pp-kpi-l">Scheduled</div><div class="pp-kpi-v"><?php echo (int)($stats->scheduled ?? 0); ?></div></div></div>
  </div>
  <div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
    <h4 class="pp-title">My AI Calls</h4>
    <div class="table-responsive">
      <table class="table pp-table">
        <thead><tr><th>When</th><th>Lead</th><th>Status</th><th>Disposition</th><th>Duration</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($mine)): ?>
          <tr><td colspan="6" class="pp-empty">No calls yet. Open a lead and use “Call Now”.</td></tr>
        <?php else: foreach ($mine as $c): ?>
          <tr>
            <td><?php echo _dt($c->created_at); ?></td>
            <td><?php echo $c->crm_lead_id ? '#'.$c->crm_lead_id : '—'; ?></td>
            <td><span class="pp-badge pp-<?php echo html_escape($c->status); ?>"><?php echo html_escape($c->status); ?></span></td>
            <td><?php echo html_escape($c->disposition ?: '—'); ?></td>
            <td><?php echo $c->duration_sec ? gmdate('i:s',$c->duration_sec) : '—'; ?></td>
            <td>
              <?php if ($c->status==='failed' && (is_admin()||staff_can('retry','payplex_aicalling'))): ?>
                <button class="btn btn-xs btn-default pp-retry" data-id="<?php echo $c->id; ?>">Retry</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div></div></div></div>
</div></div>
<?php init_tail(); ?>
<script>
window.PP_RETRY_URL = "<?php echo admin_url('payplex_aicalling/aicalling/retry_call'); ?>";
window.PP_CSRF = {name:"<?php echo $this->security->get_csrf_token_name(); ?>", hash:"<?php echo $this->security->get_csrf_hash(); ?>"};
</script></body></html>
