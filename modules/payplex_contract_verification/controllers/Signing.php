<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Contract_caps.php';
require_once __DIR__ . '/../libraries/Contract_signing_state.php';
require_once __DIR__ . '/../libraries/Contract_signing_settings.php';
require_once __DIR__ . '/../libraries/Contract_webhook_guard.php';
require_once __DIR__ . '/../libraries/Contract_failures.php';
require_once __DIR__ . '/../libraries/Contract_evidence.php';
require_once __DIR__ . '/../libraries/Contract_signer.php';
require_once __DIR__ . '/../libraries/Contract_field_mapper.php';
require_once __DIR__ . '/../libraries/Leegality_provider.php';
require_once __DIR__ . '/../libraries/Contract_lifecycle.php';
require_once __DIR__ . '/../libraries/Contract_profile_map.php';
require_once __DIR__ . '/../libraries/Contract_kyc.php';
require_once __DIR__ . '/../libraries/Contract_invite_token.php';
require_once __DIR__ . '/../libraries/Contract_circuit.php';
require_once __DIR__ . '/../libraries/Contract_assignment.php';
require_once __DIR__ . '/../libraries/Contract_authz.php';
require_once __DIR__ . '/../libraries/Contract_evidence_types.php';
require_once __DIR__ . '/../libraries/Evidence_store.php';
require_once __DIR__ . '/../libraries/Kyc_decision_service.php';

/**
 * Signing — the contract verification and e-signature endpoints.
 *
 * EVERY WRITING ACTION FOLLOWS THE SAME FOUR STEPS
 * ------------------------------------------------
 *   1. capability  — may this person press this button at all
 *   2. POST        — is this a request they made, not one made for them
 *   3. schema      — refuse against an un-migrated database, with a message
 *   4. the model   — which re-checks state and ownership before writing
 *
 * Step 4 is not redundant. The controller answers "may the button exist"; the
 * model answers "may this record change". Hiding a button has never been an
 * authorisation mechanism on this project.
 *
 * THE WEBHOOK IS THE EXCEPTION AND IS TREATED AS ONE
 * --------------------------------------------------
 * `webhook()` is the only public route. It has no session, no capability and no
 * CSRF token — it cannot have any of those, because the caller is a machine
 * somewhere else. What it has instead is `Contract_webhook_guard`, and today
 * that guard refuses everything, because the signature scheme is undocumented.
 * An unauthenticated route that accepted unauthenticated input would be the
 * worst thing in this module.
 */
