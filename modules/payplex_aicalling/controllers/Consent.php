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

    /** Record a consent/DND change for one lead (AJAX). */
    public function set()
    {
        if (!$this->cap('consent_manage')) {
            ajax_access_denied();
        }
        if (!$this->input->is_ajax_request()) {
            ajax_access_denied();
        }
        $res = $this->applyConsent(
            (string) $this->input->post('subject_type'),
            (int) $this->input->post('subject_id'),
            (string) ($this->input->post('channel') ?: 'call'),
            $this->input->post('state'),
            $this->input->post('dnd')
        );
        echo json_encode($res === true ? ['success' => true] : ['success' => false, 'message' => $res]);
    }

    /**
     * Same rule as set(), applied to every lead in subject_ids — this used to
     * only ever sync one lead at a time. action picks what changes:
     *   grant/withdraw touch consent only (dnd stays whatever it already was);
     *   dnd_on/dnd_off touch DND only (consent state stays whatever it already was).
     */
    public function bulk_set()
    {
        if (!$this->cap('consent_manage')) {
            ajax_access_denied();
        }
        if (!$this->input->is_ajax_request()) {
            ajax_access_denied();
        }
        $actions = [
            'grant'    => ['granted', null],
            'withdraw' => ['withdrawn', null],
            'dnd_on'   => [null, '1'],
            'dnd_off'  => [null, '0'],
        ];
        $action = (string) $this->input->post('action');
        if (!isset($actions[$action])) {
            echo json_encode(['success' => false, 'message' => 'Unknown bulk action.']);
            return;
        }
        [$state, $dnd] = $actions[$action];
        $subjectType = (string) $this->input->post('subject_type');
        $channel     = (string) ($this->input->post('channel') ?: 'call');

        $updated = 0;
        $failed  = [];
        foreach (array_unique(array_filter(array_map('intval', (array) $this->input->post('subject_ids')))) as $id) {
            if ($this->applyConsent($subjectType, $id, $channel, $state, $dnd) === true) {
                $updated++;
            } else {
                $failed[] = $id;
            }
        }
        echo json_encode(['success' => $updated > 0, 'updated' => $updated, 'failed' => $failed]);
    }

    /**
     * One lead's consent/DND write. $state === null keeps the lead's existing
     * state (used by the bulk DND-only actions); $dndRaw === null keeps the
     * existing DND flag (used by the bulk consent-only actions) — the same
     * "absent means unchanged" rule set() already enforced for dnd, now
     * available for state too.
     */
    private function applyConsent($subjectType, $subjectId, $channel, $state, $dndRaw)
    {
        if (!$subjectId) {
            return 'Invalid input.';
        }
        if (!in_array($subjectType, Payplex_consent_model::subjectTypes(), true)) {
            return 'Unknown subject type.';
        }
        if (!in_array($channel, Payplex_consent_model::channels(), true)) {
            return 'Unknown channel.';
        }

        $before = $this->payplex_consent_model->current($subjectType, $subjectId, $channel);

        if ($state === null) {
            if (!$before) {
                return 'No existing consent state to keep for this lead.';
            }
            $state = $before->state;
        } elseif (!in_array($state, ['granted', 'withdrawn'], true)) {
            return 'Invalid input.';
        }

        $dnd = $dndRaw === null
            ? ($before ? (int) $before->dnd : 0)
            : (in_array((string) $dndRaw, array('1', 'true', 'on', 'yes'), true) ? 1 : 0);

        $id = $this->payplex_consent_model->record($subjectType, $subjectId, $channel, $state, $dnd, 'admin_manual');
        $this->payplex_audit_model->log('consent.changed', $subjectType, $subjectId,
            $before ? ['state' => $before->state, 'dnd' => $before->dnd] : null,
            ['state' => $state, 'dnd' => $dnd,
             'dnd_source' => $dndRaw === null ? 'unchanged (not supplied)' : 'explicit']);

        return $id ? true : 'The consent row was refused.';
    }
}
