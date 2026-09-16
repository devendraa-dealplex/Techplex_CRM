<?php defined('BASEPATH') or exit('No direct script access allowed');

$intro = '<p style="margin:0 0 14px">' . pm_lang('pm_email_reschedule_intro') . '</p>';

/* The reschedule reason is internal context. It reaches staff, never the client. */
$outro = '';
if ($audience === 'internal' && !empty($p['reschedule_reason'])) {
    $outro = '<p style="margin:14px 0 0;font-size:13px;color:#6b7280"><b>'
           . pm_lang('pm_reschedule_reason') . ':</b> '
           . html_escape($p['reschedule_reason']) . '</p>';
}

$this->load->view('payplex_meetings/emails/_layout', compact('p', 'audience', 'name', 'intro', 'outro'));
