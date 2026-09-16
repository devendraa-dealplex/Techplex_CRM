<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Outbound email for meetings.
 *
 * The one rule this class exists to enforce: the data array handed to a client-facing
 * template physically cannot contain internal fields. build_payload() has two modes and
 * the 'client' mode never reads internal_body, internal notes, override reasons or
 * confidential participant metadata -- so a template bug cannot leak them, because the
 * values were never in the array.
 */
class Payplex_meeting_mailer
{
    protected $CI;

    public function __construct()
    {
        $this->CI = &get_instance();
        $this->CI->load->library('payplex_ics');
        $this->CI->load->model('payplex_meetings/payplex_meetings_model', 'pm_meetings');
        $this->CI->load->model('payplex_meetings/payplex_meeting_reminders_model', 'pm_reminders');
    }

    /* ============================================================== payloads */

    public function build_payload($meeting, $audience = 'internal')
    {
        $tz = $meeting->timezone;

        $shared = [
            'reference_no'   => $meeting->reference_no,
            'subject'        => $meeting->subject,
            'agenda'         => (string) $meeting->agenda,
            'date_local'     => pm_from_utc($meeting->start_utc, $tz, 'd M Y'),
            'start_local'    => pm_from_utc($meeting->start_utc, $tz, 'g:i A'),
            'end_local'      => pm_from_utc($meeting->end_utc, $tz, 'g:i A'),
            'timezone'       => $tz,
            'duration'       => (int) $meeting->duration_minutes,
            'meeting_type'   => pm_lang('pm_type_' . $meeting->meeting_type),
            'location_type'  => $meeting->location_type,
            'meeting_link'   => (string) $meeting->meeting_link,
            'address'        => (string) $meeting->location_address,
            'organizer_name' => $this->staff_name($meeting->organizer_staff_id),
            'organizer_email'=> $this->staff_email($meeting->organizer_staff_id),
            'status'         => $meeting->status,
        ];

        if ($audience === 'client') {
            // Nothing internal is added. This is the whole point of the split.
            return $shared;
        }

        return array_merge($shared, [
            'internal_notes'   => $this->internal_notes($meeting->id),
            'override_reason'  => (string) $meeting->conflict_override_reason,
            'reschedule_reason'=> (string) $meeting->reschedule_reason,
            'cancel_reason'    => (string) $meeting->cancel_reason,
            'is_private'       => (int) $meeting->is_private,
            'participants'     => $this->CI->pm_meetings->get_participants($meeting->id),
        ]);
    }

    /* =============================================================== senders */

    public function send_confirmation($meeting_id)
    {
        return $this->send_to_participants($meeting_id, 'pm_confirmation', true);
    }

    public function send_cancellation($meeting_id)
    {
        return $this->send_to_participants($meeting_id, 'pm_cancellation', true, 'CANCEL');
    }

    public function send_reschedule($meeting_id)
    {
        return $this->send_to_participants($meeting_id, 'pm_reschedule', true);
    }

    /**
     * Send one reminder row. Called only by the dispatcher.
     */
    public function send_reminder($reminder)
    {
        $meeting = $this->CI->pm_meetings->get($reminder->meeting_id);
        if (!$meeting || in_array($meeting->status, ['cancelled', 'completed', 'no_show'], true)) {
            return ['success' => false, 'error' => 'meeting_not_active'];
        }

        $participant = $this->CI->db->where('id', (int) $reminder->participant_id)
                                    ->get(pm_table('participants'))->row();

        if (!$participant || $participant->removed_at !== null) {
            return ['success' => false, 'error' => 'participant_removed'];
        }

        if ($reminder->channel === 'crm') {
            return $this->send_crm_notification($meeting, $participant);
        }

        if (empty($participant->email)) {
            return ['success' => false, 'error' => 'no_recipient_email'];
        }

        $audience = $participant->party_type === 'staff' ? 'internal' : 'client';
        $payload  = $this->build_payload($meeting, $audience);
        $subject  = pm_lang('pm_email_reminder_subject') . ': ' . $meeting->subject
                    . ' | ' . $payload['date_local'] . ' ' . $payload['start_local'];

        $body = $this->render('emails/reminder', [
            'p'        => $payload,
            'audience' => $audience,
            'name'     => $participant->name ?: $participant->email,
            'offset'   => (int) $reminder->offset_minutes,
        ]);

        $sent = $this->deliver($participant->email, $subject, $body);

        $this->CI->pm_reminders->log_delivery([
            'meeting_id'      => (int) $meeting->id,
            'reminder_id'     => (int) $reminder->id,
            'recipient_email' => $participant->email,
            'recipient_type'  => $participant->party_type,
            'template_slug'   => 'pm_reminder',
            'subject_rendered'=> substr($subject, 0, 255),
            'version_sent'    => $audience,
            'channel'         => 'email',
            'scheduled_utc'   => $reminder->scheduled_utc,
            'delivery_status' => $sent['success'] ? 'sent' : 'failed',
            'failure_reason'  => $sent['success'] ? null : $sent['error'],
            'retry_count'     => (int) $reminder->attempts,
        ]);

        return $sent;
    }

    /* =============================================================== internals */

