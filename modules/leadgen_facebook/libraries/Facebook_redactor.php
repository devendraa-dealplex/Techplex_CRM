<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * What an inbound delivery is allowed to leave behind in the log.
 *
 * THE PROBLEM THE LOG SOLVES, AND THE ONE IT CREATES
 * --------------------------------------------------
 * Before this change a rejected delivery left no trace at all: the module wrote
 * a row only when a lead was created. So "Facebook never called us" and
 * "Facebook called eleven times and we refused every one" produced identical
 * evidence — an empty table — and those two need opposite fixes.
 *
 * Logging every inbound request fixes that, and immediately creates a second
 * problem: the thing being logged is an unauthenticated request body from the
 * public internet containing a member of the public's name, email address and
 * phone number, and the headers around it carry the signature. A log that keeps
 * all of it is a second copy of the personal data with none of the access
 * control of the lead record, growing forever.
 *
 * So the payload is reduced before it is stored:
 *
 *   - credentials and signatures  → removed entirely, key and value
 *   - contact details             → masked to the minimum that identifies the
 *                                   record without reproducing it
 *   - structure and references    → kept in full, because that is the part that
 *                                   answers "what did Meta send and did we take
 *                                   it"
 *
 * The lead record remains the one place the real contact details live. The log
 * exists to reconstruct a delivery, not to duplicate a person.
 *
 * Pure functions, no CodeIgniter, so the redaction can be tested for what it
 * removes AND for what it keeps. A redactor that drops everything is safe and
 * useless; the tests assert both halves.
 */
class Facebook_redactor
{
    const MASK = '[REDACTED]';

    /** Hard ceiling on what is stored, after redaction. */
    const MAX_BYTES = 16384;

    /** Guards against a hostile deeply-nested payload. */
    const MAX_DEPTH = 12;

    /**
     * Key names whose value is a credential or a signature. Matched
     * case-insensitively as a substring, because Meta and its SDKs spell these
     * several ways and a new spelling must fail closed.
     */
    const SECRET_KEY_PATTERNS = array(
        'access_token', 'accesstoken', 'app_secret', 'appsecret',
        'client_secret', 'token', 'secret', 'signature', 'sig',
        'password', 'passwd', 'verify_token', 'hub_verify_token',
        'authorization', 'auth', 'api_key', 'apikey', 'credential',
        'session', 'cookie', 'bearer',
    );

    /** Key names holding an email address. */
    const EMAIL_KEY_PATTERNS = array('email', 'e_mail', 'emailaddress');

    /** Key names holding a telephone number. */
    const PHONE_KEY_PATTERNS = array('phone', 'mobile', 'whatsapp', 'telephone', 'msisdn');

    /** Key names holding a person's name. */
    const NAME_KEY_PATTERNS = array(
        'full_name', 'fullname', 'first_name', 'firstname',
        'last_name', 'lastname', 'name',
    );

    /**
     * Keys that look like names but are references, not people. `form_name`
     * and `page_name` are the label of an ad form and of a business page —
     * exactly the context needed to read the log, and not personal data.
     *
     * Checked before the name patterns, because 'form_name' contains 'name'.
     */
    const NAME_EXEMPT = array(
        'form_name', 'page_name', 'campaign_name', 'adset_name', 'ad_name',
        'field_name', 'platform', 'form_id', 'page_id', 'field',
    );

    /**
     * Reduce an arbitrary decoded payload to something safe to store.
     *
     * Returns a JSON string, or a short marker when the input was not
     * decodable — never the raw body, because a body that failed to parse is
     * exactly the case where nobody has checked what is in it.
     */
    public static function redact($decoded)
    {
        if (is_string($decoded)) {
            $try = json_decode($decoded, true);
            $decoded = is_array($try) ? $try : null;

            if ($decoded === null) {
                return json_encode(array('_note' => 'payload not valid JSON; not stored'));
            }
        }

        if (!is_array($decoded)) {
            return json_encode(array('_note' => 'payload not an object; not stored'));
        }

        $clean = self::walk($decoded, 0);
        $json = json_encode($clean);

        if ($json === false) {
            return json_encode(array('_note' => 'payload could not be encoded; not stored'));
        }

        if (strlen($json) > self::MAX_BYTES) {
            return substr($json, 0, self::MAX_BYTES - 32) . '...[TRUNCATED]';
        }

        return $json;
    }

