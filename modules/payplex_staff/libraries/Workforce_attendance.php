<?php
defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Attendance — the flag that nothing honoured.
 *
 * WHAT WAS MEASURED ON STAGING, 2026-09-11
 * ----------------------------------------
 * `attendance_required` is a column on every workforce profile. It is set from
 * the engagement type's defaults, it is editable on the staff form, and it is
 * printed on the staff view as "Attendance required / not required".
 *
 * Searching every PHP file in this module for that column: it appears in the
 * schema, in the profile library that sets it, in the model that copies it, and
 * in two views that display it. **Nothing reads it to require, check, permit or
 * refuse anything.** There is no check-in, no check-out, no shift, no late
 * arrival, no early departure and no day record anywhere in the module. The
 * screen states a policy the system does not have.
 *
 * This is the sixth time on this project that a flag has been stored, displayed
 * and honoured by nothing: the DND gate, the agent kill switch, the cron key,
 * the production-runner label, the target status, and now this.
 *
 * The event vocabulary was already there and unused — Payplex_staff_activity
 * has carried `attendance_checkin` and `attendance_checkout` since batch 2, and
 * no code path has ever written one. So this library supplies the rules and the
 * module writes those events; nothing new had to be invented to name them.
 *
 * WHAT IS DELIBERATELY NOT HERE
 * Leave, holidays and shift rosters are not built. Saying so is the point: a
 * half-built leave system that silently treats an approved absence as an
 * unexplained one would be worse than none, and `dayVerdict()` returns
 * `no_record` rather than `absent` precisely because this module cannot yet tell
 * those apart.
 */
class Workforce_attendance
{
    /** Minutes of grace before an arrival counts as late. */
    const DEFAULT_GRACE_MIN = 15;
    const DEFAULT_START     = '09:00';
    const DEFAULT_END       = '18:00';
    /** A day shorter than this is flagged, whatever the clock times say. */
    const DEFAULT_MIN_HOURS = 4.0;

    /**
     * Is attendance required of this person, and on whose authority?
     *
     * The profile flag decides. The engagement type supplies the default when a
     * profile has never been classified, and the answer says which of the two it
     * came from — an attendance rule nobody can trace the source of is how a
     * dispute becomes unresolvable.
     *
     * @param array $profile  attendance_required, employment_type, classification_required
     * @param array $typeDefaults  employment_type => bool
     * @return array required (bool), source, reason
     */
    public static function isRequired($profile, array $typeDefaults = array())
    {
        $p = (array) $profile;

        if (array_key_exists('attendance_required', $p) && $p['attendance_required'] !== null
            && $p['attendance_required'] !== '') {
            $req = (int) $p['attendance_required'] === 1;
            return array(
                'required' => $req,
                'source'   => 'profile',
                'reason'   => $req
                    ? 'This person\'s workforce profile requires attendance.'
                    : 'This person\'s workforce profile does not require attendance.',
            );
        }

        $type = isset($p['employment_type']) ? (string) $p['employment_type'] : '';
        if ($type !== '' && array_key_exists($type, $typeDefaults)) {
            $req = (bool) $typeDefaults[$type];
            return array(
                'required' => $req,
                'source'   => 'engagement_type',
                'reason'   => 'Taken from the "' . $type . '" engagement type, because this profile '
                            . 'has no attendance setting of its own.',
            );
        }

        /*
         * Unknown is not "no". A person whose engagement nobody has classified
         * has not been excused from attendance; nobody has decided yet.
         */
        return array(
            'required' => false,
            'source'   => 'undecided',
            'reason'   => 'Nobody has decided whether attendance applies to this person. Their '
                        . 'engagement type is not classified, so nothing is being enforced and '
                        . 'nothing is being excused.',
        );
    }

