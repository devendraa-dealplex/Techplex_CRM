<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Leadfinder_keyring.php';

/**
 * Leadfinder_tombstone
 *
 * What survives a purge, and why.
 *
 * THE PROBLEM THIS SOLVES
 *
 * A wasted prospect has to be forgotten and remembered at the same time. Forgotten,
 * because keeping a rejected business's phone number and address forever is
 * hoarding. Remembered, because the next `Schools in Ranchi` search returns the
 * same business, and if nothing survives, the employee rejects it again — and
 * again, every search, forever.
 *
 * So the purge clears the contact detail and leaves a tombstone: enough to
 * recognise the record if it comes back, and not enough to contact it.
 *
 * WHY THE KEYS ARE HMAC AND NOT A PLAIN HASH
 *
 * The obvious implementation is sha256(phone). It does not work, and the failure
 * is not subtle. An Indian mobile number is ten digits beginning 6-9: about four
 * billion candidates. A plain digest of one is recovered by exhaustive search in
 * minutes on ordinary hardware — so "we purged the phone number and kept only a
 * hash" would be false. The same holds for any low-entropy identifier: postcodes,
 * short business names, Place IDs drawn from a crawlable set.
 *
 * These keys are therefore HMAC-SHA256 under a server-side pepper that is not in
 * the row and not in the backup of the row. Without the pepper the digest is not
 * invertible by search; with it, suppression matching still works, because the
 * same input under the same pepper gives the same key.
 *
 * Consequences the operator has to accept, stated here rather than discovered:
 *   - The pepper must be stable. Rotating it silently orphans every existing
 *     suppression key, and rejected businesses start reappearing.
 *   - The pepper must be backed up, separately from the database.
 *   - The pepper is a secret. This class never logs it, never returns it, and
 *     refuses to derive a key without one rather than falling back to a plain
 *     hash, which would look identical and protect nothing.
 *
 * Pure: no database, no clock, no I/O.
 */
class Leadfinder_tombstone
{
    /* ---- what goes, what stays ----------------------------------------- */

    /**
     * Cleared at purge time. Everything that could contact, locate or re-identify
     * the business as a person-shaped record.
     *
     * @return array
     */
    public static function purgedFields()
    {
        return array(
            'phone',
            'phone_raw',
            'phone_e164',
            'email',
            'email_raw',
            'website',
            'lat',
            'lng',
            'formatted_address',
            'address_line',
            'street',
            'postal_code',
            'api_payload',
            'raw_response',
            'notes',
            'contact_name',
        );
    }

    /**
     * Kept after purge. Non-contact, minimal, and sufficient to recognise a
     * returning record.
     *
     * `city` and `state` stay deliberately: they are not contact details, and
     * without some geography a suppression list cannot be reasoned about at all.
     * Street, postcode and coordinates go.
     *
     * @return array
     */
    public static function tombstoneFields()
    {
        return array(
            'source_type',        // 'google_places', etc.
            'pepper_version',     // which keyring version made the keys below
            'rekey_state',
            'source_ref_key',     // HMAC
            'place_id_key',       // HMAC
            'phone_key',          // HMAC, where retaining one is permitted
            'email_key',          // HMAC, where retaining one is permitted
            'business_name_hint', // coarse, truncated; see nameHint()
            'city',
            'state',
            'waste_reason',
            'suppression_kind',   // waste | dnc
            'decided_at',
            'decided_by',
            'purge_state',
            'purged_at',
        );
    }

    /**
     * Fields that must never survive a purge under any configuration. The test
     * suite asserts that none of these appears in tombstoneFields().
     *
     * @return array
     */
    public static function neverRetained()
    {
        return array('phone', 'phone_raw', 'phone_e164', 'email', 'email_raw',
                     'website', 'lat', 'lng', 'formatted_address', 'street',
                     'postal_code', 'api_payload', 'raw_response', 'notes',
                     'contact_name');
    }

    /* ---- suppression kinds --------------------------------------------- */

    const S_WASTE = 'waste';
    const S_DNC   = 'dnc';

    /**
     * Suppression kinds, and whether their tombstone may ever expire.
     *
     * Waste suppression expires: a business rejected as "wrong category" in
     * March may be worth looking at next year, and an eternal blocklist built
     * from one employee's judgement is its own problem.
     *
     * DNC does not expire. A business that asked not to be contacted has not
     * asked again, and the whole point of the record is that it outlives the
     * prospect. This is why DNC is not a waste reason: the ordinary waste purge
     * would eventually drop it, and the next import would contact them.
     *
     * @return array
     */
    public static function suppressionKinds()
    {
        return array(
            self::S_WASTE => array(
                'expires'       => true,
                'purges_pii'    => true,
                'reimport'      => 'suppressed_until_expiry',
                'why'           => 'a rejected result should not be re-offered every search, but the judgement should not be permanent',
            ),
            self::S_DNC => array(
                'expires'       => false,
                'purges_pii'    => true,
                'reimport'      => 'suppressed_permanently',
                'why'           => 'the business asked not to be contacted; the promise outlives the record',
            ),
        );
    }

