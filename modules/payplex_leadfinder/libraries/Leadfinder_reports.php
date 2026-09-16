<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Leadfinder_caps.php';

/**
 * Leadfinder_reports — what may be reported, who may see it, and what must never
 * appear in it.
 *
 * WHY THE DEFINITIONS ARE DATA
 * ----------------------------
 * Nineteen reports written as nineteen methods means nineteen places to
 * remember the capability check, nineteen places to remember not to select the
 * key column, and nineteen chances for the twentieth to forget both. As a table
 * the properties can be asserted over all of them at once — and they are: every
 * report names a capability, no report names a forbidden column, and every
 * exportable report is exportable on purpose rather than by omission.
 *
 * THE COLUMN DENYLIST IS THE SAFETY PROPERTY
 * ------------------------------------------
 * `api_key_enc` is encrypted, and a report that selected it would emit
 * ciphertext rather than a key — which is not a leak, but is a thing nobody
 * should be able to download and take away to work on. `api_key_fingerprint` is
 * a truncated hash and equally has no business in a spreadsheet. Neither can
 * appear in any report definition, and a test walks every one of them.
 *
 * PHONE NUMBERS ARE THE INTERESTING CASE
 * --------------------------------------
 * They are the point of the module and they are personal data. A report of
 * "employee-wise lead quality" does not need them at all; a callback list does.
 * So each report says whether it carries contact detail, and the ones that do
 * mask it unless the viewer holds the capability that already lets them see the
 * prospect itself. A CSV is worse than a screen here: it leaves the building.
 *
 * CSV INJECTION
 * -------------
 * A business called `=cmd|' /c calc'!A1` is a valid business name and a working
 * spreadsheet formula. Every exported cell that begins with `=`, `+`, `-`, `@`,
 * a tab or a carriage return is prefixed with an apostrophe, which Excel and
 * LibreOffice both treat as "this is text". Quoting alone does not prevent it —
 * the formula executes on open, not on parse.
 *
 * No I/O. The model runs the queries; this says which ones exist and what may
 * come out of them.
 */
class Leadfinder_reports
{
    /** Columns no report may ever select, whatever it is for. */
    public static function forbiddenColumns()
    {
        return array('api_key_enc', 'api_key_fingerprint', 'old_fingerprint',
                     'new_fingerprint', 'idem_key', 'counters', 'evidence');
    }

    /**
     * Fields that carry contact detail and are masked unless the viewer may see
     * the prospect itself.
     */
    public static function contactColumns()
    {
        return array('phone_e164', 'phone_raw', 'verified_email', 'website');
    }

