<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Server-side validation, conflict detection and slot suggestion.
 *
 * Everything here runs on the server. The browser repeats some of it for a faster
 * experience, but nothing in the browser is trusted.
 */
class Payplex_meeting_rules
{
    protected $CI;

    public function __construct()
    {
        $this->CI = &get_instance();
    }

    /**
     * @return array ['valid' => bool, 'message' => string, 'field' => string|null]
     */
    public function validate(array $d)
    {
        if (empty($d['subject']) || trim($d['subject']) === '') {
            return $this->fail(pm_lang('pm_err_subject_required'), 'subject');
        }
        if (empty($d['rel_id'])) {
            return $this->fail(pm_lang('pm_err_lead_required'), 'rel_id');
        }
        if (empty($d['start_utc']) || empty($d['end_utc'])) {
            return $this->fail(pm_lang('pm_err_time_required'), 'start_utc');
        }

        $start = strtotime($d['start_utc'] . ' UTC');
        $end   = strtotime($d['end_utc'] . ' UTC');

        if (!$start || !$end) {
            return $this->fail(pm_lang('pm_err_time_invalid'), 'start_utc');
        }
        if ($end <= $start) {
            return $this->fail(pm_lang('pm_err_end_before_start'), 'end_utc');
        }
        if ($start < time() - 60) {
            return $this->fail(pm_lang('pm_err_past'), 'start_utc');
        }

        // Online meetings must carry a link, unless the admin allows adding it later.
        $requireLink = pm_setting('require_link_online', '1') === '1';
        $allowAfter  = pm_setting('allow_link_after', '0') === '1';

        if (($d['location_type'] ?? '') === 'online' && $requireLink) {
            $hasLink = !empty($d['meeting_link']) && $this->is_valid_url($d['meeting_link']);
            $deferred = !empty($d['link_created_after_booking']) && $allowAfter;

            if (!$hasLink && !$deferred) {
                return $this->fail(pm_lang('pm_err_link_required'), 'meeting_link');
            }
            if (!empty($d['meeting_link']) && !$hasLink) {
                return $this->fail(pm_lang('pm_err_link_invalid'), 'meeting_link');
            }
        }

        if (($d['location_type'] ?? '') === 'office' || ($d['location_type'] ?? '') === 'client_site') {
            if (empty($d['location_address'])) {
                return $this->fail(pm_lang('pm_err_address_required'), 'location_address');
            }
        }

        $tz = $d['timezone'] ?? pm_timezone();
        if (!in_array($tz, timezone_identifiers_list(), true)) {
            return $this->fail(pm_lang('pm_err_timezone_invalid'), 'timezone');
        }

        $outsideWorking = $this->outside_working_hours($d['start_utc'], $d['end_utc'], $tz);
        if ($outsideWorking && pm_setting('block_outside_hours', '0') === '1') {
            return $this->fail(pm_lang('pm_err_outside_hours'), 'start_utc');
        }

        if ($this->is_holiday($d['start_utc'], $tz) && pm_setting('block_holidays', '0') === '1') {
            return $this->fail(pm_lang('pm_err_holiday'), 'start_utc');
        }

        return ['valid' => true, 'message' => '', 'field' => null];
    }

    protected function fail($message, $field)
    {
        return ['valid' => false, 'message' => $message, 'field' => $field];
    }

    protected function is_valid_url($url)
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /* ============================================================= conflicts */

