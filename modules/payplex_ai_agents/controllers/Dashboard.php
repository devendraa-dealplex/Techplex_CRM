<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cost/budget dashboard + human-review queue.
 */
class Dashboard extends AdminController
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
        $data['title']        = 'AI Agents - Cost Dashboard';
        $data['total']        = $this->m->costSummary();
        $data['byAgent']      = $this->m->costByAgent();
        $data['byModel']      = $this->m->costByModel();
        $data['budgets']      = $this->m->budgetStatuses();
        $data['openQueue']    = $this->m->openEscalationCount();
        $data['global_daily'] = $this->m->getSetting('global_daily_budget', 50);
        $data['global_monthly'] = $this->m->getSetting('global_monthly_budget', 1000);
        $this->load->view('payplex_ai_agents/dashboard', $data);
    }

    /** Run the auto-pause sweep on demand. */
    public function enforce()
    {
        $this->guard('budgets');
        $n = $this->m->enforceBudgets($this->actor());
        set_alert($n > 0 ? 'warning' : 'success', $n > 0
            ? $n . ' agent(s) auto-paused for breaching their hard limit.'
            : 'Budget check complete - no agent over its hard limit.');
        redirect(admin_url('payplex_ai_agents/dashboard'));
    }

    public function queue()
    {
        $this->guard('logs');
        $data['title'] = 'AI Agents - Review Queue';
        $data['open']  = $this->m->escalations('open', 200);
        $data['recent'] = $this->m->escalations(null, 100);
        $this->load->view('payplex_ai_agents/queue', $data);
    }

    public function resolve($id, $decision)
    {
        $this->guard('approve');
        $note = (string) $this->input->post('note');
        $ok = $this->m->escalationResolve((int) $id, $decision, $note, $this->actor());
        set_alert($ok ? 'success' : 'warning', $ok ? 'Review item ' . $decision . '.' : 'Could not update review item.');
        redirect(admin_url('payplex_ai_agents/dashboard/queue'));
    }
}
