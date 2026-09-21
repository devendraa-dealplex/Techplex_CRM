<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * The "Video KYC" customer-profile tab (?group=payplex_vkyc). Rendered by
 * Perfex's Clients controller, so $client is this customer. The panel re-checks
 * the module's capabilities and renders nothing without 'view'.
 */
if (!isset($client)) { return; } ?>
<h4 class="customer-profile-group-heading">Video KYC</h4>
<?php $this->load->view('payplex_videokyc/client_kyc_panel', ['kyc_client_id' => (int) $client->userid]); ?>
