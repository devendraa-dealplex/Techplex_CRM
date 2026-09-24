<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Public customer page: /kyc/verify?token=…
 * A static shell — everything dynamic (token check, script text) comes from the
 * validate API, so an invalid/expired link never renders a customer's details.
 */
$cfg = json_encode([
    'validateUrl' => $validate,
    'uploadUrl'   => $upload,
    'csrfName'    => $csrf_name,
    'csrfHash'    => $csrf_hash,
    'company'     => $company,
    'ui'          => $ui,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$v = @filemtime(__DIR__ . '/../assets/js/kyc-capture.js') ?: 1;
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="referrer" content="no-referrer">
  <meta name="robots" content="noindex, nofollow">
  <title>Video KYC — <?php echo html_escape($company); ?></title>
  <link rel="stylesheet" href="<?php echo html_escape($asset_base); ?>css/kyc-capture.css?v=<?php echo $v; ?>">
</head>
<body>
<main class="kc-wrap">
  <header class="kc-brand"><?php echo html_escape($company); ?> &middot; Video KYC</header>

  <section id="s-loading" class="kc-card"><div class="kc-spinner" aria-hidden="true"></div><p data-i18n="checking">Checking your link…</p></section>

  <section id="s-error" class="kc-card" hidden>
    <h1 id="err-title">Link not available</h1>
    <p id="err-text"></p>
  </section>

  <section id="s-intro" class="kc-card" hidden>
    <h1 id="in-hello">Hello</h1>
    <p id="in-intro"><?php echo html_escape($company); ?> needs to verify your identity. It takes about a minute.</p>
    <ol class="kc-steps">
      <li data-i18n="step1">Allow camera and microphone access.</li>
      <li data-i18n="step2">Sit somewhere well lit, face the camera, and keep your face fully visible.</li>
      <li data-i18n="step3">Read the sentence shown on screen out loud, clearly.</li>
      <li data-i18n="step4">Review your video, then submit it.</li>
    </ol>
    <p class="kc-note" data-i18n="privacy">Your recording is private, encrypted in transit and only seen by authorised staff for identity verification.</p>
    <button id="btn-start" class="kc-btn kc-primary" data-i18n="allow">Allow camera &amp; microphone</button>
    <p id="perm-error" class="kc-error" role="alert" hidden></p>
  </section>

  <section id="s-record" class="kc-card" hidden>
    <div class="kc-stage">
      <video id="preview" autoplay muted playsinline></video>
      <div id="rec-badge" class="kc-rec" hidden><span></span> REC <b id="rec-timer">0:00</b></div>
    </div>
    <div class="kc-script"><small data-i18n="read_aloud">Please read this aloud:</small><p id="script-text"></p></div>
    <p id="rec-hint" class="kc-note"></p>
    <div class="kc-actions">
      <button id="btn-rec" class="kc-btn kc-primary" data-i18n="start_rec">Start recording</button>
      <button id="btn-stop" class="kc-btn kc-danger" hidden disabled data-i18n="stop_rec">Stop recording</button>
    </div>
  </section>

  <section id="s-review" class="kc-card" hidden>
    <video id="playback" controls playsinline></video>
    <p class="kc-note" data-i18n="review">Check that your face is visible and your voice is clear. Not happy? Record again.</p>
    <div id="upload-progress" class="kc-progress" hidden><div id="upload-bar"></div></div>
    <p id="upload-msg" class="kc-error" role="alert" hidden></p>
    <div class="kc-actions">
      <button id="btn-retake" class="kc-btn" data-i18n="retake">Record again</button>
      <button id="btn-submit" class="kc-btn kc-primary" data-i18n="submit">Submit video</button>
    </div>
  </section>

  <section id="s-done" class="kc-card" hidden>
    <div class="kc-check" aria-hidden="true">&#10003;</div>
    <h1 data-i18n="done_title">Submitted</h1>
    <p data-i18n="done_text">Thank you. Your video has been received and will be reviewed shortly. You can close this page.</p>
  </section>
</main>
<script>window.KYC_PUBLIC = <?php echo $cfg; ?>;</script>
<script src="<?php echo html_escape($asset_base); ?>js/kyc-capture.js?v=<?php echo $v; ?>"></script>
</body>
</html>
