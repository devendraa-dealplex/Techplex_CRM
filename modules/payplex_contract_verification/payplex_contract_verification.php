<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Payplex Contract Verification
Description: Contract verification, identity evidence and e-signature execution. Provider-independent foundation; the Leegality adapter is not yet implemented.
Version: 0.1.0
Requires at least: 2.3.*
*/

define('PAYPLEX_CV_MODULE', 'payplex_contract_verification');

require_once __DIR__ . '/libraries/Contract_caps.php';
/* The customer-profile tab fragment runs inside Perfex's own Clients
   controller, which requires none of this module's libraries. Loading them
   here is what makes the tab work at all — and the fatal that taught us was
   invisible to every source-level test, because the tests require the classes
   themselves. */
require_once __DIR__ . '/libraries/Contract_evidence_types.php';

/**
 * Payplex Contract Verification.
 *
 * WHAT THIS MODULE IS TODAY
 * -------------------------
 * The complete provider-independent half of a contract e-signature integration:
 * the state machine, the PDF coordinate mapping, the webhook hardening, the
 * settings and credential handling, the permission split, the evidence rules
 * and the schema. Everything that is hard to retrofit correctly.
 *
 * The provider adapter itself is NOT implemented. The Leegality API
 * documentation for this account has not been obtained, and endpoints, field
 * names and the webhook signature scheme are not guessed. `Leegality_provider`
 * therefore refuses every call with a named reason, and this module changes
 * nothing about how Perfex behaves until that file is completed.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It does not modify a single Perfex core file or core table. It reads
 * `tblcontracts` and writes only to its own `payplex_cv_*` tables. It sends no
 * email, no SMS and no outbound request of any kind — there is nowhere for a
 * request to go.
 */

hooks()->add_action('admin_init', 'payplex_cv_capabilities');
hooks()->add_action('admin_init', 'payplex_cv_menu');

/**
 * The eight capabilities, written out as a literal map.
 *
 * Not passed as `Contract_caps::enforced()`, deliberately: a permission list
 * that only a running process can enumerate is a permission list nobody
 * reviews, and CI's capability scanner cannot resolve a variable either. The
 * test suite asserts this literal is identical to the class, so the duplication
 * cannot drift.
 */
function payplex_cv_capabilities()
{
    if (!function_exists('register_staff_capabilities')) { return; }

    register_staff_capabilities(PAYPLEX_CV_MODULE, array('capabilities' => array(
        'contract_signing_view' =>
            'Open the contract signing screen and see the status of a request',
        'contract_signing_prepare' =>
            'Prepare a contract for signing: place signature fields, record verification, '
            . 'and submit it for internal approval',
        'contract_signing_approve' =>
            'Approve a prepared contract for signing. Cannot be used on your own submission',
        'contract_signing_send' =>
            'Send an approved contract to the signing provider and issue signing links',
        'contract_signing_cancel' =>
            'Cancel a live signing request, with a stated reason',
        'contract_signing_view_evidence' =>
            'View the signer timeline and download the signed agreement and completion certificate',
        'contract_signing_download_identity' =>
            'Download identity verification documents. Grant this to as few people as possible',
        'contract_signing_settings' =>
            'Configure the signing provider connection. Administrator only in practice',

        /*
         * The eleven KYC capabilities.
         *
         * Split out because the KYC routes used to be gated by
         * `contract_signing_send`, which meant the one role that exists to look
         * at somebody's identity documents and decide could also despatch
         * contracts to customers. Sending and verifying were done by the same
         * person, so nobody noticed; that is a coincidence, not a permission
         * model.
         */
        'contract_kyc_view' =>
            'See the status of a KYC case: state, attempts, decision and dates. '
            . 'No documents, no images, no recording',
        'contract_kyc_review' =>
            'Open an assigned KYC case and inspect the verification results in order to decide it',
        'contract_kyc_approve' =>
            'Record a KYC case as passed. Cannot be used on a case you created or invited',
        'contract_kyc_reject' =>
            'Record a KYC case as failed, with a stated reason',
        'contract_kyc_retry' =>
            'Allow a signer another verification attempt after a failure',
        'contract_kyc_view_documents' =>
            'View identity documents such as a PAN card or passport scan. '
            . 'Grant this to as few people as possible',
        'contract_kyc_view_video' =>
            'Watch a Video KYC recording. Biometric personal data — the most sensitive '
            . 'thing this module holds. Viewing only; downloading is separate',
        'contract_kyc_download_evidence' =>
            'Take a copy of KYC evidence away from this system. A downloaded file is one '
            . 'this CRM can never account for again',
        'contract_kyc_manage_retention' =>
            'Change how long KYC evidence is kept. Cannot shorten retention while a legal hold stands',
        'contract_kyc_apply_legal_hold' =>
            'Place a legal hold that stops evidence being deleted',
        'contract_kyc_release_legal_hold' =>
            'Lift a legal hold. Deliberately separate from applying one',
    )), 'Contract Verification');
}