    /**
     * @param  string $kind
     * @return bool
     */
    public static function expires($kind)
    {
        $k = self::suppressionKinds();

        return isset($k[$kind]) ? (bool) $k[$kind]['expires'] : true;
    }

    /**
     * Is this tombstone eligible to be deleted outright?
     *
     * DNC never is. This is the single check standing between a tidy-up job and
     * calling someone who asked not to be called, so it refuses anything it does
     * not positively recognise as expirable waste.
     *
     * @param  array $t       tombstone row
     * @param  int   $nowTs
     * @param  int   $ttlDays suppression period for waste
     * @return array {deletable: bool, reason: string}
     */
    public static function mayDeleteTombstone(array $t, $nowTs, $ttlDays)
    {
        $kind = isset($t['suppression_kind']) ? (string) $t['suppression_kind'] : '';

        if ($kind === self::S_DNC) {
            return array('deletable' => false, 'reason' => 'dnc_never_deleted');
        }

        if ($kind !== self::S_WASTE) {
            return array('deletable' => false, 'reason' => 'unknown_suppression_kind');
        }

        if (!isset($t['decided_at']) || !is_numeric($t['decided_at'])) {
            return array('deletable' => false, 'reason' => 'no_decision_timestamp');
        }

        $ttl = (int) $ttlDays;
        if ($ttl <= 0) {
            return array('deletable' => false, 'reason' => 'suppression_period_not_configured');
        }

        $age = ((int) $nowTs - (int) $t['decided_at']) / 86400;

        if ($age < $ttl) {
            return array('deletable' => false, 'reason' => 'within_suppression_period');
        }

        return array('deletable' => true, 'reason' => 'waste_suppression_expired');
    }

    /* ---- key derivation ------------------------------------------------ */

    /*
     * Key derivation lives in Leadfinder_keyring. This class holds the policy —
     * what is purged, what survives, and what DNC means — and delegates the
     * cryptography rather than owning a second copy of it.
     */

    /**
     * Derive a suppression key under the keyring's active version.
     *
     * @param  mixed  $keyring
     * @param  string $domain      a Leadfinder_keyring::D_* domain
     * @param  string $value       already normalised by the caller
     * @param  string $sourceType  required for the source domain
     * @return array {key, version, error}
     */
    public static function key($keyring, $domain, $value, $sourceType = '')
    {
        return Leadfinder_keyring::derive($keyring, $domain, $value, $sourceType);
    }

    /**
     * May a tombstone be written at all?
     *
     * No usable keyring means no tombstone — and, just as importantly, no purge.
     * Purging the PII while unable to write the tombstone that replaces it would
     * destroy the data and keep none of the suppression.
     *
     * @param  mixed $keyring
     * @return array {ok: bool, failure: array|null}
     */
    public static function mayWrite($keyring)
    {
        $f = Leadfinder_keyring::failure($keyring);

        return array('ok' => $f === null, 'failure' => $f);
    }

    /**
     * Constant-time key comparison.
     *
     * @param  string $a
     * @param  string $b
     * @return bool
     */
    public static function keysMatch($a, $b)
    {
        return Leadfinder_keyring::keysMatch($a, $b);
    }

    /* ---- re-import suppression ----------------------------------------- */

