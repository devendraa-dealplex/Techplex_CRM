<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * Loaded explicitly rather than relying on Kpi_engine pulling it in. This
 * controller now makes decisions with it on every request, and a transitive
 * require is not a dependency anyone can see.
 */
require_once __DIR__ . '/../libraries/Target_workflow.php';

class Sales_targets extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('sales_targets/sales_targets_model');
    }

    public function index()
    {
        $data['title'] = _l('sales_targets_title');
        $data['is_manager_view'] = is_admin() || staff_can('view_all', 'sales_targets');
        $data['show_all_statuses'] = (bool) $this->input->get('all');
        $data['records'] = $data['is_manager_view']
            ? $this->sales_targets_model->get_all(null, $data['show_all_statuses'])
            : $this->sales_targets_model->get_all(get_staff_user_id(), $data['show_all_statuses']);
        /*
         * tblsales_target_metrics holds three KPIs across these targets and the
         * list showed one. Target #5 carries 6,00,000 billed and 4,00,000
         * collected, neither of which ever reached a screen.
         */
        foreach ($data['records'] as $i => $r) {
            $data['records'][$i]['metrics'] = $this->sales_targets_model->get_target_metrics((int) $r['id']);
        }
        $this->load->view('sales_targets/index', $data);
    }

    public function manage($id = '')
    {
        $allowed = is_admin() || staff_can('create', 'sales_targets') || staff_can('revise_active', 'sales_targets');
        if (!$allowed) {
            access_denied('sales_targets');
        }

        /*
         * /sales_targets/manage/9999 rendered a blank "Edit Sales Target" form
         * for a target that does not exist, and a Cancelled target opened for
         * editing with nothing saying so. Perfex core refuses an unknown id
         * correctly; this module did not.
         */
        $existing = ($id === '' || $id === null) ? array() : $this->sales_targets_model->get($id);
        $gate = Target_workflow::editGate($id, $existing);
        if (!$gate['ok']) {
            set_alert('warning', $gate['message']);
            redirect(admin_url('sales_targets'));
        }

        if ($this->input->post()) {
            $post_data = $this->input->post();

            $save_data = array(
                'staff_id' => (int) $post_data['staff_id'],
                'target_value' => (int) $post_data['target_value'],
                'period_start' => $post_data['period_start'],
                'period_end' => $post_data['period_end'],
                'notes' => trim($post_data['notes']),
                // Phase 4: both optional metrics are always submitted by the
                // manage form (see manage.php), null when the field was left
                // blank. Passed through as-is so Sales_targets_model::add()/
                // update() can decide whether to create, sync, or remove the
                // corresponding metric row — the controller does not decide
                // that itself, matching how 'target_value' has always worked.
                'target_revenue_value' => (isset($post_data['target_revenue_value']) && trim($post_data['target_revenue_value']) !== '') ? (float) $post_data['target_revenue_value'] : null,
                'target_collected_value' => (isset($post_data['target_collected_value']) && trim($post_data['target_collected_value']) !== '') ? (float) $post_data['target_collected_value'] : null,
            );

            if ($id == '') {
                /*
                 * The overlap rule only ran on the way into Active, and the
                 * create path never went that way. It runs here now, so a
                 * duplicate is refused before it exists.
                 */
                $create = $this->sales_targets_model->creation_gate($save_data);
                if (!$create['allowed']) {
                    set_alert('warning', $create['reason']);
                    redirect(admin_url('sales_targets/manage'));
                }
                $save_data['created_by'] = get_staff_user_id();
                $save_data['date_created'] = date('Y-m-d H:i:s');
                $insert_id = $this->sales_targets_model->add($save_data);
                if ($insert_id) {
                    log_activity('New Sales Target Added [ID: ' . $insert_id . '] in '
                        . Target_workflow::initialState());
                    set_alert('success', _l('sales_targets_added')
                        . ' It starts as a ' . strtolower(Target_workflow::initialState())
                        . ' and must be submitted and approved by someone else before it counts.');
                }
            } else {
                $updated = $this->sales_targets_model->update($id, $save_data);
                if ($updated) {
                    log_activity('Sales Target Updated [ID: ' . $id . ']');
                    set_alert('success', _l('sales_targets_updated'));
                }
            }

            redirect(admin_url('sales_targets'));
        }

        $data['title'] = $id == '' ? _l('sales_targets_add_title') : _l('sales_targets_edit_title');
        $data['target_id'] = $id;
        $data['target'] = $id == '' ? array() : $this->sales_targets_model->get($id);
        $data['staff'] = $this->sales_targets_model->get_active_staff();

        // Phase 4: pre-fill the optional gross_billed_revenue / amount_collected
        // fields on the Edit form from whatever metric rows already exist for
        // this target. Keyed by kpi_key so the view can look up
        // $metrics['gross_billed_revenue'] / $metrics['amount_collected']
        // directly. Empty on Add (no target_id yet) and simply absent for a
        // target that never had that metric attached.
        $data['metrics'] = array();
        if ($id != '') {
            $existing_metrics = $this->sales_targets_model->get_target_metrics($id);
            foreach ($existing_metrics as $metric) {
                $data['metrics'][$metric['kpi_key']] = $metric['target_value'];
            }
        }

        $this->load->view('sales_targets/manage', $data);
    }

    public function delete($id)
    {
        $allowed = is_admin() || staff_can('cancel_archive', 'sales_targets');
        if (!$allowed) {
            access_denied('sales_targets');
        }
        $deleted = $this->sales_targets_model->delete($id);
        if ($deleted) {
            log_activity('Sales Target Deleted [ID: ' . $id . ']');
            set_alert('success', _l('sales_targets_deleted'));
        }
        redirect(admin_url('sales_targets'));
    }

    /* ================= v1.2.0 — achievement detail & lifecycle (§3.2, §3.3) ================= */

    /**
     * Multi-KPI achievement for one target: every KPI scored, the overall
     * rollup, and — crucially — what could not be measured and why.
     */
    public function achievement($id = 0)
    {
        $id = (int) $id;
        $target = $this->sales_targets_model->target_row($id);
        if (!$target) { show_404(); }

        $me = get_staff_user_id();
        $assignees = $this->sales_targets_model->assignees_for($id);

        // an employee may see their own target; anyone else needs the capability
        $isOwn = Target_workflow::isAssignee($me, $target, $assignees);
        /*
         * 'view' was never a registered capability of this module, so
         * staff_can() answered false for everyone who is not an administrator
         * and no manager could open a colleague's target. Seeing someone
         * else's is what view_team and view_all are for.
         */
        $canSeeOthers = staff_can('view_team', 'sales_targets')
                     || staff_can('view_all', 'sales_targets');
        if (!$isOwn && !is_admin() && !$canSeeOthers) {
            access_denied('sales_targets');
        }

        $data['title']      = 'Target achievement';
        $data['target']     = $target;
        $data['breakdown']  = $this->sales_targets_model->achievement_breakdown($target);
        $data['assignees']  = $assignees;
        $data['audit']      = $this->sales_targets_model->target_audit_log($id, 60);
        $data['is_own']     = $isOwn;
        $data['me']         = $me;
        // 'edit' was likewise never registered; the module's editing rights
        // are edit_draft and revise_active.
        $data['can_manage'] = is_admin()
            || staff_can('edit_draft', 'sales_targets')
            || staff_can('revise_active', 'sales_targets');
        $this->load->view('sales_targets/achievement', $data);
    }

    /** Move a target through its lifecycle (POST). */
    public function transition()
    {
        $id = (int) $this->input->post('id');
        $to = (string) $this->input->post('to');

        /*
         * This endpoint had no access check whatsoever: any signed-in staff
         * member could post a target id and a destination, and the only thing
         * between them and the change was whatever canAct() happened to allow.
         * The capability required is looked up from the target state, and an
         * unrecognised destination is refused rather than passed along.
         */
        $need = Target_workflow::capabilityFor($to);
        if ($need === null || !(is_admin() || staff_can($need, 'sales_targets'))) {
            access_denied('sales_targets');
        }

        $res = $this->sales_targets_model->target_transition($id, $to, get_staff_user_id(), array(
            'reason'    => (string) $this->input->post('reason'),
            // 'senior' was never registered, so this was false for every
            // non-administrator and every senior-only step was shut to them.
            'is_senior' => is_admin() || staff_can('approve_activate', 'sales_targets'),
        ));

        set_alert(!empty($res['ok']) ? 'success' : 'danger', !empty($res['ok'])
            ? 'Target #' . $id . ' moved to ' . $to . '.' : $res['reason']);
        redirect(admin_url('sales_targets/achievement/' . $id));
    }
}
