<?php defined('BASEPATH') or exit('No direct script access allowed');
$csrf_field = '<input type="hidden" name="' . html_escape($this->security->get_csrf_token_name())
            . '" value="' . html_escape($this->security->get_csrf_hash()) . '">';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo html_escape($title); ?></title>
<script src="<?php echo base_url('assets/plugins/signature-pad/signature_pad.min.js'); ?>"></script>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f4f5f7; margin:0; padding:0; color:#2b2d33; }
  .wrap { max-width:760px; margin:0 auto; padding:24px 16px 60px; }
  .card { background:#fff; border:1px solid #e3e5e8; border-radius:8px; padding:24px; margin-bottom:20px; }
  h1 { font-size:20px; margin:0 0 4px; }
  .muted { color:#6b7280; font-size:13px; }
  .alert { padding:12px 14px; border-radius:6px; margin-bottom:16px; font-size:14px; }
  .alert-warning { background:#fff7e6; color:#8a6100; border:1px solid #ffe0a3; }
  .alert-success { background:#eafaf0; color:#1e7e42; border:1px solid #b7ebc6; }
  label { display:block; font-weight:600; font-size:13px; margin:14px 0 6px; }
  input[type=text], input[type=email], input[type=tel] {
    width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #d6d9de; border-radius:6px; font-size:14px;
  }
  .otp-row { display:flex; gap:8px; align-items:flex-end; }
  .otp-row input { flex:1; }
  button { cursor:pointer; border:0; border-radius:6px; padding:11px 18px; font-size:14px; font-weight:600; }
  .btn-primary { background:#2f6fed; color:#fff; }
  .btn-default { background:#eef0f3; color:#2b2d33; }
  #pad-wrap { border:1px dashed #c9cdd3; border-radius:6px; margin-top:8px; background:#fbfbfc; }
  canvas#signature { width:100%; height:160px; display:block; touch-action:none; }
  iframe.preview { width:100%; height:420px; border:1px solid #e3e5e8; border-radius:6px; }
  .field-note { font-size:12px; color:#8a8f98; margin-top:4px; }
  .aadhaar-note { font-size:12px; color:#8a6100; background:#fff7e6; border:1px solid #ffe0a3; border-radius:6px; padding:8px 10px; margin-top:6px; }
  .co-signer { display:flex; align-items:center; gap:14px; padding:10px 0; border-top:1px solid #f0f1f3; }
  .co-signer:first-child { border-top:0; }
  .co-signer img { height:48px; max-width:180px; object-fit:contain; background:#fbfbfc; border:1px solid #e3e5e8; border-radius:4px; padding:4px; }
  .co-signer .who { font-size:13px; }
  .co-signer .who strong { display:block; }
</style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <h1><?php echo html_escape((string) $contract['subject']); ?></h1>
    <p class="muted">You are signing as <strong><?php echo html_escape((string) $signer['full_name']); ?></strong>
      (<?php echo html_escape((string) $signer['email']); ?>)</p>
  </div>

  <?php
    $flash_type = null;
    $flash_msg  = '';
    foreach (array('success', 'warning', 'danger') as $t) {
        $m = $this->session->flashdata('message-' . $t);
        if ($m !== null && $m !== false && $m !== '') {
            $flash_type = ($t === 'danger') ? 'warning' : $t;
            $flash_msg  = $m;
            break;
        }
    }
  ?>
  <?php if ($flash_type) { ?>
    <div class="alert alert-<?php echo html_escape($flash_type); ?>">
      <?php echo html_escape((string) $flash_msg); ?>
    </div>
  <?php } ?>

  <div class="card">
    <h2 style="font-size:15px;margin-top:0;">Document</h2>
    <iframe class="preview" src="<?php echo e($pdf_url); ?>"></iframe>
  </div>

  <?php if (!empty($co_signers)) { ?>
  <div class="card">
    <h2 style="font-size:15px;margin-top:0;">Already signed</h2>
    <p class="muted">The following <?php echo count($co_signers) === 1 ? 'person has' : 'people have'; ?>
      already signed this document.</p>
    <?php foreach ($co_signers as $co) { ?>
      <div class="co-signer">
        <img src="<?php echo html_escape((string) $co['image_data']); ?>" alt="Signature of <?php echo html_escape((string) $co['full_name']); ?>">
        <div class="who">
          <strong><?php echo html_escape((string) $co['full_name']); ?></strong>
          <span class="muted"><?php echo html_escape(ucfirst((string) $co['role'])); ?> &middot; signed <?php echo html_escape(date('M j, Y', (int) $co['completed_at'])); ?></span>
        </div>
      </div>
    <?php } ?>
  </div>
  <?php } ?>

  <div class="card">
    <h2 style="font-size:15px;margin-top:0;">Sign</h2>

    <?php if (!empty($needs_otp) && empty($otp_verified)) { ?>
      <p class="muted">
        This document requires a one-time code sent to your email before you can sign.
      </p>

      <form method="post" style="display:inline">
        <?php echo $csrf_field; ?>
        <input type="hidden" name="action" value="send_otp">
        <button type="submit" class="btn-default"><?php echo !empty($otp_sent) ? 'Resend code' : 'Send code'; ?></button>
      </form>

      <?php if (!empty($otp_sent)) { ?>
        <form method="post" class="otp-row" style="margin-top:14px;">
          <?php echo $csrf_field; ?>
          <input type="hidden" name="action" value="verify_otp">
          <div style="flex:1;">
            <label for="otp">Enter the 6-digit code</label>
            <input type="text" id="otp" name="otp" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" required>
          </div>
          <button type="submit" class="btn-primary">Verify</button>
        </form>
      <?php } ?>

    <?php } else { ?>

      <?php if (!empty($needs_otp)) { ?>
        <div class="alert alert-success">Code verified. You may sign below.</div>
      <?php } ?>

      <form method="post" id="signForm">
        <?php echo $csrf_field; ?>
        <input type="hidden" name="action" value="submit">
        <input type="hidden" name="signature" id="signatureInput">

        <?php if ($method === 'aadhaar') { ?>
          <label for="aadhaar_number">Aadhaar number</label>
          <input type="text" id="aadhaar_number" name="aadhaar_number" inputmode="numeric" maxlength="12"
                 pattern="[0-9]{12}" placeholder="12-digit Aadhaar number" required>
          <p class="aadhaar-note">
            This number is self-declared by you and is stored for record-keeping only. It is
            <strong>not verified against any government database</strong> by this system.
          </p>
        <?php } ?>

        <label>Draw your signature</label>
        <div id="pad-wrap">
          <canvas id="signature-pad-canvas"></canvas>
        </div>
        <p class="field-note">
          <button type="button" id="clearPad" class="btn-default" style="padding:4px 10px;font-size:12px;">Clear</button>
        </p>

        <div style="margin-top:18px;">
          <button type="submit" class="btn-primary">I agree and sign</button>
        </div>
      </form>

    <?php } ?>
  </div>

  <p class="muted" style="text-align:center;">
    This link is unique to you and can only be used once.
  </p>
</div>

<script>
(function () {
  var canvas = document.getElementById('signature-pad-canvas');
  if (!canvas) { return; }

  function resize() {
    var ratio = Math.max(window.devicePixelRatio || 1, 1);
    canvas.width = canvas.offsetWidth * ratio;
    canvas.height = canvas.offsetHeight * ratio;
    canvas.getContext('2d').scale(ratio, ratio);
    if (window.__signaturePad) { window.__signaturePad.clear(); }
  }

  window.addEventListener('resize', resize);
  resize();

  var pad = new SignaturePad(canvas, { minWidth: 1, maxWidth: 2.2 });
  window.__signaturePad = pad;

  document.getElementById('clearPad').addEventListener('click', function () {
    pad.clear();
  });

  document.getElementById('signForm').addEventListener('submit', function (e) {
    if (pad.isEmpty()) {
      e.preventDefault();
      alert('Please draw your signature before submitting.');
      return;
    }
    var dataUrl = pad.toDataURL('image/png');
    document.getElementById('signatureInput').value = dataUrl.split(',')[1];
  });
})();
</script>
</body>
</html>
