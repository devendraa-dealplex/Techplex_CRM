<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_call_timezone — whose clock the calling window is measured against.
 *
 * The approved rule is "9:00 AM to 7:00 PM in the RECIPIENT's local time"
 * (master prompt §9). An earlier decision said 8:00 AM; the enforced constant
 * moved to 9 and this line did not, which in a module about when you may
 * lawfully telephone someone is not a harmless stale comment.
 * That is a different requirement from the one previously built, which applied
 * one configured timezone to every call. A single zone is correct only while
 * every lead happens to sit in it, and silently wrong the moment one does not.
 *
 * ---------------------------------------------------------------------------
 * HOW THE ZONE IS DETERMINED
 * ---------------------------------------------------------------------------
 * From the lead's country, through PHP's own copy of the IANA timezone
 * database — not a hand-written table. DateTimeZone::listIdentifiers() with
 * PER_COUNTRY returns the zones a country actually uses, so:
 *
 *   - one zone returned  -> that is the recipient's local time, derived rather
 *                           than guessed (India returns Asia/Kolkata alone)
 *   - several returned   -> the country alone cannot answer the question. The
 *                           United States has six; picking one would mean
 *                           calling a Californian at six in the morning while
 *                           believing the window was honoured.
 *   - none, or no country-> nothing to derive from.
 *
 * A hand-maintained country->zone map would have been quicker and would have
 * rotted: zones change, countries split, and nobody would notice until someone
 * was called at dawn.
 *
 * ---------------------------------------------------------------------------
 * WHEN IT CANNOT BE DETERMINED
 * ---------------------------------------------------------------------------
 * Refuse. If you do not know where someone is, you cannot know whether it is
 * 9am for them, and a calling window you cannot evaluate is not one you are
 * honouring. An administrator may configure an explicit fallback zone to be
 * used when a lead's country is unknown, but that fallback ships EMPTY and must
 * be chosen deliberately — it is a decision about whom you are willing to risk
 * calling at the wrong hour, not a default anyone should inherit.
 */
class Payplex_call_timezone
{
    /**
     * Zones a country uses, from the IANA database PHP ships with.
     *
     * @param string $iso2 two-letter country code
     * @return array
     */
    public static function zonesForCountry($iso2)
    {
        $code = strtoupper(trim((string) $iso2));
        if (!preg_match('/^[A-Z]{2}$/', $code)) { return array(); }

        // listIdentifiers() emits a warning and returns false for a code the
        // database does not know, so the result is normalised here.
        $zones = @DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $code);
        return is_array($zones) ? array_values($zones) : array();
    }

    /**
     * Resolve the timezone a call to this lead must be judged in.
     *
     * @param array $lead    with country_iso2 resolved by the caller
     * @param array $cfg     fallback_timezone (may be empty)
     * @return array timezone, source, determinable, reason
     */
    public static function forLead($lead, $cfg = array())
    {
        $l = (array) $lead;
        $iso = isset($l['country_iso2']) ? strtoupper(trim((string) $l['country_iso2'])) : '';

        if ($iso !== '') {
            $zones = self::zonesForCountry($iso);

            if (count($zones) === 1) {
                return array(
                    'timezone'     => $zones[0],
                    'source'       => 'lead_country',
                    'determinable' => true,
                    'reason'       => '',
                );
            }
            if (count($zones) > 1) {
                $res = self::fallback($cfg);
                $res['reason'] = 'The lead\'s country (' . $iso . ') spans ' . count($zones)
                    . ' timezones, so the country alone cannot say what time it is for them.'
                    . ($res['determinable'] ? ' The configured fallback was used.' : '');
                if ($res['determinable']) { $res['source'] = 'fallback_ambiguous_country'; }
                return $res;
            }
            $res = self::fallback($cfg);
            $res['reason'] = 'The timezone database does not recognise country code "' . $iso . '".'
                . ($res['determinable'] ? ' The configured fallback was used.' : '');
            return $res;
        }

        $res = self::fallback($cfg);
        $res['reason'] = 'This lead has no country recorded, so there is nothing to derive a '
            . 'local time from.' . ($res['determinable'] ? ' The configured fallback was used.' : '');
        return $res;
    }

    /** The administrator's explicit fallback, or a refusal. */
    private static function fallback($cfg)
    {
        $tz = isset($cfg['fallback_timezone']) ? trim((string) $cfg['fallback_timezone']) : '';
        if ($tz === '') {
            return array('timezone' => '', 'source' => 'none', 'determinable' => false, 'reason' => '');
        }
        try {
            new DateTimeZone($tz);
        } catch (Exception $e) {
            return array('timezone' => '', 'source' => 'none', 'determinable' => false, 'reason' => '');
        }
        return array('timezone' => $tz, 'source' => 'fallback', 'determinable' => true, 'reason' => '');
    }

    /**
     * How many leads a given set could and could not be resolved for.
     * Used on the settings screen so an administrator can see the exposure
     * before enabling calling, rather than discovering it one refusal at a time.
     *
     * @param array $isoCounts country iso2 => number of leads
     */
    public static function coverage($isoCounts, $cfg = array())
    {
        $out = array('resolved' => 0, 'ambiguous' => 0, 'unknown' => 0,
                     'by_zone' => array(), 'ambiguous_countries' => array());

        foreach ((array) $isoCounts as $iso => $count) {
            $count = (int) $count;
            $iso = strtoupper(trim((string) $iso));
            if ($iso === '') { $out['unknown'] += $count; continue; }

            $zones = self::zonesForCountry($iso);
            if (count($zones) === 1) {
                $out['resolved'] += $count;
                $z = $zones[0];
                $out['by_zone'][$z] = (isset($out['by_zone'][$z]) ? $out['by_zone'][$z] : 0) + $count;
            } elseif (count($zones) > 1) {
                $out['ambiguous'] += $count;
                $out['ambiguous_countries'][$iso] = count($zones);
            } else {
                $out['unknown'] += $count;
            }
        }

        $fb = self::fallback($cfg);
        $out['fallback'] = $fb['determinable'] ? $fb['timezone'] : null;
        // Without a fallback, anything not resolved is a call that will refuse.
        $out['would_refuse'] = $fb['determinable'] ? 0 : ($out['ambiguous'] + $out['unknown']);
        return $out;
    }
}
