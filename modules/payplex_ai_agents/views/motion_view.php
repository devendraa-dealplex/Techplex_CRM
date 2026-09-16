<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head();
$nm = function ($id) use ($names) { $id=(int)$id; return isset($names[$id]) ? $names[$id] : ('Agent #' . $id); };
$o  = $outcome;
$sc = Payplex_agent_vote::resultClass($m->status);
$rc = Payplex_agent_vote::resultClass(isset($o['result']) ? $o['result'] : ''); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-9">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600"><?php echo html_escape($m->title); ?></h4>
        <a href="<?php echo admin_url('payplex_ai_agents/councilvote'); ?>" class="btn btn-default btn-sm">Back</a>
      </div>

      <div class="panel_s"><div class="panel-body">
        <p>
          <span class="label label-<?php echo $sc; ?>"><?php echo html_escape($m->status); ?></span>
          <span class="label label-default"><?php echo html_escape($m->motion_type); ?></span>
          <span class="label label-default"><?php echo html_escape(Payplex_agent_vote::ruleLabel($m->threshold_rule)); ?></span>
          <span class="label label-default">quorum <?php echo (float) $m->quorum; ?></span>
          <span class="label label-default"><?php echo $m->company ? html_escape($m->company) : 'group-level'; ?></span>
          <?php echo (int) $m->requires_human === 1 ? '<span class="label label-danger">needs human</span>' : ''; ?>
        </p>
        <?php if (!empty($m->description)): ?><div style="white-space:pre-wrap;font-size:13px;margin-bottom:8px"><?php echo nl2br(html_escape($m->description)); ?></div><?php endif; ?>
        <?php if (!empty($m->external_flags)): ?>
          <div class="text-danger" style="font-size:11px"><i class="fa fa-exclamation-triangle"></i> external-action phrasing: <?php echo html_escape($m->external_flags); ?></div>
        <?php endif; ?>
        <?php if ((int) $m->requires_human === 1): ?>
          <div class="alert alert-danger" style="font-size:12px;margin-top:8px">This motion authorises a real or binding action. Passing the vote does <strong>not</strong> execute anything — the Chairman must ratify it (and cannot be the person who created the motion), and any real action goes through a <a href="<?php echo admin_url('payplex_ai_agents/command/create'); ?>">Decision Packet</a>.</div>
        <?php endif; ?>
      </div></div>

      <!-- Live outcome -->
      <div class="panel_s"><div class="panel-body">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <h6 style="font-weight:600;margin:0">Live tally</h6>
          <span class="label label-<?php echo $rc; ?>" style="font-size:12px"><?php echo html_escape(isset($o['result'])?$o['result']:'—'); ?><?php echo (isset($o['quorum_met']) && !$o['quorum_met']) ? ' · no quorum' : ''; ?></span>
        </div>
        <div class="row" style="margin-top:8px;text-align:center">
          <div class="col-xs-3"><div style="font-size:20px;font-weight:700;color:#5cb85c"><?php echo (float) $o['for_weight']; ?></div><div class="text-muted" style="font-size:11px">For (<?php echo (int)$o['for_count']; ?>)</div></div>
          <div class="col-xs-3"><div style="font-size:20px;font-weight:700;color:#d9534f"><?php echo (float) $o['against_weight']; ?></div><div class="text-muted" style="font-size:11px">Against (<?php echo (int)$o['against_count']; ?>)</div></div>
          <div class="col-xs-3"><div style="font-size:20px;font-weight:700;color:#777"><?php echo (int)$o['abstain_count']; ?></div><div class="text-muted" style="font-size:11px">Abstain</div></div>
          <div class="col-xs-3"><div style="font-size:20px;font-weight:700;color:#12507F"><?php echo (int)$o['cast']; ?>/<?php echo (int)$o['eligible']; ?></div><div class="text-muted" style="font-size:11px">Cast / eligible</div></div>
        </div>
        <p class="text-muted" style="font-size:12px;margin-top:8px"><?php echo html_escape(Payplex_agent_vote::summarize($o)); ?></p>
        <?php if (!empty($o['dissent'])): ?>
          <div style="margin-top:6px"><strong style="font-size:12px">Dissent (preserved):</strong>
          <?php foreach ($o['dissent'] as $d): ?>
            <div style="border-left:3px solid #d9534f;padding:4px 8px;margin:4px 0;font-size:12px"><strong><?php echo html_escape($nm($d['agent_id'])); ?></strong> (w<?php echo (float)$d['weight']; ?>): <?php echo html_escape($d['rationale'] !== '' ? $d['rationale'] : '—'); ?></div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div></div>

      <!-- Ballots -->
      <div class="panel_s"><div class="panel-body">
        <h6 style="font-weight:600;margin-top:0">Ballots</h6>
        <table class="table table-bordered" style="font-size:12px">
          <thead><tr><th>Voter</th><th>Vote</th><th>Weight</th><th>Rationale</th><th>Updated</th></tr></thead>
          <tbody>
          <?php if (empty($votes)): ?><tr><td colspan="5" class="text-muted">No ballots yet.</td></tr>
          <?php else: foreach ($votes as $v): ?>
            <tr>
              <td><?php echo html_escape($nm($v->voter_agent_id)); ?></td>
              <td><span class="label label-<?php echo Payplex_agent_vote::voteClass($v->vote); ?>"><?php echo html_escape($v->vote); ?></span></td>
              <td><?php echo (float) $v->weight; ?></td>
              <td><?php echo html_escape((string) $v->rationale); ?></td>
              <td class="text-muted"><?php echo html_escape((string) $v->dateupdated); ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div></div>

      <?php if ($canManage && $m->status === 'open'): ?>
      <div class="panel_s"><div class="panel-body">
        <h6 style="font-weight:600;margin-top:0">Cast / change a ballot</h6>
        <?php echo form_open(admin_url('payplex_ai_agents/councilvote/vote/' . (int) $m->id)); ?>
          <div class="row">
            <div class="col-md-5 form-group"><label style="font-size:12px">Voting agent</label>
              <select class="form-control input-sm" name="voter_agent_id" required>
                <?php foreach ($voters as $a): ?><option value="<?php echo (int) $a->id; ?>"><?php echo html_escape($a->display_name ? $a->display_name : $a->name); ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-3 form-group"><label style="font-size:12px">Vote</label>
              <select class="form-control input-sm" name="vote">
                <?php foreach ($votevalues as $vv): ?><option value="<?php echo $vv; ?>"><?php echo ucfirst($vv); ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-4 form-group"><label style="font-size:12px">Weight (0–10)</label>
              <input class="form-control input-sm" name="weight" type="number" step="0.5" min="0" max="10" value="1"></div>
          </div>
          <div class="form-group"><textarea class="form-control input-sm" name="rationale" rows="2" placeholder="Rationale (required if voting against — dissent is recorded)"></textarea></div>
          <button class="btn btn-info btn-sm" type="submit">Record ballot</button>
        <?php echo form_close(); ?>
        <p class="text-muted" style="font-size:11px;margin-top:6px">Each agent has one ballot; casting again replaces it while the motion is open.</p>
      </div></div>
      <?php endif; ?>

      <?php if ($canManage || $canRatify): ?>
      <div class="panel_s"><div class="panel-body">
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <?php $btn=function($a,$l,$c) use($m){ echo form_open(admin_url('payplex_ai_agents/councilvote/act/'.(int)$m->id.'/'.$a),array('style'=>'display:inline'));echo '<button class="btn btn-'.$c.' btn-sm" type="submit">'.$l.'</button>';echo form_close(); };
            if ($m->status === 'open' && $canManage) { $btn('close','Close voting','default'); }
            if ($canRatify && in_array($m->status, array('open','closed'), true)) {
              if (!empty($o['passed'])) { $btn('ratify','Ratify (Chairman)','success'); }
              $btn('veto','Veto (Chairman)','danger');
            }
            if ($m->status === 'closed' && $canManage) { $btn('reopen','Reopen','default'); }
          ?>
        </div>
        <?php if ($canRatify && $m->status !== 'ratified' && $m->status !== 'vetoed' && empty($o['passed'])): ?>
          <p class="text-muted" style="font-size:11px;margin-top:6px">Ratify is available only once the tally passes the threshold with quorum.</p>
        <?php endif; ?>
      </div></div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
