<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_api_client.php';
require_once __DIR__ . '/../libraries/Payplex_call_gates.php';
require_once __DIR__ . '/../libraries/Payplex_call_limits.php';
require_once __DIR__ . '/../libraries/Payplex_call_timezone.php';

/**
 * Admin AI Calling controller — dashboard, history, settings, and the
 * lead-level Call Now / Schedule actions.
 *
 * Authorization is enforced here (page + action + record + API), NOT in the view.
 * Calling preconditions fail CLOSED: consent + DND + hours + ownership must pass.
 */
class Aicalling extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_aicalling/aicalling_model');
        $this->load->model('payplex_aicalling/payplex_audit_model');
    }

    private function cap($cap)
    {
        return is_admin() || staff_can($cap, 'payplex_aicalling');
    }

    private function denyUnless($cond)
    {
        if (!$cond) {
            ajax_access_denied(); // 403 for XHR; access_denied() for full page
        }
    }

    /* ---------------- Dashboard ---------------- */
    public function index()
    {
        $this->denyUnless($this->cap('view'));
        $data['title'] = 'AI Calling';
        $data['enabled'] = get_option('payplex_aicalling_enabled') === '1';
        $data['can_view_all'] = $this->cap('view_all');
        $data['health'] = $this->db->order_by('id', 'DESC')->limit(1)
            ->get(db_prefix() . 'payplex_integration_health')->row();
        $data['recent'] = $this->aicalling_model->listForStaff(
            get_staff_user_id(), $data['can_view_all'], [], 20, 0
        );
        $data['budget_warnings'] = $this->budgetWarnings($data['can_view_all']);
        $data['balance_inr'] = $this->balanceInInr($data['health']);
        $this->load->view('payplex_aicalling/dashboard', $data);
    }

    /** A converted display figure only — never the balance Sonivo itself reports. Null unless USD + a rate are both set. */
    private function balanceInInr($health)
    {
        $rate = get_option('payplex_aicalling_usd_inr_rate');
        if (!$health || $health->balance_amount === null || strtoupper((string) $health->balance_currency) !== 'USD'
            || !is_numeric($rate) || (float) $rate <= 0) {
            return null;
        }
        return round((float) $health->balance_amount * (float) $rate, 2);
    }

    /** Warn before the hard budget refusal at 100% — account only for view_all, own spend always. */
    private function budgetWarnings($canViewAll)
    {
        $since = date('Y-m-01 00:00:00');
        $checks = ['Your agent' => [$this->aicalling_model->spendSince($since, get_staff_user_id()),
                                     get_option('payplex_aicalling_agent_budget')]];
        if ($canViewAll) {
            $checks = ['Account' => [$this->aicalling_model->spendSince($since),
                                      get_option('payplex_aicalling_account_budget')]] + $checks;
        }
        $out = [];
        foreach ($checks as $label => $c) {
            [$spend, $cap] = $c;
            if (!is_numeric($cap) || (float) $cap <= 0) { continue; }
            $pct = (float) $spend / (float) $cap * 100;
            if ($pct >= 80) {
                $out[] = sprintf('%s budget %.0f%% used (%.2f of %.2f) — calls refuse at 100%%.', $label, $pct, $spend, $cap);
            }
        }
        return $out;
    }

    /* ---------------- Manager / team dashboard ---------------- */
    public function team()
    {
        $this->denyUnless($this->cap('view_all'));
        $data['title'] = 'AI Calling — Team';
        // Team-scoped call aggregates per staff.
        $data['byStaff'] = $this->db->query(
            "SELECT staff_id, COUNT(*) total,
                    SUM(status='completed') completed,
                    SUM(status='failed') failed,
                    SUM(cost) cost
             FROM " . db_prefix() . "payplex_calls
             GROUP BY staff_id ORDER BY total DESC LIMIT 100"
        )->result();
        $this->load->view('payplex_aicalling/team', $data);
    }

    /* ---------------- Employee workspace ---------------- */
    public function my()
    {
        $this->denyUnless($this->cap('view'));
        $data['title'] = 'My AI Calls';
        $staffId = get_staff_user_id();
        $data['mine'] = $this->aicalling_model->listForStaff($staffId, false, [], 50, 0);
        $data['stats'] = $this->db->query(
            "SELECT COUNT(*) total, SUM(status='completed') completed, SUM(status='scheduled') scheduled
             FROM " . db_prefix() . "payplex_calls WHERE staff_id = ?", [$staffId]
        )->row();
        $this->load->view('payplex_aicalling/my_workspace', $data);
    }

    public function history()
    {
        $this->denyUnless($this->cap('view'));
        $data['title'] = 'Call History';
        $data['can_view_all'] = $this->cap('view_all');
        $filters = ['status' => $this->input->get('status')];
        $data['calls'] = $this->aicalling_model->listForStaff(
            get_staff_user_id(), $data['can_view_all'], $filters, 100, 0
        );
        $this->load->view('payplex_aicalling/call_history', $data);
    }

    /* ---------------- Start / schedule a call ---------------- */
    public function start_call($leadId)
    {
        $this->denyUnless($this->cap('create'));
        if (!$this->input->is_ajax_request()) {
            ajax_access_denied();
        }
        echo json_encode($this->placeCall((int) $leadId, array(
            'schedule_at' => $this->input->post('schedule_at') ?: null,
            'agent_id'    => $this->input->post('agent_id'),
            'language'    => $this->input->post('language') ?: 'en-IN',
            'objective'   => $this->input->post('objective'),
        )));
    }

    /* ---------------- Retry a failed call ----------------
     *
     * The interface has offered a Retry button since v0.2.0. It had no click
     * handler and no endpoint behind it — clicking it did nothing at all,
     * while a 'retry' capability existed and appeared to govern something.
     *
     * A retry is a new call, so it goes through placeCall() and therefore
     * through every gate a first attempt faces: consent, DND, calling hours,
     * ownership, frequency, cooldown, duplicate suppression, budget,
     * disclosure and the kill switch. A retry can never reach a lead that a
     * first call would have been refused for, and it counts against the
     * attempt limits like any other call.
     */
    public function retry_call($callId)
    {
        $this->denyUnless($this->cap('retry'));
        if (!$this->input->is_ajax_request()) {
            ajax_access_denied();
        }

        $call = $this->aicalling_model->getById((int) $callId);
        if (!$call) {
            echo json_encode(['success' => false, 'code' => 'call_not_found',
                'message' => 'That call no longer exists.']);
            return;
        }
        /*
         * The same record-scope rule the other id-taking actions use. It was
         * written out again here, which is how one copy gets a fix and the
         * other does not.
         */
        $this->requireOwnershipOrViewAll((int) $callId);
        if ((string) $call->status !== 'failed') {
            echo json_encode(['success' => false, 'code' => 'not_failed',
                'message' => 'Only a failed call can be retried.']);
            return;
        }
        if (!$call->crm_lead_id) {
            echo json_encode(['success' => false, 'code' => 'no_lead',
                'message' => 'That call is not attached to a lead.']);
            return;
        }

        $this->payplex_audit_model->log('call.retry_requested', 'call', $call->id,
            null, ['original_status' => $call->status]);

        echo json_encode($this->placeCall((int) $call->crm_lead_id, array(
            'schedule_at' => null,
            'agent_id'    => $call->agent_id,
            'language'    => $call->language,
            'objective'   => null,
            'retry_of'    => (int) $call->id,
        )));
    }

    /**
     * The single path by which a call is placed.
     *
     * Both the first attempt and a retry come through here, so there is one
     * place where the gates are applied and one place to get them wrong.
     *
     * @return array the JSON response body
     */
    private function placeCall($leadId, array $params)
    {
        $scheduleAt = isset($params['schedule_at']) ? $params['schedule_at'] : null;

        // ---- Fail-closed preconditions (§4.3 / §4.4) ----
        $precheck = $this->callAllowed($leadId);
        if ($precheck !== true) {
            $this->payplex_audit_model->log('call.blocked', 'lead', $leadId, null,
                ['reason' => $precheck, 'retry_of' => isset($params['retry_of']) ? $params['retry_of'] : null]);
            return ['success' => false, 'code' => $precheck,
                    'message' => $this->reasonMessage($precheck)];
        }

        if (get_option('payplex_aicalling_enabled') !== '1') {
            return ['success' => false, 'code' => 'integration_disabled',
                    'message' => 'AI Calling is currently disabled by an administrator.'];
        }

        $idem = Payplex_api_client::uuid();
        $corr = Payplex_api_client::uuid();

        /*
         * The duration cap and the recording disclosure are only controls if
         * they travel WITH the call. A cap the CRM knows about but never sends
         * bounds nothing, and a disclosure that gates the button but never
         * reaches the agent is not read to the person being recorded.
         */
        $payload = [
            'crm_lead_id'      => $leadId,
            'staff_id'         => get_staff_user_id(),
            'agent_id'         => isset($params['agent_id']) ? $params['agent_id'] : null,
            'language'         => isset($params['language']) && $params['language'] ? $params['language'] : 'en-IN',
            'objective'        => isset($params['objective']) ? $params['objective'] : null,
            'consent_ref'      => 'consent:lead:' . $leadId,
            'callback_at'      => $scheduleAt,
            'max_duration_sec' => Payplex_call_limits::maxDurationSeconds(
                                      get_option('payplex_aicalling_max_duration_sec')),
            'recording_disclosure' => (string) get_option('payplex_aicalling_recording_disclosure'),
        ];

        // Mirror row first (pending) so we never lose track even if the API hiccups.
        $mirrorId = $this->aicalling_model->createMirror([
            'crm_lead_id'     => $leadId,
            'staff_id'        => get_staff_user_id(),
            'direction'       => 'outbound',
            'correlation_id'  => $corr,
            'idempotency_key' => $idem,
            'agent_id'        => $payload['agent_id'],
            'language'        => $payload['language'],
            'status'          => $scheduleAt ? 'scheduled' : 'pending',
            'scheduled_at'    => $scheduleAt,
            'consent_ref'     => $payload['consent_ref'],
        ]);

        $client = new Payplex_api_client();
        $res = $client->createCall($payload, $idem, $corr);

        if ($res['ok']) {
            $this->aicalling_model->updateById($mirrorId, [
                'sonivo_call_id' => $res['data']['call_id'] ?? null,
                'status'         => $res['data']['status'] ?? 'queued',
            ]);
            $this->payplex_audit_model->log('call.initiated', 'lead', $leadId, null,
                ['call_id'  => $res['data']['call_id'] ?? null,
                 'scheduled'=> (bool) $scheduleAt,
                 'retry_of' => isset($params['retry_of']) ? $params['retry_of'] : null], $corr);
            return ['success' => true, 'call_id' => $res['data']['call_id'] ?? null,
                    'status' => $res['data']['status'] ?? 'queued'];
        }

        // Queue to the outbox for reconciliation if it was a transient failure.
        if (($res['error']['retryable'] ?? false)) {
            $this->aicalling_model->queueOutbox($idem, '/api/v1/calls', 'POST', $payload, $corr);
            $this->aicalling_model->updateById($mirrorId, ['status' => 'queued_local']);
        } else {
            $this->aicalling_model->updateById($mirrorId, [
                'status'         => 'failed',
                'failure_reason' => substr((string) ($res['error']['code'] ?? 'unknown'), 0, 64),
            ]);
        }
        $this->payplex_audit_model->log('call.failed', 'lead', $leadId, null,
            ['error' => $res['error']['code'] ?? 'unknown'], $corr);

        return ['success' => false, 'code' => $res['error']['code'] ?? 'call_failed',
                'message' => $res['error']['message'] ?? 'Could not place the call. It has been queued for retry.'];
    }

    public function cancel_call($callId)
    {
        $this->denyUnless($this->cap('cancel'));
        $this->requireOwnershipOrViewAll($callId);
        $call = $this->aicalling_model->getById((int) $callId);
        if (!$call || !$call->sonivo_call_id) {
            echo json_encode(['success' => false, 'message' => 'Call not found.']);
            return;
        }
        $client = new Payplex_api_client();
        $res = $client->cancelCall($call->sonivo_call_id);
        if ($res['ok']) {
            $this->aicalling_model->updateById($callId, ['status' => 'cancelled']);
            $this->payplex_audit_model->log('call.cancelled', 'call', $call->sonivo_call_id);
        }
        echo json_encode(['success' => $res['ok']]);
    }

    public function recording($callId)
    {
        $this->denyUnless($this->cap('recording_access'));
        $this->requireOwnershipOrViewAll($callId);
        $call = $this->aicalling_model->getById((int) $callId);
        if (!$call || !$call->recording_available) {
            show_404();
        }
        $client = new Payplex_api_client();
        $res = $client->recordingUrl($call->sonivo_call_id);
        // Every recording access is audited (Deliverable 17 CR-02).
        $this->payplex_audit_model->log('recording.accessed', 'call', $call->sonivo_call_id);
        if ($res['ok'] && !empty($res['data']['url'])) {
            redirect($res['data']['url']); // signed, expiring URL minted by Sonivo
        }
        show_404();
    }

    /** Call transcript. */
    public function transcript($callId)
    {
        $this->denyUnless($this->cap('transcript_access'));
        $this->requireOwnershipOrViewAll($callId);
        $call = $this->aicalling_model->getById((int) $callId);
        if (!$call || !$call->transcript_available) {
            show_404();
        }

        $client = new Payplex_api_client();
        $res = $client->transcript($call->sonivo_call_id);
        $this->payplex_audit_model->log('transcript.accessed', 'call', $call->sonivo_call_id);

        $this->load->view('payplex_aicalling/transcript', [
            'title'      => 'Call Transcript',
            'call'       => $call,
            'ok'         => (bool) $res['ok'],
            'transcript' => $res['ok'] ? ($res['data'] ?? null) : null,
            'error'      => $res['ok'] ? null : $this->apiErrorMessage($res),
        ]);
    }

    /** api_client's 'error' is a string OR an array depending on the failure — normalize it here. */
    private function apiErrorMessage($res)
    {
        $e = $res['error'] ?? null;
        if (is_string($e) && $e !== '') { return $e; }
        if (is_array($e) && !empty($e['message'])) { return $e['message']; }
        return 'Could not load the transcript.';
    }

    /** Call detail: the mirror row plus its ordered event timeline. */
    public function call_detail($callId)
    {
        $this->denyUnless($this->cap('view'));
        $this->requireOwnershipOrViewAll($callId);
        $call = $this->aicalling_model->getById((int) $callId);
        if (!$call) {
            show_404();
        }
        $this->load->view('payplex_aicalling/call_detail', [
            'title'       => 'Call #' . (int) $call->id,
            'call'        => $call,
            'events'      => $this->aicalling_model->eventsForCall((int) $call->id),
            'next_action' => $this->suggestNextAction($call),
            'can_view_recording'  => $this->cap('recording_access'),
            'can_view_transcript' => $this->cap('transcript_access'),
        ]);
    }

    /**
     * A suggestion, not backend data — derived only from what this call already told us.
     * The callback date/time itself gets its own separate line in the view (and its own
     * Reminder), so this never repeats it — kept apart to avoid the two reading as one mixed sentence.
     */
    private function suggestNextAction($call)
    {
        if (!empty($call->callback_date)) { return 'A callback was requested — see the reminder below.'; }
        if ($call->status === 'failed') { return 'Retry the call.'; }
        $map = ['pricing_request' => 'Send a quotation.', 'demo_request' => 'Schedule a demo.',
                'not_interested'  => 'Mark as not interested — do not re-call.'];
        $intent = strtolower((string) $call->detected_intent);
        if (isset($map[$intent])) { return $map[$intent]; }
        if ($call->disposition === 'interested') { return 'Follow up with a call or message.'; }
        return null;
    }

    /* ---------------- Settings (secrets encrypted at rest) ---------------- */
    public function settings()
    {
        $this->denyUnless($this->cap('settings'));
        if ($this->input->post()) {
            $this->load->library('encryption');

            /*
             * Three things this loop used to get wrong, all of them silent.
             *
             * 1. THE KILL SWITCH COULD NOT BE SWITCHED OFF. An unchecked
             *    checkbox is simply absent from the POST, so post('enabled')
             *    returned null and the `!== null` guard skipped the write. The
             *    option kept its old value of 1. Unticking the box, saving, and
             *    seeing "Settings saved." left AI Calling enabled. A safety
             *    control that cannot be turned off is not a safety control, so
             *    the checkbox is now read explicitly as a boolean.
             *
             * 2. "LEAVE BLANK TO KEEP" ERASED THE SECRET. The password inputs
             *    always submit, as ''. Empty skipped encryption but still passed
             *    `!== null`, so every save wrote '' over the stored JWT and
             *    signing secrets — exactly the opposite of what the placeholder
             *    promised. Blank now means keep.
             *
             * 3. The calling window took any value at all. A typo saved an
             *    unusable window; the gate then refused every call and the
             *    reason was nowhere on screen.
             */
            $changed = [];

            // plain values: written as given
            /*
             * The classification lists are normalised on the way in — lowercased,
             * trimmed, de-duplicated — so a stray "Invalid_Number, " cannot
             * produce an entry that never matches, or an empty one that matches
             * a blank disposition. Storing what the admin typed and normalising
             * only at read time would leave the settings screen showing
             * something different from what is enforced.
             */
            foreach (['in_flight_statuses', 'invalid_dispositions', 'human_dispositions'] as $f) {
                $val = $this->input->post($f, false);
                if ($val === null) { continue; }
                $normalised = implode(', ', Payplex_call_limits::parseList($val));
                if ((string) get_option('payplex_aicalling_' . $f) !== $normalised) {
                    update_option('payplex_aicalling_' . $f, $normalised);
                    $changed[] = $f;
                }
            }

            foreach (['timeout_connect', 'timeout_read', 'timezone', 'fallback_timezone',
                      'max_per_day', 'max_per_week', 'cooldown_minutes', 'max_duration_sec',
                      'agent_budget', 'account_budget', 'recording_disclosure', 'usd_inr_rate'] as $f) {
                $val = $this->input->post($f, false);
                if ($val !== null) {
                    update_option('payplex_aicalling_' . $f, trim((string) $val));
                    $changed[] = $f;
                }
            }

            /*
             * base_url is not like its neighbours above: none of them has a
             * safe empty state to fall back to. timeout_connect/timeout_read
             * fall back to a hardcoded default when blank ('' ?: 5), and
             * fallback_timezone/the budgets/the disclosure are DESIGNED to
             * refuse calling when blank. base_url has no such fallback
             * anywhere downstream — Payplex_api_client only refuses when the
             * request secret is blank, never when base_url is, so an empty
             * base_url still reports itself as "configured" and every
             * outbound call is sent to a bare path with no host, fails after
             * four retries with a raw curl error, and "Settings saved." is
             * shown exactly as it would be for a real value. Blank must be
             * refused here, the same way an unusable calling window is.
             */
            $baseUrlPosted = $this->input->post('base_url', false);
            if ($baseUrlPosted !== null) {
                $newBaseUrl = trim((string) $baseUrlPosted);
                if ($newBaseUrl === '') {
                    set_alert('warning', 'Sonivo Base URL was not saved: it cannot be left blank, '
                        . 'or every AI Calling action would fail. The previous value was kept.');
                } elseif ((string) get_option('payplex_aicalling_base_url') !== $newBaseUrl) {
                    update_option('payplex_aicalling_base_url', $newBaseUrl);
                    $changed[] = 'base_url';
                }
            }

            // secrets: blank means keep, never overwrite
            foreach (['service_jwt', 'request_secret', 'webhook_secret'] as $f) {
                $val = $this->input->post($f, false);
                if ($val === null || trim((string) $val) === '') {
                    continue;
                }
                update_option('payplex_aicalling_' . $f, $this->encryption->encrypt($val));
                $changed[] = $f; // the name only — never the value
            }

            // the calling window: refuse to store one the gate cannot use
            $hs = $this->input->post('hours_start', false);
            $he = $this->input->post('hours_end', false);
            if ($hs !== null && $he !== null) {
                if (!is_numeric($hs) || !is_numeric($he)
                    || (int) $hs < 0 || (int) $hs > 23
                    || (int) $he < 1 || (int) $he > 24
                    || (int) $hs >= (int) $he) {
                    set_alert('warning', 'Calling hours were not saved: the start hour must be 0-23, '
                        . 'the end hour 1-24, and the start must come before the end.');
                } else {
                    update_option('payplex_aicalling_hours_start', (int) $hs);
                    update_option('payplex_aicalling_hours_end', (int) $he);
                    $changed[] = 'calling_hours';
                }
            }

            /*
             * The recording-disclosure confirmation is a checkbox, so it has
             * the same absent-means-off problem the kill switch had. It is also
             * re-armed whenever the disclosure TEXT changes: a confirmation
             * carried over from wording nobody has read is not a confirmation.
             */
            $newDisclosure = trim((string) $this->input->post('recording_disclosure', false));
            $oldDisclosure = trim((string) get_option('payplex_aicalling_recording_disclosure'));
            $confirmed = $this->input->post('disclosure_confirmed') ? '1' : '0';
            if ($newDisclosure !== $oldDisclosure && $confirmed === '1'
                && !$this->input->post('disclosure_reconfirmed')) {
                $confirmed = '0';
                set_alert('warning', 'The recording disclosure text changed, so its confirmation was '
                    . 'cleared. Read the new wording and confirm it again before calling resumes.');
            }
            if ((string) get_option('payplex_aicalling_disclosure_confirmed') !== $confirmed) {
                update_option('payplex_aicalling_disclosure_confirmed', $confirmed);
                $changed[] = 'disclosure_confirmed=' . $confirmed;
            }

            // the kill switch: an absent checkbox means OFF, and must be written
            $enabled = $this->input->post('enabled') ? '1' : '0';
            if ((string) get_option('payplex_aicalling_enabled') !== $enabled) {
                update_option('payplex_aicalling_enabled', $enabled);
                $changed[] = 'enabled=' . $enabled;
            }

            $this->payplex_audit_model->log('config.changed', 'settings', 'payplex_aicalling',
                null, ['fields' => $changed]);
            set_alert('success', 'Settings saved.');
            redirect(admin_url('payplex_aicalling/aicalling/settings'));
        }
        $data['title'] = 'AI Calling Settings';
        $cfg = array(
            'in_flight_statuses'   => get_option('payplex_aicalling_in_flight_statuses'),
            'invalid_dispositions' => get_option('payplex_aicalling_invalid_dispositions'),
            'human_dispositions'   => get_option('payplex_aicalling_human_dispositions'),
        );
        $data['observed'] = $this->aicalling_model->observedCallValues();
        $data['tz_coverage'] = Payplex_call_timezone::coverage(
            $this->aicalling_model->leadCountryIsoCounts(),
            array('fallback_timezone' => $this->fallbackTimezone()));
        $data['unclassified'] = Payplex_call_limits::unclassifiedValues(
            $data['observed']['statuses'], $data['observed']['dispositions'], $cfg);
        /*
         * The audit log has been hash-chained since it was built and the
         * verifier was never called from anywhere, so the tamper-evidence was
         * a property of the schema that nobody could see. Reported here as a
         * fact rather than left as a claim in a docblock.
         */
        $data['chain'] = $this->payplex_audit_model->verifyChain();
        // The view asks Payplex_secret whether each secret is usable; make sure
        // the class is loaded rather than relying on another file having done it.
        require_once __DIR__ . '/../libraries/Payplex_secret.php';

        /*
         * The window the gate would use if the option were unset, passed in
         * rather than repeated in the view.
         *
         * The field used to print a literal 8 while Payplex_call_gates defaulted
         * to 9, so an unconfigured install displayed a window one hour wider
         * than the one actually enforced — and the displayed one is the number
         * anybody would have quoted. One value, one source; reaching for the
         * constant from inside the view instead would fatal the whole settings
         * page if the class ever failed to load.
         */
        $data['default_start_hour'] = Payplex_call_gates::DEFAULT_START_HOUR;
        $data['default_end_hour']   = Payplex_call_gates::DEFAULT_END_HOUR;

        $this->load->view('payplex_aicalling/settings', $data);
    }

    /* ---------------- Health check (calls Sonivo /health) ---------------- */
    public function health_check()
    {
        $this->denyUnless($this->cap('view'));
        $client = new Payplex_api_client();
        $res = $client->health();
        $this->db->insert(db_prefix() . 'payplex_integration_health', [
            'checked_at'      => date('Y-m-d H:i:s'),
            'sonivo_reachable'=> $res['ok'] ? 1 : 0,
            'providers_json'  => isset($res['data']['providers']) ? json_encode($res['data']['providers']) : null,
            'balance_amount'  => $res['data']['balance']['amount'] ?? null,
            'balance_currency'=> $res['data']['balance']['currency'] ?? null,
        ]);
        echo json_encode(['success' => $res['ok'], 'data' => $res['data'] ?? null]);
    }

    /* ---------------- helpers ---------------- */

    /**
     * Fail-closed calling gate. Returns true, or a machine reason code.
     *
     * The decision itself lives in Payplex_call_gates so each refusal can be
     * tested without a database. This method's only job is to fetch the two
     * rows the decision needs and hand over the configured calling window.
     *
     * Two defects this replaces:
     *   - DND was read from $lead->dnd, a column tblleads does not have, while
     *     the module's own consent screen writes DND to payplex_consent.dnd.
     *     isset() was false on every lead, so the gate answered "not on DND"
     *     and permitted every call.
     *   - Calling hours used date('G'), the server's clock. On a UTC server a
     *     09:00-20:00 window is 14:30-01:30 in India — calls refused at 9am
     *     local and permitted at 1am local, inverting the rule it enforced.
     */
    private function callAllowed($leadId)
    {
        $lead = $this->db->where('id', $leadId)->get(db_prefix() . 'leads')->row_array();

        // Latest consent-ledger row for the calling channel. This same row
        // carries the DND flag, because it is the row the consent UI writes.
        $consent = $this->db->where('subject_type', 'lead')->where('subject_id', (int) $leadId)
            ->where('channel', 'call')->order_by('id', 'DESC')->limit(1)
            ->get(db_prefix() . 'payplex_consent')->row_array();

        $decision = Payplex_call_gates::evaluate($lead, $consent, array(
            'actor_id'     => get_staff_user_id(),
            'can_view_all' => $this->cap('view_all'),
            'timezone'     => $this->recipientTimezone($lead),
            'start_hour'   => get_option('payplex_aicalling_hours_start'),
            'end_hour'     => get_option('payplex_aicalling_hours_end'),
            'limits'       => $this->limitsContext($leadId),
        ));

        $this->lastDecision = $decision;

        return $decision['allowed'] === true ? true : $decision['code'];
    }

    /** The most recent gate decision, so callers can read its detail. */
    private $lastDecision = null;

    /**
     * Everything the §4.4 controls need: this lead's recent call history, the
     * spend the budget is measured against, and the configured limits.
     *
     * Spend is read as a period total rather than accumulated in an option,
     * so a missed increment cannot quietly grant unlimited budget.
     */
    private function limitsContext($leadId)
    {
        $periodStart = date('Y-m-01 00:00:00');   // budgets are monthly

        return array(
            'history'       => $this->aicalling_model->recentForLead($leadId),
            'agent_spend'   => $this->aicalling_model->spendSince($periodStart, get_staff_user_id()),
            'account_spend' => $this->aicalling_model->spendSince($periodStart),
            'cfg'           => array(
                'recording_disclosure' => get_option('payplex_aicalling_recording_disclosure'),
                'disclosure_confirmed' => get_option('payplex_aicalling_disclosure_confirmed'),
                'max_per_day'          => get_option('payplex_aicalling_max_per_day'),
                'max_per_week'         => get_option('payplex_aicalling_max_per_week'),
                'cooldown_minutes'     => get_option('payplex_aicalling_cooldown_minutes'),
                'agent_budget'         => get_option('payplex_aicalling_agent_budget'),
                'account_budget'       => get_option('payplex_aicalling_account_budget'),
                'max_duration_sec'     => get_option('payplex_aicalling_max_duration_sec'),
                // classification lists: which backend values mean what
                'in_flight_statuses'   => get_option('payplex_aicalling_in_flight_statuses'),
                'invalid_dispositions' => get_option('payplex_aicalling_invalid_dispositions'),
                'human_dispositions'   => get_option('payplex_aicalling_human_dispositions'),
            ),
        );
    }

    /**
     * The zone the permitted window is measured in: the RECIPIENT's.
     *
     * The approved rule is 9:00 AM to 7:00 PM in the RECIPIENT's local time
     * (master prompt §9; it was 8:00 AM under the earlier decision, and three
     * docblocks still said so after the constant was changed to 9).
     * This previously applied one configured zone to every call, which is only
     * correct while every lead happens to sit in it.
     *
     * The lead's country is resolved to an ISO code and then to a timezone
     * through PHP's own IANA database. A country that spans several zones
     * cannot answer the question and does not pretend to. When nothing can be
     * derived, the administrator's explicit fallback applies if one is set, and
     * otherwise the gate refuses — you cannot honour a window measured in a
     * clock you do not know.
     */
    private function recipientTimezone($lead)
    {
        $iso = $this->leadCountryIso2($lead);
        $res = Payplex_call_timezone::forLead(
            array('country_iso2' => $iso),
            array('fallback_timezone' => $this->fallbackTimezone())
        );
        $this->lastTimezoneResolution = $res;
        return $res['timezone'];
    }

    private $lastTimezoneResolution = null;

    /** tblleads.country is a foreign key to tblcountries; resolve it to iso2. */
    private function leadCountryIso2($lead)
    {
        $l = (array) $lead;
        $countryId = isset($l['country']) ? (int) $l['country'] : 0;
        if ($countryId <= 0) { return ''; }

        static $cache = array();
        if (array_key_exists($countryId, $cache)) { return $cache[$countryId]; }

        $row = $this->db->select('iso2')->where('country_id', $countryId)
            ->get(db_prefix() . 'countries')->row_array();
        $cache[$countryId] = $row && isset($row['iso2']) ? strtoupper(trim($row['iso2'])) : '';
        return $cache[$countryId];
    }

    /**
     * The zone to use when a lead's own cannot be derived.
     *
     * Ships empty and must be chosen deliberately: it decides whom you are
     * willing to risk calling at the wrong hour. It deliberately does NOT fall
     * back to Perfex's default timezone, because that is the company's clock,
     * not the recipient's, and using it would quietly reintroduce exactly the
     * assumption this change removes.
     */
    private function fallbackTimezone()
    {
        return trim((string) get_option('payplex_aicalling_fallback_timezone'));
    }

    private function requireOwnershipOrViewAll($callId)
    {
        if ($this->cap('view_all')) {
            return;
        }
        if (!$this->aicalling_model->isOwnedBy((int) $callId, get_staff_user_id())) {
            ajax_access_denied(); // IDOR/BOLA defence at the action layer
        }
    }

    /**
     * One source of wording, so a newly added refusal code can never fall
     * through to the generic message and leave the caller without a reason.
     */
    private function reasonMessage($code)
    {
        return Payplex_call_gates::message($code);
    }
}
