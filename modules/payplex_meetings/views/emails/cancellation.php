<?php defined('BASEPATH') or exit('No direct script access allowed');

$intro = '<p style="margin:0 0 14px">' . pm_lang('pm_email_cancel_intro') . '</p>';

$outro = '';
if ($audience === 'internal' && !empty($p['cancel_reason'])) {
    $outro = '<p style="margin:14px 0 0;font-size:13px;color:#6b7280"><b>'
           . pm_lang('pm_cancel_reason') . ':</b> '
           . html_escape($p['cancel_reason']) . '</p>';
}

$this->load->view('payplex_meetings/emails/_layout', compact('p', 'audience', 'name', 'intro', 'outro'));
