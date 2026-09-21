<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * The customer KYC panel shown at the top of the Contracts tab on the customer
 * profile: the two-step flow (identity documents, then Video KYC) for THIS
 * customer only. There is no dashboard, request log or case list here.
 *
 * The Clients controller knows nothing about this module, so the fragment loads
 * its own data and re-checks the module's capabilities. The controller actions it
 * talks to enforce them again; this only decides what to draw.
 *
 * Expects $kyc_client_id from the caller.
 */
$CI = &get_instance();

if (!defined('PAYPLEX_VIDEOKYC_MODULE')) { return; }

$CI->load->model('payplex_videokyc/videokyc_model', 'kyc_embed');

$vkCan = function ($cap) {
    return is_admin() || staff_can($cap, PAYPLEX_VIDEOKYC_MODULE);
};

if (!$vkCan('view')) { return; }

// Sales see KYC only for customers assigned to them (admins / `view_all` see all).
$CI->kyc_embed->scopeTo($vkCan('view_all') ? null : get_staff_user_id());

$flowCustomer = $CI->kyc_embed->subject('customer', (int) $kyc_client_id);
if (!$flowCustomer) { return; }

$can = [
    'generate' => false,   // customers do the upload and the recording themselves
    'review'   => $vkCan('review'),
    'video'    => $vkCan('video_access'),
    'settings' => $vkCan('settings'),
    'documents' => $vkCan('documents'),
];

$this->load->view('payplex_videokyc/client_kyc_flow', [
    'flow_customer' => $flowCustomer,
    'flow_state'    => $CI->kyc_embed->flowState($flowCustomer['id']),
    'flow_docs'     => $can['documents'] ? $CI->kyc_embed->documentsFor($flowCustomer['id']) : [],
    'flow_can'      => ['documents' => $can['documents']],
]);

?>
<div class="panel_s"><div class="panel-body">
  <div class="kyc-toolbar">
    <h5 class="no-margin kyc-sec">Video KYC requests</h5>
    <div class="kyc-filters">
      <select id="kyc-filter-status" class="form-control input-sm">
        <option value="">All statuses</option>
        <option value="pending">Pending (link sent)</option>
        <option value="in_progress">Verification in progress</option>
        <option value="submitted">Awaiting review</option>
        <option value="approved">Approved</option>
        <option value="rejected">Rejected</option>
        <option value="resubmit">Asked to resubmit</option>
        <option value="expired">Expired</option>
      </select>
      <button type="button" class="btn btn-default btn-sm" id="kyc-refresh" title="Refresh"><i class="fa fa-refresh"></i></button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover kyc-table" id="kyc-table" data-per-page="10">
      <thead><tr>
        <th>Customer</th><th>Generated</th><th>Status</th><th>Channels</th><th class="text-right">Actions</th>
      </tr></thead>
      <tbody><tr><td colspan="5" class="text-muted">Loading…</td></tr></tbody>
    </table>
  </div>
  <div class="kyc-pager" id="kyc-pager"></div>
</div></div>
<?php
$this->load->view('payplex_videokyc/review_modal', ['can' => $can]);
$this->load->view('payplex_videokyc/kyc_config', ['can' => $can, 'kyc_customer_id' => $flowCustomer['id']]);
