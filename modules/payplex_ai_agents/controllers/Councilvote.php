<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Councilvote — Executive Council motions & weighted voting (M11).
 *
 * The council (M6) reviews a decision across dimensions; this controller adds
 * the layer above it: a formal MOTION is put to the council and decided by a
 * WEIGHTED VOTE under an explicit quorum + threshold rule. Every dissent is
 * preserved. A vote is only ever a RECOMMENDATION — a binding or externally
 * consequential motion is flagged "needs human" and, even when it passes, must
 * be ratified by the Chairman (never the proposer's creator) and routed through
 * a Decision Packet. Company scoping (M7) and the global kill switch both apply.
 */
class Councilvote extends AdminController
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

    /** Only an admin or a holder of the 'ratify' cap may ratify/veto. */
    private function canRatify()
    {
        return $this->isAdmin() || payplex_ai_agents_can('ratify');
    }

    public function index()
    {
        $this->guard('view');
        $company = (string) $this->input->get('company');
        $status  = (string) $this->input->get('status');
        $allowed = array('open', 'closed', 'ratified', 'vetoed');
        $statusF = in_array($status, $allowed, true) ? $status : '';

        $motions = $this->m->motionsScoped($this->actor(), $this->isAdmin(), $company, $statusF !== '' ? $statusF : null);
        $rows = array();
        foreach ($motions as $mo) {
            $rows[] = array('m' => $mo, 'outcome' => $this->m->motionOutcome($mo));
        }
        $data['title']       = 'Council Votes';
        $data['rows']        = $rows;
        $data['status']      = $statusF;
        $data['summary']     = $this->m->motionsSummary($this->actor(), $this->isAdmin(), $company);
        $data['companies']   = $this->m->companies(true);
        $data['companyView'] = $company;
        $data['canManage']   = payplex_ai_agents_can('council_vote');
        $data['canRatify']   = $this->canRatify();
        $this->load->view('payplex_ai_agents/motions_list', $data);
    }

    public function create()
    {
        $this->guard('council_vote');
        $data['title']    = 'New Council Motion';
        $data['agents']   = $this->m->get();
        $data['types']    = Payplex_agent_vote::motionTypes();
        $data['rules']    = Payplex_agent_vote::thresholdRules();
        $this->load->view('payplex_ai_agents/motion_form', $data);
    }

    public function store()
    {
        $this->guard('council_vote');
        $res = $this->m->openMotion($this->input->post(), $this->actor(), $this->isAdmin());
        if (!empty($res['ok'])) {
            $msg = 'Motion #' . $res['motion_id'] . ' opened for voting.';
            if (!empty($res['warnings'])) { $msg .= ' Note: ' . implode(', ', $res['warnings']) . '.'; }
            set_alert('success', $msg);
            redirect(admin_url('payplex_ai_agents/councilvote/view/' . (int) $res['motion_id']));
        }
        set_alert('warning', 'Could not open motion: ' . implode(', ', array_map(function ($e) { return str_replace('_', ' ', $e); }, $res['errors'])));
        redirect(admin_url('payplex_ai_agents/councilvote/create'));
    }

    public function view($id)
    {
        $this->guard('view');
        $mo = $this->m->motionGet((int) $id);
        if (!$mo) { set_alert('warning', 'Motion not found.'); redirect(admin_url('payplex_ai_agents/councilvote')); }

        $names = array();
        foreach ($this->m->get() as $a) { $names[(int) $a->id] = ($a->display_name ? $a->display_name : $a->name); }

        $data['title']     = 'Motion #' . (int) $id;
        $data['m']         = $mo;
        $data['votes']     = $this->m->motionVotes($mo->id);
        $data['outcome']   = $this->m->motionOutcome($mo);
        $data['names']     = $names;
        $data['voters']    = $this->m->eligibleVoters(isset($mo->company) ? $mo->company : '');
        $data['votevalues']= Payplex_agent_vote::voteValues();
        $data['canManage'] = payplex_ai_agents_can('council_vote');
        $data['canRatify'] = $this->canRatify();
        $this->load->view('payplex_ai_agents/motion_view', $data);
    }

    public function vote($id)
    {
        $this->guard('council_vote');
        $res = $this->m->castVote((int) $id, $this->input->post(), $this->actor(), $this->isAdmin());
        set_alert(!empty($res['ok']) ? 'success' : 'warning',
            !empty($res['ok']) ? 'Ballot recorded (' . $res['vote'] . ').' : 'Could not record vote: ' . str_replace('_', ' ', (string) $res['error']));
        redirect(admin_url('payplex_ai_agents/councilvote/view/' . (int) $id));
    }

    /** Motion event: close | ratify | veto | reopen. */
    public function act($id, $event)
    {
        // ratify/veto need the Chairman capability; close/reopen need council_vote
        if (in_array($event, array('ratify', 'veto'), true)) {
            if (!$this->canRatify()) { access_denied('payplex_ai_agents'); }
        } else {
            $this->guard('council_vote');
        }
        $res = $this->m->motionEvent((int) $id, $event, $this->actor(), $this->canRatify());
        set_alert(!empty($res['ok']) ? 'success' : 'warning',
            !empty($res['ok']) ? 'Motion -> ' . $res['status'] . '.' : 'Cannot ' . $event . ': ' . str_replace('_', ' ', (string) $res['error']));
        redirect(admin_url('payplex_ai_agents/councilvote/view/' . (int) $id));
    }
}