    /**
     * The working window for a work category.
     *
     * Field and remote work are not office hours and are not judged as if they
     * were: they carry a window for reporting, and lateness is not asserted
     * against people whose work is defined by where they are, not when.
     */
    public static function windowFor($workCategory, array $settings = array())
    {
        $start = isset($settings['start']) && self::isTime($settings['start']) ? $settings['start'] : self::DEFAULT_START;
        $end   = isset($settings['end'])   && self::isTime($settings['end'])   ? $settings['end']   : self::DEFAULT_END;
        $grace = isset($settings['grace_minutes']) ? max(0, (int) $settings['grace_minutes']) : self::DEFAULT_GRACE_MIN;

        $wc = strtolower(trim((string) $workCategory));
        $judgeLateness = in_array($wc, array('office', 'hybrid'), true);

        return array(
            'start' => $start,
            'end'   => $end,
            'grace_minutes' => $grace,
            'judge_lateness' => $judgeLateness,
            'note' => $judgeLateness
                ? 'Arrival is measured against ' . $start . ' with ' . $grace . ' minutes of grace.'
                : 'Hours are recorded but arrival time is not judged: this is ' . ($wc === '' ? 'unclassified' : $wc)
                  . ' work, where when somebody starts is not the measure of it.',
        );
    }

    /**
     * Turn one day's raw check-in / check-out events into sessions.
     *
     * Real days are messy: a forgotten check-out, two check-ins in a row, a
     * check-out with no check-in. Each is reported as what it is rather than
     * dropped, because a silently discarded event is a dispute nobody can settle.
     *
     * @param array $events  each: type (attendance_checkin|attendance_checkout), at (H:i:s or Y-m-d H:i:s)
     * @return array sessions, problems, worked_minutes
     */
    public static function pairEvents(array $events)
    {
        usort($events, function ($a, $b) {
            return strcmp(self::timeOf($a), self::timeOf($b));
        });

        $sessions = array();
        $problems = array();
        $open     = null;

        foreach ($events as $e) {
            $type = isset($e['type']) ? (string) $e['type'] : '';
            $at   = self::timeOf($e);
            if ($at === '') { $problems[] = array('code' => 'bad_timestamp', 'at' => ''); continue; }

            if ($type === 'attendance_checkin') {
                if ($open !== null) {
                    $problems[] = array('code' => 'double_checkin', 'at' => $at,
                        'message' => 'Checked in again at ' . $at . ' without checking out first. '
                                   . 'The earlier session is left open rather than guessed at.');
                    $sessions[] = array('in' => $open, 'out' => null, 'minutes' => null, 'open' => true);
                }
                $open = $at;
                continue;
            }
            if ($type === 'attendance_checkout') {
                if ($open === null) {
                    $problems[] = array('code' => 'checkout_without_checkin', 'at' => $at,
                        'message' => 'Checked out at ' . $at . ' with no check-in recorded for the day.');
                    continue;
                }
                $sessions[] = array('in' => $open, 'out' => $at,
                                    'minutes' => self::minutesBetween($open, $at), 'open' => false);
                $open = null;
                continue;
            }
            $problems[] = array('code' => 'unknown_event', 'at' => $at);
        }

        if ($open !== null) {
            $sessions[] = array('in' => $open, 'out' => null, 'minutes' => null, 'open' => true);
            $problems[] = array('code' => 'never_checked_out', 'at' => $open,
                'message' => 'Checked in at ' . $open . ' and never checked out. The day is recorded '
                           . 'as incomplete rather than closed at a time nobody chose.');
        }

        $worked = 0;
        foreach ($sessions as $s) { if ($s['minutes'] !== null) { $worked += $s['minutes']; } }

        return array('sessions' => $sessions, 'problems' => $problems, 'worked_minutes' => $worked);
    }

