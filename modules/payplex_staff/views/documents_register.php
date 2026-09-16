<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">

    <h4 style="color:#12507F;font-weight:600;margin:0 0 4px">Document Compliance</h4>
    <p class="text-muted" style="font-size:13px">
      Everybody in the CRM, against the documents their engagement requires. A person with no
      classification is measured against the common set and marked <i>provisional</i> rather
      than being reported complete because nobody has decided what they need.
    </p>

    <div class="alert alert-<?php echo $storage['ready'] ? 'info' : 'danger'; ?>" style="font-size:12px;background:#F7F9FC;border:1px solid #E4E9F0;color:#4A5568">
      <b>Storage:</b> <?php echo html_escape($storage['path']); ?>
      &middot; <?php echo $storage['safe'] ? 'outside the web root' : 'NOT SAFE'; ?>
      &middot; <?php echo $storage['ready'] ? 'writable' : 'not writable'; ?>
      <?php if ($storage['message']): ?><br><?php echo html_escape($storage['message']); ?><?php endif; ?>
    </div>

    <?php
      /*
       * The expiry alert, such as it can be until SMTP exists.
       *
       * A per-row count nobody adds up is not an alert. These three totals sit
       * above the table so a lapsed document is the first thing on the screen
       * rather than the twelfth row of the ninth column. Dispatch by email is
       * still blocked on SMTP, which is stated here rather than implied by a
       * bell icon that sends nothing.
       */
      $tExpired = 0; $tSoon = 0; $tRejected = 0; $tIncomplete = 0;
      foreach ($board as $r) {
          $tExpired    += (int) $r['expired'];
          $tSoon       += (int) $r['expiring_soon'];
          $tRejected   += (int) $r['rejected'];
          if ((int) $r['percent'] < 100) { $tIncomplete++; }
      }
    ?>
    <div class="alert alert-<?php echo ($tExpired || $tRejected) ? 'danger' : ($tSoon ? 'warning' : 'success'); ?>"
         style="font-size:13px">
      <b><?php echo (int) $tExpired; ?></b> expired &middot;
      <b><?php echo (int) $tSoon; ?></b> expiring within <?php echo (int) Workforce_documents::EXPIRY_WARN_DAYS; ?> days &middot;
      <b><?php echo (int) $tRejected; ?></b> rejected &middot;
      <b><?php echo (int) $tIncomplete; ?></b> of <?php echo count($board); ?> people short of their requirement.
      <br><small>Shown here and on each person's page. Email dispatch is not enabled &mdash; SMTP is
      unconfigured on this install, and a reminder that silently fails to send is worse than none.</small>
    </div>

    <table class="table table-striped" style="font-size:13px">
      <thead><tr>
        <th>#</th><th>Name</th><th>CRM login</th><th>Engagement</th>
        <th>Verified</th><th>Not supplied</th><th>Awaiting verification</th>
        <th>Rejected</th><th>Expiring</th><th>Expired</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($board as $r): ?>
        <tr>
          <td><?php echo (int) $r['staffid']; ?></td>
          <td><?php echo html_escape($r['name']); ?></td>
          <td><?php echo $r['crm_active'] ? '<span class="label label-success">active</span>'
                                          : '<span class="label label-default">disabled</span>'; ?></td>
          <td>
            <?php echo html_escape($r['employment'] ?: 'not classified'); ?>
            <?php if ($r['provisional']): ?>
              <span class="label label-warning" style="font-size:10px">provisional</span>
            <?php endif; ?>
          </td>
          <td><b style="color:<?php echo $r['percent'] >= 100 ? '#2E7D32' : '#B26A00'; ?>">
            <?php echo (int) $r['percent']; ?>%</b></td>
          <td><?php echo (int) $r['missing']; ?></td>
          <td><?php echo (int) $r['unverified']; ?></td>
          <td><?php echo $r['rejected'] ? '<span class="text-danger">' . (int) $r['rejected'] . '</span>' : '0'; ?></td>
          <td><?php echo $r['expiring_soon'] ? '<span class="text-warning">' . (int) $r['expiring_soon'] . '</span>' : '0'; ?></td>
          <td><?php echo $r['expired'] ? '<span class="text-danger">' . (int) $r['expired'] . '</span>' : '0'; ?></td>
          <td><a class="btn btn-default btn-xs"
                 href="<?php echo admin_url('payplex_staff/staff/documents/' . (int) $r['staffid']); ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

  </div></div>
</div></div></div>
<?php init_tail(); ?>
