<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Payplex Lead Finder
Description: Google Business lead discovery with a Prospect Verification Queue. Results never reach the main Leads table without a verified, confirmed conversion.
Version: 0.1.0
Requires at least: 2.3.0
*/

define('PAYPLEX_LF_MODULE', 'payplex_leadfinder');

/*
 * NO SCHEMA IS CREATED HERE.
 *
 * Every other module in this CRM installs its tables on activation behind an
 * option-guarded schema_version. This one does not, deliberately: its
 * migrations are versioned files applied by an authorised operator. Activating
 * the module on an un-migrated install therefore gives a module that reports it
 * cannot run — which is the intended, visible failure, not a bug.
 *
 * There is no install.php for the same reason.
 */

hooks()->add_action('admin_init', 'payplex_leadfinder_capabilities');
hooks()->add_action('admin_init', 'payplex_leadfinder_menu');

/**
 * §17 — the three roles, as capabilities.
 *
 * `leadfinder_manage_profiles` is the one that matters. It gates the only screen that can
 * write an API key, and it is NOT granted by holding any other capability:
 * being able to search does not imply being able to see where the key lives.
 * There is no capability anywhere in this list that reveals a key, because no
 * code path exists that would serve one.
 */
function payplex_leadfinder_capabilities()
{
    if (!function_exists('register_staff_capabilities')) { return; }
    require_once __DIR__ . '/libraries/Leadfinder_caps.php';

    /*
     * ONLY the capabilities this build enforces.
     *
     * §17 names twelve. Seven of them belong to Phase 2 endpoints that do not
     * exist yet, and registering those would put controls on the Roles screen
     * that change nothing when toggled. See Leadfinder_caps for the full list
     * and the rule: a capability is registered in the same change as the
     * endpoint that reads it.
     */
    /*
     * The map is written out here rather than passed as
     * `Leadfinder_caps::enforced()`, so that a static reader — a colleague or
     * the CI capability scanner — can see exactly which permissions this module
     * offers without executing anything. The first version did hide it behind
     * the accessor, and the scanner correctly reported that it could not tell
     * what was being registered. A permission list that only a running process
     * can enumerate is a permission list nobody reviews.
     *
     * CapabilityEnforcementTest asserts this literal map is identical to
     * Leadfinder_caps::enforced(), so the duplication cannot drift.
     */
    register_staff_capabilities(PAYPLEX_LF_MODULE, array('capabilities' => array(
        'leadfinder_view'            => 'Open Lead Finder and view the prospect queue',
        'leadfinder_search'          => 'Run a business search against the Places API',
        'leadfinder_claim'           => 'Claim and release a prospect',
        'leadfinder_view_all'        => 'View every prospect, not only your own',
        'leadfinder_manage_profiles' => 'Configure API connection profiles and run the retention job',
        /* Step 6. A detail call bills at a higher SKU than a search, so an
           employee may be allowed to search and not to fetch contact details. */
        'leadfinder_fetch_details'   => 'Fetch phone and website for a prospect you have claimed',
        /* Step 8 — three duties, three names. Submitting and approving must be
           held by two different people for any one prospect; the model enforces
           that separately, because holding a capability says what a person may
           do and not whose work they may do it to. */
        'leadfinder_verify'             => 'Record call results and verification on a prospect you hold',
        'leadfinder_submit_conversion'  => 'Submit a verified prospect for conversion approval',
        'leadfinder_approve_conversion' => 'Approve or reject a conversion submitted by somebody else',
        /* Step 9 — reports aggregate across everybody's work, which is a
           different thing to be allowed than seeing your own queue. */
        'leadfinder_reports'            => 'View Lead Finder reports and export them',
    )), 'Payplex Lead Finder');
}

/* ===================================================================== */
/* §14.3 coordinate retention — scheduled, locked, recorded              */
/* ===================================================================== */

/**
 * The sweep, on the CRM's cron.
 *
 * THE DEFECT THIS CLOSES
 * ----------------------
 * `Leadfinder_model::purgeExpiredCoordinates()` existed, was unit-tested, and
 * was **never called**. A search of the whole deployed module for its name
 * returned one occurrence: its own definition. The module registered
 * `admin_init` twice and `app_admin_footer`, and no cron hook of any kind.
 *
 * The 30-day deletion of cached latitude and longitude that Google's Service
 * Specific Terms §14.3 requires therefore could not run. Nothing had been
 * breached only because no coordinate had ever been stored — the first
 * successful search would have started a table filling with data that nothing
 * ever cleared, reporting no error at any point.
 *
 * NOT `admin_init`
 * ----------------
 * A page-load hook would run the sweep hundreds of times a day inside requests
 * a person is waiting on, and not at all overnight — which is precisely when a
 * retention window expires. `after_cron_run` fires from the CRM's own cron
 * endpoint. `RetentionCronTest` asserts the registered hook is on the allowed
 * list and that none of the page-load hooks appears near this callback.
 *
 * The callback is deliberately thin: decide, lock, delegate, record. Everything
 * it decides lives in `Leadfinder_retention_job` where it can be tested without
 * a database, and everything it touches lives in the model.
 */
