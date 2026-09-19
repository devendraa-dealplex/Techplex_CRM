<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admin controller for the Payplex AI Agent Builder.
 * Every entry point re-checks the relevant capability; lifecycle side effects
 * go through the model, which enforces the state machine, maker-checker and
 * safety rules. Nothing here can make an agent act in production without a
 * separate approver.
 */
class Agents extends AdminController
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

    /* ---------- Lists ---------- */

    public function index()
    {
        $this->guard('view');
        $data['title']       = 'AI Agents';
        $data['agents']      = $this->m->agents();
        $data['global_kill'] = (int) $this->m->getSetting('global_kill_switch', 0) === 1;
        $data['cost']        = $this->m->costSummary();
        $this->load->view('payplex_ai_agents/agents_list', $data);
    }

    public function templates()
    {
        $this->guard('view');
        $data['title']     = 'Agent Templates';
        $data['templates'] = $this->m->templates();
        $this->load->view('payplex_ai_agents/templates', $data);
    }

    public function audit($agentId = null)
    {
        $this->guard('logs');
        $data['title'] = 'AI Agent Audit Log';
        $data['rows']  = $this->m->auditLog($agentId !== null ? (int) $agentId : null, 300);
        $data['agent_id'] = $agentId;
        $this->load->view('payplex_ai_agents/audit', $data);
    }

    public function settings()
    {
        $this->guard('budgets');
        if ($this->input->post()) {
            $this->m->setSetting('global_kill_switch', $this->input->post('global_kill_switch') ? 1 : 0, $this->actor());
            $this->m->setSetting('global_daily_budget', (float) $this->input->post('global_daily_budget'), $this->actor());
            $this->m->setSetting('global_monthly_budget', (float) $this->input->post('global_monthly_budget'), $this->actor());
            $this->m->setSetting('pipeline_assign_roles', trim((string) $this->input->post('pipeline_assign_roles')), $this->actor());
            $model = trim((string) $this->input->post('openrouter_model'));
            $this->m->setSetting('openrouter_model', $model !== '' ? $model : Payplex_agent_llm::DEFAULT_MODEL, $this->actor());
            $newKey = trim((string) $this->input->post('openrouter_api_key'));
            if ($newKey !== '') { // blank = keep the stored key
                $this->m->setSetting('openrouter_api_key', $newKey, $this->actor());
                $this->m->audit(null, 'config_change', 'OpenRouter API key updated', array(), $this->actor());
            }
            set_alert('success', 'Settings saved.');
            redirect(admin_url('payplex_ai_agents/agents/settings'));
        }
        $data['title'] = 'AI Agents - Settings & Kill Switch';
        $data['global_kill']    = (int) $this->m->getSetting('global_kill_switch', 0) === 1;
        $data['daily_budget']   = $this->m->getSetting('global_daily_budget', 50);
        $data['monthly_budget'] = $this->m->getSetting('global_monthly_budget', 1000);
        $data['assign_roles']   = $this->m->pipelineAssignRoles();
        $data['llm_key_set']    = Payplex_agent_llm::resolveKey($this->m->getSetting('openrouter_api_key', '')) !== '';
        $data['llm_model']      = $this->m->getSetting('openrouter_model', Payplex_agent_llm::DEFAULT_MODEL);
        $this->load->view('payplex_ai_agents/settings', $data);
    }

    /* ---------- Create / edit ---------- */

    public function create()
    {
        $this->guard('create');
        if ($this->input->post()) {
            $data = $this->collect();
            $errors = $this->validateAgentInput($data);
            if (empty($errors)) {
                $id = $this->m->create($data, $this->actor());
                set_alert('success', 'Agent created in Sandbox mode (draft).');
                redirect(admin_url('payplex_ai_agents/agents/view/' . (int) $id));
            }
            set_alert('warning', implode(' ', $errors));
            $this->load->view('payplex_ai_agents/agent_form', array(
                'title'   => 'Create AI Agent',
                'agent'   => (object) $data,
                'is_edit' => false,
                'staff'   => $this->staffList(),
            ));
            return;
        }
        $data['title'] = 'Create AI Agent';
        $data['agent'] = null;
        $data['staff'] = $this->staffList();
        $this->load->view('payplex_ai_agents/agent_form', $data);
    }

    public function edit($id)
    {
        $this->guard('edit');
        $agent = $this->m->get((int) $id);
        if (!$agent) {
            show_404();
        }
        if ($this->input->post()) {
            $data = $this->collect();
            $errors = $this->validateAgentInput($data, (int) $id);
            if (empty($errors)) {
                $res = $this->m->update((int) $id, $data, $this->actor());
                if ($res === 'locked') {
                    set_alert('warning', 'Agent is locked in its current status and cannot be edited. Create a new version instead.');
                } else {
                    set_alert('success', 'Agent updated.');
                }
                redirect(admin_url('payplex_ai_agents/agents/view/' . (int) $id));
            }
            set_alert('warning', implode(' ', $errors));
            $this->load->view('payplex_ai_agents/agent_form', array(
                'title'   => 'Edit AI Agent',
                'agent'   => (object) array_merge($data, array('id' => (int) $id)),
                'is_edit' => true,
                'staff'   => $this->staffList(),
            ));
            return;
        }
        $data['title'] = 'Edit AI Agent';
        $data['agent'] = $agent;
        $data['staff'] = $this->staffList();
        $this->load->view('payplex_ai_agents/agent_form', $data);
    }

    /** Active staff list for the Owner/Reviewer/Approver pickers. */
    private function staffList()
    {
        $this->load->model('staff_model');
        return $this->staff_model->get('', array('active' => 1));
    }

    /**
     * Server-side validation - the HTML5 attributes on the form (required,
     * min/max) are client-side only and trivially bypassed by a direct POST.
     * Returns an array of human-readable error strings (empty = valid).
     */
    private function validateAgentInput($data, $excludeId = 0)
    {
        $errors = array();

        if ($data['name'] === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($data['name']) > 191) {
            $errors[] = 'Name must be 191 characters or fewer.';
        } elseif ($this->m->agentNameTaken($data['name'], $excludeId)) {
            // Single-quoted, with any stray quote characters in the name stripped:
            // set_alert()'s toast embeds this message unescaped inside a
            // double-quoted JS string (see app_js_alerts()), so a raw " here
            // would break it exactly like the Knowledge Base "Ask" bug did.
            $errors[] = "An agent named '" . str_replace(array('"', "'"), '', $data['name']) . "' already exists - names must be unique.";
        }

        if ($data['confidence_threshold'] < 0 || $data['confidence_threshold'] > 1) {
            $errors[] = 'Confidence threshold must be between 0 and 1.';
        }

        $nonNegativeLabels = array(
            'retry_limit'            => 'Retry limit',
            'daily_execution_limit'  => 'Daily execution limit',
            'token_limit'            => 'Token limit',
            'daily_budget'           => 'Daily budget',
            'monthly_budget'         => 'Monthly budget',
        );
        foreach ($nonNegativeLabels as $f => $label) {
            if ($data[$f] < 0) {
                $errors[] = $label . ' cannot be negative.';
            }
        }

        $staffLabels = array('owner_id' => 'Owner', 'reviewer_id' => 'Reviewer', 'approver_id' => 'Approver');
        foreach ($staffLabels as $f => $label) {
            if ($data[$f] > 0 && !$this->m->staffExists($data[$f])) {
                $errors[] = $label . ' staff id ' . $data[$f] . ' does not match an active staff member.';
            }
        }

        return $errors;
    }

    /** Collect + normalise form fields into a config array. */
    private function collect()
    {
        $p = $this->input->post();
        $lists = array('knowledge_sources', 'products_services', 'lead_sources', 'crm_permissions',
            'allowed_tools', 'triggers', 'conditions', 'actions', 'prohibited_actions', 'approval_required_actions');
        $out = array(
            'name'                 => trim((string) $this->input->post('name')),
            'description'          => (string) $this->input->post('description'),
            'department'           => (string) $this->input->post('department'),
            'purpose'              => (string) $this->input->post('purpose'),
            'ai_provider'          => (string) $this->input->post('ai_provider'),
            'ai_model'             => (string) $this->input->post('ai_model'),
            'system_prompt'        => (string) $this->input->post('system_prompt'),
            'working_days'         => (string) $this->input->post('working_days'),
            'working_hours'        => (string) $this->input->post('working_hours'),
            'customer_timezone'    => (string) $this->input->post('customer_timezone'),
            'confidence_threshold' => (float) $this->input->post('confidence_threshold'),
            'human_escalation'     => $this->input->post('human_escalation') ? 1 : 0,
            'retry_limit'          => (int) $this->input->post('retry_limit'),
            'daily_execution_limit' => (int) $this->input->post('daily_execution_limit'),
            'token_limit'          => (int) $this->input->post('token_limit'),
            'daily_budget'         => (float) $this->input->post('daily_budget'),
            'monthly_budget'       => (float) $this->input->post('monthly_budget'),
            'reviewer_id'          => (int) $this->input->post('reviewer_id'),
            'approver_id'          => (int) $this->input->post('approver_id'),
            'owner_id'             => (int) $this->input->post('owner_id'),
        );
        foreach ($lists as $f) {
            $raw = (string) $this->input->post($f);
            $out[$f] = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw))));
        }
        return $out;
    }

    /* ---------- Clone / from template ---------- */

    public function clone_agent($id)
    {
        $this->guard('create');
        $newId = $this->m->cloneAgent((int) $id, $this->actor());
        if ($newId) {
            set_alert('success', 'Agent cloned.');
            redirect(admin_url('payplex_ai_agents/agents/view/' . (int) $newId));
        }
        show_404();
    }

    public function use_template($slug)
    {
        $this->guard('create');
        $newId = $this->m->createFromTemplate($slug, $this->actor());
        if ($newId) {
            set_alert('success', 'Agent created from template in Sandbox mode.');
            redirect(admin_url('payplex_ai_agents/agents/view/' . (int) $newId));
        }
        show_404();
    }

    /* ---------- Detail ---------- */

    public function view($id)
    {
        $this->guard('view');
        $agent = $this->m->get((int) $id);
        if (!$agent) {
            show_404();
        }
        $data['title']    = $agent->name;
        $data['agent']    = $agent;
        $data['runs']     = $this->m->runs((int) $id, 20);
        $data['versions'] = $this->m->versions((int) $id);
        $data['audit']    = $this->m->auditLog((int) $id, 50);
        $data['global_kill'] = (int) $this->m->getSetting('global_kill_switch', 0) === 1;
        $this->load->view('payplex_ai_agents/agent_view', $data);
    }

    /* ---------- Lifecycle ---------- */

    public function action($id, $action)
    {
        // Map action -> required capability.
        $capMap = array(
            'move_to_sandbox' => 'edit', 'start_testing' => 'test', 'submit' => 'submit',
            'approve' => 'approve', 'reject' => 'approve', 'schedule' => 'activate',
            'pause' => 'activate', 'resume' => 'activate', 'archive' => 'edit',
            'restore' => 'edit', 'new_version' => 'edit',
        );
        $cap = isset($capMap[$action]) ? $capMap[$action] : 'edit';
        $this->guard($cap);

        $note = (string) $this->input->post('note');
        $res  = $this->m->transition((int) $id, $action, $this->actor(), $note);
        if (!empty($res['ok'])) {
            set_alert('success', 'Agent moved to "' . $res['to'] . '".');
        } else {
            if ($res['error'] === 'maker_checker_violation') {
                $msg = 'Blocked: the approver must be different from the creator and submitter (maker-checker).';
            } elseif ($res['error'] === 'not_designated_approver') {
                $msg = 'Blocked: this agent has a specific approver configured - only that staff member may approve it.';
            } else {
                $msg = 'Action not allowed: ' . $res['error'];
            }
            set_alert('warning', $msg);
        }
        redirect(admin_url('payplex_ai_agents/agents/view/' . (int) $id));
    }

    public function activate_production($id)
    {
        $this->guard('activate');
        $res = $this->m->activateProduction((int) $id, $this->actor());
        if (!empty($res['ok'])) {
            set_alert('success', 'Agent activated in PRODUCTION mode.');
        } else {
            set_alert('warning', 'Cannot activate in production: ' . $res['error'] . '. An agent must be approved by a different user first.');
        }
        redirect(admin_url('payplex_ai_agents/agents/view/' . (int) $id));
    }

    public function destroy($id)
    {
        $this->guard('edit');
        if ($this->input->method() !== 'post') { // a GET link would bypass Perfex's CSRF protection
            set_alert('warning', 'Invalid request.');
            redirect(admin_url('payplex_ai_agents/agents'));
        }
        $deleted = $this->m->deleteAgent((int) $id, $this->actor());
        if ($deleted) {
            set_alert('success', 'Agent deleted.');
            redirect(admin_url('payplex_ai_agents/agents'));
        }
        set_alert('warning', 'Agent not found.');
        redirect(admin_url('payplex_ai_agents/agents'));
    }

    public function kill($id)
    {
        $this->guard('activate');
        if ($this->input->method() !== 'post') {
            set_alert('warning', 'Invalid request.');
            redirect(admin_url('payplex_ai_agents/agents/view/' . (int) $id));
        }
        $agent = $this->m->get((int) $id);
        if (!$agent) {
            show_404();
        }
        $this->m->setAgentKill((int) $id, (int) $agent->agent_kill === 1 ? 0 : 1, $this->actor());
        set_alert('success', 'Agent kill switch toggled.');
        redirect(admin_url('payplex_ai_agents/agents/view/' . (int) $id));
    }

    /* ---------- Sandbox test ---------- */

    public function test($id)
    {
        $this->guard('test');
        $agent = $this->m->get((int) $id);
        if (!$agent) {
            show_404();
        }
        $input = array(
            'name'    => (string) $this->input->post('t_name'),
            'email'   => (string) $this->input->post('t_email'),
            'phone'   => (string) $this->input->post('t_phone'),
            'company' => (string) $this->input->post('t_company'),
            'message' => (string) $this->input->post('t_message'),
        );
        $run = $this->m->sandboxTest((int) $id, $input, $this->actor());
        if (!empty($run['ok'])) {
            set_alert('success', 'Sandbox test run #' . $run['run_id'] . ' ' . $run['status']
                . ' - ' . $run['tokens'] . ' tokens, $' . number_format($run['estimated_cost'], 6)
                . ', ' . $run['escalations'] . ' escalation(s).');
        } else {
            set_alert('warning', 'Sandbox test could not run: ' . $run['error']);
        }
        redirect(admin_url('payplex_ai_agents/agents/view/' . (int) $id));
    }

    /* ---------- Rollback ---------- */

    public function rollback($id, $versionRowId)
    {
        $this->guard('edit');
        $res = $this->m->rollback((int) $id, (int) $versionRowId, $this->actor());
        if (!empty($res['ok'])) {
            set_alert('success', 'Rolled back. Agent reset to draft/sandbox for re-approval.');
        } else {
            set_alert('warning', 'Rollback failed: ' . $res['error']);
        }
        redirect(admin_url('payplex_ai_agents/agents/view/' . (int) $id));
    }
}
