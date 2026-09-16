<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_report_definitions — what each reported number actually means (§6).
 *
 * Pure and framework-independent. Two jobs:
 *
 *   1. Own the definition of a converted lead, so the reports stop deciding it
 *      for themselves.
 *   2. Keep the money figures §6 requires separate from each other, and refuse
 *      to let pipeline value be presented as realized revenue.
 *
 * ---------------------------------------------------------------------------
 * DEFECT 1 — CONVERSION WAS COUNTED BY PIPELINE STATUS
 * ---------------------------------------------------------------------------
 * The reports resolved "converted" like this:
 *
 *     $row = $this->db->like('name','custom')->or_like('name','convert')
 *         ->get(db_prefix().'leads_status')->row();
 *     return $row ? (int) $row->id : 0;
 *
 * then filtered leads by `status = <that id>`. Three things wrong with it, and
 * the third is fatal:
 *
 *   a. It guesses an id from a fuzzy name match and takes whichever row comes
 *      back first.
 *   b. Its fallback is 0, and `status = 0` matches nothing — so when the guess
 *      fails, every report silently shows zero conversions rather than an error.
 *   c. A lead's pipeline status does not determine whether it converted. A lead
 *      can convert while still sitting at Hot.
 *
 * Measured on this install on 2026-09-10: the fuzzy match resolves to status 36
 * ("Customer"), which holds ZERO leads, while SIX of thirteen leads are
 * genuinely converted. Every conversion count and every conversion rate in
 * Lead Funnel, Source Performance and Agent Performance read 0 and 0% when the
 * true figure was 6 and 46.2%. The reports made the sales team look like they
 * had converted nothing.
 *
 * This is the same defect already found and fixed in the Sales Targets module,
 * where `Kpi_lead_conversion` owns the correct definition and even self-audits
 * SQL for `status = <n>` filters. It reappeared here because the definition was
 * re-implemented instead of shared. Where that library is installed, it is used
 * directly; where it is not, this one refuses to report a conversion figure
 * rather than falling back to a guess.
 *
 * ---------------------------------------------------------------------------
 * DEFECT 2 — MONEY WAS ADDED UP ACROSS INCOMPATIBLE MEANINGS
 * ---------------------------------------------------------------------------
 * "Commission Liability" summed net_amount across every statement status except
 * superseded, under one column called "Net amount". Commission already PAID is
 * not a liability. Commission REVERSED by a clawback is not a liability either.
 * §6 requires billed revenue, amount collected, net revenue, commission
 * payable, commission paid, clawbacks and pipeline value to be reported
 * separately, and forbids showing pipeline value as realized revenue.
 */
class Payplex_report_definitions
{
    /* ---------------- converted leads ---------------- */

    /**
     * Where the canonical converted-lead definition lives, if it is installed.
     * A path rather than a copy: two copies of this rule is how the defect it
     * describes came back in the first place.
     */
    public static function conversionLibraryPath($modulesDir)
    {
        return rtrim((string) $modulesDir, '/') . '/sales_targets/libraries/Kpi_lead_conversion.php';
    }

    /**
     * Can conversions be reported at all on this install?
     *
     * @return array available (bool), reason
     */
    public static function conversionAvailability($libraryExists)
    {
        if ($libraryExists) {
            return array('available' => true, 'reason' => '');
        }
        return array(
            'available' => false,
            'reason'    => 'Conversion figures need the Sales Targets module, which owns the '
                         . 'definition of a converted lead. It is not installed, so conversions '
                         . 'are not reported here rather than being guessed from lead status.',
        );
    }

    /**
     * The human-readable definition, shown on every report that counts
     * conversions. §6 requires each report to state its calculation.
     */
    public static function conversionDefinition()
    {
        return 'A lead counts as converted when its date_converted is set AND a client record '
             . 'references it. Junk and lost leads are excluded. Pipeline status is deliberately '
             . 'not used: a lead can convert while still sitting at any status.';
    }

