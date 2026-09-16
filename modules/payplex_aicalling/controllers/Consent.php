<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admin Consent / DND management. All changes are audited.
 * Grant/withdraw/DND are state-changing => permission-gated + logged.
 */
class Consent extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_aicalling/payplex_consent_model');
        $this->load->model('payplex_aicalling/payplex_audit_model');
    }

    private function cap($c)
    {
        return is_admin() || staff_can($c, 'payplex_aicalling');
    }

    public function index()
    {
        if (!$this->cap('consent_view')) {
            access_denied('payplex_aicalling consent');
        }
        // Show current consent state per lead (joined minimal fields).
        $data['title'] = 'Consent & DND';
        $data['rows']  = $this->db->query(
            "SELECT c.* FROM " . db_prefix() . "payplex_consent c
             INNER JOIN (SELECT MAX(id) mid FROM " . db_prefix() . "payplex_consent GROUP BY subject_type,subject_id,channel) l
             ON c.id = l.mid ORDER BY c.created_at DESC LIMIT 200"
        )->result();
        $this->load->view('payplex_aicalling/consent', $data);
    }

    /** Record a consent/DND change (AJAX). */
    public function set()
    {
        if (!$this->cap('consent_manage')) {
            ajax_access_denied();
        }
        if (!$this->input->is_ajax_request()) {
            ajax_access_denied();
        }
        $subjectType = (string) $this->input->post('subject_type');
        $subjectId   = (int) $this->input->post('subject_id');
        $channel     = (string) ($this->input->post('channel') ?: 'call');
        $state       = $this->input->post('state');   // granted | withdrawn

        if (!$subjectId || !in_array($state, ['granted', 'withdrawn'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid input.']);
            return;
        }
        /*
         * Validate the subject and channel before anything is read or written.
         * An unrecognised value used to be coerced or stored verbatim, which
         * put a decision in the ledger about the wrong person, or on a channel
         * no gate reads.
         */
        if (!in_array($subjectType, Payplex_consent_model::subjectTypes(), true)) {
            echo json_encode(['success' => false, 'message' => 'Unknown subject type.']);
            return;
        }
        if (!in_array($channel, Payplex_consent_model::channels(), true)) {
            echo json_encode(['success' => false, 'message' => 'Unknown channel.']);
            return;
        }

        $before = $this->payplex_consent_model->current($subjectType, $subjectId, $channel);

        /*
         * DND: absent must mean "unchanged", never "off".
         *
         * This read $this->input->post('dnd') ? 1 : 0, so a request that simply
         * did not mention dnd wrote 0 — and because the latest row is the
         * authoritative one, that silently took a person who had asked not to
         * be called back off the do-not-call list. The same shape as the kill
         * switch that could not be switched off: an absent field is not a
         * decision, and a safety flag must never be cleared by omission.
         *
         * The interface always sends the key (1 or ''), so an explicit "off"
         * still works; this only changes what happens when nobody said.
         */
        $dndRaw = $this->input->post('dnd');
        if ($dndRaw === null) {
            $dnd = $before ? (int) $before->dnd : 0;
        } else {
            $dnd = in_array((string) $dndRaw, array('1', 'true', 'on', 'yes'), true) ? 1 : 0;
        }
        $id = $this->payplex_consent_model->record($subjectType, $subjectId, $channel, $state, $dnd, 'admin_manual');
        $this->payplex_audit_model->log('consent.changed', $subjectType, $subjectId,
            $before ? ['state' => $before->state, 'dnd' => $before->dnd] : null,
            ['state' => $state, 'dnd' => $dnd,
             'dnd_source' => $dndRaw === null ? 'unchanged (not supplied)' : 'explicit']);

        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'The consent row was refused.']);
            return;
        }
        echo json_encode(['success' => true]);
    }
}