class Signing extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_contract_verification/contract_verification_model', 'cv');
    }

    /* ---------------------------------------------------------------- */

    private function actor()
    {
        return function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0;
    }

    private function isAdminActor()
    {
        return function_exists('is_admin') && is_admin();
    }

    /**
     * Refuse unless the caller holds the capability.
     *
     * Takes the RESULT of the check, not the name, so every call site spells
     * the capability out as a literal. A helper that did the lookup internally
     * would leave no literal name anywhere in the file — which defeats both a
     * reader and the CI capability scanner.
     */
    private function need($held)
    {
        if ($held === true) { return; }
        if ($this->isAdminActor()) { return; }

        access_denied('payplex_contract_verification');
    }

    /**
     * Does the actor hold this capability?
     *
     * One spelling of the permission lookup, so nineteen capabilities do not
     * become nineteen slightly different calls. It returns the raw answer and
     * does NOT fold in administrator status — `need()` decides what to do about
     * an administrator, and the evidence routes deliberately want the
     * unvarnished answer, because "is an administrator" and "has been granted
     * sight of biometric data" are different statements.
     *
     * @param  string $capability
     * @return bool
     */
    private function holds($capability)
    {
        return has_permission(Contract_caps::FEATURE, '', (string) $capability) === true;
    }

    /**
     * Every capability the actor actually holds, as a list.
     *
     * The evidence service is given this rather than being asked to call back
     * into the controller: it is a pure function of its arguments, and the only
     * way to keep it that way is to hand it facts.
     *
     * @return array
     */
    private function heldCapabilities()
    {
        $out = array();

        foreach (Contract_caps::all() as $cap) {
            if ($this->holds($cap)) { $out[] = $cap; }
        }

        return $out;
    }


    /**
     * Perfex's own contract-visibility rule, applied before this module shows
     * anything about a contract.
     *
     * `contract_signing_view` answers "may this person use the signing feature".
     * It does not answer "may this person see THIS contract". Before this check
     * existed, someone holding the module capability but no contracts permission
     * could read contract details through the panel that Perfex would never have
     * shown them on the contract itself -- the module was enforcing its own door
     * and ignoring the building's.
     *
     * Mirrored from Contracts::contract(): hold `view`, or hold `view_own` and be
     * the staff member the contract was added by.
     *
     * A failed ownership test is show_404(), not access_denied(), so the panel
     * does not confirm to an unentitled caller that a given contract id exists.
     */
    private function needContractAccess($contract)
    {
        if ($this->isAdminActor()) { return; }

        if (staff_cant('view', 'contracts') && staff_cant('view_own', 'contracts')) {
            access_denied('contracts');
        }

        if (staff_cant('view', 'contracts')) {
            $owner = is_array($contract)
                ? (isset($contract['addedfrom']) ? $contract['addedfrom'] : null)
                : (isset($contract->addedfrom) ? $contract->addedfrom : null);

            if ($owner === null || (int) $owner !== (int) get_staff_user_id()) {
                show_404();
            }
        }
    }
    private function requirePost()
    {
        if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
        }
    }

    private function requireSchema()
    {
        if ($this->cv->schemaReady()) { return; }

        $this->load->view('payplex_contract_verification/not_migrated', array(
            'title'      => 'Contract signing — not installed',
            'missing'    => $this->cv->missingTables(),
            'migrations' => array('201_contract_signing_schema.php',
                                  '202_evidence_webhooks_audit.php',
                                  '203_settings_and_config.php'),
        ));
        exit;
    }

    private function backToContract($contractId)
    {
        return admin_url('payplex_contract_verification/signing/contract/' . (int) $contractId);
    }

    /* ================================================================
     * Settings
     * ============================================================== */

    /**
     * The provider settings screen.
     *
     * Shows what is configured, never what it is. Every credential is described
     * by a fingerprint; identifiers additionally show their last four characters.
     */
    public function settings()
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_settings'));
        $this->requireSchema();

        $this->load->view('payplex_contract_verification/settings', array(
            'title'    => 'Contract signing — provider settings',
            'schema'   => Contract_signing_settings::schema(),
            'stored'   => $this->cv->settings(),
            'status'   => $this->cv->integrationStatus(),
            'gate'     => Contract_signing_settings::productionConditions(),
            'is_admin' => $this->isAdminActor(),
        ));
    }

    /**
     * Save provider settings. Administrator only.
     *
     * Blank means keep for a credential, so a save that did not intend to touch
     * one cannot erase it.
     */
    public function settings_save()
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_settings'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->isAdminActor()) {
            access_denied('payplex_contract_verification');
        }

        $input = array();

        foreach (array_keys(Contract_signing_settings::schema()) as $key) {
            $posted = $this->input->post($key);

            if ($posted === null) { continue; }

            $input[$key] = $posted;
        }

        /*
         * The environment is removed here and handled by its own action. A
         * production switch buried in a general save is a production switch
         * somebody flips while changing a timeout.
         */
        unset($input['environment']);

        $r = $this->cv->saveSettings($input, $this->actor(), $this->isAdminActor());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect(admin_url('payplex_contract_verification/signing/settings'));
    }

    /**
     * Switch environment. Production requires every gate condition.
     *
     * The gate is evaluated server-side at the moment of the change, and the
     * refusal names the unmet conditions — a gate that says only "no" is a gate
     * people work around.
     */
    public function set_environment()
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_settings'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->isAdminActor()) {
            access_denied('payplex_contract_verification');
        }

        $target = (string) $this->input->post('environment');

        if ($target === Contract_signing_settings::ENV_SANDBOX) {
            $this->cv->saveSettings(array('environment' => $target), $this->actor(), true);
            set_alert('success', 'Switched to sandbox.');
            redirect(admin_url('payplex_contract_verification/signing/settings'));

            return;
        }

        if ((string) $this->input->post('confirm_production') !== 'ENABLE PRODUCTION') {
            set_alert('warning', 'Nothing was changed: the confirmation was missing.');
            redirect(admin_url('payplex_contract_verification/signing/settings'));

            return;
        }

        /*
         * Evidence is read from what the module actually recorded, never from
         * the request. A gate whose conditions arrive in the POST is not a gate.
         */
        $evidence = $this->cv->productionEvidence();
        $gate     = Contract_signing_settings::productionGate($evidence);

        if (empty($gate['allowed'])) {
            set_alert('warning', 'Production was not enabled. Unmet: '
                . implode(', ', array_map(function ($c) { return str_replace('_', ' ', $c); },
                                          $gate['unmet'])) . '.');
            redirect(admin_url('payplex_contract_verification/signing/settings'));

            return;
        }

        $this->cv->saveSettings(array('environment' => Contract_signing_settings::ENV_PRODUCTION),
                                $this->actor(), true);

        set_alert('success', 'Production enabled. Enter the production credentials now — they are '
                           . 'deliberately not carried over from sandbox.');
        redirect(admin_url('payplex_contract_verification/signing/settings'));
    }

    /**
     * Prove the credentials work, without revealing them.
     *
     * Today this reports that the adapter is not implemented, which is the
     * truthful answer and is better than a green tick that means nothing.
     */
    public function test_connection()
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_settings'));
        $this->requireSchema();
        $this->requirePost();

        $provider = new Leegality_provider(array());
        $r        = $provider->testConnection();

        $this->cv->audit($this->actor(), 'cv_test_connection', null, null,
                         array('ok' => !empty($r['ok']), 'reason' => (string) $r['reason']));

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect(admin_url('payplex_contract_verification/signing/settings'));
    }

    /**
     * A full sandbox round trip, using fixture data only.
     *
     * Blocked for the same reason as everything else, and it records the
     * attempt so the production gate can see that it has not passed.
     */
    public function sandbox_test()
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_settings'));
        $this->requireSchema();
        $this->requirePost();

        $provider = new Leegality_provider(array());
        $r        = $provider->testConnection();

        $this->cv->audit($this->actor(), 'cv_sandbox_test', null, null,
                         array('ok' => false, 'reason' => (string) $r['reason']));

        set_alert('warning', (string) $r['message']);
        redirect(admin_url('payplex_contract_verification/signing/settings'));
    }

    /* ================================================================
     * The contract panel
     * ============================================================== */

    /**
     * Everything this module knows about one contract.
     */
    public function contract($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_view'));
        $this->requireSchema();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $request = $this->cv->latestRequestForContract((int) $contractId);
        $rid     = $request ? (int) $request['id'] : 0;

        $this->load->view('payplex_contract_verification/contract_panel', array(
            'title'      => 'Contract signing',
            'contract'   => $contract,
            'request'    => $request,
            'signers'    => $rid ? $this->cv->signers($rid) : array(),
            'execution'  => $rid ? $this->cv->executionStatus($rid) : null,
            'status'     => $this->cv->integrationStatus(),
            'states'     => Contract_signing_state::states(),
            'audit'      => $this->cv->auditFor((int) $contractId, 50),
            'can_prepare' => has_permission('payplex_contract_verification', '', 'contract_signing_prepare'),
            'can_approve' => has_permission('payplex_contract_verification', '', 'contract_signing_approve'),
            'can_send'    => has_permission('payplex_contract_verification', '', 'contract_signing_send'),
            'can_cancel'  => has_permission('payplex_contract_verification', '', 'contract_signing_cancel'),
            'can_evidence' => has_permission('payplex_contract_verification', '', 'contract_signing_view_evidence'),
            'is_admin'    => $this->isAdminActor(),
        ));
    }

    /** Record a completed identity or business verification. */
    public function verify_customer($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->cv->contract((int) $contractId)) { show_404(); }

        $type    = (string) $this->input->post('verification_type');
        $outcome = (string) $this->input->post('outcome');
        $masked  = (string) $this->input->post('masked_reference');

        $r = $this->cv->recordVerification((int) $contractId, $type, $outcome, $masked,
                                           $this->actor(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backToContract($contractId));
    }

    /** Record that a Video KYC was completed and approved. */
    public function record_video_kyc($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->cv->contract((int) $contractId)) { show_404(); }

        $outcome = (string) $this->input->post('outcome');

        $r = $this->cv->recordVerification((int) $contractId, 'video_kyc', $outcome, '',
                                           $this->actor(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backToContract($contractId));
    }

    /**
     * Show where each signature field will land, before anything is sent.
     *
     * Read-only, and the only way to see a coordinate problem before a customer
     * does.
     */
    public function preview_fields($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $version = (string) $this->input->get('version');

        $this->load->view('payplex_contract_verification/preview_fields', array(
            'title'    => 'Preview signature fields',
            'contract' => $contract,
            'version'  => $version,
            'fields'   => $this->cv->fields((int) $contractId, $version),
            'types'    => Contract_field_mapper::fieldTypes(),
        ));
    }

    /**
     * The signer roster — who will sign, decided before anything is sent.
     *
     * This is the gap that made placement half a feature: the editor could only
     * offer "signer slot 1, slot 2" because there was nobody to name, and an
     * approved placement carried a NULL signer reference that the mapper then
     * refused. Slots here are the same numbers the editor stores, so the two
     * line up.
     */
    public function signers($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $this->load->view('payplex_contract_verification/signers', array(
            'title'         => 'Signers',
            'contract'      => $contract,
            'signers'       => $this->cv->contractSigners((int) $contractId),
            'readiness'     => $this->cv->rosterReadiness((int) $contractId),
            'auth_methods'  => $this->cv->signerAuthMethods(),
            'parties'       => $this->cv->signerParties(),
            'roster_ready'  => $this->cv->rosterReady(),
            'has_request'   => (bool) $this->cv->latestRequestForContract((int) $contractId),
        ));
    }

    /** Add or update one roster entry. */
    public function signer_save($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();
        $this->requirePost();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $r = $this->cv->saveContractSigner((int) $contractId, $this->signerInput(),
                                           get_staff_user_id(), time());

        $this->signerRedirect($contractId, $r, $r['ok'] ? 'Signer saved.' : null);
    }

    /** Replace a signer, keeping the original row and the reason. */
    public function signer_replace($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();
        $this->requirePost();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $r = $this->cv->replaceContractSigner(
            (int) $contractId,
            (int) $this->input->post('id'),
            $this->signerInput(),
            (string) $this->input->post('replacement_reason'),
            get_staff_user_id(),
            time()
        );

        $this->signerRedirect($contractId, $r, $r['ok'] ? 'Signer replaced. The original is kept.' : null);
    }

    /** Remove a roster entry. Refused once a request exists. */
    public function signer_delete($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();
        $this->requirePost();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $r = $this->cv->removeContractSigner((int) $contractId, (int) $this->input->post('id'),
                                             get_staff_user_id(), time());

        $this->signerRedirect($contractId, $r, $r['ok'] ? 'Signer removed.' : null);
    }

    /** One place that reads signer fields, so every route reads them identically. */
    private function signerInput()
    {
        return array(
            'id'                      => (int) $this->input->post('id'),
            'full_name'               => (string) $this->input->post('full_name'),
            'email'                   => (string) $this->input->post('email'),
            'mobile_e164'             => (string) $this->input->post('mobile_e164'),
            'designation'             => (string) $this->input->post('designation'),
            'party'                   => (string) $this->input->post('party'),
            'role'                    => (string) $this->input->post('role'),
            'signing_order'           => (int) $this->input->post('signing_order'),
            'is_mandatory'            => $this->input->post('is_mandatory') ? 1 : 0,
            'is_authorised_signatory' => $this->input->post('is_authorised_signatory') ? 1 : 0,
            'auth_method'             => (string) $this->input->post('auth_method'),
            'kyc_required'            => $this->input->post('kyc_required') ? 1 : 0,
            'reminder_interval_hours' => (int) $this->input->post('reminder_interval_hours'),
        );
    }

    /** One redirect path for every roster action, so refusals are visible. */
    private function signerRedirect($contractId, array $r, $successNote)
    {
        if (empty($r['ok'])) {
            set_alert('warning', 'Refused: ' . (string) $r['reason']);
        } elseif ($successNote !== null) {
            set_alert('success', $successNote);
        }

        redirect(admin_url('payplex_contract_verification/signing/signers/' . (int) $contractId));
    }

    /**
     * The placement editor.
     *
     * WHAT THIS EDITOR DRAWS ON, AND WHAT IT DOES NOT
     * -----------------------------------------------
     * There is no PDF pipeline in this build, so the editor cannot render the
     * contract document behind the boxes. It works on a DECLARED page geometry
     * -- width, height and rotation the operator states -- and validates
     * placements against that.
     *
     * This is deliberate and it is not a substitute for the real thing. At send
     * time Contract_field_mapper re-applies the transform using the geometry
     * read from the actual PDF, and refuses the whole batch if a box falls off
     * the page. So a declared geometry that turns out to be wrong produces a
     * refusal, not a misplaced signature block. Fail closed.
     *
     * The alternative -- letting people place fields with no geometry at all --
     * is what the module does today, which is why the mapper has never had any
     * input to map.
     */
    public function fields_editor($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $version = $this->versionFor((int) $contractId);
        $request = $this->cv->latestRequestForContract((int) $contractId);

        $this->load->view('payplex_contract_verification/fields_editor', array(
            'title'        => 'Place signature fields',
            'contract'     => $contract,
            'version'      => $version,
            'drafts'       => $this->cv->draftFields((int) $contractId, $version),
            'approved'     => $this->cv->fields((int) $contractId, $version),
            'types'        => Contract_field_mapper::fieldTypes(),
            'drafts_ready' => $this->cv->draftsReady(),
            /* Request signers if a request exists; otherwise the roster, so the
               editor names real people from the moment they are entered rather
               than offering anonymous slots until something is sent. */
            'signers'      => $request
                                ? $this->cv->signers((int) $request['id'])
                                : $this->cv->activeSigners((int) $contractId),
            'min_width'    => Contract_field_mapper::MIN_WIDTH_PT,
            'min_height'   => Contract_field_mapper::MIN_HEIGHT_PT,
            'can_approve'  => $this->isAdminActor()
                || has_permission('payplex_contract_verification', '', 'contract_signing_approve'),
        ));
    }

    /** Create, move or resize one draft placement. */
    public function field_save($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();
        $this->requirePost();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $r = $this->cv->saveDraftField(
            (int) $contractId,
            $this->versionFor((int) $contractId),
            array(
                'id'           => (int) $this->input->post('id'),
                'page_number'  => (int) $this->input->post('page_number'),
                'signer_slot'  => (int) $this->input->post('signer_slot'),
                'field_type'   => (string) $this->input->post('field_type'),
                'x'            => $this->input->post('x'),
                'y'            => $this->input->post('y'),
                'width'        => $this->input->post('width'),
                'height'       => $this->input->post('height'),
                'editor_scale' => $this->input->post('editor_scale'),
                'is_required'  => $this->input->post('is_required') ? 1 : 0,
            ),
            get_staff_user_id(),
            time()
        );

        $this->placementRedirect($contractId, $r, $r['ok'] ? 'Placement saved.' : null);
    }

    /** Remove one draft placement. */
    public function field_delete($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();
        $this->requirePost();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $r = $this->cv->deleteDraftField((int) $contractId, (int) $this->input->post('id'),
                                         get_staff_user_id(), time());

        $this->placementRedirect($contractId, $r, $r['ok'] ? 'Placement removed.' : null);
    }

    /** Copy the approved set back into the editor for revision. */
    public function fields_copy($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();
        $this->requirePost();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $r = $this->cv->copyApprovedToDrafts((int) $contractId, $this->versionFor((int) $contractId),
                                             get_staff_user_id(), time());

        $this->placementRedirect($contractId, $r,
            $r['ok'] ? ('Copied ' . (int) $r['copied'] . ' approved placement(s) for editing.') : null);
    }

    /** Discard the draft set. Approved placements are untouched. */
    public function fields_discard($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();
        $this->requirePost();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $r = $this->cv->discardDrafts((int) $contractId, $this->versionFor((int) $contractId),
                                      get_staff_user_id(), time());

        $this->placementRedirect($contractId, $r,
            $r['ok'] ? ('Discarded ' . (int) $r['discarded'] . ' draft placement(s).') : null);
    }

    /**
     * Promote drafts to the approved set.
     *
     * A separate capability from editing on purpose: drawing a box and deciding
     * the document is ready to carry it are different acts, and the second is
     * the one that reaches a customer.
     */
    public function fields_approve($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();
        $this->requirePost();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $holds = has_permission('payplex_contract_verification', '', 'contract_signing_approve');

        $r = $this->cv->approveDrafts((int) $contractId, $this->versionFor((int) $contractId),
                                      get_staff_user_id(), $holds, $this->isAdminActor(), time());

        $note = null;

        if ($r['ok']) {
            $note = 'Approved ' . (int) $r['approved'] . ' placement(s).';

            /* Said plainly rather than buried: an approved field with no signer
               attached cannot be sent, and the operator should find that out
               here rather than at the send step. */
            if ((int) $r['unbound'] > 0) {
                $note .= ' ' . (int) $r['unbound'] . ' are not yet bound to a signer, because this '
                       . 'contract has no signing request with signers. They will be refused at '
                       . 'send until signers exist.';
            }
        }

        $this->placementRedirect($contractId, $r, $note);
    }

    /**
     * The contract version placements are bound to.
     *
     * Precedence: an explicit ?version=, then the version of the latest signing
     * request, then 'v1'.
     *
     * This is a WEAKNESS INHERITED, NOT INTRODUCED. Without a PDF pipeline
     * there is no document hash to version against, and `preview_fields` already
     * defaulted to an empty string -- which is why that screen has been
     * rendering "for contract version ." When the PDF step lands, this is the
     * one method that has to change, and every placement is bound through it.
     */
    private function versionFor($contractId)
    {
        $explicit = (string) $this->input->get('version');

        if ($explicit !== '') { return $explicit; }

        $request = $this->cv->latestRequestForContract((int) $contractId);

        if ($request && (string) $request['contract_version'] !== '') {
            return (string) $request['contract_version'];
        }

        return 'v1';
    }

    /** One redirect path for every placement action, so failures are visible. */
    private function placementRedirect($contractId, array $r, $successNote)
    {
        if (empty($r['ok'])) {
            set_alert('warning', 'Placement refused: ' . (string) $r['reason']);
        } elseif ($successNote !== null) {
            set_alert('success', $successNote);
        }

        redirect(admin_url('payplex_contract_verification/signing/fields_editor/' . (int) $contractId));
    }

    /** Maker submits for internal approval. */
    public function submit_for_approval($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_prepare'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->cv->contract((int) $contractId)) { show_404(); }

        $r = $this->cv->submitForApproval((int) $contractId, $this->actor(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backToContract($contractId));
    }

    /**
     * Checker approves for signing.
     *
     * The maker-checker rule is enforced in the model and holds even for an
     * administrator: approving your own submission is precisely what the
     * control exists to prevent.
     */
    public function approve_for_signing($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_approve'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->cv->contract((int) $contractId)) { show_404(); }

        $holds = has_permission('payplex_contract_verification', '', 'contract_signing_approve');

        $r = $this->cv->approveForSigning((int) $contractId, $this->actor(), $holds,
                                          $this->isAdminActor(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backToContract($contractId));
    }

    /** Send to the provider. Blocked until the adapter is implemented. */
    public function send($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_send'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->cv->contract((int) $contractId)) { show_404(); }

        if ((string) $this->input->post('confirm_send') !== 'SEND') {
            set_alert('warning', 'Nothing was sent: the confirmation was missing.');
            redirect($this->backToContract($contractId));

            return;
        }

        $holds = has_permission('payplex_contract_verification', '', 'contract_signing_send');

        $r = $this->cv->sendForSigning((int) $contractId, $this->actor(), $holds,
                                       $this->isAdminActor(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backToContract($contractId));
    }

    /** Re-issue a signing link. Never renders the link into the audit trail. */
    public function resend_link($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_send'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->cv->contract((int) $contractId)) { show_404(); }

        $reference = (string) $this->input->post('signer_reference');

        $r = $this->cv->resendSigningLink((int) $contractId, $reference, $this->actor(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backToContract($contractId));
    }

    /** Ask the provider for the authoritative status and reconcile. */
    public function sync_status($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_view'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->cv->contract((int) $contractId)) { show_404(); }

        if (!$this->isAdminActor()) {
            access_denied('payplex_contract_verification');
        }

        $r = $this->cv->syncStatus((int) $contractId, $this->actor(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backToContract($contractId));
    }

    /** The signer timeline. Evidence capability, because it names people. */
    public function signer_timeline($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_view_evidence'));
        $this->requireSchema();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $request = $this->cv->latestRequestForContract((int) $contractId);

        $this->load->view('payplex_contract_verification/timeline', array(
            'title'    => 'Signer timeline',
            'contract' => $contract,
            'request'  => $request,
            'signers'  => $request ? $this->cv->signers((int) $request['id']) : array(),
            'audit'    => $this->cv->auditFor((int) $contractId, 200),
            'states'   => Contract_signing_state::states(),
        ));
    }

    /** Download the signed agreement. Permission-checked and audited. */
    public function download_signed($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_view_evidence'));
        $this->requireSchema();

        $r = $this->cv->serveDocument((int) $contractId, Contract_evidence::DOC_SIGNED,
                                      $this->actor(), time());

        if (empty($r['ok'])) {
            set_alert('warning', (string) $r['message']);
            redirect($this->backToContract($contractId));

            return;
        }

        $this->streamDocument($r);
    }

    /** Download the completion certificate. Same treatment. */
    public function download_certificate($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_view_evidence'));
        $this->requireSchema();

        $r = $this->cv->serveDocument((int) $contractId, Contract_evidence::DOC_CERTIFICATE,
                                      $this->actor(), time());

        if (empty($r['ok'])) {
            set_alert('warning', (string) $r['message']);
            redirect($this->backToContract($contractId));

            return;
        }

        $this->streamDocument($r);
    }

    /**
     * Download an identity document. Its own capability and a stated purpose.
     *
     * The most sensitive action in this module. The purpose is recorded because
     * "who downloaded this and why" is the question that gets asked afterwards.
     */
    public function download_identity($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_download_identity'));
        $this->requireSchema();
        $this->requirePost();

        $holds   = has_permission('payplex_contract_verification', '', 'contract_signing_download_identity');
        $purpose = (string) $this->input->post('purpose');

        $r = $this->cv->serveIdentityDocument((int) $contractId,
                                              (string) $this->input->post('verification_id'),
                                              $this->actor(), $holds, $purpose, time());

        if (empty($r['ok'])) {
            set_alert('warning', (string) $r['message']);
            redirect($this->backToContract($contractId));

            return;
        }

        $this->streamDocument($r);
    }

    /** Cancel a live signing request, with a stated reason. */
    public function cancel($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_cancel'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->cv->contract((int) $contractId)) { show_404(); }

        if ((string) $this->input->post('confirm_cancel') !== 'CANCEL') {
            set_alert('warning', 'Nothing was cancelled: the confirmation was missing.');
            redirect($this->backToContract($contractId));

            return;
        }

        $reason = (string) $this->input->post('reason');

        $r = $this->cv->cancelRequest((int) $contractId, $reason, $this->actor(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backToContract($contractId));
    }

    /* ================================================================
     * The webhook
     * ============================================================== */

    /**
     * Inbound provider callback. Public, unauthenticated by session, machine-to-machine.
     *
     * THE ORDER OF THE CHECKS IS THE SECURITY OF THIS ROUTE.
     *
     * The raw body is read first and kept byte-exact, because a signature is
     * over the bytes that were sent — not over a structure that has been
     * decoded and re-encoded, which changes key order and escaping and never
     * verifies. Nothing is parsed, looked up or written until the guard has
     * admitted the delivery.
     *
     * Today the guard refuses everything, because the signature scheme for this
     * account is undocumented. That is the correct behaviour for a public route
     * whose authentication is unspecified, and it is far better than the
     * alternative — a placeholder that returns true and turns this into an
     * open endpoint for changing contract status.
     */
    public function webhook()
    {
        /* No requireSchema() view here: this caller is a machine and wants a
           status code, not an HTML page explaining migrations. */
        if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST') {
            $this->output->set_status_header(405);

            return;
        }

        $raw = $this->rawBody();

        $decision = Contract_webhook_guard::admit(array(
            'raw_body'  => $raw,
            'signature' => (string) $this->input->get_request_header('X-Signature', true),
            'secret'    => '',
            'algorithm' => '',
            'timestamp' => $this->input->get_request_header('X-Timestamp', true),
            'now'       => time(),
            'seen_keys' => array(),
        ));

        if (!$this->cv->schemaReady()) {
            $this->output->set_status_header(503);

            return;
        }

        /*
         * Every delivery is recorded, admitted or not — a refused webhook is
         * exactly the thing somebody needs to see when they ask why a contract
         * is not updating. The body itself is never stored, only its digest.
         */
        $this->cv->recordRefusedWebhook(array(
            'body_sha256' => hash('sha256', (string) $raw),
            'result'      => (string) $decision['reason'],
            'http_status' => (int) $decision['http_status'],
            'received_at' => time(),
        ));

        $this->output->set_status_header((int) $decision['http_status']);
        $this->output->set_content_type('application/json');
        $this->output->set_output(json_encode(array('received' => true)));
    }

    /**
     * The request body, byte-exact.
     *
     * `php://input` rather than anything the framework has already consumed and
     * re-encoded. Read once and held, because a second read of the stream
     * returns nothing on some SAPIs and a signature check against an empty
     * string fails in a way that looks like a bad secret.
     *
     * @return string
     */
    private function rawBody()
    {
        static $body = null;

        if ($body !== null) { return $body; }

        $body = (string) file_get_contents('php://input');

        return $body;
    }

    /**
     * Send a stored evidence file to the browser.
     *
     * Streams from outside the document root. The file is never reachable by
     * URL, which is why this method exists at all.
     */
    private function streamDocument(array $r)
    {
        $this->output
            ->set_content_type(isset($r['content_type']) ? (string) $r['content_type'] : 'application/pdf')
            ->set_header('Content-Disposition: attachment; filename="'
                         . str_replace('"', '', (string) $r['filename']) . '"')
            ->set_header('X-Content-Type-Options: nosniff')
            ->set_header('Cache-Control: private, no-store')
            ->set_output($r['bytes']);
    }
    /* ================================================================
     * Execution & Video KYC
     *
     * Every route below follows the same four steps, in the same order, and the
     * order is the point:
     *
     *   1. requirePost()        — state changes are never reachable by URL
     *   2. need(capability)     — may this person use this feature at all
     *   3. needContractAccess() — may this person see THIS contract
     *   4. the lifecycle guard  — is this action legal in the current state
     *
     * Skipping 3 gives you a module that enforces its own door and ignores the
     * building's. Skipping 4 gives you buttons that work whenever somebody can
     * reach them, which is how a contract gets sent twice or completed early.
     * ============================================================== */

    /**
     * The Execution & Video KYC panel.
     */
    public function execution($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_view'));
        $this->requireSchema();

        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        $this->load->view('payplex_contract_verification/execution_panel',
                          $this->executionPanelData($contract));
    }

    /**
     * Everything the panel renders.
     *
     * Assembled here rather than in the view so the view contains no queries and
     * no decisions — and so the CI view-contract scanner can see every variable
     * the view is given as a literal key.
     *
     * @param  array $contract
     * @return array
     */
    private function executionPanelData(array $contract)
    {
        $contractId  = (int) $contract['id'];
        $now         = time();
        $environment = $this->cv->setting('environment', 'sandbox');

        $request   = $this->cv->latestRequestForContract($contractId);
        $readiness = $this->cv->sendReadiness($contractId);
        $profile   = $readiness['profile'];
        $context   = $this->cv->completionContext($contractId);
        $blockers  = Contract_lifecycle::completionBlockers($context);

        $state = $request ? (string) $request['state'] : '';
        $life  = $this->lifecycleFor($request, $context);

        $health  = Contract_circuit::summary(
            $this->cv->providerHealth('leegality', $environment), $now);

        $kyc     = new Unconfigured_kyc_provider();
        $kycInfo = $kyc->implementationStatus();

        $signerRows = $this->signerRows($request, $readiness['signers']);

        $canSend = $this->holds(Contract_caps::CAP_SEND);

        /*
         * The KYC buttons no longer read `can_send`.
         *
         * They did, and that is how a KYC reviewer ended up needing the send
         * capability: the screen would not show them their own controls
         * otherwise. Splitting the routes without splitting the buttons would
         * leave a reviewer looking at a panel with nothing on it.
         */
        $canKycReview = $this->holds(Contract_caps::CAP_KYC_REVIEW);
        $canKycRetry  = $this->holds(Contract_caps::CAP_KYC_RETRY);

        return array(
            'title'                 => 'Execution & Video KYC',
            'contract'              => $contract,
            'environment'           => $environment,
            'lifecycle_label'       => Contract_lifecycle::label($life),
            'lifecycle_means'       => $this->lifecycleMeans($life),
            'completion_blockers'   => $blockers,
            'provider_health'       => $health,
            'kyc_provider'          => $kycInfo,
            'profile'               => $profile,
            'auth_is_provider_owned' => $profile
                ? Contract_profile_map::authIsProviderOwned((string) $profile['auth_method']) : false,
            'profile_validated_on'  => ($profile && (int) $profile['validated_at'] > 0)
                ? _d(date('Y-m-d', (int) $profile['validated_at'])) : '',
            'mapping_blockers'      => $readiness['blockers'],
            'document'              => $this->documentSummary($request, $context),
            'signer_rows'           => $signerRows,
            'deliveries'            => $this->deliveryRows($contractId),
            'failures'              => $this->failureRows($contractId),
            'evidence_rows'         => $this->evidenceRows($contractId, $context),
            'timeline'              => $this->timelineRows($contractId),
            /* Capabilities: what this person MAY do. */
            'can_view'              => true,
            'can_send'              => $canSend,
            'can_kyc_review'        => $canKycReview,
            'can_kyc_retry'         => $canKycRetry,
            'can_kyc_view'          => $this->holds(Contract_caps::CAP_KYC_VIEW),
            'can_kyc_documents'     => $this->holds(Contract_caps::CAP_KYC_VIEW_DOCUMENTS),
            'can_kyc_video'         => $this->holds(Contract_caps::CAP_KYC_VIEW_VIDEO),
            'can_cancel'            => has_permission('payplex_contract_verification', '', 'contract_signing_cancel'),
            'can_settings'          => has_permission('payplex_contract_verification', '', 'contract_signing_settings'),
            'can_evidence'          => has_permission('payplex_contract_verification', '', 'contract_signing_view_evidence'),
            /*
             * Lifecycle gates: what the CONTRACT allows, regardless of who is
             * looking. Kept separate from the capabilities above because they
             * answer different questions, and a button needs both to be true.
             */
            'may_prepare'           => $readiness['ok'] && $request
                                        && (string) $request['operation_reference'] === '',
            'may_send'              => $readiness['ok'] && $request
                                        && (string) $request['external_request_id'] === '',
            'may_resend_invitation' => $this->mayResendInvitation($state),
            'resend_reference'      => $this->firstResendReference($request),
            'may_cancel'            => $request
                && in_array($state, Contract_signing_state::cancellable(), true),
            'kyc_sessions'          => $this->kycSessionRows($request),
            'kyc_candidates'        => $this->kycCandidates($request),
            'may_create_kyc_session' => $this->maySendKycLink($request, $context)
                                        && count($this->kycCandidates($request)) > 0,
        );
    }

    /* ---- panel assembly helpers --------------------------------------- */

    private function lifecycleFor($request, array $context)
    {
        if (!$request) { return Contract_lifecycle::L_DRAFT; }

        $state = (string) $request['state'];

        if ($state === Contract_signing_state::S_COMPLETED) {
            if (Contract_lifecycle::mayComplete($context)) {
                return Contract_lifecycle::L_COMPLETED;
            }

            if (!empty($context['kyc_required'])
                && (string) $context['kyc_state'] !== Contract_lifecycle::L_KYC_PASSED) {
                return Contract_lifecycle::L_SIGNED_PENDING_KYC;
            }

            return Contract_lifecycle::L_EVIDENCE_PENDING;
        }

        $mapped = Contract_lifecycle::fromRequestState($state);

        if ($mapped !== null) { return $mapped; }

        return (int) $request['approved_at'] > 0
            ? Contract_lifecycle::L_APPROVED : Contract_lifecycle::L_DRAFT;
    }

    private function lifecycleMeans($life)
    {
        $states = Contract_lifecycle::states();

        return isset($states[$life]) ? (string) $states[$life]['means'] : '';
    }

    private function documentSummary($request, array $context)
    {
        $hash = $request ? (string) $request['original_sha256'] : '';

        return array(
            'version'      => $request ? (int) $request['contract_version'] : 0,
            'sha256_short' => $hash === '' ? '(not hashed)' : substr($hash, 0, 16) . '…',
            'unchanged'    => !empty($context['document_version_unchanged']),
        );
    }

    private function signerRows($request, array $rosterSigners)
    {
        $rows    = array();
        $signers = $request ? $this->cv->signers((int) $request['id']) : array();

        /* Before a request exists the roster is what there is to show. Showing
           nothing would make a prepared contract look empty. */
        if (count($signers) === 0) {
            foreach ($rosterSigners as $s) {
                $rows[] = array(
                    'signing_order'     => (int) $s['signing_order'],
                    'full_name'         => (string) $s['full_name'],
                    'email_masked'      => Contract_invite_token::maskEmail((string) $s['email']),
                    'role'              => (string) $s['role'],
                    'invitation_label'  => 'Not sent',
                    'auth_label'        => (string) (isset($s['auth_method']) ? $s['auth_method'] : '—'),
                    'signature_label'   => 'Not signed',
                    'kyc_label'         => !empty($s['kyc_required'])
                        ? Contract_kyc::label(Contract_kyc::K_PENDING)
                        : Contract_kyc::label(Contract_kyc::K_NONE),
                    'kyc_reject_reason' => '',
                );
            }

            return $rows;
        }

        foreach ($signers as $s) {
            $kycState = isset($s['kyc_state']) ? (string) $s['kyc_state'] : Contract_kyc::K_NONE;

            $rows[] = array(
                'signing_order'     => (int) $s['signing_order'],
                'full_name'         => (string) $s['full_name'],
                'email_masked'      => Contract_invite_token::maskEmail((string) $s['email']),
                'role'              => (string) $s['role'],
                'invitation_label'  => (int) $s['invited_at'] > 0 ? 'Sent' : 'Not sent',
                'auth_label'        => (string) $s['signature_method'],
                'signature_label'   => (int) $s['completed_at'] > 0 ? 'Signed' : 'Not signed',
                'kyc_label'         => Contract_kyc::label($kycState),
                'kyc_reject_reason' => '',
            );
        }

        return $rows;
    }

    private function deliveryRows($contractId)
    {
        $rows = array();

        foreach ($this->cv->deliveries($contractId) as $d) {
            $rows[] = array(
                'queued_on'        => _dt(date('Y-m-d H:i:s', (int) $d['queued_at'])),
                'channel'          => (string) $d['channel'],
                'recipient_masked' => (string) $d['recipient_masked'],
                'status_label'     => (string) $d['status'] === 'prepared'
                    ? 'Prepared — outbound delivery is switched off'
                    : (string) $d['status'],
                'expires_on'       => '',
            );
        }

        return $rows;
    }

    private function failureRows($contractId)
    {
        $rows = array();

        foreach ($this->cv->openFailures($contractId) as $f) {
            $rows[] = array(
                'id'               => (int) $f['id'],
                'operation'        => (string) $f['operation'],
                'operator_message' => Contract_failures::operatorMessage((string) $f['failure_code']),
                'attempted'        => !empty($f['attempted']),
                'next_attempt_on'  => (int) $f['next_attempt_at'] > 0
                    ? _dt(date('Y-m-d H:i:s', (int) $f['next_attempt_at'])) : '—',
            );
        }

        return $rows;
    }

    private function evidenceRows($contractId, array $context)
    {
        return array(
            array(
                'label'        => 'Signed agreement',
                'stored'       => !empty($context['signed_document_stored']),
                'verified'     => !empty($context['signed_document_hash_verified']),
                'sha256_short' => '',
                'download_url' => admin_url('payplex_contract_verification/signing/download_signed/'
                                            . (int) $contractId),
            ),
            array(
                'label'        => 'Provider audit trail',
                'stored'       => !empty($context['audit_trail_stored']),
                'verified'     => !empty($context['audit_trail_hash_verified']),
                'sha256_short' => '',
                'download_url' => admin_url('payplex_contract_verification/signing/download_certificate/'
                                            . (int) $contractId),
            ),
        );
    }

    private function timelineRows($contractId)
    {
        $rows = array();

        foreach ($this->cv->auditFor($contractId) as $a) {
            $rows[] = array(
                'when' => _dt(date('Y-m-d H:i:s', (int) $a['created_at'])),
                'what' => (string) $a['event'],
                'who'  => (int) $a['staff_id'] > 0 ? ('staff #' . (int) $a['staff_id']) : 'system',
            );
        }

        return $rows;
    }

    private function mayResendInvitation($state)
    {
        return in_array($state, Contract_signing_state::resendable(), true);
    }

    private function maySendKycLink($request, array $context)
    {
        if (!$request) { return false; }

        /* Only once signing is done and verification is genuinely outstanding.
           Offering it earlier would invite somebody to verify a signer who has
           not signed. */
        return !empty($context['kyc_required'])
            && (int) $context['kyc_sessions_passed'] < (int) $context['kyc_sessions_required']
            && (string) $request['state'] === Contract_signing_state::S_COMPLETED;
    }

    /**
     * Sessions, with what may be done to each.
     *
     * The three `may_*` flags are computed from the session's own state, not
     * from the contract's, because a contract can easily have one signer
     * verified and another whose session has expired.
     */
    private function kycSessionRows($request)
    {
        $rows = array();

        if (!$request) { return $rows; }

        foreach ($this->cv->kycSessions((int) $request['id']) as $session) {
            $state = (string) $session['state'];

            $rows[] = array(
                'id'            => (int) $session['id'],
                'signer_masked' => Contract_invite_token::maskEmail((string) $session['signer_email']),
                'attempt'       => (int) $session['attempt'],
                'state_label'   => Contract_kyc::label($state),
                'may_invite'    => in_array($state, array(Contract_kyc::K_SESSION_CREATED,
                                                          Contract_kyc::K_LINK_SENT), true),
                'may_retry'     => in_array($state, array(Contract_kyc::K_FAILED,
                                                          Contract_kyc::K_EXPIRED), true),
                'may_review'    => $state === Contract_kyc::K_IN_PROGRESS,
            );
        }

        return $rows;
    }

    /**
     * Signers who need verification and have no live session.
     *
     * Offering a session for somebody who already has one open is how duplicate
     * sessions get created, so they are excluded here as well as being refused
     * in the model.
     */
    private function kycCandidates($request)
    {
        $out = array();

        if (!$request) { return $out; }

        $open = array();

        foreach ($this->cv->kycSessions((int) $request['id']) as $session) {
            if (!in_array((string) $session['state'], array(Contract_kyc::K_FAILED,
                                                            Contract_kyc::K_EXPIRED,
                                                            Contract_kyc::K_CANCELLED), true)) {
                $open[] = (int) $session['signer_id'];
            }
        }

        foreach ($this->cv->signers((int) $request['id']) as $signer) {
            if (empty($signer['kyc_required']))                 { continue; }
            if (in_array((int) $signer['id'], $open, true))      { continue; }

            $out[] = array(
                'id'    => (int) $signer['id'],
                'label' => (string) $signer['full_name'] . ' — '
                         . Contract_invite_token::maskEmail((string) $signer['email']),
            );
        }

        return $out;
    }

    /**
     * The signer an invitation would be resent to.
     *
     * The first mandatory signer who has not completed — which is the one a
     * sequential workflow is waiting on. Empty when there is nobody to chase,
     * and the button is not shown in that case.
     */
    private function firstResendReference($request)
    {
        if (!$request) { return ''; }

        foreach ($this->cv->signers((int) $request['id']) as $signer) {
            if ((int) $signer['completed_at'] > 0) { continue; }

            return (string) $signer['reference'];
        }

        return '';
    }

    private function backToExecution($contractId)
    {
        return admin_url('payplex_contract_verification/signing/execution/' . (int) $contractId);
    }

    /**
     * Load a contract and enforce both doors, or stop.
     *
     * Every state-changing route below starts with this. Written once so a new
     * route cannot be added that forgets one of the two checks.
     *
     * @param  int $contractId
     * @return array
     */
    private function contractOr404($contractId)
    {
        $contract = $this->cv->contract((int) $contractId);

        if (!$contract) { show_404(); }

        $this->needContractAccess($contract);

        return $contract;
    }

    /* ---- workflow profile mapping -------------------------------------- */

    public function profile_map()
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_settings'));
        $this->requireSchema();

        $environment = $this->cv->setting('environment', 'sandbox');

        $this->load->view('payplex_contract_verification/profile_map', array(
            'title'         => 'Leegality workflow mapping',
            'environment'   => $environment,
            'profiles'      => $this->cv->profiles($environment),
            'missing'       => $this->cv->executionMissingMigrations(),
            'ordering_modes' => Contract_profile_map::orderingModes(),
            'auth_methods'  => Contract_profile_map::authMethods(),
            'kyc_policies'  => Contract_profile_map::kycPolicies(),
            'validation'    => Contract_profile_map::validationIsDestructive(),
            'may_validate'  => Contract_profile_map::mayValidate($environment),
        ));
    }

    public function profile_save()
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_settings'));
        $this->requirePost();
        $this->requireSchema();

        $result = $this->cv->saveProfile(array(
            'id'                   => (int) $this->input->post('id'),
            'label'                => (string) $this->input->post('label'),
            'profile_id'           => (string) $this->input->post('profile_id'),
            'contract_template_id' => (int) $this->input->post('contract_template_id'),
            'signer_count'         => (int) $this->input->post('signer_count'),
            'signer_roles'         => (string) $this->input->post('signer_roles'),
            'ordering_mode'        => (string) $this->input->post('ordering_mode'),
            'auth_method'          => (string) $this->input->post('auth_method'),
            'kyc_policy'           => (string) $this->input->post('kyc_policy'),
        ), $this->actor(), time());

        if (empty($result['ok'])) {
            set_alert('warning', $result['message']);
        } else {
            set_alert('success', $result['message']);
        }

        redirect(admin_url('payplex_contract_verification/signing/profile_map'));
    }

    /**
     * Validate a workflow against the sandbox.
     *
     * REFUSES OUTSIDE SANDBOX, and the refusal is the feature.
     *
     * Leegality documents no read-only way to check a profileId, so the only
     * proof one works is creating a request with it — which creates a REAL
     * document. In sandbox that is an acceptable cost and the document is left
     * to expire. In production it would be a real document in a real account,
     * created by pressing a button labelled "validate".
     */
    public function profile_validate()
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_settings'));
        $this->requirePost();
        $this->requireSchema();

        $environment = $this->cv->setting('environment', 'sandbox');
        $may         = Contract_profile_map::mayValidate($environment);

        if (empty($may['allowed'])) {
            set_alert('warning',
                'Workflow validation is refused outside sandbox. Validating creates a real document '
              . 'in the account, because the provider offers no read-only way to check a workflow ID.');

            redirect(admin_url('payplex_contract_verification/signing/profile_map'));
        }

        $profileRowId = (int) $this->input->post('id');
        $profile      = $this->cv->profile($profileRowId);

        if (!$profile) {
            set_alert('warning', 'That workflow mapping no longer exists.');
            redirect(admin_url('payplex_contract_verification/signing/profile_map'));
        }

        $status = (new Leegality_provider($this->cv->providerSettingsForStatus()))->implementationStatus();

        if (empty($status['usable'])) {
            set_alert('warning',
                'Validation needs the provider to be configured and enabled. Missing: '
              . implode(', ', $status['missing']) . '. Nothing was sent.');

            redirect(admin_url('payplex_contract_verification/signing/profile_map'));
        }

        /*
         * Reached only with credentials present and the module enabled — which
         * is not the case today, so this branch is unreachable on staging and
         * deliberately does not pretend otherwise.
         */
        set_alert('info',
            'Provider validation is not wired to a live call yet. The workflow remains unvalidated.');

        redirect(admin_url('payplex_contract_verification/signing/profile_map'));
    }

    /* ---- KYC policy configuration -------------------------------------- */

    public function kyc_config()
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_settings'));
        $this->requireSchema();

        $kyc = new Unconfigured_kyc_provider();

        $this->load->view('payplex_contract_verification/kyc_config', array(
            'title'        => 'Video KYC configuration',
            'provider'     => $kyc->implementationStatus(),
            'capabilities' => $kyc->capabilities(),
            'known'        => Contract_kyc::knownCapabilities(),
            'policies'     => Contract_profile_map::kycPolicies(),
            'locations'    => Contract_kyc::locationLabels(),
            'retention'    => $this->cv->retentionPolicy(),
            'missing'      => $this->cv->executionMissingMigrations(),
        ));
    }

    public function kyc_config_save()
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_settings'));
        $this->requirePost();
        $this->requireSchema();

        $result = $this->cv->saveRetentionPolicy(array(
            'retain_references'   => (int) $this->input->post('retain_references'),
            'retain_recordings'   => (int) $this->input->post('retain_recordings'),
            'retention_days'      => (int) $this->input->post('retention_days'),
            'legal_hold'          => (int) $this->input->post('legal_hold'),
        ), $this->actor(), time());

        set_alert(empty($result['ok']) ? 'warning' : 'success', $result['message']);

        redirect(admin_url('payplex_contract_verification/signing/kyc_config'));
    }

    /* ---- preparation and sending --------------------------------------- */

    public function prepare_request($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_send'));
        $this->requirePost();
        $this->requireSchema();

        $contract  = $this->contractOr404($contractId);
        $readiness = $this->cv->sendReadiness((int) $contractId);

        if (empty($readiness['ok'])) {
            set_alert('warning', $this->firstBlocker($readiness['blockers']));
            redirect($this->backToExecution($contractId));
        }

        $claim = $this->cv->claimSigningAttempt((int) $contractId, $this->actor(), time());

        if (empty($claim['ok'])) {
            set_alert('warning', $claim['message']);
            redirect($this->backToExecution($contractId));
        }

        $snap = $this->cv->snapshotRosterOntoRequest(
            (int) $claim['request']['id'], (int) $contractId, $readiness['signers'], time());

        set_alert(empty($snap['ok']) ? 'warning' : 'success',
            empty($snap['ok'])
                ? 'The signer snapshot could not be written, so nothing was prepared.'
                : 'Signing request prepared with ' . (int) $snap['count'] . ' signer(s). '
                . 'Nothing has been sent to the provider.');

        redirect($this->backToExecution($contractId));
    }

    public function send_for_signature($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_send'));
        $this->requirePost();
        $this->requireSchema();

        $contract  = $this->contractOr404($contractId);
        $readiness = $this->cv->sendReadiness((int) $contractId);

        if (empty($readiness['ok'])) {
            set_alert('warning', $this->firstBlocker($readiness['blockers']));
            redirect($this->backToExecution($contractId));
        }

        /* The claim is what stops a double click becoming two documents. It is
           taken before the provider is called, not after. */
        $claim = $this->cv->claimSigningAttempt((int) $contractId, $this->actor(), time());

        if (!empty($claim['ok']) && $claim['reason'] === 'already_sent') {
            set_alert('info', $claim['message']);
            redirect($this->backToExecution($contractId));
        }

        $result = $this->cv->sendForSigning((int) $contractId, $this->actor(),
            has_permission('payplex_contract_verification', '', 'contract_signing_send'),
            $this->isAdminActor(), time());

        set_alert(empty($result['ok']) ? 'warning' : 'success', (string) $result['message']);

        redirect($this->backToExecution($contractId));
    }

    public function reconcile($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_view'));
        $this->requirePost();
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);
        $result   = $this->cv->syncStatus((int) $contractId, $this->actor(), time());

        set_alert(empty($result['ok']) ? 'warning' : 'success', (string) $result['message']);

        redirect($this->backToExecution($contractId));
    }

    public function resend_invitation($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_send'));
        $this->requirePost();
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);
        $request  = $this->cv->latestRequestForContract((int) $contractId);

        if (!$request || !$this->mayResendInvitation((string) $request['state'])) {
            set_alert('warning',
                'An invitation can only be resent while one is outstanding. There is nothing to resend.');
            redirect($this->backToExecution($contractId));
        }

        $reference = (string) $this->input->post('reference');

        $result = $this->cv->resendSigningLink((int) $contractId, $reference,
                                               $this->actor(), time());

        set_alert(empty($result['ok']) ? 'warning' : 'success', (string) $result['message']);

        redirect($this->backToExecution($contractId));
    }

    /* ---- Video KYC ------------------------------------------------------ */

    public function kyc_session($contractId = 0)
    {
        /* CAP_KYC_REVIEW, not CAP_SEND. Opening a verification session is part
           of reviewing somebody's identity; it has nothing to do with being
           allowed to despatch a contract to a customer. */
        $this->need($this->holds(Contract_caps::CAP_KYC_REVIEW));
        $this->requirePost();
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);
        $request  = $this->cv->latestRequestForContract((int) $contractId);

        if (!$request) {
            set_alert('warning', 'This contract has no signing request.');
            redirect($this->backToExecution($contractId));
        }

        $signerId = (int) $this->input->post('signer_id');
        $signer   = $this->cv->signerById($signerId, (int) $request['id']);

        if (!$signer) {
            set_alert('warning', 'That signer is not on this contract.');
            redirect($this->backToExecution($contractId));
        }

        $result = $this->cv->createKycSession($signer, new Unconfigured_kyc_provider(),
                                              $this->actor(), time());

        set_alert(empty($result['ok']) ? 'warning' : 'success', (string) $result['message']);

        redirect($this->backToExecution($contractId));
    }

    public function kyc_invite($contractId = 0)
    {
        $this->need($this->holds(Contract_caps::CAP_KYC_REVIEW));
        $this->requirePost();
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);

        $sessionId = (int) $this->input->post('kyc_session_id');
        $session   = $this->cv->kycSession($sessionId);

        if (!$session || (int) $session['contract_id'] !== (int) $contractId) {
            /* An id from another contract is a direct-object-reference attempt,
               and it gets a 404 rather than a message confirming it exists. */
            show_404();
        }

        $channel = (string) $this->input->post('channel');
        $channel = in_array($channel, array('email', 'sms'), true) ? $channel : 'email';

        $result = $this->cv->issueKycInvite($sessionId, $channel, $this->actor(), time());

        /*
         * The raw token is NOT put into the alert, the log or the session. It
         * exists for the outbound message and nowhere else — putting it on
         * screen would make it recoverable by anyone who can read a flash
         * message or a browser history.
         */
        set_alert(empty($result['ok']) ? 'warning' : 'success', (string) $result['message']);

        redirect($this->backToExecution($contractId));
    }

    public function kyc_retry($contractId = 0)
    {
        $this->need($this->holds(Contract_caps::CAP_KYC_RETRY));
        $this->requirePost();
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);

        $sessionId = (int) $this->input->post('kyc_session_id');
        $session   = $this->cv->kycSession($sessionId);

        if (!$session || (int) $session['contract_id'] !== (int) $contractId) { show_404(); }

        $provider = new Unconfigured_kyc_provider();
        $result   = $provider->retrySession((string) $session['session_reference'],
                                            (string) $this->input->post('reason'));

        set_alert('warning', (string) $result['message']);

        redirect($this->backToExecution($contractId));
    }

    public function kyc_manual_review($contractId = 0)
    {
        $this->need($this->holds(Contract_caps::CAP_KYC_REVIEW));
        $this->requirePost();
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);

        $sessionId = (int) $this->input->post('kyc_session_id');
        $session   = $this->cv->kycSession($sessionId);

        if (!$session || (int) $session['contract_id'] !== (int) $contractId) { show_404(); }

        $provider = new Unconfigured_kyc_provider();
        $result   = $provider->sendForManualReview((string) $session['session_reference'],
                                                   (string) $this->input->post('reason'));

        set_alert('warning', (string) $result['message']);

        redirect($this->backToExecution($contractId));
    }

    /* ---- local cancellation -------------------------------------------- */

    /**
     * Stop this contract here.
     *
     * The provider is NOT called. Its only withdraw-shaped operation permanently
     * deletes the document and its audit trail, so the provider's record is left
     * alone and allowed to expire on its own.
     */
    public function cancel_local($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_cancel'));
        $this->requirePost();
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);
        $reason   = trim((string) $this->input->post('reason'));

        if (strlen($reason) < 10) {
            set_alert('warning', 'Give a reason of at least ten characters for cancelling.');
            redirect($this->backToExecution($contractId));
        }

        $result = $this->cv->cancelRequest((int) $contractId, $reason, $this->actor(), time());

        set_alert(empty($result['ok']) ? 'warning' : 'success', (string) $result['message']);

        redirect($this->backToExecution($contractId));
    }

    /* ---- evidence downloads ---------------------------------------------
     *
     * `download_signed`, `download_certificate` and `download_identity` already
     * exist above and are unchanged. The panel links to those rather than to new
     * routes: two routes serving the same document would mean two places where
     * the evidence permission has to be right.
     * ------------------------------------------------------------------- */

    /**
     * The evidence package.
     *
     * Refuses while any part is missing rather than producing a partial bundle.
     * A package that quietly omits the audit trail is worse than no package: it
     * looks complete to whoever receives it.
     */
    public function download_evidence_package($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_view_evidence'));
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);
        $context  = $this->cv->completionContext((int) $contractId);
        $blockers = Contract_lifecycle::completionBlockers($context);

        if (count($blockers) > 0) {
            set_alert('warning',
                'The evidence package is not available yet: ' . $blockers[0]['message']
              . ' A partial package would look complete to whoever received it.');

            redirect($this->backToExecution($contractId));
        }

        set_alert('info',
            'Evidence package assembly is not wired yet. The signed agreement and audit trail can '
          . 'be downloaded individually.');

        redirect($this->backToExecution($contractId));
    }

    /* ---- failures -------------------------------------------------------- */

    public function failures($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_view'));
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);

        $this->load->view('payplex_contract_verification/failures', array(
            'title'       => 'Signing failures',
            'contract'    => $contract,
            'failures'    => $this->failureRows((int) $contractId),
            'back_url'    => $this->backToExecution($contractId),
            'can_retry'   => $this->holds(Contract_caps::CAP_SEND),
        ));
    }

    /* ================================================================
     * KYC case review, evidence and the client repository.
     *
     * Every route below asks Contract_authz. None of them re-implements an
     * access rule, and none of them writes a KYC outcome — that goes through
     * Kyc_decision_service, which refuses without a provider result, verified
     * evidence and a second pair of eyes.
     * ============================================================== */

    /**
     * The authorisation facts for the current actor, assembled once.
     *
     * Gathered here rather than inside Contract_authz so that the decision
     * functions stay pure: they take facts and return verdicts, which is what
     * makes them testable without a session, a database or a bootstrap.
     *
     * @param  array $contract
     * @return array
     */
    private function authzContext($contract)
    {
        $contractId = (int) (isset($contract['id']) ? $contract['id'] : 0);

        return array(
            'actor_id'          => $this->actor(),
            'contract_id'       => $contractId,
            'contract_owner_id' => (int) (isset($contract['addedfrom']) ? $contract['addedfrom'] : 0),
            'is_admin'          => $this->isAdminActor(),
            'has_view_all'      => function_exists('staff_can')
                                   ? staff_can('view', 'contracts') : false,
            'has_view_own'      => function_exists('staff_can')
                                   ? staff_can('view_own', 'contracts') : false,
            'assignments'       => $this->cv->assignmentsFor($contractId, $this->actor()),
            'held_capabilities' => $this->heldCapabilities(),
            'now'               => time(),
        );
    }

    /**
     * A KYC case, scoped to the contract in the URL.
     *
     * The session id arrives from a browser and is therefore untrusted. It is
     * matched against the contract in the SAME lookup rather than fetched and
     * checked afterwards, so a case belonging to another contract cannot be
     * loaded even for a moment.
     *
     * @param  int $contractId
     * @param  int $sessionId
     * @return array
     */
    private function kycCaseOr404($contractId, $sessionId)
    {
        $case = $this->cv->kycSessionForContract((int) $sessionId, (int) $contractId);

        if (!$case) { show_404(); }

        return $case;
    }

    /** Open one KYC case. Read-only. */
    public function kyc_case($contractId = 0)
    {
        $this->need($this->holds(Contract_caps::CAP_KYC_VIEW));
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);
        $case     = $this->kycCaseOr404($contractId, (int) $this->input->get('kyc_session_id'));

        $ctx = $this->authzContext($contract);

        $verdict = Contract_authz::canReviewKycCase(array_merge($ctx, array(
            'case'             => $case,
            'action'           => 'view',
            'holds_capability' => $this->holds(Contract_caps::CAP_KYC_VIEW),
        )));

        if (empty($verdict['allowed'])) {
            $this->cv->logEvidenceAccess($ctx, $case, Contract_evidence_types::KYC_SUMMARY,
                                         'view', 'refused', $verdict['reason']);
            show_404();
        }

        $this->cv->logEvidenceAccess($ctx, $case, Contract_evidence_types::KYC_SUMMARY,
                                     'view', 'allowed', null);

        $this->load->view('payplex_contract_verification/kyc_case',
                          $this->kycCaseData($contract, $case, $ctx));
    }

    /**
     * Record a KYC decision.
     *
     * The route validates nothing about identity itself. It gathers facts,
     * asks Contract_authz, and hands everything to Kyc_decision_service —
     * which is the only code in this module that may write a verification
     * outcome, and which refuses unless a provider produced the result and its
     * evidence hash verifies.
     */
    public function kyc_decide($contractId = 0)
    {
        $decision = (string) $this->input->post('decision');

        /* The capability follows the decision, so approve and reject are
           separately grantable — a reviewer trusted to fail a case is not
           automatically trusted to pass one. */
        $capability = Kyc_decision_service::capabilityFor($decision);

        if ($capability === null) {
            set_alert('warning', 'That is not a decision this module recognises.');
            redirect($this->backToExecution($contractId));
        }

        $this->need($this->holds($capability));
        $this->requirePost();
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);
        $case     = $this->kycCaseOr404($contractId, (int) $this->input->post('kyc_session_id'));
        $ctx      = $this->authzContext($contract);

        $authz = Contract_authz::canReviewKycCase(array_merge($ctx, array(
            'case'             => $case,
            'action'           => Kyc_decision_service::actionFor($decision),
            'holds_capability' => $this->holds($capability),
        )));

        $result = $this->cv->recordKycDecision($case, $decision, array(
            'actor_id'    => $this->actor(),
            'contract_id' => (int) $contractId,
            'authz'       => $authz,
            'reason'      => (string) $this->input->post('reason'),
            'now'         => time(),
        ));

        set_alert(empty($result['ok']) ? 'warning' : 'success', (string) $result['message']);

        redirect($this->backToExecution($contractId));
    }

    /**
     * View one piece of evidence, in the browser.
     *
     * Nothing here decides anything. The seven gates live in
     * Contract_authz::canAccessEvidenceType() and the refusal reason is logged
     * whichever way it goes, because a denied attempt to open a Video KYC
     * recording is at least as interesting as a successful one.
     */
    public function evidence_view($contractId = 0)
    {
        $this->requireSchema();

        $type     = (string) $this->input->get('type');
        $contract = $this->contractOr404($contractId);
        $ctx      = $this->authzContext($contract);
        $case     = $this->cv->kycSessionForContract((int) $this->input->get('kyc_session_id'),
                                                     (int) $contractId);
        $evidence = $this->cv->evidenceRow((int) $contractId, $type,
                                           (int) $this->input->get('kyc_session_id'));

        $verdict = Contract_authz::canAccessEvidenceType(array_merge($ctx, array(
            'evidence_type' => $type,
            'evidence'      => $evidence,
            'retention'     => $this->cv->retentionFor($contract, $type),
            'legal_hold'    => $this->cv->activeLegalHold($contract, $case),
            'reason'        => (string) $this->input->get('access_reason'),
        )));

        $this->cv->logEvidenceAccess($ctx, $case, $type, 'view',
                                     empty($verdict['allowed']) ? 'refused' : 'allowed',
                                     empty($verdict['allowed']) ? $verdict['reason'] : null);

        if (empty($verdict['allowed'])) {
            set_alert('warning', $this->evidenceRefusalMessage($verdict['reason']));
            redirect($this->backToExecution($contractId));
        }

        $this->load->view('payplex_contract_verification/evidence_view', array(
            'title'         => 'Evidence',
            'contract'      => $contract,
            'evidence_type' => $type,
            'meta'          => Contract_evidence_types::catalogue(),
            'evidence'      => $this->cv->evidenceSummary($evidence),
            'watermark'     => $this->cv->watermarkFor($this->actor(), time()),
            'back_url'      => $this->backToExecution($contractId),
        ));
    }

    /**
     * Stream a Video KYC recording.
     *
     * Separate from evidence_view because a recording is not a page: it is a
     * byte stream that a browser will happily cache, a proxy will happily
     * store, and a URL will happily outlive the permission that produced it.
     * So the physical path never reaches the client, the response carries
     * no-store headers, the hash is verified before a single byte is written,
     * and the start and the completion are logged separately — a stream that
     * starts and never completes is a different event from one that finishes.
     */
    public function evidence_stream($contractId = 0)
    {
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);
        $ctx      = $this->authzContext($contract);
        $sessionId = (int) $this->input->get('kyc_session_id');
        $case     = $this->cv->kycSessionForContract($sessionId, (int) $contractId);
        $type     = Contract_evidence_types::VIDEO_RECORDING;
        $evidence = $this->cv->evidenceRow((int) $contractId, $type, $sessionId);

        $verdict = Contract_authz::canAccessEvidenceType(array_merge($ctx, array(
            'evidence_type' => $type,
            'evidence'      => $evidence,
            'retention'     => $this->cv->retentionFor($contract, $type),
            'legal_hold'    => $this->cv->activeLegalHold($contract, $case),
            'reason'        => (string) $this->input->get('access_reason'),
        )));

        $logId = $this->cv->logEvidenceAccess($ctx, $case, $type, 'stream_start',
                                              empty($verdict['allowed']) ? 'refused' : 'allowed',
                                              empty($verdict['allowed']) ? $verdict['reason'] : null);

        if (empty($verdict['allowed'])) { show_404(); }

        $sent = $this->cv->streamEvidence($evidence, Evidence_store::streamingHeaders());

        $this->cv->completeEvidenceAccess($logId, $sent);
    }

    /**
     * Download a piece of evidence.
     *
     * A download is a view plus a permanent copy on somebody's laptop, so it
     * asks a second, separate question. The Video KYC recording is refused here
     * by the type rule regardless of capability, until a lawful basis exists.
     */
    public function evidence_download($contractId = 0)
    {
        $this->requirePost();
        $this->requireSchema();

        $type      = (string) $this->input->post('type');
        $contract  = $this->contractOr404($contractId);
        $ctx       = $this->authzContext($contract);
        $sessionId = (int) $this->input->post('kyc_session_id');
        $case      = $this->cv->kycSessionForContract($sessionId, (int) $contractId);
        $evidence  = $this->cv->evidenceRow((int) $contractId, $type, $sessionId);

        $verdict = Contract_authz::canDownloadEvidenceType(array_merge($ctx, array(
            'evidence_type' => $type,
            'evidence'      => $evidence,
            'retention'     => $this->cv->retentionFor($contract, $type),
            'legal_hold'    => $this->cv->activeLegalHold($contract, $case),
            'reason'        => (string) $this->input->post('access_reason'),
            'compliance'    => $this->cv->complianceApprovals(),
        )));

        $this->cv->logEvidenceAccess($ctx, $case, $type, 'download',
                                     empty($verdict['allowed']) ? 'refused' : 'allowed',
                                     empty($verdict['allowed']) ? $verdict['reason'] : null);

        if (empty($verdict['allowed'])) {
            set_alert('warning', $this->evidenceRefusalMessage($verdict['reason']));
            redirect($this->backToExecution($contractId));
        }

        $this->cv->streamEvidence($evidence, array_merge(Evidence_store::streamingHeaders(),
            array('Content-Disposition' => 'attachment')));
    }

    /* ---- retention and legal holds ------------------------------------ */

    public function retention_rules()
    {
        $this->need($this->holds(Contract_caps::CAP_KYC_MANAGE_RETENTION));
        $this->requireSchema();

        $this->load->view('payplex_contract_verification/retention_rules', array(
            'title'        => 'KYC evidence retention',
            'rules'        => $this->cv->retentionRules(),
            'holds'        => $this->cv->legalHolds(),
            'evidence_types' => Contract_evidence_types::catalogue(),
            'can_apply'    => $this->holds(Contract_caps::CAP_KYC_APPLY_HOLD),
            'can_release'  => $this->holds(Contract_caps::CAP_KYC_RELEASE_HOLD),
        ));
    }

    public function retention_save()
    {
        $this->need($this->holds(Contract_caps::CAP_KYC_MANAGE_RETENTION));
        $this->requirePost();
        $this->requireSchema();

        $proposed = array(
            'scope'             => (string) $this->input->post('scope'),
            'scope_id'          => (int) $this->input->post('scope_id'),
            'evidence_type'     => (string) $this->input->post('evidence_type'),
            'retention_days'    => (int) $this->input->post('retention_days'),
            'retain_recordings' => $this->input->post('store_raw_media') ? 1 : 0,
            'lawful_basis'      => (string) $this->input->post('lawful_basis'),
        );

        $verdict = Contract_authz::canChangeRetention(array(
            'actor_id'         => $this->actor(),
            'holds_capability' => $this->holds(Contract_caps::CAP_KYC_MANAGE_RETENTION),
            'is_admin'         => $this->isAdminActor(),
            'legal_hold'       => $this->cv->activeLegalHoldForScope($proposed),
            'current'          => $this->cv->retentionRuleForScope($proposed),
            'proposed'         => $proposed,
        ));

        $result = $this->cv->saveRetentionRule($proposed, $verdict, $this->actor(), time());

        set_alert(empty($result['ok']) ? 'warning' : 'success', (string) $result['message']);

        redirect(admin_url('payplex_contract_verification/signing/retention_rules'));
    }

    public function legal_hold_apply()
    {
        $this->need($this->holds(Contract_caps::CAP_KYC_APPLY_HOLD));
        $this->requirePost();
        $this->requireSchema();

        $result = $this->cv->applyLegalHold($this->holdInput(), Contract_authz::canChangeLegalHold(array(
            'actor_id'      => $this->actor(),
            'action'        => 'apply',
            'holds_apply'   => $this->holds(Contract_caps::CAP_KYC_APPLY_HOLD),
            'holds_release' => $this->holds(Contract_caps::CAP_KYC_RELEASE_HOLD),
            'is_admin'      => $this->isAdminActor(),
            'hold'          => $this->cv->activeLegalHoldForScope($this->holdInput()),
        )), $this->actor(), time());

        set_alert(empty($result['ok']) ? 'warning' : 'success', (string) $result['message']);

        redirect(admin_url('payplex_contract_verification/signing/retention_rules'));
    }

    public function legal_hold_release()
    {
        $this->need($this->holds(Contract_caps::CAP_KYC_RELEASE_HOLD));
        $this->requirePost();
        $this->requireSchema();

        $hold = $this->cv->legalHold((int) $this->input->post('hold_id'));

        $result = $this->cv->releaseLegalHold($hold, Contract_authz::canChangeLegalHold(array(
            'actor_id'      => $this->actor(),
            'action'        => 'release',
            'holds_apply'   => $this->holds(Contract_caps::CAP_KYC_APPLY_HOLD),
            'holds_release' => $this->holds(Contract_caps::CAP_KYC_RELEASE_HOLD),
            'is_admin'      => $this->isAdminActor(),
            'hold'          => $hold,
        )), (string) $this->input->post('reason'), $this->actor(), time());

        set_alert(empty($result['ok']) ? 'warning' : 'success', (string) $result['message']);

        redirect(admin_url('payplex_contract_verification/signing/retention_rules'));
    }

    private function holdInput()
    {
        return array(
            'scope'          => (string) $this->input->post('scope'),
            'scope_id'       => (int) $this->input->post('scope_id'),
            'client_id'      => (int) $this->input->post('client_id'),
            'contract_id'    => (int) $this->input->post('contract_id'),
            'kyc_session_id' => (int) $this->input->post('kyc_session_id'),
            'reference'      => (string) $this->input->post('reference'),
            'reason'         => (string) $this->input->post('reason'),
        );
    }

    /* ---- assignments --------------------------------------------------- */

    public function assignments($contractId = 0)
    {
        $this->need($this->holds(Contract_caps::CAP_SETTINGS));
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);

        $this->load->view('payplex_contract_verification/assignments', array(
            'title'       => 'Contract assignments',
            'contract'    => $contract,
            'assignments' => $this->cv->assignmentRows((int) $contractId),
            'roles'       => Contract_assignment::roles(),
            'back_url'    => $this->backToExecution($contractId),
        ));
    }

    public function assignment_save($contractId = 0)
    {
        $this->need($this->holds(Contract_caps::CAP_SETTINGS));
        $this->requirePost();
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);

        $result = $this->cv->saveAssignment((int) $contractId, array(
            'staff_id'        => (int) $this->input->post('staff_id'),
            'assignment_role' => (string) $this->input->post('assignment_role'),
            'expires_at'      => (int) $this->input->post('expires_at'),
            'note'            => (string) $this->input->post('note'),
        ), $this->actor(), time());

        set_alert(empty($result['ok']) ? 'warning' : 'success', (string) $result['message']);

        redirect(admin_url('payplex_contract_verification/signing/assignments/' . (int) $contractId));
    }

    /**
     * Revoke an assignment.
     *
     * Sets `revoked_at`, `revoked_by` and a reason. It does not delete the row,
     * and the model has no path that does — "who could see this customer's
     * identity documents in March" is asked after something has gone wrong, and
     * a deleted row answers it with silence.
     */
    public function assignment_revoke($contractId = 0)
    {
        $this->need($this->holds(Contract_caps::CAP_SETTINGS));
        $this->requirePost();
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);

        $result = $this->cv->revokeAssignment((int) $this->input->post('assignment_id'),
                                              (int) $contractId,
                                              (string) $this->input->post('reason'),
                                              $this->actor(), time());

        set_alert(empty($result['ok']) ? 'warning' : 'success', (string) $result['message']);

        redirect(admin_url('payplex_contract_verification/signing/assignments/' . (int) $contractId));
    }

    /* ---- the client-wise repository ------------------------------------ */

    /**
     * The KYC & Video KYC tab on a customer profile.
     *
     * Walks Client → Contact/Signer → KYC case → Contract → Evidence. Each
     * evidence row is filtered by what this actor may actually see, so the tab
     * does not advertise the existence of a recording to somebody who may not
     * watch it: listing it and then refusing on click discloses that it exists,
     * which for a Video KYC recording is itself information about the customer.
     */
    public function client_kyc($clientId = 0)
    {
        $this->need($this->holds(Contract_caps::CAP_KYC_VIEW));
        $this->requireSchema();

        $clientId = (int) $clientId;

        if ($clientId <= 0) { show_404(); }

        $this->load->view('payplex_contract_verification/client_kyc', array(
            'title'       => 'KYC & Video KYC',
            'client_id'   => $clientId,
            'client_name' => $this->cv->clientName($clientId),
            'cases'       => $this->cv->clientKycCases($clientId, $this->heldCapabilities(),
                                                       $this->actor(), $this->isAdminActor(), time()),
            'types'       => Contract_evidence_types::catalogue(),
        ));
    }

    /**
     * What the KYC case screen needs.
     *
     * Evidence types are filtered to what this actor may see, for the same
     * reason the client tab filters them: listing a Video KYC recording and
     * refusing on click still tells the reader that a recording exists, and for
     * a recording that is itself information about the customer.
     *
     * @param  array $contract
     * @param  array $case
     * @param  array $ctx
     * @return array
     */
    private function kycCaseData($contract, $case, array $ctx)
    {
        $held      = isset($ctx['held_capabilities']) ? $ctx['held_capabilities'] : array();
        $visible   = array();

        foreach (Contract_evidence_types::catalogue() as $type => $meta) {
            if ($type === Contract_evidence_types::FULL_PACKAGE)          { continue; }
            if (!in_array($meta['view_capability'], $held, true))         { continue; }
            $visible[$type] = $meta;
        }

        return array(
            'title'            => 'KYC case',
            'contract'         => $contract,
            'case'             => $this->cv->kycCaseSummary($case),
            'decisions'        => $this->cv->kycDecisionHistory((int) $case['id']),
            'visible_evidence' => $visible,
            'available'        => $this->cv->evidenceAvailability((int) $contract['id'],
                                                                  (int) $case['id'],
                                                                  array_keys($visible)),
            'can_approve'      => $this->holds(Contract_caps::CAP_KYC_APPROVE),
            'can_reject'       => $this->holds(Contract_caps::CAP_KYC_REJECT),
            'can_retry'        => $this->holds(Contract_caps::CAP_KYC_RETRY),
            'can_video'        => $this->holds(Contract_caps::CAP_KYC_VIEW_VIDEO),
            'back_url'         => $this->backToExecution((int) $contract['id']),
        );
    }

    /**
     * Turn a refusal reason into something a person can act on.
     *
     * Deliberately vague about WHY for the access reasons and precise about the
     * ones that are the reader's own problem. "You do not hold the capability"
     * is useful; "this contract has a Video KYC recording but you may not see
     * it" is a disclosure.
     *
     * @param  string $reason
     * @return string
     */
    private function evidenceRefusalMessage($reason)
    {
        $plain = array(
            'access_reason_required'    => 'This kind of evidence needs a stated reason of at '
                                         . 'least ten characters, and the reason is recorded.',
            'retention_period_expired'  => 'The retention period for this evidence has passed and '
                                         . 'it is no longer available to anybody, including '
                                         . 'administrators.',
            'evidence_not_stored'       => 'There is nothing stored for this evidence type.',
            'evidence_hash_did_not_verify' =>
                'The stored file does not match its recorded hash, so it will not be served. '
                . 'This is reported rather than repaired.',
            'recording_download_needs_compliance_approval' =>
                'Downloading a Video KYC recording needs an approved lawful basis and retention '
                . 'period. Viewing may still be possible.',
        );

        if (isset($plain[$reason])) { return $plain[$reason]; }

        if (strpos($reason, 'missing_capability:') === 0
            || strpos($reason, 'missing_evidence_capability:') === 0
            || strpos($reason, 'missing_download_capability:') === 0) {
            return 'You do not hold the permission this evidence type needs.';
        }

        return 'That evidence is not available to you.';
    }

    public function failure_resolve($contractId = 0)
    {
        $this->need(has_permission('payplex_contract_verification', '', 'contract_signing_send'));
        $this->requirePost();
        $this->requireSchema();

        $contract = $this->contractOr404($contractId);
        $note     = trim((string) $this->input->post('resolution'));

        if (strlen($note) < 10) {
            set_alert('warning', 'Say in at least ten characters how this was resolved.');
            redirect(admin_url('payplex_contract_verification/signing/failures/' . (int) $contractId));
        }

        $this->cv->resolveFailure((int) $this->input->post('failure_id'),
                                  $this->actor(), $note, time());

        set_alert('success', 'Failure marked resolved.');

        redirect(admin_url('payplex_contract_verification/signing/failures/' . (int) $contractId));
    }

    private function firstBlocker(array $blockers)
    {
        return count($blockers) > 0
            ? (string) $blockers[0]['message']
            : 'This contract is not ready to send.';
    }

}
