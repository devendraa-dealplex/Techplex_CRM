<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Payplex_phone — one phone number, one canonical form.
 *
 * WHY THIS EXISTS
 * ---------------
 * Duplicate detection in this CRM currently compares phone numbers as strings.
 * `leadgen_google/controllers/Webhook.php` does
 * `WHERE phonenumber = ?` with whatever the payload sent, so the same business
 * arriving as "+91 98765 43210", "09876543210" and "9876543210" defeats the
 * check three times out of three and creates three leads. Grepping the whole
 * workspace for a normaliser returns nothing — there has never been one.
 *
 * Section 10 of the Lead Finder spec makes phone one of seven duplicate keys.
 * Building that on top of string equality would produce a duplicate checker
 * that reports "New" for a record it already holds — a control that says
 * something true and can never say the other thing.
 *
 * NOTHING IS HARD-CODED
 * ---------------------
 * No country code, trunk prefix or number length appears in this file as a
 * literal. A `$profile` is passed in, seeded from module configuration by
 * migration and editable by an administrator. With no profile, the library
 * accepts ONLY numbers already in international +CC form and refuses to guess
 * at anything else. Guessing a country is how a number for one business
 * silently matches another.
 *
 * WHAT IT RETURNS
 * ---------------
 * Never a bare string. Always a result array, because "could not normalise"
 * has to be representable — a normaliser that returns a string has to invent
 * something when the input is junk, and whatever it invents becomes a match key.
 *
 *   e164     canonical +CC… form, or null
 *   key      exact-duplicate key (digits of e164), or null
 *   weakKey  the trailing national digits, for POSSIBLE-duplicate only
 *   valid    bool
 *   reason   why not, when not
 *   ext      extension digits, kept apart rather than folded into the number
 */
class Payplex_phone
{
    const R_OK            = 'ok';
    const R_EMPTY         = 'empty';
    const R_NO_DIGITS     = 'no_digits';
    const R_NO_PROFILE    = 'not_international_and_no_profile';
    const R_BAD_LENGTH    = 'national_length_not_permitted';
    const R_UNKNOWN_CC    = 'country_code_not_permitted';

    /**
     * @param string $raw     whatever a human or an API gave us
     * @param array  $profile array(
     *     'cc'              => '91',            // default country calling code
     *     'trunk_prefix'    => '0',             // national trunk prefix to strip
     *     'national_len'    => array(10),       // permitted national lengths
     *     'weak_key_len'    => 10,              // trailing digits for a weak match
     *     'allowed_cc'      => array('91'),     // country codes this install accepts
     * ) — every key optional; an empty profile means international-form only.
     */
    public static function normalise($raw, array $profile = array())
    {
        if (!is_string($raw) && !is_int($raw)) { return self::no(self::R_EMPTY); }

        $s = trim((string) $raw);
        if ($s === '') { return self::no(self::R_EMPTY); }

        /* An extension is not part of the number. Folded in, "22334455 x12"
           and "22334455 x13" become different businesses. */
        $ext = '';
        if (preg_match('/(?:ext|extn|x|#)\.?\s*([0-9]{1,6})\s*$/i', $s, $m)) {
            $ext = $m[1];
            $s   = substr($s, 0, strlen($s) - strlen($m[0]));
        }

        $intl   = self::looksInternational($s);
        $digits = preg_replace('/[^0-9]/', '', $s);
        if ($digits === '') { return self::no(self::R_NO_DIGITS, $ext); }

        $cc           = isset($profile['cc']) ? preg_replace('/[^0-9]/', '', (string) $profile['cc']) : '';
        $trunk        = isset($profile['trunk_prefix']) ? (string) $profile['trunk_prefix'] : '';
        $nationalLens = isset($profile['national_len']) && is_array($profile['national_len'])
                        ? array_map('intval', $profile['national_len']) : array();
        $allowedCc    = isset($profile['allowed_cc']) && is_array($profile['allowed_cc'])
                        ? array_map('strval', $profile['allowed_cc']) : array();
        $weakLen      = isset($profile['weak_key_len']) ? (int) $profile['weak_key_len'] : 0;

        if ($intl) {
            /* "00" is the international access prefix in most of the world; it
               is not part of the number and must go before anything else looks
               at the leading digits. */
            if (strpos($digits, '00') === 0 && strpos($s, '+') === false) {
                $digits = substr($digits, 2);
            }
            if ($digits === '') { return self::no(self::R_NO_DIGITS, $ext); }
            $national = ($cc !== '' && strpos($digits, $cc) === 0)
                        ? substr($digits, strlen($cc)) : null;
            $thisCc   = ($national !== null) ? $cc : null;

            if ($thisCc === null) {
                /* A country code we were not configured for. Accept the number
                   as given rather than mangle it, but only if this install
                   permits foreign numbers at all. */
                if ($allowedCc && !self::ccAllowed($digits, $allowedCc)) {
                    return self::no(self::R_UNKNOWN_CC, $ext);
                }
                return self::yes('+' . $digits, $digits, self::tail($digits, $weakLen), $ext);
            }
        } else {
            if ($cc === '') { return self::no(self::R_NO_PROFILE, $ext); }

            /* Already carries the country code without a plus: "919876543210".
               Only treat it that way when what remains is a permitted national
               length, otherwise "9198765" would lose its first two digits. */
            if (strpos($digits, $cc) === 0
                && self::lenOk(strlen($digits) - strlen($cc), $nationalLens)) {
                $national = substr($digits, strlen($cc));
            } else {
                $national = $digits;
                if ($trunk !== '' && strpos($national, $trunk) === 0
                    && self::lenOk(strlen($national) - strlen($trunk), $nationalLens)) {
                    $national = substr($national, strlen($trunk));
                }
            }
            $thisCc = $cc;
        }

        if (!self::lenOk(strlen($national), $nationalLens)) {
            return self::no(self::R_BAD_LENGTH, $ext);
        }

        $e164 = '+' . $thisCc . $national;
        return self::yes($e164, $thisCc . $national, self::tail($national, $weakLen), $ext);
    }

