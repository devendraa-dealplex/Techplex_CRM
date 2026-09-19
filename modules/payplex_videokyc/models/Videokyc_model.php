<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_kyc_scripts.php';

/**
 * Data access for Video KYC. All state transitions live here so the rules exist
 * in exactly one place:
 *
 *   pending ──open──▶ in_progress ──upload──▶ submitted ──review──▶ approved
 *      │                   │                                    └──▶ rejected
 *      └──────(clock)──────┴──▶ expired
 *
 *   resend/reissue: any state except approved ──▶ pending (new token, new expiry)
 */
class Videokyc_model extends App_Model
{
    const STATUSES = ['pending', 'in_progress', 'submitted', 'approved', 'rejected', 'expired'];

    private $req;
    private $vid;
    private $ntf;
    private $tpl;

    public function __construct()
    {
        parent::__construct();
        $p         = db_prefix();
        $this->req = $p . 'payplex_vkyc_requests';
        $this->vid = $p . 'payplex_vkyc_videos';
        $this->ntf = $p . 'payplex_vkyc_notifications';
        $this->tpl = $p . 'payplex_vkyc_templates';
    }

    /* ---------------------------------------------------------------- tokens */

    /**
     * 256 bits from the OS CSPRNG. Only the SHA-256 is stored; the raw value
     * exists in the link that is sent to the customer and nowhere else.
     *
     * @return array [rawToken, tokenHash]
     */
    public function newToken()
    {
        $raw = bin2hex(random_bytes(32));
        return [$raw, hash('sha256', $raw)];
    }

    public static function looksLikeToken($t)
    {
        return is_string($t) && (bool) preg_match('/^[a-f0-9]{64}$/', $t);
    }

