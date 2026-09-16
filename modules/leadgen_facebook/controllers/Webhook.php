<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
 * This controller loads its own dependencies.
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
 * The public endpoint Meta calls.
 *
 * This is the only unauthenticated, internet-reachable entry point in the
 * module, and it writes rows into `tblleads` — names, email addresses and phone
 * numbers that feed the follow-up automation and, once calling is enabled, the
 * dialler. Somebody else choosing which numbers this system calls is a
 * different order of problem from junk in a list, so the order of business here
 * is: verify, record, then act.
 *
 * WHAT EVERY REQUEST NOW LEAVES BEHIND
 * ------------------------------------
 * One row in `tblleadgen_facebook_deliveries`, whatever happened. Before this
 * change a row was written only when a lead was created, so a refused delivery
 * was indistinguishable from no delivery at all — and on staging, twelve
 * configuration saves across four days produced exactly one lead, from a
 * hand-made test payload, with no way to tell whether Meta had ever called.
 *
 * The log is written after the decision is made and outside the transaction, so
 * a rolled-back delivery still records why. It carries no token, no signature
 * value and no unmasked contact detail; see Facebook_redactor for what survives
 * and what does not.
 */
class Webhook extends App_Controller
{
    /** Wall-clock start, for the processing_ms column. */
    private $t0 = 0.0;

    /** One id for this request, shared by the log row and the claim row. */
    private $requestId = '';

    public function __construct()
    {
        parent::__construct();

        $this->t0 = microtime(true);
        $this->requestId = Facebook_redactor::requestId();
        $this->load->model('leadgen_facebook/leadgen_facebook_model', 'fb');
    }

    public function index()
    {
        $method = strtoupper((string) $this->input->method());

        if ($method === 'GET') {
            $this->handleVerification();

            return;
        }

        if ($method !== 'POST') {
            /*
             * Neither a handshake nor a delivery. Recorded rather than ignored,
             * because a stream of these is how a misconfigured callback URL or a
             * scanner announces itself.
             */
            $this->finish(Facebook_delivery::VERIFY_BAD_MODE, array(
                'http_method' => $method,
                'event_type'  => 'unsupported_method',
            ));

            return;
        }

        $this->handleEvent();
    }

    /* ================================================================== */
    /* GET — the subscription handshake                                   */
    /* ================================================================== */

    private function handleVerification()
    {
        /*
         * PHP rewrites dots in query-string keys to underscores, so Meta's
         * `hub.mode` arrives as `hub_mode`. Reading `hub.mode` would silently
         * find nothing and fail every handshake.
         */
        $mode = (string) $this->input->get('hub_mode');
        $token = $this->input->get('hub_verify_token');
        $challenge = (string) $this->input->get('hub_challenge');
        $configured = (string) get_option('facebook_verify_token');

        $sourceKey = Facebook_guard::sourceKey(
            Facebook_guard::remoteAddress($_SERVER),
            $this->fb->ipSalt()
        );

        $base = array('http_method' => 'GET', 'event_type' => 'verification',
                      'source_key' => $sourceKey);

        /*
         * The handshake path is rate-limited too.
         *
         * It compares a shared secret in constant time, so hammering it does
         * not leak the token — but it is a public URL that does work, and
         * "only the POST path is throttled" is the kind of half-applied
         * control that reads as protection and is not. The limiter is the same
         * one, counting the same rows, so a source that floods the GET path
         * spends the budget it would otherwise use on the POST path.
         *
         * Content type and size are not checked here: a GET has no body, and a
         * check that can only ever pass is worse than no check.
         */
        $guard = Facebook_guard::check(
            0,
            'application/json',
            $this->fb->recentFromSource($sourceKey, time() - Facebook_guard::RATE_WINDOW_SECONDS),
            get_option('facebook_rate_limit_per_minute')
        );

        if ($guard['decision'] === Facebook_guard::RATE_LIMITED) {
            $base['failure_reason'] = $guard['reason'];
            $this->finish(Facebook_delivery::REJECTED_RATE_LIMITED, $base);

            return;
        }

        if (trim($configured) === '') {
            /*
             * 503, not 403. Nothing was wrong with the caller's request; this
             * install has no verify token to compare against. A sender reading
             * status codes should retry once it is configured rather than treat
             * the subscription as rejected.
             */
            $this->finish(Facebook_delivery::VERIFY_NOT_CONFIGURED, $base);

            return;
        }

        if ($mode !== 'subscribe') {
            $this->finish(Facebook_delivery::VERIFY_BAD_MODE, $base);

            return;
        }

        if ($token === null || !hash_equals($configured, (string) $token)) {
            /*
             * hash_equals, and the same 403 the bad-signature path returns. The
             * response says which check failed and nothing about what would have
             * passed it — no length, no prefix, no timing difference.
             */
            $this->finish(Facebook_delivery::VERIFY_BAD_TOKEN, $base);

            return;
        }

        $this->finish(Facebook_delivery::VERIFY_OK, $base, $this->safeChallenge($challenge));
    }

