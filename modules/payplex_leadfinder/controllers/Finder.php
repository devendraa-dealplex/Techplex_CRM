<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_phone.php';
require_once __DIR__ . '/../libraries/Leadfinder_secret.php';
require_once __DIR__ . '/../libraries/Leadfinder_scope.php';
require_once __DIR__ . '/../libraries/Leadfinder_claim.php';
require_once __DIR__ . '/../libraries/Leadfinder_status.php';
require_once __DIR__ . '/../libraries/Leadfinder_fieldmask.php';
require_once __DIR__ . '/../libraries/Leadfinder_places.php';
require_once __DIR__ . '/../libraries/Leadfinder_transport.php';
require_once __DIR__ . '/../libraries/Leadfinder_dupe.php';
require_once __DIR__ . '/../libraries/Leadfinder_verification.php';
require_once __DIR__ . '/../libraries/Leadfinder_reports.php';
require_once __DIR__ . '/../libraries/Leadfinder_caps.php';

/**
 * Finder — Phase 1 endpoints.
 *
 * WHAT PHASE 1 IS
 * ---------------
 * Encrypted API profiles, a search, the Prospect Verification Queue, and the
 * claim. Conversion, scoring, dashboards and reports are Phase 2 and are not
 * stubbed here: an endpoint that exists and does nothing is worse than one that
 * does not exist, because the menu implies it works.
 *
 * EVERY CAPABILITY NAMED HERE IS ENFORCED HERE
 * --------------------------------------------
 * The module registers exactly the capabilities this controller checks, and no
 * others. The first draft registered all twelve from §17 — including eight
 * whose endpoints are Phase 2 — and CI rejected it: a permission offered on the
 * Roles screen that nothing reads grants an administrator the feeling of having
 * restricted something. The remaining eight are listed in `Leadfinder_caps` as
 * planned, and each is registered in the same change that adds the endpoint
 * enforcing it.
 *
 * REQUIRES MIGRATION 101 AND 102
 * ------------------------------
 * Every action calls `requireSchema()` first. On an un-migrated install the
 * module reports that plainly instead of producing a PHP error against a table
 * that is not there.
 */
