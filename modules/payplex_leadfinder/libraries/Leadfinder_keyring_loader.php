<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Leadfinder_keyring.php';

/**
 * Leadfinder_keyring_loader
 *
 * The one place that opens the keyring file.
 *
 * Leadfinder_keyring is pure and never touches a filesystem; this class is its
 * I/O boundary, kept small and separate so there is exactly one path from disk
 * to pepper and it can be read in a minute.
 *
 * WHAT THIS CLASS WILL AND WILL NOT RETURN
 *
 * load() returns the keyring array for the code that must derive keys with it.
 * health() returns a redacted report — loaded, active version, key length,
 * fingerprint — and nothing else. Anything user-facing, anything logged, and
 * anything that reaches an administrator's screen uses health(). A pepper value
 * is never returned by health(), never logged, never placed in an exception
 * message, and never rendered.
 *
 * The fingerprint is a truncated SHA-256 of the pepper. It is enough to say
 * which keyring is installed and to compare an operational backup against the
 * live copy; it is not enough to be the pepper, because the pepper is 48 bytes
 * of CSPRNG output rather than a low-entropy identifier.
 */
class Leadfinder_keyring_loader
{
    /** Length of the fingerprint we publish. */
    const FINGERPRINT_CHARS = 16;

    /** Cache, so a page that derives many keys opens the file once. */
    private static $cache = null;
    private static $loaded = false;

    /**
     * Where the keyring lives.
     *
     * A deployment may pin it with a constant. Otherwise it is looked for in a
     * sibling of the document root — outside it by construction, so the file is
     * not reachable over HTTP even if a rule elsewhere is misconfigured.
     *
     * @return string
     */
    public static function path()
    {
        if (defined('PAYPLEX_LF_KEYRING_PATH')) {
            return (string) PAYPLEX_LF_KEYRING_PATH;
        }

        $docroot = defined('FCPATH') ? rtrim((string) FCPATH, '/\\') : '';

        if ($docroot !== '') {
            return dirname(dirname($docroot)) . '/secure_config/payplex_leadfinder_peppers.php';
        }

        return '';
    }

    /**
     * Load the keyring, or null.
     *
     * Never throws and never emits. A keyring that cannot be loaded is a
     * condition the caller must handle — by refusing to write tombstones and
     * refusing to purge — not an exception that unwinds into an error page
     * quoting a file path.
     *
     * @param  bool $fresh bypass the per-request cache
     * @return array|null
     */
    public static function load($fresh = false)
    {
        if (self::$loaded && !$fresh) {
            return self::$cache;
        }

        self::$loaded = true;
        self::$cache  = null;

        $path = self::path();

        if ($path === '' || !is_string($path)) {
            return null;
        }

        if (!@is_readable($path)) {
            return null;
        }

        $data = @include $path;

        if (!is_array($data) || !Leadfinder_keyring::usable($data)) {
            // Deliberately discarded rather than returned "partially": a keyring
            // that fails inspection must not be used for anything.
            self::$cache = null;

            return null;
        }

        self::$cache = $data;

        return self::$cache;
    }

    /**
     * A redacted health report. This is the only shape that may be shown to a
     * human, written to a log, or returned from an endpoint.
     *
     * @param  bool $fresh
     * @return array {loaded, active_version, key_length, fingerprint, reason}
     */
    public static function health($fresh = false)
    {
        $ring = self::load($fresh);

        if ($ring === null) {
            $path    = self::path();
            $present = ($path !== '' && @file_exists($path));

            /*
             * "Present but unusable" and "absent" need different answers,
             * because they need different fixes — one is a permissions or
             * content problem, the other a missing install step.
             */
            $reason = !$present
                ? 'keyring_file_absent'
                : (!@is_readable($path) ? 'keyring_file_unreadable' : 'keyring_contents_unusable');

            return array(
                'loaded'         => false,
                'active_version' => null,
                'key_length'     => null,
                'fingerprint'    => null,
                'reason'         => $reason,
            );
        }

        $active = Leadfinder_keyring::activeVersion($ring);
        $pepper = $ring['keys'][$active];

        return array(
            'loaded'         => true,
            'active_version' => $active,
            'key_length'     => strlen($pepper),
            'fingerprint'    => substr(hash('sha256', $pepper), 0, self::FINGERPRINT_CHARS),
            'reason'         => null,
        );
    }

    /**
     * Health for every retained version, for the rotation screen.
     *
     * @return array version => {key_length, fingerprint, active}
     */
    public static function versionHealth()
    {
        $ring = self::load();

        if ($ring === null) {
            return array();
        }

        $active = Leadfinder_keyring::activeVersion($ring);
        $out    = array();

        foreach (Leadfinder_keyring::versions($ring) as $v) {
            $p = $ring['keys'][$v];
            $out[$v] = array(
                'key_length'  => strlen($p),
                'fingerprint' => substr(hash('sha256', $p), 0, self::FINGERPRINT_CHARS),
                'active'      => ($v === $active),
            );
        }

        return $out;
    }

    /**
     * Is the module allowed to perform a destructive purge right now?
     *
     * Both halves are required. Purging PII while unable to write the tombstone
     * that replaces it destroys the data and keeps none of the suppression —
     * the worst of both outcomes, and the one a naive "purge on schedule" job
     * arrives at by default.
     *
     * @return array {allowed: bool, reason: string|null, alert: array|null}
     */
    public static function purgeAllowed()
    {
        $ring = self::load();

        if ($ring === null) {
            $h = self::health();

            return array(
                'allowed' => false,
                'reason'  => $h['reason'],
                'alert'   => array(
                    'kind'    => 'leadfinder_keyring_unusable',
                    'reason'  => $h['reason'],
                    'message' => 'Lead Finder cannot read its suppression keyring (' . $h['reason'] . '). '
                               . 'Tombstone creation and the PII purge are both refused until it is restored. '
                               . 'No plain-digest fallback is used: a plain digest of a phone number is '
                               . 'reversible by exhaustive search and would not protect the data it replaced.',
                ),
            );
        }

        return array('allowed' => true, 'reason' => null, 'alert' => null);
    }

    /**
     * Reset the per-request cache. Tests only.
     *
     * @return void
     */
    public static function resetCache()
    {
        self::$cache  = null;
        self::$loaded = false;
    }
}
