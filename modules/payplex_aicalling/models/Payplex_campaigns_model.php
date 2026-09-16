<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * CRM-side campaign records. Execution happens on the Sonivo backend; this
 * mirrors state + enforces the maker-checker approval gate (creator cannot
 * approve their own campaign).
 */
class Payplex_campaigns_model extends App_Model
{
    private $table;

    public function __construct()
    {
        parent::__construct();
        $this->table = db_prefix() . 'payplex_campaigns';
    }

    public function create(array $data)
    {
        $data['status']     = 'pending_approval';
        $data['created_by'] = get_staff_user_id();
        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->insert($this->table, $data);
        return $this->db->insert_id();
    }

    public function get($id)
    {
        return $this->db->where('id', (int) $id)->get($this->table)->row();
    }

    public function all($status = null, $limit = 100)
    {
        if ($status) {
            $this->db->where('status', $status);
        }
        return $this->db->order_by('created_at', 'DESC')->limit($limit)->get($this->table)->result();
    }

    public function update($id, array $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->where('id', (int) $id)->update($this->table, $data);
        return $this->db->affected_rows();
    }

    /**
     * Maker-checker: the approver must differ from the creator.
     * Returns true on success, or a machine reason string.
     */
    public function approve($id, $approverStaffId)
    {
        $c = $this->get($id);
        if (!$c) {
            return 'not_found';
        }
        if ($c->status !== 'pending_approval') {
            return 'not_pending';
        }
        if ((int) $c->created_by === (int) $approverStaffId) {
            return 'maker_is_checker'; // segregation of duties
        }
        $this->update($id, [
            'status'      => 'approved',
            'approved_by' => (int) $approverStaffId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    public function reject($id, $reason)
    {
        return $this->update($id, ['status' => 'rejected', 'reject_reason' => $reason]);
    }
}
