<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">AI Agent Audit Log<?php echo $agent_id !== null ? ' — Agent #' . (int) $agent_id : ''; ?></h4>
        <a href="<?php echo admin_url('payplex_ai_agents/agents'); ?>" class="btn btn-default btn-sm">Back</a>
      </div>
      <p class="text-muted" style="font-size:12px">Immutable trail. Secret values (API keys, tokens, passwords) are masked before storage.</p>
      <div class="table-responsive"><table class="table table-striped table-bordered" style="font-size:12px">
        <thead><tr><th>#</th><th>When</th><th>Agent</th><th>Event</th><th>Actor</th><th>Message</th><th>IP</th></tr></thead>
        <tbody>
        <?php if (empty($rows)): ?><tr><td colspan="7" class="text-muted">No audit entries yet.</td></tr><?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?php echo (int) $r->id; ?></td>
            <td><?php echo html_escape((string) $r->datecreated); ?></td>
            <td><?php echo $r->agent_id ? '#' . (int) $r->agent_id : '-'; ?></td>
            <td><span class="label label-default"><?php echo html_escape($r->event_type); ?></span></td>
            <td><?php echo (int) $r->actor_id; ?></td>
            <td><?php echo html_escape((string) $r->message); ?></td>
            <td><?php echo html_escape((string) $r->ip); ?></td>
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