/**
 * The KYC & Video KYC tab on the customer profile.
 *
 * Registered only for somebody who holds `contract_kyc_view`. A tab that
 * appears for everyone and then shows an empty table has already told the
 * reader that this customer's verification data exists somewhere; not drawing
 * the tab at all is the quieter answer.
 *
 * Every guard is defensive. This runs inside the CRM's own bootstrap, and a
 * core upgrade that renames the tabs library must degrade to "no tab" rather
 * than to a fatal error on every admin page in the product.
 */
function payplex_cv_customer_tab()
{
    if (!function_exists('get_instance'))            { return; }
    if (!payplex_cv_can('contract_kyc_view'))        { return; }

    $CI = &get_instance();

    if (!isset($CI->app_tabs))                                              { return; }
    if (!method_exists($CI->app_tabs, 'add_customer_profile_tab'))          { return; }

    $CI->app_tabs->add_customer_profile_tab('payplex_cv_kyc', array(
        'name'     => 'KYC & Video KYC',
        'icon'     => 'fa fa-id-card-o',
        'view'     => 'payplex_contract_verification/client_kyc_tab',
        'position' => 60,
    ));
}

function payplex_cv_can($cap)
{
    if (function_exists('is_admin') && is_admin()) { return true; }
    if (!function_exists('has_permission')) { return false; }

    return has_permission(PAYPLEX_CV_MODULE, '', $cap);
}

/**
 * The sidebar entry, gated on exactly the capability the landing screen checks.
 *
 * Not one term wider. A navigation item is a statement about what somebody may
 * do, and a menu that promises a screen the controller then denies is the
 * defect this programme has already fixed twice in other modules.
 */
function payplex_cv_menu()
{
    if (!payplex_cv_can('contract_signing_settings')) { return; }

    $ci = &get_instance();

    $ci->app_menu->add_setup_menu_item('payplex-contract-verification', array(
        'name'     => 'Contract Signing',
        'href'     => admin_url('payplex_contract_verification/signing/settings'),
        'position' => 36,
    ));
}

/**
 * Reconcile pending signing requests on the CRM's own cron.
 *
 * WHY RECONCILIATION EXISTS AT ALL
 * --------------------------------
 * A webhook is a prompt, never an authority. Deliveries are lost, delayed,
 * duplicated and reordered by every provider that has them, so a contract whose
 * status depends only on webhooks will eventually be wrong and nothing will
 * notice. This job asks the provider directly for anything that has not been
 * checked recently.
 *
 * It is on `after_cron_run` rather than a page-load hook for the same reason as
 * every other job in this estate: `admin_init` would run it inside a request
 * somebody is waiting on, and never during a quiet period.
 *
 * Today it does nothing, because the adapter refuses and it has nothing to ask.
 * It is registered now so that the wiring is proven before it matters.
 */
hooks()->add_action('after_cron_run', 'payplex_cv_reconcile_cron');