    /**
     * Echo the challenge back, restricted to the characters it can legitimately
     * contain.
     *
     * Meta sends a numeric string. The previous code echoed whatever arrived,
     * unescaped, from an unauthenticated GET parameter into an HTML response —
     * reflected output on a public URL. Constraining it to
     * `[A-Za-z0-9_-]{0,128}` keeps every real handshake working and leaves
     * nothing to reflect.
     */
    private function safeChallenge($challenge)
    {
        $clean = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $challenge);

        return substr((string) $clean, 0, 128);
    }

    /* ================================================================== */
    /* POST — an event delivery                                           */
    /* ================================================================== */

    private function handleEvent()
    {
        /*
         * ENVELOPE CHECKS, BEFORE THE BODY IS READ OR HASHED
         * --------------------------------------------------
         * This endpoint is CSRF-exempt, by a scoped exclusion covering exactly
         * this one URI. CSRF was never protecting it in any meaningful sense —
         * Meta cannot present a token, which is why every real delivery was
         * refused with "419 Page Expired" — but it WAS incidentally making a
         * stranger's POST free to refuse. The exemption removes that, so the
         * work it was accidentally doing is now done on purpose.
         *
         * Order matters and is the whole point:
         *
         *   1. rate limit, per source     cheapest, and protects everything below
         *   2. declared body size         before any digest is computed
         *   3. content type               free
         *   4. actual body size           Content-Length is the caller's claim
         *   5. app secret configured
         *   6. signature present
         *   7. signature correct          <- the only thing that decides genuineness
         *   8. only now, parse the payload
         *
         * Steps 1-4 are not security in place of the signature; they are in
         * front of it, so that reaching step 7 cannot be made arbitrarily
         * expensive by an unauthenticated caller. Every one of them writes a
         * log row.
         */
        $sourceKey = Facebook_guard::sourceKey(
            Facebook_guard::remoteAddress($_SERVER),
            $this->fb->ipSalt()
        );

        $declaredLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';

        $guard = Facebook_guard::check(
            $declaredLength,
            $contentType,
            $this->fb->recentFromSource($sourceKey, time() - Facebook_guard::RATE_WINDOW_SECONDS),
            get_option('facebook_rate_limit_per_minute')
        );

        $envelope = array(
            'http_method'    => 'POST',
            'event_type'     => 'delivery',
            'source_key'     => $sourceKey,
            'payload_bytes'  => $declaredLength,
            'headers_safe'   => json_encode(Facebook_redactor::safeHeaders($_SERVER)),
        );

        if ($guard['decision'] !== Facebook_guard::OK) {
            $envelope['failure_reason'] = $guard['reason'];
            $this->finish(Facebook_guard::outcomeFor($guard['decision']), $envelope);

            return;
        }

        $raw = (string) file_get_contents('php://input');

        /*
         * The second size check, against the body actually read.
         *
         * Content-Length is a claim. A chunked request carries none, and a
         * lying one can declare ten bytes and send ten megabytes. Checking
         * only the header would be a control that cannot refuse the case it
         * exists for.
         */
        $bodyCheck = Facebook_guard::checkBodyLength(strlen($raw));

        if ($bodyCheck['decision'] !== Facebook_guard::OK) {
            $envelope['payload_bytes'] = strlen($raw);
            $envelope['failure_reason'] = $bodyCheck['reason'];
            $this->finish(Facebook_guard::outcomeFor($bodyCheck['decision']), $envelope);

            return;
        }

        $signature = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256'])
            ? (string) $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';
        $appSecret = (string) get_option('facebook_app_secret');

        $base = array_merge($envelope, array(
            'payload_bytes'     => strlen($raw),
            'signature_present' => $signature !== '' ? 1 : 0,
            'signature_fp'      => $signature === '' ? '' : substr(hash('sha256', $signature), 0, 8),
        ));

        /*
         * An install with no secret cannot verify anything, so it refuses
         * instead of accepting everything.
         */
        if (trim($appSecret) === '') {
            $this->finish(Facebook_delivery::REJECTED_NOT_CONFIGURED, $base);

            return;
        }

        /*
         * Verification is not optional and the caller does not get to decide.
         *
         * This ran as `if ($app_secret !== '' && $signature !== '')`. The
         * signature comes from the request, so omitting the header made the
         * whole block disappear and the payload was processed as genuine.
         * Measured on staging before that was fixed: a WRONG signature was
         * refused with 403, a MISSING one was accepted with 200.
         */
        if ($signature === '') {
            $this->finish(Facebook_delivery::REJECTED_NO_SIGNATURE, $base);

            return;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $raw, $appSecret);

        if (!hash_equals($expected, $signature)) {
            $this->finish(Facebook_delivery::REJECTED_BAD_SIGNATURE, $base);

            return;
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || empty($data['entry']) || !is_array($data['entry'])) {
            $base['payload_redacted'] = Facebook_redactor::redact($data);
            $this->finish(Facebook_delivery::REJECTED_BAD_PAYLOAD, $base);

            return;
        }

        $base['payload_redacted'] = Facebook_redactor::redact($data);

        try {
            $result = $this->processEntries($data['entry'], $raw);
        } catch (Throwable $e) {
            /*
             * 500 and retryable. The claim row is the reason that is safe: a
             * delivery that threw either claimed its reference and rolled the
             * whole thing back, or never claimed it — either way Meta's retry
             * cannot produce a second lead.
             */
            log_activity('leadgen_facebook: delivery ' . $this->requestId
                . ' failed — ' . $e->getMessage());

            $base['failure_reason'] = 'Processing error; delivery not applied.';
            $this->finish(Facebook_delivery::FAILED_EXCEPTION, $base);

            return;
        }

        $this->finish($result['outcome'], array_merge($base, $result['log']));
    }

    /**
     * Walk the entries and act on the parts this module handles.
     *
     * Returns the single outcome that best describes the delivery, chosen by
     * significance rather than by order, so a payload that creates one lead and
     * skips one Messenger event is logged as a creation and not as a skip.
     */
    private function processEntries(array $entries, $raw)
    {
        $outcomes = array();
        $log = array();

        foreach ($entries as $entry) {
            if (!empty($entry['changes']) && is_array($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
                    if (!isset($change['field']) || $change['field'] !== 'leadgen') {
                        continue;
                    }

                    if (empty($change['value']) || !is_array($change['value'])) {
                        continue;
                    }

                    $one = $this->processLeadgen($change['value'], $raw);
                    $outcomes[] = $one['outcome'];
                    $log = array_merge($log, $one['log']);
                }
            }

            if (!empty($entry['messaging']) && is_array($entry['messaging'])) {
                /*
                 * Messenger capture creates a lead from anybody who sends the
                 * page a message, which is a far broader behaviour than Lead
                 * Ads and was never asked for on this install. It is off by
                 * default and the event is recorded as received-and-ignored
                 * rather than dropped, so turning it on later is a setting and
                 * not a code change.
                 */
                if ((string) get_option('facebook_messenger_enabled') !== '1') {
                    $outcomes[] = Facebook_delivery::ACCEPTED_NO_ACTION;
                    $log['failure_reason'] = 'Messenger capture is disabled; event recorded only.';
                    continue;
                }

                foreach ($entry['messaging'] as $event) {
                    $one = $this->processMessenger($event, $entry);
                    $outcomes[] = $one['outcome'];
                    $log = array_merge($log, $one['log']);
                }
            }
        }

        if (empty($outcomes)) {
            return array('outcome' => Facebook_delivery::ACCEPTED_NO_ACTION, 'log' => $log);
        }

        /* Most significant first. */
        foreach (array(Facebook_delivery::FAILED_EXCEPTION,
                       Facebook_delivery::ACCEPTED_UNASSIGNED,
                       Facebook_delivery::ACCEPTED_CREATED,
                       Facebook_delivery::ACCEPTED_MATCHED,
                       Facebook_delivery::ACCEPTED_DUPLICATE,
                       Facebook_delivery::ACCEPTED_NO_ACTION) as $rank) {
            if (in_array($rank, $outcomes, true)) {
                return array('outcome' => $rank, 'log' => $log);
            }
        }

        return array('outcome' => Facebook_delivery::ACCEPTED_NO_ACTION, 'log' => $log);
    }

    /* ================================================================== */
    /* One lead-ads submission                                            */
    /* ================================================================== */

    private function processLeadgen(array $value, $raw)
    {
        $leadgenId = isset($value['leadgen_id']) ? (string) $value['leadgen_id'] : '';
        $pageId = isset($value['page_id']) ? (string) $value['page_id'] : '';
        $formId = isset($value['form_id']) ? (string) $value['form_id'] : '';
        $campaignId = isset($value['campaign_id']) ? (string) $value['campaign_id'] : '';

        $log = array('leadgen_id' => $leadgenId, 'page_id' => $pageId, 'form_id' => $formId);

        if ($leadgenId === '') {
            $log['failure_reason'] = 'Change carried no leadgen_id.';

            return array('outcome' => Facebook_delivery::ACCEPTED_NO_ACTION, 'log' => $log);
        }

        /*
         * A cheap pre-check before a paid, rate-limited Graph API call. It is
         * not the duplicate defence — the UNIQUE key is — but there is no point
         * fetching a lead we have already stored.
         */
        $already = $this->fb->existingClaim('leadgen', $leadgenId);

        if ($already !== null) {
            $log['lead_id'] = $already['lead_id'] ? (int) $already['lead_id'] : null;
            $log['failure_reason'] = Facebook_delivery::reasonFor(Facebook_delivery::ACCEPTED_DUPLICATE);

            return array('outcome' => Facebook_delivery::ACCEPTED_DUPLICATE, 'log' => $log);
        }

        $fields = $this->fetchLeadFields($leadgenId);

        if ($fields['form_id'] !== '' && $formId === '') {
            $formId = $fields['form_id'];
            $log['form_id'] = $formId;
        }

        /* ---- source, status, assignment: all resolved, none hard-coded ---- */

        $sourceName = (string) get_option('facebook_lead_source_name');
        $sourceName = trim($sourceName) === '' ? 'Facebook Lead Ads' : $sourceName;
        $source = $this->fb->ensureSource($sourceName);

        if ($source['id'] === null) {
            throw new RuntimeException('lead source could not be resolved or created');
        }

        if ($source['created']) {
            log_activity('leadgen_facebook: created lead source "' . $sourceName
                . '" (id ' . (int) $source['id'] . ').');
        }

        if ($source['duplicates'] > 0) {
            log_activity('leadgen_facebook: ' . $source['duplicates'] . ' duplicate lead '
                . 'source row(s) named "' . $sourceName . '" — merge them.');
        }

        $wantedName = (string) get_option('facebook_lead_status_name');
        $wantedName = trim($wantedName) === '' ? Facebook_status::DEFAULT_NAME : $wantedName;

        $status = Facebook_status::resolve(
            $this->fb->leadStatuses(),
            get_option('facebook_default_lead_status'),
            $wantedName
        );

        if ($status['status_id'] === null) {
            throw new RuntimeException('no lead status exists to assign');
        }

        $assignment = Facebook_assignment::decide(
            (string) get_option('facebook_assignment_mode'),
            get_option('facebook_default_assignee'),
            Facebook_settings::parsePool(get_option('facebook_round_robin_pool'))['ids'],
            get_option('facebook_round_robin_last'),
            $this->fb->eligibleStaffIds()
        );

        $name = $fields['name'] !== ''
            ? $fields['name']
            : $sourceName . ' Lead ' . $leadgenId;

        $result = $this->fb->claimAndCreateLead(array(
            'channel'         => 'leadgen',
            'fb_ref'          => $leadgenId,
            'page_id'         => $pageId,
            'form_id'         => $formId,
            'campaign_id'     => $campaignId,
            'request_id'      => $this->requestId,
            'assignment_mode' => (string) get_option('facebook_assignment_mode'),
            'assigned_to'     => (int) $assignment['staff_id'],
            'needs_review'    => $assignment['needs_review'] ? 1 : 0,
            'name'            => $name,
            'email'           => $fields['email'],
            'phone'           => $fields['phone'],
            'source_id'       => (int) $source['id'],
            'status_id'       => (int) $status['status_id'],
            /*
             * Carried so the lead itself can be tagged with its business unit
             * and Page, not just its source. One CRM now receives leads from
             * several Pages across several businesses, so "which business unit
             * is this" is the first question asked about every lead.
             */
            'source_name'     => $sourceName,
            'page_name'       => (string) get_option('facebook_page_name'),
            /*
             * The stored body is the redacted field summary, not the raw
             * payload. The lead record holds the real contact details; the log
             * does not need a second unprotected copy of them.
             */
            'message_body'    => json_encode(Facebook_redactor::maskFieldData($fields['field_data'])),
            'raw_payload'     => Facebook_redactor::redact($raw),
            'now'             => date('Y-m-d H:i:s'),
        ));

        $log['lead_id'] = $result['lead_id'];
        $log['assignment_mode'] = (string) get_option('facebook_assignment_mode');
        $log['assigned_to'] = (int) $assignment['staff_id'];
        $log['needs_review'] = $assignment['needs_review'] ? 1 : 0;

        if ($result['outcome'] === 'duplicate') {
            $log['failure_reason'] = Facebook_delivery::reasonFor(Facebook_delivery::ACCEPTED_DUPLICATE);

            return array('outcome' => Facebook_delivery::ACCEPTED_DUPLICATE, 'log' => $log);
        }

        if ($result['outcome'] === 'failed') {
            $log['failure_reason'] = 'Lead creation rolled back; nothing written.';

            return array('outcome' => Facebook_delivery::FAILED_EXCEPTION, 'log' => $log);
        }

        /*
         * Handled, kept, and deliberately not a lead: the delivery carried
         * neither an email nor a phone number. Answered 200 because we DID
         * handle it — anything else makes Meta retry a delivery already stored.
         */
        if ($result['outcome'] === 'quarantined') {
            $log['failure_reason'] = Facebook_delivery::reasonFor(Facebook_delivery::ACCEPTED_QUARANTINED)
                . ' (' . (isset($result['reason']) ? $result['reason'] : 'no_contact_identifier') . ')';

            return array('outcome' => Facebook_delivery::ACCEPTED_QUARANTINED, 'log' => $log);
        }

        /* The rotation only advances when a lead was actually assigned. */
        if ($assignment['staff_id'] > 0) {
            update_option('facebook_round_robin_last', (string) (int) $assignment['staff_id']);
        }

        if ($result['outcome'] === 'matched') {
            $log['failure_reason'] = Facebook_delivery::reasonFor(Facebook_delivery::ACCEPTED_MATCHED);

            return array('outcome' => Facebook_delivery::ACCEPTED_MATCHED, 'log' => $log);
        }

        if ($assignment['needs_review']) {
            $this->alertAdmins($result['lead_id'], $assignment['reason']);
            $log['failure_reason'] = $assignment['reason'];

            return array('outcome' => Facebook_delivery::ACCEPTED_UNASSIGNED, 'log' => $log);
        }

        $refs = isset($result['refs']) ? $result['refs'] : array('tags_applied' => array(), 'fields_written' => array(), 'failures' => array());

        log_activity('leadgen_facebook: lead ' . (int) $result['lead_id']
            . ' created from delivery ' . $this->requestId
            . ' (status "' . $status['name'] . '" via ' . $status['source']
            . ', assigned to staff ' . (int) $assignment['staff_id']
            . ', tags [' . implode(', ', $refs['tags_applied']) . ']'
            . ', refs [' . implode(', ', $refs['fields_written']) . ']'
            . (empty($refs['failures']) ? '' : ', FAILED [' . implode(', ', $refs['failures']) . ']')
            . (empty($result['duplicates']) ? '' : ', POSSIBLE DUPLICATE of lead(s) '
                . implode(', ', array_map(function ($d) { return $d['other_lead_id'] . ' by ' . $d['match_type']; }, $result['duplicates'])))
            . ').');

        return array('outcome' => Facebook_delivery::ACCEPTED_CREATED, 'log' => $log);
    }

    /* ================================================================== */
    /* One Messenger event (off by default)                               */
    /* ================================================================== */

    private function processMessenger(array $event, array $entry)
    {
        $log = array();

        if (!empty($event['message']['is_echo'])) {
            return array('outcome' => Facebook_delivery::ACCEPTED_NO_ACTION, 'log' => $log);
        }

        $psid = isset($event['sender']['id']) ? (string) $event['sender']['id'] : '';

        if ($psid === '') {
            return array('outcome' => Facebook_delivery::ACCEPTED_NO_ACTION, 'log' => $log);
        }

        $already = $this->fb->existingClaim('messenger', $psid);

        if ($already !== null) {
            $log['lead_id'] = $already['lead_id'] ? (int) $already['lead_id'] : null;

            return array('outcome' => Facebook_delivery::ACCEPTED_DUPLICATE, 'log' => $log);
        }

        $sourceName = 'Facebook Messenger';
        $source = $this->fb->ensureSource($sourceName);

        if ($source['id'] === null) {
            throw new RuntimeException('messenger lead source could not be resolved');
        }

        $status = Facebook_status::resolve(
            $this->fb->leadStatuses(),
            get_option('facebook_default_lead_status'),
            (string) get_option('facebook_lead_status_name')
        );

        $assignment = Facebook_assignment::decide(
            (string) get_option('facebook_assignment_mode'),
            get_option('facebook_default_assignee'),
            Facebook_settings::parsePool(get_option('facebook_round_robin_pool'))['ids'],
            get_option('facebook_round_robin_last'),
            $this->fb->eligibleStaffIds()
        );

        $result = $this->fb->claimAndCreateLead(array(
            'channel'         => 'messenger',
            'fb_ref'          => $psid,
            'page_id'         => isset($entry['id']) ? (string) $entry['id'] : '',
            'form_id'         => '',
            'campaign_id'     => '',
            'request_id'      => $this->requestId,
            'assignment_mode' => (string) get_option('facebook_assignment_mode'),
            'assigned_to'     => (int) $assignment['staff_id'],
            'needs_review'    => $assignment['needs_review'] ? 1 : 0,
            'name'            => $sourceName . ' Lead ' . $psid,
            'email'           => '',
            'phone'           => '',
            'source_id'       => (int) $source['id'],
            'status_id'       => (int) $status['status_id'],
            'source_name'     => $sourceName,
            'page_name'       => (string) get_option('facebook_page_name'),
            'message_body'    => isset($event['message']['text'])
                                    ? '[' . strlen((string) $event['message']['text']) . ' chars]' : '',
            'raw_payload'     => Facebook_redactor::redact($event),
            'now'             => date('Y-m-d H:i:s'),
        ));

        $log['lead_id'] = $result['lead_id'];
        $log['assigned_to'] = (int) $assignment['staff_id'];
        $log['needs_review'] = $assignment['needs_review'] ? 1 : 0;

        if ($result['outcome'] === 'duplicate') {
            return array('outcome' => Facebook_delivery::ACCEPTED_DUPLICATE, 'log' => $log);
        }

        if ($result['outcome'] === 'failed') {
            return array('outcome' => Facebook_delivery::FAILED_EXCEPTION, 'log' => $log);
        }

        if ($assignment['needs_review']) {
            $this->alertAdmins($result['lead_id'], $assignment['reason']);

            return array('outcome' => Facebook_delivery::ACCEPTED_UNASSIGNED, 'log' => $log);
        }

        return array('outcome' => Facebook_delivery::ACCEPTED_CREATED, 'log' => $log);
    }

    /* ================================================================== */
    /* Graph API                                                          */
    /* ================================================================== */

    /**
     * Fetch the submitted answers for one lead.
     *
     * Returns empty strings rather than throwing when the call cannot be made
     * or fails: a lead with no contact details is still a lead that arrived,
     * and refusing the whole delivery because Graph was slow would make Meta
     * retry something that will fail again.
     *
     * The token is read here and never leaves this method. It is not logged,
     * not returned, and not included in the redacted payload — the redactor
     * masks any key containing "token" as a second line of defence.
     */
    private function fetchLeadFields($leadgenId)
    {
        $out = array('name' => '', 'email' => '', 'phone' => '',
                     'form_id' => '', 'field_data' => array());

        $response = $this->graphGet($leadgenId, array('fields' => 'field_data,form_id,created_time'));

        if (!is_array($response)) {
            return $out;
        }

        if (isset($response['form_id'])) {
            $out['form_id'] = (string) $response['form_id'];
        }

        if (empty($response['field_data']) || !is_array($response['field_data'])) {
            return $out;
        }

        $out['field_data'] = $response['field_data'];

        foreach ($response['field_data'] as $field) {
            $fieldName = strtolower(isset($field['name']) ? (string) $field['name'] : '');
            $fieldValue = isset($field['values'][0]) ? (string) $field['values'][0] : '';

            if (strpos($fieldName, 'email') !== false && $out['email'] === '') {
                $out['email'] = $fieldValue;
            } elseif ((strpos($fieldName, 'phone') !== false || strpos($fieldName, 'mobile') !== false)
                      && $out['phone'] === '') {
                $out['phone'] = $fieldValue;
            } elseif (strpos($fieldName, 'name') !== false && $out['name'] === '') {
                $out['name'] = $fieldValue;
            }
        }

        return $out;
    }

    /**
     * One Graph API GET.
     *
     * `trim((string) $token) === ''` rather than `$token === ''`: get_option()
     * returns null for an option that has never been set, and `null === ''` is
     * false in PHP, so the previous check passed a null token straight through
     * and called Graph with `access_token=`. That is a guaranteed-failing
     * request made on every delivery.
     */
    private function graphGet($path, array $params = array())
    {
        $token = get_option('facebook_page_access_token');

        if (trim((string) $token) === '') {
            return null;
        }

        $params['access_token'] = $token;
        $url = 'https://graph.facebook.com/v19.0/' . rawurlencode((string) $path)
            . '?' . http_build_query($params);

        /*
         * cURL first, url-fopen second.
         *
         * WHY BOTH
         * --------
         * `file_get_contents` on an https:// URL needs `allow_url_fopen`, which
         * plenty of shared hosts disable — and when it is off the call returns
         * false rather than raising anything. The delivery would then be
         * accepted, the lead created from the fallback name with no email and
         * no phone, and the log would say "accepted". A lead that arrives with
         * no way to contact the person, on a path that reports success, is the
         * exact defect shape this module was opened to fix.
         *
         * cURL is present on this host and on nearly every other; the fopen
         * path stays as the fallback for the reverse case.
         */
        $response = false;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);

            if ($ch !== false) {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

                $body = curl_exec($ch);
                $err = curl_error($ch);
                curl_close($ch);

                if ($body === false || $body === null) {
                    /*
                     * Logged without the URL: it carries the access token in a
                     * query parameter, and a transport error is exactly the
                     * moment somebody pastes the whole line into a ticket.
                     */
                    log_activity('leadgen_facebook: Graph request failed at the transport layer'
                        . ($err === '' ? '' : ' (' . substr($err, 0, 120) . ')')
                        . ' — delivery ' . $this->requestId . '.');
                } else {
                    $response = $body;
                }
            }
        }

        if ($response === false && ini_get('allow_url_fopen')) {
            $context = stream_context_create(array(
                'http' => array('timeout' => 8, 'ignore_errors' => true),
            ));

            $response = @file_get_contents($url, false, $context);
        }

        if ($response === false) {
            return null;
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : null;
    }

    /* ================================================================== */
    /* Alerts                                                             */
    /* ================================================================== */

    /**
     * Tell the administrators a lead has arrived that nobody owns.
     *
     * In-app notification plus an activity-log line. Deliberately NOT email:
     * outbound communication is off for this install and an alert that sends
     * mail would be a second sending path added under cover of a bug fix. The
     * notification appears in the bell menu of every active administrator,
     * which is where a CRM user looks.
     */
    private function alertAdmins($leadId, $reason)
    {
        log_activity('leadgen_facebook: lead ' . (int) $leadId
            . ' created with no valid assignee — ' . $reason
            . ' (delivery ' . $this->requestId . ').');

        try {
            $admins = $this->db->select('staffid')->where('admin', 1)->where('active', 1)
                               ->get(db_prefix() . 'staff')->result_array();

            foreach ($admins as $admin) {
                add_notification(array(
                    'description' => 'Facebook lead needs assignment: ' . $reason,
                    'touserid'    => (int) $admin['staffid'],
                    'link'        => 'leads/index/' . (int) $leadId,
                ));
            }
        } catch (Throwable $e) {
            /* An alert that fails must not fail the delivery. */
            log_activity('leadgen_facebook: admin notification failed — ' . $e->getMessage());
        }
    }

    /* ================================================================== */
    /* Recording and responding                                           */
    /* ================================================================== */

    /**
     * Write the log row, set the status code, send the body, stop.
     *
     * One exit point for every path, so no branch can return a status code
     * without also recording why — which is exactly how the silent-rejection
     * defect survived: the refusals returned early, before any logging code.
     */
    private function finish($outcome, array $fields, $body = null)
    {
        $status = Facebook_delivery::httpFor($outcome);

        $row = array_merge(array(
            'request_id'        => $this->requestId,
            'received_at'       => date('Y-m-d H:i:s'),
            'received_epoch'    => time(),
            'timezone'          => date_default_timezone_get(),
            'source_key'        => null,
            'http_method'       => 'GET',
            'event_type'        => 'unknown',
            'outcome'           => $outcome,
            'accepted'          => Facebook_delivery::isAccepted($outcome) ? 1 : 0,
            'http_status'       => $status,
            'retryable'         => Facebook_delivery::isRetryable($outcome) ? 1 : 0,
            'failure_reason'    => Facebook_delivery::reasonFor($outcome),
            'page_id'           => null,
            'form_id'           => null,
            'leadgen_id'        => null,
            'lead_id'           => null,
            'signature_present' => 0,
            'signature_fp'      => null,
            'payload_bytes'     => 0,
            'payload_redacted'  => null,
            'headers_safe'      => null,
            'assignment_mode'   => null,
            'assigned_to'       => 0,
            'needs_review'      => 0,
        ), $fields);

        $row['processing_ms'] = (int) round((microtime(true) - $this->t0) * 1000);

        if (isset($row['failure_reason'])) {
            $row['failure_reason'] = substr((string) $row['failure_reason'], 0, 191);
        }

        $this->fb->logDelivery($row);

        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Request-Id: ' . $this->requestId);

        echo $body === null ? Facebook_delivery::bodyFor($outcome) : $body;
        exit;
    }
}
