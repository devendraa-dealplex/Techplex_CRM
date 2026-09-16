<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_secret — the Google API key, and the rule that nobody sees it.
 *
 * THE REQUIREMENT, RESTATED AS A PROPERTY
 * ---------------------------------------
 * "Employees must never see, copy or modify the raw API key. Never expose keys
 * in frontend code, browser network responses, logs or errors."
 *
 * That is not a UI rule. A key typed into a form comes back in the form's value
 * attribute; a key in a config array ends up in a debug dump; a key in a URL
 * ends up in an access log and a Referer header; a failed cURL call puts the
 * whole request — key included — into an exception message. Each is a different
 * leak and none of them is fixed by hiding an input field.
 *
 * So the plaintext key has exactly ONE exit from this class: `useFor()`, which
 * hands it to a callback and never returns it. Nothing else in the module can
 * obtain it. `forDisplay()` returns a masked string and a fingerprint, which is
 * what an administrator actually needs — "which key is loaded, and has it
 * changed" — without the value.
 *
 * FAIL CLOSED ON ENCRYPTION
 * -------------------------
 * If the crypto is unavailable, this class REFUSES to store. The established
 * `Payplex_secret` in this codebase falls back to the raw value when decrypt()
 * fails, which is correct there — it has legacy plaintext rows to keep reading.
 * A new module has no legacy, so the same fallback would only ever mean "write
 * the key in the clear and say nothing". Storing a secret unencrypted must be
 * an error, never a degraded success.
 *
 * WHAT THIS CLASS DOES NOT DO
 * ---------------------------
 * It does not generate, rotate or transport keys. Rotation is a human-owned
 * security action: an administrator pastes a new key, this class encrypts it
 * and reports only the fingerprint change.
 */
class Leadfinder_secret
{
    /** Shown instead of a key, always this length, so the mask leaks no length. */
    const MASK = '••••••••••••••••';

    const E_NO_CRYPTO   = 'encryption_unavailable';
    const E_EMPTY       = 'empty_secret';
    const E_TOO_SHORT   = 'secret_implausibly_short';
    const E_UNDECRYPT   = 'stored_value_not_decryptable';

    /**
     * Minimum plausible length. Not a Google-specific constant — a guard against
     * storing "" or "test" and believing a key is configured.
     */
    const MIN_LEN = 16;

    /**
     * Encrypt for storage.
     *
     * @param callable $encrypt fn(string $plain): string|false
     * @return array ok, value (ciphertext) | error
     */
    public static function seal($plain, $encrypt)
    {
        if (!is_string($plain) || trim($plain) === '') { return self::err(self::E_EMPTY); }
        $plain = trim($plain);
        if (strlen($plain) < self::MIN_LEN)           { return self::err(self::E_TOO_SHORT); }
        if (!is_callable($encrypt))                   { return self::err(self::E_NO_CRYPTO); }

        $cipher = call_user_func($encrypt, $plain);
        if (!is_string($cipher) || $cipher === '' || $cipher === $plain) {
            /* $cipher === $plain catches a no-op "encryptor" that returns its
               input. Without this check the module would report success and
               store the key in the clear. */
            return self::err(self::E_NO_CRYPTO);
        }
        return array('ok' => true, 'value' => $cipher, 'error' => null);
    }

    /**
     * The only way to use a key. The plaintext is passed to $fn and dropped.
     *
     * Whatever $fn returns is passed back untouched EXCEPT that the plaintext is
     * scrubbed from it first — a transport that echoes the request URL into its
     * result would otherwise hand the key straight back out.
     *
     * @param string   $cipher stored value
     * @param callable $decrypt fn(string): string|false
     * @param callable $fn      fn(string $plainKey): mixed
     */
    public static function useFor($cipher, $decrypt, $fn)
    {
        if (!is_string($cipher) || $cipher === '') { return self::err(self::E_EMPTY); }
        if (!is_callable($decrypt))                { return self::err(self::E_NO_CRYPTO); }

        $plain = call_user_func($decrypt, $cipher);
        if (!is_string($plain) || $plain === '') {
            /* Deliberately NOT falling back to $cipher. A stored value we cannot
               decrypt is a configuration fault to be reported, not a key to be
               used as-is. */
            return self::err(self::E_UNDECRYPT);
        }

        $out = call_user_func($fn, $plain);
        $out = self::redact($out, $plain);
        unset($plain);

        return array('ok' => true, 'value' => $out, 'error' => null);
    }

