<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Minimal RFC 5545 VCALENDAR builder.
 *
 * Deliberately dependency-free: an .ics attachment is the one "add to calendar" path that
 * works for every recipient on every platform with zero credentials and zero OAuth, which
 * is why v1 leads with it (decision D4).
 */
class Payplex_ics
{
    /**
     * @param  object $meeting      row from tblpayplex_meetings
     * @param  array  $participants rows from tblpayplex_meeting_participants
     * @param  string $method       REQUEST for invitations, CANCEL for cancellations
     * @return string
     */
    public function build($meeting, array $participants = [], $method = 'REQUEST')
    {
        $organizerEmail = $this->organizer_email($meeting);
        $sequence       = $this->sequence($meeting);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Payplex//Meetings ' . PAYPLEX_MEETINGS_VERSION . '//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
            'BEGIN:VEVENT',
            'UID:' . $this->uid($meeting),
            'SEQUENCE:' . $sequence,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $this->stamp($meeting->start_utc),
            'DTEND:' . $this->stamp($meeting->end_utc),
            'SUMMARY:' . $this->esc($meeting->subject),
            'STATUS:' . ($meeting->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
            'TRANSP:OPAQUE',
        ];

        $description = $this->description($meeting);
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:' . $this->esc($description);
        }

        $location = $this->location($meeting);
        if ($location !== '') {
            $lines[] = 'LOCATION:' . $this->esc($location);
        }

        if (!empty($meeting->meeting_link)) {
            $lines[] = 'URL:' . $this->esc($meeting->meeting_link);
        }

        if ($organizerEmail) {
            $lines[] = 'ORGANIZER;CN=' . $this->esc($this->organizer_name($meeting)) . ':mailto:' . $organizerEmail;
        }

        foreach ($participants as $p) {
            if (empty($p->email)) {
                continue;
            }
            $role = ($p->role === 'optional') ? 'OPT-PARTICIPANT' : 'REQ-PARTICIPANT';
            $lines[] = 'ATTENDEE;ROLE=' . $role . ';PARTSTAT=NEEDS-ACTION;RSVP=TRUE'
                . ';CN=' . $this->esc($p->name ?: $p->email)
                . ':mailto:' . $p->email;
        }

        // A single client-side alarm; the real reminder ladder is server-side.
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'DESCRIPTION:' . $this->esc($meeting->subject);
        $lines[] = 'TRIGGER:-PT30M';
        $lines[] = 'END:VALARM';

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([$this, 'fold'], $lines)) . "\r\n";
    }

    public function filename($meeting)
    {
        return $meeting->reference_no . '.ics';
    }

    /**
     * Write the .ics to a temp file so it can be attached to an outgoing email.
     * Caller is responsible for unlinking it after send.
     */
    public function to_temp_file($meeting, array $participants = [], $method = 'REQUEST')
    {
        $dir  = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR;
        $path = $dir . 'pm_' . $meeting->reference_no . '_' . bin2hex(random_bytes(4)) . '.ics';

        file_put_contents($path, $this->build($meeting, $participants, $method));

        return $path;
    }

    /* ================================================================ internals */

    protected function uid($meeting)
    {
        $host = parse_url(site_url(), PHP_URL_HOST) ?: 'payplex.local';

        return $meeting->reference_no . '@' . $host;
    }

    /**
     * SEQUENCE must increase on every update or calendar clients ignore the change.
     * The number of reschedules recorded in the audit log is exactly that counter.
     */
    protected function sequence($meeting)
    {
        $CI  = &get_instance();
        $row = $CI->db->select('COUNT(*) AS c')
                      ->where('meeting_id', (int) $meeting->id)
                      ->where_in('action', ['meeting.rescheduled', 'meeting.cancelled'])
                      ->get(pm_table('activity_logs'))->row();

        return $row ? (int) $row->c : 0;
    }

    protected function stamp($utc)
    {
        return gmdate('Ymd\THis\Z', strtotime($utc . ' UTC'));
    }

    protected function description($meeting)
    {
        $parts = [];

        if (!empty($meeting->agenda)) {
            $parts[] = pm_lang('pm_agenda') . ': ' . $meeting->agenda;
        }
        if (!empty($meeting->meeting_link)) {
            $parts[] = pm_lang('pm_join_link') . ': ' . $meeting->meeting_link;
        }
        $parts[] = pm_lang('pm_reference') . ': ' . $meeting->reference_no;

        // Internal notes and confidential fields are never placed in an .ics: the file
        // travels to every attendee, including the client.
        return implode("\n", $parts);
    }

    protected function location($meeting)
    {
        if ($meeting->location_type === 'online') {
            return (string) $meeting->meeting_link;
        }
        if (in_array($meeting->location_type, ['office', 'client_site'], true)) {
            return (string) $meeting->location_address;
        }

        return '';
    }

    protected function organizer_email($meeting)
    {
        $CI  = &get_instance();
        $row = $CI->db->select('email')->where('staffid', (int) $meeting->organizer_staff_id)
                      ->get(db_prefix() . 'staff')->row();

        return $row ? $row->email : null;
    }

    protected function organizer_name($meeting)
    {
        $CI  = &get_instance();
        $row = $CI->db->select('CONCAT(firstname, " ", lastname) AS n')
                      ->where('staffid', (int) $meeting->organizer_staff_id)
                      ->get(db_prefix() . 'staff')->row();

        return $row ? $row->n : '';
    }

    protected function esc($text)
    {
        $text = str_replace('\\', '\\\\', (string) $text);
        $text = str_replace(["\r\n", "\r", "\n"], '\\n', $text);
        $text = str_replace([',', ';'], ['\\,', '\\;'], $text);

        return $text;
    }

    /**
     * RFC 5545 line folding at 75 octets.
     */
    protected function fold($line)
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out       = substr($line, 0, 75);
        $remaining = substr($line, 75);

        while (strlen($remaining) > 74) {
            $out       .= "\r\n " . substr($remaining, 0, 74);
            $remaining = substr($remaining, 74);
        }

        return $out . "\r\n " . $remaining;
    }
}
