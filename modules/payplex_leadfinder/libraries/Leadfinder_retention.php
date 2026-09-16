<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_retention — Google's caching rules, per field, as verified.
 *
 * WHAT WAS VERIFIED, AND WHY IT CHANGED THE DESIGN
 * -----------------------------------------------
 * Phase 1 modelled retention as one number: `places_cache_max_age_seconds`,
 * applied to a whole cached prospect. That model is wrong, and it was seeded
 * blank precisely because it had not been checked.
 *
 * Checked on 2026-09-12 against the Google Maps Platform Service Specific
 * Terms, §14 "Places API (Legacy and New)":
 *
 *   14.3 Caching — "Customer may temporarily cache latitude and longitude
 *        values from the Places API for up to 30 consecutive calendar days,
 *        after which Customer must delete the cached latitude and longitude
 *        values."
 *
 *   §3 Google ID Caching — "Customer may cache the Google ID values from the
 *        Services that return such field... For example, Customer may cache
 *        (a) place_id from Places API..."
 *
 *   Places API policies page — "the place ID... is exempt from the caching
 *        restrictions. You can therefore store place ID values indefinitely."
 *
 *   14.2 No use with a non-Google map — "Customer must not use Google Maps
 *        Content from the Places API in conjunction with a non-Google map."
 *
 * So the permission is NOT "30 days for Places content". It is:
 *
 *   place_id          → indefinite
 *   latitude/longitude → 30 consecutive calendar days, then DELETE
 *   everything else    → no general caching permission is granted in §14;
 *                        treated here as retained only while it is being
 *                        actively worked, and purged with the prospect.
 *
 * A single global age would have been wrong in both directions at once: too
 * permissive for coordinates, and meaningless for the place ID it would have
 * expired along with them.
 *
 * CALENDAR DAYS, NOT 2 592 000 SECONDS
 * ------------------------------------
 * The clause says "consecutive calendar days". Counting in seconds drifts by an
 * hour across a DST boundary, which on day 30 is the difference between
 * compliant and not. Day counting is done on dates.
 */
class Leadfinder_retention
{
    /** Verified 2026-09-12 — Service Specific Terms §14.3. */
    const COORD_MAX_CALENDAR_DAYS = 30;

    const F_PERMANENT = 'permanent';
    const F_COORDS    = 'coordinates_30_calendar_days';
    const F_WORKING   = 'while_actively_worked';
    const F_OURS      = 'staff_verified_not_google_content';

    /**
     * Which rule governs each column of a prospect row.
     *
     * Stated as data so the purge job and the tests read the same list, and so
     * a new column added without a retention decision shows up as unclassified
     * rather than silently inheriting "keep for ever".
     */
    public static function fieldRules()
    {
        return array(
            /* Exempt. Google says indefinitely, explicitly. */
            'google_place_id'   => self::F_PERMANENT,

            /* §14.3, the only field-specific caching permission Places grants. */
            'latitude'          => self::F_COORDS,
            'longitude'         => self::F_COORDS,

            /* Google Maps Content with no standing caching grant in §14. Held
               only while the prospect is being worked, purged with it. */
            'business_name'     => self::F_WORKING,
            'category'          => self::F_WORKING,
            'address'           => self::F_WORKING,
            'city'              => self::F_WORKING,
            'state'             => self::F_WORKING,
            'pin_code'          => self::F_WORKING,
            'google_maps_uri'   => self::F_WORKING,
            'business_status'   => self::F_WORKING,
            'phone_raw'         => self::F_WORKING,
            'website'           => self::F_WORKING,

            /* Ours. Entered or confirmed by an employee, not Google Maps
               Content, and not subject to Google's retention rules at all.
               §18's "separate Google-provided from employee-verified data" is
               this column list, not a label on a screen. */
            'verified_email'         => self::F_OURS,
            'verified_contact_name'  => self::F_OURS,
            'verified_designation'   => self::F_OURS,
            'phone_e164'             => self::F_OURS,
            'phone_weak_key'         => self::F_OURS,
            'website_domain'         => self::F_OURS,
            'verification_notes'     => self::F_OURS,
            'call_disposition'       => self::F_OURS,
            'interest_level'         => self::F_OURS,
        );
    }

    /** Columns that must be cleared once the coordinate window expires. */
    public static function coordinateFields()
    {
        return array_keys(array_filter(self::fieldRules(), function ($r) {
            return $r === self::F_COORDS;
        }));
    }

    /** Columns an employee produced, which survive a Google-content purge. */
    public static function staffVerifiedFields()
    {
        return array_keys(array_filter(self::fieldRules(), function ($r) {
            return $r === self::F_OURS;
        }));
    }

    /**
     * Whole calendar days elapsed between two unix timestamps, in $tz.
     *
     * Dates, not seconds: "30 consecutive calendar days" straddling a DST
     * change is 30 days and either 719 or 721 hours, and a seconds-based
     * comparison gets day 30 wrong in one direction every spring.
     */
    public static function calendarDaysBetween($fromTs, $toTs, $tz = 'UTC')
    {
        $from = new DateTime('@' . (int) $fromTs);
        $to   = new DateTime('@' . (int) $toTs);
        $zone = new DateTimeZone($tz);
        $from->setTimezone($zone);
        $to->setTimezone($zone);
        $a = new DateTime($from->format('Y-m-d'), $zone);
        $b = new DateTime($to->format('Y-m-d'), $zone);
        return (int) $a->diff($b)->days * ($b < $a ? -1 : 1);
    }

