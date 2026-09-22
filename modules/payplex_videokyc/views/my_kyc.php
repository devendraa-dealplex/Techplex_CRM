<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * /admin/video-kyc/my — a staff member's own employee Video KYC status.
 * Read-only, mirroring the customer's /clients/video-kyc page: they see where
 * they stand and any reviewer's note, but do not upload documents (employee
 * KYC is video only) and do not self-start (HR/their manager issues the link) —
 * a live, already-issued link can be reopened here rather than waiting for
 * another email.
 * Expects $req (request row or null), $can_continue, $review_note.
 */
$labels = ['pending' => ['Awaiting you', 'default'], 'in_progress' => ['In progress', 'info'],
    'submitted' => ['Submitted, awaiting review', 'warning'], 'approved' => ['Approved', 'success'],
    'rejected' => ['Rejected', 'danger'], 'resubmit' => ['Action needed: please resubmit', 'warning'],
    'expired' => ['Link expired', 'default']];
$status = $req ? $req->status : null;
$label  = ($status && isset($labels[$status])) ? $labels[$status] : ['Not started', 'default'];
init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-8 col-md-offset-2">
      <div class="panel_s"><div class="panel-body">
        <h4 class="no-mtop"><i class="fa fa-video-camera"></i> My Video KYC
          <span class="label label-<?php echo $label[1]; ?> pull-right"><?php echo html_escape($label[0]); ?></span>
        </h4>
        <hr>

        <?php if (!$req) { ?>
          <p class="text-muted">Your Video KYC has not been started yet. HR or your manager will send you a link
            by email, SMS or WhatsApp when it's time.</p>

        <?php } elseif (in_array($status, ['approved', 'rejected', 'resubmit'], true)) {
            $alert = ['approved' => 'success', 'rejected' => 'danger', 'resubmit' => 'warning'][$status]; ?>
          <div class="alert alert-<?php echo $alert; ?>">
            <?php if ($status === 'approved') { ?>
              <strong>Your Video KYC has been approved.</strong>
            <?php } elseif ($status === 'rejected') { ?>
              <strong>Your Video KYC was not approved.</strong> HR or your manager will send you a new link to try again.
            <?php } else { ?>
              <strong>Something needs to be corrected.</strong> HR or your manager will send you a new link.
            <?php } ?>
            <?php if ($review_note !== '') { ?>
              <br><em><?php echo nl2br(html_escape($review_note)); ?></em>
            <?php } ?>
          </div>

        <?php } elseif ($status === 'submitted') { ?>
          <p class="text-muted">Your video has been submitted and is awaiting review.</p>

        <?php } elseif (in_array($status, ['pending', 'in_progress'], true)) { ?>
          <?php if ($can_continue) { ?>
            <p class="text-muted">You have a Video KYC link waiting. Recording takes about a minute.</p>
            <?php echo form_open(admin_url('video-kyc/my_continue')); ?>
              <button type="submit" class="btn btn-primary"><i class="fa fa-video-camera"></i> Continue Video KYC</button>
            <?php echo form_close(); ?>
          <?php } else { ?>
            <p class="text-warning">Your link has expired. Ask HR or your manager to send you a new one.</p>
          <?php } ?>

        <?php } elseif ($status === 'expired') { ?>
          <p class="text-warning">Your link has expired. Ask HR or your manager to send you a new one.</p>
        <?php } ?>

      </div></div>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
