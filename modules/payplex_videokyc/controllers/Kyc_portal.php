<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_kyc_notifier.php';
require_once __DIR__ . '/../libraries/Payplex_kyc_scripts.php';

/**
 * Customer side of Video KYC, INSIDE the logged-in customer portal:
 *
 *   GET  /clients/video-kyc          the customer's own two-step status
 *   POST /clients/video-kyc/upload   Step 1: upload an identity document
 *   POST /clients/video-kyc/start    Step 2: open the recording page
 *
 * Every action works on get_client_user_id() only — no customer id is ever
 * read from the request, so a customer cannot touch anyone else's KYC. The
 * recording itself still goes through the token flow in Kyc_public.
 */
class Kyc_portal extends ClientsController
{
    const DOC_MIME = [
        'application/pdf' => ['application/pdf', 'pdf'],
        'image/jpeg'      => ['image/jpeg', 'jpg'],
        'image/png'       => ['image/png', 'png'],
    ];
    const DOC_MAX_BYTES = 5242880;   // 5 MB

    public function __construct()
    {
        parent::__construct();
        if (!is_client_logged_in()) {
            redirect_after_login_to_current_url();
            redirect(site_url('authentication/login'));
        }
        $this->load->model('payplex_videokyc/videokyc_model', 'kyc');
    }

    private function back($type, $msg)
    {
        set_alert($type, $msg);
        redirect(site_url('clients/video-kyc'));
    }

    public function index()
    {
        $cid = (int) get_client_user_id();

        $this->data([
            'kyc_customer' => $this->kyc->subject('customer', $cid),
            'kyc_state'    => $this->kyc->flowState($cid),
            'kyc_docs'     => $this->kyc->documentsFor($cid),
        ]);
        $this->title('Video KYC');
        $this->view('payplex_videokyc/portal');
        $this->layout();
    }

    /** POST — Step 1. Validation is by file CONTENT, never by name or browser-reported type. */
    public function upload()
    {
        if ($this->input->method(true) !== 'POST') {
            redirect(site_url('clients/video-kyc'));
        }
        $cid  = (int) get_client_user_id();
        $type = (string) $this->input->post('doc_type');
        if (!isset(Videokyc_model::DOC_TYPES[$type])) {
            $this->back('danger', 'Choose the document type.');
        }

        $f = isset($_FILES['document']) ? $_FILES['document'] : null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $this->back('danger', 'No file was received, or it is too large (max 5 MB).');
        }
        if ($f['size'] <= 0 || $f['size'] > self::DOC_MAX_BYTES) {
            $this->back('danger', 'The file must be between 1 byte and 5 MB.');
        }
        $fi       = finfo_open(FILEINFO_MIME_TYPE);
        $detected = strtolower((string) finfo_file($fi, $f['tmp_name']));
        finfo_close($fi);
        if (!isset(self::DOC_MIME[$detected])) {
            $this->back('danger', 'Only PDF, JPG or PNG files are accepted.');
        }
        list($servedMime, $ext) = self::DOC_MIME[$detected];

        $rel  = 'documents/' . date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $root = FCPATH . 'uploads/payplex_videokyc/';
        $dir  = $root . dirname($rel);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            log_message('error', 'Video KYC portal: cannot create documents directory');
            $this->back('danger', 'The file could not be stored.');
        }
        $dest = $root . $rel;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            log_message('error', 'Video KYC portal: move_uploaded_file failed');
            $this->back('danger', 'The file could not be stored.');
        }
        @chmod($dest, 0640);
        $sha = hash_file('sha256', $dest);

        try {
            $id = $this->kyc->addDocument([
                'customer_id'   => $cid,
                'doc_type'      => $type,
                'storage_path'  => $rel,
                'mime_type'     => $servedMime,
                'file_size'     => filesize($dest),
                'sha256'        => $sha,
                'upload_reason' => 'Uploaded by the customer in the customer portal',
                'uploaded_by'   => 0,   // 0 = the customer themself, not a staff member
                'uploaded_ip'   => $this->input->ip_address(),
                'user_agent'    => substr((string) $this->input->user_agent(), 0, 255),
            ]);
        } catch (\Throwable $e) {
            @unlink($dest);
            log_message('error', 'Video KYC portal: addDocument failed: ' . $e->getMessage());
            $this->back('danger', 'The document could not be saved.');
        }
        log_activity('Video KYC identity document uploaded by customer #' . $cid . ' [document #' . $id
            . ', type ' . $type . ', sha256 ' . $sha . ']');

        $this->back('success', 'Document received. You can now start your Video KYC.');
    }

    /** POST — Step 2. Same rules as staff "Start Video KYC": step 1 must be done first. */
    public function start()
    {
        if ($this->input->method(true) !== 'POST') {
            redirect(site_url('clients/video-kyc'));
        }
        $cid     = (int) get_client_user_id();
        $subject = $this->kyc->subject('customer', $cid);
        if (!$subject) {
            $this->back('danger', 'Your account could not be found.');
        }
        // No document is needed first: the video and the documents are independent.
        $state = $this->kyc->flowState($cid);
        if ($state['step2'] === 'completed') {
            $this->back('info', 'Your Video KYC is already completed.');
        }
        if ($state['step2'] === 'in_progress' && !$state['resumable']) {
            $this->back('info', 'Your video has been submitted and is awaiting review.');
        }

        $hours   = (int) get_option('payplex_videokyc_link_ttl_hours') ?: 48;
        $expires = date('Y-m-d H:i:s', time() + $hours * 3600);

        if ($state['resumable']) {
            $id  = (int) $state['request_id'];
            $raw = $this->kyc->reissue($id, $expires);
            if ($raw === false) {
                $this->back('danger', 'This request cannot be reopened. Please contact your relationship manager.');
            }
        } else {
            $tpl = $this->kyc->defaultTemplate();
            if (!$tpl || !$tpl->active) {
                $this->back('danger', 'Video KYC is not available right now. Please contact your relationship manager.');
            }
            $lang = Payplex_kyc_scripts::DEFAULT_LANG;
            list($raw, $hash) = $this->kyc->newToken();
            $id = $this->kyc->createRequest([
                'token_hash'      => $hash,
                'rel_type'        => 'customer',
                'rel_id'          => $cid,
                'customer_name'   => $subject['name'],
                'customer_email'  => $subject['email'],
                'customer_phone'  => $subject['phone'],
                'template_id'     => $tpl->id,
                'dynamic_script'  => Videokyc_model::renderScript($tpl, $subject['name'], $lang),
                'script_language' => $lang,
                'expires_at'      => $expires,
                'created_by'      => 0,   // started by the customer
            ]);
        }
        log_activity('Video KYC started by customer #' . $cid . ' in the portal [request #' . $id . ']');
        redirect(Payplex_kyc_notifier::buildLink($raw));
    }
}
