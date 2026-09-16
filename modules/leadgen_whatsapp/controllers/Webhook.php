<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Webhook extends App_Controller
{
    public function index()
    {
        $method = strtoupper($this->input->method());

        if ($method === 'GET') {
            $mode = $this->input->get('hub_mode');
            $token = $this->input->get('hub_verify_token');
            $challenge = $this->input->get('hub_challenge');
            $verify_token = get_option('whatsapp_verify_token');

            if ($mode === 'subscribe' && $token !== null && $verify_token !== '' && hash_equals((string) $verify_token, (string) $token)) {
                echo $challenge;
                exit;
            }

            http_response_code(403);
            echo 'Verification failed';
            exit;
        }

        $raw = file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) ? $_SERVER['HTTP_X_HUB_SIGNATURE_256'] : '';
        $app_secret = (string) get_option('whatsapp_app_secret');

        /*
         * Same defect as the Facebook receiver, and worse in effect.
         *
         * The condition was `if ($app_secret !== '' && $signature !== '')`.
         * Both operands were under the wrong control: the signature comes from
         * the request, and on this install `whatsapp_app_secret` is empty — so
         * the block never executed at all. Measured on staging before this
         * change: a missing signature returned 200, and a deliberately bogus
         * one ALSO returned 200. There was no authentication on this endpoint
         * in either direction.
         *
         * Refusing with 503 while the secret is unset is the honest state. It
         * will stop WhatsApp lead capture until someone enters the app secret,
         * and that is not a capability this removes — an endpoint that accepts
         * anything from anyone was never capture, it was an open write into the
         * leads table that happened to be used mostly by the real sender.
         */
        if (trim($app_secret) === '') {
            log_activity('leadgen_whatsapp webhook refused: no app secret configured');
            http_response_code(503);
            echo 'webhook_not_configured';
            exit;
        }

        if ($signature === '') {
            http_response_code(403);
            echo 'Missing signature';
            exit;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $raw, $app_secret);
        if (!hash_equals($expected, $signature)) {
            http_response_code(403);
            echo 'Invalid signature';
            exit;
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['entry'])) {
            http_response_code(200);
            echo 'EVENT_RECEIVED';
            exit;
        }

        foreach ($data['entry'] as $entry) {
            if (!isset($entry['changes'])) {
                continue;
            }

            foreach ($entry['changes'] as $change) {
                $value = isset($change['value']) ? $change['value'] : array();

                if (empty($value['messages'])) {
                    continue;
                }

                foreach ($value['messages'] as $message) {
                    $this->process_message($message, $value, $raw);
                }
            }
        }

        http_response_code(200);
        echo 'EVENT_RECEIVED';
    }

    /**
     * Resolve the lead status to use when a new lead is created from an
     * inbound WhatsApp message.
     *
     * Prefers the admin-configured "Default Lead Status" option. If that
     * has never been set (its stored value is an empty string), falls back
     * to the lowest-id row in tblleads_status instead of leaving the value
     * empty/null: tblleads.status is a NOT NULL column with no database
     * default, so inserting a null/empty status previously caused every
     * inbound WhatsApp message to fail with an uncaught DB error (HTTP 500)
     * whenever the admin had not yet picked a default on the settings page.
     *
     * Same defect and same fix pattern as leadgen_facebook/controllers/Webhook.php.
     *
     * Fixed 2026-09-06 â see whatsapp_module_changelog for details.
     */
    private function resolve_default_status_id()
    {
        $status_id = get_option('whatsapp_default_lead_status');

        if ($status_id !== '' && $status_id !== null) {
            return $status_id;
        }

        $fallback = $this->db->order_by('id', 'asc')->limit(1)->get(db_prefix() . 'leads_status')->row();

        return $fallback ? $fallback->id : null;
    }

    private function process_message($message, $value, $raw)
    {
        $wa_from = isset($message['from']) ? $message['from'] : '';
        if ($wa_from === '') {
            return;
        }

        $wa_message_id = isset($message['id']) ? $message['id'] : null;
        $body = isset($message['text']['body']) ? $message['text']['body'] : '';
        $contact_name = '';

        if (!empty($value['contacts'][0]['profile']['name'])) {
            $contact_name = $value['contacts'][0]['profile']['name'];
        }

        $source_row = $this->db->where('name', 'WhatsApp')->get(db_prefix() . 'leads_sources')->row();

        if ($source_row) {
            $source_id = $source_row->id;
        } else {
            $this->db->insert(db_prefix() . 'leads_sources', array('name' => 'WhatsApp'));
            $source_id = $this->db->insert_id();
        }

        $lead = $this->db->where('phonenumber', $wa_from)->get(db_prefix() . 'leads')->row();

        if ($lead) {
            $lead_id = $lead->id;
        } else {
            $status_id = $this->resolve_default_status_id();

            $insert_data = array(
                'name' => $contact_name !== '' ? $contact_name : ('WhatsApp Lead ' . $wa_from),
                'phonenumber' => $wa_from,
                'source' => $source_id,
                'status' => $status_id,
                'dateadded' => date('Y-m-d H:i:s'),
                'addedfrom' => 0,
                'is_public' => 0,
                'from_form_id' => 0,
            );

            $this->db->insert(db_prefix() . 'leads', $insert_data);
            $lead_id = $this->db->insert_id();
        }

        $this->db->insert(db_prefix() . 'leadgen_whatsapp_messages', array(
            'lead_id' => $lead_id,
            'wa_from' => $wa_from,
            'wa_message_id' => $wa_message_id,
            'direction' => 'in',
            'message_body' => $body,
            'raw_payload' => $raw,
            'date_created' => date('Y-m-d H:i:s'),
        ));
    }
}
