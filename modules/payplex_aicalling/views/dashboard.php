<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <div class="pp-dash-head">
              <h4 class="pp-title"><i class="fa fa-phone"></i> AI Calling</h4>
              <div>
                <?php if (!$enabled): ?>
                  <span class="label label-warning">Integration disabled</span>
                <?php endif; ?>
                <button class="btn btn-default btn-sm" id="pp-health-btn">Check health</button>
              </div>
            </div>

            <?php foreach ($budget_warnings as $w): ?>
              <div class="alert alert-warning" style="font-size:13px"><i class="fa fa-exclamation-triangle"></i> <?php echo html_escape($w); ?></div>
            <?php endforeach; ?>

            <div class="row pp-kpis">
              <div class="col-md-3 col-sm-6">
                <div class="pp-kpi"><div class="pp-kpi-l">Sonivo status</div>
                  <div class="pp-kpi-v">
                    <?php if ($health && $health->sonivo_reachable): ?>
                      <span class="pp-badge pp-ok">● Healthy</span>
                    <?php else: ?>
                      <span class="pp-badge pp-dan">● Unknown</span>
                    <?php endif; ?>
                  </div>
                  <div class="pp-kpi-s">last check: <?php echo $health ? _dt($health->checked_at) : '—'; ?></div>
                </div>
              </div>
              <div class="col-md-3 col-sm-6">
                <div class="pp-kpi"><div class="pp-kpi-l">Balance</div>
                  <div class="pp-kpi-v"><?php echo $health && $health->balance_amount !== null ? app_format_money($health->balance_amount, $health->balance_currency) : '—'; ?></div>
                  <div class="pp-kpi-s">provider credit<?php echo $balance_inr !== null ? ' &mdash; &asymp; &#8377;' . number_format($balance_inr, 2) . ' (converted)' : ''; ?></div></div>
              </div>
              <div class="col-md-3 col-sm-6">
                <div class="pp-kpi"><div class="pp-kpi-l">Your recent calls</div>
                  <div class="pp-kpi-v"><?php echo count($recent); ?></div>
                  <div class="pp-kpi-s">last 20 shown</div></div>
              </div>
              <div class="col-md-3 col-sm-6">
                <div class="pp-kpi"><div class="pp-kpi-l">Scope</div>
                  <div class="pp-kpi-v"><?php echo $can_view_all ? 'All' : 'Own'; ?></div>
                  <div class="pp-kpi-s">records visible</div></div>
              </div>
            </div>

            <h5 class="pp-sub">Recent calls</h5>
            <div class="table-responsive">
              <table class="table pp-table">
                <thead><tr>
                  <th>When</th><th>Lead</th><th>Status</th><th>Disposition</th><th>Duration</th><th>Cost</th><th></th>
                </tr></thead>
                <tbody>
                <?php if (empty($recent)): ?>
                  <tr><td colspan="7" class="pp-empty">No calls yet. Open a lead and use “Call Now” to place your first AI call.</td></tr>
                <?php else: foreach ($recent as $c): ?>
                  <tr>
                    <td><?php echo _dt($c->created_at); ?></td>
                    <td><?php echo $c->crm_lead_id ? '#' . $c->crm_lead_id : '—'; ?></td>
                    <td><span class="pp-badge pp-<?php echo html_escape($c->status); ?>"><?php echo html_escape($c->status); ?></span></td>
                    <td><?php echo html_escape($c->disposition ?: '—'); ?></td>
                    <td><?php echo $c->duration_sec ? gmdate('i:s', $c->duration_sec) : '—'; ?></td>
                    <td><?php echo $c->cost !== null ? app_format_money($c->cost, $c->currency) : '—'; ?></td>
                    <td>
                      <a class="btn btn-xs btn-default" href="<?php echo admin_url('payplex_aicalling/aicalling/call_detail/'.$c->id); ?>">Details</a>
                      <?php if ($c->recording_available && (is_admin() || staff_can('recording_access','payplex_aicalling'))): ?>
                        <a class="btn btn-xs btn-default" href="<?php echo admin_url('payplex_aicalling/aicalling/recording/'.$c->id); ?>">▶</a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
<script>var PP_HEALTH_URL = "<?php echo admin_url('payplex_aicalling/aicalling/health_check'); ?>";</script>
</body></html>
