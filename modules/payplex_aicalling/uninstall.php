<?php
defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Uninstall — deliberately conservative. We DO NOT drop data tables on uninstall
 * (call history, audit log, consent are retention-sensitive). An admin who truly
 * wants them gone runs the documented purge script manually. Only options are removed.
 */
$CI = &get_instance();
$options = [
    'payplex_aicalling_base_url','payplex_aicalling_service_jwt','payplex_aicalling_request_secret',
    'payplex_aicalling_webhook_secret','payplex_aicalling_timeout_connect','payplex_aicalling_timeout_read',
    'payplex_aicalling_enabled',
];
foreach ($options as $o) {
    delete_option($o);
}
