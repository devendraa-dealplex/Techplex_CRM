<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Leadfinder_keyring
 *
 * Versioned HMAC pepper handling for suppression keys.
 *
 * WHAT A PEPPER IS FOR HERE
 *
 * A waste tombstone has to recognise a returning business without holding its
 * contact details. The obvious implementation — store sha256(phone) — does not
 * work: an Indian mobile is ten digits beginning 6-9, about four billion
 * candidates, recovered by exhaustive search in minutes. A digest of a
 * low-entropy identifier is the identifier.
 *
 * So every suppression key is HMAC-SHA256 under a secret that lives outside the
 * database and outside the document root. Without it the digest is not
 * searchable; with it, matching still works because the same input under the
 * same pepper gives the same key.
 *
 * WHY VERSIONED
 *
 * A single unversioned pepper can never be rotated. The moment you replace it,
 * every existing tombstone stops matching, and businesses that were rejected
 * months ago quietly start reappearing in every search — a failure that looks
 * like a search bug, not a key change.
 *
 * So the keyring holds several versions at once, every tombstone records the
 * version that made it, and matching tries the version the row names. Rotation
 * adds a version and leaves the old one in place; an old version is removed only
 * after its tombstones have been re-keyed and verified.
 *
 * PURITY
 *
 * This class does no I/O. It never opens the keyring file — the caller loads it
 * and passes the array in. That keeps the file path, permissions and failure
 * handling in one place in the module bootstrap, and keeps the cryptographic
 * decisions testable without a filesystem.
 *
 * It also never returns, echoes, logs or interpolates a pepper value. The
 * failure modes return reasons, not secrets.
 */
class Leadfinder_keyring
{
    /* ---- identifier domains -------------------------------------------- */

    /**
     * Domain-separated HMAC input formats.
     *
     * Each identifier kind has its own prefix so the same string cannot produce
     * the same key in two columns: a phone number and an email local-part that
     * happen to match would otherwise collide, and a suppression on one would
     * silently suppress the other.
     */
    const D_PHONE  = 'phone';
    const D_EMAIL  = 'email';
    const D_PLACE  = 'place';
    const D_SOURCE = 'source';

    const ALGO       = 'sha256';
    const KEY_LENGTH = 64;

    /** Below this a pepper is treated as absent rather than weak. */
    const MIN_PEPPER_BYTES = 32;

    /** A version label is a short, boring, matchable token. */
    const VERSION_PATTERN = '/^v[0-9]{1,6}$/';

    /* ---- keyring shape -------------------------------------------------- */

    /**
     * @return array the domains this class will derive keys for
     */
    public static function domains()
    {
        return array(self::D_PHONE, self::D_EMAIL, self::D_PLACE, self::D_SOURCE);
    }

    /**
     * Is this keyring structurally usable?
     *
     * Returns a verdict with a reason rather than a bare bool, because every
     * refusal here has to be reportable to an administrator — a silently
     * unusable keyring means suppression stops working and nobody is told.
     *
     * @param  mixed $keyring
     * @return array {ok: bool, reason: string|null, active: string|null, versions: array}
     */
    public static function inspect($keyring)
    {
        if (!is_array($keyring)) {
            return self::bad('keyring_missing');
        }

        if (empty($keyring['active']) || !is_string($keyring['active'])) {
            return self::bad('no_active_version');
        }

        if (!preg_match(self::VERSION_PATTERN, $keyring['active'])) {
            return self::bad('active_version_malformed');
        }

        if (empty($keyring['keys']) || !is_array($keyring['keys'])) {
            return self::bad('no_keys');
        }

        $good = array();

        foreach ($keyring['keys'] as $version => $pepper) {
            if (!is_string($version) || !preg_match(self::VERSION_PATTERN, $version)) {
                return self::bad('version_label_malformed');
            }
            if (!self::pepperUsable($pepper)) {
                return self::bad('pepper_unusable_for_' . $version);
            }
            $good[] = $version;
        }

        if (!in_array($keyring['active'], $good, true)) {
            return self::bad('active_version_not_in_keys');
        }

        return array('ok' => true, 'reason' => null,
                     'active' => $keyring['active'], 'versions' => $good);
    }

