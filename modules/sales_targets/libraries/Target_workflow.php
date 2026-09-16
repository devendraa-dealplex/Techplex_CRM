<?php

defined('BASEPATH') or defined('SALES_TARGETS_TEST') or exit('No direct script access allowed');

/**
 * Target_workflow — target maker-checker (spec §3.3).
 *
 * Pure and framework-independent.
 *
 * Lifecycle: Draft → Submitted → Approved → Active → Completed → Locked
 *
 * The rules that matter, and what each prevents:
 *
 *  1. AN EMPLOYEE CANNOT MODIFY THEIR OWN TARGET. Someone who can lower the bar
 *     they are measured against has no bar. This is checked against the target's
 *     assignees, not only its owner field, because a team target that pays a
 *     manager is still their own target.
 *
 *  2. MAKER IS NOT APPROVER. The person who set the numbers does not get to
 *     ratify them.
 *
 *  3. WEIGHTS MUST TOTAL 100 BEFORE APPROVAL. Enforced here rather than only in
 *     the form, because an approval route that skips the form would otherwise
 *     approve an unscoreable target.
 *
 *  4. AN ACTIVE TARGET IS NOT EDITED — IT IS VERSIONED. Achievement has already
 *     been calculated and possibly acted on. Changing the target underneath
 *     rewrites history; a new version leaves the old results standing.
 *
 *  5. NO OVERLAPPING ACTIVE PERIODS FOR THE SAME PERSON AND KPI. Two live
 *     targets covering the same day and metric means achievement counts twice
 *     and nobody can say which figure is real.
 */
class Target_workflow
{
    const DRAFT     = 'Draft';
    const SUBMITTED = 'Submitted';
    const APPROVED  = 'Approved';
    const ACTIVE    = 'Active';
    const COMPLETED = 'Completed';
    const LOCKED    = 'Locked';
    const REJECTED  = 'Rejected';

    /*
     * Which capability may move a target INTO each state.
     *
     * The module registered fifteen capabilities and checked four of them, so
     * eleven appeared on the Roles screen as permissions that controlled
     * nothing — an administrator could grant "Approve & activate targets" and
     * change nothing at all. Worse, the controller tested three names the
     * module never registered (view, edit, senior), and staff_can() answers
     * false for a capability that cannot be granted: every senior-only
     * transition was permanently closed to non-administrators while
     * administrators sailed through on is_admin() and nobody noticed.
     *
     * These names are not invented. Each is the capability the module already
     * declares for exactly this act; the map only connects the two ends that
     * were never wired together. An unmapped state returns null and is refused.
     */
    public static function stateCapabilities()
    {
        return array(
            self::DRAFT     => 'create',
            self::SUBMITTED => 'submit',
            self::APPROVED  => 'approve_activate',
            self::ACTIVE    => 'approve_activate',
            self::COMPLETED => 'lock_complete',
            self::LOCKED    => 'lock_complete',
            self::REJECTED  => 'approve_activate',
        );
    }

    /** The capability needed to move a target INTO $state, or null if unknown. */
    public static function capabilityFor($state)
    {
        $map = self::stateCapabilities();
        $state = (string) $state;
        return isset($map[$state]) ? $map[$state] : null;
    }

    public static function states()
    {
        return array(
            self::DRAFT     => 'Draft',
            self::SUBMITTED => 'Submitted for approval',
            self::APPROVED  => 'Approved',
            self::ACTIVE    => 'Active',
            self::COMPLETED => 'Completed',
            self::LOCKED    => 'Locked',
            self::REJECTED  => 'Rejected',
        );
    }

    public static function transitions()
    {
        return array(
            self::DRAFT     => array(self::SUBMITTED),
            self::SUBMITTED => array(self::APPROVED, self::REJECTED, self::DRAFT),
            self::APPROVED  => array(self::ACTIVE, self::DRAFT),
            self::ACTIVE    => array(self::COMPLETED),
            self::COMPLETED => array(self::LOCKED, self::ACTIVE),
            // Locked reopens only by a senior, handled as a separate gate below.
            self::LOCKED    => array(self::ACTIVE),
            self::REJECTED  => array(self::DRAFT),
        );
    }

    public static function canTransition($from, $to)
    {
        $t = self::transitions();
        if (!isset($t[(string) $from])) { return false; }
        return in_array((string) $to, $t[(string) $from], true);
    }

    /** States in which the target's numbers may still be edited in place. */
    public static function isEditable($status)
    {
        return in_array((string) $status, array(self::DRAFT, self::REJECTED), true);
    }