    /**
     * The converted-lead test, as a WHERE fragment for aggregate reports.
     *
     * Kpi_lead_conversion::build() is shaped for one staff member's target and
     * binds a staff id twice, so it cannot be reused verbatim to count
     * conversions by source or across a whole team. What IS reused is that
     * library's audit(): the queries built here are asserted against it, so the
     * two cannot drift apart without a test failing. Sharing the enforcement is
     * the part that matters — sharing only the text is what let this defect be
     * re-implemented wrongly in the first place.
     *
     * @param string $prefix    db prefix
     * @param array  $available columns present on the leads table
     * @return array where (string), binds (ordered names), notes
     */
    public static function convertedLeadPredicate($prefix, $available = array('junk', 'lost'))
    {
        $p = (string) $prefix;
        $available = array_map('strtolower', (array) $available);

        $where = array();
        $notes = array();

        // DATE() so a lead converted at 23:59 on the closing day still counts.
        $where[] = 'DATE(' . $p . 'leads.date_converted) BETWEEN ? AND ?';
        $notes[] = 'Date bound is inclusive of both endpoints, whole days.';

        $where[] = $p . 'leads.id IN (SELECT leadid FROM ' . $p . 'clients WHERE leadid = ' . $p . 'leads.id)';
        $notes[] = 'A client row must reference the lead; date_converted alone is not enough.';

        foreach (array('junk', 'lost') as $col) {
            if (in_array($col, $available, true)) {
                $where[] = '(' . $p . 'leads.' . $col . ' = 0 OR ' . $p . 'leads.' . $col . ' IS NULL)';
            } else {
                $notes[] = 'Column "' . $col . '" not present on this install; that exclusion is skipped.';
            }
        }
        $notes[] = 'Pipeline status is deliberately not part of this test.';

        return array(
            'where' => implode(' AND ', $where),
            'binds' => array('date_from', 'date_to'),
            'notes' => $notes,
        );
    }

    /**
     * Audit a conversion query for the defect this class exists to prevent.
     * Mirrors Kpi_lead_conversion::audit so the reports module cannot quietly
     * reintroduce a status filter of its own.
     *
     * @return array list of problems; empty means clean
     */
    public static function auditConversionSql($sql)
    {
        $s = (string) $sql;
        $problems = array();

        if (preg_match('/\bl?\.?status\s*=\s*\d+/i', $s) || preg_match('/\bstatus\s*=\s*\?/i', $s)) {
            $problems[] = 'Query filters leads by pipeline status. Lead status ids are per-install '
                        . 'data and a lead\'s status does not determine whether it converted.';
        }
        if (stripos($s, 'date_converted') === false) {
            $problems[] = 'Query does not reference date_converted, so it cannot know when — or '
                        . 'whether — the lead converted.';
        }
        if (stripos($s, 'leadid') === false) {
            $problems[] = 'Query does not join clients on leadid, so it cannot confirm a real '
                        . 'customer exists.';
        }
        return $problems;
    }

    /* ---------------- money ---------------- */

    /**
     * How each commission statement status maps onto the §6 money categories.
     *
     * Anything unrecognised maps to 'other' and is reported under its own
     * heading rather than being folded into a total. Silently absorbing an
     * unknown status into "payable" is how a paid statement ends up counted as
     * a liability.
     */
    public static function commissionBuckets()
    {
        return array(
            // still owed
            'generated'        => 'payable',
            'under_review'     => 'payable',
            'pending_approval' => 'payable',
            'approved'         => 'payable',
            'payable'          => 'payable',
            'exported'         => 'payable',
            // money that has left
            'paid'             => 'paid',
            // money taken back
            'reversed'         => 'clawed_back',
            'clawed_back'      => 'clawed_back',
            // not a figure at all
            'superseded'       => 'excluded',
            'draft'            => 'excluded',
            'rejected'         => 'excluded',
        );
    }

    public static function bucketFor($status)
    {
        $map = self::commissionBuckets();
        $s = strtolower(trim((string) $status));
        return isset($map[$s]) ? $map[$s] : 'other';
    }

    public static function bucketLabels()
    {
        return array(
            'payable'     => 'Commission payable (still owed)',
            'paid'        => 'Commission paid (already disbursed)',
            'clawed_back' => 'Clawed back (reversed)',
            'other'       => 'Unrecognised status — not included in any total',
            'excluded'    => 'Excluded (superseded, draft or rejected)',
        );
    }

