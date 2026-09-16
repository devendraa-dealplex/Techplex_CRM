<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_event — our own conversion event, and the flag that keeps the
 * untested one switched off.
 *
 * DECISION 2, IMPLEMENTED
 * -----------------------
 *   - Do NOT fire the existing `lead_created` hook yet.
 *   - Keep it behind an Admin-controlled feature flag, default OFF.
 *   - Create and test a dedicated Lead Finder conversion event first.
 *   - Activate the existing pipeline only after separate staging UAT.
 *
 * WHY A SEPARATE EVENT RATHER THAN "FIRE THE OLD ONE LATER"
 * --------------------------------------------------------
 * `lead_created` is fired by Perfex core, by the CSV importer, by web-to-lead
 * forms and by `Cron_model`. Every listener on it was written expecting those
 * shapes. A Lead Finder conversion is a different animal — it arrives with a
 * Google place id, a verified call outcome and an employee who vouched for it —
 * and listeners have never seen one.
 *
 * `payplex_lf_lead_converted` is therefore a new name with a documented
 * payload. A listener subscribing to it is opting in to this shape. Nothing
 * that exists today receives it, which is the point: the first release can be
 * observed without changing what any current listener does.
 *
 * THE FLAG FAILS OFF, IN EVERY DIRECTION
 * --------------------------------------
 * Missing config is OFF. An unparseable value is OFF. A truthy-looking string
 * that is not an explicit enable is OFF. The only thing that turns it on is an
 * exact recorded value, plus a recorded UAT date — because the approval was
 * "after separate staging UAT", and a flag that can be flipped without evidence
 * that the UAT happened is not the control that was approved.
 */
class Leadfinder_event
{
    /** Our event. Nothing in the CRM listens to it today, by design. */
    const LF_CONVERTED = 'payplex_lf_lead_converted';

    /** The existing core event we are deliberately NOT firing yet. */
    const LEGACY_LEAD_CREATED = 'lead_created';

    const OFF_NO_CONFIG   = 'flag_absent_defaults_off';
    const OFF_NOT_ENABLED = 'flag_not_explicitly_enabled';
    const OFF_NO_UAT      = 'no_recorded_staging_uat_date';
    const ON              = 'enabled_after_recorded_uat';

    /** The exact value that means on. Anything else, including 1/true/yes, is off. */
    const ENABLE_TOKEN = 'enabled_after_uat';

    /**
     * Should the legacy `lead_created` hook be fired for a Lead Finder conversion?
     *
     * @param array $cfg legacy_lead_created_hook, legacy_hook_uat_passed_on
     * @return array fire (bool), reason
     */
    public static function shouldFireLegacyHook(array $cfg)
    {
        $flag = isset($cfg['legacy_lead_created_hook']) ? $cfg['legacy_lead_created_hook'] : null;
        if ($flag === null || $flag === '') { return self::no(self::OFF_NO_CONFIG); }

        /*
         * Strict equality against one token. `(bool) '0'` is false but
         * `(bool) 'off'` is TRUE, so a truthiness test here would switch the
         * pipeline on for an administrator who typed the word "off".
         */
        if (!is_string($flag) || $flag !== self::ENABLE_TOKEN) {
            return self::no(self::OFF_NOT_ENABLED);
        }

        /* The approval was "after separate staging UAT". The flag alone is not
           that; a recorded date is. */
        $uat = isset($cfg['legacy_hook_uat_passed_on']) ? trim((string) $cfg['legacy_hook_uat_passed_on']) : '';
        if ($uat === '' || !preg_match('/^\d{4}-\d{2}-\d{2}\z/', $uat)) {
            return self::no(self::OFF_NO_UAT);
        }

        return array('fire' => true, 'reason' => self::ON, 'uat_on' => $uat);
    }

    /** True while the legacy pipeline has never been activated. A test pins it. */
    public static function legacyHookDefaultsOff() { return true; }

    /**
     * The payload of our own event.
     *
     * Built from the retention-filtered conversion payload, so the event cannot
     * become a side channel that carries coordinates into a listener's log
     * after §14.3 kept them out of the lead itself.
     *
     * @param array $prospect  the prospect row
     * @param int   $leadId    the lead that was created
     * @param array $allowed   Leadfinder_retention::conversionPayload($prospect)
     */
    public static function conversionPayload(array $prospect, $leadId, array $allowed, $actorId, $now)
    {
        return array(
            'event'       => self::LF_CONVERTED,
            'version'     => 1,
            'lead_id'     => (int) $leadId,
            'prospect_id' => isset($prospect['id']) ? (int) $prospect['id'] : 0,
            'converted_by'=> (int) $actorId,
            'at'          => (int) $now,
            /* Only what the retention allow-list already passed. Never the row. */
            'fields'      => $allowed,
            'source'      => 'payplex_leadfinder',
        );
    }

    /**
     * Fields that must never appear in an event payload, whatever the caller
     * passes. Checked rather than trusted: the whole reason the event exists is
     * that nobody has seen what listeners do with it.
     */
    public static function forbiddenInPayload()
    {
        return array('latitude', 'longitude', 'google_maps_uri', 'address',
                     'api_key', 'api_key_enc', 'phone_raw');
    }

    /** @return array ok, offending — the payload's own guard, callable in tests and at runtime. */
    public static function validatePayload(array $payload)
    {
        $bad = array();
        $walk = function ($node) use (&$walk, &$bad) {
            foreach ($node as $k => $v) {
                if (is_string($k) && in_array($k, self::forbiddenInPayload(), true)) { $bad[] = $k; }
                if (is_array($v)) { $walk($v); }
            }
        };
        $walk($payload);
        return array('ok' => count($bad) === 0, 'offending' => array_values(array_unique($bad)));
    }

    private static function no($reason)
    {
        return array('fire' => false, 'reason' => $reason, 'uat_on' => '');
    }
}
