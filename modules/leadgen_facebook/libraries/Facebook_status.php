<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Which lead status and which lead source a Facebook lead lands in.
 *
 * WHAT WENT WRONG BEFORE
 * ----------------------
 * Two separate failures, with the same cause — an id was treated as a fact.
 *
 *   1. `facebook_default_lead_status` held **38**, which on the staging install
 *      is "Cold". Every Facebook lead was therefore created as Cold and, being
 *      unassigned as well, appeared in the one kanban column nobody watches.
 *      The integration looked broken because the leads were invisible, not
 *      because they were missing.
 *
 *   2. The two installs do not share id space. Staging's statuses are 25–30;
 *      production's are 34–42, where "New Lead" is **34**. Copying the
 *      configured value from one to the other would have selected a different
 *      status, or none at all.
 *
 * So an id is never assumed to be meaningful. It is checked against the rows
 * that actually exist, and when it is not among them the status is found by
 * **name**, which is the thing that means the same on both installs.
 *
 * Pure: takes the rows, returns a decision. No database, no options, no
 * globals — so every branch including the ones that need a broken install can
 * be tested.
 */
class Facebook_status
{
    /** The configured id existed and was used. */
    const BY_CONFIG = 'configured_id';
    /** The configured id was absent or invalid; matched the preferred name. */
    const BY_NAME = 'matched_name';
    /** Neither; fell back to the lowest statusorder. */
    const BY_ORDER = 'lowest_order';
    /** No statuses exist at all. */
    const NONE = 'no_status_available';

    /** The name a new inbound lead should land in, unless configured otherwise. */
    const DEFAULT_NAME = 'New Lead';

    /**
     * Choose a lead status.
     *
     * @param array  $rows       lead-status rows: each needs id, name, statusorder
     * @param mixed  $configured the stored option value; '' / null / junk are all fine
     * @param string $wantedName the name to look for when the id is unusable
     *
     * PRECEDENCE, AND WHY IT IS THIS WAY ROUND
     * ----------------------------------------
     * Name first, then the configured id, then the leftmost column.
     *
     * The first version of this method had the id first, on the reasonable-
     * sounding principle that an explicit setting should beat a heuristic. The
     * test suite killed it in one line: staging's stored option is **38**, and
     * 38 **exists on production** — it is "Cold". So a configured-id-first
     * resolver, handed the value that is actually sitting in the staging
     * database, puts every production lead in Cold. That is not a hypothetical;
     * it is the precise defect being fixed, reintroduced by the resolver meant
     * to fix it.
     *
     * An id cannot carry intent across installs. There is no way to tell "the
     * administrator deliberately chose 38 here" from "38 was copied from
     * somewhere else" by looking at the number. A name can: `New Lead` means
     * the same thing on both installs, and an administrator who wants a
     * different status changes the *name* setting, which is a first-class
     * control on the settings page.
     *
     * The id override therefore survives only as a fallback for an install
     * where no status carries the configured name — where it cannot do the
     * damage that ordering it first would.
     *
     * @return array status_id (int|null), source, name
     */
    public static function resolve(array $rows, $configured = '', $wantedName = self::DEFAULT_NAME)
    {
        $clean = self::normalise($rows);

        if (empty($clean)) {
            return array('status_id' => null, 'source' => self::NONE, 'name' => '');
        }

        /*
         * By name. Compared case-insensitively with whitespace collapsed, so
         * "new  lead" and "New Lead " both match, because these names are typed
         * by hand in the Perfex settings screen. Exact after normalisation, not
         * a substring: this install has both "New Lead" and "Lead".
         */
        $wanted = self::key($wantedName);

        if ($wanted !== '') {
            foreach ($clean as $row) {
                if (self::key($row['name']) === $wanted) {
                    return array('status_id' => $row['id'], 'source' => self::BY_NAME, 'name' => $row['name']);
                }
            }
        }

        /*
         * No status carries that name on this install. Fall back to a
         * configured id, honoured only if a row with that id exists.
         *
         * Validated rather than cast: `(int) '34abc'` is 34, and an option that
         * picked up stray characters would otherwise silently select status 34
         * on one install and nothing on another. The same trap cost this project
         * a defect once already, in the Lead Finder profile selector.
         */
        if (preg_match('/\A[1-9][0-9]*\z/', trim((string) $configured)) === 1) {
            $id = (int) trim((string) $configured);

            foreach ($clean as $row) {
                if ($row['id'] === $id) {
                    return array('status_id' => $id, 'source' => self::BY_CONFIG, 'name' => $row['name']);
                }
            }
        }

        /*
         * Last resort: the first status in the administrator's own ordering.
         *
         * Note what this is NOT — it is not `ORDER BY id ASC`, which is what
         * the previous code used. The lowest id is whichever status was created
         * first, which on a CRM that has been reorganised is arbitrary. The
         * lowest `statusorder` is the leftmost column of the kanban, which is
         * where a brand-new lead belongs.
         */
        $best = null;

        foreach ($clean as $row) {
            if ($best === null
                || $row['order'] < $best['order']
                || ($row['order'] === $best['order'] && $row['id'] < $best['id'])) {
                $best = $row;
            }
        }

        return array('status_id' => $best['id'], 'source' => self::BY_ORDER, 'name' => $best['name']);
    }

    /**
     * Find an existing source row by name, case-insensitively.
     *
     * Returns the id, or null when it must be created. Exists so that
     * "create the source if it is not there" is decided on normalised names
     * rather than on an exact string match — otherwise an install that already
     * has "Facebook Lead ads" gets a second, near-identical source, and leads
     * split across the two.
     *
     * The production install already has a source called plain "Facebook"
     * (id 18). That is deliberately NOT treated as a match: "Facebook" is the
     * manual/organic source someone has been using, and merging ad-form leads
     * into it would destroy the distinction the reporting needs.
     */
    public static function findSourceId(array $rows, $name)
    {
        $wanted = self::key($name);

        if ($wanted === '') {
            return null;
        }

        foreach ($rows as $row) {
            $row = (array) $row;

            if (!isset($row['id'], $row['name'])) {
                continue;
            }

            if (self::key($row['name']) === $wanted) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    /**
     * Comparison key for a human-typed name: lowercase, whitespace collapsed,
     * trimmed. Not alphanumeric-only — "Facebook Lead Ads" and
     * "Facebook-Lead-Ads" are different sources and should stay different.
     */
    public static function key($name)
    {
        $n = strtolower(trim((string) $name));
        $n = preg_replace('/\s+/', ' ', $n);

        return $n === null ? '' : $n;
    }

    /**
     * Drop rows that cannot be used, and coerce the three fields this class
     * reads. A row with a non-numeric or absent id is not a status.
     */
    private static function normalise(array $rows)
    {
        $out = array();

        foreach ($rows as $row) {
            $row = (array) $row;

            if (!isset($row['id'])) {
                continue;
            }

            if (preg_match('/\A[1-9][0-9]*\z/', trim((string) $row['id'])) !== 1) {
                continue;
            }

            $out[] = array(
                'id'    => (int) trim((string) $row['id']),
                'name'  => isset($row['name']) ? (string) $row['name'] : '',
                'order' => isset($row['statusorder']) && $row['statusorder'] !== ''
                            ? (int) $row['statusorder']
                            : PHP_INT_MAX,
            );
        }

        return $out;
    }
}
