<?php init_head(); ?>
<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div id="wrapper"><div class="content">
<?php echo form_open(admin_url('leadgen_google')); ?>
<div class="row">
<div class="col-md-6">
<?php
/*
 * These two fields were rendered as type="password" with the stored key as the
 * value. A password input masks the characters on screen; it does not remove
 * them from the page. The key that authenticates every inbound Google lead was
 * sitting in the value="" attribute of the HTML, readable by anyone who opened
 * the page or viewed source — which, until the controller was given an
 * authorization check, was every staff member on the install.
 *
 * The field is now sent empty and the placeholder answers the only question a
 * settings screen needs to answer: configured, or not. No value, no length, no
 * prefix. Saving with the field blank leaves the stored key untouched; the
 * controller enforces that, so opening this page and pressing Submit cannot
 * wipe the keys.
 */
$pp_key_state = function ($opt) {
    return trim((string) get_option($opt)) !== ''
        ? 'configured — leave blank to keep it'
        : 'not configured';
};
?>
<?= render_input('settings[google_ads_webhook_key]', _l('google_ads_webhook_key'), '', 'password',
        array('placeholder' => $pp_key_state('google_ads_webhook_key'), 'autocomplete' => 'new-password')); ?>
<?= render_input('settings[google_website_form_api_key]', _l('google_website_form_api_key'), '', 'password',
        array('placeholder' => $pp_key_state('google_website_form_api_key'), 'autocomplete' => 'new-password')); ?>
<?= render_input('settings[google_ads_default_source]', _l('google_ads_default_source'), get_option('google_ads_default_source'), 'text'); ?>
<?= render_input('settings[google_website_default_source]', _l('google_website_default_source'), get_option('google_website_default_source'), 'text'); ?>
<?php if (!empty($lead_statuses)) { ?>
<?= render_select('settings[google_default_lead_status]', $lead_statuses, array('id', 'name'), 'google_default_lead_status', get_option('google_default_lead_status')); ?>
<?php } ?>
<button type="submit" class="btn btn-primary"><?= _l('submit'); ?></button>
</div>
<div class="col-md-6">
<div class="alert alert-info">
<strong><?= _l('google_ads_webhook_url'); ?>:</strong><br />
<code><?= site_url('leadgen_google/webhook/ads'); ?></code>
<hr />
<?= _l('google_ads_webhook_hint'); ?>
</div>
<div class="alert alert-info" style="margin-top:15px;">
<strong><?= _l('google_website_webhook_url'); ?>:</strong><br />
<code><?= site_url('leadgen_google/webhook/website'); ?></code>
<hr />
<?= _l('google_website_webhook_hint'); ?>
</div>
</div>
</div>
<?php echo form_close(); ?>
</div></div>
<?php init_tail(); ?>