    /**
     * What an administrator may see about a stored key: that it exists, and
     * which one it is. Never the value, never its length.
     */
    public static function forDisplay($cipher, $decrypt)
    {
        if (!is_string($cipher) || $cipher === '') {
            return array('configured' => false, 'masked' => '', 'fingerprint' => '',
                         'state' => 'absent');
        }
        $plain = is_callable($decrypt) ? call_user_func($decrypt, $cipher) : false;
        if (!is_string($plain) || $plain === '') {
            return array('configured' => true, 'masked' => self::MASK, 'fingerprint' => '',
                         'state' => 'unreadable');
        }
        $d = array('configured' => true, 'masked' => self::MASK,
                   'fingerprint' => self::fingerprint($plain), 'state' => 'ok');
        unset($plain);
        return $d;
    }

    /**
     * A short, non-reversible identity for a key.
     *
     * Lets an administrator confirm a rotation actually took ("the fingerprint
     * changed") and lets two profiles be compared ("these are the same key")
     * without either fact requiring the value. Truncated so that it cannot be
     * used to verify a guessed key at any useful rate.
     */
    public static function fingerprint($plain)
    {
        if (!is_string($plain) || $plain === '') { return ''; }
        return substr(hash('sha256', 'payplex-lf-fp:' . $plain), 0, 12);
    }

    /**
     * Remove a known plaintext from anything on its way to a log, a view or an
     * error message. Walks arrays and objects, because the value that leaks is
     * rarely the top-level one.
     */
    public static function redact($subject, $plain)
    {
        if (!is_string($plain) || strlen($plain) < 8) { return $subject; }

        if (is_string($subject)) {
            return str_replace(array($plain, rawurlencode($plain), urlencode($plain)),
                               self::MASK, $subject);
        }
        if (is_array($subject)) {
            $out = array();
            foreach ($subject as $k => $v) {
                $out[is_string($k) ? str_replace($plain, self::MASK, $k) : $k]
                    = self::redact($v, $plain);
            }
            return $out;
        }
        if (is_object($subject)) {
            $out = clone $subject;
            foreach (get_object_vars($out) as $k => $v) { $out->$k = self::redact($v, $plain); }
            return $out;
        }
        return $subject;
    }

    /**
     * Belt-and-braces scrub for text whose secret we do NOT hold — cURL error
     * strings, exception messages, Google error bodies.
     *
     * Matches on shape: a `key=` query parameter, an Authorization header, an
     * X-Goog-Api-Key header. It cannot catch everything, which is why it is the
     * second line of defence and `redact()` with the known plaintext is the
     * first.
     */
    public static function scrub($text)
    {
        if (!is_string($text) || $text === '') { return $text; }
        $patterns = array(
            '/([?&](?:key|api_?key|apikey)=)[^&\s"\']+/i',
            '/(X-Goog-Api-Key\s*:\s*)\S+/i',
            '/(Authorization\s*:\s*(?:Bearer|Basic)\s+)\S+/i',
            '/\bAIza[0-9A-Za-z\-_]{10,}/',
        );
        foreach ($patterns as $p) {
            $text = preg_replace_callback($p, function ($m) {
                return (count($m) > 1 ? $m[1] : '') . Leadfinder_secret::MASK;
            }, $text);
        }
        return $text;
    }

    /**
     * True when $haystack contains $plain anywhere. Used by the view-payload
     * assertion and by tests — a leak check has to be callable, not a comment.
     */
    public static function leaks($haystack, $plain)
    {
        if (!is_string($plain) || $plain === '') { return false; }
        return self::redact($haystack, $plain) !== $haystack;
    }

    private static function err($code)
    {
        return array('ok' => false, 'value' => null, 'error' => $code);
    }
}
