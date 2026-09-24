<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Contract_invite_token.php';

/**
 * Sign -- the module's other public, unauthenticated route.
 *
 * WHY A SEPARATE CONTROLLER, NOT A NEW ACTION ON Signing
 * ---------------------------------------------------------
 * `Signing` extends `AdminController`, so every action on it requires a
 * staff session. The person opening this link is a signer, not staff --
 * often someone with no CRM account at all. Same reasoning as
 * `Webhook extends App_Controller`: public routes get their own controller
 * rather than trying to carve an exception into an admin one.
 *
 * WHAT BEING PUBLIC DOES NOT MEAN
 * --------------------------------
 * The token in the URL is the only credential, and it is treated as one:
 * resolved through Contract_invite_token::verify() (constant-time hash
 * compare, single-use, expiring), never logged, never echoed back into the
 * page, and consumed the moment signing completes so a forwarded copy of
 * the link stops working.
 */
class Sign extends App_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_contract_verification/contract_verification_model', 'cv');
    }

    /**
     * GET  /sign/{token}         -- show the signing page
     * POST /sign/{token}         -- act on it (send_otp / verify_otp / submit)
     */
    public function index($rawToken = '')
    {
        $now = time();
        $r   = $this->cv->resolveSigningToken($rawToken, $now);

        if (empty($r['ok'])) {
            $this->load->view('payplex_contract_verification/sign_invalid', array(
                'title'  => 'Signing link',
                'reason' => (string) $r['reason'],
            ));

            return;
        }

        $signer   = $r['signer'];
        $contract = $r['contract'];
        $tokenId  = (int) $r['token_id'];

        if ($this->input->post()) {
            $this->handlePost($rawToken, $tokenId, $signer, $contract, $now);

            return;
        }

        $method = (string) (isset($signer['signature_method']) ? $signer['signature_method'] : 'virtual');

        $this->load->view('payplex_contract_verification/sign_public', array(
            'title'         => 'Sign: ' . (string) $contract['subject'],
            'signer'        => $signer,
            'contract'      => $contract,
            'method'        => $method,
            'needs_otp'     => in_array($method, array('email_otp', 'mobile_otp', 'aadhaar'), true),
            'otp_sent'      => !empty($signer['otp_hash']) && (int) $signer['otp_expires_at'] > $now,
            'otp_verified'  => (int) $signer['otp_verified_at'] > 0,
            'pdf_url'       => site_url('sign/' . rawurlencode($rawToken) . '/document'),
            'co_signers'    => $this->coSignersForView((int) $signer['request_id'], (int) $signer['id'], (int) $contract['id']),
        ));
    }

    /**
     * Already-completed signers on the same request, with their signature
     * image inlined as a data URI so the signing page and the completion
     * page can show them without a new public file-serving route.
     */
    private function coSignersForView($requestId, $excludeSignerId, $contractId)
    {
        $rows = $this->cv->getCompletedSigners($requestId, $excludeSignerId);
        $dir  = CONTRACTS_UPLOADS_FOLDER . $contractId . '/';
        $out  = array();

        foreach ($rows as $row) {
            $path = $dir . (string) $row['signature_image'];

            if (!is_file($path)) {
                continue;
            }

            $data = @file_get_contents($path);

            if ($data === false) {
                continue;
            }

            $out[] = array(
                'full_name'   => (string) $row['full_name'],
                'role'        => (string) $row['role'],
                'completed_at' => (int) $row['completed_at'],
                'image_data'  => 'data:image/png;base64,' . base64_encode($data),
            );
        }

        return $out;
    }

    /**
     * GET /sign/{token}/document -- the contract PDF for this signer's page,
     * including every signature already applied. The token is only checked,
     * never consumed, so it can be loaded as often as the page is opened.
     */
    public function document($rawToken = '')
    {
        $r = $this->cv->resolveSigningToken($rawToken, time());

        if (empty($r['ok'])) { show_404(); }

        $this->load->model('contracts_model');
        $contract = $this->contracts_model->get((int) $r['contract']['id']);

        if (!$contract) { show_404(); }

        $pdf = contract_pdf($contract);
        $pdf->Output('contract.pdf', 'I');
    }

    private function handlePost($rawToken, $tokenId, array $signer, array $contract, $now)
    {
        $action = (string) $this->input->post('action');

        if ($action === 'send_otp') {
            $r = $this->cv->generateSigningOtp((int) $signer['id'], $now);

            set_alert($r['ok'] ? 'success' : 'warning',
                $r['ok'] ? 'A code has been sent to your email.'
                         : 'Could not send a code. Please try again in a moment.');

            redirect(site_url('sign/' . rawurlencode($rawToken)));

            return;
        }

        if ($action === 'verify_otp') {
            $r = $this->cv->verifySigningOtp((int) $signer['id'], (string) $this->input->post('otp'), $now);

            set_alert($r['ok'] ? 'success' : 'warning',
                $r['ok'] ? 'Code verified.' : $this->otpFailureMessage((string) $r['reason']));

            redirect(site_url('sign/' . rawurlencode($rawToken)));

            return;
        }

        if ($action === 'submit') {
            $r = $this->cv->completeNativeSigning(
                (int) $signer['id'],
                $tokenId,
                (string) $this->input->post('signature'),
                (string) $this->input->post('aadhaar_number'),
                (string) $this->input->ip_address(),
                $now
            );

            if (empty($r['ok'])) {
                set_alert('warning', $this->signFailureMessage((string) $r['reason']));
                redirect(site_url('sign/' . rawurlencode($rawToken)));

                return;
            }

            $this->load->view('payplex_contract_verification/sign_complete', array(
                'title'      => 'Signed',
                'contract'   => $contract,
                'co_signers' => $this->coSignersForView((int) $signer['request_id'], null, (int) $contract['id']),
            ));

            return;
        }

        redirect(site_url('sign/' . rawurlencode($rawToken)));
    }

    private function otpFailureMessage($reason)
    {
        $map = array(
            'no_otp_requested'  => 'Request a code first.',
            'too_many_attempts' => 'Too many incorrect attempts. Request a new code.',
            'expired'           => 'That code has expired. Request a new one.',
            'code_does_not_match' => 'That code is not correct.',
        );

        return isset($map[$reason]) ? $map[$reason] : 'Could not verify that code.';
    }

    private function signFailureMessage($reason)
    {
        $map = array(
            'already_signed'          => 'You have already signed this document.',
            'otp_not_verified'        => 'Please verify your code before signing.',
            'invalid_aadhaar_number'  => 'Enter a valid 12-digit Aadhaar number.',
            'signature_capture_failed' => 'Your signature could not be saved. Please try again.',
        );

        return isset($map[$reason]) ? $map[$reason] : 'Could not complete signing. Please try again.';
    }
}