class Finder extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_leadfinder/leadfinder_model', 'lf');
    }

    /* ---------------------------------------------------------------- */

    private function actor()
    {
        return function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0;
    }

    /**
     * Refuse unless the caller holds the capability.
     *
     * Takes the RESULT of the check, not the capability name, so every call
     * site reads `has_permission('payplex_leadfinder', '', 'leadfinder_claim')` with
     * the capability spelled out. The first version passed the name into the
     * helper and did the lookup inside; CI's capability scanner then reported
     * two capabilities as registered-but-never-checked, because the only
     * has_permission() call in the file took a variable and no literal name
     * appeared anywhere. The scanner was right about the thing that matters: a
     * reader could not tell from an action which permission guarded it either.
     */
    private function need($held)
    {
        if ($held === true) { return; }
        if (function_exists('is_admin') && is_admin()) { return; }
        access_denied('payplex_leadfinder');
    }

    private function band()
    {
        $isAdmin   = function_exists('is_admin') && is_admin();
        $isManager = $isAdmin || has_permission('payplex_leadfinder', '', 'leadfinder_view_all');
        $canView   = $isAdmin || has_permission('payplex_leadfinder', '', 'leadfinder_view');
        return Leadfinder_scope::bandFor($isAdmin, $isManager, $canView);
    }

    /**
     * Stop before touching a table that does not exist.
     *
     * This module does not self-install. On an un-migrated database every query
     * would be a fatal, and — as this project found the hard way this week — a
     * PHP fatal on this server produces an empty 500 and no log line anywhere.
     */
    private function requireSchema()
    {
        if ($this->lf->schemaReady()) { return; }
        $this->load->view('payplex_leadfinder/not_migrated', array(
            'title'      => 'Lead Finder — not installed',
            'missing'    => $this->lf->missingTables(),
            'migrations' => array('101_leadfinder_schema.php', '102_leadfinder_config.php',
                                  '103_phone_match_key.php', '104_decision_flags.php'),
        ));
        exit;
    }

    /* ---------------------------------------------------------------- */

    /**
     * §3 — the search form, and the search. This is the module's landing page.
     *
     * Named `index()`, not `finder()`. Perfex routes
     * `/admin/payplex_leadfinder/finder` to controller `Finder`, method
     * `index` — the method name is the THIRD segment, and a missing default is
     * a 404 rather than an error. The first version called this `finder()`, so
     * the module deployed cleanly, activated cleanly, registered in tblmodules
     * as active, and its only entry point returned 404.
     *
     * Nothing in the file was wrong on its own. The route was.
     */
    public function index()
    {
        /*
         * Two capabilities, one action, and the split is real.
         *
         * `leadfinder_view` opens the page and shows work already done.
         * `leadfinder_search` executes a Places request, which spends money
         * against a billing account. The old build gated both on one name, so
         * anyone who could look at the queue could also spend.
         *
         * The search gate is applied where the POST is handled, not at the top,
         * so a viewer without it still gets a working page instead of a denial.
         */
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_view'));
        $this->requireSchema();

        $data = array(
            'title'       => 'Find Business Leads',
            'profiles'    => $this->lf->profilesForStaff($this->actor()),
            'attribution' => $this->lf->config('show_google_attribution') === '1',
            'blocked'     => $this->lf->complianceBlock(),
            'results'     => array(),
            'error'       => null,
            'notice'      => null,
            /*
             * A fresh token per rendered form.
             *
             * It is what makes a re-POST — a refresh, a double click, a
             * browser retry after a timeout — resolve to the reservation that
             * already exists instead of spending a second unit against the
             * billing account. A new token is issued only when the form is
             * rendered again, which is exactly when the employee has genuinely
             * asked for a new search.
             */
            'request_token' => bin2hex(random_bytes(16)),
            'simulated_mode' => Leadfinder_transport::isMock(
                                    $this->lf->config('places_transport', 'live')),
            'email_note'    => Leadfinder_places::emailSourceNote(),
        );

        /*
         * What is left, shown before the button that spends it.
         *
         * Read-only and unlocked: two people can both be told "1 remaining" and
         * only one will get it. That is correct — the display is an estimate and
         * the reservation is the decision — and it is stated on the screen
         * rather than implied.
         */
        $firstProfile = 0;
        foreach ($data['profiles'] as $p) { $firstProfile = (int) $p['id']; break; }

        $data['allowance'] = $firstProfile > 0
            ? $this->lf->quotaSnapshot($firstProfile, $this->actor(), Leadfinder_fieldmask::CLASS_SEARCH)
            : null;

        if ($this->input->post()) {
            $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_search'));

            $r = $this->lf->runSearch($this->actor(), array(
                'request_token' => (string) $this->input->post('request_token'),
                'keyword'     => (string) $this->input->post('keyword'),
                'category'    => (string) $this->input->post('category'),
                'city'        => (string) $this->input->post('city'),
                'state'       => (string) $this->input->post('state'),
                'pin_code'    => (string) $this->input->post('pin_code'),
                'radius_m'    => (int) $this->input->post('radius_m'),
                'max_results' => (int) $this->input->post('max_results'),
                'product'     => (string) $this->input->post('product'),
                'campaign'    => (string) $this->input->post('campaign'),
                'language'    => (string) $this->input->post('language'),
                'profile_id'  => (int) $this->input->post('profile_id'),
            ));
            /*
             * §5: results are written to the queue, never to tblleads. The model
             * has no insert path to a lead at all in Phase 1 — the conversion
             * endpoint does not exist yet, so there is nothing to get wrong.
             */
            $data['results'] = isset($r['prospects']) ? $r['prospects'] : array();
            $data['error']   = isset($r['error']) ? $r['error'] : null;
            $data['notice']  = isset($r['notice']) ? $r['notice'] : null;

            /*
             * SUPPRESSION IS CHECKED ON DISPLAY, NOT ON IMPORT.
             *
             * A result that matches a tombstone is still stored — the queue row
             * already existed or was just written — but it is marked and, for
             * an exact match, taken out of the offered list. Checking here
             * rather than inside the search keeps one rule in one place: the
             * tombstone decides, and the screen shows what it decided.
             *
             * A phone-only match is shown as "possible", never acted on. One
             * switchboard serves many real businesses.
             */
            $data['suppression'] = array();
            $kept = array();

            foreach ($data['results'] as $row) {
                $verdict = $this->lf->suppressionFor($row);
                $data['suppression'][(int) $row['id']] = $verdict;

                if (empty($verdict['suppress'])) { $kept[] = $row; }
            }

            $data['suppressed_count'] = count($data['results']) - count($kept);
            $data['results']          = $kept;
            $data['saved']            = $this->lf->savedProspectIds($this->actor(),
                                            array_map(function ($x) { return (int) $x['id']; }, $kept));

            /* Re-read after the search so the figure the employee sees reflects
               the unit they just spent, not the one before it. */
            if ($firstProfile > 0) {
                $data['allowance'] = $this->lf->quotaSnapshot(
                    (int) $this->input->post('profile_id') ?: $firstProfile,
                    $this->actor(), Leadfinder_fieldmask::CLASS_SEARCH);
            }
        }

        /*
         * THE MAP, AND THE KEY THAT DRAWS IT
         * ----------------------------------
         * The Maps JavaScript API loads through a `<script src=...&key=...>`
         * tag, so the browser key IS in the page source. There is no loader
         * that hides it and pretending otherwise would be the dangerous move:
         * the protection is that this is a DIFFERENT key from the server Places
         * key — referrer-restricted to this host, enabled only for Maps
         * JavaScript, on its own budget.
         *
         * So the decision about whether a key may appear is made by
         * `Leadfinder_map`, by exact route match, and the key is read only if
         * that decision says yes. There is no fallback to the server Places key
         * and none to the Perfex core `google_api_key`: both are credentials
         * with different restrictions, and substituting either would put a key
         * with a large budget into a public page.
         */
        $data = array_merge($data, $this->mapContext());

        $this->load->view('payplex_leadfinder/finder', $data);
    }

    /**
     * Everything the map area needs, and the key only when it may be rendered.
     *
     * Returns `map_key` as '' in every case where the answer is no, so a view
     * that forgets to check `map['allowed']` still renders no key rather than
     * an undefined variable that some later edit fills in.
     */
    private function mapContext()
    {
        require_once __DIR__ . '/../libraries/Leadfinder_map.php';

        $status = $this->lf->browserMapKeyStatus();

        $uri = trim((string) uri_string(), '/');
        $prefix = trim((string) str_replace(site_url(), '', admin_url()), '/');

        if ($prefix !== '' && strpos($uri, $prefix . '/') === 0) {
            $uri = substr($uri, strlen($prefix) + 1);
        }

        $decision = Leadfinder_map::mayRenderBrowserKey(array(
            'staff_logged_in'    => $this->actor() > 0,
            'in_admin_area'      => true,
            'admin_relative_uri' => $uri,
            'key_configured'     => !empty($status['configured']),
        ));

        return array(
            'map'            => $decision,
            'map_key'        => !empty($decision['allowed']) ? $this->lf->browserMapKey() : '',
            'map_key_status' => $status,
            'map_states'     => Leadfinder_map::markerStates(),
            'map_clustering' => Leadfinder_map::clustering(),
            'map_attribution' => Leadfinder_map::attribution(),
            'map_error'      => !empty($decision['allowed'])
                                ? null : Leadfinder_map::errorState('no_key'),
            'map_zoom'       => $this->lf->configInt('map_default_zoom', 12),
        );
    }

    /** §5 — the Prospect Verification Queue, scoped. */
    public function queue()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_view'));
        $this->requireSchema();

        $band  = $this->band();
        $scope = Leadfinder_scope::listPredicate($this->actor(), $band, $this->lf->teamOf($this->actor()));

        /*
         * Every filter comes off the query string and every one is validated in
         * the model against a fixed list. A sort column cannot be a bind
         * parameter, so a whitelist is the only safe form, and the only safe
         * failure is the default rather than whatever was typed.
         */
        $page = $this->lf->queueRows($scope, (string) $this->input->get('status'), array(
            'q'        => (string) $this->input->get('q'),
            'sort'     => (string) $this->input->get('sort'),
            'dir'      => (string) $this->input->get('dir'),
            'page'     => (int) $this->input->get('page'),
            'per_page' => (int) $this->lf->configInt('queue_default_per_page', 50),
            'owner'    => $this->input->get('owner') === null || $this->input->get('owner') === ''
                          ? -1 : (int) $this->input->get('owner'),
            'dupe_state' => (string) $this->input->get('dupe_state'),
        ));

        require_once __DIR__ . '/../libraries/Leadfinder_ui.php';
        require_once __DIR__ . '/../libraries/Leadfinder_waste.php';

        $ids = array();
        foreach ($page['rows'] as $r) { $ids[] = (int) $r['id']; }

        $this->load->view('payplex_leadfinder/queue', array(
            'title'    => 'Prospect Verification Queue',
            'saved'    => $this->lf->savedProspectIds($this->actor(), $ids),
            'reasons'  => Leadfinder_waste::reasons(),
            'undo_window' => Leadfinder_waste::undoWindowSeconds(
                                 $this->lf->configInt('waste_undo_window_seconds', 0)),
            'can_verify' => (function_exists('is_admin') && is_admin())
                || has_permission('payplex_leadfinder', '', 'leadfinder_verify'),
            'rows'     => $page['rows'],
            'paging'   => $page,
            'statuses' => Leadfinder_status::all(),
            'band'     => $band,
            'is_manager' => (function_exists('is_admin') && is_admin())
                || has_permission('payplex_leadfinder', '', 'leadfinder_view_all'),
            'simulated_mode' => Leadfinder_transport::isMock(
                                    $this->lf->config('places_transport', 'live')),
            'can_fetch_details' => (function_exists('is_admin') && is_admin())
                || has_permission('payplex_leadfinder', '', 'leadfinder_fetch_details'),
        ));
    }

    /** §7 — claim. The lock that stops two employees ringing one school. */
    public function claim($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_claim'));
        $this->requireSchema();

        /* Scoped fetch: an id from the URL must not reach a row the actor
           cannot see. Out of scope is 404, not 403 — a 403 would confirm the
           id exists. */
        $p = $this->lf->prospectInScope($prospectId, $this->actor(), $this->band(), $this->lf->teamOf($this->actor()));
        if (!$p) { show_404(); }

        $actor = $this->actor();
        $d = Leadfinder_claim::canClaim(
            $p, $actor, $this->lf->claimsToday($actor), time(), $this->lf->claimConfig());

        if (!$d['allowed']) {
            set_alert('warning', $this->lf->claimMessage($d['reason']));
        } else {
            /*
             * The claim can still be lost between the check and the write.
             * Reporting "Prospect claimed" regardless is how two employees both
             * come away believing they own the same business and both ring it —
             * so the write's own answer decides the message, not the check's.
             */
            $r = $this->lf->applyClaim($p, $actor, $d, time());

            if (!empty($r['claimed'])) {
                set_alert('success', 'Prospect claimed.');
            } else {
                set_alert('warning', 'Another employee claimed this prospect first. '
                                   . 'It has not been assigned to you.');
            }
        }
        redirect(admin_url('payplex_leadfinder/finder/queue'));
    }

    /**
     * Erase a stored API key. Separate verb, separate confirmation.
     *
     * Blank-means-keep is the rule on the save form, so erasing cannot be a
     * side effect of saving. It needs its own POST, its own confirmation token
     * and the profile-management capability.
     */
    public function clear_key($profileId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_manage_profiles'));
        $this->requireSchema();

        if (!$this->input->post()) { show_404(); }

        if ((string) $this->input->post('confirm_clear') !== 'ERASE') {
            set_alert('warning', 'The key was not erased: the confirmation was missing.');
            redirect(admin_url('payplex_leadfinder/finder/api_profiles'));

            return;
        }

        $out = $this->lf->clearProfileKey((int) $profileId, $this->actor(),
                                          (string) $this->input->post('reason'));

        set_alert(!empty($out['ok']) ? 'success' : 'warning', $out['message']);
        redirect(admin_url('payplex_leadfinder/finder/api_profiles'));
    }

    /** §7 — release your own, or a manager releases anyone's. */
    /**
     * §6 — fetch phone and website for one claimed prospect.
     *
     * POST only. A detail call bills at a higher SKU than a search, so it must
     * not be reachable by a link, a prefetching browser, or a crawler following
     * the queue page — a GET that spends money is a GET that gets spent by
     * something that is not a person.
     *
     * The capability is its own: `leadfinder_search` lets an employee find
     * businesses, `leadfinder_fetch_details` lets them spend the higher rate on
     * one. Ownership is checked in the model as well, because holding the
     * capability says they may spend on their own prospects, not on everyone's.
     */
    public function fetch_details($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_fetch_details'));
        $this->requireSchema();

        if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
        }

        /* Scoped fetch: an id from the URL must not reach a row the actor
           cannot see. Out of scope is 404, not 403. */
        $p = $this->lf->prospectInScope($prospectId, $this->actor(), $this->band(),
                                        $this->lf->teamOf($this->actor()));
        if (!$p) { show_404(); }

        $r = $this->lf->fetchDetails($this->actor(), (int) $prospectId, time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);

        redirect(admin_url('payplex_leadfinder/finder/queue'));
    }

    /**
     * §7 — a manager moves a claim from one employee to another.
     *
     * POST only, manager only, and the employee it is moved FROM comes from the
     * stored row rather than from the request: a stale page must not be usable
     * to move a claim the manager never saw.
     */
    public function reassign($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_view_all'));
        $this->requireSchema();

        if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
        }

        $isManager = (function_exists('is_admin') && is_admin())
                     || has_permission('payplex_leadfinder', '', 'leadfinder_view_all');

        $p = $this->lf->prospectInScope($prospectId, $this->actor(), $this->band(),
                                        $this->lf->teamOf($this->actor()));
        if (!$p) { show_404(); }

        $to = (int) $this->input->post('to_staff_id');

        $d = Leadfinder_claim::canReassign($p, $this->actor(), $to, $isManager === true);

        if (!$d['allowed']) {
            set_alert('warning', $this->lf->claimMessage($d['reason']));
        } else {
            $r = $this->lf->applyReassign($p, $this->actor(), $to, time(),
                                          (string) $this->input->post('reason'));

            set_alert(!empty($r['reassigned']) ? 'success' : 'warning',
                !empty($r['reassigned'])
                    ? 'Prospect reassigned.'
                    : 'Not reassigned: ' . str_replace('_', ' ', (string) $r['reason']) . '.');
        }

        redirect(admin_url('payplex_leadfinder/finder/queue'));
    }

    /**
     * §7 — one action applied to many prospects.
     *
     * POST only. Every id is authorised individually inside the model: the
     * capability check here says the actor may use this button, and says nothing
     * about which rows they may touch. Hoisting the per-row rules out of the
     * loop is what turns a bulk button into an authorisation bypass, so it is
     * not done.
     */
    public function bulk()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_claim'));
        $this->requireSchema();

        if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
        }

        $ids = $this->input->post('ids');
        $ids = is_array($ids) ? $ids : array();

        $isManager = (function_exists('is_admin') && is_admin())
                     || has_permission('payplex_leadfinder', '', 'leadfinder_view_all');

        $r = $this->lf->bulkAction(
            (string) $this->input->post('bulk_action'), $ids, $this->actor(),
            $this->band(), $this->lf->teamOf($this->actor()), $isManager, time());

        if (empty($r['ok'])) {
            set_alert('warning', 'That bulk action is not recognised. Nothing was changed.');
        } else {
            $res = $r['results'];

            /* Both numbers, always. A bulk action that reports only its
               successes hides the rows it refused, and those are the ones
               somebody needs to look at. */
            set_alert($res['refused'] > 0 ? 'warning' : 'success',
                $res['done'] . ' updated, ' . $res['refused'] . ' refused.'
                . ($res['refused'] > 0 ? ' Refused rows were left exactly as they were.' : ''));
        }

        redirect(admin_url('payplex_leadfinder/finder/queue'));
    }

    /**
     * §8 — record what happened on a call.
     *
     * POST only. `leadfinder_verify` says a person may record calls; the model
     * additionally requires that they hold this prospect, because the notes are
     * the evidence an approver will read and they have to be the notes of the
     * person who made the call.
     */
    public function verify($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_verify'));
        $this->requireSchema();

        if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
        }

        $p = $this->lf->prospectInScope($prospectId, $this->actor(), $this->band(),
                                        $this->lf->teamOf($this->actor()));
        if (!$p) { show_404(); }

        /*
         * Only the whitelisted fields are read off the request. The model
         * whitelists again — this is the outer of two, and the inner one is the
         * one that matters, because it is next to the write.
         */
        $d = array();
        foreach (Leadfinder_verification::writableFields() as $f) {
            if ($this->input->post($f) !== null) { $d[$f] = $this->input->post($f); }
        }

        $r = $this->lf->saveVerification((int) $prospectId, $this->actor(), $d, time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);

        redirect(admin_url('payplex_leadfinder/finder/queue'));
    }

    /**
     * §8 — the maker half. Asks for approval; creates no lead.
     */
    public function submit_conversion($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_submit_conversion'));
        $this->requireSchema();

        if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
        }

        $p = $this->lf->prospectInScope($prospectId, $this->actor(), $this->band(),
                                        $this->lf->teamOf($this->actor()));
        if (!$p) { show_404(); }

        $r = $this->lf->submitConversion((int) $prospectId, $this->actor(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);

        redirect(admin_url('payplex_leadfinder/finder/queue'));
    }

    /**
     * §8 — the checker half. The only route that creates a CRM lead.
     *
     * The capability says a person may approve conversions. Whether they may
     * approve THIS one is decided in the model against the stored submitter, so
     * an administrator who also makes calls still cannot approve their own work.
     */
    public function approve_conversion($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_approve_conversion'));
        $this->requireSchema();

        if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
        }

        $p = $this->lf->prospectInScope($prospectId, $this->actor(), $this->band(),
                                        $this->lf->teamOf($this->actor()));
        if (!$p) { show_404(); }

        $decision = (string) $this->input->post('decision');

        if ($decision === 'approve') {
            $r = $this->lf->approveConversion((int) $prospectId, $this->actor(), time());
        } else {
            $r = $this->lf->rejectConversion((int) $prospectId, $this->actor(),
                    (string) $this->input->post('reason'),
                    $decision === 'send_back', time());
        }

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);

        redirect(admin_url('payplex_leadfinder/finder/approvals'));
    }

    /**
     * §8 — the approver's queue.
     *
     * Separate from the prospect queue on purpose: an approver is not looking
     * for work to do on a business, they are looking at somebody else's
     * completed work to decide whether it holds up.
     */
    public function approvals()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_approve_conversion'));
        $this->requireSchema();

        $this->load->view('payplex_leadfinder/approvals', array(
            'title'   => 'Conversions Awaiting Approval',
            'rows'    => $this->lf->pendingConversions(100),
            'actor'   => $this->actor(),
        ));
    }

    /**
     * §9 — reports and monitoring.
     *
     * Two capability checks, not one. `leadfinder_reports` opens the screen;
     * each report additionally names the capability IT requires, and several
     * name the administrator one — spending figures and refused-access logs are
     * not things every reporting user should see. Checking only the screen's
     * capability would make the per-report capability decorative.
     */
    public function reports()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_reports'));
        $this->requireSchema();

        $id = (string) $this->input->get('report');

        $data = array(
            'title'       => 'Lead Finder Reports',
            'definitions' => Leadfinder_reports::definitions(),
            'selected'    => '',
            'result'      => null,
            'alerts'      => $this->mayRunReport('quota_consumption')
                             ? $this->lf->adminAlerts(time()) : array(),
            'filters'     => array(
                'from'     => (string) $this->input->get('from'),
                'to'       => (string) $this->input->get('to'),
                'staff_id' => (int) $this->input->get('staff_id'),
                'status'   => (string) $this->input->get('status'),
                'source'   => (string) $this->input->get('source'),
            ),
            'may_see_contact' => (function_exists('is_admin') && is_admin())
                || has_permission('payplex_leadfinder', '', 'leadfinder_view_all'),
        );

        if ($id !== '' && Leadfinder_reports::exists($id)) {
            if (!$this->mayRunReport($id)) { access_denied('payplex_leadfinder'); }

            $data['selected'] = $id;
            $data['result']   = $this->lf->report($id, $data['filters'],
                                    $this->lf->configInt('report_max_rows', 1000));
        }

        $this->load->view('payplex_leadfinder/reports', $data);
    }

    /**
     * §9 — take a report away as a CSV.
     *
     * A CSV leaves the building, so it gets three things the screen does not:
     * the report must be marked exportable, the row count is bounded and a
     * larger result is REFUSED rather than truncated, and the download is
     * written to the export ledger before the file is sent.
     */
    public function report_export()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_reports'));
        $this->requireSchema();

        $id = (string) $this->input->get('report');

        if (!Leadfinder_reports::exists($id) || !Leadfinder_reports::isExportable($id)) {
            show_404();
        }

        if (!$this->mayRunReport($id)) { access_denied('payplex_leadfinder'); }

        $max = $this->lf->configInt('report_export_max_rows', 5000);

        $r = $this->lf->report($id, array(
            'from'     => (string) $this->input->get('from'),
            'to'       => (string) $this->input->get('to'),
            'staff_id' => (int) $this->input->get('staff_id'),
            'status'   => (string) $this->input->get('status'),
            'source'   => (string) $this->input->get('source'),
        ), $max + 1);

        if (empty($r['ok'])) {
            set_alert('warning', 'That report could not be run with those filters.');
            redirect(admin_url('payplex_leadfinder/finder/reports'));
        }

        if (count($r['rows']) > $max) {
            /*
             * Refused, not truncated. A spreadsheet that quietly stops at the
             * limit is a wrong answer somebody will act on — and they will act
             * on it precisely because it looks complete.
             */
            set_alert('warning', 'That export would contain more than ' . $max
                . ' rows. Narrow the date range and try again — it was not truncated, '
                . 'because a truncated export looks complete.');
            redirect(admin_url('payplex_leadfinder/finder/reports?report=' . rawurlencode($id)));
        }

        $maySeeContact = (function_exists('is_admin') && is_admin())
            || has_permission('payplex_leadfinder', '', 'leadfinder_view_all');

        $this->lf->recordExport($id, $this->actor(), count($r['rows']), time());

        $this->lf->audit($this->actor(), 'report_exported', 'report', 0,
                         array('report' => $id, 'rows' => count($r['rows'])));

        $rows = array();
        foreach ($r['rows'] as $row) {
            $rows[] = Leadfinder_reports::redactRow($row, $maySeeContact);
        }

        $filename = 'leadfinder-' . preg_replace('/[^a-z0-9_\-]/i', '', $id)
                  . '-' . date('Ymd-His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');

        /*
         * `csvCell` is applied at the call site, on the header row as well as
         * the data.
         *
         * The header was previously written raw on the grounds that column names
         * are ours — which is true today and is the kind of assumption a report
         * with a dynamic column quietly breaks. It also made the guard invisible
         * where it matters: a reader (and CI's scanner) sees the neutralisation
         * on the line that writes the file, not two files away.
         */
        if ($rows) {
            fputcsv($out, array_map(array('Leadfinder_reports', 'csvCell'),
                                    array_keys($rows[0])));
        }

        foreach ($rows as $row) {
            fputcsv($out, array_map(array('Leadfinder_reports', 'csvCell'),
                                    array_values($row)));
        }

        fclose($out);
        exit;
    }

    /**
     * May this actor run this particular report?
     *
     * Each report names its own capability. An administrator passes everything,
     * as everywhere else in this module — with the single exception of approving
     * their own conversion, which is a rule about a specific person and a
     * specific submission rather than about permissions.
     */
    private function mayRunReport($id)
    {
        if (function_exists('is_admin') && is_admin()) { return true; }

        $cap = Leadfinder_reports::capabilityFor($id);

        /*
         * Spelled out, not looked up.
         *
         * The first version was `has_permission(..., $cap)`, which works and is
         * unreadable: neither a colleague nor CI's capability scanner can tell
         * from this file which permissions guard a report. It is the same defect
         * the `need()` helper was reshaped to avoid.
         *
         * Writing both branches also makes the failure closed: a report whose
         * capability is not one of these two is REFUSED rather than silently
         * passed to a lookup that would answer for whatever string it was given.
         */
        if ($cap === Leadfinder_caps::CAP_MANAGE_PROFILES) {
            return has_permission('payplex_leadfinder', '', 'leadfinder_manage_profiles') === true;
        }

        if ($cap === Leadfinder_caps::CAP_REPORTS) {
            return has_permission('payplex_leadfinder', '', 'leadfinder_reports') === true;
        }

        return false;
    }

    public function release($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_claim'));
        $this->requireSchema();

        $p = $this->lf->prospectInScope($prospectId, $this->actor(), $this->band(), $this->lf->teamOf($this->actor()));
        if (!$p) { show_404(); }

        $isManager = (function_exists('is_admin') && is_admin())
                     || has_permission('payplex_leadfinder', '', 'leadfinder_view_all');

        $d = Leadfinder_claim::canRelease($p, $this->actor(), $isManager === true);
        if (!$d['allowed']) {
            set_alert('warning', $this->lf->claimMessage($d['reason']));
        } else {
            $r = $this->lf->applyRelease($p, $this->actor(), $d['reason'], time(), $isManager === true);

            set_alert(!empty($r['released']) ? 'success' : 'warning',
                !empty($r['released'])
                    ? 'Prospect released.'
                    : 'This prospect is no longer held by you, so nothing was released.');
        }
        redirect(admin_url('payplex_leadfinder/finder/queue'));
    }

    /**
     * §2 — Admin-only API connection profiles.
     *
     * The view receives `Leadfinder_secret::forDisplay()` — a mask and a
     * fingerprint. The ciphertext is not passed to the view either: it has no
     * use in a browser and every value that reaches a template is one that can
     * end up in a page source, a cached HTML file or a screenshot.
     */
    public function api_profiles()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_manage_profiles'));
        $this->requireSchema();

        $this->load->view('payplex_leadfinder/api_profiles', array(
            'title'            => 'Configure Lead Finder API',
            'profiles'         => $this->lf->profilesForDisplay(),
            /*
             * The retention evidence is rendered on this page because this is
             * where the administrator already is. A compliance job whose history
             * lives on a screen nobody opens is a job nobody checks.
             */
            'retention_runs'   => $this->lf->retentionRuns(10),
            'expired_waiting'  => $this->lf->expiredCoordinatesWaiting(),
            'coords_max_days'  => $this->lf->configInt('coords_max_calendar_days', 30),
            /*
             * The browser map key lives here because it is a credential and
             * this is the credential screen — but it is deliberately a separate
             * form with a separate endpoint, because it is a separate key with
             * a different restriction. Nothing on this page ever displays
             * either key: a fingerprint, a length and a date are enough to
             * answer "which key is in place and did it change".
             */
            'map_key_status'   => $this->lf->browserMapKeyStatus(),
            /*
             * Flat, not a nested array. The contract suite reads the literal
             * keys of this array to work out which variables the view is owed,
             * and it cannot see into a nested literal — so a nested one reports
             * its inner keys as variables the view never uses. Two scalars say
             * the same thing and keep the checker honest.
             */
            'map_rule_browser' => $this->lf->config('browser_map_key_restriction', 'http_referrer'),
            'map_rule_server'  => $this->lf->config('server_places_key_restriction', 'ip'),
            'keyring'          => $this->lf->keyringHealth(),
            'purge_queue'      => $this->lf->purgeQueueSummary(time()),
        ));
    }

    /** §2 — write a profile. The only endpoint that accepts a key. */
    public function api_profile_save()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_manage_profiles'));
        $this->requireSchema();

        if (!$this->input->post()) { show_404(); }

        /*
         * A blank key field means "leave the stored key alone", never "erase
         * it". The field renders empty every time because the value is never
         * sent to the browser, so treating blank as an instruction would wipe
         * the key whenever somebody edited a monthly limit.
         *
         * The skip happens HERE, next to the promise the view makes, and not
         * only inside the model. CI's security scanner flagged the first
         * version for exactly that: the view said "leave blank to keep" while
         * this method passed the empty string straight through, and the guard
         * sat a layer away in saveProfile(). Both guards now exist — a
         * defence-in-depth for a secret is worth the duplication, and the
         * scanner was right that the promise and its enforcement should not be
         * in different files.
         */
        $postedKey = (string) $this->input->post('api_key');
        $apiKey    = trim($postedKey) === '' ? null : $postedKey;

        $r = $this->lf->saveProfile($this->actor(), array(
            'id'                   => (int) $this->input->post('id'),
            'name'                 => (string) $this->input->post('name'),
            'gcp_project'          => (string) $this->input->post('gcp_project'),
            'billing_label'        => (string) $this->input->post('billing_label'),
            'monthly_search_limit' => (int) $this->input->post('monthly_search_limit'),
            'monthly_detail_limit' => (int) $this->input->post('monthly_detail_limit'),
            'daily_usage_limit'    => (int) $this->input->post('daily_usage_limit'),
            'active'               => $this->input->post('active') ? 1 : 0,
            'expires_on'           => (string) $this->input->post('expires_on'),
            'effective_from'       => (string) $this->input->post('effective_from'),
            'per_staff_daily_limit'=> (int) $this->input->post('per_staff_daily_limit'),
            'admin_notes'          => (string) $this->input->post('admin_notes'),
            'staff_ids'            => (array) $this->input->post('staff_ids'),
        ), $apiKey);

        set_alert($r['ok'] ? 'success' : 'danger',
                  Leadfinder_secret::scrub($r['message']));
        redirect(admin_url('payplex_leadfinder/finder/api_profiles'));
    }

    /**
     * §14.3 — run the coordinate retention sweep now, by hand.
     *
     * The sweep runs on `after_cron_run`. This exists so an administrator can
     * force it and, more importantly, *see that it ran*: the run history is what
     * turns "we comply with the 30-day rule" from an assertion into a record.
     *
     * POST only. A retention sweep deletes data, and a GET endpoint that deletes
     * data is reachable from a prefetch, a crawler, or a link in an email.
     *
     * It takes the same lock as the cron. If the scheduled sweep is mid-run this
     * returns "already running" rather than starting a second one — the lock is
     * the arbiter for both callers, which is the only way the two cannot race.
     */
    public function run_retention()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_manage_profiles'));
        $this->requireSchema();

        if (!$this->input->post()) { show_404(); }

        require_once __DIR__ . '/../libraries/Leadfinder_retention_job.php';

        /*
         * The confirmation token is checked here as well as by the framework's
         * CSRF layer. Perfex's CSRF protection covers this URI today, and the
         * Facebook module in this same install has already needed that
         * protection turned OFF for one route — so "the framework will catch it"
         * is an assumption this action should not rest on. A destructive
         * maintenance job asked for by a GET-shaped forgery is exactly what both
         * layers exist to prevent.
         */
        if ((string) $this->input->post('confirm_retention') !== 'RUN') {
            set_alert('warning', 'The retention sweep was not started: the confirmation was missing.');
            redirect(admin_url('payplex_leadfinder/finder/api_profiles'));

            return;
        }

        $actor = $this->actor();

        if (!$this->lf->acquireJobLock(Leadfinder_retention_job::LOCK_KEY, time())) {
            set_alert('warning', 'The retention job is already running. Nothing was started.');
            redirect(admin_url('payplex_leadfinder/finder/api_profiles'));

            return;
        }

        try {
            $rec = $this->lf->runRetentionSweep(time(), 'manual', $actor);
            set_alert('success', 'Retention sweep finished: ' . (int) $rec['examined']
                . ' examined, ' . (int) $rec['purged'] . ' coordinate set(s) cleared.');
        } catch (Throwable $e) {
            $this->lf->recordRetentionFailure(time(), $e->getMessage(), 'manual', $actor);
            set_alert('danger', 'The retention sweep failed. The error is recorded in the run history.');
        }

        /* Released with this request's own token, so a sweep whose lock was
           reclaimed after a stall cannot unlock the process that reclaimed it. */
        $this->lf->releaseJobLock(Leadfinder_retention_job::LOCK_KEY, time(),
                                  $this->lf->jobLockOwner(Leadfinder_retention_job::LOCK_KEY));
        redirect(admin_url('payplex_leadfinder/finder/api_profiles'));
    }

    /* ================================================================
     * Phase 4 — Save, Waste, DNC, Undo, Purge, Re-key, Saved filters
     *
     * Every action below follows the same four steps, in this order:
     *
     *   1. capability     — may this person press this button at all
     *   2. POST           — is this a request they made, not one made for them
     *   3. scope          — may they reach THIS row (404 if not, never 403)
     *   4. the model      — which re-checks ownership and state before writing
     *
     * Step 4 is not redundant. The controller answers "may the button exist";
     * the model answers "may this row change". Hiding a button is not a
     * permission, and a bulk action loops through the model rather than around
     * it so that the per-row rules cannot be hoisted out of the loop.
     * ============================================================== */

    /** POST, or nothing. Used by every action that writes. */
    private function requirePost()
    {
        if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
        }
    }

    /** Where a row action returns to. Honours a returning screen, never an open redirect. */
    private function backTo($default = 'queue')
    {
        $to = (string) $this->input->post('return_to');

        $allowed = array('queue', 'index', 'waste');

        if (!in_array($to, $allowed, true)) { $to = $default; }

        return admin_url('payplex_leadfinder/finder/' . ($to === 'index' ? '' : $to));
    }

    private function isAdminActor()
    {
        return function_exists('is_admin') && is_admin();
    }

    /**
     * Save a search result to the employee's own shortlist.
     *
     * This writes to the module's shortlist table. It does not write to
     * `tblleads` — the only path into that table is an approved conversion,
     * which needs verification evidence, a submission and a second person.
     */
    public function save($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_view'));
        $this->requireSchema();
        $this->requirePost();

        $p = $this->lf->prospectInScope($prospectId, $this->actor(), $this->band(),
                                        $this->lf->teamOf($this->actor()));
        if (!$p) { show_404(); }

        /* Request input is read into its own statement first. The ownership
           suites assert that the session-derived identity and request input
           never share a statement in this controller, because an identity that
           could be supplied by the caller would make every ownership rule here
           decorative. */
        $note = (string) $this->input->post('note');

        $r = $this->lf->saveProspect((int) $prospectId, $this->actor(), time(), $note);

        if (!empty($r['saved'])) {
            set_alert('success', $r['reason'] === 'already_saved'
                ? 'That prospect was already on your list.'
                : 'Saved to your list. No CRM lead was created.');
        } else {
            set_alert('warning', 'Not saved: ' . str_replace('_', ' ', (string) $r['reason']) . '.');
        }

        redirect($this->backTo());
    }

    /** Remove one prospect from the actor's own shortlist. */
    public function unsave($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_view'));
        $this->requireSchema();
        $this->requirePost();

        $r = $this->lf->unsaveProspect((int) $prospectId, $this->actor());

        set_alert('success', $r['reason'] === 'removed'
            ? 'Removed from your list.'
            : 'That prospect was not on your list.');

        redirect($this->backTo());
    }

    /**
     * Save many results at once.
     *
     * The cap is enforced in the model and reported honestly here: a bulk
     * action that silently handles the first hundred of two hundred is a wrong
     * answer somebody will act on.
     */
    public function bulk_save()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_view'));
        $this->requireSchema();
        $this->requirePost();

        $ids = $this->input->post('ids');
        $ids = is_array($ids) ? $ids : array();

        $r = $this->lf->saveProspects($ids, $this->actor(), $this->band(),
                                      $this->lf->teamOf($this->actor()), time());

        if (empty($r['ok'])) {
            set_alert('warning', 'Too many rows selected. Select at most ' . (int) $r['max']
                . ' at a time. Nothing was saved.');
        } else {
            $res = $r['results'];
            set_alert($res['refused'] > 0 ? 'warning' : 'success',
                $res['done'] . ' saved, ' . $res['refused'] . ' refused.'
                . ($res['refused'] > 0 ? ' Refused rows were left exactly as they were.' : ''));
        }

        redirect($this->backTo());
    }

    /**
     * Mark a prospect as waste, with one of the nine stated reasons.
     *
     * The reason is mandatory and validated server-side; notes are mandatory
     * for "Other". A confirmation token is required in addition to the
     * framework's CSRF layer, because this action removes a row from somebody's
     * working queue and schedules the destruction of its contact details.
     */
    public function waste($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_verify'));
        $this->requireSchema();
        $this->requirePost();

        $p = $this->lf->prospectInScope($prospectId, $this->actor(), $this->band(),
                                        $this->lf->teamOf($this->actor()));
        if (!$p) { show_404(); }

        if ((string) $this->input->post('confirm_waste') !== 'WASTE') {
            set_alert('warning', 'Nothing was changed: the confirmation was missing.');
            redirect($this->backTo());

            return;
        }

        $reason = (string) $this->input->post('reason');
        $notes  = (string) $this->input->post('notes');

        $r = $this->lf->markWaste((int) $prospectId, $this->actor(), $reason, array(
            'notes'    => $notes,
            'source'   => 'manual',
            'is_admin' => $this->isAdminActor(),
            'now'      => time(),
        ));

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backTo());
    }

    /**
     * Mark a prospect as a duplicate.
     *
     * A waste decision with the reason fixed, so the reason cannot be supplied
     * by the request: the button says "duplicate" and the record has to agree
     * with the button.
     */
    public function mark_duplicate($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_verify'));
        $this->requireSchema();
        $this->requirePost();

        $p = $this->lf->prospectInScope($prospectId, $this->actor(), $this->band(),
                                        $this->lf->teamOf($this->actor()));
        if (!$p) { show_404(); }

        require_once __DIR__ . '/../libraries/Leadfinder_waste.php';

        $notes = (string) $this->input->post('notes');

        $r = $this->lf->markWaste((int) $prospectId, $this->actor(),
            Leadfinder_waste::R_DUPLICATE, array(
                'notes'    => $notes,
                'source'   => 'manual',
                'is_admin' => $this->isAdminActor(),
                'now'      => time(),
            ));

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backTo());
    }

    /**
     * Take back a waste decision, inside its window.
     *
     * Refused once the contact details have been purged, and refused outright
     * for a Do Not Contact record — reversing that is a deliberate
     * administrative act, not an undo button.
     */
    public function undo_waste($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_verify'));
        $this->requireSchema();
        $this->requirePost();

        /*
         * Scope is checked on the stored row, not on what the page showed. A
         * wasted prospect has left the active queue, so the check has to reach
         * a row the employee can no longer see in a list — which is exactly
         * what `prospectInScope` answers, and why the undo path uses it rather
         * than trusting the id in the form.
         */
        $p = $this->lf->prospectInScope($prospectId, $this->actor(), $this->band(),
                                        $this->lf->teamOf($this->actor()));
        if (!$p) { show_404(); }

        $r = $this->lf->undoWaste((int) $prospectId, $this->actor(), $this->isAdminActor(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backTo());
    }

    /**
     * Record that a business asked not to be contacted.
     *
     * Permanent. It never expires, its suppression survives the purge and a
     * pepper rotation, and it is not deleted by the waste sweep. The channel
     * and the date are required because this is the record somebody will be
     * asked to produce.
     */
    public function mark_dnc($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_verify'));
        $this->requireSchema();
        $this->requirePost();

        $p = $this->lf->prospectInScope($prospectId, $this->actor(), $this->band(),
                                        $this->lf->teamOf($this->actor()));
        if (!$p) { show_404(); }

        if ((string) $this->input->post('confirm_dnc') !== 'DNC') {
            set_alert('warning', 'Nothing was changed: the confirmation was missing.');
            redirect($this->backTo());

            return;
        }

        $request = array(
            'channel'       => (string) $this->input->post('channel'),
            'requested_on'  => (string) $this->input->post('requested_on'),
            'evidence_note' => (string) $this->input->post('evidence_note'),
            'is_admin'      => $this->isAdminActor(),
        );

        $r = $this->lf->markDnc((int) $prospectId, $this->actor(), $request, time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backTo());
    }

    /**
     * Delete a prospect outright. Administrator only.
     *
     * `leadfinder_manage_profiles` plus an administrator check plus a typed
     * confirmation plus a stated reason. Any suppression record for the
     * business is kept: removing a prospect is housekeeping, removing the thing
     * that stops us calling them again is not.
     */
    public function delete_prospect($prospectId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_manage_profiles'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->isAdminActor()) {
            access_denied('payplex_leadfinder');
        }

        if ((string) $this->input->post('confirm_delete') !== 'DELETE') {
            set_alert('warning', 'Nothing was deleted: the confirmation was missing.');
            redirect($this->backTo());

            return;
        }

        $r = $this->lf->deleteProspect((int) $prospectId, $this->actor(),
                                       (string) $this->input->post('reason'), true, time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backTo('queue'));
    }

    /**
     * Run the waste purge by hand.
     *
     * The same job the cron runs, with the same lock, the same keyring gate and
     * the same refusal when the retention period has not been stated. A manual
     * button that skipped any of those would be a second, weaker purge.
     */
    public function run_purge()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_manage_profiles'));
        $this->requireSchema();
        $this->requirePost();

        if ((string) $this->input->post('confirm_purge') !== 'PURGE') {
            set_alert('warning', 'The purge was not started: the confirmation was missing.');
            redirect(admin_url('payplex_leadfinder/finder/api_profiles'));

            return;
        }

        $r = $this->lf->runPurgeSweep(time(), 'manual', $this->actor());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect(admin_url('payplex_leadfinder/finder/api_profiles'));
    }

    /**
     * Re-key tombstones from an older pepper version onto the active one.
     *
     * Adds rows under the new version; removes nothing. Tombstones whose source
     * identifiers were already purged are marked impossible and keep their
     * original version, which is why the old version must stay in the keyring.
     */
    public function rekey_tombstones()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_manage_profiles'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->isAdminActor()) {
            access_denied('payplex_leadfinder');
        }

        $r = $this->lf->rekeyTombstones((string) $this->input->post('from_version'),
                                        $this->actor(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect(admin_url('payplex_leadfinder/finder/api_profiles'));
    }

    /**
     * Store the browser map key an administrator entered.
     *
     * A separate action from the profile save, because it is a separate
     * credential with a different restriction. Blank means keep. The key is
     * never echoed back, never logged and never returned by any endpoint.
     */
    public function save_map_key($profileId = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_manage_profiles'));
        $this->requireSchema();
        $this->requirePost();

        $r = $this->lf->saveBrowserMapKey((int) $profileId,
                                          (string) $this->input->post('browser_map_key'),
                                          (string) $this->input->post('browser_map_referrers'),
                                          $this->actor());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect(admin_url('payplex_leadfinder/finder/api_profiles'));
    }

    /** Save the current filter set under a name, for this employee only. */
    public function save_filter()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_view'));
        $this->requireSchema();
        $this->requirePost();

        $filters = $this->input->post('filters');

        $r = $this->lf->saveFilter($this->actor(), (string) $this->input->post('filter_name'),
                                   is_array($filters) ? $filters : array(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backTo('index'));
    }

    /** Delete one of the actor's own saved filters. Never anybody else's. */
    public function delete_filter($id = 0)
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_view'));
        $this->requireSchema();
        $this->requirePost();

        $r = $this->lf->deleteSavedFilter($this->actor(), (int) $id);

        set_alert(empty($r['ok']) ? 'warning' : 'success',
            empty($r['ok']) ? 'That filter is not yours, or no longer exists.' : 'Filter deleted.');

        redirect($this->backTo('index'));
    }

    /**
     * Set the two retention periods. Administrator only.
     *
     * This is the supported settings path for the periods that govern the
     * purge. It writes exactly two configuration rows, validated as a pair,
     * and touches nothing else — in particular it does not read, write or
     * reference the suppression keyring.
     */
    public function save_retention()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_manage_profiles'));
        $this->requireSchema();
        $this->requirePost();

        if (!$this->isAdminActor()) {
            access_denied('payplex_leadfinder');
        }

        if ((string) $this->input->post('confirm_retention_change') !== 'SET') {
            set_alert('warning', 'Nothing was changed: the confirmation was missing.');
            redirect($this->backTo('waste'));

            return;
        }

        $pii         = $this->input->post('waste_pii_retention_days');
        $suppression = $this->input->post('waste_suppression_days');

        $r = $this->lf->saveRetentionSettings($pii, $suppression, $this->actor(), time());

        set_alert(empty($r['ok']) ? 'warning' : 'success', (string) $r['message']);
        redirect($this->backTo('waste'));
    }

    /**
     * The waste and suppression screen.
     *
     * Read-only, capability-gated, and it shows counts and states rather than
     * suppression keys. There is no screen in this module that displays a
     * tombstone key, because a key is only useful to somebody trying to confirm
     * that a particular business is on the list.
     */
    public function waste_list()
    {
        $this->need(has_permission('payplex_leadfinder', '', 'leadfinder_view'));
        $this->requireSchema();

        require_once __DIR__ . '/../libraries/Leadfinder_waste.php';
        require_once __DIR__ . '/../libraries/Leadfinder_ui.php';
        require_once __DIR__ . '/../libraries/Leadfinder_retention_policy.php';

        /*
         * The band, the scope and the request-supplied options are built in
         * that order and kept in separate statements. Not style: the ownership
         * suites assert that no expression in this controller puts `band()` and
         * request input in the same statement, because the one defect that
         * would make every ownership rule here decorative is a band derived
         * from something the caller sent.
         */
        $band  = $this->band();
        $scope = Leadfinder_scope::listPredicate($this->actor(), $band,
                                                 $this->lf->teamOf($this->actor()));

        $filters = Leadfinder_ui::normaliseFilters($this->input->get());
        $page    = (int) $this->input->get('page');

        $rows = $this->lf->queueRows($scope, '', array(
            'waste_only' => true,
            'q'          => isset($filters['q']) ? $filters['q'] : '',
            'page'       => $page,
            'per_page'   => 50,
        ));

        $data = array(
            'title'         => 'Lead Finder — waste and suppression',
            'reasons'       => Leadfinder_waste::reasons(),
            'filters'       => $filters,
            'rows'          => $rows['rows'],
            'paging'        => $rows,
            'band'          => $band,
            'purge_queue'   => $this->lf->purgeQueueSummary(time()),
            'keyring'       => $this->lf->keyringHealth(),
            'versions'      => $this->lf->tombstoneVersionCounts(),
            'undo_window'   => $this->lf->wasteConfig(),
            'retention'     => $this->lf->retentionSettings(),
            'retention_fields' => Leadfinder_retention_policy::settings(),
            'is_admin'      => $this->isAdminActor(),
            'can_purge'     => has_permission('payplex_leadfinder', '', 'leadfinder_manage_profiles')
                               || $this->isAdminActor(),
        );

        $this->load->view('payplex_leadfinder/waste', $data);
    }
}