hooks()->add_action('after_cron_run', 'payplex_leadfinder_retention_cron');

function payplex_leadfinder_retention_cron()
{
    require_once __DIR__ . '/libraries/Leadfinder_retention_job.php';

    $CI = &get_instance();
    $CI->load->model(PAYPLEX_LF_MODULE . '/leadfinder_model', 'lf');

    if (!$CI->lf->schemaReady()) {
        return;
    }

    /*
     * Due? The cron fires every five minutes; this job does not need to. The
     * interval is config, and a missing or unparseable last-run reads as "never
     * run", which is due.
     */
    $interval = $CI->lf->configInt('retention_job_interval_seconds',
                                   Leadfinder_retention_job::DEFAULT_INTERVAL_SECONDS);

    if (!Leadfinder_retention_job::shouldRun($CI->lf->retentionLastRunAt(), time(), $interval)) {
        return;
    }

    /*
     * The lock is taken by a conditional UPDATE inside the model, so the
     * database decides the winner. Two overlapping ticks can both be told the
     * lock is available; only one of them changes a row.
     */
    if (!$CI->lf->acquireJobLock(Leadfinder_retention_job::LOCK_KEY, time())) {
        return;
    }

    try {
        $CI->lf->runRetentionSweep(time(), 'cron', 0);
    } catch (Throwable $e) {
        /*
         * Throwable, not Exception. A fatal here would take down `after_cron_run`
         * and every module hooked after it — which is how payplex_meetings once
         * broke the whole cron chain by catching the narrower type.
         */
        $CI->lf->recordRetentionFailure(time(), $e->getMessage(), 'cron', 0);
    }

    $CI->lf->releaseJobLock(Leadfinder_retention_job::LOCK_KEY, time(),
                           $CI->lf->jobLockOwner(Leadfinder_retention_job::LOCK_KEY));
}

/**
 * Release reservations whose caller never came back.
 *
 * A reservation is taken before the request goes out and settled when the
 * outcome is known. A process that dies in between — a PHP fatal, a killed
 * worker, a browser closed mid-request — leaves its unit held. Nothing would
 * ever give it back, so a crash during a busy hour would permanently shrink the
 * month's allowance and the administrator would see spending they could not
 * account for.
 *
 * On the same `after_cron_run` hook as the retention sweep, and for the same
 * reason: `admin_init` would run it inside a request a person is waiting on, and
 * never during a quiet period.
 *
 * No lock is taken. The sweep settles each reservation with a conditional
 * UPDATE on `state = 'held'`, so two overlapping ticks cannot both release the
 * same one — the database picks the winner, exactly as the claim path does.
 */
hooks()->add_action('after_cron_run', 'payplex_leadfinder_reservation_sweep');

function payplex_leadfinder_reservation_sweep()
{
    $CI = &get_instance();
    $CI->load->model(PAYPLEX_LF_MODULE . '/leadfinder_model', 'lf');

    if (!$CI->lf->schemaReady()) {
        return;
    }

    try {
        $CI->lf->sweepStaleReservations(time());
    } catch (Throwable $e) {
        /*
         * Throwable, not Exception. A fatal here would take down
         * `after_cron_run` and every module hooked after it, including the
         * retention sweep that keeps this install compliant.
         */
        $CI->lf->audit(0, 'reservation_sweep_failed', 'quota', 0,
                       array('message' => substr($e->getMessage(), 0, 180)));
    }
}

/**
 * The waste purge.
 *
 * WHY IT IS SEPARATE FROM THE RETENTION SWEEP
 * -------------------------------------------
 * They clear different things on different clocks for different reasons. The
 * retention sweep clears Google COORDINATES after thirty calendar days, because
 * the Places terms say so. This one clears the CONTACT DETAILS of prospects an
 * employee rejected, after a retention period the business states. Running them
 * as one job would mean a change to either rule silently changed the other, and
 * every report would show one number where two things happened.
 *
 * WHAT IT REFUSES TO DO
 * ---------------------
 *   - Runs at all with no readable keyring. Not because clearing a column needs
 *     a pepper, but because a purge that runs while suppression records cannot
 *     be written destroys the identifiers and leaves nothing able to recognise
 *     that business again — permanently, and silently.
 *   - Runs at all with no stated retention period. A period nobody has decided
 *     is not a licence to pick one.
 *   - Clears a prospect with no suppression record. Same reason, per row.
 *
 * On `after_cron_run` with the others, and for the same reason: `admin_init`
 * would run destructive work inside a request somebody is waiting on.
 */
hooks()->add_action('after_cron_run', 'payplex_leadfinder_purge_cron');

