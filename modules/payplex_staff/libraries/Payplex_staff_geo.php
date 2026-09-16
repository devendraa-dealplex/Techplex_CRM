<?php

defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Payplex_staff_geo
 *
 * Geospatial rules for field tracking (spec section 6): coordinate validation,
 * distance, session aggregation and the retention purge boundary.
 *
 * Pure and dependency-free — no database, no CI. Everything here is a function of
 * its inputs so the retention and distance rules can be tested exhaustively.
 *
 * Two design decisions worth stating, because they are the ones that protect the
 * company from paying out on bad data:
 *
 *  - Distance is computed by discarding implausible jumps rather than trusting
 *    every point. A GPS fix that teleports 40 km in 30 seconds is noise (or
 *    spoofing), and silently adding it to a TA-DA claim would inflate a payout.
 *  - Aggregates are computed BEFORE the purge and stored on the session, so the
 *    90-day deletion of raw points can never change a distance figure that an
 *    expense claim was already based on.
 */
class Payplex_staff_geo
{
    /** Raw GPS points are deleted after this many days (approved policy). */
    const RETENTION_DAYS = 90;

    /**
     * Which revision of the distance rules produced a stored summary.
     *
     * A session's distance is frozen at check-out, on purpose: the 90-day purge
     * must never be able to change a figure an expense claim was based on. The
     * cost of that guarantee is that a CORRECTION to the distance rules also
     * never reaches a session that is already closed, and nothing on the row
     * said which rules had been applied.
     *
     * That is not hypothetical on this install. Session #1 was closed at
     * 18:19:50 and the zero-elapsed-time fix was committed ninety seconds
     * later. Its stored distance is 1,112 m; re-run through the current rules
     * the same four points yield 0 m with all three legs rejected as
     * impossible. Both numbers are "correct" for the engine that produced
     * them, and until now the row could not tell you which one it was.
     *
     *   1 — original: a leg with zero elapsed time was counted at face value.
     *   2 — current:  zero elapsed time over more than jitter is implausible.
     *
     * A stored 0 means "written before this was recorded", which is exactly
     * what the existing rows are.
     */
    const DISTANCE_ENGINE_VERSION = 2;

    /** Earth mean radius in metres. */
    const EARTH_RADIUS_M = 6371000;

    /** A fix worse than this is too vague to be evidence of a visit. */
    const MAX_ACCURACY_M = 500;

    /** Above this speed a leg is treated as GPS noise, not travel. */
    const MAX_PLAUSIBLE_SPEED_MPS = 55.0;   // ~198 km/h

    /** Below this, movement is jitter from a stationary device. */
    const MIN_LEG_DISTANCE_M = 15.0;

    /** Longest a session may stay open before it is considered abandoned. */
    const MAX_SESSION_HOURS = 16;

    public static function retentionDays()
    {
        return self::RETENTION_DAYS;
    }

    /* ------------------------------------------------------------------ *
     * Validation
     * ------------------------------------------------------------------ */

    public static function validLat($lat)
    {
        if ($lat === null || $lat === '' || !is_numeric($lat)) { return false; }
        $lat = (float) $lat;
        return $lat >= -90.0 && $lat <= 90.0;
    }

    public static function validLng($lng)
    {
        if ($lng === null || $lng === '' || !is_numeric($lng)) { return false; }
        $lng = (float) $lng;
        return $lng >= -180.0 && $lng <= 180.0;
    }

    /**
     * Validate a whole point. Returns array('ok'=>bool,'code'=>..,'reason'=>..).
     *
     * Null Island (0,0) is rejected on purpose: it is what a device reports when
     * it has no fix at all, and accepting it would draw a straight line from the
     * customer's premises to the Gulf of Guinea and back into a distance claim.
     */
    public static function validatePoint($p)
    {
        $p   = (array) $p;
        $lat = isset($p['lat']) ? $p['lat'] : null;
        $lng = isset($p['lng']) ? $p['lng'] : null;

        if (!self::validLat($lat)) {
            return array('ok' => false, 'code' => 'bad_lat', 'reason' => 'Latitude must be between -90 and 90.');
        }
        if (!self::validLng($lng)) {
            return array('ok' => false, 'code' => 'bad_lng', 'reason' => 'Longitude must be between -180 and 180.');
        }
        if (abs((float) $lat) < 0.00001 && abs((float) $lng) < 0.00001) {
            return array('ok' => false, 'code' => 'null_island', 'reason' => 'Coordinates (0,0) indicate the device had no GPS fix.');
        }
        if (isset($p['accuracy_m']) && $p['accuracy_m'] !== null && $p['accuracy_m'] !== '') {
            if (!is_numeric($p['accuracy_m']) || (float) $p['accuracy_m'] < 0) {
                return array('ok' => false, 'code' => 'bad_accuracy', 'reason' => 'Accuracy must be a positive number of metres.');
            }
            if ((float) $p['accuracy_m'] > self::MAX_ACCURACY_M) {
                return array('ok' => false, 'code' => 'inaccurate',
                    'reason' => 'GPS accuracy of ' . (int) $p['accuracy_m'] . 'm is worse than the ' . self::MAX_ACCURACY_M . 'm limit.');
            }
        }
        if (isset($p['captured_at']) && $p['captured_at'] !== null && $p['captured_at'] !== '') {
            $t = strtotime((string) $p['captured_at']);
            if ($t === false) {
                return array('ok' => false, 'code' => 'bad_time', 'reason' => 'captured_at is not a valid timestamp.');
            }
            // A point from the future is a clock problem or a forged claim.
            if ($t > time() + 300) {
                return array('ok' => false, 'code' => 'future_time', 'reason' => 'captured_at is in the future.');
            }
        }
        return array('ok' => true, 'code' => 'ok', 'reason' => '');
    }

