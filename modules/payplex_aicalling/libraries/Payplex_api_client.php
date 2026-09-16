<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Payplex_signer.php';
require_once __DIR__ . '/Payplex_retry.php';

/**
 * Payplex_api_client
 *
 * The CRM-side client that drives the Sonivo AI Calling backend over the v1 API
 * (Deliverable 12). Responsibilities:
 *   - attach service JWT + HMAC signature + correlation id + idempotency key
 *   - enforce connect/read timeouts
 *   - retry timeouts/5xx/429 with exponential backoff + full jitter
 *   - never leak provider keys (only the scoped service credential is used)
 *
 * Secrets are read from Perfex options; the JWT/secrets are decrypted at read
 * time via the CI Encryption library (see Aicalling_settings controller).
 */
class Payplex_api_client
{
    /** @var object CI */
    private $ci;
    private $baseUrl;
    private $jwt;
    private $signer;
    private $retry;
    private $connectTimeout;
    private $readTimeout;

    /** Why this client cannot be used, or null when it can. */
    private $unconfigured = null;

    public function __construct($config = [])
    {
        $this->ci = &get_instance();

        $this->baseUrl = rtrim($config['base_url'] ?? get_option('payplex_aicalling_base_url'), '/');
        $this->jwt     = $config['service_jwt'] ?? self::readSecretOption('payplex_aicalling_service_jwt');
        $reqSecret     = $config['request_secret'] ?? self::readSecretOption('payplex_aicalling_request_secret');

        /*
         * No request secret means this client cannot sign, and it must refuse
         * rather than substitute a placeholder.
         *
         * The literal here was 'unset', so an unconfigured install signed every
         * outbound request with a key written in this file — and, worse than
         * the weak key itself, nothing ever told the operator the secret was
         * missing. Requests went out looking signed while the code read as
         * though it had signed them.
         *
         * Refusal is recorded as state rather than thrown: every method funnels
         * through request(), which already answers in an ['ok' => false, ...]
         * envelope that all nine callers check. Throwing from a constructor
         * that seven call sites build inline would turn a misconfiguration into
         * a 500 instead of a clear message.
         */
        $this->unconfigured = (!is_string($reqSecret) || trim($reqSecret) === '')
            ? 'The AI Calling request secret is not configured, so outbound requests cannot be '
              . 'signed. Set it under AI Calling settings before placing calls.'
            : null;

        $this->signer = $this->unconfigured === null
            ? new Payplex_signer($reqSecret, 300)
            : null;
        $this->retry  = new Payplex_retry(4, 1000, 10000);
        $this->connectTimeout = (int) (get_option('payplex_aicalling_timeout_connect') ?: 5);
        $this->readTimeout    = (int) (get_option('payplex_aicalling_timeout_read') ?: 15);
    }

    /**
     * Read a secret option and decrypt it. Secrets are stored via CI Encryption
     * by the settings controller; here we reverse that. Falls back to the raw
     * value when it was stored unencrypted (back-compat / robustness).
     */
    /**
     * Kept as the name callers already use; the implementation moved to
     * Payplex_secret so the settings screen, the webhook receiver and this
     * client all answer "is the secret usable" the same way.
     */
    public static function readSecretOption($optionKey)
    {
        require_once __DIR__ . '/Payplex_secret.php';
        return Payplex_secret::read($optionKey);
    }

    /** Generate a v4 UUID (correlation / idempotency keys). */
    public static function uuid()
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    /**
     * Create an outbound call. Idempotent via Idempotency-Key.
     * @return array ['ok'=>bool,'status'=>int,'data'=>array|null,'error'=>array|null,'correlation_id'=>string]
     */
    public function createCall(array $payload, $idempotencyKey = null, $correlationId = null)
    {
        $idempotencyKey = $idempotencyKey ?: self::uuid();
        $correlationId  = $correlationId ?: self::uuid();
        return $this->request('POST', '/api/v1/calls', $payload, $idempotencyKey, $correlationId);
    }

    public function getCall($callId, $correlationId = null)
    {
        return $this->request('GET', '/api/v1/calls/' . rawurlencode($callId), null, null, $correlationId ?: self::uuid());
    }

