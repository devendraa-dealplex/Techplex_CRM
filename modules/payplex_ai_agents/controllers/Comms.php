<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Comms — the internal agent-to-agent message bus.
 *
 * Agents coordinate here (requests, hand-offs, escalations, responses). It is
 * strictly INTERNAL: nothing is sent to a customer or the outside world. Any
 * message whose text asks for a real external action (email/SMS/call/payment/
 * refund/publish/delete) is flagged, marked "needs human", and must still go
 * through a Decision Packet — the bus can never be a side-channel around the
 * approval controls. Company scoping (M7) and the global kill switch both apply.
 */
class Comms extends AdminController
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

    public function index()
    {
        $this->guard('view');
        $company = (string) $this->input->get('company');
        $status  = (string) $this->input->get('status');
        $allowed = array('open', 'awaiting', 'resolved', 'escalated', 'closed');
        $statusF = in_array($status, $allowed, true) ? $status : '';

        $threads = $this->m->threadsScoped($this->actor(), $this->isAdmin(), $company, $statusF !== '' ? $statusF : null);
        $rows = array();
        foreach ($threads as $t) {
            $rows[] = array('t' => $t, 'summary' => Payplex_agent_comms::summarizeThread($this->m->threadMessages($t->id)));
        }
        $data['title']       = 'Agent Messages';
        $data['rows']        = $rows;
        $data['status']      = $statusF;
        $data['summary']     = $this->m->commsSummary($this->actor(), $this->isAdmin(), $company);
        $data['companies']   = $this->m->companies(true);
        $data['companyView'] = $company;
        $data['canManage']   = payplex_ai_agents_can('comms');
        $this->load->view('payplex_ai_agents/comms_list', $data);
    }

    public function create()
    {
        $this->guard('comms');
        $data['title']      = 'New Agent Thread';
        $data['agents']     = $this->m->get();
        $data['types']      = Payplex_agent_comms::messageTypes();
        $data['priorities'] = Payplex_agent_comms::priorities();
        $this->load->view('payplex_ai_agents/comms_form', $data);
    }

    public function store()
    {
        $this->guard('comms');
        $res = $this->m->openThread($this->input->post(), $this->actor(), $this->isAdmin());
        if (!empty($res['ok'])) {
            $msg = 'Thread #' . $res['thread_id'] . ' opened.';
            if (!empty($res['warnings'])) { $msg .= ' Note: ' . implode(', ', $res['warnings']) . ' — this needs a human + Decision Packet before any real action.'; }
            set_alert('success', $msg);
            redirect(admin_url('payplex_ai_agents/comms/view/' . (int) $res['thread_id']));
        }
        set_alert('warning', 'Could not open thread: ' . implode(', ', array_map(function ($e) { return str_replace('_', ' ', $e); }, $res['errors'])));
        redirect(admin_url('payplex_ai_agents/comms/create'));
    }

    public function view($id)
    {
        $this->guard('view');
        $t = $this->m->threadGet((int) $id);
        if (!$t) { set_alert('warning', 'Thread not found.'); redirect(admin_url('payplex_ai_agents/comms')); }
        $msgs = $this->m->threadMessages($t->id);
        // agent id -> name map
        $names = array();
        foreach ($this->m->get() as $a) { $names[(int) $a->id] = ($a->display_name ? $a->display_name : $a->name); }

        $data['title']      = 'Thread #' . (int) $id;
        $data['t']          = $t;
        $data['messages']   = $msgs;
        $data['names']      = $names;
        $data['agents']     = $this->m->get();
        $data['types']      = Payplex_agent_comms::messageTypes();
        $data['priorities'] = Payplex_agent_comms::priorities();
        $data['canManage']  = payplex_ai_agents_can('comms');
        $this->load->view('payplex_ai_agents/comms_thread', $data);
    }

    public function reply($id)
    {
        $this->guard('comms');
        $res = $this->m->postMessage((int) $id, $this->input->post(), $this->actor(), $this->isAdmin());
        if (!empty($res['ok'])) {
            $msg = 'Message posted.';
            if (!empty($res['warnings'])) { $msg .= ' Note: ' . implode(', ', $res['warnings']) . ' — needs human + Decision Packet.'; }
            set_alert('success', $msg);
        } else {
            set_alert('warning', 'Could not post: ' . implode(', ', array_map(function ($e) { return str_replace('_', ' ', $e); }, $res['errors'])));
        }
        redirect(admin_url('payplex_ai_agents/comms/view/' . (int) $id));
    }

    /** Message delivery event: read | acknowledge. */
    public function msg($id, $msgId, $event)
    {
        $this->guard('comms');
        $this->m->messageEvent((int) $msgId, $event, $this->actor());
        redirect(admin_url('payplex_ai_agents/comms/view/' . (int) $id));
    }

    /** Thread event: resolve | escalate | close | reopen. */
    public function act($id, $event)
    {
        $this->guard('comms');
        $res = $this->m->threadEvent((int) $id, $event, $this->actor());
        set_alert(!empty($res['ok']) ? 'success' : 'warning',
            !empty($res['ok']) ? 'Thread -> ' . $res['status'] . '.' : 'Cannot ' . $event . ': ' . str_replace('_', ' ', (string) $res['error']));
        redirect(admin_url('payplex_ai_agents/comms/view/' . (int) $id));
    }
}
