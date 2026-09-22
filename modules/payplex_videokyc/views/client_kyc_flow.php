<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * READ-ONLY progress of the customer's two-step KYC, for staff.
 *
 *   Step 1  Document verification : the customer uploads identity documents in the portal, any time.
 *   Video    Video KYC             : the customer records their video in the portal, any time (independent of the documents).
 *
 * Staff neither upload documents nor start the video: the customer does both after
 * logging in (the welcome email points them there). Staff open the documents, watch
 * the recording and decide, in the requests list below.
 *
 * Expects: $flow_customer (array id,name,email,phone), $flow_state (Videokyc_model::flowState),
 *          $flow_docs (rows), $flow_can (['documents' => bool]).
 */
$s1     = $flow_state['step1'];
$s2     = $flow_state['step2'];
$s2Text = ['locked' => 'Waiting for documents', 'ready' => 'Waiting for the customer to record', 'in_progress' => 'In progress / awaiting review', 'completed' => 'Approved'];
$s2Text['ready'] = in_array($flow_state['request_status'], ['rejected', 'resubmit'], true) ? 'Waiting for the customer to record again' : $s2Text['ready'];
if ($flow_state['request_status'] === 'pending') { $s2Text['in_progress'] = 'Pending: waiting for the customer to record'; }elseif ($flow_state['request_status'] === 'in_progress') { $s2Text['in_progress'] = 'Customer is recording'; }elseif ($flow_state['request_status'] === 'submitted') { $s2Text['in_progress'] = 'Video submitted: awaiting your review'; }
?>
<div class="kyc-flow" id="kyc-flow" data-customer-id="<?php echo (int) $flow_customer['id']; ?>">

  <ol class="kyc-stepper">
    <li class="kyc-step <?php echo $s1 === 'completed' ? 'is-done' : 'is-current'; ?>" id="kyc-step-1">
      <span class="kyc-step-dot"><i class="fa <?php echo $s1 === 'completed' ? 'fa-check' : 'fa-file-text-o'; ?>"></i></span>
      <span class="kyc-step-body">
        <strong>Identity documents</strong>
        <span class="kyc-step-status" data-step-status="1"><?php echo $s1 === 'completed' ? 'Uploaded by customer' : 'Not uploaded yet'; ?></span>
      </span>
    </li>
    <li class="kyc-step kyc-step-<?php echo $s2; ?>" id="kyc-step-2">
      <span class="kyc-step-dot"><i class="fa <?php echo $s2 === 'completed' ? 'fa-check' : ($s2 === 'locked' ? 'fa-lock' : 'fa-video-camera'); ?>"></i></span>
      <span class="kyc-step-body">
        <strong>Video KYC</strong>
        <span class="kyc-step-status" data-step-status="2"><?php echo html_escape($s2Text[$s2]); ?></span>
      </span>
    </li>
  </ol>

  <div class="panel_s"><div class="panel-body">
    <h5 class="no-margin kyc-sec">Identity documents <small class="text-muted">(uploaded by the customer)</small></h5>

    <div class="table-responsive mtop15">
      <table class="table table-condensed kyc-doc-table" id="kyc-doc-table">
        <thead><tr><th>Type</th><th>Uploaded</th><th>By</th><th>SHA-256</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($flow_docs)) { ?>
          <tr class="kyc-doc-empty"><td colspan="5" class="text-muted">The customer has not uploaded any document yet.</td></tr>
        <?php } else { foreach ($flow_docs as $d) { ?>
          <tr>
            <td><?php echo html_escape(isset(Videokyc_model::DOC_TYPES[$d->doc_type]) ? Videokyc_model::DOC_TYPES[$d->doc_type] : $d->doc_type); ?></td>
            <td><?php echo html_escape($d->created_at); ?></td>
            <td><?php echo html_escape($d->uploaded_by ? (string) get_staff_full_name($d->uploaded_by) : 'Customer (portal)'); ?></td>
            <td><code title="<?php echo html_escape($d->sha256); ?>"><?php echo html_escape(substr($d->sha256, 0, 16)); ?>…</code></td>
            <td><?php if ($flow_can['documents']) { ?><a class="btn btn-default btn-xs" target="_blank" rel="noopener"
                href="<?php echo admin_url('video-kyc/document/' . (int) $d->id); ?>">View</a><?php } ?></td>
          </tr>
        <?php } } ?>
        </tbody>
      </table>
    </div>
  </div></div>
</div>
