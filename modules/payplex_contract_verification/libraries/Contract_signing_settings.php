<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Contract_webhook_guard.php';

/**
 * Contract_signing_settings
 *
 * The provider credentials and operating settings: what exists, which are
 * secret, how each is validated, and the gate that stops production being
 * switched on by accident.
 *
 * WHAT "SECRET" MEANS HERE
 * ------------------------
 * A setting marked secret is encrypted at rest, is never returned by any
 * endpoint, never appears in a log line, an exception message, a URL, a
 * redirect, an export or a screenshot, and is never echoed back into the form
 * that set it. The screen shows a fingerprint and, for identifiers only, the
 * last four characters.
 *
 * The distinction between key and secret is deliberate. Showing the last four
 * characters of an application ID is a convenience with no real cost. Doing the
 * same to an HMAC secret leaks four characters of key material permanently, in
 * exchange for the same convenience — so secrets get a fingerprint and nothing
 * else. That was a judgement call and it is recorded here rather than buried.
 *
 * Pure: no database, no encryption of its own. It says what is valid; the model
 * stores it.
 */
class Contract_signing_settings
{
    const ENV_SANDBOX    = 'sandbox';
    const ENV_PRODUCTION = 'production';

    /** Reveal style for a stored credential. */
    const REVEAL_NONE        = 'fingerprint_only';
    const REVEAL_LAST_FOUR   = 'last_four_and_fingerprint';

    /**
     * Every setting this module stores.
     *
     * @return array
     */
    public static function schema()
    {
        return array(
            'enabled' => array(
                'label' => 'Enable Leegality', 'type' => 'bool', 'secret' => false,
                'default' => 0,
                'means' => 'Off by default. Turning it on does not make the integration live: '
                         . 'the environment setting does that, and it has its own gate.',
            ),
            'environment' => array(
                'label' => 'Environment', 'type' => 'enum', 'secret' => false,
                'values' => array(self::ENV_SANDBOX, self::ENV_PRODUCTION),
                'default' => self::ENV_SANDBOX,
                'means' => 'Sandbox until every acceptance test has passed. See productionGate().',
            ),
            'api_base_url' => array(
                'label' => 'API base URL', 'type' => 'url', 'secret' => false,
                'means' => 'HTTPS only. Credentials are never carried in this URL.',
            ),
            'auth_type' => array(
                'label' => 'Authentication type', 'type' => 'enum', 'secret' => false,
                /* Filled from the account documentation. Empty until then, and an
                   empty list means no value validates — which is correct, because
                   an auth type nobody has read cannot be configured. */
                'values' => array(),
                'means' => 'Taken from the API documentation for this account, not assumed.',
            ),
            'api_key' => array(
                'label' => 'API key / application ID', 'type' => 'secret', 'secret' => true,
                'reveal' => self::REVEAL_LAST_FOUR,
                'means' => 'An identifier. Last four characters may be shown so an administrator '
                         . 'can tell which credential is in place.',
            ),
            'api_secret' => array(
                'label' => 'API secret', 'type' => 'secret', 'secret' => true,
                'reveal' => self::REVEAL_NONE,
                'means' => 'Key material. A fingerprint only — see the class docblock.',
            ),
            'private_key' => array(
                'label' => 'Private key / certificate', 'type' => 'secret', 'secret' => true,
                'reveal' => self::REVEAL_NONE, 'optional' => true,
                'means' => 'Only if the documentation for this account requires one.',
            ),
            'workflow_id' => array(
                'label' => 'Workflow / profile ID', 'type' => 'string', 'secret' => false,
                'means' => 'Validated against the provider before first use, not on save — '
                         . 'a wrong workflow id is only detectable by asking the provider.',
            ),
            'webhook_secret' => array(
                'label' => 'Webhook secret', 'type' => 'secret', 'secret' => true,
                'reveal' => self::REVEAL_NONE,
                'means' => 'Verifies inbound deliveries. Without it the webhook route accepts nothing.',
            ),
            'callback_url' => array(
                'label' => 'Callback URL', 'type' => 'url', 'secret' => false,
                'means' => 'HTTPS, on this host. Shown so it can be pasted into the provider dashboard.',
            ),
            'link_expiry_hours' => array(
                'label' => 'Signing-link expiry (hours)', 'type' => 'int', 'secret' => false,
                'min' => 1, 'max' => 720,
                'means' => 'How long a signing link stays usable. Blank is refused, not defaulted.',
            ),
            'default_signing_method' => array(
                'label' => 'Default signing method', 'type' => 'enum', 'secret' => false,
                'values' => array(),
                'means' => 'Only methods confirmed ENABLED on this account — not everything the API offers.',
            ),
            'default_invitation_method' => array(
                'label' => 'Default invitation method', 'type' => 'enum', 'secret' => false,
                'values' => array(),
                'means' => 'As above.',
            ),
            'request_timeout_seconds' => array(
                'label' => 'Request timeout (seconds)', 'type' => 'int', 'secret' => false,
                'min' => 5, 'max' => 120, 'default' => 30,
                'means' => 'A signing request that hangs holds a member of staff in front of a spinner.',
            ),
            'max_retries' => array(
                'label' => 'Maximum retry count', 'type' => 'int', 'secret' => false,
                'min' => 0, 'max' => 5, 'default' => 2,
                'means' => 'Retries reuse the same internal operation reference, so a retry cannot '
                         . 'become a second signing request. See Contract_failures::shouldRetry().',
            ),
        );
    }

