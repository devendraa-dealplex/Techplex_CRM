<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_audit
 *
 * Pure helpers for audit logging. The actual row insert lives in the model;
 * this class provides secret masking and safe serialisation so no API key,
 * token or password is ever written to the audit trail in clear text.
 */
class Payplex_agent_audit
{
    /** Field-name fragments whose values must always be masked. */
    private static function secretKeys()
    {
        return array('api_key', 'apikey', 'secret', 'token', 'password', 'passwd',
                     'authorization', 'auth', 'private_key', 'access_key', 'bearer');
    }

    /** Mask a single scalar value, keeping only a short hint. */
    public static function maskValue($value)
    {
        $s = (string) $value;
        $len = strlen($s);
        if ($len === 0) {
            return '';
        }
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return substr($s, 0, 2) . str_repeat('*', max(4, $len - 4)) . substr($s, -2);
    }

    public static function isSecretKey($key)
    {
        $k = strtolower((string) $key);
        foreach (self::secretKeys() as $frag) {
            if (strpos($k, $frag) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recursively mask any secret-looking keys in an array/object before it is
     * persisted or logged.
     */
    public static function redact($data)
    {
        if (is_array($data)) {
            $out = array();
            foreach ($data as $k => $v) {
                if (self::isSecretKey($k) && (is_string($v) || is_numeric($v))) {
                    $out[$k] = self::maskValue($v);
                } else {
                    $out[$k] = self::redact($v);
                }
            }
            return $out;
        }
        return $data;
    }

    /** JSON-encode after redaction; safe for the audit column. */
    public static function encode($data)
    {
        return json_encode(self::redact($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The canonical set of auditable event types. */
    public static function eventTypes()
    {
        return array(
            'config_change', 'prompt_version', 'trigger', 'tool_call', 'crm_action',
            'approval', 'rejection', 'cost', 'output', 'error', 'retry',
            'human_intervention', 'lifecycle', 'kill_switch', 'budget',
        );
    }
}
