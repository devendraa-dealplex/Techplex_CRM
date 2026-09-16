<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_call_gates — the fail-closed preconditions for placing a call (§4.3).
 *
 * Pure and framework-independent, so every refusal can be tested without a
 * database. The gates decide whether a real person's phone rings, so the tests
 * that matter are the ones proving each gate REFUSES.
 *
 * ---------------------------------------------------------------------------
 * DEFECT THIS REPLACES
 * ---------------------------------------------------------------------------
 * The DND check read `$lead->dnd` — a column on Perfex's tblleads — while the
 * module's own consent UI writes DND to `payplex_consent.dnd`. The check was:
 *
 *     return isset($lead->dnd) ? (int) $lead->dnd === 1 : false;
 *
 * With no such column on the lead, isset() is false and the function returned
 * FALSE, meaning "not on DND", meaning every call was permitted. The comment
 * immediately above it read "default fail-closed if unknown flag" while the code
 * did precisely the opposite. Toggling DND in the module's own screen had no
 * effect on whether a call could be placed.
 *
 * A gate that cannot refuse is not a gate. DND now comes from the consent ledger
 * — the same row the UI writes — and an absent or unreadable flag REFUSES.
 *
 * ---------------------------------------------------------------------------
 * THE RULE THROUGHOUT
 * ---------------------------------------------------------------------------
 * Unknown means no. Every gate here treats missing, malformed or unreadable
 * information as a refusal, because the cost of wrongly refusing a call is a
 * delayed sales conversation, and the cost of wrongly permitting one is calling
 * someone who told you not to.
 */
class Payplex_call_gates
{
    /**
     * The permitted calling window when none is configured: 09:00–19:00 in the
     * RECIPIENT's local time, per §9 of the approved specification.
     *
     * Three places disagreed about this. The gate defaulted to 9–20, the
     * settings screen offered 8–19 when the option was unset, and the stored
     * option said 8–19. So the answer to "when may this system call someone?"
     * depended on which file you asked — and the only one that actually decides
     * is this constant, which nobody had reason to look at because the settings
     * screen appeared to be the authority.
     *
     * That is the same defect as the secret whose settings badge measured a
     * different thing from the control it described: a displayed value that is
     * not the operative one is worse than no value, because it is believed.
     * The view now reads these constants rather than repeating a number.
     */
    const DEFAULT_START_HOUR = 9;
    const DEFAULT_END_HOUR   = 19;

    public static function reasons()
    {
        return array(
            'lead_not_found'   => 'Lead not found.',
            'not_owner'        => 'You can only call leads assigned to you.',
            'consent_missing'  => 'This lead has no valid calling consent.',
            'consent_withdrawn'=> 'This lead withdrew calling consent.',
            'dnd_blocked'      => 'This number is on the Do-Not-Disturb list.',
            'dnd_unknown'      => 'Do-Not-Disturb status could not be determined, so the call is refused.',
            'outside_hours'    => 'Calls are only allowed within permitted calling hours.',
            'hours_unknown'    => 'The permitted calling window could not be determined, so the call is refused.',
            'no_actor'         => 'The calling staff member could not be identified.',
            'limits_unknown'   => 'The calling limits could not be determined, so the call is refused.',
        );
    }

    public static function message($code)
    {
        $m = self::reasons();
        if (isset($m[(string) $code])) { return $m[(string) $code]; }

        // §4.4 frequency, suppression and budget codes are worded by their own
        // library. Routing through here means a caller never has to know which
        // half of the gate refused, and a new code cannot reach the user
        // unworded.
        if (class_exists('Payplex_call_limits')) {
            $l = Payplex_call_limits::reasons();
            if (isset($l[(string) $code])) { return $l[(string) $code]; }
        }
        return 'This call is not permitted.';
    }

    /* ---------------- consent ---------------- */

    /**
     * Consent state from the ledger's latest row for this subject and channel.
     *
     * Returns 'granted', 'withdrawn' or 'missing'. Absence is never consent.
     *
     * @param array|null $latest the newest ledger row, or null when none exists
     */
    public static function consentState($latest)
    {
        if ($latest === null || $latest === false) { return 'missing'; }
        $r = (array) $latest;
        $state = strtolower(trim((string) (isset($r['state']) ? $r['state'] : '')));
        if ($state === 'granted')   { return 'granted'; }
        if ($state === 'withdrawn') { return 'withdrawn'; }
        return 'missing';
    }

    /* ---------------- DND ---------------- */

    /**
     * Is this subject on DND?
     *
     * Reads the consent ledger row the UI actually writes. Returns true (blocked)
     * when the flag cannot be read at all — the previous implementation returned
     * "not on DND" in that case and silently permitted everything.
     *
     * @return array blocked (bool), code
     */
    public static function dndState($latest)
    {
        if ($latest === null || $latest === false) {
            // No ledger row at all. Consent is missing too, so this is caught
            // earlier, but on its own an unknown DND status must still refuse.
            return array('blocked' => true, 'code' => 'dnd_unknown');
        }

        $r = (array) $latest;
        if (!array_key_exists('dnd', $r) || $r['dnd'] === null || $r['dnd'] === '') {
            return array('blocked' => true, 'code' => 'dnd_unknown');
        }
        if (!is_numeric($r['dnd']) && !is_bool($r['dnd'])) {
            return array('blocked' => true, 'code' => 'dnd_unknown');
        }
        return (int) $r['dnd'] === 1
            ? array('blocked' => true, 'code' => 'dnd_blocked')
            : array('blocked' => false, 'code' => 'ok');
    }

