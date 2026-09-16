<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Storage for workforce policy-role assignments.
 *
 * All decision logic lives in Workforce_policy_roles; this class only reads and
 * writes rows. Keeping them apart is what lets the rules be mutation-tested
 * without a database.
 *
 * NOTHING HERE DELETES. Revocation stamps the row. There is no delete method,
 * so "permanent deletion of role history" is not a policy the code is trusted
 * to follow — it is an operation that does not exist.
 */
class Payplex_policy_roles_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
        if (!class_exists('Workforce_policy_roles')) {
            require_once __DIR__ . '/../libraries/Workforce_policy_roles.php';
        }
    }

    private function table() { return db_prefix() . 'payplex_staff_policy_roles'; }
    private function auditTable() { return db_prefix() . 'payplex_staff_audit'; }
    public  function ready()  { return $this->db->table_exists($this->table()); }

    /** Every assignment for one staff member, newest first. History included. */
    public function forStaff($staffId)
    {
        if (!$this->ready()) { return array(); }
        return $this->db->where('staff_id', (int) $staffId)
                        ->order_by('id', 'DESC')->limit(200)
                        ->get($this->table())->result_array();
    }

    /** Live assignments across everybody — drives the conflict panel. */
    public function allLive()
    {
        if (!$this->ready()) { return array(); }
        $rows = $this->db->where('is_active', 1)->where('revoked_at', null)
                         ->order_by('staff_id', 'ASC')->limit(2000)
                         ->get($this->table())->result_array();
        $now = time(); $out = array();
        foreach ($rows as $r) {
            $st = Workforce_policy_roles::assignmentState($r, $now);
            if (!empty($st['live'])) { $out[] = $r; }
        }
        return $out;
    }

    /** Staff members holding more than one live role. */
    public function conflicts()
    {
        $byStaff = array();
        foreach ($this->allLive() as $r) {
            $byStaff[(int) $r['staff_id']][] = strtolower(trim($r['policy_role']));
        }
        $out = array();
        foreach ($byStaff as $sid => $roles) {
            $roles = array_values(array_unique($roles));
            if (count($roles) > 1) { $out[$sid] = $roles; }
        }
        return $out;
    }

    /**
     * Create an assignment.
     *
     * Validation happens in Workforce_policy_roles and is re-run here rather
     * than trusted from the controller: a model that assumes its caller checked
     * is a model that can be reached another way.
     */
    public function assign(array $data, $actorId)
    {
        if (!$this->ready()) { return array('ok' => false, 'errors' => array('table_missing')); }

        $staffId = isset($data['staff_id']) ? (int) $data['staff_id'] : 0;
        $ctx = array(
            'actor_id'      => (int) $actorId,
            'target_active' => $this->staffIsActive($staffId),
            'existing'      => $this->forStaff($staffId),
            'now'           => time(),
        );
        $v = Workforce_policy_roles::validateAssignment($data, $ctx);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors']); }

        $now  = date('Y-m-d H:i:s');
        $role = strtolower(trim((string) $data['policy_role']));
        $scope = Workforce_policy_roles::scopeOf($data);

        /* Sensitive or company-wide assignments are stored PENDING and are not
           live until a second person approves them. is_active stays 0 so the
           resolver cannot pick them up in the meantime. */
        $needsApproval = Workforce_policy_roles::requiresMakerChecker($role, $scope);

        $row = array(
            'staff_id'           => $staffId,
            'policy_role'        => $role,
            'business_entity_id' => isset($scope['business_entity_id']) ? $scope['business_entity_id'] : null,
            'branch'             => isset($scope['branch'])     ? $scope['branch']     : null,
            'department'         => isset($scope['department']) ? $scope['department'] : null,
            'region'             => isset($scope['region'])     ? $scope['region']     : null,
            'effective_from'     => !empty($data['effective_from']) ? $data['effective_from'] : null,
            'expires_at'         => !empty($data['expires_at'])     ? $data['expires_at']     : null,
            'is_active'          => $needsApproval ? 0 : 1,
            'approval_state'     => $needsApproval ? 'pending' : 'approved',
            'assigned_by'        => (int) $actorId,
            'assignment_reason'  => substr(trim((string) $data['assignment_reason']), 0, 255),
            'created_at'         => $now,
            'updated_at'         => $now,
        );
        $row['active_key'] = $row['is_active'] ? $staffId : null;

        $this->db->insert($this->table(), $row);
        $id = $this->db->insert_id();

        $this->audit($staffId, 'policy_role_assign',
            'Policy role ' . $role . ' assigned' . ($needsApproval ? ' (PENDING approval)' : ''),
            array('assignment_id' => $id, 'role' => $role, 'scope' => $scope,
                  'pending' => $needsApproval ? 1 : 0), $actorId);

        return array('ok' => true, 'id' => $id, 'pending' => $needsApproval);
    }

    /**
     * Approve a pending assignment. The approver may not be the assigner:
     * maker-checker applies to the act of granting authority too.
     */
    public function approve($id, $actorId)
    {
        if (!$this->ready()) { return array('ok' => false, 'errors' => array('table_missing')); }
        $row = $this->db->where('id', (int) $id)->get($this->table())->row_array();
        if (!$row) { return array('ok' => false, 'errors' => array('not_found')); }
        if ($row['approval_state'] !== 'pending') { return array('ok' => false, 'errors' => array('not_pending')); }
        if ((int) $row['assigned_by'] === (int) $actorId) {
            return array('ok' => false, 'errors' => array('approver_is_the_assigner'));
        }
        if ((int) $row['staff_id'] === (int) $actorId) {
            return array('ok' => false, 'errors' => array('self_approval_refused'));
        }
        /* Refuse to activate into a conflict. */
        foreach ($this->forStaff($row['staff_id']) as $other) {
            if ((int) $other['id'] === (int) $id) { continue; }
            $st = Workforce_policy_roles::assignmentState($other, time());
            if (!empty($st['live']) && strtolower(trim($other['policy_role'])) !== strtolower(trim($row['policy_role']))) {
                return array('ok' => false, 'errors' => array('conflicting_active_assignment'));
            }
        }
        $now = date('Y-m-d H:i:s');
        $this->db->where('id', (int) $id)->update($this->table(), array(
            'approval_state' => 'approved', 'approved_by' => (int) $actorId,
            'approved_at' => $now, 'is_active' => 1,
            'active_key' => (int) $row['staff_id'], 'updated_at' => $now,
        ));
        $this->audit((int) $row['staff_id'], 'policy_role_approve',
            'Policy role ' . $row['policy_role'] . ' approved',
            array('assignment_id' => (int) $id), $actorId);
        return array('ok' => true);
    }

    /** Revoke. Updates the row; never removes it. */
    public function revoke($id, $actorId, $reason)
    {
        if (!$this->ready()) { return array('ok' => false, 'errors' => array('table_missing')); }
        $row = $this->db->where('id', (int) $id)->get($this->table())->row_array();
        if (!$row) { return array('ok' => false, 'errors' => array('not_found')); }

        $fields = Workforce_policy_roles::revocationFields($actorId, $reason, time());
        if ($fields === null) { return array('ok' => false, 'errors' => array('revocation_reason_required')); }
        $fields['active_key'] = null;

        $this->db->where('id', (int) $id)->update($this->table(), $fields);
        $this->audit((int) $row['staff_id'], 'policy_role_revoke',
            'Policy role ' . $row['policy_role'] . ' revoked',
            array('assignment_id' => (int) $id, 'reason' => $fields['revocation_reason']), $actorId);
        return array('ok' => true);
    }

    public function staffIsActive($staffId)
    {
        $t = db_prefix() . 'staff';
        if (!$this->db->table_exists($t)) { return false; }
        $r = $this->db->select('active')->where('staffid', (int) $staffId)->limit(1)
                      ->get($t)->row_array();
        return $r ? ((int) $r['active'] === 1) : false;
    }

    private function audit($staffId, $type, $message, array $data, $actorId)
    {
        if (!$this->db->table_exists($this->auditTable())) { return; }
        $this->db->insert($this->auditTable(), array(
            'staff_id'   => (int) $staffId,
            'entry_type' => $type,
            'message'    => substr((string) $message, 0, 255),
            'data_json'  => json_encode($data),
            'created_by' => (int) $actorId,
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }
}
