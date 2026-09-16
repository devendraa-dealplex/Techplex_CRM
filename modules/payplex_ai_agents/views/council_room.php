<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
init_head();
$reviewByRole = array();
foreach ($reviews as $rv) { $reviewByRole[$rv->reviewer_role] = $rv; }
$stanceLabel = function ($s) {
    $map = array('support' => 'label-success', 'concerns' => 'label-warning', 'oppose' => 'label-danger', 'abstain' => 'label-default');
    return isset($map[$s]) ? $map[$s] : 'label-default';
};
$recLabel = function ($rec) {
    $map = array('proceed' => 'label-success', 'proceed_with_conditions' => 'label-warning', 'blocked' => 'label-danger', 'incomplete' => 'label-default');
    return isset($map[$rec]) ? $map[$rec] : 'label-default';
};
?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Executive Council — Decision #<?php echo (int) $d->id; ?></h4>
        <a href="<?php echo admin_url('payplex_ai_agents/command/view/' . (int) $d->id); ?>" class="btn btn-default btn-sm">Back to Decision</a>
      </div>

      <div class="panel_s"><div class="panel-body">
        <strong style="color:#12507F"><?php echo html_escape($d->title); ?></strong>
        <div class="text-muted" style="font-size:12px"><?php echo html_escape((string) $d->company); ?> · action <code><?php echo html_escape((string) $d->action_key); ?></code> · requires <span class="label label-primary"><?php echo html_escape($d->required_tier); ?></span></div>
      </div></div>

      <?php if (!$council): ?>
        <div class="alert alert-info" style="font-size:12px">The council has not been convened for this decision yet. Convening opens the cross-review: CFO → Risk → Legal → CISO → Audit, then the Group CEO consolidates and the Chairman decides.</div>
        <?php echo form_open(admin_url('payplex_ai_agents/command/convene/' . (int) $d->id)); ?>
          <button class="btn btn-primary" type="submit">Convene Executive Council</button>
        <?php echo form_close(); ?>
      <?php else: ?>

      <div class="row">
        <div class="col-md-8">
          <?php foreach ($roster as $role => $meta):
            $rv = isset($reviewByRole[$role]) ? $reviewByRole[$role] : null; ?>
            <div class="panel_s" style="<?php echo $meta['blocker'] ? 'border-left:3px solid #d9534f' : 'border-left:3px solid #ddd'; ?>"><div class="panel-body">
              <div style="display:flex;justify-content:space-between;align-items:center">
                <div>
                  <strong><?php echo html_escape($meta['label']); ?></strong>
                  <span class="text-muted" style="font-size:11px">· <?php echo html_escape($meta['dimension']); ?> review<?php echo $meta['blocker'] ? ' · hard blocker' : ''; ?></span>
                </div>
                <?php if ($rv): ?>
                  <span class="label <?php echo $stanceLabel($rv->stance); ?>"><?php echo html_escape($rv->stance); ?><?php echo $rv->confidence !== null ? ' · ' . round($rv->confidence * 100) . '%' : ''; ?></span>
                <?php else: ?>
                  <span class="label label-default">awaiting</span>
                <?php endif; ?>
              </div>
              <?php if ($rv && $rv->comments): ?><p style="font-size:12px;margin:6px 0 0"><?php echo nl2br(html_escape($rv->comments)); ?></p><?php endif; ?>

              <?php if ($council->status !== 'consolidated'): ?>
                <?php echo form_open(admin_url('payplex_ai_agents/command/review/' . (int) $d->id), array('class' => 'form-inline', 'style' => 'margin-top:8px')); ?>
                  <input type="hidden" name="role" value="<?php echo $role; ?>">
                  <div class="form-group"><select class="form-control input-sm" name="stance">
                    <?php foreach ($stances as $st): ?><option value="<?php echo $st; ?>" <?php echo ($rv && $rv->stance === $st) ? 'selected' : ''; ?>><?php echo $st; ?></option><?php endforeach; ?>
                  </select></div>
                  <div class="form-group"><input class="form-control input-sm" type="number" step="0.01" min="0" max="1" name="confidence" placeholder="conf 0-1" value="<?php echo $rv && $rv->confidence !== null ? html_escape($rv->confidence) : ''; ?>" style="width:90px"></div>
                  <div class="form-group"><input class="form-control input-sm" name="comments" placeholder="comments / dimension impact" value="<?php echo $rv ? html_escape((string) $rv->comments) : ''; ?>" style="width:260px"></div>
                  <button class="btn btn-default btn-sm" type="submit"><?php echo $rv ? 'Update' : 'File review'; ?></button>
                <?php echo form_close(); ?>
              <?php endif; ?>
            </div></div>
          <?php endforeach; ?>
        </div>

        <div class="col-md-4">
          <div class="panel_s"><div class="panel-body">
            <h5 style="font-weight:600;margin-top:0">Group CEO consolidation</h5>
            <?php $p = $preview; ?>
            <div style="margin-bottom:8px">
              <span class="label <?php echo $recLabel($p['recommendation']); ?>" style="font-size:12px"><?php echo html_escape($p['recommendation']); ?></span>
              <?php if ($p['conflict']): ?><span class="label label-warning">conflict</span><?php endif; ?>
            </div>
            <p style="font-size:12px"><?php echo html_escape($p['summary']); ?></p>
            <div style="font-size:12px">
              <div>Support: <strong><?php echo (int) $p['supported']; ?></strong> · Concerns: <strong><?php echo (int) $p['concerns']; ?></strong> · Oppose: <strong><?php echo (int) $p['opposed']; ?></strong> · Abstain: <strong><?php echo (int) $p['abstained']; ?></strong></div>
              <div>Council confidence (weakest link): <strong><?php echo $p['council_confidence'] !== null ? round($p['council_confidence'] * 100) . '%' : '—'; ?></strong></div>
              <?php if (!empty($p['missing_roles'])): ?><div class="text-muted">Awaiting: <?php echo html_escape(implode(', ', $p['missing_roles'])); ?></div><?php endif; ?>
            </div>

            <?php if (!empty($p['dissents'])): ?>
              <hr><strong style="font-size:12px">Dissent (preserved)</strong>
              <ul style="font-size:12px;padding-left:16px;margin:6px 0">
                <?php foreach ($p['dissents'] as $ds): ?>
                  <li><?php echo html_escape($ds['label']); ?> — <span class="label <?php echo $stanceLabel($ds['stance']); ?>"><?php echo html_escape($ds['stance']); ?></span> <?php echo html_escape($ds['comments']); ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <hr>
            <?php if ($council->status !== 'consolidated'): ?>
              <?php echo form_open(admin_url('payplex_ai_agents/command/consolidate/' . (int) $d->id)); ?>
                <button class="btn btn-primary btn-block btn-sm" type="submit" <?php echo empty($missing) ? '' : 'title="Some reviews still missing — you can consolidate now; it will be marked incomplete"'; ?>>Consolidate (Group CEO)</button>
              <?php echo form_close(); ?>
              <p class="text-muted" style="font-size:11px;margin-top:6px">Consolidation records the council's verdict on the decision packet. It never forces consensus, and it never overrides the Chairman — the Chairman still makes the final call on the decision.</p>
            <?php else: ?>
              <div class="alert alert-success" style="font-size:12px;margin-bottom:6px">Consolidated by staff #<?php echo (int) $council->consolidated_by; ?>.</div>
              <a class="btn btn-primary btn-block btn-sm" href="<?php echo admin_url('payplex_ai_agents/command/view/' . (int) $d->id); ?>">Go to Chairman decision</a>
            <?php endif; ?>
          </div></div>
        </div>
      </div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