    public function cancelCall($callId, $idempotencyKey = null, $correlationId = null)
    {
        return $this->request('POST', '/api/v1/calls/' . rawurlencode($callId) . '/cancel', [], $idempotencyKey ?: self::uuid(), $correlationId ?: self::uuid());
    }

    public function retryCall($callId, $idempotencyKey = null, $correlationId = null)
    {
        return $this->request('POST', '/api/v1/calls/' . rawurlencode($callId) . '/retry', [], $idempotencyKey ?: self::uuid(), $correlationId ?: self::uuid());
    }

    public function recordingUrl($callId, $correlationId = null)
    {
        return $this->request('GET', '/api/v1/calls/' . rawurlencode($callId) . '/recording-url', null, null, $correlationId ?: self::uuid());
    }

    public function transcript($callId, $correlationId = null)
    {
        return $this->request('GET', '/api/v1/calls/' . rawurlencode($callId) . '/transcript', null, null, $correlationId ?: self::uuid());
    }

    public function health()
    {
        return $this->request('GET', '/api/v1/health', null, null, self::uuid());
    }

    public function createCampaign(array $payload, $idempotencyKey = null, $correlationId = null)
    {
        return $this->request('POST', '/api/v1/campaigns', $payload,
            $idempotencyKey ?: self::uuid(), $correlationId ?: self::uuid());
    }

    public function reconcile(array $callIds)
    {
        return $this->request('POST', '/api/v1/reconcile', ['call_ids' => $callIds], self::uuid(), self::uuid());
    }

    /**
     * Core signed HTTP with retry. Returns a normalized envelope.
     */
    private function request($method, $path, $body, $idempotencyKey, $correlationId)
    {
        /*
         * One guard for all nine methods, because every one of them arrives
         * here. A client that cannot sign refuses in the same shape a network
         * failure would, so existing callers handle it without changes.
         */
        if ($this->unconfigured !== null) {
            return array('ok' => false, 'status' => 0, 'data' => null,
                         'code' => 'not_configured', 'error' => $this->unconfigured,
                         'correlation_id' => $correlationId);
        }

        $bodyStr = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES);
        $attempt = 0;
        $last = ['ok' => false, 'status' => 0, 'data' => null, 'error' => null, 'correlation_id' => $correlationId];

        do {
            $attempt++;
            $ts = time();
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->jwt,
                'X-Api-Version: 1',
                'X-Correlation-Id: ' . $correlationId,
                'X-Payplex-Signature: ' . $this->signer->sign($method, $path, $bodyStr, $ts),
            ];
            if ($idempotencyKey !== null) {
                $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
            }

            [$status, $respBody, $err] = $this->httpExec($method, $this->baseUrl . $path, $bodyStr, $headers);

            if ($status >= 200 && $status < 300) {
                $decoded = json_decode($respBody, true);
                return ['ok' => true, 'status' => $status, 'data' => $decoded['data'] ?? $decoded, 'error' => null, 'correlation_id' => $correlationId];
            }

            $decoded = json_decode($respBody, true);
            $last = [
                'ok' => false,
                'status' => $status,
                'data' => null,
                'error' => $decoded['error'] ?? ['code' => 'http_' . $status, 'message' => $err ?: 'Request failed', 'retryable' => $this->retry->shouldRetry($status, $attempt)],
                'correlation_id' => $correlationId,
            ];

            if (!$this->retry->shouldRetry($status, $attempt)) {
                break;
            }
            // honor Retry-After on 429 handled by caller/cron; here sleep jittered backoff (ms)
            usleep($this->retry->backoffMs($attempt) * 1000);
        } while ($attempt < $this->retry->maxAttempts());

        return $last;
    }

    /**
     * Raw cURL execution. Returns [status, body, errString].
     * Isolated so it can be stubbed in integration tests.
     */
    protected function httpExec($method, $url, $bodyStr, array $headers)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->readTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($method !== 'GET' && $bodyStr !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
        }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$status, $resp === false ? '' : $resp, $err];
    }
}
