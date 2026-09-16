<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Payplex_phone.php';

/**
 * Leadfinder_dupe — §10, across the queue and the main CRM.
 *
 * THE SEVEN KEYS, RANKED
 * ----------------------
 * §10 lists seven. They are not equally strong, and treating them as equal is
 * how a checker reports "Exact Duplicate" for two schools on the same street.
 *
 *   1. Google Place ID   — identity. One place, one id. EXACT.
 *   2. Normalised phone  — EXACT when the canonical numbers match.
 *   3. Website domain    — EXACT for a specific domain; POSSIBLE for a shared
 *                          host, because forty businesses share one free host.
 *   4. Verified email    — EXACT. An employee confirmed it on a call.
 *   5. Name + location   — POSSIBLE only. Never exact: chains exist.
 *   6. Address           — POSSIBLE only. Suites, floors, malls.
 *   7. Lat/long          — POSSIBLE only, and see the note below.
 *
 * WHY COORDINATES ARE NEARLY USELESS HERE, AND WHY THAT IS CORRECT
 * ----------------------------------------------------------------
 * Coordinates must be deleted 30 calendar days after they are fetched
 * (Service Specific Terms §14.3). So a coordinate match is unavailable for
 * exactly the records most likely to be stale duplicates. Rather than pretend
 * otherwise, coordinate matching is POSSIBLE-only, and an absent coordinate is
 * treated as "unknown", never as "not a match".
 *
 * NULL IS UNKNOWN, NEVER "NO MATCH"
 * ---------------------------------
 * `phone_match_key` is nullable and unbackfilled by approved decision, so most
 * existing leads have none. A checker that read NULL as "different" would
 * report New for every one of them. Every key here distinguishes *absent* from
 * *different*, and absence contributes nothing in either direction.
 *
 * FIVE VERDICTS, AS SPECIFIED
 * ---------------------------
 *   new · possible_duplicate · exact_duplicate · existing_prospect · existing_crm_lead
 *
 * The last two are locations, not strengths: they say WHERE the match was
 * found, which is what decides whether an employee may claim it or must be sent
 * to the existing lead.
 */
class Leadfinder_dupe
{
    const NEW_RECORD       = 'new';
    const POSSIBLE         = 'possible_duplicate';
    const EXACT            = 'exact_duplicate';
    const IN_QUEUE         = 'existing_prospect';
    const IN_CRM           = 'existing_crm_lead';

    const K_SOURCE_REF = 'source_reference';
    const K_PLACE_ID = 'google_place_id';
    const K_PHONE    = 'phone';
    const K_DOMAIN   = 'website_domain';
    const K_EMAIL    = 'verified_email';
    const K_NAME_LOC = 'name_and_location';
    const K_ADDRESS  = 'address';
    const K_COORDS   = 'coordinates';

    /** Keys strong enough to call something an exact duplicate on their own. */
    /**
     * Every verdict this classifier can return.
     *
     * Listed so a filter can be validated against it. A queue filtered on a
     * verdict string that no classification ever produces returns an empty page
     * and looks exactly like "there are none of those", which is a different
     * and wrong answer.
     */
    public static function allVerdicts()
    {
        return array(self::NEW_RECORD, self::POSSIBLE, self::EXACT, self::IN_QUEUE, self::IN_CRM);
    }

    public static function exactKeys()
    {
        return array(self::K_SOURCE_REF, self::K_PLACE_ID, self::K_PHONE, self::K_EMAIL);
    }

