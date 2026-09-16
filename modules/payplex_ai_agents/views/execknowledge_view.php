<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $e = $entry;
$sl = $e->status==='approved'?'label-success':($e->status==='review'?'label-warning':($e->status==='returned'?'label-danger':($e->status==='archived'?'label-default':'label-info'))); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-9">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600"><?php echo html_escape($e->title); ?></h4>
        <a href="<?php echo admin_url('payplex_ai_agents/execknowledge'); ?>" class="btn btn-default btn-sm">Back to list</a>
      </div>

      <div class="panel_s"><div class="panel-body">
        <p>
          <span class="label <?php echo $sl; ?>"><?php echo html_escape($e->status); ?></span>
          <?php echo $citable ? '<span class="label label-success">citable now</span>' : '<span class="label label-default">not citable</span>'; ?>
          <span class="label label-default"><?php echo html_escape($e->category); ?></span>
          <span class="label label-default"><?php echo $e->company ? html_escape($e->company) : 'group-level'; ?></span>
        </p>
        <p style="white-space:pre-wrap"><?php echo nl2br(html_escape($e->body)); ?></p>
        <hr>
        <p class="text-muted" style="font-size:12px"><strong>Provenance:</strong> <?php echo html_escape($provenance); ?></p>
        <?php if (!empty($e->url)): ?><p style="font-size:12px"><strong>Source URL:</strong> <a href="<?php echo html_escape($e->url); ?>" target="_blank" rel="noopener"><?php echo html_escape($e->url); ?></a></p><?php endif; ?>
        <?php if (!empty($e->tags)): ?><p style="font-size:12px"><strong>Tags:</strong> <?php echo html_escape($e->tags); ?></p><?php endif; ?>
        <p style="font-size:12px"><strong>Effective:</strong> <?php echo $e->effective_from ? html_escape($e->effective_from) : 'always'; ?> → <?php echo $e->effective_to ? html_escape($e->effective_to) : 'no end'; ?></p>
        <p class="text-muted" style="font-size:11px">Citation reference: <code><?php echo Payplex_agent_exec_knowledge::citationRef($e); ?></code> · created by staff #<?php echo (int) $e->created_by; ?> · last edit by staff #<?php echo (int) $e->updated_by; ?><?php echo $e->approved_by ? ' · approved by staff #' . (int) $e->approved_by : ''; ?></p>
      </div></div>

      <div class="panel_s"><div class="panel-body">
        <h5 style="font-weight:600;margin-top:0">Governance</h5>
        <?php if ($isMaker && $e->status === 'review'): ?>
          <div class="alert alert-warning" style="font-size:12px">You created or last edited this entry, so you cannot approve it. A different reviewer must approve it (maker&nbsp;&ne;&nbsp;approver).</div>
        <?php endif; ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <?php
            $btn = function ($action, $label, $class) use ($e) {
              echo form_open(admin_url('payplex_ai_agents/execknowledge/act/' . (int) $e->id . '/' . $action), array('style'=>'display:inline'));
              echo '<button class="btn btn-' . $class . ' btn-sm" type="submit">' . $label . '</button>';
              echo form_close();
            };
            if (in_array($e->status, array('draft','returned'), true)) {
              if ($canManage) { echo '<a class="btn btn-default btn-sm" href="' . admin_url('payplex_ai_agents/execknowledge/edit/' . (int) $e->id) . '">Edit</a>'; }
              if ($canManage) { $btn('submit', 'Submit for review', 'info'); }
            }
            if ($e->status === 'review' && $canApprove) { $btn('approve', 'Approve', 'success'); $btn('return', 'Return for changes', 'warning'); }
            if (in_array($e->status, array('draft','review','returned','approved'), true) && $canApprove) { $btn('archive', 'Archive', 'default'); }
            if ($e->status === 'archived' && $canApprove) { $btn('reopen', 'Reopen as draft', 'default'); }
          ?>
        </div>
      </div></div>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
