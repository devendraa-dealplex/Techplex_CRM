<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * AI Call Log + safe call testing. Sandbox simulates; real calls stay gated
 * behind production + approval + a global master switch that is OFF by default.
 */
class Calling extends AdminController
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

    public function index()
    {
        $this->guard('logs');
        $data['title']        = 'AI Agents - Call Log';
        $data['calls']        = $this->m->calls(200);
        $data['summary']      = $this->m->callSummary();
        $data['module_present'] = $this->m->aiCallingModulePresent();
        $data['allow_real']   = (int) $this->m->getSetting('allow_real_calls', 0) === 1;
        $this->load->view('payplex_ai_agents/calling', $data);
    }

    /** Simulate a call in sandbox for the given agent + sample lead. */
    public function test()
    {
        $this->guard('test');
        $agentId = (int) $this->input->post('agent_id');
        $lead = array(
            'id'    => (int) $this->input->post('lead_id'),
            'name'  => (string) $this->input->post('c_name'),
            'phone' => (string) $this->input->post('c_phone'),
            'script' => (string) $this->input->post('c_script'),
        );
        $res = $this->m->runCallingAgent($agentId, $lead, $this->actor(), false);
        if (!empty($res['ok'])) {
            set_alert('success', 'Call #' . $res['call_id'] . ': decision=' . $res['decision'] . ' (' . $res['reason'] . '), outcome=' . $res['outcome'] . '.');
        } else {
            set_alert('warning', 'Could not run call test: ' . $res['error']);
        }
        redirect(admin_url('payplex_ai_agents/calling'));
    }

    /** Master switch for real calls. High-privilege; even ON, nothing auto-dials. */
    public function toggle_real()
    {
        $this->guard('approve');
        $new = (int) $this->m->getSetting('allow_real_calls', 0) === 1 ? 0 : 1;
        $this->m->setSetting('allow_real_calls', $new, $this->actor());
        $this->m->audit(null, 'config_change', 'Global allow_real_calls set to ' . ($new ? 'ON' : 'OFF'), array(), $this->actor());
        set_alert($new ? 'warning' : 'success', 'Real-calls master switch ' . ($new ? 'ENABLED (production, approved agents only; still no auto-dial)' : 'disabled') . '.');
        redirect(admin_url('payplex_ai_agents/calling'));
    }
}
