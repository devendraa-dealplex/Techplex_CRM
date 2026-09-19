<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Templates — the Executive Template library + admin Create-Template form.
 *
 * Lists three sources: 13 built-in, 18 executive (C-suite) and admin-created
 * custom templates. Admin can create/edit/delete custom templates and spin up a
 * new AI agent/employee from ANY template. Every created agent starts in
 * SANDBOX as a draft and must go through the maker-checker approval flow.
 */
class Templates extends AdminController
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

    /** Library view: built-in + executive + custom templates. */
    public function index()
    {
        $this->guard('view');
        $data['title']       = 'AI Agent Templates';
        $data['view']        = $this->input->get('view') === 'executive' ? 'executive' : 'all';
        $data['builtin']     = Payplex_agent_templates::all();
        $data['executive']   = $this->m->execTemplates();
        $data['custom']      = $this->m->customTemplates();
        $data['suggestions'] = $this->m->execNameSuggestions();
        $this->load->view('payplex_ai_agents/templates_library', $data);
    }

    /** Show the Create-Template form. */
    public function create()
    {
        $this->guard('create');
        $data['title']    = 'Create Template';
        $data['template'] = null;
        $data['tiers']    = Payplex_agent_custom_template::tiers();
        $data['errors']   = array();
        $this->load->view('payplex_ai_agents/template_form', $data);
    }

    /** Persist a new custom template from the form. */
    public function store()
    {
        $this->guard('create');
        $res = Payplex_agent_custom_template::validate($this->input->post());
        if (empty($res['ok'])) {
            set_alert('warning', implode(' ', $res['errors']));
            $data['title']    = 'Create Template';
            $data['template'] = (object) $this->input->post();
            $data['tiers']    = Payplex_agent_custom_template::tiers();
            $data['errors']   = $res['errors'];
            $this->load->view('payplex_ai_agents/template_form', $data);
            return;
        }
        $id = $this->m->saveCustomTemplate($res['data'], $this->actor(), 0);
        set_alert('success', 'Custom template created. You can now create an agent from it.');
        redirect(admin_url('payplex_ai_agents/templates'));
    }

    /** Edit an existing custom template. */
    public function edit($id)
    {
        $this->guard('edit');
        $tpl = $this->m->customTemplate((int) $id);
        if (!$tpl) {
            set_alert('warning', 'Template not found.');
            redirect(admin_url('payplex_ai_agents/templates'));
        }
        $data['title']    = 'Edit Template';
        $data['template'] = $tpl;
        $data['tiers']    = Payplex_agent_custom_template::tiers();
        $data['errors']   = array();
        $this->load->view('payplex_ai_agents/template_form', $data);
    }

    public function update($id)
    {
        $this->guard('edit');
        if (!$this->m->customTemplate((int) $id)) {
            set_alert('warning', 'Template not found.');
            redirect(admin_url('payplex_ai_agents/templates'));
        }
        $res = Payplex_agent_custom_template::validate($this->input->post());
        if (empty($res['ok'])) {
            set_alert('warning', implode(' ', $res['errors']));
            redirect(admin_url('payplex_ai_agents/templates/edit/' . (int) $id));
        }
        $this->m->saveCustomTemplate($res['data'], $this->actor(), (int) $id);
        set_alert('success', 'Template updated.');
        redirect(admin_url('payplex_ai_agents/templates'));
    }

    public function destroy($id)
    {
        $this->guard('edit');
        if ($this->input->method() !== 'post') { // a GET link would bypass Perfex's CSRF protection
            set_alert('warning', 'Invalid request.');
            redirect(admin_url('payplex_ai_agents/templates'));
        }
        $this->m->deleteCustomTemplate((int) $id, $this->actor());
        set_alert('success', 'Template deleted.');
        redirect(admin_url('payplex_ai_agents/templates'));
    }

    /** Create a new AI agent/employee from any template (optional overrides). */
    public function use_template($slug)
    {
        $this->guard('create');
        $overrides = array(
            'name'         => (string) $this->input->post('custom_name'),
            'display_name' => (string) $this->input->post('display_name'),
            'agent_ref'    => (string) $this->input->post('agent_ref'),
            'company'      => (string) $this->input->post('company'),
        );
        $newId = $this->m->createFromTemplate($slug, $this->actor(), $overrides);
        if (!$newId) {
            set_alert('warning', 'Template not found: ' . html_escape($slug));
            redirect(admin_url('payplex_ai_agents/templates'));
        }
        set_alert('success', 'Agent created from template in Sandbox mode (draft). Configure, then submit for approval.');
        redirect(admin_url('payplex_ai_agents/agents/view/' . (int) $newId));
    }

    /** Save an existing agent's config as a reusable custom template. */
    public function save_as_template($agentId)
    {
        $this->guard('create');
        $id = $this->m->saveAgentAsTemplate((int) $agentId, $this->actor());
        if (!$id) {
            set_alert('warning', 'Could not save agent as template.');
        } else {
            set_alert('success', 'Agent saved as a reusable custom template.');
        }
        redirect(admin_url('payplex_ai_agents/templates'));
    }
}
