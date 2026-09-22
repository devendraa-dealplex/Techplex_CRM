<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_kyc_notifier.php';
require_once __DIR__ . '/../libraries/Payplex_kyc_scripts.php';

/**
 * Staff side of Video KYC. Routed as /admin/video-kyc/<action> (see
 * application/config/my_routes.php). AdminController already enforces login;
 * every action additionally checks its own capability. State-changing actions
 * are POST-only and go through Perfex CSRF.
 */
class Videokyc extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_videokyc/videokyc_model', 'kyc');
        // Admins and holders of `view_all` see everything; everyone else (sales) is
        // limited to their own customers, leads and requests.
        $this->kyc->scopeTo($this->can('view_all') ? null : get_staff_user_id());
        // Employee KYC has its own rules: admins/HR see all employees, managers their own reports.
        $this->kyc->scopeEmployees($this->can('employee_all'), get_staff_user_id());
    }

    /* ------------------------------------------------------------ helpers */

    private function can($cap)
    {
        return is_admin() || staff_can($cap, PAYPLEX_VIDEOKYC_MODULE);
    }

    private function need($cap)
    {
        if (!$this->can($cap)) {
            if ($this->input->is_ajax_request()) {
                $this->json(['success' => false, 'message' => 'You do not have permission to do that.'], 403);
                exit;
            }
            access_denied('payplex_videokyc');
        }
    }

    private function json(array $body, $status = 200)
    {
        $this->output->set_status_header($status)
            ->set_content_type('application/json')
            ->set_output(json_encode($body));
    }

    private function postOnly()
    {
        if ($this->input->method(true) !== 'POST') {
            $this->json(['success' => false, 'message' => 'POST required.'], 405);
            exit;
        }
    }

    private function viewData($title)
    {
        return [
            'title'  => $title,
            'can'    => [
                'generate' => false,   // customers complete KYC themselves; staff only review
                'review'   => $this->can('review'),
                'video'    => $this->can('video_access'),
                'settings' => $this->can('settings'),
            ],
            'urlBase' => admin_url('video-kyc'),
            // Shared with the browser so the live preview renders exactly like the server does.
            'languages' => Payplex_kyc_scripts::languages(),
            'lang_defaults' => Payplex_kyc_scripts::defaultBodies(),
            'lang_months' => Payplex_kyc_scripts::months(),
        ];
    }

    /* -------------------------------------------------------------- pages */

    public function index()
    {
        redirect(admin_url('video-kyc/dashboard'));
    }

    /**
     * Staff-side review console (all customers and leads). Deliberately NOT part of
     * the customer profile: the profile only shows that customer's two-step flow.
     */
    public function dashboard()
    {
        $this->need('view');
        $data              = $this->viewData('Video KYC — Dashboard');
        $data['templates'] = $this->kyc->templates(true);
        $data['ttl_hours'] = (int) get_option('payplex_videokyc_link_ttl_hours') ?: 48;
        $data['show']      = 'dashboard';
        $this->load->view('payplex_videokyc/dashboard', $data);
    }

    public function requests()
    {
        $this->need('view');
        $data              = $this->viewData('Video KYC — Requests / Logs');
        $data['templates'] = $this->kyc->templates(true);
        $data['ttl_hours'] = (int) get_option('payplex_videokyc_link_ttl_hours') ?: 48;
        $data['show']      = 'requests';
        $this->load->view('payplex_videokyc/dashboard', $data);
    }

    /* ------------------------------------------------- my own employee KYC */

    /**
     * GET /admin/video-kyc/my — a staff member's OWN Video KYC status, exactly
     * as a customer sees theirs on /clients/video-kyc. Open to every logged-in
     * staff member: no capability check, because checking your own status is
     * not reviewing anybody.
     */
    public function my()
    {
        $sid = (int) get_staff_user_id();
        $req = $this->kyc->myEmployeeRequest($sid);

        $reviewNote = '';
        if ($req && in_array($req->status, ['approved', 'rejected', 'resubmit'], true)) {
            $v = $this->kyc->latestVideo($req->id);
            $reviewNote = $v ? (string) $v->review_notes : '';
        }

        $this->load->view('payplex_videokyc/my_kyc', [
            'title'       => 'My Video KYC',
            'req'         => $req,
            'review_note' => $reviewNote,
            'can_continue'=> $req && in_array($req->status, ['pending', 'in_progress'], true)
                             && strtotime($req->expires_at) >= time(),
        ]);
    }

    /**
     * POST /admin/video-kyc/my_continue — reopen MY already-issued link. Never
     * creates a new request: employee KYC requests are issued by HR/a manager
     * (Employee KYC page), never self-started. This only re-delivers a link that
     * already exists, for whenever the employee wants it, instead of making them
     * wait on another email/SMS.
     */
    public function my_continue()
    {
        $this->postOnly();
        $sid  = (int) get_staff_user_id();
        $last = $this->kyc->myEmployeeRequest($sid);

        if (!$last || !in_array($last->status, ['pending', 'in_progress'], true)
            || strtotime($last->expires_at) < time()) {
            set_alert('warning', 'There is no active Video KYC link to continue. Ask HR or your manager to send you one.');
            redirect(admin_url('video-kyc/my'));
        }

        $hours   = (int) get_option('payplex_videokyc_link_ttl_hours') ?: 48;
        $expires = date('Y-m-d H:i:s', time() + $hours * 3600);
        $raw     = $this->kyc->reissueOwnRequest((int) $last->id, $sid, $expires);
        if ($raw === false) {
            set_alert('danger', 'This link cannot be reopened. Ask HR or your manager to send you a new one.');
            redirect(admin_url('video-kyc/my'));
        }

        log_activity('Video KYC continued by employee #' . $sid . ' [request #' . (int) $last->id . ']');
        redirect(Payplex_kyc_notifier::buildLink($raw));
    }

    /* ----------------------------------------------------- employee KYC */

    /**
     * Who gets the Employee KYC page: admins, HR (employee_all / employee_send) and
     * anyone with employees reporting to them (payplex_staff reporting manager).
     */
    private function canEmployeeArea()
    {
        return $this->can('employee_all') || $this->can('employee_send')
            || $this->kyc->managesAnyone(get_staff_user_id());
    }

    private function needEmployeeArea()
    {
        if (!$this->canEmployeeArea()) {
            if ($this->input->is_ajax_request()) {
                $this->json(['success' => false, 'message' => 'You do not have permission to do that.'], 403);
                exit;
            }
            access_denied('payplex_videokyc');
        }
    }

    /** GET /admin/video-kyc/employees — HR sends links; HR and managers review. */
    public function employees()
    {
        $this->needEmployeeArea();
        $data                = $this->viewData('Employee Video KYC');
        $data['can']['review'] = true;    // whoever sees an employee's request may decide it (rules are in the model)
        $data['can']['video']  = true;
        $data['can_send']    = $this->can('employee_send');
        $data['employees']   = $this->kyc->employeeRows();
        $data['ttl_hours']   = (int) get_option('payplex_videokyc_link_ttl_hours') ?: 48;
        $this->load->view('payplex_videokyc/employees', $data);
    }

    /**
     * POST /admin/video-kyc/employee_send   staff_ids[], channels[] (default: email)
     *
     * HR/admin sends an employee their Video KYC link. An employee with a live
     * pending link gets it re-issued (the old link dies); one already submitted or
     * approved is skipped. Identity (name, email, phone) is read from the staff
     * record here, never from the browser.
     */
    public function employee_send()
    {
        $this->postOnly();
        $this->need('employee_send');

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->input->post('staff_ids')))));
        if (!$ids || count($ids) > 300) {
            return $this->json(['success' => false, 'message' => 'Choose between 1 and 300 employees.'], 422);
        }
        $channels = array_values(array_intersect(Payplex_kyc_notifier::CHANNELS, (array) $this->input->post('channels')));
        $channels = $channels ?: ['email'];

        $tpl = $this->kyc->defaultTemplate();
        if (!$tpl || !$tpl->active) {
            return $this->json(['success' => false, 'message' => 'No active script template. Add one in Video KYC settings.'], 422);
        }
        $hours   = (int) get_option('payplex_videokyc_link_ttl_hours') ?: 48;
        $expires = date('Y-m-d H:i:s', time() + $hours * 3600);
        $lang    = Payplex_kyc_scripts::DEFAULT_LANG;
        $notify  = new Payplex_kyc_notifier($this->kyc);

        $sent = 0;
        $skip = [];
        foreach ($ids as $sid) {
            $r = $this->issueEmployeeLink($sid, $channels, $tpl, $expires, $lang, $notify);
            if ($r['ok']) {
                $sent++;
            } else {
                $skip[] = $r['reason'];
            }
        }

        $this->json(['success' => true, 'sent' => $sent, 'skipped' => $skip,
            'message' => $sent . ' link(s) sent' . ($skip ? ', ' . count($skip) . ' skipped' : '') . '.']);
    }

    /**
     * Issue (or reissue) and deliver ONE employee's Video KYC link. Shared by
     * employee_send() (HR/a manager choosing who to send to) and review() (an
     * automatic redo link the moment an employee's KYC is rejected or asked to
     * be resubmitted — an employee cannot self-start, so leaving them with
     * nothing to click would strand them with no way forward).
     *
     * @return array ['ok' => bool, 'name' => string|null, 'reason' => string|null]
     *               reason is the skip/failure message; set only when ok is false.
     */
    private function issueEmployeeLink($staffId, array $channels, $tpl, $expires, $lang, Payplex_kyc_notifier $notify)
    {
        $subject = $this->kyc->subject('staff', (int) $staffId);
        if (!$subject) {
            return ['ok' => false, 'name' => null, 'reason' => 'Employee #' . (int) $staffId . ': not found or not yours to manage'];
        }
        $last = $this->kyc->latestFor('staff', (int) $staffId);
        if ($last && $last->status === 'approved') {
            return ['ok' => false, 'name' => $subject['name'], 'reason' => $subject['name'] . ': already approved'];
        }
        if ($last && $last->status === 'submitted') {
            return ['ok' => false, 'name' => $subject['name'], 'reason' => $subject['name'] . ': video submitted, awaiting review'];
        }

        if ($last && in_array($last->status, ['pending', 'in_progress'], true) && strtotime($last->expires_at) >= time()) {
            $raw = $this->kyc->reissue((int) $last->id, $expires);   // fresh link; the old one stops working
            $rid = (int) $last->id;
        } else {
            list($raw, $hash) = $this->kyc->newToken();
            $rid = $this->kyc->createRequest([
                'token_hash'      => $hash,
                'rel_type'        => 'staff',
                'rel_id'          => $subject['id'],
                'customer_name'   => $subject['name'],
                'customer_email'  => $subject['email'],
                'customer_phone'  => $subject['phone'],
                'template_id'     => $tpl->id,
                'dynamic_script'  => Videokyc_model::renderScript($tpl, $subject['name'], $lang),
                'script_language' => $lang,
                'expires_at'      => $expires,
                'created_by'      => get_staff_user_id(),
            ]);
        }
        if ($raw === false || !$rid) {
            return ['ok' => false, 'name' => $subject['name'], 'reason' => $subject['name'] . ': could not issue a link'];
        }

        $req     = $this->kyc->get($rid);
        $results = $notify->dispatch($req, $raw, $channels);
        $ok      = false;
        foreach ($results as $r) {
            $ok = $ok || $r['status'] === 'sent';
        }
        log_activity('Employee Video KYC link issued [employee #' . (int) $staffId . ', request #' . $rid . ']');

        return $ok
            ? ['ok' => true, 'name' => $subject['name'], 'reason' => null]
            : ['ok' => false, 'name' => $subject['name'],
               'reason' => $subject['name'] . ': link created but not delivered (' . $this->summarise($results) . ')'];
    }

    /* --------------------------------------------------------------- JSON */

    /** GET /admin/video-kyc/stats — metric cards. */
    public function stats()
    {
        $this->need('view');
        $this->json(['success' => true, 'stats' => $this->kyc->stats()]);
    }

    /** GET /admin/video-kyc/list?status=&q=&page=&per_page= */
    public function list_requests()
    {
        $kind = $this->input->get('kind') === 'employee' ? 'employee' : 'customer';
        $kind === 'employee' ? $this->needEmployeeArea() : $this->need('view');
        $per  = min(100, max(5, (int) $this->input->get('per_page') ?: 10));
        $page = max(1, (int) $this->input->get('page'));
        list($rows, $total) = $this->kyc->listRequests(
            (string) $this->input->get('status'),
            trim((string) $this->input->get('q')),
            $page,
            $per,
            (int) $this->input->get('customer_id'),
            $kind
        );
        $this->json(['success' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per]);
    }

    /** GET /admin/video-kyc/detail/<id> — everything the review modal needs. */
    public function detail($id = 0)
    {
        // Out-of-scope requests (including other people's employee KYC) read as "not found".
        $r = $this->kyc->get((int) $id);
        if (!$r) {
            return $this->json(['success' => false, 'message' => 'Request not found.'], 404);
        }
        // Customer/lead requests need the Video KYC "view" capability; an employee's
        // request is governed by who they report to (already enforced by get()).
        if ($r->rel_type !== 'staff') {
            $this->need('view');
        }
        $v = $this->kyc->latestVideo($r->id);
        $out = [
            'id' => (int) $r->id, 'customer_name' => $r->customer_name, 'customer_email' => $r->customer_email,
            'customer_phone' => $r->customer_phone, 'rel_type' => $r->rel_type, 'rel_id' => (int) $r->rel_id,
            'status' => $r->status, 'dynamic_script' => $r->dynamic_script, 'script_language' => $r->script_language,
            'created_at' => $r->created_at,
            'expires_at' => $r->expires_at, 'submitted_at' => $r->submitted_at, 'reviewed_at' => $r->reviewed_at,
            'send_count' => (int) $r->send_count, 'notifications' => $this->kyc->notificationsFor($r->id),
            'video' => null,
        ];
        if ($v) {
            $reviewer = $v->verified_by ? get_staff_full_name($v->verified_by) : null;
            $out['video'] = [
                'id' => (int) $v->id, 'mime_type' => $v->mime_type, 'file_size' => (int) $v->file_size,
                'duration_sec' => $v->duration_sec !== null ? (int) $v->duration_sec : null,
                'sha256' => $v->sha256, 'uploaded_at' => $v->created_at, 'uploaded_ip' => $v->uploaded_ip,
                'user_agent' => $v->user_agent, 'verified_by' => $reviewer, 'verified_at' => $v->verified_at,
                'review_notes' => $v->review_notes, 'checklist' => $v->checklist_json ? json_decode($v->checklist_json, true) : null,
                'url' => ($r->rel_type === 'staff' || $this->can('video_access')) ? admin_url('video-kyc/video/' . $v->id) : null,
            ];
        }
        $this->json(['success' => true, 'request' => $out]);
    }


    /* ------------------------------------------------ identity documents (step 1) */
    /** GET /admin/video-kyc/flow_state/<customerId> — the two-step state as JSON. */
    public function flow_state($customerId = 0)
    {
        $this->need('view');
        if (!$this->kyc->subject('customer', (int) $customerId)) {
            return $this->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }
        $this->json(['success' => true, 'state' => $this->kyc->flowState((int) $customerId)]);
    }

    /** GET /admin/video-kyc/document/<id> — streams a document after re-verifying its hash. */
    public function document($id = 0)
    {
        $this->need('documents');
        $d = $this->kyc->document((int) $id);
        if (!$d || !$this->kyc->customerInScope($d->customer_id)) {
            show_404();
        }
        $base = realpath(FCPATH . 'uploads/payplex_videokyc');
        $path = realpath($base . DIRECTORY_SEPARATOR . $d->storage_path);
        if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            show_404();
        }
        if (!hash_equals((string) $d->sha256, hash_file('sha256', $path))) {
            log_activity('Video KYC document REFUSED, hash mismatch [document #' . (int) $d->id . ']');
            show_error('The stored file does not match its recorded hash, so it will not be served.', 409);
        }
        log_activity('Video KYC identity document viewed [document #' . (int) $d->id . ', customer #' . (int) $d->customer_id . ']');

        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: ' . $d->mime_type);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="document-' . (int) $d->id . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /** POST /admin/video-kyc/review — decision=approve|reject|resubmit, notes, checklist[] */
    public function review()
    {
        $this->postOnly();

        $id  = (int) $this->input->post('request_id');
        $req = $this->kyc->get($id);
        if (!$req) {
            return $this->json(['success' => false, 'message' => 'Request not found.'], 404);
        }
        // Customers/leads: the `review` capability. Employees: admins, HR and the
        // employee's reporting manager (never the employee themself); get() above
        // already refused anyone else.
        if ($req->rel_type !== 'staff') {
            $this->need('review');
        }
        $decision = $this->input->post('decision');
        $notes    = trim((string) $this->input->post('notes'));
        if (!in_array($decision, ['approve', 'reject', 'resubmit'], true)) {
            return $this->json(['success' => false, 'message' => 'Invalid decision.'], 422);
        }
        if ($decision !== 'approve' && $notes === '') {
            return $this->json(['success' => false, 'message' => 'Tell the customer why (a reason is required when rejecting or asking again).'], 422);
        }

        // Approving requires the reviewer to have actually completed the checklist.
        $allowed   = ['face_visible', 'script_read', 'audio_clear', 'single_person', 'live_person'];
        $checklist = [];
        foreach ($allowed as $k) {
            $checklist[$k] = (bool) $this->input->post('check_' . $k);
        }
        if ($decision === 'approve' && in_array(false, $checklist, true)) {
            return $this->json(['success' => false, 'message' => 'Tick every verification check before approving.'], 422);
        }

        if (!$this->kyc->review($id, $decision, get_staff_user_id(), mb_substr($notes, 0, 2000), $checklist)) {
            return $this->json(['success' => false, 'message' => 'This submission is no longer awaiting review (someone else may have decided it).'], 409);
        }
        $final = ['approve' => 'approved', 'reject' => 'rejected', 'resubmit' => 'resubmit'][$decision];
        log_activity('Video KYC ' . $final . ' [request #' . $id . ']');

        /*
         * Employees cannot self-start (the customer's own "record again" button
         * does not exist here — HR/the manager issues the link). So the moment an
         * employee's KYC is rejected or sent back for resubmission, a fresh link
         * is issued and delivered automatically, right here, rather than leaving
         * them stuck until someone remembers to go back to Employee KYC and
         * resend by hand. A failure here never fails the review decision itself.
         */
        $redo = null;
        if ($req->rel_type === 'staff' && in_array($decision, ['reject', 'resubmit'], true)) {
            $tpl = $this->kyc->defaultTemplate();
            if ($tpl && $tpl->active) {
                $hours   = (int) get_option('payplex_videokyc_link_ttl_hours') ?: 48;
                $expires = date('Y-m-d H:i:s', time() + $hours * 3600);
                $notify  = new Payplex_kyc_notifier($this->kyc);
                $redo    = $this->issueEmployeeLink((int) $req->rel_id, Payplex_kyc_notifier::CHANNELS,
                    $tpl, $expires, Payplex_kyc_scripts::DEFAULT_LANG, $notify);
            } else {
                $redo = ['ok' => false, 'reason' => 'No active script template, so a new link could not be sent automatically.'];
            }
        }

        $this->json(['success' => true, 'status' => $final, 'redo' => $redo]);
    }

    /**
     * GET /admin/video-kyc/video/<videoId> — streams the recording with HTTP
     * Range support (needed for seeking). Files are never web-accessible directly.
     */
    public function video($videoId = 0)
    {
        $v   = $this->kyc->video((int) $videoId);
        $req = $v ? $this->kyc->get($v->request_id) : null;
        if (!$v || !$req) {
            show_404();
        }
        if ($req->rel_type !== 'staff') {
            $this->need('video_access');   // employees' videos follow the reporting-line rule in get()
        }
        $base = realpath(FCPATH . 'uploads/payplex_videokyc');
        $path = realpath($base . DIRECTORY_SEPARATOR . $v->storage_path);
        // realpath + prefix check defeats any traversal even if a path were ever tampered with.
        if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            show_404();
        }
        log_activity('Video KYC recording viewed [video #' . (int) $v->id . ', request #' . (int) $v->request_id . ']');

        $size  = filesize($path);
        $start = 0;
        $end   = $size - 1;
        $code  = 200;
        if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] === '' && $m[2] !== '') {            // suffix range: last N bytes
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = min($end, (int) $m[2]);
                }
            }
            if ($start > $end || $start >= $size) {
                header('Content-Range: bytes */' . $size);
                http_response_code(416);
                exit;
            }
            $code = 206;
        }

        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code($code);
        header('Content-Type: ' . $v->mime_type);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . ($end - $start + 1));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if ($code === 206) {
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }
        $fh = fopen($path, 'rb');
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, min(8192 * 8, $left));
            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
        fclose($fh);
        exit;
    }

    /* ----------------------------------------------------- settings/templates */

    public function settings()
    {
        $this->need('settings');

        if ($this->input->method(true) === 'POST') {
            $this->saveSettings();
            set_alert('success', 'Video KYC settings saved.');
            redirect(admin_url('video-kyc/settings'));
        }

        $data              = $this->viewData('Video KYC — Settings & Templates');
        $data['templates'] = $this->kyc->templates(false);
        $data['php_limit_mb'] = min($this->iniBytes('upload_max_filesize'), $this->iniBytes('post_max_size')) / 1048576;
        $data['secrets']   = [
            'twilio_token' => Payplex_kyc_notifier::secretUsable('payplex_videokyc_twilio_token'),
            'meta_token'   => Payplex_kyc_notifier::secretUsable('payplex_videokyc_meta_token'),
        ];
        $this->load->view('payplex_videokyc/settings', $data);
    }

    private function saveSettings()
    {
        $int = function ($key, $min, $max, $default) {
            $v = (int) $this->input->post($key);
            return (string) (($v >= $min && $v <= $max) ? $v : $default);
        };
        update_option('payplex_videokyc_link_ttl_hours', $int('link_ttl_hours', 1, 24 * 14, 48));
        update_option('payplex_videokyc_max_attempts', $int('max_attempts', 1, 10, 3));
        update_option('payplex_videokyc_max_upload_mb', $int('max_upload_mb', 1, 200, 30));
        update_option('payplex_videokyc_max_record_sec', $int('max_record_sec', 10, 300, 90));
        update_option('payplex_videokyc_min_record_sec', $int('min_record_sec', 1, 60, 5));

        $plain = ['twilio_sid', 'twilio_sms_from', 'twilio_messaging_service', 'twilio_wa_from', 'twilio_wa_content_sid',
                  'twilio_wa_content_sid_hi', 'twilio_wa_content_sid_mr',
                  'meta_phone_id', 'meta_template', 'meta_template_lang', 'meta_template_hi', 'meta_template_mr', 'msg_sms'];
        foreach ($plain as $k) {
            update_option('payplex_videokyc_' . $k, trim((string) $this->input->post($k)));
        }
        update_option('payplex_videokyc_whatsapp_provider', $this->input->post('whatsapp_provider') === 'meta' ? 'meta' : 'twilio');

        // Secrets: blank means "keep what is stored" so re-saving never wipes a working credential.
        foreach (['twilio_token', 'meta_token'] as $k) {
            $val = trim((string) $this->input->post($k));
            if ($val !== '') {
                Payplex_kyc_notifier::saveSecret('payplex_videokyc_' . $k, $val);
            }
        }
    }

    /** POST /admin/video-kyc/template_save */
    public function template_save()
    {
        $this->postOnly();
        $this->need('settings');
        $name = trim((string) $this->input->post('name'));
        $body = trim((string) $this->input->post('body'));
        $hi   = trim((string) $this->input->post('body_hi'));
        $mr   = trim((string) $this->input->post('body_mr'));
        if ($name === '' || mb_strlen($body) < 20) {
            return $this->json(['success' => false, 'message' => 'Give the template a name and an English script of at least 20 characters.'], 422);
        }
        // Hindi / Marathi are optional (blank = the standard statement in that language is used).
        foreach (['English' => $body, 'Hindi' => $hi, 'Marathi' => $mr] as $label => $txt) {
            if (mb_strlen($txt) > 1000) {
                return $this->json(['success' => false, 'message' => "Keep the {$label} script under 1000 characters — it has to be read aloud."], 422);
            }
            if ($txt !== '' && mb_strlen($txt) < 20) {
                return $this->json(['success' => false, 'message' => "The {$label} script is too short (minimum 20 characters), or leave it blank."], 422);
            }
        }
        $id = $this->kyc->saveTemplate(
            (int) $this->input->post('id'), mb_substr($name, 0, 120), $body, $hi, $mr,
            (bool) $this->input->post('active'), (bool) $this->input->post('is_default'), get_staff_user_id()
        );
        $this->json(['success' => true, 'id' => $id]);
    }

    /** POST /admin/video-kyc/template_delete/<id> */
    public function template_delete($id = 0)
    {
        $this->postOnly();
        $this->need('settings');
        $this->json(['success' => true, 'result' => $this->kyc->deleteTemplate((int) $id)]);
    }

    /* ------------------------------------------------------------- internals */

    private function summarise(array $results)
    {
        $parts = [];
        foreach ($results as $ch => $r) {
            $parts[] = ucfirst($ch) . ': ' . $r['status'] . ($r['status'] !== 'sent' && $r['error'] ? ' (' . $r['error'] . ')' : '');
        }
        return implode(' · ', $parts);
    }

    private function iniBytes($key)
    {
        $v = trim((string) ini_get($key));
        $n = (float) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;   // no break
            case 'm': $n *= 1024;   // no break
            case 'k': $n *= 1024;
        }
        return $n;
    }
}
