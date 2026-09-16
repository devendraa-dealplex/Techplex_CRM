<?php
defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Compliance documents — the artifact the lifecycle already names.
 *
 * WHAT WAS MEASURED ON STAGING, 2026-09-11
 * ----------------------------------------
 * Payplex_staff_lifecycle defines the state `documentation_pending` and the
 * transition `submit_docs`. The profile carries `kyc_status`, `pan_status` and
 * `bank_verified`, all reading "pending" on all eighteen staff.
 *
 * There is no document store. Not an empty one — **none**. No table, no upload,
 * no file, no expiry, no verification, no access log. A staff member can pass
 * through `documentation_pending`, be `submitted`, be `verified` and reach
 * `approved` without a single document existing anywhere in the system.
 *
 * That is the same shape as the attendance flag in Module 5 and the five before
 * it: a state, a status field and a screen naming something the system does not
 * have. This library supplies the rules; the module supplies the storage.
 *
 * WHERE THE FILES GO, AND WHY IT MATTERS
 * --------------------------------------
 * These are identity documents — Aadhaar, PAN, bank proof, signed contracts.
 * Perfex's own uploads directory sits inside the web root, where a file is one
 * guessed URL away from anybody. So the module writes them OUTSIDE the document
 * root entirely and serves them only through a permission-checked action that
 * writes an access log first. Unguessable filenames are a second lock, not the
 * only one: "nobody will guess the path" is not an access control.
 */
class Workforce_documents
{
    /** Hard ceiling per file. Generous for a scan, small enough to refuse a payload. */
    const MAX_BYTES = 10485760; // 10 MB
    /** Documents inside this many days of expiry are flagged rather than left to surprise somebody. */
    const EXPIRY_WARN_DAYS = 30;

    /**
     * The document types the brief names, with whether an expiry date is
     * meaningful for each. An Aadhaar card does not expire; a contract does, and
     * recording "no expiry" on one that has one is how a lapsed agreement stays
     * invisible.
     */
    public static function types()
    {
        return array(
            'identity_proof'   => array('label' => 'Aadhaar / authorised identity proof', 'expires' => false, 'sensitive' => true),
            'pan'              => array('label' => 'PAN',                                  'expires' => false, 'sensitive' => true),
            'address_proof'    => array('label' => 'Address proof',                        'expires' => true,  'sensitive' => true),
            'resume'           => array('label' => 'Resume',                               'expires' => false, 'sensitive' => false),
            'offer_letter'     => array('label' => 'Offer / appointment letter',           'expires' => false, 'sensitive' => false),
            'agreement'        => array('label' => 'Freelancer / consultant agreement',    'expires' => true,  'sensitive' => false),
            'nda'              => array('label' => 'NDA',                                  'expires' => true,  'sensitive' => false),
            'contract'         => array('label' => 'Signed contract',                      'expires' => true,  'sensitive' => false),
            'bank_proof'       => array('label' => 'Bank proof',                           'expires' => false, 'sensitive' => true),
            'experience'       => array('label' => 'Experience documents',                 'expires' => false, 'sensitive' => false),
        );
    }

    public static function isType($slug)
    {
        return array_key_exists((string) $slug, self::types());
    }

    public static function label($slug)
    {
        $t = self::types();
        return isset($t[$slug]) ? $t[$slug]['label'] : (string) $slug;
    }

    public static function isSensitive($slug)
    {
        $t = self::types();
        return isset($t[$slug]) ? (bool) $t[$slug]['sensitive'] : true; // unknown is treated as sensitive
    }

    /**
     * Which documents each engagement type must hold.
     *
     * A freelancer needs an agreement and an NDA and does not need an offer
     * letter; an employee is the other way round. Demanding the same paperwork
     * of everyone is how a compliance screen becomes noise people learn to
     * ignore.
     */
    public static function requiredFor($employmentType)
    {
        $common = array('identity_proof', 'pan', 'bank_proof');
        $map = array(
            'fixed_salary'      => array_merge($common, array('offer_letter', 'address_proof')),
            'salary_commission' => array_merge($common, array('offer_letter', 'address_proof')),
            'commission_only'   => array_merge($common, array('agreement')),
            'field_sales'       => array_merge($common, array('offer_letter', 'address_proof')),
            'telecaller'        => array_merge($common, array('offer_letter')),
            'freelancer'        => array_merge($common, array('agreement', 'nda')),
            'channel_partner'   => array_merge($common, array('agreement', 'nda')),
            'intern'            => array_merge($common, array('offer_letter')),
            'manager'           => array_merge($common, array('offer_letter', 'address_proof')),
            'finance_staff'     => array_merge($common, array('offer_letter', 'address_proof', 'nda')),
            'auditor'           => array_merge($common, array('offer_letter', 'nda')),
        );
        $t = (string) $employmentType;
        if (isset($map[$t])) { return $map[$t]; }

        /*
         * An unclassified person is not held to nothing. The common set applies,
         * and the caller is told the list is provisional — the alternative is a
         * compliance screen that reports somebody as complete because nobody has
         * decided what they need.
         */
        return $common;
    }

