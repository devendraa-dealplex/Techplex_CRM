<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Webhook extends App_Controller
{
    public function __construct()
    {
        parent::__construct();
    }

    public function ads()
    {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);

        $expected_key = (string) get_option('google_ads_webhook_key');
        $received_key = is_array($payload) && isset($payload['google_key']) ? (string) $payload['google_key'] : '';

        if (!$this->_key_ok($expected_key, $received_key)) {
            log_message('error', 'leadgen_google ads webhook: key mismatch or not configured');
            header('HTTP/1.1 403 Forbidden');
            echo 'Invalid key';
            return;
        }

        $fields     = $this->_parse_user_column_data(is_array($payload) && isset($payload['user_column_data']) ? $payload['user_column_data'] : []);
        $google_ref = $this->_reference($payload, 'lead_id', $raw);
        $this->_process_google_lead($google_ref, 'ads', $fields, get_option('google_ads_default_source'), $raw);
        echo 'OK';
    }

    public function website()
    {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            $payload = $this->input->post();
        }

        $expected_key = (string) get_option('google_website_form_api_key');

        /*
         * Preference order: header, then body, then — still, for now — the
         * query string.
         *
         * A shared secret in a URL is written to this server's access log, to
         * every proxy in front of it, and to the Referer header of anything the
         * page subsequently loads. It should not be accepted at all. It is
         * still accepted here because the sender is a form on a site I cannot
         * inspect, and dropping it outright would silently stop website lead
         * capture with no way to tell in advance whether that had happened.
         *
         * So the query-string path stays, and every use of it is recorded. Once
         * the log shows the sender has moved to the header, the branch comes
         * out. It is marked here rather than left implicit so that removal is a
         * scheduled step and not an archaeological discovery.
         */
        $received_key = '';
        $key_source   = 'none';

        $header_key = $this->input->get_request_header('X-Api-Key', true);
        if ($header_key !== null && $header_key !== '') {
            $received_key = (string) $header_key;
            $key_source   = 'header';
        } elseif (is_array($payload) && isset($payload['key']) && $payload['key'] !== '') {
            $received_key = (string) $payload['key'];
            $key_source   = 'body';
        } elseif ($this->input->get('key')) {
            $received_key = (string) $this->input->get('key');
            $key_source   = 'query_string';
        }

        if (!$this->_key_ok($expected_key, $received_key)) {
            log_message('error', 'leadgen_google website webhook: key mismatch or not configured');
            header('HTTP/1.1 403 Forbidden');
            echo 'Invalid key';
            return;
        }

        if ($key_source === 'query_string') {
            log_message('error', 'leadgen_google website webhook: DEPRECATED key delivery via query string. '
                . 'The API key is being written to access and proxy logs. Move the sender to the X-Api-Key header.');
        }

        $fields = [
            'name' => isset($payload['name']) ? $payload['name'] : '',
            'email' => isset($payload['email']) ? $payload['email'] : '',
            'phone' => isset($payload['phone']) ? $payload['phone'] : '',
            'message' => isset($payload['message']) ? $payload['message'] : '',
        ];
        $google_ref = $this->_reference($payload, 'submission_id', $raw);
        $this->_process_google_lead($google_ref, 'website', $fields, get_option('google_website_default_source'), $raw);
        echo 'OK';
    }

    /**
     * Constant-time key comparison, and no key at all means no.
     *
     * The comparison was `$received_key !== $expected_key`, which leaks timing.
     * The WhatsApp and Facebook receivers on this install already use
     * hash_equals for theirs; this one did not.
     */
    private function _key_ok($expected, $received)
    {
        $expected = trim((string) $expected);
        $received = (string) $received;

        if ($expected === '' || $received === '') {
            return false;
        }

        return hash_equals($expected, $received);
    }

    /**
     * The deduplication reference, derived from the payload rather than invented.
     *
     * This read `isset($payload['lead_id']) ? $payload['lead_id'] : uniqid('gads_')`.
     * uniqid() returns something new on every call, so when the sender omitted
     * its own reference the dedupe key could never match an existing row and a
     * retried or replayed delivery created another lead every time. §10 requires
     * every action to be idempotent; that one was idempotent only when the
     * sender chose to make it so.
     *
     * A digest of the exact body is deterministic, carries no personal data,
     * and makes an identical replay resolve to the identical reference. Two
     * genuinely different submissions still differ by a byte somewhere and keep
     * their own rows.
     */
    private function _reference($payload, $field, $raw)
    {
        if (is_array($payload) && isset($payload[$field]) && trim((string) $payload[$field]) !== '') {
            return (string) $payload[$field];
        }

        return 'sha1:' . sha1((string) $raw);
    }

    private function _parse_user_column_data($columns)
    {
        $fields = ['name' => '', 'email' => '', 'phone' => ''];

        if (is_array($columns)) {
            foreach ($columns as $col) {
                $id = isset($col['column_id']) ? $col['column_id'] : '';
                $val = isset($col['string_value']) ? $col['string_value'] : '';

                if ($id === 'FULL_NAME') {
                    $fields['name'] = $val;
                }
                if ($id === 'EMAIL') {
                    $fields['email'] = $val;
                }
                if ($id === 'PHONE_NUMBER') {
                    $fields['phone'] = $val;
                }
            }
        }

        return $fields;
    }

    /**
     * Resolve the lead status to use when a new lead is created from an
     * inbound Google lead (Ads Lead Form or website/Google Form submission).
     *
     * Prefers the admin-configured "Default Lead Status" option. If that
     * has never been set (its stored value is an empty string), falls back
     * to the lowest-id row in tblleads_status instead of a hardcoded id.
     *
     * The previous fallback used a hardcoded status id of 1, which does not
     * exist in tblleads_status on this install (real rows are 34-39) - so
     * every Google lead created before an admin picked a default status
     * would silently get an invalid/nonexistent status reference instead of
     * one of the real pipeline stages. This does not crash the insert (status
     * is still a non-null integer), but it is the same class of bug already
     * fixed in leadgen_facebook and leadgen_whatsapp's Webhook.php, just with
     * a non-crashing failure mode here instead of an HTTP 500.
     *
     * Fixed 2026-09-06 â see google_module_changelog for details.
     */
    private function resolve_default_status_id()
    {
        $status_id = get_option('google_default_lead_status');

        if ($status_id !== '' && $status_id !== null) {
            return $status_id;
        }

        $CI = &get_instance();
        $fallback = $CI->db->order_by('id', 'asc')->limit(1)->get('leads_status')->row();

        return $fallback ? $fallback->id : null;
    }

    private function _process_google_lead($google_ref, $source_type, $fields, $source_name, $raw)
    {
        $CI = &get_instance();
        $existing = $CI->db->where('google_ref', $google_ref)->where('source_type', $source_type)->get('leadgen_google_leads')->row();

        if ($existing && !empty($existing->lead_id)) {
            return $existing->lead_id;
        }

        $lead_id = null;

        if (!empty($fields['email'])) {
            $lead = $CI->db->where('email', $fields['email'])->get('leads')->row();
            if ($lead) {
                $lead_id = $lead->id;
            }
        }

        if (!$lead_id && !empty($fields['phone'])) {
            $lead = $CI->db->where('phonenumber', $fields['phone'])->get('leads')->row();
            if ($lead) {
                $lead_id = $lead->id;
            }
        }

        if (!$lead_id) {
            $source_id = $this->_get_or_create_source($source_name);
            $status_id = $this->resolve_default_status_id();
            $lead_data = [
                'name' => !empty($fields['name']) ? $fields['name'] : 'Google Lead',
                'email' => !empty($fields['email']) ? $fields['email'] : '',
                'phonenumber' => !empty($fields['phone']) ? $fields['phone'] : '',
                'source' => $source_id,
                'status' => $status_id,
                'dateadded' => date('Y-m-d H:i:s'),
                'lastcontact' => date('Y-m-d H:i:s'),
            ];
            $CI->db->insert('leads', $lead_data);
            $lead_id = $CI->db->insert_id();
        }

        $CI->db->insert('leadgen_google_leads', [
            'lead_id' => $lead_id,
            'google_ref' => $google_ref,
            'source_type' => $source_type,
            'raw_payload' => $raw,
            'date_created' => date('Y-m-d H:i:s'),
        ]);

        return $lead_id;
    }

    private function _get_or_create_source($name)
    {
        $CI = &get_instance();

        /*
         * An unconfigured default source used to arrive here as '' and be
         * inserted as a lead source with an empty name — reference data created
         * by an inbound webhook, on a row nobody could later identify. An
         * integration may select a source; it should not be able to invent one
         * out of a blank setting.
         */
        $name = trim((string) $name);
        if ($name === '') {
            $fallback = $CI->db->order_by('id', 'asc')->limit(1)->get('leads_sources')->row();
            return $fallback ? $fallback->id : null;
        }

        $row = $CI->db->where('name', $name)->get('leads_sources')->row();

        if ($row) {
            return $row->id;
        }

        $CI->db->insert('leads_sources', ['name' => $name]);

        return $CI->db->insert_id();
    }
}