    /**
     * @param  mixed $keyring
     * @return bool
     */
    public static function usable($keyring)
    {
        $i = self::inspect($keyring);

        return $i['ok'];
    }

    /**
     * @param  mixed $keyring
     * @return string|null
     */
    public static function activeVersion($keyring)
    {
        $i = self::inspect($keyring);

        return $i['ok'] ? $i['active'] : null;
    }

    /**
     * Versions available for matching, newest-declared first.
     *
     * @param  mixed $keyring
     * @return array
     */
    public static function versions($keyring)
    {
        $i = self::inspect($keyring);

        return $i['ok'] ? $i['versions'] : array();
    }

    /**
     * Text that marks a value as a template somebody forgot to replace.
     *
     * This list exists because the shipped example file very nearly defeated
     * this class. Its placeholder — a 44-character string with 22 distinct
     * characters — passed the length and entropy tests and would have been
     * accepted as a real pepper. An operator copying the example into place
     * would then have had a system that looked correct and derived every
     * suppression key under a value published in the repository.
     *
     * @return array
     */
    public static function placeholderMarkers()
    {
        return array('REPLACE', 'EXAMPLE', 'CHANGEME', 'CHANGE-ME', 'PLACEHOLDER',
                     'SAMPLE', 'TODO', 'YOUR-', 'YOUR_', 'INSERT', 'XXXX');
    }

    /**
     * Is a pepper value good enough to depend on?
     *
     * Three gates, and the order matters: a placeholder is rejected by name
     * before anything else, because the failure it causes is silent.
     *
     * @param  mixed $pepper
     * @return bool
     */
    public static function pepperUsable($pepper)
    {
        if (!is_string($pepper) || strlen($pepper) < self::MIN_PEPPER_BYTES) {
            return false;
        }

        $upper = strtoupper($pepper);

        foreach (self::placeholderMarkers() as $marker) {
            if (strpos($upper, $marker) !== false) {
                return false;
            }
        }

        /*
         * The generator writes base64 of raw CSPRNG bytes, so a real pepper
         * always decodes. A hand-typed passphrase will not, and that is the
         * intended outcome: this value is not meant to be typed by a person.
         */
        $decoded = base64_decode($pepper, true);

        if ($decoded === false || strlen($decoded) < self::MIN_PEPPER_BYTES) {
            return false;
        }

        // Length without entropy is a placeholder by another name.
        if (count(array_unique(str_split($pepper))) < 8) {
            return false;
        }

        /*
         * Base64 of random bytes almost always spans several character classes.
         * A memorable passphrase does not — and PHP's strict base64 decode
         * tolerates whitespace, so "correct horse battery staple" decodes
         * cleanly and clears every gate above it. Requiring three of four
         * classes catches the typed-phrase case without pretending to measure
         * entropy. A genuine generated pepper failing this is vanishingly
         * unlikely, and if one did the system would refuse rather than proceed
         * on a weak secret, which is the right way round.
         */
        $classes = (preg_match('/[a-z]/', $pepper) ? 1 : 0)
                 + (preg_match('/[A-Z]/', $pepper) ? 1 : 0)
                 + (preg_match('/[0-9]/', $pepper) ? 1 : 0)
                 + (preg_match('~[+/=]~', $pepper) ? 1 : 0);

        if ($classes < 3) {
            return false;
        }

        return true;
    }

    /* ---- HMAC input construction ---------------------------------------- */

    /**
     * Build the domain-separated message that gets HMAC'd.
     *
     * `source` carries two parts, which introduces an ambiguity worth closing:
     * source type "google" with reference "abc:def" and source type
     * "google:abc" with reference "def" would otherwise produce the identical
     * message and therefore the identical key. Source types are a controlled
     * vocabulary, so a colon in one is rejected outright rather than escaped —
     * a rejected write is visible, a silent collision is not.
     *
     * @param  string $domain
     * @param  string $value
     * @param  string $sourceType required for D_SOURCE, ignored otherwise
     * @return string|null
     */
    public static function message($domain, $value, $sourceType = '')
    {
        if (!in_array($domain, self::domains(), true)) {
            return null;
        }

        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return null;
        }