    /* ------------------------------------------------------------------ *
     * Distance
     * ------------------------------------------------------------------ */

    /** Great-circle distance in metres between two coordinates. */
    public static function haversine($lat1, $lng1, $lat2, $lng2)
    {
        $lat1 = (float) $lat1; $lng1 = (float) $lng1;
        $lat2 = (float) $lat2; $lng2 = (float) $lng2;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);

        // clamp guards against a floating-point value marginally above 1
        $a = min(1.0, max(0.0, $a));
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_M * $c;
    }

    /**
     * Total travelled distance over an ordered list of points, discarding legs
     * that are jitter (too short) or implausible (too fast).
     *
     * @return array total_m, legs, skipped_jitter, skipped_implausible
     */
    public static function pathDistance($points)
    {
        $pts = self::sortByTime($points);
        $out = array('total_m' => 0.0, 'legs' => 0, 'skipped_jitter' => 0, 'skipped_implausible' => 0);
        $prev = null;

        foreach ($pts as $p) {
            $p = (array) $p;
            if (!self::validLat(isset($p['lat']) ? $p['lat'] : null) ||
                !self::validLng(isset($p['lng']) ? $p['lng'] : null)) {
                continue;
            }
            if ($prev === null) { $prev = $p; continue; }

            $d = self::haversine($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);

            if ($d < self::MIN_LEG_DISTANCE_M) {
                $out['skipped_jitter']++;
                // stationary jitter: keep the earlier fix as the anchor
                continue;
            }

            $dt = self::secondsBetween($prev, $p);
            // $dt === null means at least one timestamp was missing, so speed
            // cannot be judged and the leg is taken at face value. A $dt of
            // exactly 0 is different: both points ARE timestamped, to the same
            // second, yet sit more than a jitter apart. Nothing travels that far
            // in no time, so it is either a broken device clock or a spoofed
            // trail — either way it must not reach a TA-DA claim.
            if ($dt !== null && ($dt === 0 || ($d / $dt) > self::MAX_PLAUSIBLE_SPEED_MPS)) {
                $out['skipped_implausible']++;
                // Re-anchor so one bad fix costs us one leg, not the whole path.
                $prev = $p;
                continue;
            }

            $out['total_m'] += $d;
            $out['legs']++;
            $prev = $p;
        }

        $out['total_m'] = round($out['total_m'], 2);
        return $out;
    }

    /**
     * Seconds between two points, or null when either lacks a usable timestamp.
     * Null and zero mean different things to the plausibility check, so they are
     * deliberately not collapsed into the same return value.
     */
    private static function secondsBetween($a, $b)
    {
        $ta = isset($a['captured_at']) && $a['captured_at'] !== '' ? strtotime((string) $a['captured_at']) : false;
        $tb = isset($b['captured_at']) && $b['captured_at'] !== '' ? strtotime((string) $b['captured_at']) : false;
        if ($ta === false || $tb === false) { return null; }
        return abs($tb - $ta);
    }

    /** Order points by capture time; rows without a time keep their input order. */
    public static function sortByTime($points)
    {
        $pts = array();
        $i = 0;
        foreach ((array) $points as $p) {
            $p = (array) $p;
            $t = isset($p['captured_at']) ? strtotime((string) $p['captured_at']) : false;
            $pts[] = array('t' => $t === false ? 0 : $t, 'i' => $i++, 'p' => $p);
        }
        usort($pts, function ($a, $b) {
            if ($a['t'] !== $b['t']) { return $a['t'] < $b['t'] ? -1 : 1; }
            return $a['i'] < $b['i'] ? -1 : 1;
        });
        $out = array();
        foreach ($pts as $row) { $out[] = $row['p']; }
        return $out;
    }

    /* ------------------------------------------------------------------ *
     * Session aggregation
     * ------------------------------------------------------------------ */

    /**
     * Roll a session's points into the durable summary. This is what survives the
     * 90-day purge, so it must be computed while the points still exist.
     */
    public static function summarise($session, $points)
    {
        $s    = (array) $session;
        $pts  = self::sortByTime($points);
        $dist = self::pathDistance($pts);

        $first = null; $last = null;
        foreach ($pts as $p) {
            if (self::validLat($p['lat']) && self::validLng($p['lng'])) {
                if ($first === null) { $first = $p; }
                $last = $p;
            }
        }

        $startedAt = isset($s['started_at']) && $s['started_at'] ? strtotime((string) $s['started_at']) : null;
        $endedAt   = isset($s['ended_at'])   && $s['ended_at']   ? strtotime((string) $s['ended_at'])   : null;
        $duration  = 0;
        if ($startedAt && $endedAt && $endedAt >= $startedAt) { $duration = $endedAt - $startedAt; }

        return array(
            'point_count'         => count($pts),
            'distance_m'          => (int) round($dist['total_m']),
            'duration_s'          => (int) $duration,
            'legs'                => $dist['legs'],
            'skipped_jitter'      => $dist['skipped_jitter'],
            'skipped_implausible' => $dist['skipped_implausible'],
            'start_lat'           => $first ? (float) $first['lat'] : (isset($s['start_lat']) ? $s['start_lat'] : null),
            'start_lng'           => $first ? (float) $first['lng'] : (isset($s['start_lng']) ? $s['start_lng'] : null),
            'end_lat'             => $last  ? (float) $last['lat']  : null,
            'end_lng'             => $last  ? (float) $last['lng']  : null,
        );
    }

    /** Human-friendly distance for the UI. */
    public static function formatDistance($metres)
    {
        $m = (float) $metres;
        if ($m < 1000) { return round($m) . ' m'; }
        return number_format($m / 1000, 2) . ' km';
    }

    /** Human-friendly duration for the UI. */
    public static function formatDuration($seconds)
    {
        $s = (int) $seconds;
        if ($s <= 0) { return '—'; }
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        if ($h > 0) { return $h . 'h ' . $m . 'm'; }
        if ($m > 0) { return $m . 'm'; }
        return $s . 's';
    }

    /* ------------------------------------------------------------------ *
     * Retention
     * ------------------------------------------------------------------ */

    /**
     * The cutoff date for the raw-point purge. Points captured strictly before
     * this are deleted.
     */
    public static function purgeCutoff($now = null, $days = self::RETENTION_DAYS)
    {
        $t = $now === null ? time() : (is_numeric($now) ? (int) $now : strtotime((string) $now));
        if ($t === false) { $t = time(); }
        return date('Y-m-d', $t - ((int) $days * 86400));
    }

    /** Should this point be purged as of $now? */
    public static function isExpired($capturedAt, $now = null, $days = self::RETENTION_DAYS)
    {
        $c = strtotime((string) $capturedAt);
        if ($c === false) { return false; }
        $cutoff = strtotime(self::purgeCutoff($now, $days) . ' 00:00:00');
        return $c < $cutoff;
    }

    /**
     * Split points into those to keep and those to purge. Used by the purge job
     * and by the tests that prove aggregates survive it.
     */
    public static function partitionForPurge($points, $now = null, $days = self::RETENTION_DAYS)
    {
        $keep = array(); $purge = array();
        foreach ((array) $points as $p) {
            $p = (array) $p;
            $when = isset($p['captured_at']) ? $p['captured_at'] : null;
            if ($when !== null && self::isExpired($when, $now, $days)) { $purge[] = $p; }
            else { $keep[] = $p; }
        }
        return array('keep' => $keep, 'purge' => $purge);
    }

    /** Days remaining before a point is deleted (0 once due). */
    public static function daysUntilPurge($capturedAt, $now = null, $days = self::RETENTION_DAYS)
    {
        $c = strtotime((string) $capturedAt);
        if ($c === false) { return 0; }
        $t = $now === null ? time() : (is_numeric($now) ? (int) $now : strtotime((string) $now));
        $remaining = (int) ceil((($c + ($days * 86400)) - $t) / 86400);
        return $remaining > 0 ? $remaining : 0;
    }

    /* ------------------------------------------------------------------ *
     * Session lifecycle helpers
     * ------------------------------------------------------------------ */

    /** A session left open past the limit is abandoned, not open. */
    public static function isAbandoned($session, $now = null)
    {
        $s = (array) $session;
        if ((string) (isset($s['status']) ? $s['status'] : '') !== 'open') { return false; }
        $start = isset($s['started_at']) ? strtotime((string) $s['started_at']) : false;
        if ($start === false) { return false; }
        $t = $now === null ? time() : (is_numeric($now) ? (int) $now : strtotime((string) $now));
        return ($t - $start) > (self::MAX_SESSION_HOURS * 3600);
    }

    /**
     * How much of a stored summary's distance is actually supported by legs the
     * engine believed, and which engine said so.
     *
     * The counters were always computed — pathDistance() has returned `legs`,
     * `skipped_jitter` and `skipped_implausible` since the first version — and
     * closeSession() threw all three away before storing. So a session where
     * the engine rejected most of the trail as impossible looked on the screen
     * exactly like a clean one: a distance, a duration, a point count.
     *
     * Verdicts:
     *   unrecorded  — written before the counters were stored; unverifiable
     *                 from the row alone
     *   no_movement — nothing to measure
     *   clean       — every leg counted
     *   partly_rejected — some legs rejected; the distance is a lower bound
     *   unsupported — a distance above zero resting on no counted leg at all.
     *                 This is the one that must never reach a payout quietly.
     *
     * @return array verdict, legs, rejected, engine_version
     */
    public static function summaryTrust($session)
    {
        $s = (array) $session;
        $engine   = isset($s['engine_version']) ? (int) $s['engine_version'] : 0;
        $legs     = isset($s['legs_counted']) ? (int) $s['legs_counted'] : 0;
        $jitter   = isset($s['skipped_jitter']) ? (int) $s['skipped_jitter'] : 0;
        $bad      = isset($s['skipped_implausible']) ? (int) $s['skipped_implausible'] : 0;
        $distance = isset($s['distance_m']) ? (int) $s['distance_m'] : 0;

        $base = array('legs' => $legs, 'rejected' => $bad, 'jitter' => $jitter,
                      'engine_version' => $engine);

        if ($engine <= 0) { return array('verdict' => 'unrecorded') + $base; }
        /* distance without a believed leg behind it — the dangerous one */
        if ($distance > 0 && $legs <= 0) { return array('verdict' => 'unsupported') + $base; }
        /*
         * Nothing counted because everything was refused is not the same as
         * nothing counted because nobody moved, and it is not "partly"
         * rejected either. This is what the one live session on staging would
         * be had it closed under the current rules, so it is a real state and
         * not a hypothetical one.
         */
        if ($legs <= 0 && $bad > 0)  { return array('verdict' => 'all_rejected') + $base; }
        if ($legs <= 0 && $bad <= 0) { return array('verdict' => 'no_movement') + $base; }
        if ($bad > 0)                { return array('verdict' => 'partly_rejected') + $base; }
        return array('verdict' => 'clean') + $base;
    }

    /** One sentence a non-technical reader can act on. */
    public static function trustMessage($trust)
    {
        $t = (array) $trust;
        $v = isset($t['verdict']) ? $t['verdict'] : '';
        switch ($v) {
            case 'unrecorded':
                return 'This distance was computed before the plausibility rules were '
                     . 'recorded on the session. It has not been checked against the current rules.';
            case 'unsupported':
                return 'Every leg of this trail was rejected as physically impossible, '
                     . 'yet a distance is stored. Do not pay a claim on this figure without re-verifying it.';
            case 'all_rejected':
                return 'All ' . (int) $t['rejected'] . ' leg(s) of this trail were rejected as '
                     . 'physically impossible, so no distance could be established. The points look '
                     . 'like a broken device clock or a spoofed trail rather than a journey.';
            case 'partly_rejected':
                return (int) $t['rejected'] . ' leg(s) were rejected as physically impossible. '
                     . 'The stored distance counts only the ' . (int) $t['legs'] . ' leg(s) the engine believed, '
                     . 'so it is a lower bound.';
            case 'no_movement':
                return 'No measurable movement was recorded for this session.';
            case 'clean':
                return 'Every leg of this trail passed the plausibility rules.';
            default:
                return '';
        }
    }

    public static function trustClass($verdict)
    {
        switch ((string) $verdict) {
            case 'clean':           return 'success';
            case 'partly_rejected': return 'warning';
            case 'all_rejected':    return 'danger';
            case 'unsupported':     return 'danger';
            case 'unrecorded':      return 'warning';
            default:                return 'default';
        }
    }

    public static function statusClass($status)
    {
        switch ((string) $status) {
            case 'open':      return 'warning';
            case 'closed':    return 'success';
            case 'abandoned': return 'danger';
            default:          return 'default';
        }
    }
}
