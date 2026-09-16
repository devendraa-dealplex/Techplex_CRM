<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>

      <?php if (!$request) { ?>
        <div class="alert alert-info">No signing request exists for this contract.</div>
      <?php } else { ?>
        <p>
          Request state:
          <strong><?php echo html_escape(isset($states[$request['state']])
              ? $states[$request['state']]['label'] : (string) $request['state']); ?></strong>
        </p>

        <div class="table-responsive">
          <table class="table table-striped">
            <thead><tr>
              <th>Signer</th><th class="hidden-xs">Invited</th><th class="hidden-xs">Viewed</th>
              <th>Completed</th><th class="hidden-xs">Declined</th>
            </tr></thead>
            <tbody>
            <?php foreach ($signers as $s) { ?>
              <tr>
                <td><?php echo html_escape((string) $s['full_name']); ?></td>
                <td class="hidden-xs"><?php echo $s['invited_at']
                      ? html_escape(_dt(date('Y-m-d H:i:s', (int) $s['invited_at']))) : '&mdash;'; ?></td>
                <td class="hidden-xs"><?php echo $s['viewed_at']
                      ? html_escape(_dt(date('Y-m-d H:i:s', (int) $s['viewed_at']))) : '&mdash;'; ?></td>
                <td><?php echo $s['completed_at']
                      ? html_escape(_dt(date('Y-m-d H:i:s', (int) $s['completed_at']))) : '&mdash;'; ?></td>
                <td class="hidden-xs"><?php echo $s['declined_at']
                      ? html_escape(_dt(date('Y-m-d H:i:s', (int) $s['declined_at']))) : '&mdash;'; ?></td>
              </tr>
            <?php } ?>
            </tbody>
          </table>
        </div>
      <?php } ?>

      <h5>Audit trail</h5>
      <div class="table-responsive">
        <table class="table table-condensed">
          <thead><tr><th>When</th><th>Who</th><th>Event</th><th class="hidden-xs">Detail</th></tr></thead>
          <tbody>
          <?php foreach ($audit as $a) { ?>
            <tr>
              <td><?php echo html_escape(_dt(date('Y-m-d H:i:s', (int) $a['at']))); ?></td>
              <td><?php echo (int) $a['actor_id'] > 0
                    ? html_escape(get_staff_full_name((int) $a['actor_id'])) : 'system'; ?></td>
              <td><?php echo html_escape(str_replace('_', ' ', (string) $a['event'])); ?></td>
              <td class="hidden-xs"><code style="font-size:11px"><?php
                echo html_escape(mb_substr((string) $a['detail'], 0, 160)); ?></code></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
      <p class="text-muted small">
        The audit trail outlives the contract workspace, so it carries no contact detail, no
        identity number and no signing link. Anything on that list is written as
        <code>[redacted]</code>.
      </p>
    </div></div>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