function payplex_cv_reconcile_cron()
{
    $CI = &get_instance();
    $CI->load->model(PAYPLEX_CV_MODULE . '/contract_verification_model', 'cv');

    if (!$CI->cv->schemaReady()) { return; }

    try {
        $pending = $CI->cv->pendingReconciliation(25, 900);

        foreach ($pending as $req) {
            /* One at a time, bounded, and each marked synced whatever the
               outcome — otherwise a request the provider cannot answer about is
               retried on every tick and consumes the rate limit that the ones
               which CAN be answered need. */
            $CI->cv->syncStatus((int) $req['contract_id'], 0, time());
        }
    } catch (Throwable $e) {
        /*
         * Throwable, not Exception. A fatal here would take down
         * `after_cron_run` and every module hooked after it — which is how
         * another module in this estate once broke the whole cron chain by
         * catching the narrower type.
         */
        $CI->cv->audit(0, 'cv_reconcile_failed', null, null,
                       array('message' => substr($e->getMessage(), 0, 180)));
    }
}

/**
 * A link to this module's panel, from the contract the panel is about.
 *
 * WHY THIS HOOK
 * -------------
 * `after_contract_view_as_client_link` is the only action Perfex exposes inside
 * the contract view. It sits between two <li> elements of the contract's own
 * actions dropdown, so the callback emits list items, and it is handed the
 * contract object rather than an id.
 *
 * Until this existed the module had no entry point at all. Every screen worked
 * and nothing linked to any of them, so the panel was reachable only by typing
 * a URL -- which is indistinguishable, to anyone using the CRM, from the feature
 * not being there.
 *
 * Labels are literals, not _l() keys: this module ships no language directory
 * and never calls register_language_files(), so _l() would print the key.
 *
 * THE LINK IS NOT THE CONTROL
 * ---------------------------
 * These checks decide what to SHOW. The identical rules are enforced again in
 * Signing::needContractAccess(), because hiding a menu item protects nothing --
 * the URL can still be typed. If the two ever disagree the controller wins, and
 * the caller gets a refusal rather than a page.
 */
function payplex_cv_contract_action_links($contract)
{
    if (!is_object($contract) || !isset($contract->id)) { return; }

    $contractId = (int) $contract->id;

    if ($contractId <= 0) { return; }

    /* Perfex's rule for who may look at a contract, mirrored from
       Contracts::contract(): hold `view`, or hold `view_own` and own this one. */
    if (!is_admin()) {
        if (staff_cant('view', 'contracts') && staff_cant('view_own', 'contracts')) { return; }

        if (staff_cant('view', 'contracts')
            && (!isset($contract->addedfrom)
                || (int) $contract->addedfrom !== (int) get_staff_user_id())) { return; }

        if (!has_permission('payplex_contract_verification', '', 'contract_signing_view')) { return; }
    }

    $panel     = admin_url('payplex_contract_verification/signing/contract/' . $contractId);
    $execution = admin_url('payplex_contract_verification/signing/execution/' . $contractId);
    $timeline  = admin_url('payplex_contract_verification/signing/signer_timeline/' . $contractId);

    echo '<li><a href="' . $panel . '">Contract Verification</a></li>';
    /*
     * The execution panel is where the lifecycle, the workflow mapping, the
     * verification sessions and the completion blockers live. It is gated by the
     * same two checks as the panel above -- the module capability and Perfex's
     * own contract visibility -- and the controller re-checks both, because a
     * hidden link protects nothing against a typed URL.
     */
    echo '<li><a href="' . $execution . '">Execution</a></li>';
    echo '<li><a href="' . $timeline . '">Signing evidence timeline</a></li>';
}

hooks()->add_action('after_contract_view_as_client_link', 'payplex_cv_contract_action_links');

/**
 * Contribute "2 Invite" and "3 Finalize" to the contract edit page's stage
 * row, alongside the core "1 Create" stage it always shows for itself.
 *
 * Same visibility rule as payplex_cv_contract_action_links(), for the same
 * reason: a stage link is not the control, Signing::signers()/finalize()
 * re-check contract access and the module capability independently, so
 * hiding this changes what's shown, never what's allowed.
 *
 * @param  array  $stages   stages already contributed by other modules
 * @param  object $contract
 * @return array
 */