    private static function walk($node, $depth)
    {
        if ($depth > self::MAX_DEPTH) {
            return '[DEPTH_LIMIT]';
        }

        if (!is_array($node)) {
            return self::scalar($node);
        }

        /*
         * Meta's answer array, handled before the generic walk can get at it.
         *
         * This is the case the generic walk cannot see. The shape is
         * `[{"name":"email","values":["a@b.com"]}]` — the field's identity is
         * the *value* of a key called "name", and the address sits under a key
         * called "values", which classifies as plain. So a purely key-based
         * redactor stores every lead's real email address and phone number in
         * the log, under a key that means nothing in particular, while every
         * assertion about masking `email` and `phone` keys still passes.
         *
         * Caught by the test asserting the address is absent from the redacted
         * payload, which failed against the first version of this file.
         */
        if (self::isFieldDataNode($node)) {
            return self::maskFieldData($node);
        }

        $out = array();

        foreach ($node as $key => $value) {
            $class = self::classify($key);

            if ($class === 'secret') {
                /*
                 * The key is kept so the log records that a credential was
                 * present in the payload; the value never is.
                 */
                $out[$key] = self::MASK;
                continue;
            }

            if (is_array($value)) {
                $out[$key] = self::walk($value, $depth + 1);
                continue;
            }

            if ($class === 'email') {
                $out[$key] = self::maskEmail($value);
            } elseif ($class === 'phone') {
                $out[$key] = self::maskPhone($value);
            } elseif ($class === 'name') {
                $out[$key] = self::maskName($value);
            } else {
                $out[$key] = self::scalar($value);
            }
        }

        return $out;
    }

