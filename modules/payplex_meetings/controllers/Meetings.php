<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Meetings extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_meetings/payplex_meetings_model', 'pm');
        $this->load->model('payplex_meetings/payplex_meeting_reminders_model', 'pm_reminders');
        $this->load->library('payplex_meeting_rules');
        $this->load->library('payplex_ics');
    }

    /* ================================================================= pages */

    public function index()
    {
        $this->guard('view');

        $data['title']    = pm_lang('pm_all_meetings');
        $data['statuses'] = pm_statuses();
        $data['meetings'] = $this->pm->get_list([
            'status' => $this->input->get('status') ?: null,
        ], 200);

        $this->load->view('payplex_meetings/manage', $data);
    }

    public function mine()
    {
        $this->guard('view_own');

        $data['title']    = pm_lang('pm_my_meetings');
        $data['statuses'] = pm_statuses();
        $data['meetings'] = $this->pm->get_list(['staff_id' => get_staff_user_id()], 200);
        $data['mine']     = true;

        $this->load->view('payplex_meetings/manage', $data);
    }

    /**
     * Meetings whose end time has passed but which have no recorded outcome yet.
     */
    public function pending()
    {
        $this->guard('view_own');

        $data['title']    = pm_lang('pm_pending_completion');
        $data['statuses'] = pm_statuses();
        $data['meetings'] = $this->pm->get_list([
            'status'   => ['scheduled', 'confirmed', 'rescheduled', 'in_progress'],
            'to_utc'   => gmdate('Y-m-d H:i:s'),
            'order'    => 'ASC',
        ], 200);
        $data['pending']  = true;

        $this->load->view('payplex_meetings/manage', $data);
    }

    public function view($id)
    {
        $this->guard('view_own');

        $meeting = $this->pm->get($id);
        if (!$meeting || !$this->can_see($meeting)) {
            show_404();
        }

        $data['title']        = $meeting->reference_no;
        $data['meeting']      = $meeting;
        $data['participants'] = $this->pm->get_participants($id);
        $data['activity']     = $this->pm->activity($id);
        $data['deliveries']   = $this->pm_reminders->delivery_log($id);
        $data['reminders']    = $this->pm_reminders->for_meeting($id);
        $data['statuses']     = pm_statuses();
        $data['can_confidential'] = pm_can('view_confidential');

        $this->load->view('payplex_meetings/view_meeting', $data);
    }

    public function settings()
    {
        $this->guard('config');

        if ($this->input->post()) {
            $allowed = [
                'default_timezone', 'default_duration', 'working_days', 'working_hours_start',
                'working_hours_end', 'reminder_offsets', 'reminder_channels', 'retry_max',
                'retry_backoff_minutes', 'missed_window_minutes', 'require_link_online',
                'allow_link_after', 'conflict_block', 'block_outside_hours', 'block_holidays',
                'holidays', 'calendar_feed_enabled', 'approval_required_types',
            ];

            foreach ($allowed as $key) {
                $value = $this->input->post($key);
                update_option('pm_' . $key, $value === null ? '' : $value);
            }

            $this->pm->log(null, 'config.updated');
            set_alert('success', pm_lang('pm_settings_saved'));
            redirect(admin_url('payplex_meetings/meetings/settings'));
        }

        $data['title'] = pm_lang('pm_settings');
        $this->load->view('payplex_meetings/settings', $data);
    }

    /* ================================================================== ajax */

    /**
     * Indicators for the leads-list Engagement column.
     * POST ids[] -> one aggregate query for the whole page of leads.
     */
    public function lead_indicators()
    {
        $this->ajax_guard('view_own');

        $ids  = $this->input->post('ids');
        $ids  = is_array($ids) ? $ids : [];
        $rel  = $this->relType($this->input->post('rel_type'));
        if ($rel === 'customer') {
            $ids = array_values(array_filter($ids, function ($i) { return $this->customerAccessible($i); }));
        }
        $rows = $this->pm->indicators($rel, $ids);
        $tz   = pm_timezone();
        $out  = [];

        foreach ($ids as $id) {
            $id = (int) $id;
            $r  = isset($rows[$id]) ? $rows[$id] : null;

            $out[$id] = [
                'next'      => $r && $r['next_start_utc'] ? pm_from_utc($r['next_start_utc'], $tz, 'd M · g:i A') : null,
                'overdue'   => $r && $r['overdue_start_utc'] ? $this->days_since($r['overdue_start_utc']) : null,
                'completed' => $r ? $r['completed'] : 0,
                'no_show'   => $r ? $r['no_show'] : 0,
                'total'     => $r ? $r['total'] : 0,
            ];
        }

        $this->json(['success' => true, 'data' => $out]);
    }

    /**
     * The "Meetings & Follow-ups" panel rendered into the lead profile.
     */
    public function lead_tab($lead_id)
    {
        $this->guard('view_own');

        $data['lead_id']  = (int) $lead_id;
        $data['meetings'] = $this->pm->get_list([
            'rel_type' => 'lead',
            'rel_id'   => (int) $lead_id,
        ], 100);
        $data['statuses'] = pm_statuses();

        $this->load->view('payplex_meetings/lead_tab', $data);
    }

    /**
     * Booking form, returned as HTML for the drawer.
     */
    public function book_form($lead_id)
    {
        $this->guard('create');

        $rel = $this->relType($this->input->get('rel_type'));

        if ($rel === 'customer') {
            $lead = $this->customerAsLead((int) $lead_id);
        } else {
            $lead = $this->db->where('id', (int) $lead_id)->get(db_prefix() . 'leads')->row();
        }
        if (!$lead) {
            show_404();
        }

        $data['rel_type']  = $rel;
        $data['lead']      = $lead;
        $data['staff']     = $this->db->select('staffid, firstname, lastname, email')
                                      ->where('active', 1)->get(db_prefix() . 'staff')->result();
        $data['types']     = pm_meeting_types();
        $data['platforms'] = pm_platforms();
        $data['tz']        = pm_timezone();
        $data['duration']  = (int) pm_setting('default_duration', 30);

        $this->load->view('payplex_meetings/book_form', $data);
    }

    /**
     * Create the meeting. Validation, conflict detection and permission checks all run
     * server-side; the browser's copies are conveniences only.
     */
    public function book()
    {
        $this->ajax_guard('create');

        $tz       = $this->input->post('timezone') ?: pm_timezone();
        $date     = $this->input->post('date');
        $start    = $this->input->post('start_time');
        $end      = $this->input->post('end_time');
        $startUtc = pm_to_utc($date . ' ' . $start, $tz);
        $endUtc   = pm_to_utc($date . ' ' . $end, $tz);

        if (!$startUtc || !$endUtc) {
            $this->json(['success' => false, 'message' => pm_lang('pm_err_time_invalid')]);
        }

        $rel = $this->relType($this->input->post('rel_type'));
        if ($rel === 'customer' && !$this->customerAccessible((int) $this->input->post('lead_id'))) {
            $this->json(['success' => false, 'message' => pm_lang('pm_access_denied')], 403);
        }

        $data = [
            'rel_type'                   => $rel,
            'rel_id'                     => (int) $this->input->post('lead_id'),
            'subject'                    => trim((string) $this->input->post('subject')),
            'agenda'                     => $this->input->post('agenda'),
            'description'                => $this->input->post('description'),
            'meeting_type'               => $this->input->post('meeting_type'),
            'category'                   => $this->input->post('category'),
            'priority'                   => $this->input->post('priority') ?: 'medium',
            'language'                   => $this->input->post('language') ?: 'en',
            'tags'                       => $this->input->post('tags'),
            'location_type'              => $this->input->post('location_type') ?: 'online',
            'location_address'           => $this->input->post('location_address'),
            'platform'                   => $this->input->post('platform'),
            'meeting_link'               => trim((string) $this->input->post('meeting_link')),
            'link_created_after_booking' => (int) $this->input->post('link_later'),
            'start_utc'                  => $startUtc,
            'end_utc'                    => $endUtc,
            'timezone'                   => $tz,
            'duration_minutes'           => max(1, (int) round((strtotime($endUtc . ' UTC') - strtotime($startUtc . ' UTC')) / 60)),
            'is_private'                 => (int) $this->input->post('is_private'),
            'owner_staff_id'             => (int) $this->input->post('owner_staff_id') ?: null,
        ];

        $participants = $this->parse_participants();

        $options = [
            'override_conflict' => (bool) $this->input->post('override_conflict'),
            'override_reason'   => (string) $this->input->post('override_reason'),
        ];

        $result = $this->pm->create($data, $participants, $options);

        if (!$result['success']) {
            $this->json([
                'success'     => false,
                'message'     => $result['message'],
                'conflicts'   => $this->present_conflicts($result['conflicts']),
                'suggestions' => isset($result['suggestions']) ? $result['suggestions'] : [],
            ]);
        }

        $this->load->library('payplex_meeting_mailer');
        $this->payplex_meeting_mailer->send_confirmation($result['id']);

        $this->json([
            'success'  => true,
            'message'  => $result['message'],
            'id'       => $result['id'],
            'redirect' => admin_url('payplex_meetings/meetings/view/' . $result['id']),
        ]);
    }

    public function reschedule($id)
    {
        $this->ajax_guard('edit');

        $meeting = $this->pm->get($id);
        if (!$meeting || !$this->can_see($meeting)) {
            $this->json(['success' => false, 'message' => pm_lang('pm_not_found')]);
        }

        $tz       = $this->input->post('timezone') ?: $meeting->timezone;
        $startUtc = pm_to_utc($this->input->post('date') . ' ' . $this->input->post('start_time'), $tz);
        $endUtc   = pm_to_utc($this->input->post('date') . ' ' . $this->input->post('end_time'), $tz);

        if (!$startUtc || !$endUtc) {
            $this->json(['success' => false, 'message' => pm_lang('pm_err_time_invalid')]);
        }

        $result = $this->pm->reschedule($id, [
            'start_utc'        => $startUtc,
            'end_utc'          => $endUtc,
            'timezone'         => $tz,
            'duration_minutes' => max(1, (int) round((strtotime($endUtc . ' UTC') - strtotime($startUtc . ' UTC')) / 60)),
        ], (string) $this->input->post('reason'));

        if ($result['success']) {
            $this->load->library('payplex_meeting_mailer');
            $this->payplex_meeting_mailer->send_reschedule($id);
        }

        $this->json($result);
    }

    public function cancel($id)
    {
        $this->ajax_guard('cancel');

        $meeting = $this->pm->get($id);
        if (!$meeting || !$this->can_see($meeting)) {
            $this->json(['success' => false, 'message' => pm_lang('pm_not_found')]);
        }

        $result = $this->pm->cancel($id, (string) $this->input->post('reason'));

        if ($result['success']) {
            $this->load->library('payplex_meeting_mailer');
            $this->payplex_meeting_mailer->send_cancellation($id);
        }

        $this->json($result);
    }

    public function set_status($id)
    {
        $this->ajax_guard('edit');

        $meeting = $this->pm->get($id);
        if (!$meeting || !$this->can_see($meeting)) {
            $this->json(['success' => false, 'message' => pm_lang('pm_not_found')]);
        }

        $this->json($this->pm->set_status(
            $id,
            (string) $this->input->post('status'),
            $this->input->post('outcome')
        ));
    }

    /**
     * Live conflict probe used by the booking drawer before submit.
     */
    public function check_conflicts()
    {
        $this->ajax_guard('create');

        $tz       = $this->input->post('timezone') ?: pm_timezone();
        $startUtc = pm_to_utc($this->input->post('date') . ' ' . $this->input->post('start_time'), $tz);
        $endUtc   = pm_to_utc($this->input->post('date') . ' ' . $this->input->post('end_time'), $tz);

        if (!$startUtc || !$endUtc) {
            $this->json(['success' => false, 'conflicts' => [], 'suggestions' => []]);
        }

        $probe = [
            'rel_type'         => $this->relType($this->input->post('rel_type')),
            'rel_id'           => (int) $this->input->post('lead_id'),
            'subject'          => (string) $this->input->post('subject'),
            'start_utc'        => $startUtc,
            'end_utc'          => $endUtc,
            'timezone'         => $tz,
            'duration_minutes' => max(1, (int) round((strtotime($endUtc . ' UTC') - strtotime($startUtc . ' UTC')) / 60)),
        ];

        $participants = $this->parse_participants();
        $conflicts    = $this->payplex_meeting_rules->find_conflicts($probe, $participants);

        $this->json([
            'success'     => true,
            'conflicts'   => $this->present_conflicts($conflicts),
            'suggestions' => empty($conflicts) ? [] : $this->payplex_meeting_rules->suggest_slots($probe, $participants),
            'can_override'=> pm_can('override_conflict'),
        ]);
    }

    /* ============================================================== calendar */

    /**
     * Feed consumed by the CRM calendar. Returns only meetings the caller may see,
     * because get_list() applies the visibility scope in SQL.
     */
    public function calendar_feed()
    {
        $this->ajax_guard('view_own');

        if (pm_setting('calendar_feed_enabled', '1') !== '1') {
            $this->json([]);
        }

        $tz       = pm_timezone();
        $meetings = $this->pm->get_list([
            'from_utc' => $this->input->get('start') ? pm_to_utc($this->input->get('start') . ' 00:00:00', $tz) : null,
            'to_utc'   => $this->input->get('end') ? pm_to_utc($this->input->get('end') . ' 23:59:59', $tz) : null,
        ], 500);

        $statuses = pm_statuses();
        $events   = [];

        foreach ($meetings as $m) {
            $events[] = [
                'id'    => 'pm_' . $m->id,
                'title' => $m->subject,
                'start' => pm_from_utc($m->start_utc, $m->timezone, 'Y-m-d\TH:i:s'),
                'end'   => pm_from_utc($m->end_utc, $m->timezone, 'Y-m-d\TH:i:s'),
                'color' => isset($statuses[$m->status]) ? $statuses[$m->status]['color'] : '#3b7dd8',
                'url'   => admin_url('payplex_meetings/meetings/view/' . $m->id),
            ];
        }

        $this->json($events);
    }

    public function ics($id)
    {
        $this->guard('view_own');

        $meeting = $this->pm->get($id);
        if (!$meeting || !$this->can_see($meeting)) {
            show_404();
        }

        $participants = $this->pm->get_participants($id);
        $method       = $meeting->status === 'cancelled' ? 'CANCEL' : 'REQUEST';
        $content      = $this->payplex_ics->build($meeting, $participants, $method);

        $this->pm->log($id, 'ics.downloaded');

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $this->payplex_ics->filename($meeting) . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    /* =============================================================== helpers */

    protected function parse_participants()
    {
        $raw = $this->input->post('participants');
        $in  = is_string($raw) ? json_decode($raw, true) : $raw;
        $in  = is_array($in) ? $in : [];
        $out = [];

        foreach ($in as $p) {
            $email = isset($p['email']) ? trim((string) $p['email']) : '';

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue; // drop malformed addresses rather than queue a guaranteed bounce
            }

            $out[] = [
                'party_type'            => in_array($p['party_type'] ?? '', ['staff', 'contact', 'external'], true)
                                            ? $p['party_type'] : 'external',
                'staff_id'              => isset($p['staff_id']) ? (int) $p['staff_id'] : null,
                'contact_id'            => isset($p['contact_id']) ? (int) $p['contact_id'] : null,
                'email'                 => $email,
                'name'                  => isset($p['name']) ? strip_tags((string) $p['name']) : null,
                'role'                  => in_array($p['role'] ?? '', ['required', 'optional', 'cc'], true) ? $p['role'] : 'required',
                'is_summary_recipient'  => !empty($p['is_summary_recipient']),
                'is_reminder_recipient' => !isset($p['is_reminder_recipient']) || $p['is_reminder_recipient'],
            ];
        }

        return $out;
    }

    protected function present_conflicts(array $conflicts)
    {
        $out = [];

        foreach ($conflicts as $c) {
            $m     = $c['meeting'];
            $out[] = [
                'type'       => $c['type'],
                'reference'  => $m->reference_no,
                'subject'    => $m->subject,
                'start_local'=> pm_from_utc($m->start_utc, $m->timezone, 'd M Y, g:i A'),
                'end_local'  => pm_from_utc($m->end_utc, $m->timezone, 'g:i A'),
                'url'        => admin_url('payplex_meetings/meetings/view/' . $m->id),
            ];
        }

        return $out;
    }

    protected function can_see($meeting)
    {
        if (pm_can('view')) {
            return true;
        }
        if (!pm_can('view_own')) {
            return false;
        }

        $me = (int) get_staff_user_id();
        if ((int) $meeting->organizer_staff_id === $me || (int) $meeting->owner_staff_id === $me) {
            return true;
        }

        $row = $this->db->where('meeting_id', (int) $meeting->id)
                        ->where('staff_id', $me)
                        ->where('removed_at IS NULL', null, false)
                        ->get(pm_table('participants'))->row();

        return (bool) $row;
    }

    protected function days_since($utc)
    {
        $diff = time() - strtotime($utc . ' UTC');

        return max(0, (int) floor($diff / 86400));
    }

    /** Only the record types meetings can hang off. Anything else is a lead. */
    protected function relType($value)
    {
        return $value === 'customer' ? 'customer' : 'lead';
    }

    /**
     * May this staff member attach a meeting to / see the status of this customer?
     * Same rule as the Customers list: everyone with "view customers", otherwise only
     * the customers they administer.
     */
    protected function customerAccessible($customer_id)
    {
        $customer_id = (int) $customer_id;

        return $customer_id > 0 && (staff_can('view', 'customers') || is_customer_admin($customer_id));
    }

    /**
     * A customer shaped like the lead the booking form expects: name, company,
     * email, phone of the PRIMARY CONTACT (company phone as fallback).
     */
    protected function customerAsLead($customer_id)
    {
        if (!$this->customerAccessible($customer_id)) {
            return null;
        }

        $row = $this->db->select('c.userid AS id, c.company, c.phonenumber AS company_phone, '
                . 'ct.firstname, ct.lastname, ct.email, ct.phonenumber AS contact_phone')
            ->from(db_prefix() . 'clients c')
            ->join(db_prefix() . 'contacts ct', 'ct.userid = c.userid AND ct.is_primary = 1', 'left')
            ->where('c.userid', (int) $customer_id)->get()->row();

        if (!$row) {
            return null;
        }

        $name = trim($row->firstname . ' ' . $row->lastname);

        return (object) [
            'id'          => (int) $row->id,
            'name'        => $name !== '' ? $name : (string) $row->company,
            'company'     => (string) $row->company,
            'email'       => (string) $row->email,
            'phonenumber' => (string) ($row->contact_phone !== '' && $row->contact_phone !== null ? $row->contact_phone : $row->company_phone),
        ];
    }

    protected function guard($capability)
    {
        if (!pm_can($capability)) {
            access_denied('payplex_meetings');
        }
    }

    protected function ajax_guard($capability)
    {
        if (!$this->input->is_ajax_request()) {
            show_404();
        }
        if (!pm_can($capability)) {
            $this->json(['success' => false, 'message' => pm_lang('pm_access_denied')], 403);
        }
    }

    /**
     * Echo directly rather than handing the body to CI's output class: these methods
     * exit immediately, and an exit() skips CI's output flush, which would send an
     * empty 200 back to the browser.
     *
     * Every response refreshes the CSRF hash, because Perfex rotates it per request and
     * the client keeps a copy for the next call.
     */
    protected function json($payload, $code = 200)
    {
        if (is_array($payload)) {
            $payload['csrf_hash'] = $this->security->get_csrf_hash();
        }

        $this->output->set_status_header($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