    /**
     * Every report, as data.
     *
     * `capability`  who may run it.
     * `contact`     true when rows carry personal contact detail.
     * `export`      whether a CSV may be taken away.
     * `group`       for the screen's headings only.
     */
    public static function definitions()
    {
        $view    = Leadfinder_caps::CAP_REPORTS;
        $admin   = Leadfinder_caps::CAP_MANAGE_PROFILES;

        return array(
            'searches_by_source_staff' => array(
                'title' => 'Searches by source and employee', 'group' => 'Activity',
                'capability' => $view, 'contact' => false, 'export' => true),

            'result_counts' => array(
                'title' => 'Result counts per search', 'group' => 'Activity',
                'capability' => $view, 'contact' => false, 'export' => true),

            'unique_prospects' => array(
                'title' => 'Unique prospects discovered', 'group' => 'Activity',
                'capability' => $view, 'contact' => false, 'export' => true),

            'duplicate_rate' => array(
                'title' => 'Duplicate rate', 'group' => 'Quality',
                'capability' => $view, 'contact' => false, 'export' => true),

            'api_usage' => array(
                'title' => 'API search and detail usage', 'group' => 'Spending',
                'capability' => $view, 'contact' => false, 'export' => true),

            'quota_consumption' => array(
                'title' => 'Quota consumption and estimated cost', 'group' => 'Spending',
                /*
                 * Spending is an administrator's business. An employee seeing
                 * the installation's total spend learns nothing that helps them
                 * make a call, and it is exactly the figure a departing employee
                 * would screenshot.
                 */
                'capability' => $admin, 'contact' => false, 'export' => true),

            'claims_and_releases' => array(
                'title' => 'Claims, releases and expiry', 'group' => 'Ownership',
                'capability' => $view, 'contact' => false, 'export' => true),

            'calls_and_callbacks' => array(
                'title' => 'Calls and callbacks due', 'group' => 'Calling',
                'capability' => $view, 'contact' => true, 'export' => true),

            'verification_outcomes' => array(
                'title' => 'Verification outcomes', 'group' => 'Calling',
                'capability' => $view, 'contact' => false, 'export' => true),

            'interest_split' => array(
                'title' => 'Interested and not interested', 'group' => 'Calling',
                'capability' => $view, 'contact' => false, 'export' => true),

            'invalid_outcomes' => array(
                'title' => 'Wrong number, rejected and do-not-contact', 'group' => 'Quality',
                'capability' => $view, 'contact' => false, 'export' => true),

            'conversion_submissions' => array(
                'title' => 'Conversion submissions', 'group' => 'Conversion',
                'capability' => $view, 'contact' => false, 'export' => true),

            'approvals_and_rejections' => array(
                'title' => 'Approvals and rejections', 'group' => 'Conversion',
                'capability' => $view, 'contact' => false, 'export' => true),

            'conversion_rate_by_source' => array(
                'title' => 'Conversion rate by source', 'group' => 'Conversion',
                'capability' => $view, 'contact' => false, 'export' => true),

            'lead_quality_by_employee' => array(
                'title' => 'Lead quality by employee', 'group' => 'Conversion',
                'capability' => $view, 'contact' => false, 'export' => true),

            'api_errors_and_retries' => array(
                'title' => 'API errors and retries', 'group' => 'Monitoring',
                'capability' => $admin, 'contact' => false, 'export' => true),

            'profile_expiry_and_rotation' => array(
                'title' => 'Profile expiry and key rotation', 'group' => 'Monitoring',
                'capability' => $admin, 'contact' => false, 'export' => true),

            'retention_runs' => array(
                'title' => 'Coordinate retention runs and failures', 'group' => 'Monitoring',
                'capability' => $admin, 'contact' => false, 'export' => true),

            'access_refusals' => array(
                'title' => 'Refused access attempts', 'group' => 'Monitoring',
                /*
                 * Not exportable, deliberately. It is a list of who tried to
                 * reach what they could not, which is exactly the material for
                 * quietly building a picture of colleagues. It can be read on
                 * screen by an administrator and not carried away.
                 */
                'capability' => $admin, 'contact' => false, 'export' => false),
        );
    }

    public static function ids()
    {
        return array_keys(self::definitions());
    }

    public static function exists($id)
    {
        return array_key_exists((string) $id, self::definitions());
    }

    public static function definition($id)
    {
        $d = self::definitions();

        return isset($d[$id]) ? $d[$id] : null;
    }

    /** The capability a report requires, or null when the report does not exist. */
    public static function capabilityFor($id)
    {
        $d = self::definition($id);

        return $d === null ? null : $d['capability'];
    }

    public static function isExportable($id)
    {
        $d = self::definition($id);

        return $d !== null && !empty($d['export']);
    }

    /**
     * Validate and normalise the filters.
     *
     * Dates use `checkdate()`, the same rule `effective_from` and the quota
     * period keys use. An unparseable date becomes null rather than being
     * passed through — a report silently run over "all time" because somebody
     * typed `2026-13-01` would be a wrong answer presented as a right one.
     *
     * @return array from, to, staff_id, source, status, errors
     */
    public static function normaliseFilters(array $f)
    {
        $errors = array();

        $from = self::dateOrNull(isset($f['from']) ? $f['from'] : '');
        $to   = self::dateOrNull(isset($f['to']) ? $f['to'] : '');

        if (!empty($f['from']) && $from === null) { $errors['from'] = 'that is not a real date'; }
        if (!empty($f['to']) && $to === null)     { $errors['to'] = 'that is not a real date'; }

        /*
         * A backwards range returns nothing and looks exactly like "there is no
         * activity", which is the wrong conclusion to hand somebody.
         */
        if ($from !== null && $to !== null && $from > $to) {
            $errors['range'] = 'the start date is after the end date';
        }

        $status = isset($f['status']) ? trim((string) $f['status']) : '';

        return array(
            'from'     => $from,
            'to'       => $to,
            'staff_id' => isset($f['staff_id']) && (int) $f['staff_id'] > 0 ? (int) $f['staff_id'] : 0,
            'source'   => isset($f['source']) ? substr(trim((string) $f['source']), 0, 40) : '',
            'status'   => substr($status, 0, 40),
            'errors'   => $errors,
        );
    }