    /**
     * Is this node a list of `{name, values}` answer objects?
     *
     * Detected by shape rather than by the key that holds it, so a payload that
     * puts the same structure under `custom_answers` or an unnamed array is
     * masked as well. A list is treated as answer data when it is non-empty,
     * sequentially keyed, and every element is an array carrying both `name`
     * and `values`.
     */
    public static function isFieldDataNode($node)
    {
        if (!is_array($node) || empty($node)) {
            return false;
        }

        $i = 0;

        foreach ($node as $k => $v) {
            if ($k !== $i++) {
                return false;
            }

            if (!is_array($v) || !array_key_exists('name', $v) || !array_key_exists('values', $v)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Decide what a key holds.
     *
     * A numeric key — every element of a `field_data` array — carries no
     * meaning of its own, so its children are classified on their own keys and
     * the element itself is passed through. This matters: Meta sends contact
     * details as `{"name":"email","values":["a@b.com"]}`, where the *value* of
     * "name" is the string "email" and the address sits under "values". That
     * shape is handled by maskFieldData() below rather than pretended away
     * here.
     */
    public static function classify($key)
    {
        $k = strtolower((string) $key);

        if ($k === '' || ctype_digit($k)) {
            return 'plain';
        }

        foreach (self::SECRET_KEY_PATTERNS as $p) {
            if (strpos($k, $p) !== false) {
                return 'secret';
            }
        }

        if (in_array($k, self::NAME_EXEMPT, true)) {
            return 'plain';
        }

        foreach (self::EMAIL_KEY_PATTERNS as $p) {
            if (strpos($k, $p) !== false) {
                return 'email';
            }
        }

        foreach (self::PHONE_KEY_PATTERNS as $p) {
            if (strpos($k, $p) !== false) {
                return 'phone';
            }
        }

        foreach (self::NAME_KEY_PATTERNS as $p) {
            if (strpos($k, $p) !== false) {
                return 'name';
            }
        }

        return 'plain';
    }

    /**
     * Reduce Meta's `field_data` array — the one place a lead's actual answers
     * arrive — to field names plus masked values.
     *
     * This runs as a separate pass because the shape defeats key-based
     * classification: the field's identity is a *value* ("email"), not a key.
     * Handled explicitly rather than left to the generic walk, which would
     * store `values: ["someone@example.com"]` verbatim under a key called
     * "values" that means nothing in particular.
     */
    public static function maskFieldData($fieldData)
    {
        if (!is_array($fieldData)) {
            return array();
        }

        $out = array();

        foreach ($fieldData as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = isset($field['name']) ? strtolower((string) $field['name']) : '';
            $raw = isset($field['values'][0]) ? (string) $field['values'][0] : '';

            if (strpos($name, 'email') !== false) {
                $masked = self::maskEmail($raw);
            } elseif (strpos($name, 'phone') !== false || strpos($name, 'mobile') !== false) {
                $masked = self::maskPhone($raw);
            } elseif (strpos($name, 'name') !== false) {
                $masked = self::maskName($raw);
            } else {
                /*
                 * A free-text answer can contain anything the person typed,
                 * including their own phone number. Presence and length are
                 * recorded; the text is not.
                 */
                $masked = $raw === '' ? '' : '[' . strlen($raw) . ' chars]';
            }

            $out[] = array('field' => $name, 'value' => $masked);
        }

        return $out;
    }

    /**
     * a.person@example.com -> a***n@example.com
     *
     * The domain is kept because "every rejected delivery came from the same
     * domain" is a diagnosis, and a domain is not a person. The local part
     * keeps its first and last character so two different addresses at one
     * domain remain distinguishable in the log.
     */
    public static function maskEmail($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $at = strrpos($value, '@');

        if ($at === false || $at === 0) {
            return '[MASKED]';
        }

        $local = substr($value, 0, $at);
        $domain = substr($value, $at);

        if (strlen($local) <= 2) {
            return str_repeat('*', strlen($local)) . $domain;
        }

        return substr($local, 0, 1) . str_repeat('*', strlen($local) - 2) . substr($local, -1) . $domain;
    }

    /**
     * +91 98765 43210 -> +91*****3210
     *
     * Country prefix and last four digits: enough to match a log row against a
     * lead record you are already entitled to see, not enough to dial.
     *
     * "Country prefix" is defined as whatever digits precede the final ten,
     * because national numbers are ten digits in India and the US and country
     * codes are one to three. Keeping a fixed three would have exposed the
     * first digit of the subscriber number on a +91 number — which is what the
     * first version of this method did, and what the test below caught.
     */
    public static function maskPhone($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === '' || strlen($digits) < 4) {
            return '[MASKED]';
        }

        $keepFront = strlen($digits) > 10 ? strlen($digits) - 10 : 0;
        $front = $keepFront > 0 ? substr($digits, 0, $keepFront) : '';
        $back = substr($digits, -4);
        $stars = strlen($digits) - $keepFront - 4;

        return ($value[0] === '+' ? '+' : '') . $front . str_repeat('*', max(0, $stars)) . $back;
    }

    /**
     * Priya Sharma -> P*** S***
     *
     * Initials plus word count. Distinguishes two leads in a log without
     * reproducing either name.
     */
    public static function maskName($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        $out = array();

        foreach ($parts as $p) {
            $out[] = mb_substr($p, 0, 1) . '***';
        }

        return implode(' ', $out);
    }

    private static function scalar($value)
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        $value = (string) $value;

        /* A single field should never be enormous; store a marker instead. */
        if (strlen($value) > 512) {
            return '[' . strlen($value) . ' chars]';
        }

        return $value;
    }

    /**
     * Headers worth keeping, with the signature reduced to a fingerprint.
     *
     * The signature value itself is an HMAC over the body using the app secret.
     * It is not the secret, but it is a valid authenticator for that exact body
     * and there is no reason to keep it. Its first eight hex characters are
     * enough to confirm "the same delivery arrived twice with the same
     * signature", which is precisely the duplicate-replay question.
     */
    public static function safeHeaders(array $server)
    {
        $out = array();

        $sig = isset($server['HTTP_X_HUB_SIGNATURE_256']) ? (string) $server['HTTP_X_HUB_SIGNATURE_256'] : '';
        $out['signature_present'] = $sig !== '';
        $out['signature_fp'] = $sig === '' ? '' : substr(hash('sha256', $sig), 0, 8);

        foreach (array('HTTP_USER_AGENT' => 'user_agent',
                       'REQUEST_METHOD'  => 'method',
                       'CONTENT_TYPE'    => 'content_type') as $k => $label) {
            if (isset($server[$k])) {
                $out[$label] = substr((string) $server[$k], 0, 191);
            }
        }

        return $out;
    }

    /**
     * An identifier for one inbound request, so the log row, the activity entry
     * and any error can be tied together.
     *
     * Not a UUID and not security-relevant: it only has to be unique enough
     * that two deliveries in the same second are distinguishable.
     */
    public static function requestId()
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (Exception $e) {
            return substr(hash('sha256', uniqid('fb', true) . mt_rand()), 0, 16);
        }
    }
}
