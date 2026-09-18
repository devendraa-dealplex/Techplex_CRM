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

    /** The form's default placeholder value - an obviously-fake sample number, not a real one. */
    const SAMPLE_PHONE = '+910000000000';

    /**
     * Strictly Indian mobile numbers only: 10 digits starting 6-9, optionally
     * prefixed +91/91/0. Everything else is rejected - other countries' numbers
     * included, not just short/random ones - except the form's own sample
     * placeholder, which is let through so leaving the default value in place
     * still runs a simulation instead of failing validation.
     */
    private function isValidPhone($phone)
    {
        $normalized = preg_replace('/[\s\-.()]/', '', trim((string) $phone));
        if ($normalized === '') {
            return false;
        }
        if ($normalized === self::SAMPLE_PHONE) {
            return true;
        }
        return (bool) preg_match('/^(?:\+91|91|0)?[6-9]\d{9}$/', $normalized);
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

    /** Sends a JSON error to an AJAX caller, or a flash-alert + redirect otherwise. Nothing is recorded either way. */
    private function rejectTest($message)
    {
        if ($this->input->is_ajax_request()) {
            $this->output->set_content_type('application/json')
                ->set_output(json_encode(array('success' => false, 'error' => $message)));
            return;
        }
        set_alert('warning', $message);
        redirect(admin_url('payplex_ai_agents/calling'));
    }

    /** Simulate a call in sandbox for the given agent + sample lead. */
    public function test()
    {
        $this->guard('test');

        $agentIdRaw = trim((string) $this->input->post('agent_id'));
        $leadName   = trim((string) $this->input->post('c_name'));
        $phone      = trim((string) $this->input->post('c_phone'));

        /*
         * Every check below runs BEFORE anything touches the model. A blank,
         * malformed, or non-existent input must never reach runCallingAgent()
         * — that is what was writing no_phone / empty rows to the call log
         * for submissions that were never a real test.
         */
        if ($agentIdRaw === '' || $leadName === '' || $phone === '') {
            $this->rejectTest('Please fill out all required fields (Agent ID, Lead, and Phone Number) before simulating a call.');
            return;
        }

        if (!preg_match('/^\d+$/', $agentIdRaw) || (int) $agentIdRaw <= 0) {
            $this->rejectTest('Please enter a valid Agent ID.');
            return;
        }
        $agentId = (int) $agentIdRaw;

        if (!$this->isValidPhone($phone)) {
            $this->rejectTest('Enter a valid 10-digit Indian mobile number (starting 6-9), '
                . 'e.g. +910000000000.');
            return;
        }

        if (!$this->m->get($agentId)) {
            $this->rejectTest('Agent ID not found.');
            return;
        }

        $lead = array(
            'id'     => (int) $this->input->post('lead_id'),
            'name'   => $leadName,
            'phone'  => $phone,
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
