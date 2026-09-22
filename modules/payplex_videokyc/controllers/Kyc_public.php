<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_kyc_scripts.php';

/**
 * Customer side of Video KYC — NO login. Authority comes solely from the
 * unguessable link token (256-bit, only its SHA-256 is stored).
 *
 *   GET  /kyc/verify?token=…          the capture page (static shell)
 *   GET  /kyc/api/validate/<token>    token + status + expiry check, returns the script
 *   POST /kyc/api/upload              the recorded video
 *
 * The upload is protected by Perfex CSRF like any other public POST: the page
 * embeds the token and the JS sends it back. These routes are deliberately not
 * under /api/ (see my_routes.php) because that prefix is CSRF-exempt.
 */
class Kyc_public extends App_Controller
{
    /** detected content type → [served content type, file extension] */
    const MIME_MAP = [
        'video/webm'       => ['video/webm', 'webm'],
        'video/x-matroska' => ['video/webm', 'webm'],   // libmagic often reports MediaRecorder WebM as Matroska
        'video/mp4'        => ['video/mp4',  'mp4'],
        'video/quicktime'  => ['video/mp4',  'mp4'],    // Safari's MediaRecorder output
    ];

    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_videokyc/videokyc_model', 'kyc');
    }

    private function json(array $body, $status = 200)
    {
        $this->output->set_status_header($status)
            ->set_header('Cache-Control: no-store')
            ->set_header('X-Robots-Tag: noindex, nofollow')
            ->set_content_type('application/json')
            ->set_output(json_encode($body));
    }

    /* ------------------------------------------------------------------ page */

    public function index()
    {
        // The token is in the URL, so make sure it can't leak onward, be cached, or be indexed.
        $this->output
            ->set_header('Referrer-Policy: no-referrer')
            ->set_header('Cache-Control: no-store')
            ->set_header('X-Robots-Tag: noindex, nofollow')
            ->set_header('X-Frame-Options: DENY')
            ->set_header('Permissions-Policy: camera=(self), microphone=(self), geolocation=()');

        $this->load->view('payplex_videokyc/verify', [
            'company'    => (string) get_option('companyname'),
            'csrf_name'  => $this->security->get_csrf_token_name(),
            'csrf_hash'  => $this->security->get_csrf_hash(),
            'validate'   => site_url('kyc/api/validate') . '/',
            'upload'     => site_url('kyc/api/upload'),
            'asset_base' => module_dir_url(PAYPLEX_VIDEOKYC_MODULE, 'assets/'),
            'ui'         => Payplex_kyc_scripts::ui(),
        ]);
    }

    /* -------------------------------------------------------------- validate */

    public function validate($token = '')
    {
        if ($this->input->method(true) !== 'GET') {
            return $this->json(['valid' => false, 'reason' => 'method_not_allowed'], 405);
        }
        $req = $this->kyc->findByToken((string) $token);
        if (!$req) {
            return $this->json(['valid' => false, 'reason' => 'invalid'], 404);
        }

        $maxAttempts = max(1, (int) get_option('payplex_videokyc_max_attempts') ?: 3);
        switch ($req->status) {
            case 'expired':
                return $this->json(['valid' => false, 'reason' => 'expired'], 410);
            case 'submitted':
            case 'approved':
                return $this->json(['valid' => false, 'reason' => 'already_submitted'], 409);
            case 'rejected':
            case 'resubmit':
                return $this->json(['valid' => false, 'reason' => 'rejected'], 409);
        }
        if ((int) $req->upload_attempts >= $maxAttempts) {
            return $this->json(['valid' => false, 'reason' => 'attempts_exhausted'], 429);
        }

        $this->kyc->markOpened($req->id);

        $this->json([
            'valid'         => true,
            'customer_name' => $req->customer_name,
            'company'       => (string) get_option('companyname'),
            'language'      => Payplex_kyc_scripts::normalize($req->script_language),
            'script'        => $req->dynamic_script,
            'expires_at'    => $req->expires_at,
            'attempts_left' => $maxAttempts - (int) $req->upload_attempts,
            // Where "Back to home" goes: employees return to the staff area, customers to their portal.
            'home_url'      => $req->rel_type === 'staff' ? admin_url() : site_url('clients/video-kyc'),
            'limits'        => [
                'min_sec' => (int) get_option('payplex_videokyc_min_record_sec') ?: 5,
                'max_sec' => (int) get_option('payplex_videokyc_max_record_sec') ?: 90,
                'max_mb'  => $this->effectiveMaxMb(),
            ],
        ]);
    }

    /* ---------------------------------------------------------------- upload */

    public function upload()
    {
        if ($this->input->method(true) !== 'POST') {
            return $this->json(['success' => false, 'message' => 'POST required.'], 405);
        }

        $req = $this->kyc->findByToken((string) $this->input->post('token'));
        if (!$req) {
            return $this->json(['success' => false, 'reason' => 'invalid', 'message' => 'This link is not valid.'], 404);
        }
        if (!in_array($req->status, ['pending', 'in_progress'], true)) {
            return $this->json(['success' => false, 'reason' => $req->status === 'expired' ? 'expired' : 'already_submitted',
                'message' => $req->status === 'expired' ? 'This link has expired.' : 'A video has already been submitted for this link.'], 409);
        }

        $maxAttempts = max(1, (int) get_option('payplex_videokyc_max_attempts') ?: 3);
        if (!$this->kyc->claimUploadAttempt($req->id, $maxAttempts)) {
            return $this->json(['success' => false, 'reason' => 'attempts_exhausted',
                'message' => 'No upload attempts left for this link. Please contact the company for a new link.'], 429);
        }

        // From here on a rejected file gives the attempt back, so a customer's bad recording isn't a strike.
        // `code` lets the customer page show the message in the customer's own language.
        $fail = function ($status, $message, $code) use ($req) {
            $this->kyc->releaseUploadAttempt($req->id);
            return $this->json(['success' => false, 'code' => $code, 'message' => $message], $status);
        };

        $f = isset($_FILES['video']) ? $_FILES['video'] : null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $tooBig = $f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            return $fail($tooBig ? 413 : 400, $tooBig ? 'The video is too large. Please record a shorter clip.' : 'No video was received. Please try again.', $tooBig ? 'too_large' : 'no_file');
        }

        $maxBytes = $this->effectiveMaxMb() * 1048576;
        if ($f['size'] <= 0 || $f['size'] > $maxBytes) {
            return $fail(413, 'The video is too large. Please record a shorter clip.', 'too_large');
        }

        // Trust the file's CONTENT, not the browser-supplied type or extension.
        $fi       = finfo_open(FILEINFO_MIME_TYPE);
        $detected = strtolower((string) finfo_file($fi, $f['tmp_name']));
        finfo_close($fi);
        if (!isset(self::MIME_MAP[$detected]) || !$this->hasVideoSignature($f['tmp_name'], self::MIME_MAP[$detected][1])) {
            return $fail(415, 'That file is not a supported video. Please record again.', 'not_video');
        }
        list($servedMime, $ext) = self::MIME_MAP[$detected];

        // Private, randomly-named, date-bucketed. The name carries no personal data.
        $rel = date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $dir = FCPATH . 'uploads/payplex_videokyc/' . dirname($rel);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            log_message('error', 'Video KYC: cannot create storage directory');
            return $fail(500, 'We could not store your video. Please try again shortly.', 'store_failed');
        }
        $dest = FCPATH . 'uploads/payplex_videokyc/' . $rel;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            log_message('error', 'Video KYC: move_uploaded_file failed');
            return $fail(500, 'We could not store your video. Please try again shortly.', 'store_failed');
        }
        @chmod($dest, 0640);

        $maxSec   = (int) get_option('payplex_videokyc_max_record_sec') ?: 90;
        $duration = (int) $this->input->post('duration');
        $duration = ($duration >= 0 && $duration <= $maxSec + 5) ? $duration : null;   // client-reported; sanity only

        try {
            $this->kyc->addVideo([
                'request_id'   => $req->id,
                'storage_path' => $rel,
                'mime_type'    => $servedMime,
                'file_size'    => filesize($dest),
                'duration_sec' => $duration,
                'sha256'       => hash_file('sha256', $dest),
                'uploaded_ip'  => $this->input->ip_address(),
                'user_agent'   => substr((string) $this->input->user_agent(), 0, 255),
            ]);
        } catch (\Throwable $e) {
            @unlink($dest);
            log_message('error', 'Video KYC: addVideo failed: ' . $e->getMessage());
            return $fail(500, 'We could not save your submission. Please try again shortly.', 'store_failed');
        }

        $this->json(['success' => true, 'message' => 'Your video has been submitted. You can close this page.']);
    }

    /* ---------------------------------------------------------------- helpers */

    /** The smaller of the admin's limit and what PHP will actually accept. */
    private function effectiveMaxMb()
    {
        $cfg = (int) get_option('payplex_videokyc_max_upload_mb') ?: 30;
        $php = min($this->iniBytes('upload_max_filesize'), $this->iniBytes('post_max_size')) / 1048576;
        return max(1, (int) floor(min($cfg, $php)));
    }

    /** Container magic bytes: EBML header for WebM/Matroska, 'ftyp' box for MP4/MOV. */
    private function hasVideoSignature($path, $ext)
    {
        $h = @file_get_contents($path, false, null, 0, 12);
        if ($h === false || strlen($h) < 8) {
            return false;
        }
        return $ext === 'webm' ? strncmp($h, "\x1A\x45\xDF\xA3", 4) === 0 : substr($h, 4, 4) === 'ftyp';
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
        return $n ?: PHP_INT_MAX;
    }
}
