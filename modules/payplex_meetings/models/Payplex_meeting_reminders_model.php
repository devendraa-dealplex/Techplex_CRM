<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Reminder scheduling and dispatch.
 *
 * Two guarantees, both enforced by the database rather than by careful code:
 *
 *  1. NEVER TWICE  - every row carries a unique idempotency_key built from
 *                    (meeting, participant, offset, channel). A duplicate insert is
 *                    refused by the UNIQUE index, whatever calls it.
 *  2. NEVER TWO WORKERS ON ONE ROW - the dispatcher claims work with a single atomic
 *                    UPDATE ... LIMIT that stamps a per-run claim token. Whichever run's
 *                    UPDATE lands first owns those rows; an overlapping run finds nothing
 *                    left in 'pending' and claims nothing.
 */
class Payplex_meeting_reminders_model extends App_Model
{
    /**
     * Materialise one row per (reminder recipient x configured offset x channel).
     * Offsets already in the past at booking time are skipped, not backdated.
     */
    public function generate_for_meeting($meeting_id)
    {
        $this->load->model('payplex_meetings/payplex_meetings_model', 'pm_meetings');

        $meeting = $this->pm_meetings->get($meeting_id);
        if (!$meeting || in_array($meeting->status, ['cancelled', 'completed', 'no_show'], true)) {
            return 0;
        }

        $offsets  = array_filter(array_map('intval', explode(',', (string) pm_setting('reminder_offsets', '1440,120,30'))));
        $channels = array_filter(array_map('trim', explode(',', (string) pm_setting('reminder_channels', 'email'))));
        $now      = gmdate('Y-m-d H:i:s');
        $created  = 0;

        $participants = $this->pm_meetings->get_participants($meeting_id);

        foreach ($participants as $p) {
            if (!$p->is_reminder_recipient) {
                continue;
            }

            foreach ($offsets as $offset) {
                $scheduled = gmdate('Y-m-d H:i:s', strtotime($meeting->start_utc . ' UTC') - ($offset * 60));
                if ($scheduled <= $now) {
                    continue; // the window has already passed; do not backdate
                }

                foreach ($channels as $channel) {
                    if ($channel === 'crm' && empty($p->staff_id)) {
                        continue; // no CRM notification target for an external participant
                    }

                    $key = hash('sha256', implode('|', [$meeting_id, $p->id, $offset, $channel, $meeting->start_utc]));

                    // Rely on the UNIQUE index instead of a check-then-insert race.
                    $this->db->query(
                        'INSERT IGNORE INTO `' . pm_table('reminders') . '`
                         (meeting_id, participant_id, channel, offset_minutes, template_slug,
                          scheduled_utc, status, attempts, idempotency_key, date_created)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
                        [
                            (int) $meeting_id, (int) $p->id, $channel, (int) $offset,
                            'pm_reminder', $scheduled, 'pending', $key, date('Y-m-d H:i:s'),
                        ]
                    );

                    if ($this->db->affected_rows() > 0) {
                        $created++;
                    }
                }
            }
        }

        return $created;
    }

    public function supersede_pending($meeting_id)
    {
        $this->db->where('meeting_id', (int) $meeting_id)
                 ->where('status', 'pending')
                 ->update(pm_table('reminders'), ['status' => 'superseded']);

        return $this->db->affected_rows();
    }

    public function cancel_pending($meeting_id)
    {
        $this->db->where('meeting_id', (int) $meeting_id)
                 ->where_in('status', ['pending', 'sending'])
                 ->update(pm_table('reminders'), ['status' => 'cancelled']);

        return $this->db->affected_rows();
    }

