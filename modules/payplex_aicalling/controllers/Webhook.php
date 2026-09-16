<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_signer.php';
require_once __DIR__ . '/../libraries/Payplex_secret.php';

/**
 * Public signed webhook receiver: Sonivo -> Perfex.
 * URL: /payplex_aicalling/webhook  (No auth session; authenticated by HMAC.)
 *
 * Processing order (ALL must pass before we act):
 *   1. signature valid  2. timestamp in window  3. event_id not seen (idempotency)
 *   4. schema valid  ->  store, ack 202 fast, apply state if sequence is newer.
 */
class Webhook extends App_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_aicalling/aicalling_model');
        $this->load->model('payplex_aicalling/payplex_audit_model');
    }

    public function index()
    {
        // Only POST
        if ($this->input->method(true) !== 'POST') {
            return $this->respond(405, ['error' => ['code' => 'method_not_allowed']]);
        }

        $raw = file_get_contents('php://input');
        $sigHeader = $this->server('HTTP_X_PAYPLEX_SIGNATURE');
        // Webhook secret is stored encrypted by the settings controller; decrypt
        // it here (fall back to raw when stored unencrypted).
        $secret = Payplex_secret::read('payplex_aicalling_webhook_secret');

        /*
         * No secret configured means refuse, not "sign with a placeholder".
         *
         * This read `new Payplex_signer($secret ?: 'unset', 300)`. The signer
         * deliberately throws on an empty secret — an unconfigured install has
         * no way to authenticate anything and must reject. Substituting the
         * literal string 'unset' stepped around that guard and handed every
         * unconfigured install a publicly-known HMAC key: anyone who has read
         * this file could sign a call.completed event and write a disposition,
         * a duration and a COST into the calls table, and cost is what the
         * budget control sums. A signature check with a known key is not a
         * signature check.
         */
        if (!is_string($secret) || trim($secret) === '') {
            $this->payplex_audit_model->log('webhook.refused', 'webhook', null, null,
                ['reason' => 'no_webhook_secret_configured']);
            return $this->respond(503, ['error' => ['code' => 'webhook_not_configured']]);
        }

        $signer = new Payplex_signer($secret, 300);
        $path = '/payplex_aicalling/webhook';
        $sigValid = $signer->verify($sigHeader, 'POST', $path, $raw);

        $payload = json_decode($raw, true);
        $eventId = is_array($payload) ? ($payload['event_id'] ?? null) : null;

        // Reject unsigned/forged immediately (do not reveal detail).
        if (!$sigValid) {
            /*
             * Record the attempt, never its content. This branch is reachable
             * by anyone on the internet, so storing the raw body here is an
             * unauthenticated write of attacker-chosen bytes into the inbox
             * table. A digest and a length preserve the forensic value — was
             * the same forgery retried, how big was it — without the payload.
             */
            if ($eventId) {
                $this->aicalling_model->storeInbox(
                    substr((string) $eventId, 0, 64), false, false,
                    json_encode([
                        'unsigned_attempt' => true,
                        'body_sha256'      => hash('sha256', $raw),
                        'body_bytes'       => strlen($raw),
                    ])
                );
            }
            return $this->respond(401, ['error' => ['code' => 'invalid_signature']]);
        }
        if (!$eventId || !isset($payload['event'])) {
            return $this->respond(400, ['error' => ['code' => 'bad_payload']]);
        }

        // Idempotency: duplicate event_id -> ack without reprocessing.
        if ($this->aicalling_model->webhookAlreadyProcessed($eventId)) {
            return $this->respond(200, ['success' => true, 'duplicate' => true]);
        }

        $this->aicalling_model->storeInbox($eventId, true, true, $raw);

        /*
         * Processed inline, then acknowledged — the acknowledgement is not sent
         * before the work, whatever the previous comment here said. A queue
         * worker is the production shape; until it exists, the sender's timeout
         * bounds processing time, so this is the honest description rather than
         * the intended one.
         */
        $this->processEvent($payload);
        $this->aicalling_model->markInboxProcessed($eventId);

        return $this->respond(202, ['success' => true]);
    }

    private function processEvent(array $p)
    {
        $data = $p['data'] ?? [];
        $sonivoCallId = $data['call_id'] ?? null;
        $sequence = (int) ($p['sequence'] ?? 0);
        $correlation = $p['correlation_id'] ?? null;

        $call = $sonivoCallId
            ? $this->db->where('sonivo_call_id', $sonivoCallId)->get(db_prefix() . 'payplex_calls')->row()
            : null;
        $callId = $call ? $call->id : null;

        if ($callId && $sequence <= $this->aicalling_model->lastSequence($callId)) {
            $this->aicalling_model->recordEvent($callId, $p['event_id'], $p['event'], $sequence, $p['occurred_at'] ?? null, $correlation, $p);
            return;
        }

        $update = [];
        switch ($p['event']) {
            case 'call.ringing':
            case 'call.answered':
            case 'call.queued':
                $update['status'] = str_replace('call.', '', $p['event']);
                break;
            case 'call.failed':
                $update['status'] = 'failed';
                $update['failure_reason'] = $data['reason'] ?? 'unknown';
                break;
            case 'call.completed':
                $update['status'] = 'completed';
                $update['disposition'] = $data['disposition'] ?? null;
                $update['duration_sec'] = $data['duration_sec'] ?? null;
                $update['cost'] = $data['cost'] ?? null;
                $update['currency'] = $data['currency'] ?? null;
                $update['sentiment'] = $data['sentiment'] ?? null;
                $update['detected_intent'] = $data['detected_intent'] ?? null;
                $update['objections_json'] = isset($data['objections']) ? json_encode($data['objections']) : null;
                $update['callback_date'] = $data['callback_date'] ?? null;
                break;
            case 'call.recording_ready':
                $update['recording_available'] = 1;
                break;
            case 'call.transcript_ready':
                $update['transcript_available'] = 1;
                $update['summary_text'] = $data['summary'] ?? null;
                $update['sentiment'] = $data['sentiment'] ?? ($call->sentiment ?? null);
                break;
            default:
                break;
        }

        if ($callId && !empty($update)) {
            $this->aicalling_model->updateById($callId, $update);
        }
        if ($callId) {
            $this->aicalling_model->recordEvent($callId, $p['event_id'], $p['event'], $sequence, $p['occurred_at'] ?? null, $correlation, $p);
        }

        $this->payplex_audit_model->log('webhook.' . $p['event'], 'call', $sonivoCallId, null, $update ?: $data, $correlation);
    }

    private function server($k)
    {
        return isset($_SERVER[$k]) ? $_SERVER[$k] : '';
    }

    private function respond($code, $body)
    {
        $this->output
            ->set_status_header($code)
            ->set_content_type('application/json')
            ->set_output(json_encode($body));
    }
}
