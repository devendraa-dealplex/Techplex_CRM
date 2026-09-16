<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Compatibility + convenience helpers.
 *
 * Perfex has changed the name of several core helpers across versions (_l/app_lang,
 * has_permission/staff_can). Every call the module makes goes through these wrappers so a
 * version difference degrades gracefully instead of throwing a fatal error on activation.
 */

if (!function_exists('pm_lang')) {
    function pm_lang($key, $sprintf = null)
    {
        if (function_exists('app_lang')) {
            return app_lang($key, $sprintf);
        }
        if (function_exists('_l')) {
            return _l($key, $sprintf);
        }

        return $key;
    }
}

if (!function_exists('pm_can')) {
    /**
     * Capability check against the payplex_meetings feature.
     * Administrators always pass. Anything unknown fails closed.
     */
    function pm_can($capability, $staff_id = null)
    {
        if (function_exists('is_admin') && is_admin($staff_id)) {
            return true;
        }

        if (function_exists('staff_can')) {
            return (bool) staff_can($capability, 'payplex_meetings', $staff_id);
        }

        if (function_exists('has_permission')) {
            return (bool) has_permission('payplex_meetings', $staff_id, $capability);
        }

        return false;
    }
}

if (!function_exists('pm_table')) {
    function pm_table($name)
    {
        $prefix = function_exists('db_prefix') ? db_prefix() : 'tbl';

        return $prefix . 'payplex_meeting_' . $name;
    }
}

if (!function_exists('pm_meetings_table')) {
    function pm_meetings_table()
    {
        $prefix = function_exists('db_prefix') ? db_prefix() : 'tbl';

        return $prefix . 'payplex_meetings';
    }
}

if (!function_exists('pm_setting')) {
    /**
     * Module settings are stored as regular Perfex options under a pm_ prefix.
     */
    function pm_setting($key, $default = null)
    {
        $value = function_exists('get_option') ? get_option('pm_' . $key) : '';

        return ($value === '' || $value === null) ? $default : $value;
    }
}

if (!function_exists('pm_timezone')) {
    function pm_timezone()
    {
        return pm_setting('default_timezone', 'Asia/Kolkata');
    }
}

if (!function_exists('pm_to_utc')) {
    /**
     * Convert a local wall-clock string in $tz to a UTC 'Y-m-d H:i:s' string.
     * Everything in the schema is stored UTC; the meeting's own timezone renders it back.
     */
    function pm_to_utc($localDateTime, $tz = null)
    {
        $tz = $tz ?: pm_timezone();

        try {
            $dt = new DateTime($localDateTime, new DateTimeZone($tz));
            $dt->setTimezone(new DateTimeZone('UTC'));

            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('pm_from_utc')) {
    function pm_from_utc($utcDateTime, $tz = null, $format = 'Y-m-d H:i:s')
    {
        if (empty($utcDateTime)) {
            return '';
        }
        $tz = $tz ?: pm_timezone();

        try {
            $dt = new DateTime($utcDateTime, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($tz));

            return $dt->format($format);
        } catch (Exception $e) {
            return '';
        }
    }
}

if (!function_exists('pm_statuses')) {
    function pm_statuses()
    {
        return [
            'scheduled'    => ['label' => 'pm_status_scheduled',   'color' => '#3b7dd8'],
            'confirmed'    => ['label' => 'pm_status_confirmed',   'color' => '#2f7d5d'],
            'rescheduled'  => ['label' => 'pm_status_rescheduled', 'color' => '#8f6410'],
            'in_progress'  => ['label' => 'pm_status_in_progress', 'color' => '#6f42c1'],
            'completed'    => ['label' => 'pm_status_completed',   'color' => '#1f7a4d'],
            'cancelled'    => ['label' => 'pm_status_cancelled',   'color' => '#8a8f98'],
            'no_show'      => ['label' => 'pm_status_no_show',     'color' => '#a81f16'],
        ];
    }
}

if (!function_exists('pm_meeting_types')) {
    function pm_meeting_types()
    {
        return [
            'online', 'phone_call', 'office', 'client_site', 'demo', 'sales',
            'technical', 'onboarding', 'support', 'payment_followup',
            'partnership', 'interview', 'other',
        ];
    }
}

if (!function_exists('pm_platforms')) {
    function pm_platforms()
    {
        return ['google_meet', 'ms_teams', 'zoom', 'custom_link', 'phone', 'physical'];
    }
}

if (!function_exists('pm_reference_no')) {
    function pm_reference_no($id)
    {
        return 'MTG-' . date('Y') . '-' . str_pad((int) $id, 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('pm_client_ip')) {
    function pm_client_ip()
    {
        $CI = &get_instance();

        return $CI->input->ip_address();
    }
}
