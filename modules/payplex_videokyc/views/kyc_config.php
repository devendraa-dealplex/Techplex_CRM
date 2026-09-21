<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * window.KYC config for assets/js/videokyc.js on the customer profile tab.
 * Expects $can from client_kyc_panel.php. "Start Video KYC" needs only the base
 * URL and the permission flags: the server issues the link and the browser opens
 * it directly, so there is no generate-link popup here.
 */
?>
<script>
window.KYC = {
  base: <?php echo json_encode(admin_url('video-kyc')); ?>,
  can: <?php echo json_encode($can); ?>,
  customerId: <?php echo (int) (isset($kyc_customer_id) ? $kyc_customer_id : 0); ?>,
  languages: {},
  isDashboard: false
};
</script>
