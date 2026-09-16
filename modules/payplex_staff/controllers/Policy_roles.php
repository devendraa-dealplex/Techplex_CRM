<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Workforce_policy_roles.php';
require_once __DIR__ . '/../libraries/Workforce_scope.php';
require_once __DIR__ . '/../libraries/Workforce_shadow.php';

/**
 * Workforce Policy Roles — Admin-only role assignment.
 *
 * DESIGN RULES, each enforced rather than documented:
 *
 *  - Every mutating action is POST-only and CSRF-checked. A role can never be
 *    assigned, approved or revoked by following a link, so a crafted URL is
 *    inert.
 *  - Identity is never read from the request. The actor is the session's staff
 *    id; the target comes from a POSTed field that is validated against the
 *    staff table. No parameter names a role for the *actor*.
 *  - Sensitive and company-wide assignments are stored pending and are not live
 *    until a different person approves them.
 *  - There is no delete route. Revocation stamps the row.
 *  - Nothing here enforces runtime authorization: the emergency guard is still
 *    the live decision and the new policy is still in shadow. This screen only
 *    records who holds which role.
 */
class Policy_roles extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        /* Admin only. Not a module capability - handing out authority is not a
           permission an ordinary module role should be able to hold. */
        if (!is_admin()) { access_denied('payplex_staff'); }
        $this->load->model('payplex_staff/payplex_policy_roles_model', 'pr');
    }

    private function actor()
    {
        return (int) get_staff_user_id();
    }

    /** POST + CSRF or nothing happens. */
    private function requirePost()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') { show_404(); }
        return true;
    }

    public function index()
    {
        $data['title']      = 'Workforce Policy Roles';
        $data['vocabulary'] = Workforce_policy_roles::vocabulary();
        $data['sensitive']  = Workforce_policy_roles::sensitiveRoles();
        $data['ready']      = $this->pr->ready();
        $data['conflicts']  = $data['ready'] ? $this->pr->conflicts() : array();
        $data['live']       = $data['ready'] ? $this->pr->allLive() : array();
        $data['staff']      = $this->db->select('staffid, firstname, lastname, active')
                                       ->order_by('firstname', 'ASC')->limit(500)
                                       ->get(db_prefix() . 'staff')->result_array();
        $data['warnings']   = Workforce_shadow::warnings();
        $this->load->view('payplex_staff/policy_roles/index', $data);
    }

    /** Assignment history for one staff member, plus the two previews. */
    public function staff($staffId = 0)
    {
        $staffId = (int) $staffId;
        if ($staffId <= 0) { show_404(); }

        $rows = $this->pr->forStaff($staffId);
        $now  = time();
        foreach ($rows as $i => $r) {
            $st = Workforce_policy_roles::assignmentState($r, $now);
            $rows[$i]['__live']   = !empty($st['live']);
            $rows[$i]['__reason'] = $st['reason'];
        }

        /* Context preview: exactly what the shadow resolver would build. */
        Workforce_shadow::reset();
        $ctx = Workforce_shadow::context($this, $staffId, $staffId);

        $data['title']       = 'Policy Roles — Staff #' . $staffId;
        $data['staff_id']    = $staffId;
        $data['rows']        = $rows;
        $data['context']     = $ctx;
        $data['role_source'] = isset($ctx['role_source']) ? $ctx['role_source'] : 'default';
        $data['resolved']    = isset($ctx['actor_roles'][0]) ? $ctx['actor_roles'][0] : 'employee';
        $data['access']      = $this->expectedAccess($data['resolved'], $ctx);
        $data['warnings']    = Workforce_shadow::warnings();
        $data['vocabulary']  = Workforce_policy_roles::vocabulary();
        $this->load->view('payplex_staff/policy_roles/staff', $data);
    }

    /**
     * Expected-access preview: what the NEW policy would decide for this role,
     * over every call site. Read-only and computed, never stored - so the
     * screen cannot drift from the engine it is previewing.
     */
    private function expectedAccess($role, array $ctx)
    {
        $out = array();
        foreach (Workforce_scope::callSiteMap() as $route => $spec) {
            if (!isset($spec['action'])) {
                $out[$route] = array('domain' => $spec['domain'], 'action' => 'via allowlist', 'verdict' => 'n/a');
                continue;
            }
            $c = $ctx;
            $c['actor_roles'] = array($role);
            $c['domain'] = $spec['domain'];
            $c['action'] = $spec['action'];
            $d = Workforce_scope::decide($c);
            $out[$route] = array(
                'domain'  => $spec['domain'],
                'action'  => $spec['action'],
                'verdict' => !empty($d['allowed']) ? 'allow' : 'deny',
                'reason'  => isset($d['reason']) ? $d['reason'] : '',
            );
        }
        return $out;
    }

    public function assign()
    {
        $this->requirePost();
        $data = array(
            'staff_id'           => (int) $this->input->post('staff_id'),
            'policy_role'        => (string) $this->input->post('policy_role'),
            'business_entity_id' => (string) $this->input->post('business_entity_id'),
            'branch'             => (string) $this->input->post('branch'),
            'department'         => (string) $this->input->post('department'),
            'region'             => (string) $this->input->post('region'),
            'effective_from'     => (string) $this->input->post('effective_from'),
            'expires_at'         => (string) $this->input->post('expires_at'),
            'assignment_reason'  => (string) $this->input->post('assignment_reason'),
        );
        $r = $this->pr->assign($data, $this->actor());
        if (!empty($r['ok'])) {
            set_alert('success', !empty($r['pending'])
                ? 'Assignment recorded and is PENDING a second approver. It is not live.'
                : 'Policy role assigned.');
        } else {
            set_alert('danger', 'Refused: ' . implode(', ', $r['errors']));
        }
        redirect(admin_url('payplex_staff/policy_roles/staff/' . (int) $data['staff_id']));
    }

    public function approve()
    {
        $this->requirePost();
        $id = (int) $this->input->post('id');
        $r  = $this->pr->approve($id, $this->actor());
        set_alert(!empty($r['ok']) ? 'success' : 'danger',
            !empty($r['ok']) ? 'Assignment approved and is now live.'
                             : 'Refused: ' . implode(', ', $r['errors']));
        redirect(admin_url('payplex_staff/policy_roles/staff/' . (int) $this->input->post('staff_id')));
    }

    public function revoke()
    {
        $this->requirePost();
        $id = (int) $this->input->post('id');
        $r  = $this->pr->revoke($id, $this->actor(), (string) $this->input->post('revocation_reason'));
        set_alert(!empty($r['ok']) ? 'success' : 'danger',
            !empty($r['ok']) ? 'Assignment revoked. The record is retained.'
                             : 'Refused: ' . implode(', ', $r['errors']));
        redirect(admin_url('payplex_staff/policy_roles/staff/' . (int) $this->input->post('staff_id')));
    }
}
