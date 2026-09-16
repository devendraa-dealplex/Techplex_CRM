<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Who a lead is, and whether anybody can actually reach them.
 *
 * THE RULE THIS LIBRARY ENCODES
 * ----------------------------
 * A lead with a phone but no email is a lead. A lead with an email but no phone
 * is a lead. A lead with **neither** is not a lead — it is a record of an event
 * that produced no way to contact anybody, and putting it on the Leads list
 * means a salesperson opens it, finds nothing to do, and learns to distrust the
 * list. Those go to quarantine instead, where they are reviewable and inert.
 *
 * WHY MATCHING NO LONGER MERGES
 * -----------------------------
 * The previous behaviour attached a delivery whose email or phone matched an
 * existing lead to that lead, and created nothing new. That is silent merging,
 * and it is wrong in the one case that matters most: two people from the same
 * company filling the same form from the same switchboard number are two leads
 * and two commissions, and the second one vanished without a trace.
 *
 * So every distinct Facebook lead id now produces its own lead, and a matching
 * identifier produces a **Possible Duplicate** marker for a human to judge.
 * Nothing is merged, nothing is discarded, and the decision is recorded with a
 * person's name against it.
 *
 * SHARED NUMBERS
 * --------------
 * A switchboard number is not evidence of duplication — it is evidence of a
 * company. Two guards: an explicit list an administrator maintains, and a
 * frequency rule (a number already on N leads is a switchboard, whatever anyone
 * configured). Either one suppresses the duplicate marker; neither one suppresses
 * the lead.
 *
 * Pure functions. No database, no options, no side effects.
 */
class Facebook_identity
{
    /** Digits kept when comparing phone numbers: the subscriber part. */
    const PHONE_SIGNIFICANT_DIGITS = 10;

    /** Shortest thing that can be a phone number at all. */
    const PHONE_MIN_DIGITS = 7;

    /** A number on this many leads is a switchboard, not a duplicate. */
    const DEFAULT_SHARED_THRESHOLD = 3;

    const MATCH_EMAIL = 'email';
    const MATCH_PHONE = 'phone';

    /**
     * Normalise an email for comparison.
     *
     * Lowercased and trimmed, and nothing else. It is tempting to strip dots and
     * +tags the way Gmail does, and it would be wrong: those rules are Gmail's,
     * other providers treat the characters as significant, and a false match
     * here marks two real leads as duplicates of each other. Under-matching
     * produces a missed marker a human can still spot; over-matching produces a
     * confident wrong answer.
     */
    public static function normaliseEmail($email)
    {
        $e = strtolower(trim((string) $email));

        if ($e === '' || strpos($e, '@') === false) {
            return '';
        }

        /* One @ only, something either side. Not full RFC validation — this is a
           comparison key, not an acceptance test. */
        $parts = explode('@', $e);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return '';
        }

        if (strpos($parts[1], '.') === false) {
            return '';
        }