    /**
     * @return array setting keys that are secret
     */
    public static function secretKeys()
    {
        $out = array();

        foreach (self::schema() as $k => $meta) {
            if (!empty($meta['secret'])) { $out[] = $k; }
        }

        return $out;
    }

    /**
     * @param  string $key
     * @return bool
     */
    public static function isSecret($key)
    {
        $s = self::schema();

        return isset($s[$key]) && !empty($s[$key]['secret']);
    }

    /**
     * Validate one setting value.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return array {ok, value, error, reason}
     */
    public static function validate($key, $value)
    {
        $s = self::schema();

        if (!isset($s[$key])) {
            return self::bad('unknown_setting', 'That is not a setting this module stores.');
        }

        $meta     = $s[$key];
        $optional = !empty($meta['optional']);

        if (is_array($value) || is_object($value)) {
            return self::bad('not_scalar', 'That value is not a single field.');
        }

        $raw = is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);

        if ($raw === '' && !$optional && $meta['type'] !== 'bool') {
            return self::bad('blank', 'This setting is required.');
        }

        switch ($meta['type']) {
            case 'bool':
                return self::good($raw === '1' || strtolower($raw) === 'true' ? 1 : 0);

            case 'int':
                if (!preg_match('/^[0-9]{1,6}$/', $raw)) {
                    return self::bad('not_a_whole_number', 'Give a whole number.');
                }

                $n = (int) $raw;

                if (isset($meta['min']) && $n < (int) $meta['min']) {
                    return self::bad('below_minimum', 'The minimum is ' . $meta['min'] . '.');
                }

                if (isset($meta['max']) && $n > (int) $meta['max']) {
                    return self::bad('above_maximum', 'The maximum is ' . $meta['max'] . '.');
                }

                return self::good($n);

            case 'enum':
                $values = isset($meta['values']) ? $meta['values'] : array();

                if (!$values) {
                    /*
                     * An empty list is not "allow anything". It means the
                     * permitted values come from documentation this account has
                     * not supplied, so nothing can be validated and therefore
                     * nothing may be saved.
                     */
                    return self::bad('no_documented_values',
                        'The permitted values for this setting come from the provider documentation, '
                        . 'which has not been supplied. It cannot be set yet.');
                }

                if (!in_array($raw, $values, true)) {
                    return self::bad('not_a_permitted_value', 'That is not one of the permitted values.');
                }

                return self::good($raw);

            case 'url':
                if (!preg_match('~^https://~i', $raw)) {
                    return self::bad('not_https', 'The URL must start with https://.');
                }

                if (preg_match('~[?&](key|secret|token|password|api_key)=~i', $raw)) {
                    return self::bad('credential_in_url',
                        'Credentials must not appear in a URL. They are stored encrypted and sent '
                        . 'in the request, never in a query string that ends up in access logs.');
                }

                if (filter_var($raw, FILTER_VALIDATE_URL) === false) {
                    return self::bad('malformed_url', 'That is not a valid URL.');
                }

                return self::good($raw);

            case 'secret':
                if ($raw === '' && $optional) { return self::good(''); }

                if (strlen($raw) < 8) {
                    return self::bad('secret_too_short', 'That does not look like a credential.');
                }

                return self::good($raw);

            default: /* string */
                if (strlen($raw) > 190) {
                    return self::bad('too_long', 'That value is too long.');
                }

                return self::good($raw);
        }
    }

    /* ---- how a stored credential is displayed -------------------------- */

    /**
     * What a screen may show about a stored credential.
     *
     * Never the value. This method is the only sanctioned way to describe a
     * stored secret, so there is one place to check rather than a habit to
     * maintain across every view.
     *
     * @param  string $key
     * @param  string $plain       the value, only at the moment it is saved
     * @param  bool   $isConfigured
     * @return array {configured, fingerprint, last_four, reveal}
     */
    public static function describe($key, $plain, $isConfigured)
    {
        $s     = self::schema();
        $style = isset($s[$key]['reveal']) ? $s[$key]['reveal'] : self::REVEAL_NONE;

        if (!$isConfigured || !is_string($plain) || $plain === '') {
            return array('configured' => false, 'fingerprint' => null,
                         'last_four' => null, 'reveal' => $style);
        }

        return array(
            'configured'  => true,
            'fingerprint' => substr(hash('sha256', $plain), 0, 16),
            'last_four'   => $style === self::REVEAL_LAST_FOUR ? substr($plain, -4) : null,
            'reveal'      => $style,
        );
    }

    /* ---- the production gate ------------------------------------------- */

    /**
     * The conditions required before production may be selected.
     *
     * @return array condition key => what it means
     */
    public static function productionConditions()
    {
        return array(
            'sandbox_credentials_present' => 'Sandbox credentials are stored.',
            'test_connection_passed'      => 'Test Connection succeeded against sandbox.',
            'sandbox_round_trip_passed'   => 'A full sandbox round trip completed: request created, '
                                           . 'signed, webhook verified, signed document and completion '
                                           . 'certificate downloaded, both hashes stored.',
            'acceptance_tests_recorded'   => 'All sandbox acceptance tests are recorded as passed.',
            'administrator_confirmed'     => 'An authorised administrator typed the confirmation.',
        );
    }

    /**
     * May production be enabled?
     *
     * Every condition is checked server-side at the moment of the change, and
     * the refusal names the ones that are unmet — a gate that says only "no" is
     * a gate people work around.
     *
     * Production credentials are entered AFTER the switch, never before, so a
     * half-configured production mode cannot exist.
     *
     * @param  array $evidence condition key => bool
     * @return array {allowed, unmet, reason}
     */
    public static function productionGate(array $evidence)
    {
        $unmet = array();

        foreach (array_keys(self::productionConditions()) as $c) {
            if (empty($evidence[$c])) { $unmet[] = $c; }
        }

        if ($unmet) {
            return array('allowed' => false, 'unmet' => $unmet,
                         'reason' => 'production_conditions_unmet');
        }

        return array('allowed' => true, 'unmet' => array(), 'reason' => 'all_conditions_met');
    }

    /**
     * Is the integration ready to make a real call?
     *
     * Separate from the production gate: this answers "can it work at all",
     * which is false today for a reason that has nothing to do with credentials.
     *
     * @param  array $stored key => configured bool
     * @return array {ready, missing, blocked_by}
     */
    public static function operational(array $stored)
    {
        $missing = array();

        foreach (self::schema() as $k => $meta) {
            if (!empty($meta['optional'])) { continue; }
            if ($k === 'enabled' || $k === 'environment') { continue; }

            if (empty($stored[$k])) { $missing[] = $k; }
        }

        $blocked = array();
        $v       = Contract_webhook_guard::verifierAvailable();

        if (empty($v['available'])) {
            $blocked[] = 'webhook_signature_algorithm_not_documented';
        }

        if (!Contract_webhook_guard::eventMap()) {
            $blocked[] = 'provider_event_map_not_documented';
        }

        return array(
            'ready'      => !$missing && !$blocked,
            'missing'    => $missing,
            'blocked_by' => $blocked,
        );
    }

    /* ---- helpers ------------------------------------------------------- */

    private static function good($v)
    {
        return array('ok' => true, 'value' => $v, 'error' => null, 'reason' => 'valid');
    }

    private static function bad($reason, $error)
    {
        return array('ok' => false, 'value' => null, 'error' => $error, 'reason' => $reason);
    }
}
