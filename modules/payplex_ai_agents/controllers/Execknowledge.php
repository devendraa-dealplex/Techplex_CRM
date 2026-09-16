<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Execknowledge — Executive Knowledge Base + Executive Memory.
 *
 * The governed, citable corpus the C-suite AI agents may ground their decisions
 * on. Every entry follows the same maker != approver rule as decision packets,
 * and only APPROVED, in-effect entries are ever citable (anti-hallucination).
 *
 * Row-level company scoping (M7) applies throughout: a staff member sees only
 * the companies they are granted; admins (Chairman-level) see the whole group.
 */
class Execknowledge extends AdminController
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

    private function isAdmin()
    {
        return function_exists('is_admin') && is_admin();
    }

    /** Can the actor approve/return/archive executive knowledge? */
    private function canApprove()
    {
        return $this->isAdmin() || payplex_ai_agents_can('exec_knowledge_approve');
    }

    /* ---------------- Knowledge Base ---------------- */

    public function index()
    {
        $this->guard('view');
        $status  = (string) $this->input->get('status');
        $allowed = array('draft', 'review', 'approved', 'returned', 'archived');
        $statusF = in_array($status, $allowed, true) ? $status : '';
        $company = (string) $this->input->get('company');

        $data['title']       = 'Executive Knowledge';
        $data['entries']     = $this->m->ekScoped($this->actor(), $this->isAdmin(), $company, $statusF !== '' ? $statusF : null);
        $data['status']      = $statusF;
        $data['companies']   = $this->m->companies(true);
        $data['companyView'] = $company;
        $data['canManage']   = payplex_ai_agents_can('exec_knowledge');
        $data['canApprove']  = $this->canApprove();
        $this->load->view('payplex_ai_agents/execknowledge_list', $data);
    }

    public function create()
    {
        $this->guard('exec_knowledge');
        $data['title']      = 'New Executive Knowledge';
        $data['entry']      = null;
        $data['categories'] = Payplex_agent_exec_knowledge::categories();
        $data['companies']  = $this->m->companies(true);
        $this->load->view('payplex_ai_agents/execknowledge_form', $data);
    }

    public function store()
    {
        $this->guard('exec_knowledge');
        $res = $this->m->ekCreate($this->input->post(), $this->actor());
        if (!empty($res['ok'])) {
            $msg = 'Knowledge entry #' . $res['id'] . ' saved as draft.';
            if (!empty($res['warnings'])) { $msg .= ' Note: ' . implode(', ', $res['warnings']) . '.'; }
            set_alert('success', $msg);
            redirect(admin_url('payplex_ai_agents/execknowledge/view/' . (int) $res['id']));
        }
        set_alert('warning', 'Could not save: ' . implode(', ', $res['errors']));
        redirect(admin_url('payplex_ai_agents/execknowledge/create'));
    }

    public function edit($id)
    {
        $this->guard('exec_knowledge');
        $entry = $this->m->ekGet((int) $id);
        if (!$entry) { set_alert('warning', 'Entry not found.'); redirect(admin_url('payplex_ai_agents/execknowledge')); }
        if ($this->input->post()) {
            $res = $this->m->ekUpdate((int) $id, $this->input->post(), $this->actor());
            if (!empty($res['ok'])) { set_alert('success', 'Entry updated.'); }
            else { set_alert('warning', 'Could not update: ' . implode(', ', $res['errors'])); }
            redirect(admin_url('payplex_ai_agents/execknowledge/view/' . (int) $id));
        }
        $data['title']      = 'Edit Executive Knowledge #' . (int) $id;
        $data['entry']      = $entry;
        $data['categories'] = Payplex_agent_exec_knowledge::categories();
        $data['companies']  = $this->m->companies(true);
        $this->load->view('payplex_ai_agents/execknowledge_form', $data);
    }

    public function view($id)
    {
        $this->guard('view');
        $entry = $this->m->ekGet((int) $id);
        if (!$entry) { set_alert('warning', 'Entry not found.'); redirect(admin_url('payplex_ai_agents/execknowledge')); }
        $data['title']      = 'Executive Knowledge #' . (int) $id;
        $data['entry']      = $entry;
        $data['citable']    = Payplex_agent_exec_knowledge::isCitable($entry);
        $data['provenance'] = Payplex_agent_memory::provenance($entry);
        $data['isMaker']    = ((int) $entry->created_by === $this->actor()) || ((int) $entry->updated_by === $this->actor());
        $data['canManage']  = payplex_ai_agents_can('exec_knowledge');
        $data['canApprove'] = $this->canApprove();
        $this->load->view('payplex_ai_agents/execknowledge_view', $data);
    }

    /** Governance transition: submit | approve | return | archive | reopen. */
    public function act($id, $action)
    {
        if (in_array($action, array('approve', 'return', 'archive', 'reopen'), true)) {
            if (!$this->canApprove()) { access_denied('payplex_ai_agents'); }
        } else {
            $this->guard('exec_knowledge');
        }
        $res = $this->m->ekTransition((int) $id, $action, $this->actor(), $this->canApprove());
        if (!empty($res['ok'])) {
            set_alert('success', 'Entry ' . $action . ' → ' . $res['status'] . '.');
        } else {
            set_alert('warning', 'Cannot ' . $action . ': ' . str_replace('_', ' ', (string) $res['error']));
        }
        redirect(admin_url('payplex_ai_agents/execknowledge/view/' . (int) $id));
    }

    /** Grounded retrieval demo — deterministic, only cites APPROVED in-effect entries. */
    public function search()
    {
        $this->guard('view');
        $q       = (string) $this->input->get('q');
        $company = (string) $this->input->get('company');
        $cat     = (string) $this->input->get('category');
        $opts    = array('limit' => 10);
        if ($cat !== '') { $opts['category'] = $cat; }

        $results = $q !== '' ? $this->m->ekRetrieve($q, $this->actor(), $this->isAdmin(), $company, $opts) : array();
        $data['title']       = 'Knowledge Retrieval';
        $data['q']           = $q;
        $data['results']     = $results;
        $data['categories']  = Payplex_agent_exec_knowledge::categories();
        $data['category']    = $cat;
        $data['companies']   = $this->m->companies(true);
        $data['companyView'] = $company;
        $this->load->view('payplex_ai_agents/execknowledge_search', $data);
    }

    /* ---------------- Executive Memory ---------------- */

    public function memory()
    {
        $this->guard('view');
        $company = (string) $this->input->get('company');
        $kind    = (string) $this->input->get('kind');
        $data['title']       = 'Executive Memory';
        $data['records']     = $this->m->emScoped($this->actor(), $this->isAdmin(), $company, $kind !== '' ? $kind : null);
        $data['kind']        = $kind;
        $data['companies']   = $this->m->companies(true);
        $data['companyView'] = $company;
        $data['agents']      = $this->m->get();
        $data['canManage']   = payplex_ai_agents_can('exec_knowledge');
        $this->load->view('payplex_ai_agents/execmemory', $data);
    }

    public function captureMemory()
    {
        $this->guard('exec_knowledge');
        $res = $this->m->emCapture($this->input->post(), $this->actor());
        set_alert(!empty($res['ok']) ? 'success' : 'warning',
            !empty($res['ok']) ? 'Memory #' . $res['id'] . ' captured.' : 'Could not capture: ' . str_replace('_', ' ', (string) $res['error']));
        redirect(admin_url('payplex_ai_agents/execknowledge/memory'));
    }
}