    /**
     * Is this number a switchboard that several businesses legitimately share?
     *
     * TWO WAYS TO KNOW, AND BOTH ARE NEEDED
     * -------------------------------------
     * The configured list catches the ones somebody has already noticed — a
     * business park's single reception line, a franchise's national number. The
     * threshold catches the ones nobody has: if the same number is already on
     * three different businesses, it is a switchboard whether or not it is on
     * anyone's list, and the fourth match is not evidence that the fourth
     * business is a duplicate of the first.
     *
     * This matters because the phone is otherwise an EXACT key. Without this,
     * every tenant of a shared office merges into whichever one was entered
     * first, and the merge is silent.
     *
     * The threshold counts DISTINCT businesses, not rows. Ten rows for one
     * business with one number is not a switchboard; it is a duplicate problem
     * of a different kind, and treating it as a switchboard would suppress the
     * very match that should fire.
     */
    public static function isSharedNumber($e164, array $opt = array())
    {
        $n = trim((string) $e164);

        if ($n === '') { return false; }

        $listed = isset($opt['shared_numbers']) ? (array) $opt['shared_numbers'] : array();

        foreach ($listed as $s) {
            if (trim((string) $s) !== '' && trim((string) $s) === $n) { return true; }
        }

        $counts    = isset($opt['phone_business_counts']) ? (array) $opt['phone_business_counts'] : array();
        $threshold = isset($opt['shared_phone_threshold']) ? (int) $opt['shared_phone_threshold'] : 0;

        if ($threshold > 0 && isset($counts[$n]) && (int) $counts[$n] >= $threshold) {
            return true;
        }

        return false;
    }

    /** Keys that may only ever raise a suspicion. */
    public static function possibleOnlyKeys()
    {
        return array(self::K_NAME_LOC, self::K_ADDRESS, self::K_COORDS);
    }

    /**
     * Hosts on which a shared domain means nothing. Configuration, not a
     * constant: the list is regional and changes, and a business on a free host
     * is still a business.
     */
    public static function isSharedHost($domain, array $sharedHosts)
    {
        $d = self::domainOf($domain);
        if ($d === '') { return false; }
        foreach ($sharedHosts as $h) {
            $h = strtolower(trim((string) $h));
            if ($h !== '' && ($d === $h || substr($d, -(strlen($h) + 1)) === '.' . $h)) {
                return true;
            }
        }
        return false;
    }