function payplex_cv_contract_workflow_stages($stages, $contract)
{
    if (!is_object($contract) || !isset($contract->id)) { return $stages; }

    $contractId = (int) $contract->id;

    if ($contractId <= 0) { return $stages; }

    if (!is_admin()) {
        if (staff_cant('view', 'contracts') && staff_cant('view_own', 'contracts')) { return $stages; }

        if (staff_cant('view', 'contracts')
            && (!isset($contract->addedfrom)
                || (int) $contract->addedfrom !== (int) get_staff_user_id())) { return $stages; }

        if (!has_permission('payplex_contract_verification', '', 'contract_signing_view')) { return $stages; }
    }

    $stages[] = array(
        'key'   => 'invite',
        'label' => '2 Invite',
        'url'   => admin_url('payplex_contract_verification/signing/signers/' . $contractId),
    );
    $stages[] = array(
        'key'   => 'finalize',
        'label' => '3 Finalize',
        'url'   => admin_url('payplex_contract_verification/signing/finalize/' . $contractId),
    );

    return $stages;
}

hooks()->add_filter('contract_workflow_stages', 'payplex_cv_contract_workflow_stages', 10, 2);

/**
 * A contract with a live signing request is signed only through each signer's
 * own link. The stock one-click "Sign" on the client contract page would mark
 * the whole contract signed by a single person and skip everyone else.
 */
function payplex_cv_legacy_signing_allowed($allowed, $contractId)
{
    if (!$allowed) { return false; }

    $ci = &get_instance();
    $ci->load->model('payplex_contract_verification/contract_verification_model', 'cv');

    $request = $ci->cv->latestRequestForContract((int) $contractId);

    if (!$request) { return true; }

    return in_array((string) $request['state'], array('cancelled', 'expired', 'declined', 'failed'), true);
}

hooks()->add_filter('contract_legacy_signing_allowed', 'payplex_cv_legacy_signing_allowed', 10, 2);

/**
 * When the contract has an uploaded PDF, that PDF is the document: return it
 * with every completed signer's signature stamped where its field was placed.
 * Returning null leaves the standard generated PDF in place.
 *
 * @param  mixed  $custom
 * @param  object $contract
 * @return mixed
 */
function payplex_cv_contract_pdf_document($custom, $contract)
{
    if ($custom !== null || !is_object($contract) || empty($contract->id)) { return $custom; }

    require_once __DIR__ . '/libraries/Contract_main_document.php';

    $path = Contract_main_document::path($contract);

    if ($path === null) { return $custom; }

    $ci = &get_instance();
    $ci->load->model('payplex_contract_verification/contract_verification_model', 'cv');

    $data = $ci->cv->stampData((int) $contract->id);

    return Contract_main_document::render($path, $data['signers'], $data['fields']);
}

hooks()->add_filter('contract_pdf_custom_document', 'payplex_cv_contract_pdf_document', 10, 2);

/**
 * Who signed, who's left -- one line, right under the stage row on the
 * contract dashboard, so a staff member sees it without leaving the page.
 *
 * Same visibility rule as the stage row it sits under: nothing here is the
 * access control, Signing::signers() re-checks independently.
 *
 * @param object $contract
 */
function payplex_cv_contract_signer_summary($contract)
{
    if (!is_object($contract) || empty($contract->id)) { return; }

    if (!is_admin()) {
        if (staff_cant('view', 'contracts') && staff_cant('view_own', 'contracts')) { return; }
        if (staff_cant('view', 'contracts')
            && (!isset($contract->addedfrom)
                || (int) $contract->addedfrom !== (int) get_staff_user_id())) { return; }
        if (!has_permission('payplex_contract_verification', '', 'contract_signing_view')) { return; }
    }

    $ci = &get_instance();
    $ci->load->model('payplex_contract_verification/contract_verification_model', 'cv');

    $request = $ci->cv->latestRequestForContract((int) $contract->id);

    if (!$request) { return; }

    $signers = $ci->cv->signers((int) $request['id']);

    if (empty($signers)) { return; }

    $signed    = array();
    $remaining = array();

    foreach ($signers as $s) {
        if ((string) $s['role'] === 'reviewer') { continue; }

        $label = e((string) $s['full_name']);

        if ((int) $s['completed_at'] > 0) {
            $signed[] = $label;
        } else {
            $remaining[] = $label;
        }
    }

    if (empty($signed) && empty($remaining)) { return; }

    if ((int) $contract->signed === 1 && empty($signed)) {
        $by = trim((string) (isset($contract->acceptance_firstname) ? $contract->acceptance_firstname : '') . ' '
                 . (string) (isset($contract->acceptance_lastname) ? $contract->acceptance_lastname : ''));
        echo '<div class="alert alert-warning">This contract is marked signed, but no signer has signed through '
           . 'the signing links. It was signed outside this workflow'
           . ($by !== '' ? ' by ' . e($by) : '')
           . (!empty($contract->acceptance_date) ? ' on ' . e($contract->acceptance_date) : '')
           . '. Use More &rarr; Clear signature to reset it.</div>';
    }

    echo '<div class="mbot15">';
    echo '<span class="label label-success" data-toggle="tooltip" title="' . e(implode(', ', $signed)) . '">'
       . '<i class="fa fa-check"></i> Signed: ' . count($signed) . '/' . (count($signed) + count($remaining))
       . '</span> ';

    if (!empty($signed)) {
        echo '<span class="text-muted small">' . implode(', ', $signed) . '</span>';
    }

    if (!empty($remaining)) {
        echo ' &nbsp; <span class="label label-warning"><i class="fa fa-clock-o"></i> Remaining: '
           . count($remaining) . '</span> ';
        echo '<span class="text-muted small">' . implode(', ', $remaining) . '</span>';
    }

    echo '</div>';
}

