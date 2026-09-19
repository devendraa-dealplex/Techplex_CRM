<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Goals — executive Objectives & Key Results (OKRs / KPIs) and the agent
 * performance dashboard.
 *
 * Objectives are company-scoped (M7 RBAC). Progress is computed deterministically
 * from each key result's baseline -> current -> target, and health compares that
 * progress against how much of the period has elapsed. Agent performance is
 * aggregated live from real decision-packet and council-review activity and
 * scored with the deterministic scorecard — no vanity defaults.
 */
class Goals extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_ai_agents/payplex_ai_agents_model', 'm');
    }

    private function guard($cap)
    {
        if (!payplex_ai_agents_can($cap)) { access_denied('payplex_ai_agents'); }
    }

    private function actor()
    {
        return function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0;
    }

    private function isAdmin()
    {
        return function_exists('is_admin') && is_admin();
    }

    /** State changes must be POST (Perfex CSRF only protects POST; a GET link would bypass it). */
    private function requirePost()
    {
        if ($this->input->method() !== 'post') {
            set_alert('warning', 'Invalid request.');
            redirect(admin_url('payplex_ai_agents/goals'));
        }
    }

    /** Load an objective the actor may access, or bounce with a generic "not found". */
    private function accessibleObjective($id)
    {
        $o = $this->m->objGet((int) $id);
        if (!$o || !$this->m->objCanAccess($o, $this->actor(), $this->isAdmin())) {
            set_alert('warning', 'Objective not found.');
            redirect(admin_url('payplex_ai_agents/goals'));
        }
        return $o;
    }

    private function defaultPeriod()
    {
        $q = (int) ceil(((int) date('n')) / 3);
        return date('Y') . '-Q' . $q;
    }

    /* ---------------- Objectives ---------------- */

    public function index()
    {
        $this->guard('view');
        $company = (string) $this->input->get('company');
        $period  = Payplex_agent_goals::normalizePeriod((string) $this->input->get('period'));
        $status  = (string) $this->input->get('status');
        $allowedS = array('draft', 'active', 'achieved', 'missed', 'cancelled');
        $statusF  = in_array($status, $allowedS, true) ? $status : '';

        $objectives = $this->m->objScoped($this->actor(), $this->isAdmin(), $company, $period !== '' ? $period : null, $statusF !== '' ? $statusF : null);
        // decorate with progress + health
        $rows = array();
        foreach ($objectives as $o) {
            $krs      = $this->m->objKeyResults($o->id);
            $progress = Payplex_agent_goals::objectiveProgress($krs);
            $elapsed  = Payplex_agent_goals::elapsedFraction($o->period);
            $rows[] = array(
                'o' => $o, 'kr_count' => count($krs), 'progress' => $progress,
                'elapsed' => $elapsed, 'health' => Payplex_agent_goals::displayHealth($o->status, count($krs), $progress, $elapsed),
            );
        }

        $data['title']       = 'Goals & OKRs';
        $data['rows']        = $rows;
        $data['companies']   = $this->m->companies(true);
        $data['companyView'] = $company;
        $data['period']      = $period;
        $data['status']      = $statusF;
        $data['canManage']   = payplex_ai_agents_can('goals');
        $this->load->view('payplex_ai_agents/goals_list', $data);
    }

    public function create()
    {
        $this->guard('goals');
        $data['title']         = 'New Objective';
        $data['companies']     = $this->m->companies(true);
        $data['defaultPeriod'] = $this->defaultPeriod();
        $this->load->view('payplex_ai_agents/goals_form', $data);
    }

    public function store()
    {
        $this->guard('goals');
        $this->requirePost();
        $res = $this->m->objCreate($this->input->post(), $this->actor());
        if (!empty($res['ok'])) {
            set_alert('success', 'Objective #' . $res['id'] . ' created. Add key results to make it measurable.');
            redirect(admin_url('payplex_ai_agents/goals/view/' . (int) $res['id']));
        }
        set_alert('warning', 'Could not create objective: ' . implode(', ', $res['errors']));
        redirect(admin_url('payplex_ai_agents/goals/create'));
    }

    public function view($id)
    {
        $this->guard('view');
        $o = $this->accessibleObjective($id);
        $krs = $this->m->objKeyResults($o->id);
        $krRows = array();
        foreach ($krs as $kr) { $krRows[] = array('kr' => $kr, 'progress' => Payplex_agent_goals::keyResultProgress($kr)); }
        $progress = Payplex_agent_goals::objectiveProgress($krs);
        $elapsed  = Payplex_agent_goals::elapsedFraction($o->period);

        $data['title']     = 'Objective #' . (int) $id;
        $data['o']         = $o;
        $data['krRows']    = $krRows;
        $data['progress']  = $progress;
        $data['elapsed']   = $elapsed;
        $data['health']    = Payplex_agent_goals::displayHealth($o->status, count($krs), $progress, $elapsed);
        $data['bounds']    = Payplex_agent_goals::periodBounds($o->period);
        $data['canManage'] = payplex_ai_agents_can('goals');
        $this->load->view('payplex_ai_agents/goals_view', $data);
    }

    public function addkr($objId)
    {
        $this->guard('goals');
        $this->requirePost();
        $this->accessibleObjective($objId);
        $res = $this->m->krAdd((int) $objId, $this->input->post(), $this->actor());
        set_alert(!empty($res['ok']) ? 'success' : 'warning',
            !empty($res['ok']) ? 'Key result added.' : 'Could not add key result: ' . implode(', ', $res['errors']));
        redirect(admin_url('payplex_ai_agents/goals/view/' . (int) $objId));
    }

    public function updatekr($objId)
    {
        $this->guard('goals');
        $this->requirePost();
        $this->accessibleObjective($objId);
        $res = $this->m->krUpdateCurrent((int) $this->input->post('kr_id'), $this->input->post('current'), $this->actor(), (int) $objId);
        set_alert(!empty($res['ok']) ? 'success' : 'warning',
            !empty($res['ok']) ? 'Progress updated.' : 'Could not update: ' . str_replace('_', ' ', (string) $res['error']));
        redirect(admin_url('payplex_ai_agents/goals/view/' . (int) $objId));
    }

    public function act($id, $action)
    {
        $this->guard('goals');
        $this->requirePost();
        $this->accessibleObjective($id);
        $res = $this->m->objTransition((int) $id, $action, $this->actor());
        set_alert(!empty($res['ok']) ? 'success' : 'warning',
            !empty($res['ok']) ? 'Objective -> ' . $res['status'] . '.' : 'Cannot ' . $action . ': ' . str_replace('_', ' ', (string) $res['error']));
        redirect(admin_url('payplex_ai_agents/goals/view/' . (int) $id));
    }

    /* ---------------- Agent performance ---------------- */

    public function performance()
    {
        $this->guard('view');
        $company = (string) $this->input->get('company');
        $cards = $this->m->agentPerformance($this->actor(), $this->isAdmin(), $company);
        $data['title']       = 'Agent Performance';
        $data['cards']       = $cards;
        $data['companies']   = $this->m->companies(true);
        $data['companyView'] = $company;
        $this->load->view('payplex_ai_agents/performance', $data);
    }
}
