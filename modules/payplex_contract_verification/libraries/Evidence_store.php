<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Evidence_store
 *
 * Where evidence files live, what they are called, and the rules about both.
 *
 * THE THREE RULES
 * ---------------
 * 1. Outside the document root. Always. A file under `public_html` is one
 *    misconfigured directory, one stray `.htaccess` edit, or one server
 *    migration away from being public, and nothing about the application would
 *    notice. `pathIsSafe()` refuses such a path outright rather than trusting
 *    an `.htaccess` to hold.
 *
 * 2. The name carries no information. Not the client's name, not a mobile
 *    number, not a PAN, not the contract id, not a sequential row id. A
 *    directory listing that leaks "ACME_Industries_9876543210_PAN.jpg" has
 *    disclosed the customer, their phone number and the document type without
 *    anybody opening anything. Keys here are random, and random means
 *    `random_bytes` — never `mt_rand`, never `uniqid`, never a hash of the
 *    identifiers, because a hash of a mobile number is a lookup table away from
 *    being the mobile number.
 *
 * 3. Nothing reaches a browser except through a controller that has asked
 *    Contract_authz first. There is no signed URL, no expiring public link, no
 *    "temporary" copy in a web-readable folder. The storage path is never sent
 *    to a client in any form, including in an error message.
 *
 * WHY A KEY AND NOT A PATH IN THE DATABASE
 * ----------------------------------------
 * The row stores a key. The path is derived from the key and the configured
 * root at read time. Move the storage root and nothing in the database is
 * stale; leak a row and you have a name for a file you still cannot locate or
 * reach.
 */
class Evidence_store
{
    /** Bytes of entropy in a storage key. 32 bytes = 256 bits. */
    const KEY_BYTES = 32;

    /** Hex, lowercase, exactly 64 characters. */
    const KEY_PATTERN = '/^[a-f0-9]{64}$/';

    /** Two levels of fan-out so no directory grows without limit. */
    const FANOUT_DEPTH = 2;
    const FANOUT_WIDTH = 2;

    /**
     * A fresh, non-guessable storage key.
     *
     * `random_bytes` throws rather than returning weak output when no good
     * source is available, and that is the behaviour wanted here: failing to
     * store evidence is recoverable, storing it under a predictable name is
     * not. There is deliberately no fallback path.
     *
     * @return string 64 hex characters
     * @throws Exception when no cryptographic source is available
     */
    public static function newKey()
    {
        return bin2hex(random_bytes(self::KEY_BYTES));
    }

    /**
     * @param  string $key
     * @return bool
     */
    public static function validKey($key)
    {
        return is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * The relative path for a key: aa/bb/aabb…
     *
     * Derived from the key alone. Nothing about the client, the contract or the
     * evidence type appears in it, so the path discloses nothing even if it is
     * read off a backup tape.
     *
     * @param  string $key
     * @return string|null
     */
    public static function relativePath($key)
    {
        if (!self::validKey($key)) { return null; }

        $parts = array();

        for ($i = 0; $i < self::FANOUT_DEPTH; $i++) {
            $parts[] = substr($key, $i * self::FANOUT_WIDTH, self::FANOUT_WIDTH);
        }

        $parts[] = $key;

        return implode('/', $parts);
    }

    /**
     * Is this storage root acceptable?
     *
     * Refuses anything that looks like it is inside a served directory, and
     * refuses relative paths and traversal. The check is on the NORMALISED
     * string: `/home/x/public_html/../evidence` resolves outside the root but
     * reads as inside it, and accepting it on appearance while rejecting it on
     * resolution — or the reverse — is how this kind of guard gets bypassed.
     *
     * @param  string $root
     * @param  array  $documentRoots paths known to be web-served
     * @return array {safe, reason}
     */
    public static function pathIsSafe($root, array $documentRoots = array())
    {
        $root = (string) $root;

        if ($root === '') {
            return self::no('storage_root_not_configured');
        }

        if (substr($root, 0, 1) !== '/') {
            return self::no('storage_root_must_be_absolute');
        }

        if (strpos($root, "\0") !== false) {
            return self::no('storage_root_contains_null_byte');
        }

        /* Normalise . and .. without touching the filesystem, so the rule is
           testable and gives the same answer on a machine where the directory
           does not exist. */
        $out = array();

        foreach (explode('/', $root) as $seg) {
            if ($seg === '' || $seg === '.') { continue; }
            if ($seg === '..') { array_pop($out); continue; }
            $out[] = $seg;
        }

        $normal = '/' . implode('/', $out);

        foreach (self::webServedMarkers() as $marker) {
            if (strpos($normal . '/', '/' . $marker . '/') !== false) {
                return self::no('storage_root_is_inside_a_web_served_directory');
            }
        }

        foreach ($documentRoots as $docroot) {
            $docroot = rtrim((string) $docroot, '/');

            if ($docroot === '') { continue; }

            if ($normal === $docroot || strpos($normal . '/', $docroot . '/') === 0) {
                return self::no('storage_root_is_inside_a_document_root');
            }
        }

        return array('safe' => true, 'reason' => 'outside_web_root', 'normalised' => $normal);
    }

    /**
     * Directory names that mean "this is served to the internet" on the
     * hosting this module runs on.
     *
     * @return array
     */
    public static function webServedMarkers()
    {
        return array('public_html', 'public', 'www', 'htdocs', 'web', 'wwwroot');
    }

    /**
     * Does this proposed filename leak anything?
     *
     * Used on keys and on any name about to be written. The rule is stricter
     * than "no personal data": the name must be the key and nothing else, so
     * there is no judgement call about whether a particular fragment is
     * identifying.
     *
     * @param  string $name
     * @return array {safe, reason}
     */
    public static function filenameIsSafe($name)
    {
        $name = (string) $name;

        if (!self::validKey($name)) {
            return self::no('filename_must_be_a_storage_key_and_nothing_else');
        }

        return array('safe' => true, 'reason' => 'opaque');
    }

    /**
     * The rules, as data, so the settings screen and the suite read the same
     * statement rather than two descriptions of it.
     *
     * @return array
     */
    public static function rules()
    {
        return array(
            'outside_document_root'       => true,
            'directory_listing'           => false,
            'served_through'              => 'authorized_controller_action',
            'key_entropy_bits'            => self::KEY_BYTES * 8,
            'key_source'                  => 'random_bytes',
            'path_contains_identifiers'   => false,
            'permanent_public_url'        => false,
            'signed_public_url'           => false,
            'access_audited'              => true,
            'hash_verified_before_serving' => true,
        );
    }

    /**
     * What a streaming response must set.
     *
     * Kept here rather than in the controller so the suite can assert the
     * headers without rendering a response, and so a second streaming route
     * cannot quietly ship with a different set.
     *
     * @return array
     */
    public static function streamingHeaders()
    {
        return array(
            'Cache-Control'           => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'                  => 'no-cache',
            'Expires'                 => '0',
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Disposition'     => 'inline',
            'Referrer-Policy'         => 'no-referrer',
        );
    }

    /* ---- helpers ------------------------------------------------------- */

    private static function no($reason)
    {
        return array('safe' => false, 'reason' => (string) $reason);
    }
}