hooks()->add_action('after_contract_workflow_stages', 'payplex_cv_contract_signer_summary');

/**
 * Stamp EVERY completed native signer's signature into the contract PDF,
 * not just the single legacy one PDF_Signature::process_signature() draws
 * from tblcontracts.signature/acceptance_*.
 *
 * WHY A HOOK, NOT AN EDIT TO PDF_Signature.php
 * ---------------------------------------------
 * The module rule elsewhere in this file is: no core file touched. TCPDF's
 * own Close() already calls process_signature() through a filter
 * (`process_pdf_signature_on_close`) and then fires a `pdf_close` action on
 * the SAME $pdf instance before finishing the document — that is the one
 * shared document layer this contract's PDF has. Suppressing the core
 * single-signer block and drawing our own multi-signer block on that same
 * instance keeps every signature on one document, appended in signing
 * order, with no per-signer copy of the PDF ever created.
 *
 * @param  bool $default
 * @return bool
 */
function payplex_cv_suppress_legacy_pdf_signature($default)
{
    if (empty($GLOBALS['contract_pdf']) || empty($GLOBALS['contract_pdf']->id)) {
        return $default;
    }

    $ci = &get_instance();
    $ci->load->model('payplex_contract_verification/contract_verification_model', 'cv');

    $request = $ci->cv->latestRequestForContract((int) $GLOBALS['contract_pdf']->id);

    if (!$request) {
        return $default;
    }

    $signed = $ci->cv->getCompletedSigners((int) $request['id']);

    return empty($signed) ? $default : false;
}

hooks()->add_filter('process_pdf_signature_on_close', 'payplex_cv_suppress_legacy_pdf_signature', 10, 1);

/**
 * Draw every completed signer's captured signature, in signing order, onto
 * the PDF instance that is about to close. Runs after
 * payplex_cv_suppress_legacy_pdf_signature() has stopped the single-signer
 * legacy block, so this is additive, not duplicate.
 *
 * @param array $data {pdf_instance, type}
 */
