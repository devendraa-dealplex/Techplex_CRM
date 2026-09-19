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

    /**
     * @param array $s settings: early_minutes, late_grace_minutes, max_accuracy_m
     * @return array [ok, reason, distance_m, is_late]
     */
    public static function evaluate($schedule, $workplace, $lat, $lng, $accuracy, $nowTs, array $s)
    {
        $fail = function ($reason, $d = null) {
            return ['ok' => false, 'reason' => $reason, 'distance_m' => $d, 'is_late' => 0];
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
        if (!in_array(date('N', $nowTs), $days)) {
            return $fail('Outside permitted attendance time (not a working day).', $dist);
        }
        $date  = date('Y-m-d', $nowTs);
        $start = strtotime($date . ' ' . $schedule['start_time']);
        $end   = strtotime($date . ' ' . $schedule['end_time']);
        if ($end <= $start) {
            $end += 86400; // overnight shift
        }
        if ($nowTs < $start - $s['early_minutes'] * 60 || $nowTs > $end) {
            return $fail('Outside permitted attendance time.', $dist);
        }

        $late = $nowTs > $start + $s['late_grace_minutes'] * 60 ? 1 : 0;

        return ['ok' => true, 'reason' => $late ? 'Verified (late arrival).' : 'Verified.', 'distance_m' => $dist, 'is_late' => $late];
    }
}