    /**
     * What happened on this day.
     *
     * @return array verdict, minutes, late_minutes, early_minutes, notes
     *
     * Verdicts: not_required · no_record · incomplete · short_day · late ·
     *           early_departure · complete
     */
    public static function dayVerdict($paired, $window, $required, array $opts = array())
    {
        $minHours = isset($opts['min_hours']) ? (float) $opts['min_hours'] : self::DEFAULT_MIN_HOURS;
        $notes    = array();

        if (!$required) {
            return array('verdict' => 'not_required', 'minutes' => (int) $paired['worked_minutes'],
                         'late_minutes' => 0, 'early_minutes' => 0,
                         'notes' => array('Attendance is not required of this person. Any hours shown '
                                        . 'are recorded, not expected.'));
        }

        if (empty($paired['sessions'])) {
            /*
             * NOT "absent". This module has no leave or holiday record, so it
             * cannot tell an unexplained absence from an approved one, and
             * asserting the first would put a mark against somebody on a day the
             * system knows nothing about.
             */
            return array('verdict' => 'no_record', 'minutes' => 0, 'late_minutes' => 0, 'early_minutes' => 0,
                         'notes' => array('No check-in was recorded. This module has no leave or holiday '
                                        . 'calendar, so it cannot say whether that is an absence.'));
        }

        $firstIn = $paired['sessions'][0]['in'];
        $lastOut = null;
        $incomplete = false;
        foreach ($paired['sessions'] as $s) {
            if ($s['open']) { $incomplete = true; }
            if ($s['out'] !== null) { $lastOut = $s['out']; }
        }

        $late = 0; $early = 0;
        if (!empty($window['judge_lateness'])) {
            $allowed = self::addMinutes($window['start'], (int) $window['grace_minutes']);
            if (strcmp($firstIn, $allowed) > 0) { $late = self::minutesBetween($allowed, $firstIn); }
            if ($lastOut !== null && strcmp($lastOut, $window['end']) < 0) {
                $early = self::minutesBetween($lastOut, $window['end']);
            }
        } else {
            $notes[] = $window['note'];
        }

        if ($incomplete) {
            $notes[] = 'At least one session has no check-out.';
            return array('verdict' => 'incomplete', 'minutes' => (int) $paired['worked_minutes'],
                         'late_minutes' => $late, 'early_minutes' => $early, 'notes' => $notes);
        }

        $hours = $paired['worked_minutes'] / 60.0;
        if ($hours < $minHours) {
            $notes[] = 'Worked ' . round($hours, 2) . ' h against a ' . $minHours . ' h minimum.';
            return array('verdict' => 'short_day', 'minutes' => (int) $paired['worked_minutes'],
                         'late_minutes' => $late, 'early_minutes' => $early, 'notes' => $notes);
        }
        if ($late > 0) {
            $notes[] = 'Arrived ' . $late . ' minutes after the grace period.';
            return array('verdict' => 'late', 'minutes' => (int) $paired['worked_minutes'],
                         'late_minutes' => $late, 'early_minutes' => $early, 'notes' => $notes);
        }
        if ($early > 0) {
            $notes[] = 'Left ' . $early . ' minutes before the end of the window.';
            return array('verdict' => 'early_departure', 'minutes' => (int) $paired['worked_minutes'],
                         'late_minutes' => $late, 'early_minutes' => $early, 'notes' => $notes);
        }

        return array('verdict' => 'complete', 'minutes' => (int) $paired['worked_minutes'],
                     'late_minutes' => 0, 'early_minutes' => 0, 'notes' => $notes);
    }

    /** May this person check in right now, given what is already recorded? */
    public static function canCheckIn(array $paired)
    {
        foreach ($paired['sessions'] as $s) {
            if ($s['open']) {
                return array('allowed' => false, 'code' => 'already_open',
                             'reason' => 'You checked in at ' . $s['in'] . ' and have not checked out.');
            }
        }
        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    public static function canCheckOut(array $paired)
    {
        foreach ($paired['sessions'] as $s) {
            if ($s['open']) { return array('allowed' => true, 'code' => 'ok', 'reason' => ''); }
        }
        return array('allowed' => false, 'code' => 'not_checked_in',
                     'reason' => 'There is no open session to check out of today.');
    }

    /* ---------------- small helpers, kept pure ---------------- */

    private static function isTime($v)
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v);
    }

    private static function timeOf($e)
    {
        $at = isset($e['at']) ? trim((string) $e['at']) : '';
        if ($at === '') { return ''; }
        if (preg_match('/(\d{2}:\d{2}:\d{2})$/', $at, $m)) { return $m[1]; }
        if (preg_match('/^(\d{2}:\d{2})$/', $at)) { return $at . ':00'; }
        return '';
    }

    private static function minutesBetween($a, $b)
    {
        return (int) round((strtotime('1970-01-01 ' . self::pad($b)) - strtotime('1970-01-01 ' . self::pad($a))) / 60);
    }

    private static function addMinutes($hhmm, $mins)
    {
        return date('H:i:s', strtotime('1970-01-01 ' . self::pad($hhmm)) + ($mins * 60));
    }

    private static function pad($t)
    {
        $t = (string) $t;
        return strlen($t) === 5 ? $t . ':00' : $t;
    }
}
