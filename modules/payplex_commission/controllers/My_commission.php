<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_commission_workflow.php';

/**
 * Employee view of their own commission (own-scope only) + raise dispute.
 */
class My_commission extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_commission/payplex_commission_model', 'cmodel');
    }

    /**
     * The module registers a view_own capability for exactly this page and
     * nothing ever checked it, so every signed-in staff member could open it
     * whether or not an administrator had granted the permission. The data was
     * already own-scoped, so nothing leaked — but a permission that is offered
     * and not enforced tells an administrator they have restricted something
     * they have not.
     */
    private function cap($c)
    {
        return is_admin() || staff_can($c, 'payplex_commission');
    }

    private function requireOwnView()
    {
        if (!$this->cap('view_own') && !$this->cap('view_all')) {
            access_denied('payplex_commission');
        }
    }

    public function index()
    {
        $this->requireOwnView();
        $staffId = get_staff_user_id();
        $data['title']      = 'My Commission';
        $data['statements'] = $this->cmodel->all(null, $staffId);
        $this->load->view('payplex_commission/my_commission', $data);
    }

    public function dispute($id)
    {
        $this->requireOwnView();
        $s = $this->cmodel->get($id);
        // Record-scope: staff can only dispute their OWN statement.
        if (!$s || (int) $s->staff_id !== (int) get_staff_user_id()) {
            ajax_access_denied();
        }
        $this->cmodel->raiseDispute($id, $this->input->post('reason') ?: 'No reason given');
        set_alert('info', 'Dispute raised. Finance will review.');
        redirect(admin_url('payplex_commission/my_commission'));
    }
}
