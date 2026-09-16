<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * The DRAFT / FINAL stamp shown on any screen that presents a statement.
 *
 * A draft must be unmistakably a draft. Someone glancing at a screen, or
 * printing it, must not be able to take an unapproved statement for a final
 * one — so the stamp is a banner rather than a small badge, it says what is
 * missing, and it appears above the figures rather than below them.
 *
 * The state is read from workflow_state via the workflow library, never from
 * the legacy `status` enum: those two columns were written by separate paths
 * and could disagree, and whichever said "approved" would have won.
 *
 * Expects: $issue (from Payplex_commission_workflow::canIssue()).
 */
?>
<?php if ($issue['label'] === 'FINAL'): ?>
  <div style="border:1px solid #A6E9C3;background:#F0FDF6;color:#067647;border-radius:6px;
              padding:10px 12px;margin-bottom:14px;font-size:13px">
    <b>FINAL</b> — approved, and may be issued.
  </div>
<?php else: ?>
  <div style="border:2px solid #B42318;background:#FEF3F2;color:#B42318;border-radius:6px;
              padding:12px 14px;margin-bottom:14px">
    <div style="font-size:20px;font-weight:700;letter-spacing:3px">
      <?php echo html_escape($issue['label']); ?>
    </div>
    <div style="font-size:13px;margin-top:4px"><?php echo html_escape($issue['reason']); ?></div>
    <div style="font-size:12px;margin-top:6px;opacity:.85">
      Not to be issued, downloaded as a final statement, or sent to anyone outside the
      approval chain in this state.
    </div>
  </div>
<?php endif; ?>