    /**
     * Do two raw numbers denote the same line?
     *
     * 'exact'    same canonical number
     * 'possible' same trailing national digits but not the same canonical number
     * 'no'       different, or either one could not be normalised
     *
     * Two unnormalisable numbers are NEVER 'exact', however identical their
     * text. "n/a" equals "n/a" as a string and would merge every business that
     * has no phone number into one.
     */
    public static function compare($a, $b, array $profile = array())
    {
        $x = self::normalise($a, $profile);
        $y = self::normalise($b, $profile);
        if (!$x['valid'] || !$y['valid']) { return 'no'; }
        if ($x['key'] === $y['key'])      { return 'exact'; }
        if ($x['weakKey'] !== null && $x['weakKey'] === $y['weakKey']) { return 'possible'; }
        return 'no';
    }

    /** Masked for display in lists and logs: keeps the tail, hides the rest. */
    public static function mask($e164, $keep = 4)
    {
        if (!is_string($e164) || $e164 === '') { return ''; }
        $keep = max(0, (int) $keep);
        $n    = strlen($e164);
        if ($keep >= $n) { return str_repeat('•', $n); }
        return str_repeat('•', $n - $keep) . substr($e164, $n - $keep);
    }

    /* ------------------------------------------------------------------ */

    private static function looksInternational($s)
    {
        return strpos(ltrim($s), '+') === 0 || preg_match('/^\s*00[0-9]/', $s) === 1;
    }

    private static function ccAllowed($digits, array $allowed)
    {
        foreach ($allowed as $cc) {
            if ($cc !== '' && strpos($digits, (string) $cc) === 0) { return true; }
        }
        return false;
    }

    /* No permitted-length list configured means "do not police length". It does
       NOT mean "reject everything" — that would make an unconfigured install
       silently normalise nothing. */
    private static function lenOk($len, array $permitted)
    {
        if (!$permitted) { return $len > 0; }
        return in_array((int) $len, $permitted, true);
    }

    private static function tail($digits, $len)
    {
        $len = (int) $len;
        if ($len <= 0 || strlen($digits) < $len) { return null; }
        return substr($digits, -$len);
    }

    private static function yes($e164, $key, $weak, $ext)
    {
        return array('valid' => true, 'e164' => $e164, 'key' => $key,
                     'weakKey' => $weak, 'ext' => $ext, 'reason' => self::R_OK);
    }

    private static function no($reason, $ext = '')
    {
        return array('valid' => false, 'e164' => null, 'key' => null,
                     'weakKey' => null, 'ext' => $ext, 'reason' => $reason);
    }
}
