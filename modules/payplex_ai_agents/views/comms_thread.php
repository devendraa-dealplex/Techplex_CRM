<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head();
$nm = function ($id) use ($names) { $id=(int)$id; return isset($names[$id]) ? $names[$id] : ('Agent #' . $id); };
$st = $t->status==='escalated'?'label-danger':($t->status==='awaiting'?'label-warning':($t->status==='resolved'?'label-success':($t->status==='closed'?'label-default':'label-info'))); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-9">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600"><?php echo html_escape($t->subject); ?></h4>
        <a href="<?php echo admin_url('payplex_ai_agents/comms'); ?>" class="btn btn-default btn-sm">Back</a>
      </div>

      <div class="panel_s"><div class="panel-body">
        <p>
          <span class="label <?php echo $st; ?>"><?php echo html_escape($t->status); ?></span>
          <span class="label label-<?php echo Payplex_agent_comms::typeClass($t->kind); ?>"><?php echo html_escape($t->kind); ?></span>
          <span class="label label-default"><?php echo $t->company ? html_escape($t->company) : 'group-level'; ?></span>
          <?php echo (int) $t->requires_human === 1 ? '<span class="label label-danger">needs human</span>' : ''; ?>
        </p>
        <?php if ((int) $t->requires_human === 1): ?>
        <div class="alert alert-danger" style="font-size:12px">This thread contains a message that asks for a real external action or was escalated. Nothing here executes automatically — a human must decide, and any real action must go through a <a href="<?php echo admin_url('payplex_ai_agents/command/create'); ?>">Decision Packet</a>.</div>
        <?php endif; ?>

        <?php foreach ($messages as $m):
          $tc = Payplex_agent_comms::typeClass($m->msg_type); $pc = Payplex_agent_comms::priorityClass($m->priority); ?>
          <div style="border-left:3px solid #ddd;padding:6px 10px;margin-bottom:10px">
            <div style="font-size:12px">
              <strong><?php echo html_escape($nm($m->from_agent_id)); ?></strong>
              <span class="text-muted">→ <?php echo html_escape($nm($m->to_agent_id)); ?></span>
              <span class="label label-<?php echo $tc; ?>"><?php echo html_escape($m->msg_type); ?></span>
              <span class="label label-<?php echo $pc; ?>"><?php echo html_escape($m->priority); ?></span>
              <span class="label label-default"><?php echo html_escape($m->status); ?></span>
              <?php echo (int) $m->requires_human === 1 ? '<span class="label label-danger">needs human</span>' : ''; ?>
            </div>
            <div style="white-space:pre-wrap;margin:6px 0;font-size:13px"><?php echo nl2br(html_escape($m->body)); ?></div>
            <?php if (!empty($m->external_flags)): ?><div class="text-danger" style="font-size:11px"><i class="fa fa-exclamation-triangle"></i> external-action phrasing: <?php echo html_escape($m->external_flags); ?></div><?php endif; ?>
            <div class="text-muted" style="font-size:11px">
              <?php echo html_escape((string) $m->datecreated); ?>
              <?php if ($canManage && $m->status === 'sent'): ?>
                · <a href="<?php echo admin_url('payplex_ai_agents/comms/msg/' . (int) $t->id . '/' . (int) $m->id . '/read'); ?>">mark read</a>
              <?php endif; ?>
              <?php if ($canManage && in_array($m->status, array('sent','read'), true)): ?>
                · <a href="<?php echo admin_url('payplex_ai_agents/comms/msg/' . (int) $t->id . '/' . (int) $m->id . '/acknowledge'); ?>">acknowledge</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div></div>

      <?php if ($canManage && $t->status !== 'closed'): ?>
      <div class="panel_s"><div class="panel-body">
        <h6 style="font-weight:600;margin-top:0">Reply</h6>
        <?php echo form_open(admin_url('payplex_ai_agents/comms/reply/' . (int) $t->id)); ?>
          <div class="row">
            <div class="col-md-4 form-group"><label style="font-size:12px">From</label>
              <select class="form-control input-sm" name="from_agent_id" required>
                <?php foreach ($agents as $a): ?><option value="<?php echo (int) $a->id; ?>"><?php echo html_escape($a->display_name ? $a->display_name : $a->name); ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-4 form-group"><label style="font-size:12px">To</label>
              <select class="form-control input-sm" name="to_agent_id" required>
                <?php foreach ($agents as $a): ?><option value="<?php echo (int) $a->id; ?>"><?php echo html_escape($a->display_name ? $a->display_name : $a->name); ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-2 form-group"><label style="font-size:12px">Type</label>
              <select class="form-control input-sm" name="type">
                <?php foreach ($types as $ty): ?><option value="<?php echo $ty; ?>" <?php echo $ty==='response'?'selected':''; ?>><?php echo ucfirst($ty); ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-2 form-group"><label style="font-size:12px">Priority</label>
              <select class="form-control input-sm" name="priority">
                <?php foreach ($priorities as $p): ?><option value="<?php echo $p; ?>" <?php echo $p==='normal'?'selected':''; ?>><?php echo ucfirst($p); ?></option><?php endforeach; ?>
              </select></div>
          </div>
          <div class="form-group"><textarea class="form-control input-sm" name="body" rows="3" required placeholder="Reply (internal only)"></textarea></div>
          <button class="btn btn-info btn-sm" type="submit">Post message</button>
        <?php echo form_close(); ?>
      </div></div>

      <div class="panel_s"><div class="panel-body">
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <?php $btn=function($a,$l,$c) use($t){ echo form_open(admin_url('payplex_ai_agents/comms/act/'.(int)$t->id.'/'.$a),array('style'=>'display:inline'));echo '<button class="btn btn-'.$c.' btn-sm" type="submit">'.$l.'</button>';echo form_close(); };
            if (in_array($t->status,array('open','awaiting'),true)) { $btn('resolve','Resolve','success'); $btn('escalate','Escalate to human','danger'); }
            if (in_array($t->status,array('resolved','escalated'),true)) { $btn('reopen','Reopen','default'); }
            $btn('close','Close','default');
          ?>
        </div>
      </div></div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
