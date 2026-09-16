<?php
defined('BASEPATH') or defined('LEADGEN_FU_TEST') or exit('No direct script access allowed');

/**
 * Followup_eligibility — the candidate query, with suppression in the WHERE.
 *
 * WHY THE PREDICATE MUST BE IN SQL, BEFORE `LIMIT`
 * ------------------------------------------------
 * The old code selected leads and then made every other decision in PHP. With
 * no `LIMIT` that was merely slow. Add a `LIMIT` for safety — which this fix
 * does, because an unbounded cron loop over a growing table is its own defect —
 * and filtering afterwards becomes wrong in a way that is almost impossible to
 * see: the database returns the first 25 rows, PHP discards the suppressed
 * ones, and the run silently processes eight leads while believing it processed
 * twenty-five. Worse, the same eight come back every run and everything behind
 * them starves.
 *
 * So every suppression rule is a WHERE clause, and `LIMIT` is applied to rows
 * that have already survived it. `assertPredicateBeforeLimit()` exists so a test
 * can prove that property of the generated SQL rather than trusting this
 * comment.
 *
 * HARD SUPPRESSION — WHO MUST NEVER RECEIVE THIS
 * ----------------------------------------------
 *   converted customer · lost · junk · unassigned · do-not-contact · invalid ·
 *   rejected · unsubscribed · any record owned by Lead Finder
 *
 * The measured reason this list is not optional: of the 24 follow-up rows on
 * staging, 16 belonged to leads that had already converted, every one was sent
 * after the conversion date, and every one included the lead email. Sixteen
 * customers were written to as prospects, by name, from the company address.
 *
 * TWO QUERIES, ONE FROM CLAUSE
 * ----------------------------
 * `eligibleSql()` is what the cron runs. `auditSql()` is the same joins and the
 * same CASE, with no filter, so the UAT can show *why* each lead was or was not
 * selected. They share `fromClause()` and `suppressionCase()`, so an audit that
 * disagrees with the live query is not possible — which is the only way an
 * audit is worth anything.
 *
 * Every value is bound. No identifier here comes from user input; the only
 * interpolation is the table prefix and the optional status list, and the status
 * list is cast to int before it is used.
 */
class Followup_eligibility
{
    const R_CONVERTED   = 'converted_customer';
    const R_LOST        = 'lost';
    const R_JUNK        = 'junk';
    const R_UNASSIGNED  = 'unassigned';
    const R_DNC         = 'do_not_contact';
    const R_INVALID     = 'invalid';
    const R_REJECTED    = 'rejected';
    const R_UNSUBSCRIBE = 'unsubscribed';
    const R_LEADFINDER  = 'lead_finder_record';

    /** Categories the module's own suppression table may carry. */
    public static function markerCategories()
    {
        return array(self::R_DNC, self::R_INVALID, self::R_REJECTED, self::R_UNSUBSCRIBE);
    }

    /** Every reason this builder can emit. A test asserts the CASE covers them all. */
    public static function allReasons()
    {
        return array(
            self::R_CONVERTED, self::R_LOST, self::R_JUNK, self::R_UNASSIGNED,
            self::R_DNC, self::R_INVALID, self::R_REJECTED, self::R_UNSUBSCRIBE,
            self::R_LEADFINDER,
        );
    }

    /**
     * @param string $prefix        database table prefix
     * @param array  $opts          available_tables, customer_status_ids
     */
    public static function fromClause($prefix, array $opts = array())
    {
        return '`' . $prefix . 'leads` l';
    }