    public static function requirementIsProvisional($employmentType)
    {
        $map = self::requiredFor('fixed_salary'); // force the map to build
        unset($map);
        return !in_array((string) $employmentType, array('fixed_salary', 'salary_commission',
            'commission_only', 'field_sales', 'telecaller', 'freelancer', 'channel_partner',
            'intern', 'manager', 'finance_staff', 'auditor'), true);
    }

    /* ==================================================================== *
     * Upload validation
     * ==================================================================== */

    /**
     * @param array $file name, size, mime (as reported), tmp_ok
     * @return array ok, code, reason, extension
     */
    public static function validateUpload($file)
    {
        $name = isset($file['name']) ? (string) $file['name'] : '';
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        $mime = isset($file['mime']) ? strtolower(trim((string) $file['mime'])) : '';

        if ($name === '') {
            return array('ok' => false, 'code' => 'no_file', 'reason' => 'No file was supplied.');
        }
        if ($size <= 0) {
            return array('ok' => false, 'code' => 'empty_file', 'reason' => 'The file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            return array('ok' => false, 'code' => 'too_large',
                'reason' => 'The file is ' . round($size / 1048576, 1) . ' MB. The limit is '
                          . (self::MAX_BYTES / 1048576) . ' MB.');
        }

        /*
         * An allowlist, not a blocklist. A blocklist is a list of the attacks
         * somebody thought of.
         */
        $allowed = array(
            'pdf'  => array('application/pdf'),
            'jpg'  => array('image/jpeg'),
            'jpeg' => array('image/jpeg'),
            'png'  => array('image/png'),
        );

        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !isset($allowed[$ext])) {
            return array('ok' => false, 'code' => 'extension_not_allowed',
                'reason' => 'Only PDF, JPG and PNG files are accepted. This one is "'
                          . ($ext === '' ? 'no extension' : $ext) . '".');
        }

        /*
         * A double extension is how "cv.pdf.php" gets past a check that reads
         * only the last one on a misconfigured server. The whole name is checked.
         */
        if (preg_match('/\.(php\d?|phtml|phar|cgi|pl|py|sh|exe|js|html?|svg)\b/i', $name)) {
            return array('ok' => false, 'code' => 'dangerous_name',
                'reason' => 'The file name contains an executable extension.');
        }

        if ($mime !== '' && !in_array($mime, $allowed[$ext], true)) {
            return array('ok' => false, 'code' => 'mime_mismatch',
                'reason' => 'The file claims to be ' . $mime . ' but is named .' . $ext
                          . '. The two must agree.');
        }

