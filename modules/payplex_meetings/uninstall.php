<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Deactivation cleanup.
 *
 * Deliberately conservative: meeting history, summaries, delivery logs and the audit trail
 * are NOT dropped when the module is deactivated. Losing that on a mis-click would be
 * unrecoverable, and an audit trail that a deactivation can erase is not an audit trail.
 *
 * To drop the schema, set the option pm_allow_destructive_uninstall to 1 first, in the
 * database, deliberately. Take a backup before you do.
 */

$CI = &get_instance();

if (function_exists('get_option') && get_option('pm_allow_destructive_uninstall') === '1') {
    $prefix = function_exists('db_prefix') ? db_prefix() : 'tbl';

    $tables = [
        'payplex_meeting_activity_logs',
        'payplex_meeting_email_logs',
        'payplex_meeting_integrations',
        'payplex_meeting_attachments',
        'payplex_meeting_notes',
        'payplex_meeting_action_items',
        'payplex_meeting_summaries',
        'payplex_meeting_reminders',
        'payplex_meeting_participants',
        'payplex_meetings',
    ];

    foreach ($tables as $t) {
        $CI->db->query('DROP TABLE IF EXISTS `' . $prefix . $t . '`');
    }

    $CI->db->like('name', 'pm_', 'after')->delete($prefix . 'options');
}