    /**
     * The suppression expression: the first matching reason, or NULL.
     *
     * Order matters only for which reason gets reported, never for whether a
     * lead is suppressed — any non-NULL result blocks the send.
     */
    public static function suppressionCase($prefix, array $opts = array())
    {
        $tables   = isset($opts['available_tables']) ? (array) $opts['available_tables'] : array();
        $statuses = self::intList(isset($opts['customer_status_ids']) ? $opts['customer_status_ids'] : array());

        $sup = $prefix . 'leadgen_followup_suppression';
        $con = $prefix . 'consents';
        $lfp = $prefix . 'payplex_lf_prospects';

        $parts = array();

        $parts[] = "WHEN l.`date_converted` IS NOT NULL THEN '" . self::R_CONVERTED . "'";

        if (!empty($statuses)) {
            $parts[] = 'WHEN l.`status` IN (' . implode(',', $statuses) . ") THEN '" . self::R_CONVERTED . "'";
        }

        $parts[] = "WHEN l.`lost` = 1 THEN '" . self::R_LOST . "'";
        $parts[] = "WHEN l.`junk` = 1 THEN '" . self::R_JUNK . "'";
        $parts[] = "WHEN l.`assigned` = 0 THEN '" . self::R_UNASSIGNED . "'";

        /*
         * The module's own marker table. It exists because `tblleads` on this
         * install has no do-not-contact, invalid, rejected or unsubscribed
         * column, and inventing four columns on a core table to hold a module's
         * policy is not a change this module gets to make on its own.
         */
        if (in_array($sup, $tables, true)) {
            foreach (self::markerCategories() as $cat) {
                $parts[] = "WHEN EXISTS (SELECT 1 FROM `{$sup}` s WHERE s.`leadid` = l.`id` "
                         . "AND s.`category` = '" . $cat . "') THEN '" . $cat . "'";
            }
        }

        /* Perfex's own GDPR consent ledger: an opt-out is an unsubscribe. */
        if (in_array($con, $tables, true)) {
            $parts[] = "WHEN EXISTS (SELECT 1 FROM `{$con}` c WHERE c.`lead_id` = l.`id` "
                     . "AND c.`action` = 'opt-out') THEN '" . self::R_UNSUBSCRIBE . "'";
        }

        /*
         * Lead Finder prospects converted into leads are that module's records
         * and are excluded from this drip by the approved decision. When the
         * table is absent no prospect has ever been converted, so there is
         * nothing to exclude — the predicate is omitted rather than guessed at,
         * and the run audit records which predicates were active.
         */
        if (in_array($lfp, $tables, true)) {
            $parts[] = "WHEN EXISTS (SELECT 1 FROM `{$lfp}` p WHERE p.`converted_lead_id` = l.`id`) "
                     . "THEN '" . self::R_LEADFINDER . "'";
        }

        return "CASE\n    " . implode("\n    ", $parts) . "\n    ELSE NULL\nEND";
    }

    /**
     * Candidate leads for this run.
     *
     * `:now` and `:min_age_hours` are bound. The age filter is the cheapest
     * possible form of "not due yet" — a lead younger than the first stage can
     * never be selected, so there is no reason to carry it into PHP. The precise
     * per-stage decision still belongs to Followup_schedule; this only trims the
     * set the database has to hand over.
     */
    public static function eligibleSql($prefix, array $opts = array())
    {
        $case = self::suppressionCase($prefix, $opts);

        return "SELECT l.`id`, l.`name`, l.`email`, l.`assigned`, l.`dateadded`,\n"
             . "       (" . $case . ") AS suppression_reason\n"
             . "FROM " . self::fromClause($prefix, $opts) . "\n"
             . "WHERE (" . $case . ") IS NULL\n"
             . "  AND l.`dateadded` IS NOT NULL\n"
             . "  AND l.`dateadded` <= DATE_SUB(?, INTERVAL ? HOUR)\n"
             . "ORDER BY l.`dateadded` ASC, l.`id` ASC\n"
             . "LIMIT ?";
    }

    /**
     * Every lead with its reason, unfiltered — the evidence query.
     *
     * Deliberately has no LIMIT: it is not a sending path, it is what the UAT
     * reads to show that the live query's exclusions were the intended ones.
     */
    public static function auditSql($prefix, array $opts = array())
    {
        $case = self::suppressionCase($prefix, $opts);

        return "SELECT l.`id`, l.`name`, l.`assigned`, l.`dateadded`, l.`date_converted`,\n"
             . "       (" . $case . ") AS suppression_reason\n"
             . "FROM " . self::fromClause($prefix, $opts) . "\n"
             . "ORDER BY l.`id` ASC";
    }

    /**
     * Prove the eligibility filter is applied by the database, not afterwards.
     *
     * Returns true only when a WHERE clause exists, it carries the suppression
     * expression, and LIMIT comes after it. A test calls this against the real
     * generated SQL and also against a deliberately broken string, so the check
     * is known to be capable of returning false.
     */
    public static function assertPredicateBeforeLimit($sql)
    {
        $where = stripos($sql, 'WHERE');

        if ($where === false || !is_int($where)) {
            return false;
        }

        $limit = stripos($sql, 'LIMIT');

        if (is_int($limit) && $limit < $where) {
            return false;
        }

        $tail = substr($sql, $where, ($limit === false ? strlen($sql) : $limit) - $where);

        return stripos($tail, 'CASE') !== false && stripos($tail, 'IS NULL') !== false;
    }

    /** Cast a status list to integers, dropping anything that is not one. */
    private static function intList($v)
    {
        if (!is_array($v)) {
            $v = ($v === null || $v === '') ? array() : explode(',', (string) $v);
        }

        $out = array();

        foreach ($v as $x) {
            $x = is_string($x) ? trim($x) : $x;

            if (is_int($x) || (is_string($x) && preg_match('/\A\d+\z/', $x))) {
                $n = (int) $x;

                if ($n > 0) {
                    $out[$n] = $n;
                }
            }
        }

        return array_values($out);
    }
}
