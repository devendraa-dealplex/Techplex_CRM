<?php init_head(); ?>
<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div id="wrapper"><div class="content">
<?php echo form_open(admin_url('leadgen_whatsapp')); ?>
<div class="row">
<div class="col-md-6">
<?= render_input('settings[whatsapp_phone_number_id]', _l('whatsapp_phone_number_id'), get_option('whatsapp_phone_number_id'), 'text'); ?>
<?= render_input('settings[whatsapp_business_account_id]', _l('whatsapp_business_account_id'), get_option('whatsapp_business_account_id'), 'text'); ?>
<?= render_input('settings[whatsapp_access_token]', _l('whatsapp_access_token'), get_option('whatsapp_access_token'), 'password'); ?>
<?= render_input('settings[whatsapp_app_secret]', _l('whatsapp_app_secret'), get_option('whatsapp_app_secret'), 'password'); ?>
<?= render_input('settings[whatsapp_verify_token]', _l('whatsapp_verify_token'), get_option('whatsapp_verify_token'), 'text'); ?>
<?php if (!empty($lead_statuses)) { ?>
<?= render_select('settings[whatsapp_default_lead_status]', $lead_statuses, array('id', 'name'), 'whatsapp_default_lead_status', get_option('whatsapp_default_lead_status')); ?>
<?php } ?>
<button type="submit" class="btn btn-primary"><?= _l('submit'); ?></button>
</div>
<div class="col-md-6">
<div class="alert alert-info">
<strong><?= _l('whatsapp_webhook_url'); ?>:</strong><br />
<code><?= site_url('leadgen_whatsapp/webhook'); ?></code>
<hr />
<?= _l('whatsapp_webhook_hint'); ?>
</div>
</div>
</div>
<?php echo form_close(); ?>
</div></div>
<?php init_tail(); ?>
</code></strong>