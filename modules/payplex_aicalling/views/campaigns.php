<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <div class="pp-dash-head">
      <h4 class="pp-title">AI Calling Campaigns</h4>
      <?php if ($can_create): ?>
        <a class="btn btn-primary btn-sm" href="<?php echo admin_url('payplex_aicalling/campaigns/create'); ?>">+ New Campaign</a>
      <?php endif; ?>
    </div>
    <p class="text-muted">Bulk campaigns require approval by a different user before they can run (maker-checker).</p>
    <div class="table-responsive">
      <table class="table pp-table">
        <thead><tr><th>Name</th><th>Agent</th><th>Targets</th><th>Status</th><th>Created by</th><th>Approved by</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($campaigns)): ?>
          <tr><td colspan="7" class="pp-empty">No campaigns yet.</td></tr>
        <?php else: foreach ($campaigns as $c): ?>
          <tr>
            <td><b><?php echo html_escape($c->name); ?></b><div class="mini"><?php echo html_escape($c->objective ?: ''); ?></div></td>
            <td class="mini"><?php echo html_escape($c->agent_id ?: '—'); ?> · <?php echo html_escape($c->language ?: ''); ?></td>
            <td><?php echo (int)$c->completed_calls; ?> / <?php echo (int)$c->total_targets; ?></td>
            <td><span class="pp-badge pp-<?php echo in_array($c->status,['approved','running','completed'])?'completed':(in_array($c->status,['rejected'])?'failed':'scheduled'); ?>"><?php echo html_escape(str_replace('_',' ',$c->status)); ?></span></td>
            <td class="mini"><?php echo $c->created_by ? get_staff_full_name($c->created_by) : '—'; ?></td>
            <td class="mini"><?php echo $c->approved_by ? get_staff_full_name($c->approved_by) : '—'; ?></td>
            <td>
              <?php if ($can_approve && $c->status === 'pending_approval'): ?>
                <a class="btn btn-xs btn-success" href="<?php echo admin_url('payplex_aicalling/campaigns/approve/'.$c->id); ?>">Approve</a>
                <?php echo form_open(admin_url('payplex_aicalling/campaigns/reject/'.$c->id), ['style'=>'display:inline']); ?>
                  <button class="btn btn-xs btn-default" type="submit">Reject</button>
                <?php echo form_close(); ?>
              <?php endif; ?>
              <?php if ($can_create && $c->status !== 'running'): ?>
                <?php echo form_open(admin_url('payplex_aicalling/campaigns/delete/'.$c->id), ['style'=>'display:inline']); ?>
                  <button class="btn btn-xs btn-danger" type="submit" onclick="return confirm('Delete this campaign? This cannot be undone.');">Delete</button>
                <?php echo form_close(); ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
