<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
 * This file loads its own dependencies.
 *
 * WHY, AND HOW IT WAS FOUND
 * -------------------------
 * The libraries were required by the module bootstrap
 * (`leadgen_facebook.php`), which Perfex executes only for modules marked
 * active. The HMVC router, however, resolves `/<module>/<controller>/<method>`
 * by finding the controller file on disk, and does not consult
 * `tblmodules.active` at all.
 *
 * So on a freshly deployed, not-yet-activated install, the public webhook URL
 * is routable and its classes are not loaded — and the endpoint answered a
 * live GET with **HTTP 500 and an empty body**. Measured on production
 * immediately after deployment, before activation.
 *
 * That is the wrong failure in two ways. Meta retries a 5xx, so an
 * unactivated install would collect retries for a delivery it never recorded;
 * and a fatal produces no log row, which is the exact silence this whole
 * change set exists to remove.
 *
 * `require_once` is idempotent, so this costs nothing when the bootstrap has
 * already run and makes the endpoint correct when it has not.
 */
require_once __DIR__ . '/../libraries/Facebook_settings.php';
require_once __DIR__ . '/../libraries/Facebook_redactor.php';
require_once __DIR__ . '/../libraries/Facebook_delivery.php';
require_once __DIR__ . '/../libraries/Facebook_status.php';
require_once __DIR__ . '/../libraries/Facebook_assignment.php';
require_once __DIR__ . '/../libraries/Facebook_guard.php';
require_once __DIR__ . '/../libraries/Facebook_health.php';
require_once __DIR__ . '/../libraries/Facebook_tagging.php';
require_once __DIR__ . '/../libraries/Facebook_identity.php';


/**
 * The admin side: settings, the inbound delivery log, and the review queue.
 *
 * TWO DEFECTS THIS CONTROLLER USED TO HAVE
 * ----------------------------------------
 * **1. It wrote whatever option name it was handed.**
 *
 *     $settings = $this->input->post('settings');
 *     foreach ($settings as $key => $value) { update_option($key, $value); }
 *
 * The names came from the request body. `settings[smtp_password]`,
 * `settings[default_timezone]`, `settings[allow_registration]` — all of them
 * would have been stored, because update_option() writes the name it is given
 * and does not care which form it came from. The five fields on the page are a
 * property of the HTML, not of the endpoint. Writes now go through
 * Facebook_settings::filter(), which drops every name that is not in an
 * explicit allowlist and reports what it dropped.
 *
 * **2. Its permission check disagreed with the sidebar's.** The menu was shown
 * to anyone with `leads: view`; the page required `settings: view` or
 * `leadgen_facebook: view`. Most of the sales team therefore saw a link that
 * refused them. Both now require exactly `leadgen_facebook: view` (or admin),
 * and a test extracts the capability set from each and asserts they are equal.
 */
