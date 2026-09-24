<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo html_escape($title); ?></title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#f4f5f7; margin:0; }
  .wrap { max-width:520px; margin:60px auto; padding:0 16px; text-align:center; }
  .card { background:#fff; border:1px solid #e3e5e8; border-radius:8px; padding:32px 24px; }
  h1 { font-size:18px; margin-top:0; color:#1e7e42; }
  p { color:#6b7280; font-size:14px; }
  .tick { font-size:40px; margin-bottom:8px; }
  .muted { color:#8a8f98; }
  .signatures { text-align:left; margin-top:8px; }
  .co-signer { display:flex; align-items:center; gap:14px; padding:10px 0; border-top:1px solid #f0f1f3; }
  .co-signer:first-child { border-top:0; }
  .co-signer img { height:44px; max-width:170px; object-fit:contain; background:#fbfbfc; border:1px solid #e3e5e8; border-radius:4px; padding:4px; }
  .co-signer .who { font-size:13px; }
  .co-signer .who strong { display:block; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="tick">&#10003;</div>
    <h1>Thank you, your signature has been recorded</h1>
    <p><?php echo html_escape((string) $contract['subject']); ?></p>
    <p>You may close this page.</p>
  </div>

  <?php if (!empty($co_signers)) { ?>
  <div class="card signatures">
    <h2 style="font-size:15px;margin-top:0;">Signatures on this document</h2>
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
</div>
</body>
</html>
