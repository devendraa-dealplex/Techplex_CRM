<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $s = $summary; ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Chairman Command Centre</h4>
        <div>
          <?php $cv = isset($companyView) ? $companyView : ''; if (!empty($companies)): ?>
          <form method="get" action="<?php echo admin_url('payplex_ai_agents/command'); ?>" style="display:inline-block;margin-right:6px">
            <select class="form-control input-sm" name="company" onchange="this.form.submit()" style="display:inline-block;width:auto">
              <option value="">Group (all)</option>
              <?php foreach ($companies as $c): ?><option value="<?php echo html_escape($c->code); ?>" <?php echo $cv===$c->code?'selected':''; ?>><?php echo html_escape($c->name); ?></option><?php endforeach; ?>
            </select>
          </form>
          <?php endif; ?>
          <span class="label label-default" style="font-size:12px">Your authority: <?php echo html_escape(ucfirst($actorTier)); ?></span>
          <a href="<?php echo admin_url('payplex_ai_agents/command/inbox'); ?>" class="btn btn-primary btn-sm">Decision Inbox</a>
          <a href="<?php echo admin_url('payplex_ai_agents/command/matrix'); ?>" class="btn btn-default btn-sm">Approval Matrix</a>
        </div>
      </div>

      <div class="alert alert-info" style="font-size:12px">Nothing high-risk executes without approval. Agents submit <strong>decision packets</strong> with evidence; the Chairman approves, rejects or returns them. A packet can't be approved by its maker, and can't be approved while incomplete.</div>

      <div class="row">
        <?php
          $tiles = array(
            array('Awaiting decision', $s['pending'], '#d9534f', 'submitted'),
            array('Needs Chairman', $s['chairman_pending'], '#12507F', 'submitted'),
            array('Approved', $s['approved'], '#5cb85c', 'approved'),
            array('Returned', $s['returned'], '#f0ad4e', 'returned'),
            array('Rejected', $s['rejected'], '#777', 'rejected'),
            array('Delegated', $s['delegated'], '#5bc0de', 'delegated'),
          );
          foreach ($tiles as $t): ?>
          <div class="col-md-2 col-sm-4 col-xs-6" style="margin-bottom:12px">
            <a href="<?php echo admin_url('payplex_ai_agents/command/inbox?status=' . $t[3]); ?>" style="text-decoration:none">
              <div class="panel_s" style="border-top:3px solid <?php echo $t[2]; ?>"><div class="panel-body" style="text-align:center">
                <div style="font-size:26px;font-weight:700;color:<?php echo $t[2]; ?>"><?php echo (int) $t[1]; ?></div>
                <div class="text-muted" style="font-size:11px"><?php echo $t[0]; ?></div>
              </div></div>
            </a>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="row">
        <div class="col-md-4">
          <div class="panel_s"><div class="panel-body">
            <h5 style="font-weight:600;margin-top:0">Pending by company</h5>
            <?php if (empty($s['by_company'])): ?><p class="text-muted" style="font-size:12px">No pending decisions.</p>
            <?php else: foreach ($s['by_company'] as $co => $n): ?>
              <div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #eee">
                <span><?php echo html_escape($co); ?></span><span class="label label-warning"><?php echo (int) $n; ?></span>
              </div>
            <?php endforeach; endif; ?>
          </div></div>
        </div>
        <div class="col-md-8">
          <div class="panel_s"><div class="panel-body">
            <h5 style="font-weight:600;margin-top:0">Recent decisions</h5>
            <div class="table-responsive"><table class="table table-striped" style="font-size:12px">
              <thead><tr><th>#</th><th>Title</th><th>Company</th><th>Tier</th><th>Status</th></tr></thead>
              <tbody>
              <?php if (empty($s['recent'])): ?><tr><td colspan="5" class="text-muted">No decisions yet. Create one from the Decision Inbox.</td></tr>
              <?php else: foreach ($s['recent'] as $d): ?>
                <tr>
                  <td><a href="<?php echo admin_url('payplex_ai_agents/command/view/' . (int) $d->id); ?>">#<?php echo (int) $d->id; ?></a></td>
                  <td><?php echo html_escape($d->title); ?></td>
                  <td><?php echo html_escape((string) $d->company); ?></td>
                  <td><span class="label <?php echo $d->required_tier === 'chairman' ? 'label-primary' : ($d->required_tier === 'manager' ? 'label-info' : 'label-default'); ?>"><?php echo html_escape($d->required_tier); ?></span></td>
                  <td><?php echo html_escape($d->status); ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table></div>
          </div></div>
        </div>
      </div>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
