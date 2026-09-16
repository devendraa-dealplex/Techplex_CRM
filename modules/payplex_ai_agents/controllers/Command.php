<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Command — Chairman Command Centre, Decision Inbox and the approval matrix.
 *
 * Decision packets flow: agent/admin creates a packet (draft) -> submit ->
 * the Chairman (or a delegate within limit for non-chairman tiers) approves,
 * rejects or returns it. Enforced by the pure governance libraries:
 *  - maker can never approve their own packet
 *  - no approval on an incomplete packet (no blind one-click approvals)
 *  - high-risk / irreversible / unknown actions require the Chairman
 */
class Command extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_ai_agents/payplex_ai_agents_model', 'm');
    }

    private function guard($cap)
    {
        if (!payplex_ai_agents_can($cap)) {
            access_denied('payplex_ai_agents');
        }
    }

    private function actor()
    {
        return function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0;
    }

    /** Derive the acting staff member's approval authority tier. */
    private function actorTier()
    {
        if (function_exists('is_admin') && is_admin()) { return 'chairman'; }
        if (payplex_ai_agents_can('chairman')) { return 'chairman'; }
        if (payplex_ai_agents_can('approve') || payplex_ai_agents_can('activate')) { return 'manager'; }
        return 'auto';
    }

    private function isAdmin()
    {
        return function_exists('is_admin') && is_admin();
    }

    /** Chairman Command Centre dashboard. */
    public function index()
    {
        $this->guard('view');
        $company = (string) $this->input->get('company');
        $data['title']       = 'Chairman Command Centre';
        $data['summary']     = $this->m->commandCentreSummaryScoped($this->actor(), $this->isAdmin(), $company);
        $data['actorTier']   = $this->actorTier();
        $data['companies']   = $this->m->companies(true);
        $data['companyView'] = $company;
        $this->load->view('payplex_ai_agents/command_centre', $data);
    }

    /** Decision Inbox — list, filterable by status and company. */
    public function inbox()
    {
        $this->guard('view');
        $status = $this->input->get('status');
        $allowed = array('submitted', 'approved', 'rejected', 'returned', 'delegated', 'draft', 'executed');
        $status = in_array($status, $allowed, true) ? $status : 'submitted';
        $company = (string) $this->input->get('company');
        $data['title']       = 'Decision Inbox';
        $data['status']      = $status;
        $data['decisions']   = $this->m->decisionsScoped($status, $this->actor(), $this->isAdmin(), $company, 200);
        $data['actorTier']   = $this->actorTier();
        $data['companies']   = $this->m->companies(true);
        $data['companyView'] = $company;
        $this->load->view('payplex_ai_agents/decision_inbox', $data);
    }

    /** Full decision packet + action controls. */
    public function view($id)
    {
        $this->guard('view');
        $d = $this->m->decision((int) $id);
        if (!$d) { set_alert('warning', 'Decision not found.'); redirect(admin_url('payplex_ai_agents/command/inbox')); }
        $data['title']       = 'Decision #' . (int) $id;
        $data['d']           = $d;
        $data['completeness']= Payplex_agent_decision::completeness((array) $d);
        $data['allowed']     = Payplex_agent_decision::allowedActions($d->status);
        $data['actorTier']   = $this->actorTier();
        $data['isMaker']     = ((int) $d->created_by === $this->actor());
        $this->load->view('payplex_ai_agents/decision_view', $data);
    }

    /** New decision packet form (manual / agent-assisted submission). */
    public function create()
    {
        $this->guard('decisions');
        $data['title']  = 'New Decision Packet';
        $data['agents'] = $this->m->get();
        $this->load->view('payplex_ai_agents/decision_form', $data);
    }

    public function store()
    {
        $this->guard('decisions');
        $id = $this->m->createDecision($this->input->post(), $this->actor());
        set_alert('success', 'Decision packet #' . $id . ' created as draft. Review completeness, then submit to the Chairman.');
        redirect(admin_url('payplex_ai_agents/command/view/' . (int) $id));
    }

    /** Governance transitions. */
    public function act($id, $action)
    {
        // capability: submitting needs 'decisions'; deciding needs at least 'approve'
        if (in_array($action, array('approve', 'reject', 'return', 'delegate'), true)) {
            $this->guard('approve');
        } else {
            $this->guard('decisions');
        }
        $note = (string) $this->input->post('note');
        $res  = $this->m->decideOn((int) $id, $action, $this->actor(), $this->actorTier(), $note, $this->isAdmin());
        if (!empty($res['ok'])) {
            set_alert('success', 'Decision #' . (int) $id . ' -> ' . $res['status'] . '.');
        } else {
            $msg = 'Could not ' . $action . ' decision: ' . $res['error'];
            if (!empty($res['missing'])) { $msg .= ' (missing: ' . implode(', ', $res['missing']) . ')'; }
            set_alert('warning', $msg);
        }
        redirect(admin_url('payplex_ai_agents/command/view/' . (int) $id));
    }

    /** Approval matrix configuration. */
    public function matrix()
    {
        $this->guard('budgets');
        if ($this->input->post('action_key')) {
            $this->m->saveMatrixRow(
                $this->input->post('action_key'),
                $this->input->post('tier'),
                (float) $this->input->post('manager_limit'),
                $this->actor()
            );
            set_alert('success', 'Approval rule saved.');
            redirect(admin_url('payplex_ai_agents/command/matrix'));
        }
        $data['title']  = 'Approval Matrix';
        $data['rows']   = $this->m->approvalMatrix();
        $this->load->view('payplex_ai_agents/approval_matrix', $data);
    }

    /* ---------------- M6: Executive Council ---------------- */

    /** Council room for a decision. */
    public function council($id)
    {
        $this->guard('view');
        $d = $this->m->decision((int) $id);
        if (!$d) { set_alert('warning', 'Decision not found.'); redirect(admin_url('payplex_ai_agents/command/inbox')); }
        $data['title']    = 'Executive Council — Decision #' . (int) $id;
        $data['d']        = $d;
        $data['council']  = $this->m->councilFor((int) $id);
        $data['reviews']  = $this->m->councilReviews((int) $id);
        $data['roster']   = Payplex_agent_council::roster();
        $data['missing']  = $this->m->councilMissingRoles((int) $id);
        $data['preview']  = $data['council'] ? $this->m->councilPreview((int) $id) : null;
        $data['stances']  = Payplex_agent_council::validStances();
        $this->load->view('payplex_ai_agents/council_room', $data);
    }

    public function convene($id)
    {
        $this->guard('decisions');
        $cid = $this->m->conveneCouncil((int) $id, $this->actor());
        set_alert($cid ? 'success' : 'warning', $cid ? 'Executive Council convened. Each reviewer can now file their review.' : 'Could not convene council.');
        redirect(admin_url('payplex_ai_agents/command/council/' . (int) $id));
    }

    /** Submit one reviewer role's review. */
    public function review($id)
    {
        $this->guard('decisions');
        $ok = $this->m->submitCouncilReview(
            (int) $id,
            (string) $this->input->post('role'),
            (string) $this->input->post('stance'),
            $this->input->post('confidence'),
            (string) $this->input->post('comments'),
            (string) $this->input->post('impact'),
            (int) $this->input->post('reviewer_agent_id'),
            $this->actor()
        );
        set_alert($ok ? 'success' : 'warning', $ok ? 'Council review recorded.' : 'Could not record review (convene the council first).');
        redirect(admin_url('payplex_ai_agents/command/council/' . (int) $id));
    }

    /** Group CEO consolidation step. */
    public function consolidate($id)
    {
        $this->guard('approve');
        $res = $this->m->consolidateCouncil((int) $id, $this->actor());
        if (!empty($res['ok'])) {
            set_alert('success', 'Council consolidated: ' . $res['recommendation'] . '. Dissent preserved. The Chairman makes the final decision.');
        } else {
            set_alert('warning', 'Could not consolidate: ' . (isset($res['error']) ? $res['error'] : 'unknown'));
        }
        redirect(admin_url('payplex_ai_agents/command/council/' . (int) $id));
    }
}
