<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $cv = isset($companyView) ? $companyView : ''; $s = $summary; ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Agent Messages</h4>
        <div>
          <?php if ($canManage): ?><a href="<?php echo admin_url('payplex_ai_agents/comms/create'); ?>" class="btn btn-info btn-sm"><i class="fa fa-plus"></i> New Thread</a><?php endif; ?>
        </div>
      </div>
      <div class="alert alert-info" style="font-size:12px">Internal coordination bus for agents — requests, hand-offs, escalations and responses. <strong>Nothing here is ever sent to a customer or the outside world.</strong> A message that asks for a real external action (email/SMS/call/payment/refund/publish/delete) is flagged “needs human” and must go through a Decision Packet before anything acts on it.</div>

      <div class="row">
        <?php $tiles = array(
          array('Threads', $s['total'], '#12507F', ''),
          array('Awaiting reply', $s['awaiting'], '#f0ad4e', 'awaiting'),
          array('Escalated', $s['escalated'], '#d9534f', 'escalated'),
          array('Needs human', $s['needs_human'], '#d9534f', ''),
        ); foreach ($tiles as $tl): ?>
          <div class="col-md-3 col-xs-6" style="margin-bottom:10px">
            <a href="<?php echo admin_url('payplex_ai_agents/comms' . ($tl[3] ? '?status=' . $tl[3] : '')); ?>" style="text-decoration:none">
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
          <?php foreach (array(''=>'All','open'=>'Open','awaiting'=>'Awaiting','escalated'=>'Escalated','resolved'=>'Resolved','closed'=>'Closed') as $k=>$label): ?>
            <li class="<?php echo $status===$k?'active':''; ?>"><a href="<?php echo admin_url('payplex_ai_agents/comms?status='.$k.'&company='.urlencode($cv)); ?>"><?php echo $label; ?></a></li>
          <?php endforeach; ?>
        </ul>
        <?php if (!empty($companies)): ?>
        <form method="get" action="<?php echo admin_url('payplex_ai_agents/comms'); ?>" style="margin-bottom:10px">
          <input type="hidden" name="status" value="<?php echo html_escape($status); ?>">
          <select class="form-control input-sm" name="company" onchange="this.form.submit()" style="display:inline-block;width:auto">
            <option value="">Group (all)</option>
            <?php foreach ($companies as $c): ?><option value="<?php echo html_escape($c->code); ?>" <?php echo $cv===$c->code?'selected':''; ?>><?php echo html_escape($c->name); ?></option><?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </div>

      <div class="table-responsive"><table class="table table-bordered" style="font-size:12px">
        <thead><tr><th>#</th><th>Subject</th><th>Kind</th><th>Company</th><th>Status</th><th>Msgs</th><th>Flags</th><th>Updated</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($rows)): ?><tr><td colspan="9" class="text-muted">No threads.</td></tr>
        <?php else: foreach ($rows as $r): $t=$r['t']; $sm=$r['summary'];
          $st = $t->status==='escalated'?'label-danger':($t->status==='awaiting'?'label-warning':($t->status==='resolved'?'label-success':($t->status==='closed'?'label-default':'label-info'))); ?>
          <tr>
            <td>#<?php echo (int) $t->id; ?></td>
            <td><strong><?php echo html_escape($t->subject); ?></strong></td>
            <td><span class="label label-<?php echo Payplex_agent_comms::typeClass($t->kind); ?>"><?php echo html_escape($t->kind); ?></span></td>
            <td><?php echo $t->company ? html_escape($t->company) : '<span class="text-muted">group</span>'; ?></td>
            <td><span class="label <?php echo $st; ?>"><?php echo html_escape($t->status); ?></span></td>
            <td><?php echo (int) $sm['count']; ?><?php echo $sm['unread']?' <span class="label label-info">'.(int)$sm['unread'].' new</span>':''; ?></td>
            <td><?php echo (int) $t->requires_human === 1 ? '<span class="label label-danger">needs human</span>' : ''; ?></td>
            <td><?php echo html_escape((string) $t->dateupdated); ?></td>
            <td><a class="btn btn-primary btn-xs" href="<?php echo admin_url('payplex_ai_agents/comms/view/' . (int) $t->id); ?>">Open</a></td>
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
