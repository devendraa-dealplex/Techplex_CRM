<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Decision Inbox</h4>
        <div>
          <a href="<?php echo admin_url('payplex_ai_agents/command'); ?>" class="btn btn-default btn-sm">Command Centre</a>
          <a href="<?php echo admin_url('payplex_ai_agents/command/create'); ?>" class="btn btn-info btn-sm"><i class="fa fa-plus"></i> New Decision Packet</a>
        </div>
      </div>

      <?php $cv = isset($companyView) ? $companyView : ''; ?>
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
        <ul class="nav nav-tabs" style="margin-bottom:14px">
          <?php foreach (array('submitted'=>'Awaiting','approved'=>'Approved','returned'=>'Returned','rejected'=>'Rejected','delegated'=>'Delegated','draft'=>'Drafts') as $k=>$label): ?>
            <li class="<?php echo $status===$k?'active':''; ?>"><a href="<?php echo admin_url('payplex_ai_agents/command/inbox?status='.$k.'&company='.urlencode($cv)); ?>"><?php echo $label; ?></a></li>
          <?php endforeach; ?>
        </ul>
        <?php if (!empty($companies)): ?>
        <form method="get" action="<?php echo admin_url('payplex_ai_agents/command/inbox'); ?>" style="margin-bottom:10px">
          <input type="hidden" name="status" value="<?php echo html_escape($status); ?>">
          <label style="font-size:12px;font-weight:600">Company view:</label>
          <select class="form-control input-sm" name="company" onchange="this.form.submit()" style="display:inline-block;width:auto">
            <option value="">Group (all)</option>
            <?php foreach ($companies as $c): ?><option value="<?php echo html_escape($c->code); ?>" <?php echo $cv===$c->code?'selected':''; ?>><?php echo html_escape($c->name); ?></option><?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </div>

      <div class="table-responsive"><table class="table table-bordered" style="font-size:12px">
        <thead><tr><th>#</th><th>Title</th><th>Company</th><th>Action</th><th>Tier</th><th>Risk</th><th>Conf.</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($decisions)): ?><tr><td colspan="9" class="text-muted">No decisions in this state.</td></tr>
        <?php else: foreach ($decisions as $d): ?>
          <tr>
            <td>#<?php echo (int) $d->id; ?></td>
            <td><strong><?php echo html_escape($d->title); ?></strong></td>
            <td><?php echo html_escape((string) $d->company); ?></td>
            <td><code style="font-size:11px"><?php echo html_escape((string) $d->action_key); ?></code></td>
            <td><span class="label <?php echo $d->required_tier === 'chairman' ? 'label-primary' : ($d->required_tier === 'manager' ? 'label-info' : 'label-default'); ?>"><?php echo html_escape($d->required_tier); ?></span></td>
            <td><?php echo html_escape((string) $d->risk_rating); ?></td>
            <td><?php echo $d->confidence !== null ? (int) round($d->confidence * 100) . '%' : '-'; ?></td>
            <td><?php echo html_escape((string) $d->datecreated); ?></td>
            <td><a class="btn btn-primary btn-xs" href="<?php echo admin_url('payplex_ai_agents/command/view/' . (int) $d->id); ?>">Open</a></td>
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
