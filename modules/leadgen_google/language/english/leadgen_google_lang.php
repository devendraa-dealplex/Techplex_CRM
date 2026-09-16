<?php
/*
 * Its Facebook and WhatsApp siblings both carry this guard; this one did not,
 * so the file could be requested directly over HTTP. Nothing sensitive is in
 * it, which is why this is a warning and not a finding — but a PHP file under
 * the webroot that runs on request is a habit worth not having.
 */
defined('BASEPATH') or exit('No direct script access allowed');

$lang['leadgen_google_settings'] = 'Google Lead Settings';
$lang['google_ads_webhook_key'] = 'Google Ads Webhook Key';
$lang['google_website_form_api_key'] = 'Website Form API Key';
$lang['google_ads_default_source'] = 'Google Ads Lead Source Name';
$lang['google_website_default_source'] = 'Website Form Lead Source Name';
$lang['google_default_lead_status'] = 'Default Lead Status';
$lang['google_ads_webhook_url'] = 'Google Ads Webhook URL';
$lang['google_ads_webhook_hint'] = 'Copy this URL into your Google Ads Lead Form Extension webhook integration settings, along with the Webhook Key above.';
$lang['google_website_webhook_url'] = 'Website / Google Form Webhook URL';
$lang['google_website_webhook_hint'] = 'Point your website form or a Google Form (via Apps Script) to this URL, sending the API Key above as a "key" field.';
$lang['leadgen_google_perm_view'] = 'View';
$lang['leadgen_google_perm_edit'] = 'Edit';
