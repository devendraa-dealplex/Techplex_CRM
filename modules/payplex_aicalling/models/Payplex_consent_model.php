<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Consent / DND ledger — append-only. Current state = latest row per
 * (subject_type, subject_id, channel). This is the AUTHORITY the calling gate
 * checks (fail-closed): a call is allowed only when the latest row is
 * state='granted' AND dnd=0.
 */
class Payplex_consent_model extends App_Model
{
    private $table;

    public function __construct()
    {
        parent::__construct();
        $this->table = db_prefix() . 'payplex_consent';
    }

    /** Append a consent/DND event (never updates existing rows). */
    /**
     * Subjects and channels a consent row may be recorded against.
     *
     * A row written on any other channel is a record nobody consults: the call
     * gate reads channel 'call' and nothing else, so consent — or, worse, a
     * WITHDRAWAL — filed under 'calls' or 'sms' would sit in the ledger looking
     * authoritative while the gate never saw it. Adding a channel here is only
     * half the job; the gate that reads it has to exist too.
     */
    public static function subjectTypes() { return array('lead', 'customer'); }
    public static function channels()     { return array('call'); }

    public function record($subjectType, $subjectId, $channel, $state, $dnd = 0, $source = null, $evidenceRef = null)
    {
        /*
         * Refuse an unknown subject or channel; never coerce.
         *
         * This silently rewrote any unrecognised subject_type to 'lead', so a
         * consent decision meant for customer #7 was filed against LEAD #7 —
         * a record about the wrong person, written without complaint. And an
         * unrecognised channel was stored verbatim, producing a row the call
         * gate never reads. Both are worse than a rejected write, because both
         * leave something in the ledger that looks like a decision.
         */
        if (!in_array($subjectType, self::subjectTypes(), true)) { return 0; }
        if (!in_array((string) $channel, self::channels(), true)) { return 0; }

        $this->db->insert($this->table, [
            'subject_type'   => $subjectType,
            'subject_id'     => (int) $subjectId,
            'channel'        => (string) $channel,
            'state'          => $state === 'granted' ? 'granted' : 'withdrawn',
            'dnd'            => $dnd ? 1 : 0,
            'source'         => $source,
            'evidence_ref'   => $evidenceRef,
            'actor_staff_id' => function_exists('get_staff_user_id') ? get_staff_user_id() : null,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        return $this->db->insert_id();
    }

    /** Latest state row, or null. */
    public function current($subjectType, $subjectId, $channel = 'call')
    {
        return $this->db->where('subject_type', $subjectType)
            ->where('subject_id', (int) $subjectId)
            ->where('channel', $channel)
            ->order_by('id', 'DESC')->limit(1)
            ->get($this->table)->row();
    }

    /**
     * The consent+DND half of the call gate. Returns true only when calling is
     * permitted on consent grounds; ownership and calling hours are decided by
     * Payplex_call_gates::evaluate(), which is what start_call() uses.
     *
     * This method used to describe itself as "the single call-gate check" while
     * the controller ignored it and rolled its own — reading DND from the leads
     * table, which has no such column, so its check always answered "not on
     * DND". Correct code sat here, unused, next to broken code that ran. Both
     * now resolve through the same library so there is one implementation to
     * be right or wrong.
     */
    public function callingAllowed($subjectType, $subjectId, $channel = 'call')
    {
        require_once __DIR__ . '/../libraries/Payplex_call_gates.php';

        $row = $this->current($subjectType, $subjectId, $channel);
        if (Payplex_call_gates::consentState($row) !== 'granted') {
            return false;
        }
        $dnd = Payplex_call_gates::dndState($row);
        return $dnd['blocked'] === false;
    }

    /** Full history for the audit/timeline view. */
    public function history($subjectType, $subjectId, $channel = null)
    {
        $this->db->where('subject_type', $subjectType)->where('subject_id', (int) $subjectId);
        if ($channel) {
            $this->db->where('channel', $channel);
        }
        return $this->db->order_by('id', 'DESC')->get($this->table)->result();
    }
}
