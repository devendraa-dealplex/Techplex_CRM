<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Human-Review Queue <span class="text-muted" style="font-size:13px">(<?php echo count($open); ?> open)</span></h4>
        <a href="<?php echo admin_url('payplex_ai_agents/dashboard'); ?>" class="btn btn-default btn-sm">Back to Dashboard</a>
      </div>
      <p class="text-muted" style="font-size:12px">Low-confidence actions, KB misses and budget breaches land here for a human to approve, reject or resolve. Nothing acts in production without clearing review.</p>

      <h5>Open</h5>
      <div class="table-responsive"><table class="table table-bordered" style="font-size:12px">
        <thead><tr><th>#</th><th>When</th><th>Agent</th><th>Run</th><th>Reason</th><th>Detail</th><th style="width:230px">Action</th></tr></thead>
        <tbody>
        <?php if (empty($open)): ?><tr><td colspan="7" class="text-muted">Queue is empty.</td></tr><?php else: foreach ($open as $e): ?>
          <tr>
            <td><?php echo (int) $e->id; ?></td>
            <td><?php echo html_escape((string) $e->datecreated); ?></td>
            <td>#<?php echo (int) $e->agent_id; ?></td>
            <td><?php echo $e->run_id ? '#' . (int) $e->run_id : '-'; ?></td>
            <td><span class="label label-warning"><?php echo html_escape($e->reason); ?></span></td>
            <td><?php echo html_escape((string) $e->detail); ?></td>
            <td style="white-space:nowrap">
              <?php echo form_open(admin_url('payplex_ai_agents/dashboard/resolve/' . (int) $e->id . '/approved'), array('style' => 'display:inline')); ?><button class="btn btn-success btn-xs" type="submit">Approve</button><?php echo form_close(); ?>
              <?php echo form_open(admin_url('payplex_ai_agents/dashboard/resolve/' . (int) $e->id . '/rejected'), array('style' => 'display:inline')); ?><button class="btn btn-danger btn-xs" type="submit">Reject</button><?php echo form_close(); ?>
              <?php echo form_open(admin_url('payplex_ai_agents/dashboard/resolve/' . (int) $e->id . '/resolved'), array('style' => 'display:inline')); ?><button class="btn btn-default btn-xs" type="submit">Resolve</button><?php echo form_close(); ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>

      <h5 style="margin-top:18px">Recent (all)</h5>
      <div class="table-responsive"><table class="table table-striped" style="font-size:12px">
        <thead><tr><th>#</th><th>When</th><th>Agent</th><th>Reason</th><th>Status</th><th>By</th><th>Resolved</th></tr></thead>
        <tbody>
        <?php if (empty($recent)): ?><tr><td colspan="7" class="text-muted">Nothing yet.</td></tr><?php else: foreach ($recent as $e): $sc = $e->status === 'approved' ? 'success' : ($e->status === 'rejected' ? 'danger' : ($e->status === 'open' ? 'warning' : 'default')); ?>
          <tr>
            <td><?php echo (int) $e->id; ?></td>
            <td><?php echo html_escape((string) $e->datecreated); ?></td>
            <td>#<?php echo (int) $e->agent_id; ?></td>
            <td><?php echo html_escape($e->reason); ?></td>
            <td><span class="label label-<?php echo $sc; ?>"><?php echo html_escape($e->status); ?></span></td>
            <td><?php echo $e->resolved_by ? (int) $e->resolved_by : '-'; ?></td>
            <td><?php echo html_escape((string) $e->resolved_at); ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div></div></div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