    /**
     * Must this row's coordinates be deleted now?
     *
     * @param array  $p  prospect row — google_fetched_at, latitude, longitude
     * @param int    $now
     */
    /**
     * @param int|null $maxDays the configured window; defaults to the 30-day
     *                          term. Passed in by the sweep so the setting an
     *                          administrator can change and the rule the code
     *                          applies are the same number — they were two
     *                          before, and a shortened window would have been
     *                          accepted and ignored.
     */
    public static function coordinatesExpired(array $p, $now, $tz = 'UTC', $maxDays = null)
    {
        if (!self::hasCoordinates($p)) { return false; }

        $limit = $maxDays === null ? self::COORD_MAX_CALENDAR_DAYS : (int) $maxDays;

        /* A zero or negative window would expire everything the moment it was
           fetched. That is a misconfiguration, not an instruction. */
        if ($limit < 1) { $limit = self::COORD_MAX_CALENDAR_DAYS; }

        $fetched = isset($p['google_fetched_at']) ? (int) $p['google_fetched_at'] : 0;
        if ($fetched <= 0) {
            /* Coordinates with no recorded fetch time cannot be shown to be
               inside the window, so they are outside it. Keeping them on the
               grounds that we failed to record when we got them is the wrong
               way round. */
            return true;
        }
        return self::calendarDaysBetween($fetched, $now, $tz) >= $limit;
    }

    /** Calendar days left before coordinates must go, or null if already gone. */
    public static function coordinateDaysRemaining(array $p, $now, $tz = 'UTC')
    {
        if (!self::hasCoordinates($p)) { return null; }
        $fetched = isset($p['google_fetched_at']) ? (int) $p['google_fetched_at'] : 0;
        if ($fetched <= 0) { return 0; }
        $used = self::calendarDaysBetween($fetched, $now, $tz);
        return max(0, self::COORD_MAX_CALENDAR_DAYS - $used);
    }

    /**
     * The update a purge job applies to one expired row.
     *
     * Returns column => null for the coordinate fields and nothing else. The
     * place ID stays, the staff-verified work stays, and the row itself stays —
     * deleting the prospect would destroy an employee's verification work to
     * comply with a rule that only covers two numbers.
     */
    public static function expiryUpdate()
    {
        $u = array();
        foreach (self::coordinateFields() as $f) { $u[$f] = null; }
        $u['coords_purged_at'] = null;   // caller sets the timestamp
        return $u;
    }

    /**
     * What may be copied into a CRM lead at conversion.
     *
     * This is the load-bearing compliance decision in the whole module.
     * Converting copies data OUT of the retention-governed queue and INTO
     * `tblleads`, where nothing expires it. So Google Maps Content is not
     * copied — except the place ID, which is explicitly exempt — and the lead
     * is populated from what the employee verified on the call.
     *
     * The business name is the one judgement call: it is carried because a lead
     * with no name is unusable, and because the employee confirms the name
     * during verification, which makes the stored value theirs rather than a
     * cached copy of Google's. That reasoning is recorded here rather than left
     * implicit, because it is the part a reviewer should challenge.
     */
    public static function conversionPayloadRules()
    {
        return array(
            'copy'       => array('google_place_id', 'phone_e164', 'verified_email',
                                  'verified_contact_name', 'verified_designation',
                                  'website_domain', 'verification_notes', 'business_name'),
            'never_copy' => array('latitude', 'longitude', 'google_maps_uri', 'address',
                                  'category', 'business_status', 'phone_raw', 'website'),
        );
    }

    /**
     * Filter a prospect row down to what may cross into a CRM lead.
     * Anything not on the copy list is dropped, including keys nobody listed —
     * an unknown column defaults to "do not copy".
     */
    public static function conversionPayload(array $p)
    {
        $rules = self::conversionPayloadRules();
        $out   = array();
        foreach ($rules['copy'] as $f) {
            if (array_key_exists($f, $p) && $p[$f] !== null && $p[$f] !== '') {
                $out[$f] = $p[$f];
            }
        }
        return $out;
    }

    /**
     * §14.2 — Places content must not be shown with a non-Google map.
     *
     * The module renders no map at all, which is the simplest way to comply.
     * Stated as a checkable fact so a future map widget has to come past this.
     */
    public static function rendersNonGoogleMap() { return false; }

    /**
     * §18 / Google attribution, as verified on the Places policies page.
     *
     * Returned as data so the view cannot quietly drop a requirement: new
     * implementations use the words "Google Maps" (not "Google"), the text must
     * not be translated, wrapped or re-capitalised, and it carries a minimum
     * size and an accessible contrast.
     */
    public static function attribution()
    {
        return array(
            'text'          => 'Google Maps',
            'translate'     => 'no',      // HTML translate attribute
            'min_font_px'   => 12,
            'max_font_px'   => 16,
            'font_weight'   => 400,
            'allowed_colors' => array('#FFFFFF', '#1F1F1F', '#5E5E5E'),
            'must_not'      => array('localize', 'wrap_onto_multiple_lines',
                                     'change_capitalisation', 'obscure'),
            'source'        => 'Places API policies and attributions, checked 2026-09-12',
        );
    }

    private static function hasCoordinates(array $p)
    {
        foreach (self::coordinateFields() as $f) {
            if (isset($p[$f]) && $p[$f] !== null && $p[$f] !== '') { return true; }
        }
        return false;
    }
}