        return array('ok' => true, 'code' => 'ok', 'reason' => '', 'extension' => $ext);
    }

    /**
     * A stored filename that reveals nothing and collides with nothing.
     * The original name is kept in the database, not on disk.
     */
    public static function storedName($extension, $randomHex)
    {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower((string) $extension));
        $hex = preg_replace('/[^a-f0-9]/', '', strtolower((string) $randomHex));
        return substr($hex, 0, 40) . ($ext !== '' ? '.' . $ext : '');
    }

    /* ==================================================================== *
     * Expiry and verification
     * ==================================================================== */

    /**
     * @return array state, days, message
     * States: no_expiry · valid · expiring_soon · expired · not_applicable
     */
    public static function expiryState($slug, $expiresOn, $today = null)
    {
        $today = $today ?: date('Y-m-d');
        $types = self::types();
        $canExpire = isset($types[$slug]) ? (bool) $types[$slug]['expires'] : true;

        $e = trim((string) $expiresOn);
        if ($e === '' || $e === '0000-00-00') {
            if (!$canExpire) {
                return array('state' => 'not_applicable', 'days' => null,
                             'message' => 'This document type does not expire.');
            }
            return array('state' => 'no_expiry', 'days' => null,
                'message' => 'No expiry date recorded, and this document type has one. '
                           . 'A lapsed agreement with no date on file cannot be noticed.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) {
            return array('state' => 'no_expiry', 'days' => null,
                         'message' => 'The expiry date on file is not a date.');
        }

        $days = (int) floor((strtotime($e) - strtotime($today)) / 86400);
        if ($days < 0) {
            return array('state' => 'expired', 'days' => $days,
                'message' => 'Expired ' . abs($days) . ' day(s) ago, on ' . $e . '.');
        }
        if ($days <= self::EXPIRY_WARN_DAYS) {
            return array('state' => 'expiring_soon', 'days' => $days,
                'message' => 'Expires in ' . $days . ' day(s), on ' . $e . '.');
        }
        return array('state' => 'valid', 'days' => $days, 'message' => 'Valid until ' . $e . '.');
    }

    /**
     * Who may verify a document.
     *
     * The same rule as activity verification and commission approval: the
     * verifier needs the capability and may not be the person the document
     * belongs to. Somebody certifying their own identity proof is not
     * verification, it is filing.
     *
     * @return array allowed, code, reason
     */
    public static function canVerify($ownerStaffId, $actorId, $hasCapability)
    {
        $owner = (int) $ownerStaffId;
        $actor = (int) $actorId;

        if ($actor <= 0) {
            return array('allowed' => false, 'code' => 'unknown_actor',
                         'reason' => 'An acting user must be identified.');
        }
        if (!$hasCapability) {
            return array('allowed' => false, 'code' => 'not_permitted',
                         'reason' => 'Verifying a document needs the document verification capability.');
        }
        if ($owner === $actor) {
            return array('allowed' => false, 'code' => 'self_verification',
                'reason' => 'You cannot verify your own document. Somebody certifying their own '
                          . 'identity proof is filing it, not verifying it.');
        }
        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    /**
     * How complete is somebody's file?
     *
     * @param array $required  document type slugs
     * @param array $held      slug => status (pending|verified|rejected)
     * @return array complete, missing, unverified, rejected, percent
     */
    public static function completeness(array $required, array $held)
    {
        $missing = array(); $unverified = array(); $rejected = array(); $verified = array();
        foreach ($required as $slug) {
            if (!array_key_exists($slug, $held)) { $missing[] = $slug; continue; }
            $st = strtolower((string) $held[$slug]);
            if ($st === 'verified')      { $verified[] = $slug; }
            elseif ($st === 'rejected')  { $rejected[] = $slug; }
            else                         { $unverified[] = $slug; }
        }
        $total = count($required);
        return array(
            'complete'   => (count($verified) === $total && $total > 0),
            'missing'    => $missing,
            'unverified' => $unverified,
            'rejected'   => $rejected,
            'verified'   => $verified,
            /*
             * Only VERIFIED documents count toward the percentage. An uploaded
             * but unchecked document is a claim, and counting claims as
             * compliance is how a file looks complete and is not.
             */
            'percent'    => $total > 0 ? (int) round(count($verified) * 100 / $total) : 0,
        );
    }

    /* ==================================================================== *
     * Where the files live
     * ==================================================================== */

    /**
     * Resolve a storage directory that is OUTSIDE every document root.
     *
     * The obvious answer, dirname(FCPATH), is wrong on this host and on most
     * cPanel accounts: the CRM sits at
     *   /home/<user>/public_html/staging.support.techplex.in/
     * so dirname(FCPATH) is /home/<user>/public_html — the MAIN domain's
     * document root. Files placed there are served over the web; the directory
     * is one level up from the CRM but not one level out of the web.
     *
     * So the rule is not "go up one" but "go above the outermost web root":
     * walk the path, find the LAST segment that names a document root, and stop
     * one above it. Nothing is hard-coded — the answer is computed from the path
     * the application is actually running from, which is why this function is
     * pure and has tests rather than living inline in a model.
     *
     * @param string $fcpath  the application's FCPATH
     * @return string absolute directory (no trailing slash)
     */
    public static function resolveStorageRoot($fcpath)
    {
        $dir = rtrim(str_replace('\\', '/', (string) $fcpath), '/');
        if ($dir === '') { return ''; }

        $webroots = array('public_html', 'www', 'htdocs', 'httpdocs', 'wwwroot', 'web', 'html');
        $parts    = explode('/', $dir);

        $cut = null;
        foreach ($parts as $i => $seg) {
            if ($i > 0 && in_array(strtolower($seg), $webroots, true)) { $cut = $i; }
        }

        $base = $cut !== null
            ? implode('/', array_slice($parts, 0, $cut))
            : dirname($dir);

        /* Never hand back the filesystem root or an empty string: a storage
           directory at / is a different accident, not a fix. */
        if ($base === '' || $base === '/' || $base === '.') { $base = rtrim(dirname($dir), '/'); }
        if ($base === '' || $base === '/') { return ''; }

        return $base . '/payplex_workforce_documents';
    }

    /**
     * Is a resolved storage directory actually out of reach of the web?
     *
     * Called by the module on every documents screen so the answer is visible
     * rather than assumed. A path inside FCPATH is reported as unsafe even if it
     * exists and is writable — writable and private are different questions.
     *
     * @return array safe, code, message
     */
    public static function storageIsSafe($storageRoot, $fcpath)
    {
        $s = rtrim(str_replace('\\', '/', (string) $storageRoot), '/');
        $f = rtrim(str_replace('\\', '/', (string) $fcpath), '/');

        if ($s === '') {
            return array('safe' => false, 'code' => 'unresolved',
                         'message' => 'No storage directory could be resolved.');
        }
        if ($f !== '' && strpos($s . '/', $f . '/') === 0) {
            return array('safe' => false, 'code' => 'inside_application',
                'message' => 'The storage directory is inside the application directory, '
                           . 'so every file in it is one guessed URL away from anybody.');
        }
        $webroots = array('public_html', 'www', 'htdocs', 'httpdocs', 'wwwroot');
        foreach (explode('/', $s) as $seg) {
            if (in_array(strtolower($seg), $webroots, true)) {
                return array('safe' => false, 'code' => 'inside_document_root',
                    'message' => 'The storage directory is under "' . $seg . '", which is served '
                               . 'over the web. Identity documents must not be.');
            }
        }
        return array('safe' => true, 'code' => 'ok', 'message' => '');
    }
}