function payplex_cv_stamp_all_signatures($data)
{
    $pdf = isset($data['pdf_instance']) ? $data['pdf_instance'] : null;

    if (!$pdf || (isset($data['type']) ? $data['type'] : '') !== 'contract') { return; }
    if (empty($GLOBALS['contract_pdf']) || empty($GLOBALS['contract_pdf']->id)) { return; }

    $contractId = (int) $GLOBALS['contract_pdf']->id;

    $ci = &get_instance();
    $ci->load->model('payplex_contract_verification/contract_verification_model', 'cv');

    $request = $ci->cv->latestRequestForContract($contractId);
    if (!$request) { return; }

    $signers = $ci->cv->getCompletedSigners((int) $request['id']);
    if (empty($signers)) { return; }

    // signing_order, not completion time -- the printed document lists
    // signers in the order the contract assigned them, regardless of which
    // one happened to finish last.
    usort($signers, function ($a, $b) {
        return (int) $a['signing_order'] <=> (int) $b['signing_order'];
    });

    $dir       = CONTRACTS_UPLOADS_FOLDER . $contractId . '/';
    $dims      = $pdf->getPageDimensions();
    $width     = $dims['wk'] - ($dims['rm'] + $dims['lm']);

    $pdf->Ln(10);

    foreach ($signers as $signer) {
        $path = $dir . (string) $signer['signature_image'];

        if (!is_file($path)) { continue; }

        $imageData = base64_encode(file_get_contents($path));

        $html  = '<div nobr="true"><span style="font-weight:bold;">'
               . e((string) $signer['full_name']) . '</span> ('
               . e(ucfirst((string) $signer['role'])) . ')<br />'
               . _l('contract_signed_date') . ': ' . _dt(date('Y-m-d H:i:s', (int) $signer['completed_at'])) . '<br />'
               . '<img src="@' . $imageData . '" width="180" />'
               . '</div><br />';

        $pdf->MultiCell($width, 0, $html, 0, 'L', 0, 1, '', '', true, 0, true, false, 0);
    }
}

hooks()->add_action('pdf_close', 'payplex_cv_stamp_all_signatures');

/**
 * Refuse to let the stock Delete button destroy verification evidence.
 *
 * WHY THIS EXISTS
 * ----------------
 * Contracts_model::delete() is a hard delete with no concept of this module.
 * Deleting a contract with an active legal hold, any signing request -- sent,
 * in progress or completed -- or any recorded KYC decision would silently
 * orphan or destroy exactly the evidence
 * 212_retention_holds_and_decisions.php exists to protect, and that
 * migration's own comment is explicit that a deletion sweep is a separate,
 * deliberately-unwritten change. This is the other half: not code that
 * destroys evidence, code that refuses to let something else destroy it by
 * accident.
 *
 * `before_contract_delete` does not exist in core. Contracts::delete() calls
 * it as a plain apply_filters() with a default of {allowed: true}, so an
 * install without this module behaves exactly as it does today -- this only
 * changes behaviour where a module actually answers.
 *
 * WHAT IS STILL ALLOWED
 * ----------------------
 * A contract that was never sent for signing -- no request row, no legal
 * hold, no KYC decision -- has nothing here worth protecting. Deleting it is
 * allowed, and payplex_cv_after_contract_delete() below clears the roster,
 * draft placements and staff assignments so nothing about it lingers.
 *
 * @param  array $decision   {allowed, reason}
 * @param  int   $contractId
 * @return array {allowed, reason}
 */
function payplex_cv_before_contract_delete($decision, $contractId)
{
    $contractId = (int) $contractId;

    if ($contractId <= 0) { return $decision; }
    if (!function_exists('get_instance')) { return $decision; }

    $CI = &get_instance();
    $CI->load->model(PAYPLEX_CV_MODULE . '/contract_verification_model', 'cv');

    if (!$CI->cv->schemaReady()) { return $decision; }

    try {
        if ($CI->cv->contractHasActiveLegalHold($contractId)) {
            return array('allowed' => false, 'reason' =>
                'This contract has an active legal hold in Contract Verification and cannot be '
              . 'deleted. Release the hold first if it is no longer needed.');
        }

        if ($CI->cv->contractHasSigningRequest($contractId)) {
            return array('allowed' => false, 'reason' =>
                'This contract has a signing request in Contract Verification -- sent, in '
              . 'progress, or completed -- and cannot be deleted, to avoid losing signing '
              . 'evidence or an in-progress signature. Cancel it there first.');
        }

        if ($CI->cv->contractHasKycDecisions($contractId)) {
            return array('allowed' => false, 'reason' =>
                'This contract has recorded KYC decisions in Contract Verification and cannot be '
              . 'deleted.');
        }
    } catch (Throwable $e) {
        /* Refuse, don't fatal, and don't silently allow. An error here means
           we could not confirm the contract is free of protected data, which
           is exactly when deleting it anyway is the wrong default. */
        return array('allowed' => false, 'reason' =>
            'Contract Verification could not confirm this contract has no protected signing or '
          . 'KYC data, so deletion was refused rather than risk destroying it.');
    }

    return $decision;
}

