<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Classification storage.
 *
 * The previous save() took whatever staff_id it was handed and inserted a row
 * for it. There was no existence check anywhere in the write path, so a POST to
 * /staff_classification/edit/99999 created a classification for a staff member
 * who does not exist. The controller now gates the id, and this model refuses
 * independently — two checks, because the one that was missing was the one that
 * mattered.
 */
class Staff_classification_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param bool $includeInactive the list used to hide inactive staff while
     *                              the edit URL still opened them
     */
    public function get_all($includeInactive = false)
    {
        $this->db->select(
            'staff.staffid, staff.firstname, staff.lastname, staff.email, staff.active,
             sc.employment_type, sc.work_category, sc.work_mode, sc.department, sc.department_id,
             sc.branch, sc.territory, sc.shift, sc.manager_id, sc.date_updated,
             dept.name as department_name,
             mgr.firstname as manager_firstname, mgr.lastname as manager_lastname, mgr.active as manager_active'
        );
        $this->db->from(db_prefix() . 'staff staff');
        $this->db->join(db_prefix() . 'staff_classification sc', 'sc.staff_id = staff.staffid', 'left');
        $this->db->join(db_prefix() . 'departments dept', 'dept.departmentid = sc.department_id', 'left');
        $this->db->join(db_prefix() . 'staff mgr', 'mgr.staffid = sc.manager_id', 'left');
        if (!$includeInactive) {
            $this->db->where('staff.active', 1);
        }
        $this->db->order_by('staff.active', 'desc');
        $this->db->order_by('staff.firstname', 'asc');

        return $this->db->get()->result_array();
    }

    public function inactive_count()
    {
        return (int) $this->db->where('active', 0)->count_all_results(db_prefix() . 'staff');
    }

    public function get($staff_id)
    {
        $this->db->where('staff_id', (int) $staff_id);
        $row = $this->db->get(db_prefix() . 'staff_classification')->row_array();
        return $row ?: array();
    }

    public function get_staff_member($staff_id)
    {
        $id = trim((string) $staff_id);
        if ($id === '' || !ctype_digit($id)) {
            return null;
        }
        $this->db->where('staffid', (int) $id);
        return $this->db->get(db_prefix() . 'staff')->row();
    }

    /** Real departments, so department stops being a typed string. */
    public function departments()
    {
        return $this->db->order_by('name', 'asc')
                        ->get(db_prefix() . 'departments')->result_array();
    }

    public function get_potential_managers($exclude_staff_id)
    {
        $this->db->select('staffid, firstname, lastname');
        $this->db->where('active', 1);
        $this->db->where('is_not_staff', 0);
        $this->db->where('staffid !=', (int) $exclude_staff_id);
        $this->db->order_by('firstname', 'asc');

        return $this->db->get(db_prefix() . 'staff')->result_array();
    }

    /** staff_id => manager_id, for loop detection. */
    public function manager_chain()
    {
        $chain = array();
        foreach ($this->db->select('staff_id, manager_id')
                          ->get(db_prefix() . 'staff_classification')->result_array() as $r) {
            $chain[(int) $r['staff_id']] = (int) $r['manager_id'];
        }
        return $chain;
    }

    /**
     * @return array ok, reason
     */
    public function save($staff_id, $values)
    {
        $staff_id = (int) $staff_id;

        /* The check whose absence created orphan rows. */
        $exists = $this->db->where('staffid', $staff_id)->count_all_results(db_prefix() . 'staff');
        if ($exists === 0) {
            return array('ok' => false,
                         'reason' => 'Staff member #' . $staff_id . ' does not exist. Nothing was saved.');
        }

        $allowed = array('employment_type', 'work_category', 'department_id', 'manager_id',
                         'branch', 'territory', 'shift');
        $save = array();
        foreach ($allowed as $f) {
            $save[$f] = array_key_exists($f, $values) ? $values[$f] : null;
        }
        $save['date_updated'] = date('Y-m-d H:i:s');

        if ($this->get($staff_id)) {
            $this->db->where('staff_id', $staff_id);
            $this->db->update(db_prefix() . 'staff_classification', $save);
            /*
             * affected_rows is 0 for a save that changes nothing and -1 on
             * error. Treating -1 as "no change" is a mistake this project has
             * already made once.
             */
            return array('ok' => $this->db->affected_rows() >= 0, 'reason' => '');
        }

        $save['staff_id'] = $staff_id;
        $this->db->insert(db_prefix() . 'staff_classification', $save);
        return array('ok' => $this->db->insert_id() > 0, 'reason' => '');
    }

    /** Field-level audit. The old module recorded only that something changed. */
    public function audit($staff_id, $actor_id, array $changes)
    {
        if (!$changes) { return 0; }
        $table = db_prefix() . 'wf_classification_audit';
        if (!$this->db->table_exists($table)) { return 0; }

        $rows = array();
        foreach ($changes as $field => $c) {
            $rows[] = array(
                'staff_id'   => (int) $staff_id,
                'actor_id'   => (int) $actor_id,
                'field'      => $field,
                'old_value'  => $c['from'] === '' ? null : mb_substr((string) $c['from'], 0, 190),
                'new_value'  => $c['to']   === '' ? null : mb_substr((string) $c['to'], 0, 190),
                'changed_at' => date('Y-m-d H:i:s'),
            );
        }
        $this->db->insert_batch($table, $rows);
        return count($rows);
    }

    public function history($staff_id, $limit = 100)
    {
        $table = db_prefix() . 'wf_classification_audit';
        if (!$this->db->table_exists($table)) { return array(); }
        $this->db->select('a.*, CONCAT(s.firstname," ",s.lastname) AS actor_name');
        $this->db->from($table . ' a');
        $this->db->join(db_prefix() . 'staff s', 's.staffid = a.actor_id', 'left');
        $this->db->where('a.staff_id', (int) $staff_id);
        $this->db->order_by('a.id', 'desc');
        $this->db->limit((int) $limit);
        return $this->db->get()->result_array();
    }
}
