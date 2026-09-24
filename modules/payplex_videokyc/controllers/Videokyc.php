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
                'generate' => $this->can('generate'),
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
        $this->need('view');
        $per  = min(100, max(5, (int) $this->input->get('per_page') ?: 10));
        $page = max(1, (int) $this->input->get('page'));
        list($rows, $total) = $this->kyc->listRequests(
            (string) $this->input->get('status'),
            trim((string) $this->input->get('q')),
            $page,
            $per
        );
        $this->json(['success' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $per]);
    }

    /** GET /admin/video-kyc/subjects?type=lead|customer&q= — picker for the generate form. */
    public function subjects()
    {
        $this->need('generate');
        $type = $this->input->get('type') === 'customer' ? 'customer' : 'lead';
        $this->json(['success' => true, 'items' => $this->kyc->searchSubjects($type, (string) $this->input->get('q'))]);
    }

    /** GET /admin/video-kyc/detail/<id> — everything the review modal needs. */
    public function detail($id = 0)
    {
        $this->need('view');
        $r = $this->kyc->get((int) $id);
        if (!$r) {
            return $this->json(['success' => false, 'message' => 'Request not found.'], 404);
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
                'url' => $this->can('video_access') ? admin_url('video-kyc/video/' . $v->id) : null,
            ];
        }
        $this->json(['success' => true, 'request' => $out]);
    }

    /**
     * POST /admin/video-kyc/generate_link
     * customer_type, customer_id, template_id, expires_hours, channels[]
     *
     * Name/email/phone are re-read from the CRM here — nothing identity-related
     * is trusted from the browser.
     */
    public function generate_link()
    {
        $this->postOnly();
        $this->need('generate');

        $type    = $this->input->post('customer_type') === 'customer' ? 'customer' : 'lead';
        $subject = $this->kyc->subject($type, (int) $this->input->post('customer_id'));
        if (!$subject) {
            return $this->json(['success' => false, 'message' => 'Select a valid customer or lead.'], 422);
        }

        $channels = array_values(array_intersect(Payplex_kyc_notifier::CHANNELS, (array) $this->input->post('channels')));
        if (!$channels) {
            return $this->json(['success' => false, 'message' => 'Choose at least one delivery channel.'], 422);
        }

        $hours = (int) $this->input->post('expires_hours');
        if ($hours < 1 || $hours > 24 * 14) {
            return $this->json(['success' => false, 'message' => 'Expiry must be between 1 hour and 14 days.'], 422);
        }

        $lang = $this->input->post('script_language');
        $lang = ($lang === null || $lang === '') ? Payplex_kyc_scripts::DEFAULT_LANG : $lang;
        if (!Payplex_kyc_scripts::isValid($lang)) {
            return $this->json(['success' => false, 'message' => 'Choose a supported script language (English, Hindi or Marathi).'], 422);
        }

        $tpl = (int) $this->input->post('template_id') ? $this->kyc->template((int) $this->input->post('template_id')) : $this->kyc->defaultTemplate();
        if (!$tpl || !$tpl->active) {
            return $this->json(['success' => false, 'message' => 'Select an active script template.'], 422);
        }

        // Guard against double-clicks / duplicate links to the same subject.
        $open = $this->db->where('rel_type', $type)->where('rel_id', $subject['id'])
            ->where_in('status', ['pending', 'in_progress', 'submitted'])->where('expires_at >=', date('Y-m-d H:i:s'))
            ->count_all_results(db_prefix() . 'payplex_vkyc_requests');
        if ($open > 0 && !$this->input->post('force')) {
            return $this->json(['success' => false, 'code' => 'open_request_exists',
                'message' => 'This person already has an open KYC request. Use Resend on it, or confirm to create another.'], 409);
        }

        list($raw, $hash) = $this->kyc->newToken();
        $id = $this->kyc->createRequest([
            'token_hash'     => $hash,
            'rel_type'       => $type,
            'rel_id'         => $subject['id'],
            'customer_name'  => $subject['name'],
            'customer_email' => $subject['email'],
            'customer_phone' => $subject['phone'],
            'template_id'    => $tpl->id,
            'dynamic_script' => Videokyc_model::renderScript($tpl, $subject['name'], $lang),
            'script_language' => $lang,
            'expires_at'     => date('Y-m-d H:i:s', time() + $hours * 3600),
            'created_by'     => get_staff_user_id(),
        ]);

        $req     = $this->kyc->get($id);
        $notify  = new Payplex_kyc_notifier($this->kyc);
        $results = $notify->dispatch($req, $raw, $channels);

        log_activity('Video KYC link generated for ' . $type . ' #' . $subject['id'] . ' [request #' . $id . ']');

        $this->json([
            'success'  => true,
            'id'       => $id,
            'language' => $lang,
            'results'  => $results,
            'message'  => $this->summarise($results),
        ]);
    }

    /** POST /admin/video-kyc/resend/<id> — new token, new expiry, old link dies. */
    public function resend($id = 0)
    {
        $this->postOnly();
        $this->need('generate');

        $hours = (int) get_option('payplex_videokyc_link_ttl_hours') ?: 48;
        $raw   = $this->kyc->reissue((int) $id, date('Y-m-d H:i:s', time() + $hours * 3600));
        if ($raw === false) {
            return $this->json(['success' => false, 'message' => 'This request cannot be resent (missing or already approved).'], 409);
        }
        $req      = $this->kyc->get((int) $id);
        $channels = (array) $this->input->post('channels');
        $channels = $channels ? array_intersect(Payplex_kyc_notifier::CHANNELS, $channels) : Payplex_kyc_notifier::CHANNELS;

        $results = (new Payplex_kyc_notifier($this->kyc))->dispatch($req, $raw, $channels);
        log_activity('Video KYC link re-sent [request #' . (int) $id . ']');
        $this->json(['success' => true, 'results' => $results, 'message' => $this->summarise($results)]);
    }

    /** POST /admin/video-kyc/review — decision=approve|reject, notes, checklist[] */
    public function review()
    {
        $this->postOnly();
        $this->need('review');

        $id       = (int) $this->input->post('request_id');
        $decision = $this->input->post('decision');
        $notes    = trim((string) $this->input->post('notes'));
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return $this->json(['success' => false, 'message' => 'Invalid decision.'], 422);
        }
        if ($decision === 'reject' && $notes === '') {
            return $this->json(['success' => false, 'message' => 'A reason is required when rejecting.'], 422);
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
        log_activity('Video KYC ' . ($decision === 'approve' ? 'approved' : 'rejected') . ' [request #' . $id . ']');
        $this->json(['success' => true, 'status' => $decision === 'approve' ? 'approved' : 'rejected']);
    }

    /**
     * GET /admin/video-kyc/video/<videoId> — streams the recording with HTTP
     * Range support (needed for seeking). Files are never web-accessible directly.
     */
    public function video($videoId = 0)
    {
        $this->need('video_access');
        $v = $this->kyc->video((int) $videoId);
        if (!$v) {
            show_404();
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