    /* ---------------- calling hours ---------------- */

    /**
     * Is the moment inside the permitted window, in the SUBJECT's timezone?
     *
     * The previous implementation used date('G'), the server's timezone. On a
     * UTC server a 09:00–20:00 window is 14:30–01:30 in India: calls blocked at
     * 9am local and permitted at 1am local, which inverts the regulation it was
     * meant to enforce. The zone is therefore explicit, and an unusable zone
     * refuses rather than falling back to the server's.
     *
     * @param string      $timezone  e.g. 'Asia/Kolkata'
     * @param int|null    $startHour inclusive
     * @param int|null    $endHour   exclusive
     * @param int|null    $now       unix time, for testing
     * @return array allowed, code, local_hour
     */
    public static function withinCallingHours($timezone, $startHour = null, $endHour = null, $now = null)
    {
        $start = $startHour === null || $startHour === '' ? self::DEFAULT_START_HOUR : (int) $startHour;
        $end   = $endHour   === null || $endHour   === '' ? self::DEFAULT_END_HOUR   : (int) $endHour;

        if ($start < 0 || $start > 23 || $end < 1 || $end > 24 || $start >= $end) {
            return array('allowed' => false, 'code' => 'hours_unknown', 'local_hour' => null);
        }

        $tz = trim((string) $timezone);
        if ($tz === '') { return array('allowed' => false, 'code' => 'hours_unknown', 'local_hour' => null); }

        try {
            $zone = new DateTimeZone($tz);
        } catch (Exception $e) {
            return array('allowed' => false, 'code' => 'hours_unknown', 'local_hour' => null);
        }

        $ts = $now === null ? time() : (int) $now;
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone($zone);
        $h = (int) $dt->format('G');

        return $h >= $start && $h < $end
            ? array('allowed' => true,  'code' => 'ok',            'local_hour' => $h)
            : array('allowed' => false, 'code' => 'outside_hours', 'local_hour' => $h);
    }

    /* ---------------- ownership ---------------- */

    /**
     * May this staff member act on this lead?
     * An unassigned lead belongs to nobody, so only a view-all user may call it.
     */
    public static function ownsLead($lead, $actorId, $canViewAll = false)
    {
        $actorId = (int) $actorId;
        if ($actorId <= 0) { return false; }
        if ($canViewAll)   { return true; }

        $l = (array) $lead;
        if (!isset($l['assigned']) || !is_numeric($l['assigned'])) { return false; }
        $assigned = (int) $l['assigned'];
        return $assigned > 0 && $assigned === $actorId;
    }

    /* ---------------- the whole decision ---------------- */

    /**
     * Everything, in the order that refuses soonest and leaks least.
     *
     * @param array|null $lead
     * @param array|null $latestConsent newest payplex_consent row for this lead+channel
     * @param array      $ctx actor_id, can_view_all, timezone, start_hour, end_hour, now
     *
     * @return array allowed, code, message
     */
    public static function evaluate($lead, $latestConsent, $ctx = array())
    {
        $deny = function ($code) {
            return array('allowed' => false, 'code' => $code, 'message' => self::message($code));
        };

        if ($lead === null || $lead === false || !is_array((array) $lead) || !(array) $lead) {
            return $deny('lead_not_found');
        }

        $actorId = (int) (isset($ctx['actor_id']) ? $ctx['actor_id'] : 0);
        if ($actorId <= 0) { return $deny('no_actor'); }

        if (!self::ownsLead($lead, $actorId, !empty($ctx['can_view_all']))) {
            return $deny('not_owner');
        }

        $consent = self::consentState($latestConsent);
        if ($consent === 'withdrawn') { return $deny('consent_withdrawn'); }
        if ($consent !== 'granted')   { return $deny('consent_missing'); }

        $dnd = self::dndState($latestConsent);
        if ($dnd['blocked']) { return $deny($dnd['code']); }

        $hours = self::withinCallingHours(
            isset($ctx['timezone']) ? $ctx['timezone'] : '',
            isset($ctx['start_hour']) ? $ctx['start_hour'] : null,
            isset($ctx['end_hour']) ? $ctx['end_hour'] : null,
            isset($ctx['now']) ? $ctx['now'] : null
        );
        if (!$hours['allowed']) { return $deny($hours['code']); }

        /*
         * §4.4 — frequency, suppression and cost. Composed here rather than
         * called separately by the controller, so that "may this call be
         * placed?" has exactly one answer in exactly one place. The consent
         * model once carried a second, divergent copy of the consent check and
         * the controller quietly used neither; that is not repeated.
         *
         * Skipped only when the caller passes no limits context at all, which
         * is what the pure gate tests do.
         */
        if (array_key_exists('limits', $ctx) && $ctx['limits'] !== null) {
            if (!class_exists('Payplex_call_limits')) {
                // The limits library is expected. Its absence means the call is
                // unbounded in frequency and cost, which is not a state to
                // proceed from.
                return $deny('limits_unknown');
            }
            $lim = (array) $ctx['limits'];
            $res = Payplex_call_limits::evaluate($lead, isset($lim['history']) ? $lim['history'] : array(), $lim);
            if ($res['allowed'] !== true) {
                $out = $deny($res['code']);
                $out['detail'] = isset($res['detail']) ? $res['detail'] : array();
                return $out;
            }
            return array('allowed' => true, 'code' => 'ok', 'message' => '',
                         'detail' => isset($res['detail']) ? $res['detail'] : array());
        }

        return array('allowed' => true, 'code' => 'ok', 'message' => '');
    }
}