hooks()->add_filter('before_contract_delete', 'payplex_cv_before_contract_delete', 10, 2);

/**
 * Clear this module's prep-only rows once core has actually deleted the
 * contract.
 *
 * Only reachable for a contract payplex_cv_before_contract_delete() allowed,
 * which means it carried no legal hold, no signing request and no KYC
 * decision -- so nothing purgePrepDataForDeletedContract() removes is
 * evidence of anything; it is working state that no longer refers to a
 * contract that exists.
 */
function payplex_cv_after_contract_delete($contractId)
{
    $contractId = (int) $contractId;

    if ($contractId <= 0) { return; }
    if (!function_exists('get_instance')) { return; }

    $CI = &get_instance();
    $CI->load->model(PAYPLEX_CV_MODULE . '/contract_verification_model', 'cv');

    if (!$CI->cv->schemaReady()) { return; }

    try {
        $CI->cv->purgePrepDataForDeletedContract($contractId);
    } catch (Throwable $e) {
        /* The contract is already gone. Anything left behind here is an inert
           row an orphan sweep can find later, not a reason to fatal a delete
           that has already happened. */
    }
}

hooks()->add_action('after_contract_deleted', 'payplex_cv_after_contract_delete');

/*
 * The client-facing "Sign with Aadhaar (via Leegality)" button that used to
 * be contributed here is gone along with the Leegality integration. Signers
 * now reach a signing page through the link this module emails them
 * directly (see Contract_verification_model::nativeIssueSigningInvites()),
 * not by visiting the shared contract page and finding an extra button --
 * so the contract_client_signing_options filter has no handler registered
 * here any more.
 */

/**
 * Narrow-screen behaviour for this module's tables.
 *
 * WHY A HELPER AND NOT A SHARED VIEW PARTIAL
 * ------------------------------------------
 * Six views need the same rules. A partial would be the obvious answer, and it
 * is the wrong one here: CI asserts that every view on disk is loaded by the
 * controller, because this module once shipped a finished screen no route
 * reached. A partial loaded only by another view is invisible to that check and
 * would have to be excluded from it — weakening the one assertion that caught a
 * real defect, in order to tidy some CSS. Not a trade worth making.
 *
 * A function in the module bootstrap is already loaded, already covered, and
 * emits exactly once per request.
 *
 * WHY THE TABLES SCROLL INSTEAD OF WRAPPING
 * -----------------------------------------
 * A table that does not fit does not wrap. It widens its parent, and the whole
 * page then scrolls sideways — so the headings, the blocker sentences and the
 * buttons all drift off-screen together. Pushing the scroll down into each
 * table's own wrapper keeps the page still and moves only the table.
 *
 * SCOPING
 * -------
 * Every selector is prefixed with `.cv-tablewrap` or `.cv-exec`, both invented
 * by this module. These views render inside the CRM's own chrome, and an
 * unscoped `.table-responsive`, `.label` or `.btn-xs` rule here would silently
 * restyle every other screen in the product.
 *
 * Nothing here reads user input, builds a URL, or touches a route, capability,
 * lifecycle rule or query.
 */
