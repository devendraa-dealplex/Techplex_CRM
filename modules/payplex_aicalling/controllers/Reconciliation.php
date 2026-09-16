<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_api_client.php';

/**
 * Failed-sync + manual reconciliation screen. Reads the outbox (pending/failed
 * outbound requests) and the webhook inbox (unprocessed/invalid webhooks), and
 * lets an authorized user trigger a manual reconcile against the backend.
 */
class Reconciliation extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_aicalling/aicalling_model');
        $this->load->model('payplex_aicalling/payplex_audit_model');
    }

    private function cap($c)
    {
        return is_admin() || staff_can($c, 'payplex_aicalling');
    }

    public function index()
    {
        if (!$this->cap('reconcile_view')) {
            access_denied('payplex_aicalling reconcile');
        }
        $data['title']  = 'Failed Sync & Reconciliation';
        $data['outbox'] = $this->db->where_in('status', ['pending', 'failed'])
            ->order_by('created_at', 'DESC')->limit(100)
            ->get(db_prefix() . 'payplex_outbox')->result();
        $data['badWebhooks'] = $this->db->group_start()
                ->where('signature_valid', 0)->or_where('processed', 0)->group_end()
            ->order_by('received_at', 'DESC')->limit(100)
            ->get(db_prefix() . 'payplex_webhook_inbox')->result();
        $this->load->view('payplex_aicalling/reconciliation', $data);
    }

    /** Reconcile in-progress calls against the backend (manual). */
    public function run()
    {
        if (!$this->cap('reconcile_run')) {
            ajax_access_denied();
        }
        $stuck = $this->db->where_in('status', ['queued', 'ringing', 'answered', 'pending', 'queued_local'])
            ->limit(100)->get(db_prefix() . 'payplex_calls')->result();
        $ids = array_values(array_filter(array_map(function ($c) { return $c->sonivo_call_id; }, $stuck)));
        $updated = 0;
        if (!empty($ids)) {
            $client = new Payplex_api_client();
            $res = $client->reconcile($ids);
            if (!empty($res['ok']) && is_array($res['data'])) {
                foreach ($res['data'] as $row) {
                    if (!empty($row['call_id']) && !empty($row['status']) && $row['status'] !== 'unknown') {
                        $this->aicalling_model->updateBySonivoId($row['call_id'], ['status' => $row['status']]);
                        $updated++;
                    }
                }
            }
        }
        $this->payplex_audit_model->log('reconcile.manual', 'integration', null, null, ['checked' => count($ids), 'updated' => $updated]);
        set_alert('success', "Reconciliation done — {$updated} call(s) updated.");
        redirect(admin_url('payplex_aicalling/reconciliation'));
    }
}
