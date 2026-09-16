<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_signer
 *
 * Framework-independent HMAC request/webhook signing + verification for the
 * Perfex <-> Sonivo AI Calling integration. Kept free of CodeIgniter so it is
 * unit-testable in isolation (see tests/SignerTest.php).
 *
 * Signature scheme (matches Deliverable 12 API spec):
 *   signing_string = timestamp . "\n" . method . "\n" . path . "\n" . sha256hex(body)
 *   header value   = "t=<timestamp>,v1=<hmac_sha256_hex(signing_string, secret)>"
 *
 * Verification is constant-time and enforces a timestamp window to defeat replay.
 */
class Payplex_signer
{
    /** @var string */
    private $secret;

    /** @var int seconds of allowed clock skew for replay protection */
    private $tolerance;

    public function __construct($secret, $tolerance = 300)
    {
        if (!is_string($secret) || $secret === '') {
            throw new InvalidArgumentException('Signing secret must be a non-empty string.');
        }
        $this->secret    = $secret;
        $this->tolerance = (int) $tolerance;
    }

    /**
     * Build the canonical signing string for a request/webhook.
     */
    public function canonical($timestamp, $method, $path, $body)
    {
        $method = strtoupper((string) $method);
        $bodyHash = hash('sha256', (string) $body);
        return $timestamp . "\n" . $method . "\n" . $path . "\n" . $bodyHash;
    }

    /**
     * Produce the header value to send with an outbound request.
     */
    public function sign($method, $path, $body, $timestamp = null)
    {
        $timestamp = $timestamp === null ? time() : (int) $timestamp;
        $sig = hash_hmac('sha256', $this->canonical($timestamp, $method, $path, $body), $this->secret);
        return 't=' . $timestamp . ',v1=' . $sig;
    }

    /**
     * Parse a "t=...,v1=..." header into [timestamp, signature] or null.
     */
    public function parseHeader($header)
    {
        if (!is_string($header) || $header === '') {
            return null;
        }
        $t = null;
        $v1 = null;
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $t = $kv[1];
            } elseif ($kv[0] === 'v1') {
                $v1 = $kv[1];
            }
        }
        if ($t === null || $v1 === null || !ctype_digit((string) $t)) {
            return null;
        }
        return ['timestamp' => (int) $t, 'signature' => $v1];
    }

    /**
     * Verify an inbound signature header. Returns true only when the signature
     * matches AND the timestamp is within tolerance (replay defence).
     *
     * @param string   $header  raw X-Payplex-Signature value
     * @param string   $method
     * @param string   $path
     * @param string   $body    raw request body (bytes as received)
     * @param int|null $now     injectable clock for testing
     */
    public function verify($header, $method, $path, $body, $now = null)
    {
        $parsed = $this->parseHeader($header);
        if ($parsed === null) {
            return false;
        }
        $now = $now === null ? time() : (int) $now;
        if (abs($now - $parsed['timestamp']) > $this->tolerance) {
            return false; // outside window -> reject (stale/replayed)
        }
        $expected = hash_hmac(
            'sha256',
            $this->canonical($parsed['timestamp'], $method, $path, $body),
            $this->secret
        );
        return hash_equals($expected, (string) $parsed['signature']);
    }
}