    /**
     * Split statement rows into the §6 categories.
     *
     * Returns a total ONLY for the liability figure, because that is the only
     * one of these that is a single meaningful sum. Paid and clawed-back are
     * reported beside it, never added to it.
     *
     * @param array $rows each with status, cnt, net
     */
    public static function commissionSummary($rows)
    {
        $buckets = array();
        foreach (array_keys(self::bucketLabels()) as $b) {
            $buckets[$b] = array('count' => 0, 'amount' => 0.0, 'statuses' => array());
        }

        foreach ((array) $rows as $row) {
            $r = (array) $row;
            $status = isset($r['status']) ? (string) $r['status'] : '';
            $b = self::bucketFor($status);
            $buckets[$b]['count']  += (int) (isset($r['cnt']) ? $r['cnt'] : 0);
            $buckets[$b]['amount'] += (float) (isset($r['net']) ? $r['net'] : 0);
            $buckets[$b]['statuses'][] = $status;
        }

        return array(
            'buckets'   => $buckets,
            // the single number that is genuinely "liability"
            'liability' => round($buckets['payable']['amount'], 2),
            // reported separately, never summed into the above
            'paid'        => round($buckets['paid']['amount'], 2),
            'clawed_back' => round($buckets['clawed_back']['amount'], 2),
            'has_unrecognised' => $buckets['other']['count'] > 0,
        );
    }

    /**
     * The §6 money vocabulary, with an explicit note on what each is NOT.
     * Shown on money reports so two different figures are never read as one.
     */
    public static function moneyDefinitions()
    {
        return array(
            'billed_revenue'     => 'Invoiced to customers. Not necessarily received.',
            'amount_collected'   => 'Payments actually received. This is the only realized figure.',
            'net_revenue'        => 'Collected, less refunds and credit notes.',
            'commission_payable' => 'Approved and owed to staff. Not yet paid.',
            'commission_paid'    => 'Already disbursed. Not a liability.',
            'clawbacks'          => 'Commission reversed after the fact. Reduces future payouts.',
            'pipeline_value'     => 'Estimated value of open leads. NOT revenue, realized or '
                                  . 'otherwise, and never to be totalled with any figure above.',
        );
    }

    /**
     * Guard for report construction: refuses to build a figure that mixes
     * pipeline value into a revenue or commission total.
     *
     * @return array list of problems; empty means clean
     */
    public static function auditMoneyComposition($figureName, array $componentKeys)
    {
        $problems = array();
        $name = strtolower((string) $figureName);
        $has = function ($k) use ($componentKeys) { return in_array($k, $componentKeys, true); };

        if ($has('pipeline_value') && count($componentKeys) > 1) {
            $problems[] = 'Figure "' . $figureName . '" combines pipeline value with other amounts. '
                        . 'Pipeline value is an estimate of open leads and is not revenue.';
        }
        if ($has('pipeline_value') && preg_match('/revenue|collected|earned|realis|realiz/i', $name)) {
            $problems[] = 'Figure "' . $figureName . '" presents pipeline value under a name that '
                        . 'implies realized revenue.';
        }
        if ($has('commission_paid') && $has('commission_payable')
            && preg_match('/liabilit|owed|outstanding|payable/i', $name)) {
            $problems[] = 'Figure "' . $figureName . '" adds commission already paid to commission '
                        . 'still owed. Paid commission is not a liability.';
        }
        if ($has('billed_revenue') && $has('amount_collected')
            && preg_match('/revenue|collected/i', $name)) {
            $problems[] = 'Figure "' . $figureName . '" adds billed revenue to amount collected, '
                        . 'which double-counts every invoice that has been paid.';
        }
        return $problems;
    }

    /* ---------------- report metadata (§6) ---------------- */

