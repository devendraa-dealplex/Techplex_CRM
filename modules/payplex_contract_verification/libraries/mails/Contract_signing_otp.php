<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Contract_signing_otp
 *
 * Emails one signer their one-time verification code. Same reasoning as
 * Contract_signing_invite for leaving $for unset -- an invitee is not
 * necessarily a CRM contact or staff member.
 */
class Contract_signing_otp extends App_mail_template
{
    public $slug = 'contract-signing-otp';

    public $rel_type = 'contract';

    protected $signer;

    protected $code;

    protected $ttlMinutes;

    public function __construct($signer, $code, $ttlMinutes)
    {
        parent::__construct();

        $this->signer     = $signer;
        $this->code       = $code;
        $this->ttlMinutes = $ttlMinutes;
    }

    public function build()
    {
        $this->to($this->signer['email'])
             ->set_merge_fields(array(
                 '{signer_name}'       => html_escape((string) $this->signer['full_name']),
                 '{otp_code}'          => (string) $this->code,
                 '{otp_expiry_minutes}' => (int) $this->ttlMinutes,
             ));
    }
}
