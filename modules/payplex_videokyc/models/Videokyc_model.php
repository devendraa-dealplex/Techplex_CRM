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
    const STATUSES = ['pending', 'in_progress', 'submitted', 'approved', 'rejected', 'resubmit', 'expired'];

    private $req;
    private $vid;
    private $ntf;
    private $tpl;
    private $doc;

    public function __construct()
    {
        parent::__construct();
        $p         = db_prefix();
        $this->req = $p . 'payplex_vkyc_requests';
        $this->vid = $p . 'payplex_vkyc_videos';
        $this->ntf = $p . 'payplex_vkyc_notifications';
        $this->tpl = $p . 'payplex_vkyc_templates';
        $this->doc = $p . 'payplex_vkyc_documents';
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

    /* ----------------------------------------------------------------- scope */

    /**
     * When set to a staff id, every read/list/lookup below is limited to that staff
     * member's own customers (customer_admins) and leads (assigned / added by),
     * plus requests they created. null = unrestricted (admins, `view_all`, and the
     * public customer page, whose authority is the link token instead).
     */
    public $scopeStaff = null;

    public function scopeTo($staffId)
    {
        $this->scopeStaff = $staffId === null ? null : (int) $staffId;
        return $this;
    }

    /**
     * Employee KYC (rel_type = 'staff') follows different rules from customers:
     *   - $actorId is the logged-in staff member; nobody ever reviews their own KYC.
     *   - $employeeAll (admins, HR): every employee except themself.
     *   - otherwise: only employees who report to $actorId (payplex_staff_profiles).
     * With no actor (the public link, the customer portal) employee data is unreachable.
     */
    public $employeeAll = false;
    public $actorId     = null;

    public function scopeEmployees($all, $actorId)
    {
        $this->employeeAll = (bool) $all;
        $this->actorId     = $actorId === null ? null : (int) $actorId;
        return $this;
    }

    /** SELECT of the staff ids that report to a manager, or a never-true one if that module is absent. */
    private function reportsSql($managerId)
    {
        $p = db_prefix();
        if (!$this->db->table_exists($p . 'payplex_staff_profiles')) {
            return 'SELECT 0 WHERE 1 = 0';
        }
        return "SELECT staff_id FROM {$p}payplex_staff_profiles WHERE is_current = 1 AND reporting_manager_id = " . (int) $managerId;
    }

    /** Is this staff member someone the actor may see and review? */
    public function employeeAccess($staffId)
    {
        if ($this->actorId === null || (int) $staffId === $this->actorId) {
            return false;
        }
        if ($this->employeeAll) {
            return true;
        }
        if (!$this->db->table_exists(db_prefix() . 'payplex_staff_profiles')) {
            return false;
        }
        $rows = $this->db->query($this->reportsSql($this->actorId) . ' AND staff_id = ' . (int) $staffId)->num_rows();
        return $rows > 0;
    }

    /** Does the actor manage anybody? Decides whether they get the Employee KYC page. */
    public function managesAnyone($staffId)
    {
        return $this->db->table_exists(db_prefix() . 'payplex_staff_profiles')
            && $this->db->query($this->reportsSql($staffId) . ' LIMIT 1')->num_rows() > 0;
    }

    /**
     * SQL predicate restricting a requests row (columns rel_type, rel_id, created_by)
     * to what the actor may see. $kind 'customer' = leads and customers, 'employee' = staff.
     */
    private function requestScopeSql($kind = 'customer')
    {
        if ($kind === 'employee') {
            if ($this->actorId === null) {
                return '(1 = 0)';
            }
            $a = (int) $this->actorId;
            if ($this->employeeAll) {
                return "(rel_type = 'staff' AND rel_id <> {$a})";
            }
            return "(rel_type = 'staff' AND rel_id <> {$a} AND rel_id IN (" . $this->reportsSql($a) . '))';
        }

        $types = "rel_type IN ('lead','customer')";
        if ($this->scopeStaff === null) {
            return "({$types})";
        }
        $s = (int) $this->scopeStaff;
        $p = db_prefix();
        return "({$types} AND (created_by = {$s}"
            . " OR (rel_type = 'customer' AND rel_id IN (SELECT customer_id FROM {$p}customer_admins WHERE staff_id = {$s}))"
            . " OR (rel_type = 'lead' AND rel_id IN (SELECT id FROM {$p}leads WHERE assigned = {$s} OR addedfrom = {$s}))))";
    }

    /** May the scoped staff member touch this customer? */
    public function customerInScope($customerId)
    {
        if ($this->scopeStaff === null) {
            return true;
        }
        return $this->db->where('customer_id', (int) $customerId)->where('staff_id', $this->scopeStaff)
            ->count_all_results(db_prefix() . 'customer_admins') > 0;
    }

    /** May the scoped staff member touch this request row? */
    public function requestInScope($r)
    {
        if ($r->rel_type === 'staff') {
            return $this->employeeAccess($r->rel_id);   // employee KYC has its own rules
        }
        if ($this->scopeStaff === null) {
            return true;
        }
        if ((int) $r->created_by === $this->scopeStaff) {
            return true;
        }
        if ($r->rel_type === 'customer') {
            return $this->customerInScope($r->rel_id);
        }
        return $this->db->where('id', (int) $r->rel_id)
            ->group_start()->where('assigned', $this->scopeStaff)->or_where('addedfrom', $this->scopeStaff)->group_end()
            ->count_all_results(db_prefix() . 'leads') > 0;
    }

    /* -------------------------------------------------------------- subjects */

    /** Search leads or customers by name/email/phone for the "Generate link" picker. */
    public function searchSubjects($type, $q, $limit = 15)
    {
        $q   = trim((string) $q);
        $out = [];
        if ($type === 'lead') {
            $this->db->select('id, name, email, phonenumber AS phone')->from(db_prefix() . 'leads');
            if ($this->scopeStaff !== null) {
                $this->db->group_start()->where('assigned', $this->scopeStaff)->or_where('addedfrom', $this->scopeStaff)->group_end();
            }
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
            if ($this->scopeStaff !== null) {
                $this->db->where('c.userid IN (SELECT customer_id FROM ' . db_prefix() . 'customer_admins WHERE staff_id = ' . (int) $this->scopeStaff . ')', null, false);
            }
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
        if ($type === 'staff') {
            // An employee: only ones the actor may see (never themself).
            if (!$this->employeeAccess($id)) {
                return null;
            }
            $r = $this->db->select("staffid AS id, TRIM(CONCAT(firstname, ' ', lastname)) AS name, email, phonenumber AS phone", false)
                ->where('staffid', $id)->where('active', 1)->where('is_not_staff', 0)
                ->get(db_prefix() . 'staff')->row();
        } elseif ($type === 'lead') {
            $this->db->select('id, name, email, phonenumber AS phone')->where('id', $id);
            if ($this->scopeStaff !== null) {
                $this->db->group_start()->where('assigned', $this->scopeStaff)->or_where('addedfrom', $this->scopeStaff)->group_end();
            }
            $r = $this->db->get(db_prefix() . 'leads')->row();
        } else {
            if (!$this->customerInScope($id)) {
                return null;
            }
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
        $r = $this->db->where('id', (int) $id)->get($this->req)->row();
        // Out-of-scope requests read as "not found" so ids cannot be probed.
        return ($r && $this->requestInScope($r)) ? $r : null;
    }

    /**
     * Unscoped fetch by id, for system-internal callers that are not acting as
     * any particular staff member — e.g. the hook that sends a brand-new
     * employee their KYC link automatically on account creation. The employee
     * scoping exists to stop one PERSON reviewing another's KYC; it has nothing
     * to say about the system issuing a link on its own, and calling the scoped
     * get() here would refuse every employee (no actor is ever "logged in").
     */
    public function getUnscoped($id)
    {
        return $this->db->where('id', (int) $id)->get($this->req)->row();
    }

    /* ------------------------------------------------ identity documents (step 1) */

    const DOC_TYPES = [
        'pan'             => 'PAN card',
        'aadhaar'         => 'Aadhaar',
        'passport'        => 'Passport',
        'voter_id'        => 'Voter ID',
        'driving_licence' => 'Driving licence',
        'other'           => 'Other identity document',
    ];

    public function addDocument(array $d)
    {
        $this->db->insert($this->doc, [
            'customer_id'   => (int) $d['customer_id'],
            'doc_type'      => $d['doc_type'],
            'storage_path'  => $d['storage_path'],
            'mime_type'     => $d['mime_type'],
            'file_size'     => (int) $d['file_size'],
            'sha256'        => $d['sha256'],
            'upload_reason' => $d['upload_reason'],
            'uploaded_by'   => (int) $d['uploaded_by'],
            'uploaded_ip'   => $d['uploaded_ip'],
            'user_agent'    => $d['user_agent'],
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->insert_id();
    }

    public function document($id)
    {
        return $this->db->where('id', (int) $id)->get($this->doc)->row();
    }

    /** @return array of document rows, newest first */
    public function documentsFor($customerId)
    {
        return $this->db->where('customer_id', (int) $customerId)->order_by('id', 'DESC')->get($this->doc)->result();
    }

    public function hasDocuments($customerId)
    {
        return $this->db->where('customer_id', (int) $customerId)->count_all_results($this->doc) > 0;
    }

    /**
     * The two-step state for one customer. Single source of truth: the view, the
     * upload response and the generate_link gate all read this.
     *
     *   step1: 'pending' | 'completed'
     *   step2: 'locked' | 'ready' | 'in_progress' | 'completed'
     *
     * step2 comes from the customer's most recent non-expired Video KYC request:
     * pending/in_progress/submitted = in progress, approved = completed; a
     * rejected or expired latest request puts them back to ready (link again).
     */
    public function flowState($customerId)
    {
        $docs   = $this->documentsFor($customerId);
        $step1  = $docs ? 'completed' : 'pending';
        $latest = $this->db->where('rel_type', 'customer')->where('rel_id', (int) $customerId)
            ->order_by('id', 'DESC')->limit(1)->get($this->req)->row();

        // The two steps are independent: the video can be recorded whenever the customer
        // wants, with or without a document on file.
        $step2 = 'ready';
        if ($latest) {
            if ($latest->status === 'approved') {
                $step2 = 'completed';
            } elseif (in_array($latest->status, ['pending', 'in_progress', 'submitted'], true)
                      && strtotime($latest->expires_at) >= time()) {
                $step2 = 'in_progress';
            }
        }
        // A pending / in-progress link that has not been used yet can be reopened
        // ("Continue Video KYC"); one already submitted for review cannot.
        $resumable = $step2 === 'in_progress' && $latest && in_array($latest->status, ['pending', 'in_progress'], true);

        return [
            'resumable'  => $resumable,
            'step1'      => $step1,
            'step2'      => $step2,
            'doc_count'  => count($docs),
            'request_id' => $latest ? (int) $latest->id : null,
            'request_status' => $latest ? $latest->status : null,
            // What the reviewer told the customer (approve / reject / ask again), for the portal.
            'review_note'    => ($latest && in_array($latest->status, ['approved', 'rejected', 'resubmit'], true) && ($vv = $this->latestVideo($latest->id))) ? (string) $vv->review_notes : '',
            'reviewed_at'    => $latest ? $latest->reviewed_at : null,
        ];
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
        return $this->reissueRow($r, $expiresAt);
    }

    /**
     * Reissue MY OWN employee-KYC request (self-service "Continue Video KYC").
     * Deliberately bypasses the scoped get()/employeeAccess(): that scoping
     * exists to stop someone reviewing or managing ANOTHER person's KYC, and it
     * refuses a staff member their own row for exactly that reason — which also
     * broke reissue() here, since reissue() reads the row through get(). This
     * is not review; it's the same act a customer's own "Continue" button
     * performs, so it checks OWNERSHIP directly instead.
     */
    public function reissueOwnRequest($requestId, $staffId, $expiresAt)
    {
        $r = $this->db->where('id', (int) $requestId)->where('rel_type', 'staff')
            ->where('rel_id', (int) $staffId)->get($this->req)->row();
        if (!$r || $r->status === 'approved') {
            return false;
        }
        return $this->reissueRow($r, $expiresAt);
    }

    private function reissueRow($r, $expiresAt)
    {
        list($raw, $hash) = $this->newToken();
        $this->db->where('id', (int) $r->id)->update($this->req, [
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
        $status = ['approve' => 'approved', 'reject' => 'rejected', 'resubmit' => 'resubmit'][$decision];
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

    public function stats($kind = 'customer')
    {
        $this->expireOverdue();
        $counts = array_fill_keys(self::STATUSES, 0);
        $this->db->where($this->requestScopeSql($kind), null, false);
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
    public function listRequests($status, $q, $page, $perPage, $customerId = 0, $kind = 'customer')
    {
        $this->expireOverdue();

        $scope = $this->requestScopeSql($kind);
        $apply = function () use ($status, $q, $scope, $customerId) {
            $this->db->where($scope, null, false);
            if ($customerId > 0) {
                $this->db->where('rel_type', 'customer')->where('rel_id', (int) $customerId);
            }
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

    /** The newest request for a subject (customer / lead / staff), or null. */
    public function latestFor($type, $id)
    {
        return $this->db->where('rel_type', $type)->where('rel_id', (int) $id)
            ->order_by('id', 'DESC')->limit(1)->get($this->req)->row();
    }

    /**
     * A staff member's own latest employee-KYC request. Deliberately UNSCOPED:
     * employeeAccess()/subject('staff', ...) refuse a staff member their own row
     * on purpose (nobody reviews their own KYC), but checking your own STATUS is
     * not reviewing — it is the same thing a customer does on their own portal
     * page, and needs no permission.
     */
    public function myEmployeeRequest($staffId)
    {
        return $this->latestFor('staff', (int) $staffId);
    }

    /**
     * Employees the actor may see, each with their latest employee-KYC request
     * (status NULL = no request yet). Admins/HR: every active employee but
     * themself; anyone else: only the employees who report to them.
     */
    public function employeeRows()
    {
        if ($this->actorId === null) {
            return [];
        }
        $p = db_prefix();
        $a = (int) $this->actorId;

        $where = "s.active = 1 AND s.is_not_staff = 0 AND s.staffid <> {$a}";
        if (!$this->employeeAll) {
            if (!$this->db->table_exists($p . 'payplex_staff_profiles')) {
                return [];
            }
            $where .= ' AND s.staffid IN (' . $this->reportsSql($a) . ')';
        }
        $this->expireOverdue();

        return $this->db->query(
            "SELECT s.staffid, TRIM(CONCAT(s.firstname, ' ', s.lastname)) AS name, s.email, s.phonenumber AS phone,
                    r.id AS request_id, r.status AS request_status, r.submitted_at, r.expires_at
               FROM {$p}staff s
          LEFT JOIN {$this->req} r
                 ON r.id = (SELECT MAX(x.id) FROM {$this->req} x WHERE x.rel_type = 'staff' AND x.rel_id = s.staffid)
              WHERE {$where}
           ORDER BY s.firstname, s.lastname"
        )->result();
    }

    /* ------------------------------------------------- mandatory-KYC lock */

    /**
     * Was this account created AFTER the lock's cutoff date? Only accounts
     * created from that point on are ever hard-locked to Dashboard + Video
     * KYC — someone who already had full access before this feature existed
     * never has it taken away. A missing cutoff (module not yet upgraded)
     * means nobody is new, i.e. nobody is locked.
     */
    private function isAfterLockCutoff($createdAt)
    {
        $cutoff = get_option('payplex_videokyc_lock_cutoff');
        if (!$cutoff || !$createdAt) {
            return false;
        }
        return strtotime((string) $createdAt) > strtotime((string) $cutoff);
    }

    /** Is this CONTACT (customer login) a new account, per the cutoff above? */
    public function isNewCustomerContact($contactId)
    {
        $c = $this->db->select('datecreated')->where('id', (int) $contactId)
            ->get(db_prefix() . 'contacts')->row();
        return $c ? $this->isAfterLockCutoff($c->datecreated) : false;
    }

    /** Is this STAFF member a new account, per the cutoff above? */
    public function isNewStaffMember($staffId)
    {
        $s = $this->db->select('datecreated')->where('staffid', (int) $staffId)
            ->get(db_prefix() . 'staff')->row();
        return $s ? $this->isAfterLockCutoff($s->datecreated) : false;
    }
}
