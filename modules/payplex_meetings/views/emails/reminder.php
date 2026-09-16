<?php defined('BASEPATH') or exit('No direct script access allowed');

$hours = (int) floor($offset / 60);
$when  = $offset >= 60
    ? $hours . ' ' . pm_lang($hours === 1 ? 'pm_hour' : 'pm_hours')
    : (int) $offset . ' ' . pm_lang('pm_minutes');

$intro = '<p style="margin:0 0 14px">'
       . sprintf(pm_lang('pm_email_reminder_intro'), html_escape($when))
       . '</p>';

$this->load->view('payplex_meetings/emails/_layout', compact('p', 'audience', 'name', 'intro'));
