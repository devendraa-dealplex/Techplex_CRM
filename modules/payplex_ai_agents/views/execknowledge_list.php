<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $cv = isset($companyView) ? $companyView : ''; ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Executive Knowledge</h4>
        <div>
          <a href="<?php echo admin_url('payplex_ai_agents/execknowledge/search'); ?>" class="btn btn-default btn-sm"><i class="fa fa-search"></i> Retrieval</a>
          <a href="<?php echo admin_url('payplex_ai_agents/execknowledge/memory'); ?>" class="btn btn-default btn-sm">Executive Memory</a>
          <?php if ($canManage): ?><a href="<?php echo admin_url('payplex_ai_agents/execknowledge/create'); ?>" class="btn btn-info btn-sm"><i class="fa fa-plus"></i> New Entry</a><?php endif; ?>
        </div>
      </div>

      <div class="alert alert-info" style="font-size:12px">Only <strong>approved &amp; in-effect</strong> entries are citable by the agents — a draft, returned, archived or expired entry can never ground a decision. Whoever wrote or edited an entry cannot approve it (maker&nbsp;&ne;&nbsp;approver). Entries are scoped by company.</div>

      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <ul class="nav nav-tabs" style="margin-bottom:14px">
          <?php $tabs = array(''=>'All','draft'=>'Draft','review'=>'In review','approved'=>'Approved','returned'=>'Returned','archived'=>'Archived');
          foreach ($tabs as $k=>$label): ?>
            <li class="<?php echo $status===$k?'active':''; ?>"><a href="<?php echo admin_url('payplex_ai_agents/execknowledge?status='.$k.'&company='.urlencode($cv)); ?>"><?php echo $label; ?></a></li>
          <?php endforeach; ?>
        </ul>
        <?php if (!empty($companies)): ?>
        <form method="get" action="<?php echo admin_url('payplex_ai_agents/execknowledge'); ?>" style="margin-bottom:10px">
          <input type="hidden" name="status" value="<?php echo html_escape($status); ?>">
          <select class="form-control input-sm" name="company" onchange="this.form.submit()" style="display:inline-block;width:auto">
            <option value="">Group (all)</option>
            <?php foreach ($companies as $c): ?><option value="<?php echo html_escape($c->code); ?>" <?php echo $cv===$c->code?'selected':''; ?>><?php echo html_escape($c->name); ?></option><?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </div>

      <div class="table-responsive"><table class="table table-bordered" style="font-size:12px">
        <thead><tr><th>#</th><th>Title</th><th>Category</th><th>Company</th><th>Status</th><th>Conf.</th><th>Citable</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($entries)): ?><tr><td colspan="8" class="text-muted">No entries.</td></tr>
        <?php else: foreach ($entries as $e):
          $cit = Payplex_agent_exec_knowledge::isCitable($e);
          $sl  = $e->status==='approved'?'label-success':($e->status==='review'?'label-warning':($e->status==='returned'?'label-danger':($e->status==='archived'?'label-default':'label-info'))); ?>
          <tr>
            <td>#<?php echo (int) $e->id; ?></td>
            <td><strong><?php echo html_escape($e->title); ?></strong></td>
            <td><?php echo html_escape($e->category); ?></td>
            <td><?php echo html_escape((string) $e->company); ?></td>
            <td><span class="label <?php echo $sl; ?>"><?php echo html_escape($e->status); ?></span></td>
            <td><?php echo (int) round(((float) $e->confidence) * 100); ?>%</td>
            <td><?php echo $cit ? '<span class="label label-success">yes</span>' : '<span class="text-muted">no</span>'; ?></td>
            <td><a class="btn btn-primary btn-xs" href="<?php echo admin_url('payplex_ai_agents/execknowledge/view/' . (int) $e->id); ?>">Open</a></td>
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