    private static function dateOrNull($v)
    {
        if (!is_string($v)) { return null; }

        $v = trim($v);

        if ($v === '' || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $v, $m)) { return null; }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }

    /**
     * Make one cell safe to put in a CSV.
     *
     * The apostrophe prefix is the part that matters. A business genuinely
     * called `+91 Traders` or `-Line Services` becomes `'+91 Traders` in the
     * file and displays as typed in every spreadsheet; without it, one of them
     * is a formula. Quoting does not help: the formula runs when the file is
     * opened, not when it is parsed.
     */
    public static function csvCell($v)
    {
        if ($v === null) { return ''; }

        $s = (string) $v;

        if ($s === '') { return ''; }

        $first = substr($s, 0, 1);

        if (in_array($first, array('=', '+', '-', '@', "\t", "\r"), true)) {
            return "'" . $s;
        }

        return $s;
    }

    /** A whole row, made safe. */
    public static function csvRow(array $row)
    {
        return array_map(array('Leadfinder_reports', 'csvCell'), array_values($row));
    }

    /**
     * Strip or mask what must not leave.
     *
     * Forbidden columns are REMOVED rather than blanked: a column headed
     * `api_key_fingerprint` full of empty cells still tells a reader that the
     * report knows about it, and invites the next person to "fix" the blanks.
     *
     * Contact columns are masked rather than removed, because a callback list
     * with no phone column at all is not a callback list — the employee needs
     * to see that a number exists and recognise their own.
     */
    public static function redactRow(array $row, $maySeeContact)
    {
        foreach (self::forbiddenColumns() as $c) {
            unset($row[$c]);
        }

        if ($maySeeContact) { return $row; }

        foreach (self::contactColumns() as $c) {
            if (array_key_exists($c, $row) && $row[$c] !== null && $row[$c] !== '') {
                $row[$c] = self::mask((string) $row[$c]);
            }
        }

        return $row;
    }

    /** Keeps the last four characters, hides the rest. Never the whole value. */
    public static function mask($v)
    {
        /*
         * Counted in characters, not bytes.
         *
         * `substr()` on a multi-byte value slices mid-character and produces
         * replacement glyphs — and a business name or an address is exactly the
         * kind of field that carries them. The mask must not be the thing that
         * corrupts the value it is protecting.
         */
        $mb = function_exists('mb_strlen');
        $n  = $mb ? mb_strlen($v, 'UTF-8') : strlen($v);

        if ($n <= 4) { return str_repeat('•', $n); }

        $tail = $mb ? mb_substr($v, $n - 4, 4, 'UTF-8') : substr($v, $n - 4);

        return str_repeat('•', $n - 4) . $tail;
    }

    /**
     * The alert conditions an administrator should be told about.
     *
     * Returned as data so the same list drives the screen, the email and the
     * test. A threshold that lives in one of those three and not the others is
     * how a monitor comes to report something nobody is watching.
     */
    public static function alertKinds()
    {
        return array(
            'quota_band'        => 'A spending ceiling passed 80%, 90% or 100%',
            'retention_failed'  => 'The coordinate retention sweep failed or has not run',
            'api_error_streak'  => 'Repeated Places API failures on one connection',
            'profile_expiring'  => 'An API connection expires soon or has expired',
            'stale_reservations'=> 'Quota reservations are being held and never settled',
        );
    }

    /**
     * Is a run of API failures worth telling somebody about?
     *
     * A single failure is weather. The threshold exists so that a monitor which
     * fires on every transient 503 does not train its recipient to ignore it —
     * and the recipient of that alert is the person who would otherwise notice
     * a key that has been revoked.
     */
    public static function errorStreakAlerts($failures, $threshold = 5)
    {
        $threshold = max(1, (int) $threshold);

        return (int) $failures >= $threshold;
    }

    /** Days before expiry at which an administrator is warned. */
    public static function expiryWarningDays()
    {
        return 14;
    }
}
