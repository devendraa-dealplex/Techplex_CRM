<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Customer portal page: /clients/video-kyc. Plain server-rendered forms (no JS
 * needed); Perfex's form_open adds the CSRF field.
 * Expects $kyc_customer, $kyc_state, $kyc_docs.
 */
$s1     = $kyc_state['step1'];
$s2     = $kyc_state['step2'];
$s2Text = ['locked' => 'Locked', 'ready' => 'Ready', 'in_progress' => 'In progress', 'completed' => 'Completed'];
$canStart = $s2 === 'ready' || !empty($kyc_state['resumable']);
$label    = !empty($kyc_state['resumable']) ? 'Continue Video KYC'
    : ($s2 === 'in_progress' ? 'Awaiting review' : ($s2 === 'completed' ? 'Video KYC completed'
    : (in_array($kyc_state['request_status'], ['rejected', 'resubmit'], true) ? 'Record video again' : 'Start Video KYC')));
$statusLabels = ['pending' => 'Awaiting you', 'in_progress' => 'In progress', 'submitted' => 'Submitted, awaiting review',
    'approved' => 'Approved', 'rejected' => 'Rejected', 'resubmit' => 'Action needed: please resubmit', 'expired' => 'Link expired'];
?>
<div class="row">
  <div class="col-md-8 col-md-offset-2">
    <h3 class="section-heading">Video KYC</h3>
    <?php if (!$kyc_customer) { ?>
      <div class="alert alert-warning">Your account could not be found.</div>
    <?php } else { ?>

    <div class="panel_s"><div class="panel-body">
      <h4 class="no-margin">Identity document
        <span class="label label-<?php echo $s1 === 'completed' ? 'success' : 'warning'; ?> pull-right"><?php echo $s1 === 'completed' ? 'Completed' : 'Pending'; ?></span>
      </h4>
      <hr>
      <?php echo form_open_multipart(site_url('clients/video-kyc/upload')); ?>
        <div class="form-group">
          <label for="kyc-doc-type">Document type</label>
          <select id="kyc-doc-type" name="doc_type" class="form-control" required>
            <option value="">Select…</option>
            <?php foreach (Videokyc_model::DOC_TYPES as $k => $l) { ?>
              <option value="<?php echo html_escape($k); ?>"><?php echo html_escape($l); ?></option>
            <?php } ?>
          </select>
        </div>
        <div class="form-group">
          <label for="kyc-doc-file">File <small class="text-muted">(PDF, JPG or PNG, max 5 MB)</small></label>
          <input type="file" id="kyc-doc-file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
        </div>
        <button type="submit" class="btn btn-primary">Upload document</button>
      <?php echo form_close(); ?>

      <?php if ($kyc_docs) { ?>
      <div class="table-responsive mtop15">
        <table class="table table-condensed">
          <thead><tr><th>Type</th><th>Uploaded</th></tr></thead>
          <tbody>
          <?php foreach ($kyc_docs as $d) { ?>
            <tr>
              <td><?php echo html_escape(isset(Videokyc_model::DOC_TYPES[$d->doc_type]) ? Videokyc_model::DOC_TYPES[$d->doc_type] : $d->doc_type); ?></td>
              <td><?php echo html_escape(_dt($d->created_at)); ?></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
      <?php } ?>
    </div></div>

    <div class="panel_s"><div class="panel-body">
      <h4 class="no-margin">Video KYC
        <span class="label label-<?php echo $s2 === 'completed' ? 'success' : ($s2 === 'locked' ? 'default' : 'info'); ?> pull-right"><?php echo html_escape($s2Text[$s2]); ?></span>
      </h4>
      <hr>
      <?php if ($kyc_state['request_status']) { ?>
        <p>Latest request: <strong><?php echo html_escape(isset($statusLabels[$kyc_state['request_status']]) ? $statusLabels[$kyc_state['request_status']] : $kyc_state['request_status']); ?></strong></p>
      <?php } ?>
      <?php if (in_array($kyc_state['request_status'], ['approved', 'rejected', 'resubmit'], true)) {
          $alert = ['approved' => 'success', 'rejected' => 'danger', 'resubmit' => 'warning'][$kyc_state['request_status']]; ?>
        <div class="alert alert-<?php echo $alert; ?>">
          <?php if ($kyc_state['request_status'] === 'approved') { ?>
            <strong>Your KYC has been approved.</strong>
          <?php } elseif ($kyc_state['request_status'] === 'rejected') { ?>
            <strong>Your KYC was not approved.</strong> You can upload documents again and record a new video.
          <?php } else { ?>
            <strong>We need something more from you.</strong> Please follow the note below, then record again.
          <?php } ?>
          <?php if ($kyc_state['review_note'] !== '') { ?>
            <br><em><?php echo nl2br(html_escape($kyc_state['review_note'])); ?></em>
          <?php } ?>
        </div>
      <?php } ?>
      <p class="text-muted">You can do this whenever you like, in any order with the document upload. You will be asked to allow your camera and microphone, then read a short statement aloud. It takes about a minute.</p>
      <?php echo form_open(site_url('clients/video-kyc/start')); ?>
        <button type="submit" class="btn btn-primary" <?php echo $canStart ? '' : 'disabled'; ?>><i class="fa fa-video-camera"></i> <?php echo html_escape($label); ?></button>
      <?php echo form_close(); ?>
    </div></div>

    <?php } ?>
  </div>
</div>
