<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Payplex_meetings_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /* ================================================================= reads */

    public function get($id)
    {
        $this->db->where('id', (int) $id);

        return $this->db->get(pm_meetings_table())->row();
    }

    public function get_by_reference($ref)
    {
        $this->db->where('reference_no', $ref);

        return $this->db->get(pm_meetings_table())->row();
    }

    /**
     * @param array $filters status, rel_type, rel_id, staff_id, from_utc, to_utc, scope
     */
    public function get_list($filters = [], $limit = 100, $offset = 0)
    {
        $t = pm_meetings_table();
        $this->db->select($t . '.*');
        $this->db->from($t);

        if (!empty($filters['rel_type'])) {
            $this->db->where($t . '.rel_type', $filters['rel_type']);
        }
        if (!empty($filters['rel_id'])) {
            $this->db->where($t . '.rel_id', (int) $filters['rel_id']);
        }
        if (!empty($filters['status'])) {
            is_array($filters['status'])
                ? $this->db->where_in($t . '.status', $filters['status'])
                : $this->db->where($t . '.status', $filters['status']);
        }
        if (!empty($filters['from_utc'])) {
            $this->db->where($t . '.start_utc >=', $filters['from_utc']);
        }
        if (!empty($filters['to_utc'])) {
            $this->db->where($t . '.start_utc <=', $filters['to_utc']);
        }

        $this->apply_visibility_scope($t, isset($filters['staff_id']) ? $filters['staff_id'] : null);

        $this->db->order_by($t . '.start_utc', isset($filters['order']) ? $filters['order'] : 'DESC');
        $this->db->limit((int) $limit, (int) $offset);

        return $this->db->get()->result();
    }

    /**
     * Visibility is enforced in SQL, never in the view.
     *
     * - 'view'      : unscoped, every meeting.
     * - 'view_own'  : meetings the staff organises, owns, or is a participant of.
     * - neither     : an impossible predicate, so the query returns nothing rather than
     *                 relying on the controller having checked first (fail closed).
     */
    protected function apply_visibility_scope($t, $staff_id = null)
    {
        $staff_id = $staff_id ?: get_staff_user_id();

        if (pm_can('view', $staff_id)) {
            return;
        }

        if (!pm_can('view_own', $staff_id)) {
            $this->db->where('1 = 0', null, false);

            return;
        }

        $p   = pm_table('participants');
        $sid = (int) $staff_id;

        $this->db->group_start();
        $this->db->where($t . '.organizer_staff_id', $sid);
        $this->db->or_where($t . '.owner_staff_id', $sid);
        $this->db->or_where(
            "EXISTS (SELECT 1 FROM `{$p}` pp WHERE pp.meeting_id = {$t}.id"
            . " AND pp.staff_id = {$sid} AND pp.removed_at IS NULL)",
            null,
            false
        );
        $this->db->group_end();
    }

    public function get_participants($meeting_id, $include_removed = false)
    {
        $this->db->where('meeting_id', (int) $meeting_id);
        if (!$include_removed) {
            $this->db->where('removed_at IS NULL', null, false);
        }
        $this->db->order_by('party_type', 'ASC');

        return $this->db->get(pm_table('participants'))->result();
    }

    /**
     * Aggregate indicators for the leads-list Engagement column.
     * One query for the whole visible page of leads -- never one query per row.
     *
     * @param  array $lead_ids
     * @return array keyed by lead id
     */
    public function lead_indicators(array $lead_ids)
    {
        $lead_ids = array_values(array_filter(array_map('intval', $lead_ids)));
        if (empty($lead_ids)) {
            return [];
        }

        $t      = pm_meetings_table();
        $in     = implode(',', $lead_ids);
        $nowUtc = gmdate('Y-m-d H:i:s');

        $sql = "SELECT rel_id AS lead_id,
                       SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                       SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END)   AS no_show_count,
                       MIN(CASE WHEN start_utc > '{$nowUtc}'
                                 AND status IN ('scheduled','confirmed','rescheduled')
                                THEN start_utc END)                          AS next_start_utc,
                       MAX(CASE WHEN start_utc < '{$nowUtc}'
                                 AND status IN ('scheduled','confirmed','rescheduled')
                                THEN start_utc END)                          AS overdue_start_utc,
                       COUNT(*)                                              AS total_count
                  FROM `{$t}`
                 WHERE rel_type = 'lead'
                   AND rel_id IN ({$in})
                 GROUP BY rel_id";

        $rows = $this->db->query($sql)->result();
        $out  = [];

        foreach ($rows as $r) {
            $out[(int) $r->lead_id] = [
                'next_start_utc'    => $r->next_start_utc,
                'overdue_start_utc' => $r->overdue_start_utc,
                'completed'         => (int) $r->completed_count,
                'no_show'           => (int) $r->no_show_count,
                'total'             => (int) $r->total_count,
            ];
        }

        return $out;
    }

    /* ================================================================ writes */

    /**
     * Create a meeting, its participants and its reminder rows in one transaction.
     * Either everything lands or nothing does -- a meeting with no reminders, or
     * reminders pointing at a meeting that failed to insert, are both worse than an error.
     *
     * @return array ['success' => bool, 'id' => int|null, 'message' => string, 'conflicts' => array]
     */
    public function create(array $data, array $participants = [], array $options = [])
    {
        $this->load->library('payplex_meeting_rules');

        $validation = $this->payplex_meeting_rules->validate($data);
        if (!$validation['valid']) {
            return ['success' => false, 'id' => null, 'message' => $validation['message'], 'conflicts' => []];
        }

        $conflicts = $this->payplex_meeting_rules->find_conflicts($data, $participants);
        $override  = !empty($options['override_conflict']);

        if (!empty($conflicts) && pm_setting('conflict_block', '1') === '1' && !$override) {
            return [
                'success'   => false,
                'id'        => null,
                'message'   => pm_lang('pm_conflict_detected'),
                'conflicts' => $conflicts,
                'suggestions' => $this->payplex_meeting_rules->suggest_slots($data, $participants),
            ];
        }

        if (!empty($conflicts) && $override && !pm_can('override_conflict')) {
            return ['success' => false, 'id' => null, 'message' => pm_lang('pm_override_not_permitted'), 'conflicts' => $conflicts];
        }

        $staff_id = get_staff_user_id();
        $now      = date('Y-m-d H:i:s');

        $row = [
            'reference_no'               => 'PENDING',
            'rel_type'                   => isset($data['rel_type']) ? $data['rel_type'] : 'lead',
            'rel_id'                     => (int) $data['rel_id'],
            'subject'                    => $data['subject'],
            'agenda'                     => isset($data['agenda']) ? $data['agenda'] : null,
            'description'                => isset($data['description']) ? $data['description'] : null,
            'meeting_type'               => isset($data['meeting_type']) ? $data['meeting_type'] : 'other',
            'category'                   => isset($data['category']) ? $data['category'] : null,
            'priority'                   => isset($data['priority']) ? $data['priority'] : 'medium',
            'language'                   => isset($data['language']) ? $data['language'] : 'en',
            'tags'                       => isset($data['tags']) ? $data['tags'] : null,
            'location_type'              => $data['location_type'],
            'location_address'           => isset($data['location_address']) ? $data['location_address'] : null,
            'platform'                   => isset($data['platform']) ? $data['platform'] : null,
            'meeting_link'               => isset($data['meeting_link']) ? $data['meeting_link'] : null,
            'link_created_after_booking' => !empty($data['link_created_after_booking']) ? 1 : 0,
            'start_utc'                  => $data['start_utc'],
            'end_utc'                    => $data['end_utc'],
            'timezone'                   => isset($data['timezone']) ? $data['timezone'] : pm_timezone(),
            'duration_minutes'           => (int) $data['duration_minutes'],
            'status'                     => 'scheduled',
            'is_private'                 => !empty($data['is_private']) ? 1 : 0,
            'organizer_staff_id'         => $staff_id,
            'owner_staff_id'             => isset($data['owner_staff_id']) ? (int) $data['owner_staff_id'] : $staff_id,
            'approval_state'             => $this->requires_approval($data) ? 'pending' : 'not_required',
            'parent_meeting_id'          => isset($data['parent_meeting_id']) ? (int) $data['parent_meeting_id'] : null,
            'created_by'                 => $staff_id,
            'date_created'               => $now,
        ];

        if (!empty($conflicts) && $override) {
            $row['conflict_override_by']     = $staff_id;
            $row['conflict_override_reason'] = isset($options['override_reason']) ? $options['override_reason'] : '';
        }

        $this->db->trans_begin();

        $this->db->insert(pm_meetings_table(), $row);
        $id = $this->db->insert_id();

        if (!$id) {
            $this->db->trans_rollback();

            return ['success' => false, 'id' => null, 'message' => pm_lang('pm_create_failed'), 'conflicts' => []];
        }

        $this->db->where('id', $id)->update(pm_meetings_table(), ['reference_no' => pm_reference_no($id)]);

        $this->sync_participants($id, $participants, false);
        $this->load->model('payplex_meetings/payplex_meeting_reminders_model', 'pm_reminders');
        $this->pm_reminders->generate_for_meeting($id);

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();

            return ['success' => false, 'id' => null, 'message' => pm_lang('pm_create_failed'), 'conflicts' => []];
        }

        $this->db->trans_commit();

        $this->log($id, 'meeting.created', null, null, $row['subject'], null, $row['rel_type'], $row['rel_id']);

        if (!empty($conflicts) && $override) {
            $this->log($id, 'meeting.conflict_override', null, null, null, $row['conflict_override_reason']);
        }

        return ['success' => true, 'id' => $id, 'message' => pm_lang('pm_created'), 'conflicts' => $conflicts];
    }

    /**
     * Reschedule: keeps history, regenerates reminders, never silently drops sent ones.
     */
    public function reschedule($id, array $data, $reason)
    {
        $meeting = $this->get($id);
        if (!$meeting) {
            return ['success' => false, 'message' => pm_lang('pm_not_found')];
        }
        if (trim((string) $reason) === '') {
            return ['success' => false, 'message' => pm_lang('pm_reason_required')];
        }

        $this->db->trans_begin();

        $this->db->where('id', $id)->update(pm_meetings_table(), [
            'start_utc'         => $data['start_utc'],
            'end_utc'           => $data['end_utc'],
            'timezone'          => isset($data['timezone']) ? $data['timezone'] : $meeting->timezone,
            'duration_minutes'  => (int) $data['duration_minutes'],
            'status'            => 'rescheduled',
            'reschedule_reason' => $reason,
            'last_updated'      => date('Y-m-d H:i:s'),
        ]);

        $this->load->model('payplex_meetings/payplex_meeting_reminders_model', 'pm_reminders');
        $this->pm_reminders->supersede_pending($id);
        $this->pm_reminders->generate_for_meeting($id);

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();

            return ['success' => false, 'message' => pm_lang('pm_update_failed')];
        }
        $this->db->trans_commit();

        $this->log($id, 'meeting.rescheduled', 'start_utc', $meeting->start_utc, $data['start_utc'], $reason);

        return ['success' => true, 'message' => pm_lang('pm_rescheduled')];
    }

    /**
     * Cancellation is a status, never a DELETE.
     */
    public function cancel($id, $reason)
    {
        $meeting = $this->get($id);
        if (!$meeting) {
            return ['success' => false, 'message' => pm_lang('pm_not_found')];
        }
        if (trim((string) $reason) === '') {
            return ['success' => false, 'message' => pm_lang('pm_reason_required')];
        }

        $this->db->trans_begin();

        $this->db->where('id', $id)->update(pm_meetings_table(), [
            'status'        => 'cancelled',
            'cancel_reason' => $reason,
            'last_updated'  => date('Y-m-d H:i:s'),
        ]);

        $this->load->model('payplex_meetings/payplex_meeting_reminders_model', 'pm_reminders');
        $this->pm_reminders->cancel_pending($id);

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();

            return ['success' => false, 'message' => pm_lang('pm_update_failed')];
        }
        $this->db->trans_commit();

        $this->log($id, 'meeting.cancelled', 'status', $meeting->status, 'cancelled', $reason);

        return ['success' => true, 'message' => pm_lang('pm_cancelled')];
    }

    public function set_status($id, $status, $outcome = null)
    {
        $meeting = $this->get($id);
        if (!$meeting || !array_key_exists($status, pm_statuses())) {
            return ['success' => false, 'message' => pm_lang('pm_not_found')];
        }

        $update = ['status' => $status, 'last_updated' => date('Y-m-d H:i:s')];
        if ($outcome !== null) {
            $update['outcome'] = $outcome;
        }

        $this->db->where('id', $id)->update(pm_meetings_table(), $update);

        if (in_array($status, ['completed', 'cancelled', 'no_show'], true)) {
            $this->load->model('payplex_meetings/payplex_meeting_reminders_model', 'pm_reminders');
            $this->pm_reminders->cancel_pending($id);
        }

        $this->log($id, 'meeting.status_changed', 'status', $meeting->status, $status);

        return ['success' => true, 'message' => pm_lang('pm_updated')];
    }

    /* ========================================================== participants */

    public function sync_participants($meeting_id, array $participants, $log = true)
    {
        $now      = date('Y-m-d H:i:s');
        $staff_id = get_staff_user_id();
        $existing = $this->get_participants($meeting_id);
        $seen     = [];

        foreach ($participants as $p) {
            $email = isset($p['email']) ? trim(strtolower($p['email'])) : '';
            $key   = ($p['party_type'] ?? 'staff') . '|' . (int) ($p['staff_id'] ?? 0) . '|' . $email;
            $seen[$key] = true;

            $already = null;
            foreach ($existing as $e) {
                $ekey = $e->party_type . '|' . (int) $e->staff_id . '|' . strtolower((string) $e->email);
                if ($ekey === $key) {
                    $already = $e;
                    break;
                }
            }

            $row = [
                'meeting_id'            => (int) $meeting_id,
                'party_type'            => $p['party_type'] ?? 'staff',
                'staff_id'              => !empty($p['staff_id']) ? (int) $p['staff_id'] : null,
                'contact_id'            => !empty($p['contact_id']) ? (int) $p['contact_id'] : null,
                'email'                 => $email ?: null,
                'name'                  => $p['name'] ?? null,
                'role'                  => $p['role'] ?? 'required',
                'is_summary_recipient'  => !empty($p['is_summary_recipient']) ? 1 : 0,
                'is_reminder_recipient' => isset($p['is_reminder_recipient']) ? (int) (bool) $p['is_reminder_recipient'] : 1,
                'added_by'              => $staff_id,
                'date_created'          => $now,
            ];

            if ($already) {
                unset($row['date_created'], $row['added_by']);
                $this->db->where('id', $already->id)->update(pm_table('participants'), $row);
            } else {
                $this->db->insert(pm_table('participants'), $row);
                if ($log) {
                    $this->log($meeting_id, 'participant.added', null, null, $row['email'] ?: $row['name']);
                }
            }
        }

        // Soft-remove anything no longer present, so the audit trail keeps the history.
        foreach ($existing as $e) {
            $ekey = $e->party_type . '|' . (int) $e->staff_id . '|' . strtolower((string) $e->email);
            if (!isset($seen[$ekey])) {
                $this->db->where('id', $e->id)->update(pm_table('participants'), ['removed_at' => $now]);
                if ($log) {
                    $this->log($meeting_id, 'participant.removed', null, $e->email ?: $e->name, null);
                }
            }
        }
    }

    /* ================================================================ helpers */

    protected function requires_approval(array $data)
    {
        $types = array_filter(array_map('trim', explode(',', (string) pm_setting('approval_required_types', ''))));

        return in_array($data['meeting_type'] ?? '', $types, true);
    }

    /**
     * Append-only. There is deliberately no update() or delete() for this table anywhere
     * in the module.
     */
    public function log($meeting_id, $action, $field = null, $old = null, $new = null, $reason = null, $rel_type = null, $rel_id = null)
    {
        $CI = &get_instance();

        $this->db->insert(pm_table('activity_logs'), [
            'meeting_id'   => $meeting_id ? (int) $meeting_id : null,
            'rel_type'     => $rel_type,
            'rel_id'       => $rel_id ? (int) $rel_id : null,
            'staff_id'     => get_staff_user_id(),
            'action'       => $action,
            'field'        => $field,
            'old_value'    => is_scalar($old) ? (string) $old : json_encode($old),
            'new_value'    => is_scalar($new) ? (string) $new : json_encode($new),
            'reason'       => $reason,
            'ip_address'   => pm_client_ip(),
            'user_agent'   => substr((string) $CI->input->user_agent(), 0, 255),
            'date_created' => date('Y-m-d H:i:s'),
        ]);
    }

    public function activity($meeting_id, $limit = 100)
    {
        $this->db->where('meeting_id', (int) $meeting_id);
        $this->db->order_by('date_created', 'DESC');
        $this->db->limit((int) $limit);

        return $this->db->get(pm_table('activity_logs'))->result();
    }
}
