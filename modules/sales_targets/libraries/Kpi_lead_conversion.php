<?php

defined('BASEPATH') or defined('SALES_TARGETS_TEST') or exit('No direct script access allowed');

/**
 * Kpi_lead_conversion — how a converted lead is counted (spec §3.1).
 *
 * Pure and framework-independent: it builds the SQL and explains the rules, so
 * the definition of "converted" is testable without a database and reviewable
 * without reading the engine.
 *
 * ---------------------------------------------------------------------------
 * THE DEFECT THIS REPLACES
 * ---------------------------------------------------------------------------
 * The previous query carried `AND status = 1`, copied verbatim from Perfex's own
 * Goals module (Goals_model::calculate_goal_achievement, goal_type 2) on the
 * stated grounds that it was "already proven". It was not proven; it was a
 * latent core defect that propagated by being copied.
 *
 * `tblleads.status` is a foreign key to `tblleads_status.id`. Those ids are
 * per-install data, not constants. On this install the lead statuses are
 * 34 New Lead, 35 Hot, 36 Customer, 37 Warm, 38 Cold, 39 Nurture — verified
 * directly from Setup > Lead statuses. THERE IS NO STATUS WITH ID 1, so the
 * condition matched nothing and converted-leads achievement was always zero.
 *
 * Critically, substituting the "right" id would NOT fix it. The Customer status
 * (36) holds zero leads on this install, while a lead HAS been converted to a
 * client. A lead's pipeline status is independent of whether it converted: a
 * lead can convert while still sitting at Hot. Filtering conversions by pipeline
 * status is therefore wrong in principle, not merely mis-numbered — which is why
 * the clause is removed rather than corrected.
 *
 * ---------------------------------------------------------------------------
 * WHAT ACTUALLY PROVES A CONVERSION
 * ---------------------------------------------------------------------------
 *   1. `date_converted` is set, and falls inside the period; AND
 *   2. a real client row references the lead (tblclients.leadid).
 *
 * Both must hold. (1) alone can survive a client being deleted afterwards;
 * (2) alone carries no date to attribute the conversion to a period.
 *
 * Junk and lost leads are excluded. A lead marked junk or lost is not a won
 * customer, and counting one toward a sales target would inflate achievement —
 * and, downstream, commission.
 */
class Kpi_lead_conversion
{
    /** Columns that must exist before a condition depending on them is emitted. */
    public static function optionalExclusionColumns()
    {
        return array('junk', 'lost');
    }

    /**
     * Build the converted-leads query.
     *
     * @param string $prefix    db prefix
     * @param array  $available columns present on the leads table, so the same
     *                          code works on installs that predate junk/lost
     *                          rather than throwing an unknown-column error
     * @return array sql, binds (ordered placeholder names), excluded, notes
     */
    public static function build($prefix, $available = array('junk', 'lost'))
    {
        $p = (string) $prefix;
        $available = array_map('strtolower', (array) $available);

        $where = array();
        $notes = array();
        $excluded = array();

        // Date bound. DATE() strips the time component, so a lead converted at
        // 23:59 on the closing day still counts. period_start/period_end are
        // plain Y-m-d in the app's timezone, and date_converted is stored in the
        // same timezone, so no conversion is applied — introducing one here
        // would shift conversions across midnight.
        $where[] = 'DATE(' . $p . 'leads.date_converted) BETWEEN ? AND ?';
        $notes[] = 'Date bound is inclusive of both endpoints, whole days, app timezone.';

        // A real client must exist for the lead.
        $where[] = $p . 'leads.id IN (SELECT leadid FROM ' . $p . 'clients WHERE leadid = ' . $p . 'leads.id)';
        $notes[] = 'A client row must reference the lead; date_converted alone is not enough.';

        // Junk / lost exclusions, only when the columns exist.
        foreach (self::optionalExclusionColumns() as $col) {
            if (in_array($col, $available, true)) {
                $where[] = '(' . $p . 'leads.' . $col . ' = 0 OR ' . $p . 'leads.' . $col . ' IS NULL)';
                $excluded[] = $col;
            } else {
                $notes[] = 'Column "' . $col . '" not present on this install; that exclusion is skipped.';
            }
        }
        if ($excluded) {
            $notes[] = 'Excluded from the count: ' . implode(' and ', $excluded) . ' leads.';
        }

        // Attribution: the assignee owns the conversion; an unassigned lead falls
        // back to whoever created it. NULL is treated as unassigned, because a
        // NULL assigned would otherwise compare false on both branches and the
        // conversion would belong to nobody.
        $where[] = '(CASE WHEN ' . $p . 'leads.assigned IS NULL OR ' . $p . 'leads.assigned = 0 '
                 . 'THEN ' . $p . 'leads.addedfrom = ? ELSE ' . $p . 'leads.assigned = ? END)';
        $notes[] = 'Attribution: assigned staff, falling back to the creator when unassigned or NULL.';

        // DISTINCT guards against a lead being counted twice if the clients table
        // ever holds two rows pointing at the same lead.
        $sql = 'SELECT DISTINCT ' . $p . 'leads.id AS id'
             . ' FROM ' . $p . 'leads'
             . ' WHERE ' . implode("\n                  AND ", $where);

        return array(
            'sql'      => $sql,
            'binds'    => array('period_start', 'period_end', 'staff_id', 'staff_id'),
            'excluded' => $excluded,
            'notes'    => $notes,
        );
    }

    /**
     * Guard against the defect coming back.
     *
     * The original bug arrived by copy-paste and would arrive the same way again,
     * so this asserts the properties that matter rather than trusting review.
     *
     * @return array ok, problems
     */
    public static function audit($sql)
    {
        $s = (string) $sql;
        $problems = array();

        // any literal pipeline-status equality is the defect returning
        if (preg_match('/\bstatus\s*=\s*\d+/i', $s)) {
            $problems[] = 'Query filters leads by a hard-coded status id. Lead status ids are '
                        . 'per-install data and a lead\'s pipeline status does not determine whether '
                        . 'it converted.';
        }
        if (!preg_match('/DATE\s*\(\s*\w*leads\.date_converted\s*\)/i', $s)) {
            $problems[] = 'Query does not bound on DATE(date_converted); the period boundary would '
                        . 'drop conversions recorded later in the closing day.';
        }
        if (stripos($s, 'clients') === false || stripos($s, 'leadid') === false) {
            $problems[] = 'Query does not require a real client row for the lead.';
        }
        if (stripos($s, 'DISTINCT') === false) {
            $problems[] = 'Query is not DISTINCT and could double-count a lead.';
        }

        return array('ok' => empty($problems), 'problems' => $problems);
    }

    /** Human-readable definition, for the UI and for an auditor. */
    public static function explain($build)
    {
        $b = (array) $build;
        $lines = array();
        $lines[] = 'A lead counts as converted when its date_converted falls inside the period AND a '
                 . 'client record references it.';
        foreach ((array) (isset($b['notes']) ? $b['notes'] : array()) as $n) { $lines[] = '- ' . $n; }
        $lines[] = '- A lead\'s pipeline status is deliberately NOT part of this test.';
        return implode("\n", $lines);
    }
}