class Leadgen_facebook extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->load->model('leadgen_facebook/leadgen_facebook_model', 'fb');

        if (!is_admin() && !staff_can('view', 'leadgen_facebook')) {
            access_denied('Leadgen Facebook');
        }
    }

    public function index()
    {
        if ($this->input->post()) {
            if (!is_admin() && !staff_can('edit', 'leadgen_facebook')) {
                access_denied('Leadgen Facebook');
            }

            $this->save();

            return;
        }

        $data['title'] = _l('leadgen_facebook_settings');
        $data['lead_statuses'] = $this->fb->leadStatuses();
        $data['lead_sources'] = $this->fb->leadSources();
        $data['staff'] = $this->db->select('staffid, firstname, lastname')
                                  ->where('active', 1)->order_by('firstname', 'asc')
                                  ->get(db_prefix() . 'staff')->result_array();

        /*
         * The webhook URL as Meta must be given it. Built from site_url() so it
         * is right on whichever install this page is open on, rather than a
         * value typed into a document that then goes stale.
         */
        $data['webhook_url'] = rtrim(site_url('leadgen_facebook/webhook'), '/');

        /*
         * Secrets are described, never rendered. See Facebook_settings for why
         * type="password" was not protecting anything.
         */
        $data['secret_state'] = array();

        foreach (Facebook_settings::SECRET_NAMES as $name) {
            $data['secret_state'][$name] = Facebook_settings::describeLine(get_option($name));
        }

        /* What the module would do with a delivery arriving right now. */
        $data['resolved_status'] = Facebook_status::resolve(
            $data['lead_statuses'],
            get_option('facebook_default_lead_status'),
            trim((string) get_option('facebook_lead_status_name')) === ''
                ? Facebook_status::DEFAULT_NAME
                : (string) get_option('facebook_lead_status_name')
        );

        $sourceName = trim((string) get_option('facebook_lead_source_name')) === ''
            ? 'Facebook Lead Ads'
            : (string) get_option('facebook_lead_source_name');

        $data['resolved_source_name'] = $sourceName;
        $data['resolved_source_id'] = Facebook_status::findSourceId($data['lead_sources'], $sourceName);

        $pool = Facebook_settings::parsePool(get_option('facebook_round_robin_pool'));

        $data['resolved_assignment'] = Facebook_assignment::decide(
            (string) get_option('facebook_assignment_mode'),
            get_option('facebook_default_assignee'),
            $pool['ids'],
            get_option('facebook_round_robin_last'),
            $this->fb->eligibleStaffIds()
        );

        $data['pool_dropped'] = $pool['dropped'];
        $data['totals'] = $this->fb->deliveryTotals();

        $this->load->view('settings', $data);
    }

    /**
     * Save the allowlisted settings.
     *
     * A rejected name is recorded in the activity log and shown to the
     * administrator. Silently ignoring it would look identical to saving it,
     * which is the failure mode an allowlist most needs to avoid.
     */
    private function save()
    {
        $filtered = Facebook_settings::filter($this->input->post('settings'));

        /* Validate the values that have a fixed set of legal answers. */
        if (isset($filtered['write']['facebook_assignment_mode'])
            && !Facebook_settings::isValidAssignmentMode($filtered['write']['facebook_assignment_mode'])) {
            unset($filtered['write']['facebook_assignment_mode']);
            $filtered['rejected'][] = 'facebook_assignment_mode (not a recognised mode)';
        }

        /*
         * Credential changes are recorded before the write, because the old
         * value has to be read to fingerprint it. Nothing but a fingerprint and
         * a length is stored; see recordCredentialEvent().
         */
        $staffId = function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0;

        foreach ($filtered['write'] as $name => $value) {
            if (Facebook_settings::isSecret($name)) {
                $this->fb->recordCredentialEvent($name, get_option($name), $value, $staffId);
            }

            update_option($name, $value);
        }

        /*
         * Clearing a credential is a deliberate act with its own control, not
         * something a blank field does by accident. An empty secret field means
         * "I did not retype it"; this checkbox means "remove it".
         */
        $cleared = array();
        $toClear = $this->input->post('clear_secret');

        if (is_array($toClear)) {
            foreach ($toClear as $name) {
                if (Facebook_settings::isSecret($name)) {
                    $this->fb->recordCredentialEvent($name, get_option($name), '', $staffId);
                    update_option($name, '');
                    $cleared[] = $name;
                }
            }
        }

        $note = 'Leadgen Facebook settings updated ('
            . count($filtered['write']) . ' saved, '
            . count($filtered['kept']) . ' credential(s) left unchanged, '
            . count($cleared) . ' cleared, '
            . count($filtered['rejected']) . ' rejected';

        if (!empty($filtered['rejected'])) {
            $note .= ': ' . implode(', ', array_slice($filtered['rejected'], 0, 10));
        }

        log_activity($note . ').');

        if (!empty($filtered['rejected'])) {
            set_alert('warning', 'Saved. These fields were not recognised and were ignored: '
                . implode(', ', array_slice($filtered['rejected'], 0, 10)));
        } else {
            set_alert('success', _l('settings_updated'));
        }

        redirect(admin_url('leadgen_facebook'));
    }

    /**
     * The inbound delivery log.
     *
     * This is the screen that makes a refused delivery visible, and the one
     * that gets compared line by line with Meta's own "Recent Deliveries".
     * Read-only: there is no edit or delete path, because an audit trail a user
     * can tidy up is not one.
     */
    public function deliveries()
    {
        $data['title'] = 'Facebook inbound deliveries';
        $data['outcome'] = (string) $this->input->get('outcome');
        $data['rows'] = $this->fb->deliveries(200, $data['outcome']);
        $data['totals'] = $this->fb->deliveryTotals();
        $data['outcomes'] = Facebook_delivery::all();

        $this->load->view('deliveries', $data);
    }

    /**
     * Accepted, rejected, failed and duplicate deliveries — plus what they mean.
     *
     * The delivery log solved the original problem: a refused delivery used to
     * leave no trace. This screen solves the one that replaced it — a log
     * nobody reads. It counts the buckets, and then says in a sentence what the
     * counts imply, because "12 consecutive signature failures" is data and
     * "the App Secret here does not match the app Meta is signing with" is the
     * answer.
     *
     * Read-only. No edit, no delete, no retry button: an audit trail a user can
     * tidy is not one, and a manual retry would duplicate what Meta already
     * retries by itself.
     */
    public function reports()
    {
        $hours = (int) get_option('facebook_health_window_hours');
        $hours = $hours > 0 ? $hours : 168;
        $since = time() - ($hours * 3600);

        $report = $this->fb->deliveryReport($since);

        $sourceName = trim((string) get_option('facebook_lead_source_name')) === ''
            ? 'Facebook Lead Ads'
            : (string) get_option('facebook_lead_source_name');
        $sourceId = Facebook_status::findSourceId($this->fb->leadSources(), $sourceName);
        $reviewCount = $sourceId === null ? 0 : count($this->fb->reviewQueue($sourceId, 500));

        $data['title'] = _l('facebook_reports_title');
        $data['hours'] = $hours;
        $data['summary'] = Facebook_health::summarise($report['totals']);
        $data['totals'] = $report['totals'];
        $data['retry_pending'] = $report['retry_pending'];
        $data['retryable'] = $this->fb->retryableDeliveries(25);
        $data['review_count'] = $reviewCount;
        $data['last_accepted'] = $report['last_accepted'];

        $queue = $this->fb->enrichmentQueue(200);
        $pending = 0;
        $exhausted = 0;

        foreach ($queue as $item) {
            if ((int) $item['resolved'] === 0) {
                $pending++;

                if (!empty($item['exhausted'])) {
                    $exhausted++;
                }
            }
        }

        $data['enrichment_pending'] = $pending;
        $data['enrichment_exhausted'] = $exhausted;

        $data['alerts'] = Facebook_health::alerts(array(
            'recent_outcomes'      => $report['recent_outcomes'],
            'review_count'         => $reviewCount,
            'last_accepted'        => $report['last_accepted'],
            'now'                  => time(),
            'total'                => $report['window_rows'],
            'enrichment_pending'   => $pending,
            'enrichment_exhausted' => $exhausted,
            'quarantine_open'      => $this->fb->quarantineOpenCount(),
            'duplicates_open'      => $this->fb->duplicateOpenCount(),
        ));

        $data['severity'] = Facebook_health::worstSeverity($data['alerts']);

        /* Credential history: fingerprints and lengths, never values. */
        $data['credential_events'] = $this->fb->credentialEvents(30);

        $data['credential_state'] = array();

        foreach (Facebook_settings::SECRET_NAMES as $name) {
            $data['credential_state'][$name] = Facebook_settings::describeLine(get_option($name));
        }

        $this->load->view('reports', $data);
    }

    /**
     * Leads from the Facebook source that nobody owns.
     *
     * Derived from the leads themselves rather than from a separate list, so a
     * lead somebody assigns through the normal Leads screen leaves this queue
     * immediately without anything having to remember to remove it.
     */
    public function review()
    {
        $sourceName = trim((string) get_option('facebook_lead_source_name')) === ''
            ? 'Facebook Lead Ads'
            : (string) get_option('facebook_lead_source_name');

        $sourceId = Facebook_status::findSourceId($this->fb->leadSources(), $sourceName);

        $data['title'] = 'Facebook leads awaiting assignment';
        $data['source_name'] = $sourceName;
        $data['source_id'] = $sourceId;
        $data['rows'] = $sourceId === null ? array() : $this->fb->reviewQueue($sourceId);

        $this->load->view('review', $data);
    }

    /**
     * Leads that were saved without all of their labels.
     *
     * Separate from the review queue on purpose. The review queue is about
     * ownership — a lead nobody has been told to call. This is about labelling
     * — a lead somebody will call, filed under the wrong business or missing
     * the campaign it came from. Mixing them would let the quieter one hide
     * behind the louder one.
     *
     * The retry is a POST and needs the same `edit` capability the settings
     * form does: it writes to leads.
     */
    public function enrichment()
    {
        if ($this->input->post()) {
            if (!is_admin() && !staff_can('edit', 'leadgen_facebook')) {
                access_denied('Leadgen Facebook');
            }

            $outcome = $this->fb->retryEnrichment(25);

            set_alert(
                empty($outcome['still_failing']) ? 'success' : 'warning',
                _l('facebook_enrichment_retry_done') . ' — '
                . (int) $outcome['attempted'] . ' / '
                . (int) $outcome['resolved'] . ' / '
                . (int) $outcome['still_failing']
            );

            redirect(admin_url('leadgen_facebook/enrichment'));
        }

        $data['title'] = _l('facebook_enrichment_title');
        $data['rows'] = $this->fb->enrichmentQueue(200);
        $data['max_attempts'] = (int) get_option('facebook_enrichment_max_attempts');

        $this->load->view('enrichment', $data);
    }

    /**
     * Deliveries that arrived with no way to contact anybody.
     *
     * Kept off the Leads list on purpose. Releasing one requires a contact
     * identifier, and the model enforces that rather than this screen, so the
     * rule holds for every caller.
     */
    public function quarantine()
    {
        if ($this->input->post()) {
            if (!is_admin() && !staff_can('edit', 'leadgen_facebook')) {
                access_denied('Leadgen Facebook');
            }

            $sourceName = trim((string) get_option('facebook_lead_source_name')) === ''
                ? 'Facebook Lead Ads'
                : (string) get_option('facebook_lead_source_name');

            $source = $this->fb->ensureSource($sourceName);
            $status = Facebook_status::resolve(
                $this->fb->leadStatuses(),
                (string) get_option('facebook_lead_status_name'),
                (string) get_option('facebook_default_lead_status')
            );

            $outcome = $this->fb->releaseQuarantine(
                (int) $this->input->post('quarantine_id'),
                (string) $this->input->post('email'),
                (string) $this->input->post('phone'),
                function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0,
                array(
                    'source_id'   => (int) $source['id'],
                    'status_id'   => (int) $status['status_id'],
                    'source_name' => $sourceName,
                )
            );

            if (!empty($outcome['ok'])) {
                set_alert('success', _l('facebook_quarantine_released') . ' #' . (int) $outcome['lead_id']);
            } else {
                set_alert('warning', _l('facebook_quarantine_release_failed')
                    . ' (' . (isset($outcome['error']) ? $outcome['error'] : 'unknown') . ')');
            }

            redirect(admin_url('leadgen_facebook/quarantine'));
        }

        $data['title'] = _l('facebook_quarantine_title');
        $data['rows'] = $this->fb->quarantineQueue(200);

        $this->load->view('quarantine', $data);
    }

    /**
     * Leads that resemble someone already in the CRM.
     *
     * Nothing here merges anything. A decision records a judgement — who made
     * it, why, and when — and the operator then merges the records in the CRM's
     * own UI with this row as the authorisation.
     */
    public function duplicates()
    {
        if ($this->input->post()) {
            if (!is_admin() && !staff_can('edit', 'leadgen_facebook')) {
                access_denied('Leadgen Facebook');
            }

            $outcome = $this->fb->decideDuplicate(
                (int) $this->input->post('duplicate_id'),
                (string) $this->input->post('decision'),
                (string) $this->input->post('reason'),
                function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0
            );

            if (!empty($outcome['ok'])) {
                set_alert('success', _l('facebook_duplicate_recorded'));
            } else {
                set_alert('warning', _l('facebook_duplicate_not_recorded')
                    . ' (' . (isset($outcome['error']) ? $outcome['error'] : 'unknown') . ')');
            }

            redirect(admin_url('leadgen_facebook/duplicates'));
        }

        $data['title'] = _l('facebook_duplicates_title');
        $data['rows'] = $this->fb->duplicateQueue(200, '');

        $this->load->view('duplicates', $data);
    }
}