function payplex_cv_responsive_table_assets()
{
    static $emitted = false;

    if ($emitted) { return ''; }

    $emitted = true;

    return <<<'CVASSETS'
<style>
/* Bootstrap 3 only enables .table-responsive below 768px. These tables can
   overflow a narrow content column above that too (768px still shows the
   sidebar), so the wrapper scrolls at every width instead. */
.cv-tablewrap { max-width: 100%; }
.cv-tablewrap .table-responsive {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  width: 100%;
}

/* The swipe hint is hidden by default and revealed by script only on a wrapper
   that is genuinely scrollable. A permanent "swipe to view more" under a
   two-column table that always fits is noise, and it trains people to ignore
   the hint on the seven-column table where it matters. */
.cv-tablewrap .cv-swipe { display: none; }
@media (max-width: 767px) {
  .cv-tablewrap.cv-is-scrollable .cv-swipe {
    display: block;
    margin: -6px 0 14px;
    font-size: 11px;
    line-height: 1.4;
    color: #8a94a6;
  }
  .cv-tablewrap.cv-is-scrollable .cv-swipe::before { content: "\2194\00a0"; }
}

/* Long unbreakable strings — document and evidence hashes, provider references.
   Left alone they are the widest thing on the screen. */
.cv-tablewrap code,
.cv-tablewrap .cv-ref { word-break: break-all; overflow-wrap: anywhere; white-space: normal; }

/* Status badges wrap instead of stretching their row. */
.cv-tablewrap .label { white-space: normal; display: inline-block; }

/* Timeline timestamp: fixed on desktop, un-wrapped on a phone, so the date is
   never broken across lines and never dictates the table width. */
.cv-tablewrap .cv-when { width: 180px; white-space: nowrap; }
@media (max-width: 767px) { .cv-tablewrap .cv-when { width: auto; } }

@media (max-width: 767px) {
  /* Touch targets. btn-xs is 22px tall by default — about half what a finger
     needs, on buttons that send a verification link. */
  .cv-tablewrap .btn-xs, .cv-tablewrap .btn-sm,
  .cv-exec .btn-xs, .cv-exec .btn-sm {
    min-height: 38px; padding: 8px 14px; line-height: 20px;
  }
  /* Action buttons share a cell via .dinline. Stacked, each gets a full-width
     target instead of three cramped ones. */
  .cv-tablewrap td form.dinline, .cv-exec td form.dinline { display: block; margin-bottom: 6px; }
  .cv-tablewrap td form.dinline:last-child, .cv-exec td form.dinline:last-child { margin-bottom: 0; }
  .cv-tablewrap td form.dinline .btn, .cv-exec td form.dinline .btn { display: block; width: 100%; }
}
</style>
<script>
/* Swipe hint, shown only where it is true.
   CSS cannot ask whether a box overflows, so the class is set by measuring it.
   Reads nothing from the user, writes nothing into a URL, and touches only
   elements this module rendered. If the script does not run the tables still
   scroll; only the caption is absent. */
(function () {
  function measure() {
    var wraps = document.querySelectorAll('.cv-tablewrap');
    for (var i = 0; i < wraps.length; i++) {
      var box = wraps[i].querySelector('.table-responsive');
      if (!box) { continue; }
      /* 1px of slack: sub-pixel rounding otherwise reports a table that fits
         exactly as scrollable. */
      var scrollable = (box.scrollWidth - box.clientWidth) > 1;
      var has = /(^|\s)cv-is-scrollable(\s|$)/.test(wraps[i].className);
      if (scrollable && !has) { wraps[i].className += ' cv-is-scrollable'; }
      if (!scrollable && has) {
        wraps[i].className = wraps[i].className.replace(/(^|\s)cv-is-scrollable(?=\s|$)/g, '');
      }
    }
  }
  var pending = null;
  function schedule() { if (pending) { clearTimeout(pending); } pending = setTimeout(measure, 120); }

  /* Measuring once at DOMContentLoaded is not enough, and this was caught on the
     live staging page rather than reasoned about: at 390px one table stopped
     overflowing after a late reflow (webfonts and the CRM's own scripts settle
     after the DOM is ready) and its hint stayed on, advertising a swipe that did
     nothing. A hint that is sometimes wrong is the thing this measurement exists
     to avoid, so the class is kept in step with the box instead of set once.

     ResizeObserver fires whenever a wrapper or its contents change size, which
     covers every late reflow without polling. Where it is missing, two delayed
     re-measures cover the same settling period. */
  function watch() {
    measure();
    if (typeof ResizeObserver === 'function') {
      var ro = new ResizeObserver(schedule);
      var boxes = document.querySelectorAll('.cv-tablewrap .table-responsive');
      for (var i = 0; i < boxes.length; i++) {
        ro.observe(boxes[i]);
        if (boxes[i].firstElementChild) { ro.observe(boxes[i].firstElementChild); }
      }
    } else {
      setTimeout(measure, 600);
      setTimeout(measure, 1800);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', watch);
  } else { watch(); }
  window.addEventListener('load', schedule);
  window.addEventListener('resize', schedule);
  window.addEventListener('orientationchange', schedule);
})();
</script>
CVASSETS;
}
