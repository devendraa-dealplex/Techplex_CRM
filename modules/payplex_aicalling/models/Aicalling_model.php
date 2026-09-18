<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Data access for calls + events + webhook inbox + outbox.
 * Enforces record-scope for non-admin staff at the query layer (IDOR/BOLA defence).
 */
class Aicalling_model extends App_Model
{
    private $tCalls;
    private $tEvents;
    private $tInbox;
    private $tOutbox;

    public function __construct()
    {
        parent::__construct();
        $this->tCalls  = db_prefix() . 'payplex_calls';
        $this->tEvents = db_prefix() . 'payplex_call_events';
        $this->tInbox  = db_prefix() . 'payplex_webhook_inbox';
        $this->tOutbox = db_prefix() . 'payplex_outbox';
    }

    /**
     * Insert a pending mirror row when a call is created from the CRM.
     */
    public function createMirror(array $data)
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->insert($this->tCalls, $data);
        return $this->db->insert_id();
    }

    public function updateBySonivoId($sonivoCallId, array $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->where('sonivo_call_id', $sonivoCallId)->update($this->tCalls, $data);
        return $this->db->affected_rows();
    }

    public function updateById($id, array $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->where('id', $id)->update($this->tCalls, $data);
        return $this->db->affected_rows();
    }

    public function getById($id)
    {
        return $this->db->where('id', $id)->get($this->tCalls)->row();
    }

    /**
     * List calls, scoped to the current staff unless they hold the view-all cap.
     * $canViewAll should be resolved by the controller from capabilities.
     */
    public function listForStaff($staffId, $canViewAll, $filters = [], $limit = 50, $offset = 0)
    {
        if (!$canViewAll) {
            $this->db->where('staff_id', (int) $staffId);
        }
        if (!empty($filters['lead_id'])) {
            $this->db->where('crm_lead_id', (int) $filters['lead_id']);
        }
        if (!empty($filters['status'])) {
            $this->db->where('status', $filters['status']);
        }
        return $this->db->order_by('created_at', 'DESC')
            ->limit((int) $limit, (int) $offset)
            ->get($this->tCalls)->result();
    }

    public function listForLead($leadId)
    {
        return $this->db->where('crm_lead_id', (int) $leadId)
            ->order_by('created_at', 'DESC')->get($this->tCalls)->result();
    }

    /**
     * The rows the §4.4 controls need: attempts, cooldown, duplicate-call
     * prevention, invalid-number and human-handoff suppression all read this.
     *
     * Bounded by a lookback window and a row cap so a long-lived lead cannot
     * make the gate progressively slower, and ordered oldest-last so the cap
     * keeps the RECENT rows — the ones every window actually cares about.
     */
    public function recentForLead($leadId, $sinceDays = 30, $limit = 200)
    {
        return $this->db
            ->select('id, created_at, status, disposition, failure_reason, cost, staff_id')
            ->where('crm_lead_id', (int) $leadId)
            ->where('created_at >=', date('Y-m-d H:i:s', time() - ((int) $sinceDays * 86400)))
            ->order_by('created_at', 'DESC')
            ->limit((int) $limit)
            ->get($this->tCalls)->result_array();
    }

    /**
     * Spend in a period, for the budget control.
     *
     * Returns null — never 0 — when the total cannot be read, because the
     * budget check must refuse on an unknown spend rather than treat it as
     * nothing spent.
     */
    public function spendSince($since, $staffId = null)
    {
        $this->db->select('SUM(cost) AS total', false)
            ->where('created_at >=', $since)
            ->where('cost IS NOT NULL', null, false);
        if ($staffId !== null) {
            $this->db->where('staff_id', (int) $staffId);
        }
        $row = $this->db->get($this->tCalls)->row_array();
        if ($row === null) { return null; }
        // SUM() over no rows is NULL, which genuinely means nothing spent.
        return $row['total'] === null ? 0.0 : (float) $row['total'];
    }

    /**
     * The distinct status and disposition values the backend has actually sent.
     *
     * The suppression lists only work if they contain the words this particular
     * telephony backend uses. Without this, configuring them is guesswork; with
     * it, an admin classifies values they can see they have received.
     *
     * @return array statuses => count, dispositions => count
     */
    public function observedCallValues($limit = 100)
    {
        $out = array('statuses' => array(), 'dispositions' => array());

        $rows = $this->db->select('status, COUNT(*) AS c', false)
            ->where('status IS NOT NULL', null, false)
            ->group_by('status')->order_by('c', 'DESC')->limit((int) $limit)
            ->get($this->tCalls)->result_array();
        foreach ($rows as $r) { $out['statuses'][(string) $r['status']] = (int) $r['c']; }

        $rows = $this->db->select('disposition, COUNT(*) AS c', false)
            ->where('disposition IS NOT NULL', null, false)
            ->where('disposition !=', '')
            ->group_by('disposition')->order_by('c', 'DESC')->limit((int) $limit)
            ->get($this->tCalls)->result_array();
        foreach ($rows as $r) { $out['dispositions'][(string) $r['disposition']] = (int) $r['c']; }

        // failure_reason feeds the invalid-number check too, so it belongs here
        $rows = $this->db->select('failure_reason, COUNT(*) AS c', false)
            ->where('failure_reason IS NOT NULL', null, false)
            ->where('failure_reason !=', '')
            ->group_by('failure_reason')->order_by('c', 'DESC')->limit((int) $limit)
            ->get($this->tCalls)->result_array();
        foreach ($rows as $r) {
            $k = (string) $r['failure_reason'];
            $out['dispositions'][$k] = (isset($out['dispositions'][$k]) ? $out['dispositions'][$k] : 0) + (int) $r['c'];
        }

        return $out;
    }

    /**
     * How many leads sit in each country, by ISO code.
     *
     * Feeds the settings screen so an administrator can see, before enabling
     * calling, how many recipients' local time can actually be determined —
     * rather than finding out one refusal at a time.
     */
    public function leadCountryIsoCounts()
    {
        $p = db_prefix();
        $rows = $this->db->query(
            "SELECT COALESCE(c.iso2, '') AS iso2, COUNT(*) AS n
             FROM {$p}leads l
             LEFT JOIN {$p}countries c ON c.country_id = l.country
             GROUP BY iso2"
        )->result_array();

        $out = array();
        foreach ($rows as $r) {
            $iso = strtoupper(trim((string) $r['iso2']));
            $out[$iso] = (isset($out[$iso]) ? $out[$iso] : 0) + (int) $r['n'];
        }
        return $out;
    }

    /**
     * Ownership check for record-level authorization.
     */
    public function isOwnedBy($callId, $staffId)
    {
        $row = $this->db->select('staff_id')->where('id', $callId)->get($this->tCalls)->row();
        return $row && (int) $row->staff_id === (int) $staffId;
    }

    /* ---------------- events / ordering ---------------- */

    public function eventSeen($eventId)
    {
        return (bool) $this->db->where('event_id', $eventId)->count_all_results($this->tEvents);
    }

    public function lastSequence($callId)
    {
        $row = $this->db->select_max('sequence', 'maxseq')->where('call_id', $callId)->get($this->tEvents)->row();
        return $row && $row->maxseq !== null ? (int) $row->maxseq : -1;
    }

    /**
     * A call's full event history in the order they occurred, for the call
     * detail / timeline view. Sequence is the backend's own ordering; id is
     * the tiebreaker for events recorded with an equal or missing sequence.
     */
    public function eventsForCall($callId)
    {
        return $this->db->where('call_id', (int) $callId)
            ->order_by('sequence', 'ASC')->order_by('id', 'ASC')
            ->get($this->tEvents)->result();
    }

    public function recordEvent($callId, $eventId, $event, $sequence, $occurredAt, $correlationId, $payload)
    {
        $this->db->insert($this->tEvents, [
            'call_id' => $callId,
            'event_id' => $eventId,
            'event' => $event,
            'sequence' => (int) $sequence,
            'occurred_at' => $occurredAt,
            'correlation_id' => $correlationId,
            'payload_json' => json_encode($payload),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->db->insert_id();
    }

    /* ---------------- webhook inbox (idempotency) ---------------- */

    public function webhookAlreadyProcessed($eventId)
    {
        $row = $this->db->select('processed')->where('event_id', $eventId)->get($this->tInbox)->row();
        return $row && (int) $row->processed === 1;
    }

    public function storeInbox($eventId, $sigValid, $tsValid, $rawBody)
    {
        // INSERT IGNORE semantics via unique(event_id)
        $this->db->query(
            "INSERT IGNORE INTO `{$this->tInbox}` (event_id, signature_valid, timestamp_valid, processed, raw_body, received_at)
             VALUES (?,?,?,0,?,?)",
            [$eventId, $sigValid ? 1 : 0, $tsValid ? 1 : 0, $rawBody, date('Y-m-d H:i:s')]
        );
    }

    public function markInboxProcessed($eventId)
    {
        $this->db->where('event_id', $eventId)->update($this->tInbox, [
            'processed' => 1, 'processed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /* ---------------- outbox (reconciliation) ---------------- */

    public function queueOutbox($idempotencyKey, $endpoint, $method, $payload, $correlationId)
    {
        $this->db->query(
            "INSERT IGNORE INTO `{$this->tOutbox}` (idempotency_key, endpoint, method, payload_json, status, attempts, correlation_id, created_at)
             VALUES (?,?,?,?, 'pending', 0, ?, ?)",
            [$idempotencyKey, $endpoint, $method, json_encode($payload), $correlationId, date('Y-m-d H:i:s')]
        );
    }

    public function pendingOutbox($limit = 50)
    {
        return $this->db->where_in('status', ['pending', 'failed'])
            ->group_start()->where('next_retry_at <=', date('Y-m-d H:i:s'))->or_where('next_retry_at', null)->group_end()
            ->limit($limit)->get($this->tOutbox)->result();
    }

    public function markOutbox($id, $status, $error = null, $nextRetryAt = null)
    {
        $this->db->where('id', $id)->update($this->tOutbox, [
            'status' => $status,
            'last_error' => $error ? substr($error, 0, 255) : null,
            'next_retry_at' => $nextRetryAt,
            'attempts' => $this->getOutboxAttempts($id) + 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function getOutboxAttempts($id)
    {
        $row = $this->db->select('attempts')->where('id', $id)->get($this->tOutbox)->row();
        return $row ? (int) $row->attempts : 0;
    }
}