    /**
     * Atomically claim up to $limit due rows.
     *
     * Uses a claim token rather than SELECT ... FOR UPDATE SKIP LOCKED, which needs
     * MySQL 8.0 / MariaDB 10.6 and would silently fail on the 5.7 installs Perfex still
     * commonly runs on. A single UPDATE ... LIMIT is atomic on every supported engine:
     * whichever worker's UPDATE lands first owns those rows, and the token tells it which.
     */
    public function claim_due($limit = 50)
    {
        $table = pm_table('reminders');
        $now   = gmdate('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(16));
        $limit = max(1, (int) $limit);

        // Reclaim rows abandoned by a worker that died mid-batch, so a crash costs one
        // cycle rather than stranding the reminder forever in 'sending'.
        $stale = gmdate('Y-m-d H:i:s', time() - 900);
        $this->db->query(
            "UPDATE `{$table}` SET status = 'pending', claim_token = NULL
              WHERE status = 'sending' AND (claimed_at IS NULL OR claimed_at < ?)",
            [$stale]
        );

        $this->db->query(
            "UPDATE `{$table}`
                SET status = 'sending', claim_token = ?, claimed_at = ?
              WHERE status = 'pending' AND scheduled_utc <= ?
              ORDER BY scheduled_utc ASC
              LIMIT {$limit}",
            [$token, $now, $now]
        );

        if ($this->db->affected_rows() < 1) {
            return [];
        }

        return $this->db->query(
            "SELECT * FROM `{$table}` WHERE claim_token = ? ORDER BY scheduled_utc ASC",
            [$token]
        )->result();
    }

    public function mark_sent($reminder_id)
    {
        $this->db->where('id', (int) $reminder_id)->update(pm_table('reminders'), [
            'status'      => 'sent',
            'sent_utc'    => gmdate('Y-m-d H:i:s'),
            'claim_token' => null,
        ]);
    }

    /**
     * Failure handling: retry with backoff until the admin-configured limit, then stop.
     */
    public function mark_failed($reminder_id, $error)
    {
        $row = $this->db->where('id', (int) $reminder_id)->get(pm_table('reminders'))->row();
        if (!$row) {
            return;
        }

        $attempts = (int) $row->attempts + 1;
        $max      = (int) pm_setting('retry_max', 3);
        $backoff  = (int) pm_setting('retry_backoff_minutes', 10);

        $update = [
            'attempts'    => $attempts,
            'last_error'  => substr((string) $error, 0, 2000),
            'claim_token' => null,
        ];

        if ($attempts >= $max) {
            $update['status'] = 'failed';
        } else {
            $update['status']        = 'pending';
            $update['scheduled_utc'] = gmdate('Y-m-d H:i:s', time() + ($backoff * 60 * $attempts));
        }

        $this->db->where('id', (int) $reminder_id)->update(pm_table('reminders'), $update);
    }

    /**
     * A reminder whose window has passed while the dispatcher was down is NOT sent late --
     * "your meeting starts in 30 minutes", arriving two hours afterwards, is worse than
     * silence. It is recorded as failed with an explicit reason so the outage is visible
     * in the delivery log rather than invisible.
     */
    public function expire_missed()
    {
        $grace  = (int) pm_setting('missed_window_minutes', 15);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($grace * 60));

        $this->db->where('status', 'pending')
                 ->where('scheduled_utc <', $cutoff)
                 ->update(pm_table('reminders'), [
                     'status'     => 'failed',
                     'last_error' => 'window_missed',
                 ]);

        return $this->db->affected_rows();
    }

    public function log_delivery(array $data)
    {
        $this->db->insert(pm_table('email_logs'), array_merge([
            'channel'         => 'email',
            'delivery_status' => 'sent',
            'sent_utc'        => gmdate('Y-m-d H:i:s'),
            'retry_count'     => 0,
        ], $data));
    }

    /**
     * The reminder ladder as actually scheduled for one meeting.
     * Surfaced on the meeting page so "did the reminders get created, and when do they
     * fire" is answerable without database access -- which is exactly the question
     * acceptance testing asks first.
     */
    public function for_meeting($meeting_id)
    {
        $this->db->where('meeting_id', (int) $meeting_id);
        $this->db->order_by('scheduled_utc', 'ASC');

        return $this->db->get(pm_table('reminders'))->result();
    }

    public function delivery_log($meeting_id, $limit = 200)
    {
        $this->db->where('meeting_id', (int) $meeting_id);
        $this->db->order_by('id', 'DESC');
        $this->db->limit((int) $limit);

        return $this->db->get(pm_table('email_logs'))->result();
    }
}