    /** States whose achievement figures are final. */
    public static function isFinal($status)
    {
        return in_array((string) $status, array(self::COMPLETED, self::LOCKED), true);
    }

    /* ---------------- gates ---------------- */

    /**
     * May $actorId move this target to $to?
     *
     * @param array $target    status, created_by, approved_by
     * @param array $assignees staff ids the target is set for
     * @param array $metrics   for the weight check on approval
     * @param array $opts      is_senior, reason
     */
    public static function canAct($target, $to, $actorId, $assignees = array(), $metrics = array(), $opts = array())
    {
        $t = (array) $target;
        $actorId = (int) $actorId;
        $from = (string) (isset($t['status']) ? $t['status'] : '');
        $reason = (string) (isset($opts['reason']) ? $opts['reason'] : '');

        if ($actorId <= 0) {
            return array('allowed' => false, 'code' => 'no_actor', 'reason' => 'An acting user must be identified.');
        }
        if (!self::canTransition($from, $to)) {
            return array('allowed' => false, 'code' => 'bad_transition',
                'reason' => 'A target cannot move from ' . ($from ?: '(none)') . ' to ' . $to . '.');
        }

        // an employee may never move their own target along
        if (self::isAssignee($actorId, $t, $assignees)) {
            return array('allowed' => false, 'code' => 'own_target',
                'reason' => 'This target is set for you, so you cannot change its status. '
                          . 'Ask your manager.');
        }

        if ($to === self::REJECTED && trim($reason) === '') {
            return array('allowed' => false, 'code' => 'reason_required',
                'reason' => 'A rejection must state why, so it can be acted on.');
        }
        if ($from === self::SUBMITTED && $to === self::DRAFT && trim($reason) === '') {
            return array('allowed' => false, 'code' => 'reason_required',
                'reason' => 'A revision request must say what needs changing.');
        }

        if ($to === self::APPROVED) {
            if ((int) (isset($t['created_by']) ? $t['created_by'] : 0) === $actorId) {
                return array('allowed' => false, 'code' => 'maker_is_approver',
                    'reason' => 'The person who created this target cannot approve it.');
            }
            $w = Kpi_weighting::validateWeights($metrics);
            if (!$w['ok']) {
                return array('allowed' => false, 'code' => 'bad_weights',
                    'reason' => 'Weights are not valid: ' . implode(' ', $w['errors']));
            }
        }

        // reopening a locked target is a senior-only act
        if ($from === self::LOCKED && $to === self::ACTIVE) {
            if (empty($opts['is_senior'])) {
                return array('allowed' => false, 'code' => 'senior_required',
                    'reason' => 'Only a senior manager can reopen a locked target.');
            }
            if (trim($reason) === '') {
                return array('allowed' => false, 'code' => 'reason_required',
                    'reason' => 'Reopening a locked target must be justified in writing.');
            }
        }

        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    /** Is this actor one of the people the target is set for? */
    public static function isAssignee($actorId, $target, $assignees = array())
    {
        $actorId = (int) $actorId;
        if ($actorId <= 0) { return false; }

        $t = (array) $target;
        if ((int) (isset($t['staff_id']) ? $t['staff_id'] : 0) === $actorId) { return true; }

        foreach ((array) $assignees as $a) {
            $id = is_array($a) || is_object($a)
                ? (int) (isset(((array) $a)['staff_id']) ? ((array) $a)['staff_id'] : 0)
                : (int) $a;
            if ($id === $actorId) { return true; }
        }
        return false;
    }

    /**
     * May this target be edited in place by this actor?
     * Anything past Draft/Rejected must be versioned instead.
     */
    public static function canEdit($target, $actorId, $assignees = array())
    {
        $t = (array) $target;
        $status = (string) (isset($t['status']) ? $t['status'] : '');

        if (self::isAssignee($actorId, $t, $assignees)) {
            return array('allowed' => false, 'code' => 'own_target',
                'reason' => 'This target is set for you, so you cannot edit it.');
        }
        if (!self::isEditable($status)) {
            return array('allowed' => false, 'code' => 'not_editable',
                'reason' => 'A ' . strtolower($status) . ' target cannot be edited directly. '
                          . 'Save the change as a new version so past results stay intact.');
        }
        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    /**
     * Build the successor version of a target that has already gone live.
     * The original is left exactly as it stands.
     */
    public static function nextVersion($target, $changes, $actorId = 0)
    {
        $t = (array) $target;
        $new = array_merge($t, (array) $changes);

        unset($new['id']);
        $new['version']      = (int) (isset($t['version']) ? $t['version'] : 1) + 1;
        $new['supersedes']   = (int) (isset($t['id']) ? $t['id'] : 0);
        $new['status']       = self::DRAFT;
        $new['created_by']   = (int) $actorId;
        $new['approved_by']  = null;
        $new['approved_at']  = null;
        return $new;
    }

    /* ---------------- creation ---------------- */

    /**
     * THE DEFECT THIS EXISTS TO CLOSE.
     *
     * Every guard in this module — maker-checker, the capability map, and the
     * overlap rule documented at the top of this file — lives behind
     * target_transition(). Sales_targets_model::add() never calls it and never
     * sets a status, so the column default takes over, and that default is
     * `Active`. A target was therefore BORN in the state the workflow exists to
     * gate, having passed through none of it.
     *
     * Measured on staging on 2026-09-11: all five targets carry
     * approved_by = NULL and submitted_by = NULL. Targets #4 and #5 are the same
     * staff member, the same period (2026-09-01 to 09-30) and the same value,
     * created seventeen minutes apart, both live — precisely the double-counting
     * rule 5 above was written to prevent. The overlap check never ran, because
     * it only runs on the way INTO Active and nothing ever travelled that road.
     *
     * A target now starts in Draft and reaches Active the way every other state
     * change happens. And the overlap rule is applied at creation as well, so a
     * duplicate is refused before it exists rather than at an activation step
     * that may never come.
     *
     * @param array $candidate staff_id, period_start, period_end, metrics[]
     * @param array $existing  every other target, each with metrics[]
     * @return array allowed, code, reason
     */
    public static function creationGate($candidate, array $existing)
    {
        $staffId = isset($candidate['staff_id']) ? (int) $candidate['staff_id'] : 0;
        if ($staffId <= 0) {
            return array('allowed' => false, 'code' => 'no_staff',
                         'reason' => 'A target must belong to a staff member.');
        }

        $from = isset($candidate['period_start']) ? trim((string) $candidate['period_start']) : '';
        $to   = isset($candidate['period_end'])   ? trim((string) $candidate['period_end'])   : '';
        if (!self::isDate($from) || !self::isDate($to)) {
            return array('allowed' => false, 'code' => 'bad_period',
                         'reason' => 'Both period dates are required, as YYYY-MM-DD.');
        }
        if ($from > $to) {
            return array('allowed' => false, 'code' => 'reversed_period',
                         'reason' => 'The period ends before it starts.');
        }

        $clashes = self::overlaps($candidate, $existing);
        if ($clashes) {
            $msgs = array();
            foreach ($clashes as $c) { $msgs[] = $c['message']; }
            return array('allowed' => false, 'code' => 'overlap',
                         'reason' => 'This duplicates a target that already exists. '
                                   . implode(' ', $msgs)
                                   . ' Two live targets on one metric and one period means the same '
                                   . 'achievement is counted twice and nobody can say which figure is real.');
        }

        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    /** The state a newly created target starts in. Never Active. */
    public static function initialState()
    {
        return self::DRAFT;
    }

    private static function isDate($v)
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v);
    }

    /**
     * May this target id be opened for editing at all?
     *
     * /sales_targets/manage/9999 rendered a blank "Edit Sales Target" form for a
     * target that does not exist, and a Cancelled target opened for editing with
     * nothing saying it was cancelled. Perfex core refuses an unknown staff id
     * correctly; this module did not.
     *
     * @return array ok, code, message
     */
    public static function editGate($id, $row)
    {
        $raw = trim((string) $id);
        if ($raw === '') { return array('ok' => true, 'code' => 'new', 'message' => ''); }
        if (!ctype_digit($raw) || (int) $raw <= 0) {
            return array('ok' => false, 'code' => 'bad_id',
                         'message' => 'That is not a target reference.');
        }
        if (!is_array($row) || empty($row)) {
            return array('ok' => false, 'code' => 'not_found',
                         'message' => 'Target #' . (int) $raw . ' does not exist. Nothing was opened '
                                    . 'and nothing was saved.');
        }
        $status = isset($row['status']) ? (string) $row['status'] : '';
        if (in_array($status, array('Cancelled', self::LOCKED, self::COMPLETED), true)) {
            return array('ok' => false, 'code' => 'closed',
                         'message' => 'Target #' . (int) $raw . ' is ' . strtolower($status)
                                    . ' and cannot be edited. Its figures are part of the record.');
        }
        return array('ok' => true, 'code' => 'ok', 'message' => '');
    }

    /* ---------------- overlap ---------------- */

    /**
     * Would this target overlap an existing live one for the same person and the
     * same KPI? Checked before activation, because two live targets on one metric
     * double-count achievement.
     *
     * @param array $candidate staff_id, period_start, period_end, metrics[], id
     * @param array $existing  the same shape, for other targets
     */
    public static function overlaps($candidate, $existing)
    {
        $c = (array) $candidate;
        $out = array();

        $cKpis = self::kpiKeysOf($c);
        $cStaff = (int) (isset($c['staff_id']) ? $c['staff_id'] : 0);

        foreach ((array) $existing as $e) {
            $e = (array) $e;

            if ((int) (isset($e['id']) ? $e['id'] : 0) === (int) (isset($c['id']) ? $c['id'] : -1)) { continue; }
            if (!in_array((string) (isset($e['status']) ? $e['status'] : ''),
                array(self::ACTIVE, self::APPROVED), true)) { continue; }
            if ((int) (isset($e['staff_id']) ? $e['staff_id'] : 0) !== $cStaff) { continue; }
            if (!self::datesOverlap($c, $e)) { continue; }

            $shared = array_values(array_intersect($cKpis, self::kpiKeysOf($e)));
            if (!$shared) { continue; }

            $out[] = array(
                'target_id' => (int) (isset($e['id']) ? $e['id'] : 0),
                'kpis'      => $shared,
                'message'   => 'Overlaps target #' . (int) (isset($e['id']) ? $e['id'] : 0)
                             . ' for the same staff member on ' . implode(', ', $shared)
                             . '. Two live targets on one KPI would count the same achievement twice.',
            );
        }
        return $out;
    }

    private static function kpiKeysOf($target)
    {
        $t = (array) $target;
        $out = array();
        foreach ((array) (isset($t['metrics']) ? $t['metrics'] : array()) as $m) {
            $m = (array) $m;
            if (!empty($m['kpi_key'])) { $out[] = (string) $m['kpi_key']; }
        }
        return $out;
    }

    private static function datesOverlap($a, $b)
    {
        $as = isset($a['period_start']) ? strtotime((string) $a['period_start']) : false;
        $ae = isset($a['period_end'])   ? strtotime((string) $a['period_end'])   : false;
        $bs = isset($b['period_start']) ? strtotime((string) $b['period_start']) : false;
        $be = isset($b['period_end'])   ? strtotime((string) $b['period_end'])   : false;

        if ($as === false || $ae === false || $bs === false || $be === false) { return false; }
        return $as <= $be && $bs <= $ae;
    }

    /* ---------------- validation ---------------- */

    public static function validate($target, $metrics)
    {
        $t = (array) $target;
        $e = array();

        if ((int) (isset($t['staff_id']) ? $t['staff_id'] : 0) <= 0) {
            $e[] = 'A target must be assigned to a staff member.';
        }

        $s = isset($t['period_start']) ? strtotime((string) $t['period_start']) : false;
        $en = isset($t['period_end'])  ? strtotime((string) $t['period_end'])   : false;
        if ($s === false)  { $e[] = 'Period start is not a valid date.'; }
        if ($en === false) { $e[] = 'Period end is not a valid date.'; }
        if ($s !== false && $en !== false && $en < $s) {
            $e[] = 'Period end cannot be before period start.';
        }

        $w = Kpi_weighting::validateWeights($metrics);
        foreach ($w['errors'] as $we) { $e[] = $we; }

        foreach ((array) $metrics as $i => $m) {
            $m = (array) $m;
            $n = $i + 1;
            if (!isset($m['target_value']) || !is_numeric($m['target_value'])) {
                $e[] = 'KPI ' . $n . ' has no target value.';
            } elseif ((float) $m['target_value'] <= 0) {
                $e[] = 'KPI ' . $n . ' has a target value of zero or less.';
            }
            if (!empty($m['kpi_key']) && class_exists('Kpi_catalog') && !Kpi_catalog::exists($m['kpi_key'])) {
                $e[] = 'KPI "' . $m['kpi_key'] . '" is not a known KPI.';
            }
        }

        return array('ok' => empty($e), 'errors' => $e);
    }

    public static function statusClass($status)
    {
        switch ((string) $status) {
            case self::ACTIVE:    return 'success';
            case self::APPROVED:  return 'info';
            case self::SUBMITTED: return 'warning';
            case self::COMPLETED: return 'primary';
            case self::LOCKED:    return 'default';
            case self::REJECTED:  return 'danger';
            default:              return 'default';
        }
    }
}
