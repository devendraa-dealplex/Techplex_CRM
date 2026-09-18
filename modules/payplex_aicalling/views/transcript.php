<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-8 col-md-offset-2">
  <div class="panel_s"><div class="panel-body">
    <h4 class="pp-title">Call Transcript &mdash; #<?php echo (int) $call->id; ?>
      <?php echo $call->crm_lead_id ? '<small class="text-muted">Lead #' . (int) $call->crm_lead_id . '</small>' : ''; ?>
    </h4>

    <?php
      $turns = $plain = null;
      if ($ok && $transcript) {
          foreach (['turns', 'segments', 'utterances'] as $k) {
              if (!empty($transcript[$k]) && is_array($transcript[$k])) { $turns = $transcript[$k]; break; }
          }
          foreach (['transcript', 'text'] as $k) {
              if (!empty($transcript[$k]) && is_string($transcript[$k])) { $plain = $transcript[$k]; break; }
          }
      }
    ?>
    <?php if (!$ok): ?>
      <div class="alert alert-danger"><?php echo html_escape($error); ?></div>
    <?php elseif (empty($transcript)): ?>
      <div class="alert alert-info">The backend has not returned a transcript for this call yet.</div>
    <?php elseif ($turns): ?>
      <div class="pp-transcript">
        <?php foreach ($turns as $t): $t = (array) $t; ?>
          <div style="margin-bottom:10px;">
            <b><?php echo html_escape($t['speaker'] ?? $t['role'] ?? 'Unknown'); ?></b>
            <?php if (!empty($t['timestamp'])): ?><span class="text-muted mini"> &middot; <?php echo html_escape($t['timestamp']); ?></span><?php endif; ?>
            <div><?php echo nl2br(html_escape($t['text'] ?? $t['message'] ?? '')); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php elseif ($plain): ?>
      <div style="white-space:pre-wrap;"><?php echo nl2br(html_escape($plain)); ?></div>
    <?php else: ?>
      <p class="text-muted">Unrecognised transcript shape — raw response:</p>
      <pre style="white-space:pre-wrap;"><?php echo html_escape(json_encode($transcript, JSON_PRETTY_PRINT)); ?></pre>
    <?php endif; ?>

    <p style="margin-top:16px;">
      <a href="<?php echo admin_url('payplex_aicalling/aicalling/call_detail/' . (int) $call->id); ?>" class="btn btn-default btn-sm">&larr; Back to call detail</a>
    </p>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
