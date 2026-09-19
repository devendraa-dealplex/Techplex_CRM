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
      <div class="alert alert-info" style="font-size:12px">Scores are computed live from real decision packets (approved / rejected / returned), council reviews and votes. An agent is scored only after it has at least <strong><?php echo (int) Payplex_agent_scorecard::MIN_OUTCOMES; ?> decided packets</strong>; until then it shows “insufficient data” (or “no data” with no activity) and gets no score or rank, so there is never a flattering default. Votes and reviews add to contribution but are not outcomes. Templates and archived agents are not listed.</div>
      <?php $scored = 0; foreach ($cards as $cc) { if (!Payplex_agent_scorecard::isUnscored($cc['rating'])) { $scored++; } } ?>
      <p class="text-muted" style="font-size:12px"><strong><?php echo $scored; ?></strong> of <?php echo count($cards); ?> agents have enough decided packets to be scored.</p>

      <div class="table-responsive"><table class="table table-bordered" style="font-size:12px">
        <thead><tr><th>#</th><th>Agent</th><th>Company</th><th>Score</th><th>Rating</th><th>Submitted</th><th>Approved</th><th>Rejected</th><th>Returned</th><th>Reviews</th><th>Avg conf.</th></tr></thead>
        <tbody>
        <?php if (empty($cards)): ?><tr><td colspan="11" class="text-muted">No agents in scope.</td></tr>
        <?php else: $rank=0; foreach ($cards as $c): $rc=Payplex_agent_scorecard::ratingClass($c['rating']);
          $ranked = !Payplex_agent_scorecard::isUnscored($c['rating']);
          if ($ranked) { $rank++; }
          $s=$c['stats']; ?>
          <tr>
            <td><?php echo $ranked ? $rank : '<span class="text-muted">—</span>'; ?></td>
            <td><strong><?php echo html_escape($c['agent_name']); ?></strong></td>
            <td><?php echo $c['company'] ? html_escape($c['company']) : '<span class="text-muted">—</span>'; ?></td>
            <td style="min-width:120px">
              <?php if ($ranked): ?>
              <div class="progress" style="margin:0;height:16px"><div class="progress-bar progress-bar-<?php echo $rc; ?>" style="width:<?php echo (int)$c['score']; ?>%;min-width:2em"><?php echo (int)$c['score']; ?></div></div>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
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
      <p class="text-muted" style="font-size:11px">Score = 35% approval quality + 30% contribution (approved + reviews, saturating) + 20% low-rework quality + 15% confidence. Confidence is the agent's own reported figure; when there is none it is left out and the other weights are rescaled.</p>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