    protected function send_to_participants($meeting_id, $slug, $attach_ics = false, $ics_method = 'REQUEST')
    {
        $meeting = $this->CI->pm_meetings->get($meeting_id);
        if (!$meeting) {
            return ['success' => false, 'error' => 'meeting_not_found'];
        }

        $participants = $this->CI->pm_meetings->get_participants($meeting_id);
        $icsPath      = null;

        if ($attach_ics) {
            $icsPath = $this->CI->payplex_ics->to_temp_file($meeting, $participants, $ics_method);
        }

        $sentCount = 0;

        foreach ($participants as $p) {
            if (empty($p->email)) {
                continue;
            }

            $audience = $p->party_type === 'staff' ? 'internal' : 'client';
            $payload  = $this->build_payload($meeting, $audience);

            $subject = $this->subject_for($slug, $payload);
            $body    = $this->render('emails/' . str_replace('pm_', '', $slug), [
                'p'        => $payload,
                'audience' => $audience,
                'name'     => $p->name ?: $p->email,
            ]);

            $result = $this->deliver($p->email, $subject, $body, $icsPath);
            $sentCount += $result['success'] ? 1 : 0;

            $this->CI->pm_reminders->log_delivery([
                'meeting_id'      => (int) $meeting->id,
                'reminder_id'     => null,
                'recipient_email' => $p->email,
                'recipient_type'  => $p->party_type,
                'template_slug'   => $slug,
                'subject_rendered'=> substr($subject, 0, 255),
                'version_sent'    => $audience,
                'channel'         => 'email',
                'delivery_status' => $result['success'] ? 'sent' : 'failed',
                'failure_reason'  => $result['success'] ? null : $result['error'],
            ]);
        }

        if ($icsPath && file_exists($icsPath)) {
            @unlink($icsPath);
        }

        $this->CI->pm_meetings->log($meeting_id, 'email.' . $slug . '.sent', null, null, $sentCount);

        return ['success' => true, 'sent' => $sentCount];
    }

    protected function subject_for($slug, array $p)
    {
        $map = [
            'pm_confirmation' => pm_lang('pm_email_confirm_subject'),
            'pm_reschedule'   => pm_lang('pm_email_reschedule_subject'),
            'pm_cancellation' => pm_lang('pm_email_cancel_subject'),
        ];

        $prefix = isset($map[$slug]) ? $map[$slug] : pm_lang('pm_meeting');

        return $prefix . ': ' . $p['subject'] . ' | ' . $p['date_local'] . ' ' . pm_lang('pm_at') . ' ' . $p['start_local'];
    }

    protected function render($view, array $data)
    {
        return $this->CI->load->view('payplex_meetings/' . $view, $data, true);
    }

    /**
     * Uses the CRM's configured SMTP through CodeIgniter's email library, so there is no
     * second mail configuration and no hard-coded sender anywhere in this module.
     */
    protected function deliver($to, $subject, $html, $attachmentPath = null)
    {
        try {
            $this->CI->load->library('email');
            $this->CI->email->clear(true);

            $from = get_option('smtp_email');
            $name = get_option('companyname') ?: get_option('smtp_email');

            if (empty($from)) {
                return ['success' => false, 'error' => 'smtp_not_configured'];
            }

            $this->CI->email->from($from, $name);
            $this->CI->email->to($to);
            $this->CI->email->subject($subject);
            $this->CI->email->message($html);
            $this->CI->email->set_mailtype('html');

            if ($attachmentPath && file_exists($attachmentPath)) {
                $this->CI->email->attach($attachmentPath);
            }

            if ($this->CI->email->send(false)) {
                return ['success' => true, 'error' => null];
            }

            return ['success' => false, 'error' => substr((string) $this->CI->email->print_debugger(['headers']), 0, 500)];
        } catch (Exception $e) {
            return ['success' => false, 'error' => substr($e->getMessage(), 0, 500)];
        }
    }

    protected function send_crm_notification($meeting, $participant)
    {
        if (empty($participant->staff_id) || !function_exists('add_notification')) {
            return ['success' => false, 'error' => 'crm_notification_unavailable'];
        }

        add_notification([
            'description' => 'pm_notification_upcoming',
            'touserid'    => (int) $participant->staff_id,
            'link'        => 'payplex_meetings/meetings/view/' . (int) $meeting->id,
            'additional_data' => serialize([$meeting->subject]),
        ]);

        return ['success' => true, 'error' => null];
    }

    protected function internal_notes($meeting_id)
    {
        return $this->CI->db->where('meeting_id', (int) $meeting_id)
                            ->where('visibility', 'internal')
                            ->order_by('date_created', 'ASC')
                            ->get(pm_table('notes'))->result();
    }

    protected function staff_name($staff_id)
    {
        $row = $this->CI->db->select('CONCAT(firstname, " ", lastname) AS n')
                            ->where('staffid', (int) $staff_id)
                            ->get(db_prefix() . 'staff')->row();

        return $row ? $row->n : '';
    }

    protected function staff_email($staff_id)
    {
        $row = $this->CI->db->select('email')->where('staffid', (int) $staff_id)
                            ->get(db_prefix() . 'staff')->row();

        return $row ? $row->email : '';
    }
}
