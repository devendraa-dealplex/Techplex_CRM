<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $cv = isset($companyView) ? $companyView : ''; ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Agent Performance</h4>
        <div>
          <a href="<?php echo admin_url('payplex_ai_agents/goals'); ?>" class="btn btn-default btn-sm">Goals &amp; OKRs</a>
          <?php if (!empty($companies)): ?>
          <form method="get" action="<?php echo admin_url('payplex_ai_agents/goals/performance'); ?>" style="display:inline-block">
            <select class="form-control input-sm" name="company" onchange="this.form.submit()" style="display:inline-block;width:auto">
              <option value="">Group (all)</option>
              <?php foreach ($companies as $c): ?><option value="<?php echo html_escape($c->code); ?>" <?php echo $cv===$c->code?'selected':''; ?>><?php echo html_escape($c->name); ?></option><?php endforeach; ?>
            </select>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <div class="alert alert-info" style="font-size:12px">Scores are computed live from real activity — decision packets submitted / approved / rejected / returned, council reviews filed, and average confidence — using a deterministic scorecard. An agent with no activity scores 0 and rates “no data”, never a flattering default.</div>

      <div class="table-responsive"><table class="table table-bordered" style="font-size:12px">
        <thead><tr><th>#</th><th>Agent</th><th>Company</th><th>Score</th><th>Rating</th><th>Submitted</th><th>Approved</th><th>Rejected</th><th>Returned</th><th>Reviews</th><th>Avg conf.</th></tr></thead>
        <tbody>
        <?php if (empty($cards)): ?><tr><td colspan="11" class="text-muted">No agents in scope.</td></tr>
        <?php else: $rank=0; foreach ($cards as $c): $rank++; $rc=Payplex_agent_scorecard::ratingClass($c['rating']);
          $s=$c['stats']; ?>
          <tr>
            <td><?php echo $rank; ?></td>
            <td><strong><?php echo html_escape($c['agent_name']); ?></strong></td>
            <td><?php echo $c['company'] ? html_escape($c['company']) : '<span class="text-muted">—</span>'; ?></td>
            <td style="min-width:120px">
              <div class="progress" style="margin:0;height:16px"><div class="progress-bar progress-bar-<?php echo $rc; ?>" style="width:<?php echo (int)$c['score']; ?>%;min-width:2em"><?php echo (int)$c['score']; ?></div></div>
            </td>
            <td><span class="label label-<?php echo $rc; ?>"><?php echo str_replace('_',' ',$c['rating']); ?></span></td>
            <td><?php echo (int)$s['submitted']; ?></td>
            <td><?php echo (int)$s['approved']; ?></td>
            <td><?php echo (int)$s['rejected']; ?></td>
            <td><?php echo (int)$s['returned']; ?></td>
            <td><?php echo (int)$s['reviews']; ?></td>
            <td><?php echo $s['avg_confidence']>0 ? ((int) round($s['avg_confidence']*100).'%') : '—'; ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
      <p class="text-muted" style="font-size:11px">Score = 35% approval quality + 30% contribution (approved + reviews, saturating) + 20% low-rework quality + 15% confidence.</p>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