    /**
     * Overlap rule: two meetings conflict when start < otherEnd AND end > otherStart.
     * Touching edges (one ends exactly when the next starts) is not a conflict.
     *
     * @return array of ['type' => ..., 'meeting' => row, 'staff_id' => int|null]
     */
    public function find_conflicts(array $d, array $participants = [], $exclude_meeting_id = null)
    {
        $t         = pm_meetings_table();
        $p         = pm_table('participants');
        $conflicts = [];

        $staffIds = [];
        foreach ($participants as $part) {
            if (($part['party_type'] ?? 'staff') === 'staff' && !empty($part['staff_id'])) {
                $staffIds[] = (int) $part['staff_id'];
            }
        }
        $staffIds[] = (int) get_staff_user_id();
        $staffIds   = array_values(array_unique(array_filter($staffIds)));

        $start = $this->CI->db->escape($d['start_utc']);
        $end   = $this->CI->db->escape($d['end_utc']);
        $live  = "'scheduled','confirmed','rescheduled','in_progress'";
        $skip  = $exclude_meeting_id ? ' AND m.id <> ' . (int) $exclude_meeting_id : '';

        /* 1. staff double-booking */
        if (!empty($staffIds)) {
            $in  = implode(',', $staffIds);
            $sql = "SELECT DISTINCT m.*, 'staff_busy' AS conflict_type
                      FROM `{$t}` m
                      LEFT JOIN `{$p}` pp ON pp.meeting_id = m.id AND pp.removed_at IS NULL
                     WHERE m.status IN ({$live})
                       AND m.start_utc < {$end} AND m.end_utc > {$start}
                       AND (m.organizer_staff_id IN ({$in}) OR pp.staff_id IN ({$in}))
                       {$skip}
                     LIMIT 20";
            foreach ($this->CI->db->query($sql)->result() as $row) {
                $conflicts[] = ['type' => 'staff_busy', 'meeting' => $row];
            }
        }

        /* 2. the lead already has an overlapping meeting */
        $relType = $this->CI->db->escape($d['rel_type'] ?? 'lead');
        $relId   = (int) $d['rel_id'];
        $sql     = "SELECT m.*, 'lead_busy' AS conflict_type
                      FROM `{$t}` m
                     WHERE m.status IN ({$live})
                       AND m.rel_type = {$relType} AND m.rel_id = {$relId}
                       AND m.start_utc < {$end} AND m.end_utc > {$start}
                       {$skip}
                     LIMIT 20";
        foreach ($this->CI->db->query($sql)->result() as $row) {
            $conflicts[] = ['type' => 'lead_busy', 'meeting' => $row];
        }

        /* 3. an identical meeting already exists (duplicate submit, double click) */
        $subject = $this->CI->db->escape($d['subject']);
        $sql     = "SELECT m.*, 'duplicate' AS conflict_type
                      FROM `{$t}` m
                     WHERE m.status IN ({$live})
                       AND m.rel_type = {$relType} AND m.rel_id = {$relId}
                       AND m.subject = {$subject}
                       AND m.start_utc = {$start}
                       {$skip}
                     LIMIT 5";
        foreach ($this->CI->db->query($sql)->result() as $row) {
            $conflicts[] = ['type' => 'duplicate', 'meeting' => $row];
        }

        return $conflicts;
    }

    /**
     * Offer the next few free slots on the same day, then the following working day.
     */
    public function suggest_slots(array $d, array $participants = [], $count = 3)
    {
        $tz       = $d['timezone'] ?? pm_timezone();
        $duration = (int) ($d['duration_minutes'] ?? 30);
        $step     = 15 * 60;
        $cursor   = strtotime($d['start_utc'] . ' UTC');
        $limit    = $cursor + (7 * 86400);
        $found    = [];

        while ($cursor < $limit && count($found) < $count) {
            $cursor += $step;

            $startUtc = gmdate('Y-m-d H:i:s', $cursor);
            $endUtc   = gmdate('Y-m-d H:i:s', $cursor + ($duration * 60));

            if ($this->outside_working_hours($startUtc, $endUtc, $tz) || $this->is_holiday($startUtc, $tz)) {
                continue;
            }

            $probe = array_merge($d, ['start_utc' => $startUtc, 'end_utc' => $endUtc]);
            if (empty($this->find_conflicts($probe, $participants))) {
                $found[] = [
                    'start_utc'   => $startUtc,
                    'end_utc'     => $endUtc,
                    'start_local' => pm_from_utc($startUtc, $tz, 'd M Y, g:i A'),
                ];
            }
        }

        return $found;
    }

    /* ========================================================= working hours */

    public function outside_working_hours($startUtc, $endUtc, $tz)
    {
        $days = array_filter(array_map('intval', explode(',', (string) pm_setting('working_days', '1,2,3,4,5,6'))));
        $from = (string) pm_setting('working_hours_start', '09:00');
        $to   = (string) pm_setting('working_hours_end', '20:00');

        try {
            $zone  = new DateTimeZone($tz);
            $start = new DateTime($startUtc, new DateTimeZone('UTC'));
            $end   = new DateTime($endUtc, new DateTimeZone('UTC'));
            $start->setTimezone($zone);
            $end->setTimezone($zone);
        } catch (Exception $e) {
            return false;
        }

        if (!in_array((int) $start->format('N'), $days, true)) {
            return true;
        }

        $startMin = ((int) $start->format('H') * 60) + (int) $start->format('i');
        $endMin   = ((int) $end->format('H') * 60) + (int) $end->format('i');
        $fromMin  = $this->to_minutes($from);
        $toMin    = $this->to_minutes($to);

        return $startMin < $fromMin || $endMin > $toMin;
    }

    public function is_holiday($startUtc, $tz)
    {
        $raw = trim((string) pm_setting('holidays', ''));
        if ($raw === '') {
            return false;
        }

        $dates = array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw)));
        $local = pm_from_utc($startUtc, $tz, 'Y-m-d');

        return in_array($local, $dates, true);
    }

    protected function to_minutes($hhmm)
    {
        $parts = explode(':', (string) $hhmm);

        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }
}