    /**
     * Should an incoming search result be suppressed?
     *
     * Matching is by key, in descending order of confidence. A Place ID or a
     * source reference identifies the same listing outright. A phone match is
     * weaker — a shared switchboard puts several businesses behind one number —
     * so it is reported as a possible match for review, not an automatic drop,
     * which is the same rule the duplicate checker already applies.
     *
     * @param  array $candidateKeys  kind => key, for the incoming result
     * @param  array $tombstone      a tombstone row
     * @return array {suppress: bool, confidence: string, matched: string|null, kind: string|null}
     */
    public static function matches(array $candidateKeys, array $tombstone)
    {
        /*
         * The candidate keys arrive indexed by version, because a tombstone
         * written before a rotation can only be matched under the version that
         * wrote it. A caller that hands over active-version keys alone will
         * silently stop matching older rows — which looks like a search bug and
         * is really a key-rotation bug, so the version is required here rather
         * than assumed.
         */
        $version = isset($tombstone['pepper_version']) ? (string) $tombstone['pepper_version'] : '';

        if ($version === '') {
            return self::miss('tombstone_has_no_pepper_version');
        }

        $keys = isset($candidateKeys[$version]) && is_array($candidateKeys[$version])
            ? $candidateKeys[$version]
            : null;

        if ($keys === null) {
            // The row names a version this keyring cannot derive under. Failing
            // safe means reporting it, not treating the record as unseen.
            return self::miss('no_candidate_key_for_version:' . $version);
        }

        $exact = array(
            Leadfinder_keyring::D_PLACE  => 'place_id_key',
            Leadfinder_keyring::D_SOURCE => 'source_ref_key',
        );

        foreach ($exact as $domain => $col) {
            if (!empty($keys[$domain]) && !empty($tombstone[$col])
                && self::keysMatch($keys[$domain], $tombstone[$col])) {
                return self::hit(true, 'exact', $domain, $tombstone);
            }
        }

        /*
         * Phone and email are weaker. A shared switchboard puts several
         * businesses behind one number, so suppressing on a phone match alone
         * would silently drop every other business on that line — the same trap
         * the duplicate checker already refuses to fall into.
         */
        foreach (array(Leadfinder_keyring::D_PHONE => 'phone_key',
                       Leadfinder_keyring::D_EMAIL => 'email_key') as $domain => $col) {
            if (!empty($keys[$domain]) && !empty($tombstone[$col])
                && self::keysMatch($keys[$domain], $tombstone[$col])) {
                return self::hit(false, 'possible', $domain, $tombstone);
            }
        }

        return self::miss('no_match');
    }

    /**
     * Build the per-version candidate key set for one incoming result.
     *
     * @param  mixed $keyring
     * @param  array $ids  domain => value; source uses array(type, ref)
     * @return array version => (domain => key)
     */
    public static function candidateKeys($keyring, array $ids)
    {
        $out = array();

        foreach (Leadfinder_keyring::versions($keyring) as $v) {
            $set = array();

            foreach ($ids as $domain => $value) {
                $sourceType = '';

                if ($domain === Leadfinder_keyring::D_SOURCE && is_array($value)) {
                    $sourceType = isset($value[0]) ? $value[0] : '';
                    $value      = isset($value[1]) ? $value[1] : '';
                }

                $k = Leadfinder_keyring::deriveWithVersion($keyring, $v, $domain, $value, $sourceType);

                if ($k !== null) {
                    $set[$domain] = $k;
                }
            }

            if ($set) {
                $out[$v] = $set;
            }
        }

        return $out;
    }

    /**
     * A coarse, non-identifying hint kept so a suppression list is readable by a
     * human reviewing it. Truncated hard, and never the full trading name plus
     * address that would make the tombstone a contact record again.
     *
     * @param  string $name
     * @return string
     */
    public static function nameHint($name)
    {
        $name = is_string($name) ? trim(preg_replace('/\s+/', ' ', $name)) : '';

        if ($name === '') {
            return '';
        }

        $max = 24;
        $len = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

        if ($len <= $max) {
            return $name;
        }

        return (function_exists('mb_substr') ? mb_substr($name, 0, $max, 'UTF-8') : substr($name, 0, $max)) . '…';
    }

    /* ---- purge states -------------------------------------------------- */

    const P_PENDING = 'pending';
    const P_DUE     = 'due';
    const P_DONE    = 'purged';
    const P_HELD    = 'held';      // legal hold or DNC: PII cleared, tombstone kept

    /**
     * @return array
     */
    public static function purgeStates()
    {
        return array(self::P_PENDING, self::P_DUE, self::P_DONE, self::P_HELD);
    }

    /**
     * Is the record's PII due for clearing?
     *
     * Separate from tombstone deletion: DNC records purge their PII on the same
     * schedule as waste, they simply keep the suppression key afterwards.
     *
     * @param  array $r
     * @param  int   $nowTs
     * @param  int   $retentionDays
     * @return bool
     */
    public static function piiDue(array $r, $nowTs, $retentionDays)
    {
        if (!isset($r['decided_at']) || !is_numeric($r['decided_at'])) {
            return false;
        }

        if (isset($r['purge_state']) && $r['purge_state'] === self::P_DONE) {
            return false;
        }

        $days = (int) $retentionDays;

        if ($days < 0) {
            return false;
        }

        return (((int) $nowTs - (int) $r['decided_at']) / 86400) >= $days;
    }

    private static function hit($suppress, $confidence, $domain, array $t)
    {
        return array(
            'suppress'   => (bool) $suppress,
            'confidence' => $confidence,
            'matched'    => $domain,
            'kind'       => isset($t['suppression_kind']) ? $t['suppression_kind'] : null,
            'note'       => null,
        );
    }

    private static function miss($note)
    {
        return array('suppress' => false, 'confidence' => 'none',
                     'matched' => null, 'kind' => null, 'note' => $note);
    }
}