        if ($domain === self::D_SOURCE) {
            $sourceType = is_string($sourceType) ? trim($sourceType) : '';

            if ($sourceType === '' || strpos($sourceType, ':') !== false) {
                return null;
            }

            return self::D_SOURCE . ':' . $sourceType . ':' . $value;
        }

        return $domain . ':' . $value;
    }

    /* ---- derivation ----------------------------------------------------- */

    /**
     * Derive a suppression key under a named version.
     *
     * Returns null — never a fallback digest — when the keyring is unusable or
     * the version is unknown. A plain hash here would be indistinguishable to
     * the caller and would protect nothing, so the only safe answer is no answer.
     *
     * @param  mixed  $keyring
     * @param  string $version
     * @param  string $domain
     * @param  string $value
     * @param  string $sourceType
     * @return string|null
     */
    public static function deriveWithVersion($keyring, $version, $domain, $value, $sourceType = '')
    {
        $i = self::inspect($keyring);

        if (!$i['ok']) {
            return null;
        }

        if (!is_string($version) || !in_array($version, $i['versions'], true)) {
            return null;
        }

        $msg = self::message($domain, $value, $sourceType);

        if ($msg === null) {
            return null;
        }

        return hash_hmac(self::ALGO, $msg, $keyring['keys'][$version]);
    }

    /**
     * Derive under the active version, and say which version was used.
     *
     * @param  mixed  $keyring
     * @param  string $domain
     * @param  string $value
     * @param  string $sourceType
     * @return array {key: string|null, version: string|null, error: string|null}
     */
    public static function derive($keyring, $domain, $value, $sourceType = '')
    {
        $i = self::inspect($keyring);

        if (!$i['ok']) {
            return array('key' => null, 'version' => null, 'error' => $i['reason']);
        }

        $key = self::deriveWithVersion($keyring, $i['active'], $domain, $value, $sourceType);

        if ($key === null) {
            return array('key' => null, 'version' => null, 'error' => 'value_or_domain_rejected');
        }

        return array('key' => $key, 'version' => $i['active'], 'error' => null);
    }

    /**
     * Derive the same identifier under every retained version.
     *
     * Used when checking an incoming result against tombstones written before a
     * rotation: the stored row names its own version, so the candidate must be
     * available under that version too.
     *
     * @param  mixed  $keyring
     * @param  string $domain
     * @param  string $value
     * @param  string $sourceType
     * @return array version => key
     */
    public static function deriveAllVersions($keyring, $domain, $value, $sourceType = '')
    {
        $out = array();

        foreach (self::versions($keyring) as $v) {
            $k = self::deriveWithVersion($keyring, $v, $domain, $value, $sourceType);
            if ($k !== null) {
                $out[$v] = $k;
            }
        }

        return $out;
    }

    /**
     * Constant-time comparison.
     *
     * @param  mixed $a
     * @param  mixed $b
     * @return bool
     */
    public static function keysMatch($a, $b)
    {
        if (!is_string($a) || !is_string($b) || $a === '' || $b === '') {
            return false;
        }

        if (strlen($a) !== strlen($b)) {
            return false;
        }

        return function_exists('hash_equals') ? hash_equals($a, $b) : ($a === $b);
    }

    /* ---- failure handling ----------------------------------------------- */

    /**
     * Operations that must refuse when the keyring is unusable.
     *
     * Both are listed deliberately. Refusing to CREATE a tombstone is obvious.
     * Refusing to PURGE is the one that gets forgotten: purging PII while unable
     * to write the tombstone that replaces it destroys the data and keeps none
     * of the suppression, which is the worst of both outcomes.
     *
     * @return array
     */
    public static function blockedWhenUnusable()
    {
        return array('create_tombstone', 'purge_pii', 'rekey');
    }

    /**
     * A redacted, reportable description of why the keyring cannot be used.
     * Carries the reason code and never any part of a pepper.
     *
     * @param  mixed $keyring
     * @return array|null null when the keyring is fine
     */
    public static function failure($keyring)
    {
        $i = self::inspect($keyring);

        if ($i['ok']) {
            return null;
        }

        return array(
            'alert'   => 'leadfinder_keyring_unusable',
            'reason'  => $i['reason'],
            'blocked' => self::blockedWhenUnusable(),
            'message' => 'Lead Finder suppression keyring is unusable (' . $i['reason'] . '). '
                       . 'Tombstone creation and PII purge are refused. No plain-digest fallback is '
                       . 'performed, because a plain digest of a phone number is reversible by search '
                       . 'and would not protect the data it replaced.',
        );
    }

    /* ---- rotation -------------------------------------------------------- */

    /**
     * Is a proposed rotation safe?
     *
     * Rotation adds a version and promotes it. It must not remove the version
     * that existing tombstones were written under, or those tombstones stop
     * matching and their businesses come back.
     *
     * @param  mixed  $current
     * @param  mixed  $proposed
     * @return array {ok: bool, reason: string|null}
     */
    public static function rotationSafe($current, $proposed)
    {
        $c = self::inspect($current);
        $p = self::inspect($proposed);

        if (!$p['ok']) {
            return array('ok' => false, 'reason' => 'proposed_keyring_unusable:' . $p['reason']);
        }

        if (!$c['ok']) {
            // No usable current keyring: this is an initial install, not a rotation.
            return array('ok' => true, 'reason' => 'initial_keyring');
        }

        foreach ($c['versions'] as $v) {
            if (!in_array($v, $p['versions'], true)) {
                return array('ok' => false, 'reason' => 'would_drop_retained_version:' . $v);
            }
        }

        if ($p['active'] === $c['active']) {
            return array('ok' => false, 'reason' => 'active_version_unchanged');
        }

        return array('ok' => true, 'reason' => 'adds_version_and_retains_previous');
    }

    /**
     * May a version be retired?
     *
     * Only once nothing depends on it. The caller supplies the count of
     * tombstones still carrying that version; a non-zero count refuses.
     *
     * @param  mixed  $keyring
     * @param  string $version
     * @param  int    $dependentTombstones
     * @return array {ok: bool, reason: string}
     */
    public static function mayRetire($keyring, $version, $dependentTombstones)
    {
        $i = self::inspect($keyring);

        if (!$i['ok']) {
            return array('ok' => false, 'reason' => 'keyring_unusable');
        }

        if ($version === $i['active']) {
            return array('ok' => false, 'reason' => 'cannot_retire_active_version');
        }

        if (!in_array($version, $i['versions'], true)) {
            return array('ok' => false, 'reason' => 'unknown_version');
        }

        if (!is_numeric($dependentTombstones)) {
            return array('ok' => false, 'reason' => 'dependent_count_unknown');
        }

        if ((int) $dependentTombstones > 0) {
            return array('ok' => false, 'reason' => 'tombstones_still_reference_version');
        }

        return array('ok' => true, 'reason' => 'no_dependents');
    }

    /* ---- re-keying -------------------------------------------------------- */

    const RK_NONE       = 'none';
    const RK_PENDING    = 'pending';
    const RK_DONE       = 're_keyed';
    const RK_IMPOSSIBLE = 'impossible_source_purged';

    /**
     * @return array
     */
    public static function rekeyStates()
    {
        return array(self::RK_NONE, self::RK_PENDING, self::RK_DONE, self::RK_IMPOSSIBLE);
    }

    /**
     * Can this tombstone be re-keyed to a new version?
     *
     * Only if the original identifier is still available. Once the PII is
     * purged the plaintext is gone, so the row can never be re-keyed — it can
     * only be matched under its own version, which is exactly why versions are
     * retained rather than replaced.
     *
     * @param  array $tombstone
     * @return array {ok: bool, reason: string}
     */
    public static function mayRekey(array $tombstone)
    {
        $purged = isset($tombstone['purge_state']) && $tombstone['purge_state'] === 'purged';

        if ($purged) {
            return array('ok' => false, 'reason' => self::RK_IMPOSSIBLE);
        }

        if (empty($tombstone['pepper_version'])) {
            return array('ok' => false, 'reason' => 'no_pepper_version_recorded');
        }

        return array('ok' => true, 'reason' => 'source_still_available');
    }

    private static function bad($reason)
    {
        return array('ok' => false, 'reason' => $reason, 'active' => null, 'versions' => array());
    }
}