function payplex_leadfinder_purge_cron()
{
    $CI = &get_instance();
    $CI->load->model(PAYPLEX_LF_MODULE . '/leadfinder_model', 'lf');

    if (!$CI->lf->schemaReady()) {
        return;
    }

    try {
        /*
         * The model does the deciding — keyring, retention period, lock, batch.
         * This callback is thin on purpose: everything it would otherwise judge
         * is testable without a cron, and a cron hook is the worst place to
         * discover that a judgement was wrong.
         */
        $CI->lf->runPurgeSweep(time(), 'cron', 0);
    } catch (Throwable $e) {
        /*
         * Throwable, not Exception. A fatal here would take down
         * `after_cron_run` and every module hooked after it, including the
         * retention sweep that keeps this install compliant.
         */
        $CI->lf->audit(0, 'purge_sweep_failed', 'purge_queue', 0,
                       array('message' => substr($e->getMessage(), 0, 180)));
    }
}

function payplex_leadfinder_can($cap)
{
    if (function_exists('is_admin') && is_admin()) { return true; }
    if (!function_exists('has_permission')) { return false; }
    return has_permission(PAYPLEX_LF_MODULE, '', $cap);
}

/**
 * The sidebar link — gated on exactly the capability the landing page requires.
 *
 * This condition used to be `lf_search OR lf_api_manage`, which is one term
 * wider than the page it links to. That is the same shape as the defect just
 * found in leadgen_followup, where the menu asked `leads: view` and the page
 * asked `leadgen_followup: view`, so staff were shown a link that denied them.
 *
 * Nothing leaked there and nothing would have leaked here — but a navigation
 * item is a statement about what someone may do, and it should not make a
 * promise the controller has no intention of keeping.
 *
 * `CapabilityNamespaceTest` extracts the full capability set from this function
 * and from `Finder::index()` and requires them to be equal, so a partial OR
 * cannot pass.
 */
function payplex_leadfinder_menu()
{
    /* Menu visibility only. The endpoints gate themselves — hiding a link has
       never been treated as authorisation on this project. */
    if (!has_permission(PAYPLEX_LF_MODULE, '', 'leadfinder_view')
        && !(function_exists('is_admin') && is_admin())) {
        return;
    }
    $ci = &get_instance();
    $ci->app_menu->add_sidebar_children_item('leads', array(
        'slug'     => 'payplex-lead-finder',
        'name'     => 'Find Business Leads',
        'href'     => admin_url('payplex_leadfinder/finder'),
        'position' => 31,
    ));

    /*
     * Same capability as the link above, because it is the same capability the
     * screen checks. The waste list is a read-only view of prospects already in
     * the employee's own scope; the destructive controls on it are rendered
     * only for `leadfinder_manage_profiles` and re-checked by every endpoint.
     *
     * CapabilityNamespaceTest requires the capability set guarding this
     * function to equal the one guarding the landing page, so a second, wider
     * condition here would fail the gate rather than quietly promise staff a
     * screen that denies them.
     */
    $ci->app_menu->add_sidebar_children_item('leads', array(
        'slug'     => 'payplex-lead-finder-waste',
        'name'     => 'Waste and suppression',
        'href'     => admin_url('payplex_leadfinder/finder/waste_list'),
        'position' => 32,
    ));
}

/*
 * The button on the existing All Leads page.
 *
 * Injected through `app_admin_footer`, which is one of the hook names verified
 * to fire on this build. The All Leads module is NOT edited: it registers no
 * extension point of its own, and the alternative — `leads_table_columns` — is
 * known on this install to add a header cell with no matching row data and
 * blank the entire core leads table. That is a documented, already-paid-for
 * failure in this workspace and is not repeated here.
 *
 * The injection is gated on the current URI so it appears on one page only, and
 * on the capability, so an employee without `leadfinder_view` never sees a control
 * they cannot use.
 */
hooks()->add_action('app_admin_footer', 'payplex_leadfinder_all_leads_button');

function payplex_leadfinder_all_leads_button()
{
    if (!has_permission(PAYPLEX_LF_MODULE, "", "leadfinder_view")
        && !(function_exists("is_admin") && is_admin())) { return; }
    if (strpos(uri_string(), 'payplex_all_leads/all_leads') === false) { return; }

    $href = admin_url('payplex_leadfinder/finder');
    echo '<script>(function(){'
       . 'var h=' . json_encode($href) . ';'
       . 'var host=document.querySelector("#pp_bulkbar")||document.querySelector(".panel-body");'
       . 'if(!host||document.getElementById("pp_lf_btn"))return;'
       . 'var a=document.createElement("a");'
       . 'a.id="pp_lf_btn";a.className="btn btn-info btn-sm";a.href=h;'
       . 'a.textContent="Find Business Leads";a.style.marginLeft="6px";'
       . 'host.appendChild(a);'
       . '})();</script>';
}