        return $e;
    }

    /**
     * Normalise a phone number for comparison.
     *
     * Digits only, then the last ten. A number arrives as +91 98765 43210,
     * 098765 43210, and 9876543210 from three different forms; all three are one
     * person. Comparing the last ten digits makes them one key without needing to
     * know the country, and ten is the significant length in the market this
     * install serves.
     *
     * Returns '' for anything too short to be a phone number, so a form that
     * sends "n/a" or "123" produces no key rather than a key that collides with
     * every other piece of junk.
     */
    public static function normalisePhone($phone)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        $digits = $digits === null ? '' : $digits;

        if (strlen($digits) < self::PHONE_MIN_DIGITS) {
            return '';
        }

        if (strlen($digits) > self::PHONE_SIGNIFICANT_DIGITS) {
            $digits = substr($digits, -self::PHONE_SIGNIFICANT_DIGITS);
        }

        /* All-same-digit strings (0000000000, 9999999999) are placeholders that
           forms and test tools emit. They would collide spectacularly. */
        if (preg_match('/\A(\d)\1+\z/', $digits) === 1) {
            return '';
        }

        return $digits;
    }

    /**
     * Can anybody actually contact this lead?
     *
     * The single question that decides lead versus quarantine.
     */
    public static function isContactable($email, $phone)
    {
        return self::normaliseEmail($email) !== '' || self::normalisePhone($phone) !== '';
    }

    /**
     * Which identifiers are present, as a short machine-readable reason.
     *
     * Used for the quarantine reason and for the delivery log, so the answer to
     * "why was this quarantined" is a fact rather than a sentence somebody has
     * to parse.
     */
    public static function contactState($email, $phone)
    {
        $hasEmail = self::normaliseEmail($email) !== '';
        $hasPhone = self::normalisePhone($phone) !== '';

        if ($hasEmail && $hasPhone) {
            return 'email+phone';
        }

        if ($hasEmail) {
            return 'email_only';
        }

        if ($hasPhone) {
            return 'phone_only';
        }

        return 'no_contact_identifier';
    }

    /**
     * The stored form of a matching key.
     *
     * A SHA-256 prefix, never the identifier itself. The duplicate table is a
     * review artifact; it does not need a second unprotected copy of every
     * lead's email address and phone number, and a reviewer only needs to know
     * that two leads matched on the same thing, not what that thing was — the
     * leads themselves carry it.
     *
     * Salted by the caller so the hashes are not a rainbow-table of the phone
     * book: sixteen hex characters of an unsalted phone hash is trivially
     * reversible, because there are only ten billion of them.
     */
    public static function identityKey($salt, $type, $normalisedValue)
    {
        $value = (string) $normalisedValue;

        if ($value === '' || (string) $salt === '') {
            return '';
        }

        return substr(hash('sha256', $salt . '|' . $type . '|' . $value), 0, 32);
    }

    /**
     * Numbers an administrator has declared shared, parsed from the option.
     *
     * Normalised on the way in, so a list written as "+91 22 6100 0000" matches
     * a delivery that sends "02261000000".
     */
    public static function parseSharedNumbers($raw)
    {
        $out = array();

        /*
         * Split on commas, semicolons, pipes and newlines — NOT on spaces.
         *
         * Found by its own test: splitting on whitespace tore "+91 22 6100 0000"
         * into four tokens, none of which is a phone number, so a switchboard
         * number typed the way people actually write it was silently dropped
         * from the list and its leads were flagged as duplicates forever.
         * A real number contains spaces; a list separator does not have to.
         */
        foreach (preg_split('/[,;|\r\n]+/', (string) $raw) as $candidate) {
            $n = self::normalisePhone($candidate);

            if ($n !== '' && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /**
     * Is this number a switchboard rather than a person?
     *
     * Two independent grounds, and either is enough:
     *
     *   - an administrator listed it;
     *   - it is already on `$existingCount` leads, at or above the threshold.
     *
     * The frequency rule exists because the list will always be incomplete.
     * Nobody maintains a list of every shared number their market uses, and the
     * consequence of missing one is a stream of false duplicate markers that
     * teaches everyone to ignore the queue.
     */
    public static function isSharedNumber($normalisedPhone, array $configuredShared, $existingCount, $threshold = null)
    {
        if ((string) $normalisedPhone === '') {
            return false;
        }

        if (in_array($normalisedPhone, $configuredShared, true)) {
            return true;
        }

        $limit = self::threshold($threshold);

        return (int) $existingCount >= $limit;
    }

    public static function threshold($configured)
    {
        $n = (int) $configured;

        /* Below two the rule would call every second occurrence a switchboard,
           which is the opposite of what it is for. */
        return $n >= 2 ? $n : self::DEFAULT_SHARED_THRESHOLD;
    }

    /** Tag applied to a lead that matched an existing one. */
    public static function duplicateTag()
    {
        return 'Possible Duplicate';
    }

    /** Tag applied to a quarantined record, if one is ever released. */
    public static function quarantineTag()
    {
        return 'No Contact Identifier';
    }

    public static function isMatchType($type)
    {
        return $type === self::MATCH_EMAIL || $type === self::MATCH_PHONE;
    }
}
