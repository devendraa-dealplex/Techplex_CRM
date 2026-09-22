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

/*
 * There is deliberately no standalone "KYC & Video KYC" customer-profile tab.
 * The KYC cases render as the "KYC & Video KYC" sub-tab of the core Contracts
 * tab (application/views/admin/clients/groups/contracts.php, ?group=contracts&sub=kyc),
 * gated on `contract_kyc_view`, so the sidebar highlight never leaves Contracts.
 */

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
    echo '<li><a href="' . $execution . '">Execution &amp; Video KYC</a></li>';
    echo '<li><a href="' . $timeline . '">Signing evidence timeline</a></li>';
}

hooks()->add_action('after_contract_view_as_client_link', 'payplex_cv_contract_action_links');

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
