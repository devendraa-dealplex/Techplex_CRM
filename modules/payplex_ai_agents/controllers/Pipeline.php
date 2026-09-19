<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Lead-pipeline activity + safe manual testing. Runs the pipeline in sandbox
 * (recommendations only, no writes). Auto-run on live lead events is OFF by
 * default and toggled here.
 */
class Pipeline extends AdminController
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
        $data['title']     = 'AI Agents - Pipeline Activity';
        $data['events']    = $this->m->pipelineEvents(200);
        $data['lookups']   = $this->m->pipelineLookups($data['events']);
        $data['assign_roles'] = $this->m->pipelineAssignRoles();
        $data['auto_run']  = (int) $this->m->getSetting('pipeline_auto_run', 0) === 1;
        $this->load->view('payplex_ai_agents/pipeline', $data);
    }

    /** Run the pipeline (sandbox) on a real lead id, on demand. */
    public function test()
    {
        $this->guard('test');
        $leadId = (int) $this->input->post('lead_id');
        try {
            $res = $this->m->runPipelineForLead($leadId, 'manual', $this->actor());
            if (!empty($res['ok'])) {
                set_alert('success', 'Pipeline ran (sandbox) for lead #' . $leadId . ' - score ' . $res['score'] . '/100'
                    . ($res['escalate'] ? ', escalated to review.' : ', no issues.'));
            } else {
                set_alert('warning', 'Could not run pipeline: ' . $res['error']);
            }
        } catch (\Throwable $e) {
            if (function_exists('log_activity')) {
                @log_activity('Payplex AI pipeline test error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            }
            set_alert('danger', 'Could not run the pipeline for this lead. The error has been logged for review.');
        }
        redirect(admin_url('payplex_ai_agents/pipeline'));
    }

    public function toggle_auto()
    {
        $this->guard('budgets');
        $new = (int) $this->m->getSetting('pipeline_auto_run', 0) === 1 ? 0 : 1;
        $this->m->setSetting('pipeline_auto_run', $new, $this->actor());
        $this->m->audit(null, 'config_change', 'pipeline_auto_run set to ' . ($new ? 'ON' : 'OFF'), array(), $this->actor());
        set_alert('success', 'Pipeline auto-run on new leads ' . ($new ? 'ENABLED (sandbox recommendations only)' : 'disabled') . '.');
        redirect(admin_url('payplex_ai_agents/pipeline'));
    }
}