    /**
     * Every report must be able to say what it covers and where it came from.
     * Returns the block a view renders; a missing piece is reported as missing
     * rather than left blank.
     */
    public static function metadata($opts = array())
    {
        $o = (array) $opts;
        $get = function ($k, $default = null) use ($o) {
            return array_key_exists($k, $o) && $o[$k] !== '' ? $o[$k] : $default;
        };

        $from = $get('date_from');
        $to   = $get('date_to');

        return array(
            'date_range'  => ($from && $to) ? ($from . ' to ' . $to) : 'All time (no date filter applied)',
            'filters'     => $get('filters', 'None'),
            'data_source' => $get('data_source', 'Not stated'),
            'definition'  => $get('definition', 'Not stated'),
            'generated_at'=> $get('generated_at', null),
            'row_count'   => (int) $get('row_count', 0),
            'complete'    => $get('data_source') !== null && $get('definition') !== null,
        );
    }

    /* ---------------- the declared period (§6) ---------------- */

    /**
     * DEFECT 3 — THE PERIOD WAS DECLARED AND APPLIED TO ONE COLUMN.
     *
     * Reports::dateRange() reads date_from / date_to and every report printed
     * them in its provenance block as "Period: X to Y". Only the CONVERSION
     * queries were bound by those dates. Total leads, Contacted, Lost, leads
     * per source, leads per agent and call spend were all-time, whatever the
     * header said.
     *
     * Measured on this install on 2026-09-11, with 13 leads and 6 conversions:
     *
     *   window            leads shown   converted shown   conv % shown
     *   all time          13            6                 46.2%
     *   2026-09-01 on     13  (wrong)   1                  7.7%
     *   August            13  (wrong)   0                  0.0%
     *
     * Seven of the thirteen leads were created in September, so the September
     * figure should read 1 of 7. Narrowing the window did not filter the
     * denominator, it only shrank the numerator — so every filtered view
     * understated conversion, and the narrower the filter the worse it looked.
     * That is the same harm as DEFECT 1 above: a report that makes the sales
     * team look like they converted less than they did.
     *
     * The volume figures are now bound by the same window. The percentage is a
     * separate problem: leads are counted by when they were CREATED and
     * conversions by when they CONVERTED, so over a narrowed period the two are
     * different sets of leads and their ratio is not a conversion rate. Rather
     * than invent a cohort rule that nothing else in this codebase shares —
     * which is exactly how DEFECT 1 was created — the ratio is withheld with a
     * stated reason, and both counts are shown.
     */
    const WINDOW_OPEN_FROM = '1970-01-01';

    /**
     * Has the viewer actually narrowed the period, or are these the wide-open
     * defaults Reports::dateRange() supplies when nothing is passed?
     */
    public static function windowIsNarrowed($from, $to, $today = null)
    {
        $today = $today !== null ? (string) $today : date('Y-m-d');
        return !((string) $from === self::WINDOW_OPEN_FROM && (string) $to >= $today);
    }

    /**
     * Bound lead VOLUME by when the lead was created. dateadded is the only
     * date a lead has for "when it entered", so this invents nothing.
     */
    public static function leadVolumePredicate($prefix)
    {
        return 'DATE(' . (string) $prefix . 'leads.dateadded) BETWEEN ? AND ?';
    }

    /** The same bound for a joined alias (the assignee join in agent performance). */
    public static function leadVolumePredicateFor($alias)
    {
        return 'DATE(' . (string) $alias . '.dateadded) BETWEEN ? AND ?';
    }

    /**
     * May a conversion PERCENTAGE be shown for this window?
     *
     * @return array comparable (bool), reason
     */
    public static function rateComparability($narrowed)
    {
        if (!$narrowed) {
            return array('comparable' => true, 'reason' => '');
        }
        return array(
            'comparable' => false,
            'reason'     => 'Conversion % is not shown for a narrowed period. Leads are counted '
                          . 'by when they were created and conversions by when they converted, so '
                          . 'over a shorter period these are different sets of leads — one created '
                          . 'in June and converted in September appears in the second count and not '
                          . 'the first. Their ratio would not be a conversion rate. Both counts are '
                          . 'correct for the period; clear the date filter to see the rate.',
        );
    }

