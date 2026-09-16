<?php defined('BASEPATH') or exit('No direct script access allowed');

$intro = '<p style="margin:0 0 14px">' . pm_lang('pm_email_confirm_intro') . '</p>';
$outro = '<p style="margin:14px 0 0;font-size:13px;color:#6b7280">'
       . pm_lang('pm_email_ics_note') . '</p>';

$this->load->view('payplex_meetings/emails/_layout', compact('p', 'audience', 'name', 'intro', 'outro'));
