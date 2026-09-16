<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_call_limits.php';

require_once __DIR__ . '/../libraries/Payplex_api_client.php';

/**
 * Campaign management with maker-checker approval.
 * Bulk calling is created here (draft -> pending_approval), approved by a
 * DIFFERENT user, then launched on the Sonivo backend. No bulk calling runs
 * without an approval (Sprint plan rule).
 */
class Campaigns extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_aicalling/payplex_campaigns_model');
        $this->load->model('payplex_aicalling/payplex_audit_model');
    }

    private function cap($c)
    {
        return is_admin() || staff_can($c, 'payplex_aicalling');
    }

    public function index()
    {
        if (!$this->cap('campaign_view')) {
            access_denied('payplex_aicalling campaigns');
        }
        $data['title']     = 'AI Calling Campaigns';
        $data['campaigns'] = $this->payplex_campaigns_model->all();
        $data['can_create']  = $this->cap('campaign_create');
        $data['can_approve'] = $this->cap('campaign_approve');
        $this->load->view('payplex_aicalling/campaigns', $data);
    }

    public function create()
    {
        if (!$this->cap('campaign_create')) {
            ajax_access_denied();
        }
        if ($this->input->post()) {
            $id = $this->payplex_campaigns_model->create([
                'name'          => $this->input->post('name'),
                'agent_id'      => $this->input->post('agent_id'),
                'language'      => $this->input->post('language') ?: 'en-IN',
                'objective'     => $this->input->post('objective'),
                'audience_json' => json_encode(['filter' => $this->input->post('audience')]),
                'total_targets' => (int) $this->input->post('total_targets'),
            ]);
            $this->payplex_audit_model->log('campaign.created', 'campaign', $id, null,
                ['name' => $this->input->post('name')]);
            set_alert('success', 'Campaign submitted for approval.');
            redirect(admin_url('payplex_aicalling/campaigns'));
        }
        $this->load->view('payplex_aicalling/campaign_form', ['title' => 'New Campaign']);
    }

    /** Approve (maker-checker enforced in the model). */
    public function approve($id)
    {
        if (!$this->cap('campaign_approve')) {
            ajax_access_denied();
        }
        $res = $this->payplex_campaigns_model->approve($id, get_staff_user_id());
        if ($res !== true) {
            $msg = [
                'not_found'        => 'Campaign not found.',
                'not_pending'      => 'Campaign is not awaiting approval.',
                'maker_is_checker' => 'You cannot approve a campaign you created (segregation of duties).',
            ][$res] ?? 'Could not approve.';
            set_alert('warning', $msg);
            redirect(admin_url('payplex_aicalling/campaigns'));
            return;
        }
        /*
         * A campaign is many calls, so the controls that govern one call govern
         * it too. None of them were applied here: approval launched a backend
         * campaign directly, which meant the kill switch did not stop it, the
         * recording disclosure never travelled with it, and an unconfigured
         * budget did not refuse it — every §4.3/§4.4 control enforced on the
         * single-call path was absent from the bulk one.
         *
         * The rules below are the module's own, read from the same options
         * placeCall() reads; nothing new is invented here.
         */
        $c = $this->payplex_campaigns_model->get($id);
        $launchRefusal = $this->launchRefusal();

        if ($launchRefusal !== null) {
            $this->payplex_audit_model->log('campaign.launch_refused', 'campaign', $id, null,
                ['approved_by' => get_staff_user_id(), 'reason' => $launchRefusal]);
            set_alert('warning', 'Campaign approved, but NOT launched: ' . $launchRefusal
                . ' The approval stands; launching is blocked until this is resolved.');
            redirect(admin_url('payplex_aicalling/campaigns'));
            return;
        }

        $client = new Payplex_api_client();
        $api = $client->createCampaign([
            'approved'  => true,
            'name'      => $c->name,
            'agent_id'  => $c->agent_id,
            'language'  => $c->language,
            'objective' => $c->objective,
            /*
             * These travel WITH the campaign for the same reason they travel
             * with a single call: a duration cap the CRM knows about but never
             * sends bounds nothing, and a disclosure that gates a button but
             * never reaches the agent is not read to the person being recorded.
             */
            'max_duration_sec'     => Payplex_call_limits::maxDurationSeconds(
                                          get_option('payplex_aicalling_max_duration_sec')),
            'recording_disclosure' => (string) get_option('payplex_aicalling_recording_disclosure'),
            'calling_window'       => array(
                'start_hour' => get_option('payplex_aicalling_hours_start'),
                'end_hour'   => get_option('payplex_aicalling_hours_end'),
                'timezone'   => 'recipient_local',
            ),
        ]);
        if (!empty($api['ok'])) {
            $this->payplex_campaigns_model->update($id, [
                'status'             => 'running',
                'sonivo_campaign_id' => $api['data']['campaign_id'] ?? null,
            ]);
        }
        $this->payplex_audit_model->log('campaign.approved', 'campaign', $id, null,
            ['approved_by' => get_staff_user_id(), 'launched' => !empty($api['ok'])]);
        set_alert('success', 'Campaign approved' . (!empty($api['ok']) ? ' and launched.' : ' (backend launch pending).'));
        redirect(admin_url('payplex_aicalling/campaigns'));
    }

    /**
     * Why this campaign must not be launched, or null when it may be.
     *
     * Only the module's existing rules, applied to the bulk path:
     *
     *   - the kill switch, which is not a kill switch if it stops single calls
     *     and lets a campaign dial anyway;
     *   - the recording disclosure, which Payplex_call_limits treats as a hard
     *     legal precondition for ANY call, written AND confirmed;
     *   - a configured budget, because the module already refuses a single call
     *     when no budget is set, and a campaign is the case where an
     *     unconfigured budget matters most.
     *
     * NOT covered here, and deliberately not faked: consent, DND, calling hours
     * and frequency are per-recipient decisions, and this endpoint never sees
     * the recipient list — the backend selects it. Those remain enforced for
     * calls the CRM places and unenforced for calls a campaign places. That is
     * an architectural gap, not something to paper over with a check that
     * cannot see the data it would need.
     */
    private function launchRefusal()
    {
        if (get_option('payplex_aicalling_enabled') !== '1') {
            return 'AI Calling is disabled by an administrator.';
        }

        if (!Payplex_call_limits::disclosureReady(
                get_option('payplex_aicalling_recording_disclosure'),
                get_option('payplex_aicalling_disclosure_confirmed'))) {
            return 'The recording disclosure has not been written and confirmed, '
                 . 'and nobody may be recorded without it.';
        }

        $budget = Payplex_call_limits::budget(0, 0, array(
            'agent_budget'   => get_option('payplex_aicalling_agent_budget'),
            'account_budget' => get_option('payplex_aicalling_account_budget'),
        ));
        if (empty($budget['allowed']) && isset($budget['code'])
            && $budget['code'] === 'budget_not_configured') {
            return 'No calling budget is configured, so the spend a campaign could '
                 . 'incur is unbounded.';
        }

        return null;
    }

    public function reject($id)
    {
        if (!$this->cap('campaign_approve')) {
            ajax_access_denied();
        }
        $this->payplex_campaigns_model->reject($id, $this->input->post('reason') ?: 'No reason given');
        $this->payplex_audit_model->log('campaign.rejected', 'campaign', $id);
        set_alert('info', 'Campaign rejected.');
        redirect(admin_url('payplex_aicalling/campaigns'));
    }
}
