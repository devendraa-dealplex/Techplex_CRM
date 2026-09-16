<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $cv = isset($companyView) ? $companyView : ''; $s = $summary; ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Council Votes</h4>
        <div>
          <?php if ($canManage): ?><a href="<?php echo admin_url('payplex_ai_agents/councilvote/create'); ?>" class="btn btn-info btn-sm"><i class="fa fa-plus"></i> New Motion</a><?php endif; ?>
        </div>
      </div>
      <div class="alert alert-info" style="font-size:12px">The council decides a <strong>motion</strong> by a weighted vote under an explicit quorum and threshold rule. Every dissent is preserved. <strong>A vote is only a recommendation</strong> — a binding or externally consequential motion is flagged “needs human” and, even if it passes, must be ratified by the Chairman (never the proposer) and routed through a <a href="<?php echo admin_url('payplex_ai_agents/command/create'); ?>">Decision Packet</a> before anything acts on it.</div>

      <div class="row">
        <?php $tiles = array(
          array('Motions', $s['total'], '#12507F', ''),
          array('Open', $s['open'], '#5bc0de', 'open'),
          array('Ratified', $s['ratified'], '#5cb85c', 'ratified'),
          array('Needs human', $s['needs_human'], '#d9534f', ''),
        ); foreach ($tiles as $tl): ?>
          <div class="col-md-3 col-xs-6" style="margin-bottom:10px">
            <a href="<?php echo admin_url('payplex_ai_agents/councilvote' . ($tl[3] ? '?status=' . $tl[3] : '')); ?>" style="text-decoration:none">
              <div class="panel_s" style="border-top:3px solid <?php echo $tl[2]; ?>"><div class="panel-body" style="text-align:center">
                <div style="font-size:24px;font-weight:700;color:<?php echo $tl[2]; ?>"><?php echo (int) $tl[1]; ?></div>
                <div class="text-muted" style="font-size:11px"><?php echo $tl[0]; ?></div>
              </div></div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <ul class="nav nav-tabs" style="margin-bottom:12px">
          <?php foreach (array(''=>'All','open'=>'Open','closed'=>'Closed','ratified'=>'Ratified','vetoed'=>'Vetoed') as $k=>$label): ?>
            <li class="<?php echo $status===$k?'active':''; ?>"><a href="<?php echo admin_url('payplex_ai_agents/councilvote?status='.$k.'&company='.urlencode($cv)); ?>"><?php echo $label; ?></a></li>
          <?php endforeach; ?>
        </ul>
        <?php if (!empty($companies)): ?>
        <form method="get" action="<?php echo admin_url('payplex_ai_agents/councilvote'); ?>" style="margin-bottom:10px">
          <input type="hidden" name="status" value="<?php echo html_escape($status); ?>">
          <select class="form-control input-sm" name="company" onchange="this.form.submit()" style="display:inline-block;width:auto">
            <option value="">Group (all)</option>
            <?php foreach ($companies as $c): ?><option value="<?php echo html_escape($c->code); ?>" <?php echo $cv===$c->code?'selected':''; ?>><?php echo html_escape($c->name); ?></option><?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </div>

      <div class="table-responsive"><table class="table table-bordered" style="font-size:12px">
        <thead><tr><th>#</th><th>Motion</th><th>Type</th><th>Company</th><th>Rule</th><th>Status</th><th>Result (live)</th><th>Flags</th><th>Updated</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($rows)): ?><tr><td colspan="10" class="text-muted">No motions.</td></tr>
        <?php else: foreach ($rows as $r): $m=$r['m']; $o=$r['outcome'];
          $sc = Payplex_agent_vote::resultClass($m->status);
          $res = $m->status==='ratified' ? 'ratified' : ($m->status==='vetoed' ? 'vetoed' : (isset($o['result'])?$o['result']:'—'));
          $rc = Payplex_agent_vote::resultClass($res); ?>
          <tr>
            <td>#<?php echo (int) $m->id; ?></td>
            <td><strong><?php echo html_escape($m->title); ?></strong></td>
            <td><span class="label label-default"><?php echo html_escape($m->motion_type); ?></span></td>
            <td><?php echo $m->company ? html_escape($m->company) : '<span class="text-muted">group</span>'; ?></td>
            <td><span class="text-muted"><?php echo html_escape(Payplex_agent_vote::ruleLabel($m->threshold_rule)); ?></span></td>
            <td><span class="label label-<?php echo $sc; ?>"><?php echo html_escape($m->status); ?></span></td>
            <td><span class="label label-<?php echo $rc; ?>"><?php echo html_escape($res); ?></span>
                <span class="text-muted">(<?php echo (float) $o['for_weight']; ?>/<?php echo (float) $o['against_weight']; ?>)</span></td>
            <td><?php echo (int) $m->requires_human === 1 ? '<span class="label label-danger">needs human</span>' : ''; ?></td>
            <td><?php echo html_escape((string) $m->dateupdated); ?></td>
            <td><a class="btn btn-primary btn-xs" href="<?php echo admin_url('payplex_ai_agents/councilvote/view/' . (int) $m->id); ?>">Open</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