    /** Normalise a URL to a comparable registrable host. */
    public static function domainOf($url)
    {
        if (!is_string($url) || trim($url) === '') { return ''; }
        $u = trim(strtolower($url));
        if (strpos($u, '//') === false) { $u = 'http://' . $u; }
        $host = parse_url($u, PHP_URL_HOST);
        if (!is_string($host) || $host === '') { return ''; }
        if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }
        return $host;
    }

    /**
     * Compare a candidate against one existing record.
     *
     * @param array $cand      the incoming prospect
     * @param array $existing  a queue row or a CRM lead row
     * @param array $opt       phone_profile, shared_hosts
     * @return array strength (exact|possible|no), keys (which matched)
     */
    public static function compareOne(array $cand, array $existing, array $opt = array())
    {
        $profile = isset($opt['phone_profile']) ? (array) $opt['phone_profile'] : array();
        $shared  = isset($opt['shared_hosts']) ? (array) $opt['shared_hosts'] : array();

        $exact    = array();
        $possible = array();

        /*
         * 0. Source reference — the id the record carried in from wherever it
         *    came. A Facebook lead id, an import batch row, a prior CRM ref.
         *
         * It is an exact key because it is an identity assigned by a system, not
         * an attribute observed about a business: two records with the same
         * source reference are the same record arriving twice. NULL means "this
         * did not come from a referenced source", which is not a match with
         * every other unreferenced record — `bothPresent` enforces that.
         */
        if (self::bothPresent($cand, $existing, 'source_ref')
            && (string) $cand['source_ref'] !== ''
            && $cand['source_ref'] === $existing['source_ref']) {
            $exact[] = self::K_SOURCE_REF;
        }

        /* 1. Place ID — identity. */
        if (self::bothPresent($cand, $existing, 'google_place_id')
            && $cand['google_place_id'] === $existing['google_place_id']) {
            $exact[] = self::K_PLACE_ID;
        }

        /* 2. Phone, normalised. compare() never calls two unnormalisable
              numbers equal, so "n/a" does not merge the phoneless. */
        $candPhone = self::first($cand, array('phone_e164', 'phone_raw', 'phonenumber'));
        $existPhone = self::first($existing, array('phone_e164', 'phone_match_key', 'phone_raw', 'phonenumber'));

        $ph = Payplex_phone::compare($candPhone, $existPhone, $profile);

        if ($ph === 'exact') {
            /*
             * A SHARED SWITCHBOARD IS NOT AN IDENTITY.
             *
             * Two businesses in the same building answering the same reception
             * line are two businesses. Left as an exact key, the second one
             * entered is silently classified as a duplicate of the first and
             * never reaches anybody — and nothing in the queue would show why.
             *
             * The Facebook module hit exactly this with a shared corporate
             * number. There the fix was made after the merge had already
             * happened; here it is made before, and the downgrade is to
             * POSSIBLE rather than to "no match", because a shared number is
             * still a reason for a human to look.
             */
            $sharedCand   = self::isSharedNumber($candPhone, $opt);
            $sharedExists = self::isSharedNumber($existPhone, $opt);

            if ($sharedCand || $sharedExists) { $possible[] = self::K_PHONE; }
            else                              { $exact[]    = self::K_PHONE; }
        } elseif ($ph === 'possible') {
            $possible[] = self::K_PHONE;
        }

        /* 3. Website domain. Shared hosts downgrade to possible. */
        $dc = self::domainOf(self::first($cand, array('website_domain', 'website')));
        $de = self::domainOf(self::first($existing, array('website_domain', 'website')));
        if ($dc !== '' && $dc === $de) {
            if (self::isSharedHost($dc, $shared)) { $possible[] = self::K_DOMAIN; }
            else                                  { $exact[]    = self::K_DOMAIN; }
        }

        /* 4. Verified email — a human confirmed it. */
        $ec = strtolower(trim((string) self::first($cand, array('verified_email', 'email'))));
        $ee = strtolower(trim((string) self::first($existing, array('verified_email', 'email'))));
        if ($ec !== '' && $ec === $ee) { $exact[] = self::K_EMAIL; }

        /* 5. Name + location. Possible only — chains exist. */
        $nc = self::slug(self::first($cand, array('business_name', 'name', 'company')));
        $ne = self::slug(self::first($existing, array('business_name', 'name', 'company')));
        $lc = self::slug(self::first($cand, array('city')));
        $le = self::slug(self::first($existing, array('city')));
        if ($nc !== '' && $nc === $ne && $lc !== '' && $lc === $le) {
            $possible[] = self::K_NAME_LOC;
        }

        /* 6. Address. Possible only. */
        $ac = self::slug(self::first($cand, array('address')));
        $ae = self::slug(self::first($existing, array('address')));
        if ($ac !== '' && $ac === $ae) { $possible[] = self::K_ADDRESS; }

        /* 7. Coordinates. Possible only, and often absent by law — see the
              class docblock. Compared at ~11 m to avoid float equality. */
        if (self::coordsClose($cand, $existing)) { $possible[] = self::K_COORDS; }

        if ($exact) {
            return array('strength' => 'exact', 'keys' => array_values(array_unique($exact)),
                         'weak_keys' => array_values(array_unique($possible)));
        }
        if ($possible) {
            return array('strength' => 'possible', 'keys' => array_values(array_unique($possible)),
                         'weak_keys' => array());
        }
        return array('strength' => 'no', 'keys' => array(), 'weak_keys' => array());
    }

    /**
     * Classify a candidate against the queue and the CRM at once.
     *
     * An exact match in the CRM outranks an exact match in the queue: a lead
     * already being worked by sales is a stronger reason to stop than a
     * prospect nobody has claimed.
     *
     * @return array verdict, where, matched_id, keys, all (every match found)
     */
    public static function classify(array $cand, array $queueRows, array $crmRows, array $opt = array())
    {
        $all = array();
        $bestCrm = null; $bestQueue = null;

        foreach ($crmRows as $row) {
            $c = self::compareOne($cand, $row, $opt);
            if ($c['strength'] === 'no') { continue; }
            $c['where'] = self::IN_CRM;
            $c['id']    = isset($row['id']) ? (int) $row['id'] : 0;
            $all[] = $c;
            if ($c['strength'] === 'exact' && $bestCrm === null) { $bestCrm = $c; }
        }
        foreach ($queueRows as $row) {
            $c = self::compareOne($cand, $row, $opt);
            if ($c['strength'] === 'no') { continue; }
            $c['where'] = self::IN_QUEUE;
            $c['id']    = isset($row['id']) ? (int) $row['id'] : 0;
            $all[] = $c;
            if ($c['strength'] === 'exact' && $bestQueue === null) { $bestQueue = $c; }
        }

        if ($bestCrm) {
            return self::verdict(self::EXACT, self::IN_CRM, $bestCrm, $all);
        }
        if ($bestQueue) {
            return self::verdict(self::EXACT, self::IN_QUEUE, $bestQueue, $all);
        }
        foreach ($all as $c) {
            if ($c['strength'] === 'possible') {
                return self::verdict(self::POSSIBLE, $c['where'], $c, $all);
            }
        }
        return array('verdict' => self::NEW_RECORD, 'where' => null, 'matched_id' => 0,
                     'keys' => array(), 'all' => array());
    }

    /**
     * §10: "Never create another lead when an exact record already exists."
     * The gate, stated once, so the conversion path has something to call.
     */
    public static function mayCreateLead(array $classification)
    {
        $v = isset($classification['verdict']) ? $classification['verdict'] : '';
        if ($v === self::EXACT) {
            return array('allowed' => false, 'reason' => 'exact_duplicate_exists');
        }
        if ($v === self::POSSIBLE) {
            /* A possible duplicate is not a refusal — it is a question for a
               human. It blocks the AUTOMATIC path and requires an explicit
               acknowledgement, which is recorded. */
            return array('allowed' => false, 'reason' => 'possible_duplicate_needs_review');
        }
        if ($v === self::NEW_RECORD) {
            return array('allowed' => true, 'reason' => 'no_match_found');
        }
        /* Unrecognised verdict: refuse. A classifier that returns something
           this function has never seen is a reason to stop, not to proceed. */
        return array('allowed' => false, 'reason' => 'unrecognised_classification');
    }

    /* ------------------------------------------------------------------ */

    private static function verdict($v, $where, array $c, array $all)
    {
        return array('verdict' => $v, 'where' => $where,
                     'matched_id' => isset($c['id']) ? $c['id'] : 0,
                     'keys' => $c['keys'], 'all' => $all);
    }

    private static function bothPresent(array $a, array $b, $k)
    {
        return isset($a[$k]) && isset($b[$k])
               && trim((string) $a[$k]) !== '' && trim((string) $b[$k]) !== '';
    }

    private static function first(array $r, array $keys)
    {
        foreach ($keys as $k) {
            if (isset($r[$k]) && trim((string) $r[$k]) !== '') { return $r[$k]; }
        }
        return '';
    }

    private static function slug($s)
    {
        $s = strtolower(trim((string) $s));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /* ~0.0001 degree ≈ 11 m. Integer comparison of scaled values: float
       equality on coordinates is a coin toss across a round trip. */
    private static function coordsClose(array $a, array $b)
    {
        foreach (array('latitude', 'longitude') as $k) {
            if (!isset($a[$k]) || !isset($b[$k])
                || $a[$k] === null || $b[$k] === null
                || trim((string) $a[$k]) === '' || trim((string) $b[$k]) === '') {
                return false;
            }
        }
        return (int) round((float) $a['latitude'] * 10000) === (int) round((float) $b['latitude'] * 10000)
            && (int) round((float) $a['longitude'] * 10000) === (int) round((float) $b['longitude'] * 10000);
    }
}
