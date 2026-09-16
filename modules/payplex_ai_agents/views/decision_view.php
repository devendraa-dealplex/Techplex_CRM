<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
init_head();
$row = function ($label, $val) {
    if ($val === null || $val === '') { $val = '<span class="text-muted">—</span>'; } else { $val = nl2br(html_escape((string) $val)); }
    echo '<tr><th style="width:210px;vertical-align:top">' . $label . '</th><td>' . $val . '</td></tr>';
};
$tierLabel = $d->required_tier === 'chairman' ? 'label-primary' : ($d->required_tier === 'manager' ? 'label-info' : 'label-default');
$canDecide = in_array($actorTier, array('manager','chairman'), true);
?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Decision #<?php echo (int) $d->id; ?> — <?php echo html_escape($d->title); ?></h4>
        <div>
          <a href="<?php echo admin_url('payplex_ai_agents/command/council/' . (int) $d->id); ?>" class="btn btn-info btn-sm"><i class="fa fa-users"></i> Executive Council</a>
          <a href="<?php echo admin_url('payplex_ai_agents/command/inbox'); ?>" class="btn btn-default btn-sm">Back to Inbox</a>
        </div>
      </div>

      <div style="margin-bottom:10px">
        <span class="label <?php echo $tierLabel; ?>">Requires: <?php echo html_escape($d->required_tier); ?></span>
        <span class="label label-default">Status: <?php echo html_escape($d->status); ?></span>
        <span class="text-muted" style="font-size:11px">tier reason: <?php echo html_escape((string) $d->tier_reason); ?></span>
      </div>

      <?php if (!$completeness['ok']): ?>
        <div class="alert alert-warning" style="font-size:12px"><strong>Packet incomplete</strong> — the Chairman cannot approve until these are filled: <?php echo html_escape(implode(', ', $completeness['missing'])); ?>. Return it for revision or edit the source.</div>
      <?php endif; ?>

      <div class="row">
        <div class="col-md-8">
          <div class="panel_s"><div class="panel-body">
            <table class="table table-bordered" style="font-size:12px">
              <?php
              $row('Requesting agent', $d->requesting_agent_id ? ('#' . (int) $d->requesting_agent_id) : null);
              $row('Company / brand', $d->company);
              $row('Business objective', $d->objective);
              $row('Recommended action', $d->recommended_action . ($d->action_key ? ' (' . $d->action_key . ')' : ''));
              $row('Reason', $d->reason);
              $row('Evidence', $d->evidence);
              $row('Source-data links', $d->source_links);
              $row('Assumptions', $d->assumptions);
              $row('Confidence', $d->confidence !== null ? (round($d->confidence * 100) . '%') : null);
              $row('Alternatives', $d->alternatives);
              $row('Financial impact', $d->financial_impact);
              $row('Expected revenue', $d->expected_revenue !== null ? number_format((float) $d->expected_revenue, 2) : null);
              $row('Expected cost', $d->expected_cost !== null ? number_format((float) $d->expected_cost, 2) : null);
              $row('ROI / payback', $d->roi);
              $row('Amount', $d->amount !== null ? number_format((float) $d->amount, 2) : null);
              $row('Legal / compliance impact', $d->legal_impact);
              $row('Security impact', $d->security_impact);
              $row('Employee / customer impact', $d->people_impact);
              $row('Risk rating', $d->risk_rating);
              $row('Reversibility', $d->reversibility);
              $row('Rollback method', $d->rollback_method);
              $row('Execution owner', $d->execution_owner);
              $row('Deadline', $d->deadline);
              $row('Other agents\' opinions', $d->other_opinions);
              $row('Audit-agent verification', $d->audit_verification);
              ?>
            </table>
          </div></div>
        </div>

        <div class="col-md-4">
          <div class="panel_s"><div class="panel-body">
            <h5 style="font-weight:600;margin-top:0">Decision</h5>
            <?php if ($d->decided_by): ?>
              <p style="font-size:12px">Decided by staff #<?php echo (int) $d->decided_by; ?> on <?php echo html_escape((string) $d->decided_at); ?>.</p>
              <?php if ($d->decision_note): ?><div class="well" style="font-size:12px"><?php echo nl2br(html_escape($d->decision_note)); ?></div><?php endif; ?>
            <?php endif; ?>

            <?php if ($isMaker && in_array($d->status, array('submitted'), true)): ?>
              <div class="alert alert-info" style="font-size:12px">You created this packet, so you cannot approve or reject it (maker ≠ approver). A different approver must decide.</div>
            <?php endif; ?>

            <?php if (empty($allowed)): ?>
              <p class="text-muted" style="font-size:12px">No actions available from status "<?php echo html_escape($d->status); ?>".</p>
            <?php else: ?>
              <?php echo form_open(admin_url('payplex_ai_agents/command/act/' . (int) $d->id . '/PLACEHOLDER'), array('id' => 'pp-decide')); ?>
                <div class="form-group">
                  <label style="font-size:12px">Note (optional)</label>
                  <textarea class="form-control" name="note" rows="2"></textarea>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px">
                  <?php if (in_array('submit', $allowed, true)): ?>
                    <button class="btn btn-primary btn-sm" formaction="<?php echo admin_url('payplex_ai_agents/command/act/' . (int) $d->id . '/submit'); ?>" type="submit">Submit to Chairman</button>
                  <?php endif; ?>
                  <?php if (in_array('approve', $allowed, true)): ?>
                    <button class="btn btn-success btn-sm" formaction="<?php echo admin_url('payplex_ai_agents/command/act/' . (int) $d->id . '/approve'); ?>" type="submit" <?php echo (!$completeness['ok'] || $isMaker || !$canDecide) ? 'disabled' : ''; ?>>Approve</button>
                    <button class="btn btn-danger btn-sm" formaction="<?php echo admin_url('payplex_ai_agents/command/act/' . (int) $d->id . '/reject'); ?>" type="submit" <?php echo ($isMaker || !$canDecide) ? 'disabled' : ''; ?>>Reject</button>
                    <button class="btn btn-warning btn-sm" formaction="<?php echo admin_url('payplex_ai_agents/command/act/' . (int) $d->id . '/return'); ?>" type="submit" <?php echo $isMaker ? 'disabled' : ''; ?>>Return for revision</button>
                    <?php if ($d->required_tier !== 'chairman'): ?>
                      <button class="btn btn-info btn-sm" formaction="<?php echo admin_url('payplex_ai_agents/command/act/' . (int) $d->id . '/delegate'); ?>" type="submit" <?php echo ($isMaker || !$canDecide) ? 'disabled' : ''; ?>>Delegate-approve (within limit)</button>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if (in_array('execute', $allowed, true)): ?>
                    <button class="btn btn-default btn-sm" formaction="<?php echo admin_url('payplex_ai_agents/command/act/' . (int) $d->id . '/execute'); ?>" type="submit">Mark executed (human-performed)</button>
                  <?php endif; ?>
                </div>
              <?php echo form_close(); ?>
              <p class="text-muted" style="font-size:11px;margin-top:8px">Approval records the decision. It does not itself perform any real-world action — execution stays with the human owner.</p>
            <?php endif; ?>
          </div></div>
        </div>
      </div>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