    /** Request row for a raw token, with lazy expiry applied. Null if unknown. */
    public function findByToken($rawToken)
    {
        if (!self::looksLikeToken($rawToken)) {
            return null;
        }
        $row = $this->db->where('token_hash', hash('sha256', $rawToken))->get($this->req)->row();
        if ($row && in_array($row->status, ['pending', 'in_progress'], true)
            && strtotime($row->expires_at) < time()) {
            $this->db->where('id', $row->id)->update($this->req, ['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')]);
            $row->status = 'expired';
        }
        return $row;
    }

    /** Flip overdue links to 'expired' so counts and lists never lie. */
    public function expireOverdue()
    {
        $this->db->where_in('status', ['pending', 'in_progress'])
            ->where('expires_at <', date('Y-m-d H:i:s'))
            ->update($this->req, ['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /* -------------------------------------------------------------- subjects */

    /** Search leads or customers by name/email/phone for the "Generate link" picker. */
    public function searchSubjects($type, $q, $limit = 15)
    {
        $q   = trim((string) $q);
        $out = [];
        if ($type === 'lead') {
            $this->db->select('id, name, email, phonenumber AS phone')->from(db_prefix() . 'leads');
            if ($q !== '') {
                $this->db->group_start()->like('name', $q)->or_like('email', $q)->or_like('phonenumber', $q)->group_end();
            }
            foreach ($this->db->order_by('id', 'DESC')->limit($limit)->get()->result() as $r) {
                $out[] = ['id' => (int) $r->id, 'name' => $r->name, 'email' => $r->email, 'phone' => $r->phone];
            }
        } else {
            $this->db->select('c.userid AS id, c.company AS name, ct.email, COALESCE(NULLIF(ct.phonenumber, ""), c.phonenumber) AS phone', false)
                ->from(db_prefix() . 'clients c')
                ->join(db_prefix() . 'contacts ct', 'ct.userid = c.userid AND ct.is_primary = 1', 'left');
            if ($q !== '') {
                $this->db->group_start()->like('c.company', $q)->or_like('ct.email', $q)->or_like('c.phonenumber', $q)->group_end();
            }
            foreach ($this->db->order_by('c.userid', 'DESC')->limit($limit)->get()->result() as $r) {
                $out[] = ['id' => (int) $r->id, 'name' => $r->name, 'email' => $r->email, 'phone' => $r->phone];
            }
        }
        return $out;
    }

    /** One subject, resolved server-side — never trust name/email/phone posted by the browser. */
    public function subject($type, $id)
    {
        $rows = $this->searchSubjectsById($type, (int) $id);
        return $rows ?: null;
    }

    private function searchSubjectsById($type, $id)
    {
        if ($id <= 0) {
            return null;
        }
        if ($type === 'lead') {
            $r = $this->db->select('id, name, email, phonenumber AS phone')->where('id', $id)
                ->get(db_prefix() . 'leads')->row();
        } else {
            $r = $this->db->select('c.userid AS id, c.company AS name, ct.email, COALESCE(NULLIF(ct.phonenumber, ""), c.phonenumber) AS phone', false)
                ->from(db_prefix() . 'clients c')
                ->join(db_prefix() . 'contacts ct', 'ct.userid = c.userid AND ct.is_primary = 1', 'left')
                ->where('c.userid', $id)->get()->row();
        }
        return $r ? ['id' => (int) $r->id, 'name' => (string) $r->name, 'email' => (string) $r->email, 'phone' => (string) $r->phone] : null;
    }

    /* ------------------------------------------------------------- templates */

    public function templates($onlyActive = false)
    {
        if ($onlyActive) {
            $this->db->where('active', 1);
        }
        return $this->db->order_by('is_default', 'DESC')->order_by('name', 'ASC')->get($this->tpl)->result();
    }

    public function template($id)
    {
        return $this->db->where('id', (int) $id)->get($this->tpl)->row();
    }

    public function defaultTemplate()
    {
        $t = $this->db->where('active', 1)->where('is_default', 1)->get($this->tpl)->row();
        return $t ?: $this->db->where('active', 1)->order_by('id', 'ASC')->limit(1)->get($this->tpl)->row();
    }

    public function saveTemplate($id, $name, $body, $bodyHi, $bodyMr, $active, $isDefault, $staffId)
    {
        $data = [
            'name'       => $name,
            'body'       => $body,
            'body_hi'    => $bodyHi !== '' ? $bodyHi : null,   // empty = use the standard Hindi statement
            'body_mr'    => $bodyMr !== '' ? $bodyMr : null,   // empty = use the standard Marathi statement
            'active'     => $active ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($isDefault) {
            $this->db->update($this->tpl, ['is_default' => 0]);
            $data['is_default'] = 1;
        }
        if ($id) {
            $this->db->where('id', (int) $id)->update($this->tpl, $data);
            return (int) $id;
        }
        $data['created_by'] = $staffId;
        $data['created_at'] = date('Y-m-d H:i:s');
        $this->db->insert($this->tpl, $data);
        return (int) $this->db->insert_id();
    }

    /** A template referenced by past requests is deactivated, not deleted — the audit trail keeps its id. */
    public function deleteTemplate($id)
    {
        $used = $this->db->where('template_id', (int) $id)->count_all_results($this->req);
        if ($used > 0) {
            $this->db->where('id', (int) $id)->update($this->tpl, ['active' => 0, 'is_default' => 0]);
            return 'deactivated';
        }
        $this->db->where('id', (int) $id)->delete($this->tpl);
        return 'deleted';
    }

    /**
     * The exact text the customer will be asked to read, in the chosen language,
     * with name / company / date filled in. Stored on the request as a snapshot.
     */
    public static function renderScript($tpl, $customerName, $lang)
    {
        $lang = Payplex_kyc_scripts::normalize($lang);
        return Payplex_kyc_scripts::render(
            Payplex_kyc_scripts::bodyFor($tpl, $lang),
            $customerName,
            $lang,
            (string) get_option('companyname')
        );
    }

    /* -------------------------------------------------------------- requests */

    public function createRequest(array $d)
    {
        $now = date('Y-m-d H:i:s');
        $this->db->insert($this->req, [
            'token_hash'     => $d['token_hash'],
            'rel_type'       => $d['rel_type'],
            'rel_id'         => (int) $d['rel_id'],
            'customer_name'  => $d['customer_name'],
            'customer_email' => $d['customer_email'] ?: null,
            'customer_phone' => $d['customer_phone'] ?: null,
            'template_id'    => $d['template_id'] ?: null,
            'dynamic_script' => $d['dynamic_script'],
            'script_language' => Payplex_kyc_scripts::normalize(isset($d['script_language']) ? $d['script_language'] : 'en'),
            'status'         => 'pending',
            'expires_at'     => $d['expires_at'],
            'created_by'     => (int) $d['created_by'],
            'created_at'     => $now,
        ]);
        return (int) $this->db->insert_id();
    }

    public function get($id)
    {
        return $this->db->where('id', (int) $id)->get($this->req)->row();
    }

    /**
     * Re-issue a link: new token, fresh expiry, attempts reset, back to pending.
     * The previous token stops working immediately. An approved request is final.
     *
     * @return string|false raw token, or false if the request cannot be re-issued
     */
    public function reissue($id, $expiresAt)
    {
        $r = $this->get($id);
        if (!$r || $r->status === 'approved') {
            return false;
        }
        list($raw, $hash) = $this->newToken();
        $this->db->where('id', (int) $id)->update($this->req, [
            'token_hash'      => $hash,
            'status'          => 'pending',
            'expires_at'      => $expiresAt,
            'upload_attempts' => 0,
            'opened_at'       => null,
            'send_count'      => (int) $r->send_count + 1,
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
        return $raw;
    }

    /** First open of the link: pending → in_progress. Idempotent. */
    public function markOpened($id)
    {
        $this->db->where('id', (int) $id)->where('status', 'pending')
            ->update($this->req, ['status' => 'in_progress', 'opened_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Claim an upload slot atomically. Two simultaneous uploads with the same
     * token cannot both pass: the UPDATE only succeeds while attempts < max and
     * the request is still open.
     */
    public function claimUploadAttempt($id, $maxAttempts)
    {
        $this->db->query(
            "UPDATE `{$this->req}` SET upload_attempts = upload_attempts + 1, updated_at = ?
             WHERE id = ? AND status IN ('pending','in_progress') AND upload_attempts < ? AND expires_at >= ?",
            [date('Y-m-d H:i:s'), (int) $id, (int) $maxAttempts, date('Y-m-d H:i:s')]
        );
        return $this->db->affected_rows() === 1;
    }

    /** Give a claimed slot back when the upload itself was rejected (bad file), so a customer's typo isn't a strike. */
    public function releaseUploadAttempt($id)
    {
        $this->db->query("UPDATE `{$this->req}` SET upload_attempts = GREATEST(upload_attempts - 1, 0) WHERE id = ?", [(int) $id]);
    }

    public function addVideo(array $v)
    {
        $this->db->insert($this->vid, [
            'request_id'   => (int) $v['request_id'],
            'storage_path' => $v['storage_path'],
            'mime_type'    => $v['mime_type'],
            'file_size'    => (int) $v['file_size'],
            'duration_sec' => $v['duration_sec'],
            'sha256'       => $v['sha256'],
            'uploaded_ip'  => $v['uploaded_ip'],
            'user_agent'   => $v['user_agent'],
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        $this->db->where('id', (int) $v['request_id'])->update($this->req, [
            'status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->insert_id();
    }

    public function video($id)
    {
        return $this->db->where('id', (int) $id)->get($this->vid)->row();
    }

    public function latestVideo($requestId)
    {
        return $this->db->where('request_id', (int) $requestId)->order_by('id', 'DESC')->limit(1)->get($this->vid)->row();
    }

    /**
     * Approve / reject. Compare-and-swap on status='submitted', so two reviewers
     * clicking at once can't both "win" and a decision can't be silently flipped.
     *
     * @return bool true if THIS call made the decision
     */
    public function review($requestId, $decision, $staffId, $notes, array $checklist)
    {
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $now    = date('Y-m-d H:i:s');
        $this->db->where('id', (int) $requestId)->where('status', 'submitted')
            ->update($this->req, ['status' => $status, 'reviewed_at' => $now, 'updated_at' => $now]);
        if ($this->db->affected_rows() !== 1) {
            return false;
        }
        $v = $this->latestVideo($requestId);
        if ($v) {
            $this->db->where('id', $v->id)->update($this->vid, [
                'verified_by'    => (int) $staffId,
                'verified_at'    => $now,
                'review_notes'   => $notes,
                'checklist_json' => json_encode($checklist),
            ]);
        }
        return true;
    }

    /* --------------------------------------------------------- notifications */

    public function logNotification($requestId, $channel, $recipient, $status, $providerId = null, $error = null)
    {
        $this->db->insert($this->ntf, [
            'request_id'          => (int) $requestId,
            'channel'             => $channel,
            'recipient'           => $recipient ? substr($recipient, 0, 191) : null,
            'status'              => $status,
            'provider_message_id' => $providerId ? substr($providerId, 0, 80) : null,
            'error'               => $error ? substr($error, 0, 255) : null,
            'sent_at'             => $status === 'sent' ? date('Y-m-d H:i:s') : null,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);
    }

    public function notificationsFor($requestId)
    {
        return $this->db->where('request_id', (int) $requestId)->order_by('id', 'DESC')->get($this->ntf)->result();
    }

    /* ------------------------------------------------------- dashboard/lists */

    public function stats()
    {
        $this->expireOverdue();
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->select('status, COUNT(*) c', false)->group_by('status')->get($this->req)->result() as $r) {
            $counts[$r->status] = (int) $r->c;
        }
        return [
            'total'            => array_sum($counts),
            'pending_approval' => $counts['submitted'],
            'approved'         => $counts['approved'],
            'rejected'         => $counts['rejected'],
            'awaiting_customer' => $counts['pending'] + $counts['in_progress'],
            'expired'          => $counts['expired'],
            'by_status'        => $counts,
        ];
    }

    /** @return array [rows, total] */
    public function listRequests($status, $q, $page, $perPage)
    {
        $this->expireOverdue();

        $apply = function () use ($status, $q) {
            if ($status && in_array($status, self::STATUSES, true)) {
                $this->db->where('status', $status);
            }
            if ($q !== '') {
                $this->db->group_start()->like('customer_name', $q)->or_like('customer_email', $q)
                    ->or_like('customer_phone', $q)->group_end();
            }
        };

        $apply();
        $total = $this->db->count_all_results($this->req);

        $apply();
        $rows = $this->db->select('id, rel_type, rel_id, customer_name, customer_email, customer_phone, script_language, status, created_at, expires_at, submitted_at, send_count')
            ->order_by('id', 'DESC')->limit($perPage, max(0, ($page - 1) * $perPage))
            ->get($this->req)->result();

        // Channel status per row: latest attempt per channel.
        $byReq = [];
        if ($rows) {
            $ids = array_map(function ($r) { return (int) $r->id; }, $rows);
            foreach ($this->db->select('request_id, channel, status')->where_in('request_id', $ids)
                ->order_by('id', 'ASC')->get($this->ntf)->result() as $n) {
                $byReq[$n->request_id][$n->channel] = $n->status;   // later rows overwrite earlier ones
            }
        }
        foreach ($rows as $r) {
            $r->channels = isset($byReq[$r->id]) ? $byReq[$r->id] : [];
        }
        return [$rows, $total];
    }
}
