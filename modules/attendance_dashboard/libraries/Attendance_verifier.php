<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Pure verification rules (no DB / CI). Location and time are decided here; the caller
 * additionally requires a valid live image before a record is verified.
 */
class Attendance_verifier
{
    public static function distanceM($lat1, $lng1, $lat2, $lng2)
    {
        $a = deg2rad($lat2 - $lat1);
        $b = deg2rad($lng2 - $lng1);
        $h = sin($a / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($b / 2) ** 2;

        return (int) round(2 * 6371000 * asin(min(1, sqrt($h))));
    }

    public static function validCoords($lat, $lng)
    {
        return is_numeric($lat) && is_numeric($lng) && abs($lat) <= 90 && abs($lng) <= 180 && !($lat == 0 && $lng == 0);
    }

    /** Date of the shift a check-in/out belongs to (an overnight shift's check-out after midnight belongs to yesterday). */
    public static function shiftDate($schedule, $nowTs, $type)
    {
        $overnight = strtotime('1970-01-01 ' . $schedule['end_time']) <= strtotime('1970-01-01 ' . $schedule['start_time']);
        if ($type === 'out' && $overnight && date('H:i:s', $nowTs) < $schedule['end_time']) {
            return date('Y-m-d', $nowTs - 86400);
        }

        return date('Y-m-d', $nowTs);
    }

    /**
     * @param string $type 'in' | 'out'
     * @param array  $s    settings: early_minutes, late_grace_minutes, max_accuracy_m, checkout_late_minutes
     * @return array [ok, reason, distance_m, is_late, is_early_out, shift_date]
     */
    public static function evaluate($schedule, $workplace, $lat, $lng, $accuracy, $nowTs, array $s, $type = 'in')
    {
        $date = self::shiftDate($schedule, $nowTs, $type);
        $fail = function ($reason, $d = null) use ($date) {
            return ['ok' => false, 'reason' => $reason, 'distance_m' => $d, 'is_late' => 0, 'is_early_out' => 0, 'shift_date' => $date];
        };

        if (!self::validCoords($lat, $lng)) {
            return $fail('Location permission required.');
        }
        if ($accuracy !== null && $accuracy > $s['max_accuracy_m']) {
            return $fail('GPS accuracy too low (' . (int) $accuracy . ' m). Move to open sky and retry.');
        }
        $dist = self::distanceM((float) $lat, (float) $lng, (float) $workplace['latitude'], (float) $workplace['longitude']);
        if ($dist > (int) $workplace['radius_m']) {
            return $fail('Outside assigned workplace.', $dist);
        }

        $days = array_filter(explode(',', (string) $schedule['working_days']), 'strlen');
        if (!in_array(date('N', strtotime($date)), $days)) {
            return $fail('Outside permitted attendance time (not a working day).', $dist);
        }
        $start = strtotime($date . ' ' . $schedule['start_time']);
        $end   = strtotime($date . ' ' . $schedule['end_time']);
        if ($end <= $start) {
            $end += 86400; // overnight shift
        }

        $ok = ['ok' => true, 'distance_m' => $dist, 'is_late' => 0, 'is_early_out' => 0, 'shift_date' => $date];
        if ($type === 'out') {
            // Check-out is allowed from shift start until the configured time after shift end.
            if ($nowTs < $start || $nowTs > $end + $s['checkout_late_minutes'] * 60) {
                return $fail('Outside permitted attendance time.', $dist);
            }
            $early = $nowTs < $end - $s['late_grace_minutes'] * 60 ? 1 : 0;

            return ['reason' => $early ? 'Check-out verified (left early).' : 'Check-out verified.', 'is_early_out' => $early] + $ok;
        }

        if ($nowTs < $start - $s['early_minutes'] * 60 || $nowTs > $end) {
            return $fail('Outside permitted attendance time.', $dist);
        }
        $late = $nowTs > $start + $s['late_grace_minutes'] * 60 ? 1 : 0;

        return ['reason' => $late ? 'Check-in verified (late arrival).' : 'Check-in verified.', 'is_late' => $late] + $ok;
    }

    /** Late / early-exit / overtime minutes for one shift. Any of $inTs/$outTs may be null. */
    public static function metrics($schedule, $date, $inTs, $outTs, $graceMin)
    {
        $start = strtotime($date . ' ' . $schedule['start_time']);
        $end   = strtotime($date . ' ' . $schedule['end_time']);
        if ($end <= $start) {
            $end += 86400;
        }

        return [
            'late'  => ($inTs && $inTs > $start + $graceMin * 60) ? (int) round(($inTs - $start) / 60) : 0,
            'early' => ($outTs && $outTs < $end - $graceMin * 60) ? (int) round(($end - $outTs) / 60) : 0,
            'ot'    => ($outTs && $outTs > $end) ? (int) round(($outTs - $end) / 60) : 0,
        ];
    }
}
