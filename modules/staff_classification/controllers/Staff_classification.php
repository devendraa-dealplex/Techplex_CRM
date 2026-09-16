<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Workforce_classification.php';

/**
 * Staff classification.
 *
 * Every decision here is delegated to Workforce_classification, which is pure
 * and tested. The controller's job is to fetch, ask, and either save or
 * re-render with the reasons — it no longer makes rules of its own, because
 * the rules it used to make inline were wrong in four different ways. See the
 * library's header for what each of them did.
 */
class Staff_classification extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('staff_classification/staff_classification_model');
    }

    private function canView()
    {
        return staff_can('view', 'staff_classification') || staff_can('view', 'staff') || is_admin();
    }

    private function canEdit()
    {
        return staff_can('edit', 'staff_classification') || staff_can('edit', 'staff') || is_admin();
    }

    public function index()
    {
        if (!$this->canView()) {
            access_denied('staff_classification');
        }

        /*
         * The list used to hard-filter active = 1, which hid every deactivated,
         * resigned and on-notice worker — while the edit URL happily opened
         * them. Inactive staff are now included behind an explicit toggle, so
         * what the list shows and what the URL reaches are the same set.
         */
        $includeInactive = (bool) $this->input->get('inactive');

        $data['title']            = _l('staff_classification_title');
        $data['records']          = $this->staff_classification_model->get_all($includeInactive);
        $data['include_inactive'] = $includeInactive;
        $data['inactive_count']   = $this->staff_classification_model->inactive_count();
        $data['employment_types'] = Workforce_classification::employmentTypes();
        $data['work_categories']  = Workforce_classification::workCategories();
        $data['can_edit']         = $this->canEdit();

        $this->load->view('staff_classification/list', $data);
    }

    public function edit($staff_id = '')
    {
        if (!$this->canEdit()) {
            access_denied('staff_classification');
        }

        /*
         * DEFECT: this used to be `if (!$staff_id) redirect(...)` and nothing
         * else, so /edit/99999 rendered a complete working form for a staff
         * member who does not exist, and the model would have inserted a row
         * for that id. Perfex core refuses the same request correctly; this
         * module now does too.
         */
        $member = $this->staff_classification_model->get_staff_member($staff_id);
        $gate   = Workforce_classification::staffGate($staff_id, $member ? (array) $member : null);

        if (!$gate['ok']) {
            set_alert('warning', $gate['message']);
            redirect(admin_url('staff_classification'));
        }
        $staff_id = (int) $staff_id;

        $departments = $this->staff_classification_model->departments();
        $managers    = $this->staff_classification_model->get_potential_managers($staff_id);

        $errors = array();
        $posted = null;

        if ($this->input->post()) {
            $posted = $this->input->post();
            $ctx = array(
                'staff_id'       => $staff_id,
                'department_ids' => array_column($departments, 'departmentid'),
                'manager_ids'    => array_column($managers, 'staffid'),
            );

            $result = Workforce_classification::validate($posted, $ctx);
            $errors = $result['errors'];

            if ($result['ok'] && !empty($result['values']['manager_id'])) {
                $chain = $this->staff_classification_model->manager_chain();
                if (Workforce_classification::createsCycle($staff_id, $result['values']['manager_id'], $chain)) {
                    $errors['manager_id'] = 'That reporting line loops back on itself. '
                                          . 'Choose a manager who does not already report to this person.';
                }
            }

            if (empty($errors)) {
                $before = $this->staff_classification_model->get($staff_id);
                $saved  = $this->staff_classification_model->save($staff_id, $result['values']);

                if ($saved['ok']) {
                    $changes = Workforce_classification::diff($before ?: array(), $result['values']);
                    $this->staff_classification_model->audit($staff_id, get_staff_user_id(), $changes);

                    /*
                     * The old log line was "Staff Classification Updated
                     * [StaffID: n]" — that something changed, never what. A
                     * classification decides commission eligibility and
                     * reporting lines.
                     */
                    log_activity('Staff Classification Updated [StaffID: ' . $staff_id . '; '
                        . ($changes ? count($changes) . ' field(s): ' . implode(', ', array_keys($changes))
                                    : 'no change') . ']');

                    set_alert('success', $changes
                        ? _l('staff_classification_updated') . ' (' . count($changes) . ' field(s) changed)'
                        : 'No changes to save.');
                    redirect(admin_url('staff_classification'));
                }

                set_alert('danger', $saved['reason']);
                redirect(admin_url('staff_classification'));
            }

            /*
             * Invalid input used to be nulled and saved with a success message.
             * Now nothing is written and the form comes back with the reasons.
             */
            set_alert('warning', 'Nothing was saved. Please correct the highlighted field(s).');
        }

        $data['title']            = _l('staff_classification_edit_title');
        $data['staff_id']         = $staff_id;
        $data['staff_member']     = $member;
        $data['gate']             = $gate;
        $data['classification']   = $this->staff_classification_model->get($staff_id);
        $data['managers']         = $managers;
        $data['departments']      = $departments;
        $data['employment_types'] = Workforce_classification::employmentTypes();
        $data['work_categories']  = Workforce_classification::workCategories();
        $data['errors']           = $errors;
        $data['posted']           = $posted;

        $this->load->view('staff_classification/edit', $data);
    }

    /** Field-level history for one staff member. */
    public function history($staff_id = '')
    {
        if (!$this->canView()) {
            access_denied('staff_classification');
        }
        $member = $this->staff_classification_model->get_staff_member($staff_id);
        $gate   = Workforce_classification::staffGate($staff_id, $member ? (array) $member : null);
        if (!$gate['ok']) {
            set_alert('warning', $gate['message']);
            redirect(admin_url('staff_classification'));
        }

        $data['title']        = 'Classification history';
        $data['staff_member'] = $member;
        $data['staff_id']     = (int) $staff_id;
        $data['entries']      = $this->staff_classification_model->history((int) $staff_id);
        $data['employment_types'] = Workforce_classification::employmentTypes();
        $data['work_categories']  = Workforce_classification::workCategories();
        $this->load->view('staff_classification/history', $data);
    }
}
