<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$issueText = array(
    'missing_name'      => 'Name missing',
    'no_contact_method' => 'No email or phone',
    'invalid_email'     => 'Invalid email',
    'invalid_phone'     => 'Invalid phone',
);
$reasonText = array(
    'least_loaded'        => 'least-loaded sales staff',
    'territory+workload'  => 'territory match, then least-loaded',
    'no_available_staff'  => 'no eligible staff (check the role rule in Settings)',
);
$leadLink = function ($id) use ($lookups) {
    $id = (int) $id;
    $name = isset($lookups['leads'][$id]) ? $lookups['leads'][$id] : null;
    return '<a href="' . admin_url('leads/index/' . $id) . '" target="_blank">' . ($name !== null && $name !== '' ? html_escape($name) : '(lead deleted)') . '</a> <span class="text-muted">#' . $id . '</span>';
};
?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Lead Pipeline Activity</h4>
        <a href="<?php echo admin_url('payplex_ai_agents/agents'); ?>" class="btn btn-default btn-sm">Back to Agents</a>
      </div>

      <div class="alert <?php echo $auto_run ? 'alert-warning' : 'alert-info'; ?>">
        <strong>Auto-run on new leads:</strong> <?php echo $auto_run ? '<span class="label label-warning">ON</span>' : '<span class="label label-success">OFF</span>'; ?>
        <?php echo form_open(admin_url('payplex_ai_agents/pipeline/toggle_auto'), array('style' => 'display:inline;margin-left:8px')); ?>
          <button class="btn btn-xs <?php echo $auto_run ? 'btn-default' : 'btn-primary'; ?>" type="submit"><?php echo $auto_run ? 'Disable auto-run' : 'Enable auto-run'; ?></button>
        <?php echo form_close(); ?>
        <div class="text-muted" style="font-size:11px;margin-top:4px">When ON, each new/converted lead is run through the pipeline in <strong>sandbox</strong> (validate → dedupe → score → assign → follow-up) and the recommendations are logged. It never changes the lead. OFF by default so the live lead flow is untouched.</div>
      </div>

      <div class="panel_s" style="background:#f4f8fb"><div class="panel-body">
        <strong>Test: run pipeline on a lead (sandbox)</strong>
        <?php echo form_open(admin_url('payplex_ai_agents/pipeline/test'), array('class' => 'form-inline', 'style' => 'margin-top:6px')); ?>
          <div class="form-group"><input class="form-control input-sm" name="lead_id" placeholder="Lead id (e.g. 1192)" style="width:180px"></div>
          <button class="btn btn-info btn-sm" type="submit">Run pipeline</button>
        <?php echo form_close(); ?>
      </div></div>

      <p class="text-muted" style="font-size:12px">Staff are suggested only from active staff whose role contains: <strong><?php echo $assign_roles !== '' ? html_escape($assign_roles) : 'any role (no restriction)'; ?></strong>. <a href="<?php echo admin_url('payplex_ai_agents/agents/settings'); ?>">Change in Settings</a>. Every row is a recommendation only - no lead was changed.</p>

      <div class="table-responsive"><table class="table table-striped table-bordered" style="font-size:12px">
        <thead><tr><th>#</th><th>When</th><th>Lead</th><th>Event</th><th>Valid</th><th>Score</th><th>Duplicates</th><th>Suggested staff</th><th>Escalated</th></tr></thead>
        <tbody>
        <?php if (empty($events)): ?><tr><td colspan="9" class="text-muted">No pipeline activity yet. Run a test above, or enable auto-run.</td></tr><?php else: foreach ($events as $e):
          $res = json_decode((string) $e->result_json, true);
          $res = is_array($res) ? $res : array();
          $issues = isset($res['validation']['issues']) && is_array($res['validation']['issues']) ? $res['validation']['issues'] : array();
          $assign = isset($res['assignment']) && is_array($res['assignment']) ? $res['assignment'] : array();
          $plan   = isset($res['followup']) && is_array($res['followup']) ? $res['followup'] : array();
          $stages = isset($res['stages']) && is_array($res['stages']) ? $res['stages'] : array();
          $staffName = $e->assign_staff && isset($lookups['staff'][(int) $e->assign_staff]) ? $lookups['staff'][(int) $e->assign_staff] : null;
          $reasonKey = isset($assign['reason']) ? (string) $assign['reason'] : '';
        ?>
          <tr>
            <td><?php echo (int) $e->id; ?></td>
            <td><?php echo html_escape((string) $e->datecreated); ?></td>
            <td><?php echo $leadLink($e->lead_id); ?></td>
            <td><span class="label label-default"><?php echo html_escape($e->event_type); ?></span></td>
            <td>
              <?php if ((int) $e->valid === 1): ?><span class="label label-success">yes</span>
              <?php else: ?><span class="label label-danger">no</span>
                <?php foreach ($issues as $i): ?><div class="text-danger" style="font-size:11px"><?php echo html_escape(isset($issueText[$i]) ? $issueText[$i] : $i); ?></div><?php endforeach; ?>
              <?php endif; ?>
            </td>
            <td><strong><?php echo (int) $e->score; ?></strong>/100</td>
            <td>
              <?php if ($e->duplicates): foreach (array_filter(explode(',', (string) $e->duplicates)) as $d): ?>
                <a class="label label-warning" href="<?php echo admin_url('leads/index/' . (int) $d); ?>" target="_blank">#<?php echo (int) $d; ?></a>
              <?php endforeach; else: ?><span class="text-muted">none</span><?php endif; ?>
            </td>
            <td>
              <?php if ($e->assign_staff): ?>
                <?php echo html_escape($staffName !== null ? $staffName : 'Staff'); ?> <span class="text-muted">#<?php echo (int) $e->assign_staff; ?></span>
                <?php if ($reasonKey !== ''): ?><div class="text-muted" style="font-size:11px"><?php echo html_escape(isset($reasonText[$reasonKey]) ? $reasonText[$reasonKey] : $reasonKey); ?></div><?php endif; ?>
              <?php else: ?>
                <span class="text-muted">-</span>
                <?php if ($reasonKey !== ''): ?><div class="text-muted" style="font-size:11px"><?php echo html_escape(isset($reasonText[$reasonKey]) ? $reasonText[$reasonKey] : $reasonKey); ?></div><?php endif; ?>
              <?php endif; ?>
            </td>
            <td><?php echo (int) $e->escalated === 1 ? '<span class="label label-warning">yes</span>' : 'no'; ?></td>
          </tr>
          <?php if (!empty($stages) || !empty($plan)): ?>
          <tr><td></td><td colspan="8" style="padding-top:0;border-top:0">
            <details><summary class="text-muted" style="cursor:pointer">Details: pipeline stages &amp; follow-up plan</summary>
              <div class="row" style="margin-top:6px">
                <div class="col-md-6">
                  <strong>Stages</strong>
                  <ul style="margin:4px 0 0;padding-left:18px">
                  <?php foreach ($stages as $s): ?>
                    <li><span class="label label-<?php echo !empty($s['ok']) ? 'success' : 'warning'; ?>"><?php echo html_escape((string) ($s['stage'] ?? '')); ?></span> <?php echo html_escape((string) ($s['detail'] ?? '')); ?></li>
                  <?php endforeach; ?>
                  </ul>
                </div>
                <div class="col-md-6">
                  <strong>Follow-up plan</strong> <span class="text-muted">(recommended, not scheduled)</span>
                  <?php if (empty($plan)): ?>
                    <div class="text-muted">No steps - the lead has no email or phone to contact.</div>
                  <?php else: ?>
                    <ul style="margin:4px 0 0;padding-left:18px">
                    <?php foreach ($plan as $st): ?>
                      <li>+<?php echo (int) ($st['offset_hours'] ?? 0); ?>h &middot; <strong><?php echo html_escape((string) ($st['channel'] ?? '')); ?></strong> - <?php echo html_escape((string) ($st['note'] ?? '')); ?></li>
                    <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </div>
              </div>
            </details>
          </td></tr>
          <?php endif; ?>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div></div></div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
