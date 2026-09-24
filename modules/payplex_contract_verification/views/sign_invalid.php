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
  h1 { font-size:18px; margin-top:0; }
  p { color:#6b7280; font-size:14px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>This signing link cannot be used</h1>
    <p><?php
        $messages = array(
            'token_does_not_match' => 'This link is not recognised.',
            'already_used'         => 'This link has already been used to sign the document.',
            'already_signed'       => 'You have already signed this document.',
            'expired'              => 'This link has expired. Ask whoever sent it to send you a new one.',
            'no_token_presented'   => 'No signing link was provided.',
            'signer_not_found'     => 'The signer for this link could not be found.',
            'contract_not_found'   => 'The document for this link could not be found.',
        );
        echo html_escape(isset($messages[$reason]) ? $messages[$reason]
            : (strpos((string) $reason, 'revoked_') === 0
                ? 'This link has been withdrawn.'
                : 'This link is no longer valid.'));
    ?></p>
  </div>
</div>
</body>
</html>
