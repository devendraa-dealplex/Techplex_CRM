<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_secret — one way to read a stored secret.
 *
 * There were three, and they did not agree:
 *
 *   1. Payplex_api_client::readSecretOption() decrypted the option and
 *      returned the usable value.
 *   2. Webhook::index() repeated that decrypt inline.
 *   3. The settings view asked a different question entirely — is the stored
 *      option non-empty — and printed "configured" on the strength of it.
 *
 * Number 3 is the one that did damage. The stored value is ciphertext, so it
 * is non-empty whenever anything was ever saved, including a saved empty
 * string. The badge therefore read "configured" while the secret decrypted to
 * nothing, and the webhook receiver refused every event for want of a key that
 * the settings screen insisted was present. A status indicator that measures a
 * different thing from the control it describes is worse than no indicator.
 *
 * "Usable" here means exactly what the callers need: decrypts to a non-empty
 * string. That is the only sense in which a signing key is configured.
 */
class Payplex_secret
{
    /**
     * The single place this module decrypts anything.
     *
     * Three implementations once existed and drifted apart, which is the defect
     * this class was created to end; the suite enforces that by counting
     * decrypt call sites, and it counted the two this file added for the
     * at-rest check. Rather than exempt them, they route through here — the
     * invariant is worth more than the convenience, and a private helper is
     * what "one implementation" actually means.
     */
    private static function decryptRaw($raw)
    {
        $ci = &get_instance();
        $ci->load->library('encryption');
        return $ci->encryption->decrypt($raw);
    }

    /**
     * The usable secret behind an option, or '' when there is none.
     *
     * Falls back to the raw stored value when decryption fails, because early
     * installs stored these unencrypted and a readable legacy secret is still
     * a secret. A value that decrypts successfully TO an empty string is not.
     */
    public static function read($optionKey)
    {
        if (!function_exists('get_option')) { return ''; }

        $raw = get_option($optionKey);
        if ($raw === null || trim((string) $raw) === '') { return ''; }

        if (!function_exists('get_instance')) { return (string) $raw; }

        $dec = self::decryptRaw($raw);

        // decrypt() answers false when the value was not encrypted by us.
        $value = ($dec === false) ? $raw : $dec;

        return trim((string) $value) === '' ? '' : (string) $value;
    }

    /** Is there a secret that can actually be used to sign or verify? */
    public static function isUsable($optionKey)
    {
        return self::read($optionKey) !== '';
    }

    /**
     * How the secret is held on disk: 'absent', 'plaintext' or 'encrypted'.
     *
     * read() falls back to the raw value when decryption fails, and that
     * fallback is right — an early install stored these unencrypted and a
     * readable legacy secret is still a working secret, so refusing it would
     * break a live integration to make a point. But the fallback is silent, and
     * silence here means a credential can sit in the options table in clear
     * text indefinitely with every indicator showing green: isUsable() answers
     * true, the settings badge reads "configured", and nothing distinguishes it
     * from a properly encrypted one.
     *
     * This install is that case. payplex_aicalling_service_jwt is stored in
     * clear text while its two siblings — request_secret and webhook_secret —
     * are ciphertext. The save path encrypts all three, so the JWT was written
     * by something else and has been readable ever since.
     *
     * It is readable enough that a routine audit query printed part of it while
     * redacting the other two, because the redaction matched on names
     * containing "secret", "token", "key" or "pass" and this one is called
     * "jwt". The naming is why it escaped a filter; being plaintext is why
     * there was anything to escape with.
     *
     * Reported, never asserted: the question is answered by trying to decrypt,
     * not by inspecting the shape of the stored value.
     */
    public static function atRestState($optionKey)
    {
        if (!function_exists('get_option')) { return 'absent'; }

        $raw = get_option($optionKey);
        if ($raw === null || trim((string) $raw) === '') { return 'absent'; }

        if (!function_exists('get_instance')) { return 'plaintext'; }

        $dec = self::decryptRaw($raw);

        /* decrypt() answers false for anything this install did not encrypt */
        if ($dec === false) { return 'plaintext'; }
        return trim((string) $dec) === '' ? 'absent' : 'encrypted';
    }

    /** Every named secret that is sitting in clear text. */
    public static function plaintextAmong(array $optionKeys)
    {
        $out = array();
        foreach ($optionKeys as $label => $key) {
            if (self::atRestState($key) === 'plaintext') { $out[$label] = $key; }
        }
        return $out;
    }

    /**
     * Re-encrypt a secret that is stored in clear text. Idempotent.
     *
     * Deliberately narrow: it will not touch a value that is absent or already
     * encrypted, and it verifies the round-trip before writing, so a failure
     * leaves the working plaintext in place rather than replacing a usable
     * credential with something that cannot be read back.
     *
     * @return string 'absent' | 'already_encrypted' | 'encrypted' | 'failed'
     */
    public static function encryptAtRest($optionKey)
    {
        if (self::atRestState($optionKey) !== 'plaintext') {
            return self::atRestState($optionKey) === 'absent' ? 'absent' : 'already_encrypted';
        }
        if (!function_exists('get_instance') || !function_exists('update_option')) { return 'failed'; }

        $plain = (string) get_option($optionKey);
        if (trim($plain) === '') { return 'absent'; }

        $ci = &get_instance();
        $ci->load->library('encryption');
        $cipher = $ci->encryption->encrypt($plain);

        /* never replace a working secret with one that cannot be read back */
        if (!is_string($cipher) || $cipher === '') { return 'failed'; }
        $check = self::decryptRaw($cipher);
        if ($check === false || (string) $check !== $plain) { return 'failed'; }

        update_option($optionKey, $cipher);
        return 'encrypted';
    }

    /**
     * A UI-safe report: whether each named secret is usable, and nothing else.
     *
     * Never returns a value, a length, or a prefix. The question a settings
     * screen needs answered is binary, and anything more is a disclosure with
     * no purpose.
     *
     * @param  array $optionKeys label => option key
     * @return array label => bool
     */
    public static function statusOf(array $optionKeys)
    {
        $out = array();
        foreach ($optionKeys as $label => $key) {
            $out[$label] = self::isUsable($key);
        }
        return $out;
    }
}