    /**
     * Guard for report construction, mirroring auditConversionSql above: if a
     * report declares a period, every figure it shows must be bound by it.
     *
     * @param bool  $declaresPeriod
     * @param array $queries label => sql
     * @return array problems; empty means clean
     */
    public static function auditWindowedReport($declaresPeriod, array $queries)
    {
        $problems = array();
        if (!$declaresPeriod) { return $problems; }
        foreach ($queries as $label => $sql) {
            if (!preg_match('/BETWEEN\s*\?\s*AND\s*\?/i', (string) $sql)) {
                $problems[] = 'Report declares a period but the query for "' . $label . '" carries '
                            . 'no date bound, so that figure is all-time while the header says '
                            . 'otherwise.';
            }
        }
        return $problems;
    }

    /**
     * The export link must carry the period the viewer is looking at.
     *
     * The buttons were hard-coded to "?export=csv", so exporting a filtered
     * view silently downloaded the unfiltered one. An export that quietly
     * differs from the screen it was launched from is worse than no export.
     */
    public static function exportQuery($get)
    {
        $keep = array();
        foreach (array('date_from', 'date_to') as $k) {
            $v = is_array($get) && isset($get[$k]) ? (string) $get[$k] : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $keep[$k] = $v; }
        }
        $keep['export'] = 'csv';
        return http_build_query($keep);
    }

    /* ---------------- core dashboard endpoints (§5) ---------------- */

    /**
     * Perfex's own dashboard controller exposes three endpoints that the
     * widgets calling them are permission-checked for, and they are not:
     *
     *   admin/dashboard/ticket_widget/<period>            renders the per-staff
     *       tickets report. dashboard.php builds it only inside is_admin(), and
     *       the view it returns (tickets_report_table.php) checks nothing.
     *   admin/dashboard/weekly_payments_statistics/<cur>  company payment totals
     *   admin/dashboard/monthly_payments_statistics/<cur> company payment totals
     *       payments_chart.php is rendered only for staff_can('view','payments')
     *       or staff_can('view_own','invoices'); the endpoints check neither.
     *
     * All three inherit AdminController, so the only requirement is a logged-in
     * staff session. This is the same defect this module already fixed in its
     * own Agent Performance report — an employee ranking readable by employees —
     * except it is in a Perfex core file, which is not ours to edit.
     *
     * So the rule lives here and payplex_reports enforces it on admin_init,
     * before the controller method runs. No core file is modified. The rule
     * mirrors each widget's own check, so nothing an admin or a permitted staff
     * member could do before is refused now.
     *
     * @return string|null 'admin', 'payments', or null when not one of them
     */
    public static function dashboardEndpointRule($method)
    {
        $map = array(
            'ticket_widget'               => 'admin',
            'weekly_payments_statistics'  => 'payments',
            'monthly_payments_statistics' => 'payments',
        );
        $m = strtolower(trim((string) $method));
        return isset($map[$m]) ? $map[$m] : null;
    }

    /**
     * Decide the guard from already-resolved facts, so the decision is testable
     * without a framework. Returns true when the request must be refused.
     *
     * @param string $class      routed controller class
     * @param string $method     routed method
     * @param bool   $isAdmin
     * @param bool   $canPayments  staff_can('view','payments') || view_own invoices
     */
    public static function dashboardGuardRefuses($class, $method, $isAdmin, $canPayments)
    {
        if (strtolower(trim((string) $class)) !== 'dashboard') { return false; }
        $rule = self::dashboardEndpointRule($method);
        if ($rule === null) { return false; }
        if ($isAdmin) { return false; }
        if ($rule === 'admin') { return true; }
        return !$canPayments;
    }

    /**
     * Which report rows a given viewer may see (§5: no cross-role leakage).
     *
     * A staff member without the team-wide capability sees only their own row.
     * Agent Performance previously showed every colleague's leads, conversions,
     * call volume and cost to anyone holding the plain 'view' capability, which
     * is an employee ranking exposed to employees.
     */
    public static function scopeRows($rows, $viewerStaffId, $canViewAll, $staffIdKey = 'staffid')
    {
        if ($canViewAll) { return array_values((array) $rows); }
        $viewer = (int) $viewerStaffId;
        if ($viewer <= 0) { return array(); }

        $out = array();
        foreach ((array) $rows as $row) {
            $r = (array) $row;
            if (isset($r[$staffIdKey]) && (int) $r[$staffIdKey] === $viewer) { $out[] = $row; }
        }
        return $out;
    }
}
