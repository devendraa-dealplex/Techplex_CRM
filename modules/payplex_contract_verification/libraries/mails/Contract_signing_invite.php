<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Contract_signing_invite
 *
 * Emails one invitee their own single-use native signing link. Not tied to
 * "customer" or "staff" ($for is left unset on purpose): an invitee is
 * frequently neither -- a witness, a guarantor, someone with no CRM account
 * at all -- and App_mail_template::is_user_inactive() only runs its check
 * for those two values, so leaving it unset means every invitee's mail
 * actually goes out rather than being silently blocked by a check meant for
 * a different kind of recipient.
 */
class Contract_signing_invite extends App_mail_template
{
    public $slug = 'contract-signing-invite';

    public $rel_type = 'contract';

    protected $signer;

    protected $contract;

    protected $url;

    protected $expiresAt;

    public function __construct($signer, $contract, $url, $expiresAt)
    {
        parent::__construct();

        $this->signer    = $signer;
        $this->contract  = $contract;
        $this->url       = $url;
        $this->expiresAt = $expiresAt;
    }

    public function build()
    {
        $this->to($this->signer['email'])
             ->set_rel_id($this->contract['id'])
             ->set_merge_fields(array(
                 '{signer_name}'      => html_escape((string) $this->signer['full_name']),
                 '{contract_subject}' => html_escape((string) $this->contract['subject']),
                 '{signing_link}'     => (string) $this->url,
                 '{link_expiry}'      => html_escape(date('Y-m-d H:i', (int) $this->expiresAt)),
             ));
    }
}
