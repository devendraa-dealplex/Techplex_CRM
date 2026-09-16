<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Companies — Plex Group company registry and per-staff company access.
 *
 * Row-level scoping: agents, decisions and templates carry a company. A staff
 * member sees only the companies they are granted (admins see the whole group).
 * A cross-company grant lets one staff member see across companies.
 */
class Companies extends AdminController
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
        $this->guard('view');
        // add / save a company
        if ($this->input->post('company_name')) {
            $this->guard('companies');
            $this->m->saveCompany($this->input->post('company_name'), (string) $this->input->post('company_code'), $this->actor());
            set_alert('success', 'Company saved.');
            redirect(admin_url('payplex_ai_agents/companies'));
        }
        $data['title']     = 'Companies & Access';
        $data['companies'] = $this->m->companies(false);
        $data['staff']     = $this->allStaff();
        $data['selStaff']  = (int) $this->input->get('staff');
        $data['grants']    = $data['selStaff'] ? $this->m->companyAccessFor($data['selStaff']) : array();
        $data['canManage'] = payplex_ai_agents_can('companies');
        $this->load->view('payplex_ai_agents/companies', $data);
    }

    public function toggle($id)
    {
        $this->guard('companies');
        $this->m->toggleCompany((int) $id, $this->actor());
        set_alert('success', 'Company updated.');
        redirect(admin_url('payplex_ai_agents/companies'));
    }

    /** Grant / revoke a company for a staff member. */
    public function access()
    {
        $this->guard('companies');
        $staffId = (int) $this->input->post('staff_id');
        $code    = (string) $this->input->post('company_code');
        $op      = (string) $this->input->post('op');
        $cross   = (int) $this->input->post('can_cross');
        if ($op === 'revoke') {
            $this->m->revokeCompany($staffId, $code, $this->actor());
            set_alert('success', 'Access revoked.');
        } else {
            $this->m->grantCompany($staffId, $code, $cross, $this->actor());
            set_alert('success', 'Access granted.');
        }
        redirect(admin_url('payplex_ai_agents/companies?staff=' . $staffId));
    }

    private function allStaff()
    {
        if (!$this->db->table_exists(db_prefix() . 'staff')) { return array(); }
        return $this->db->select('staffid, firstname, lastname, admin')
            ->where('active', 1)->order_by('firstname', 'ASC')
            ->get(db_prefix() . 'staff')->result();
    }
}
