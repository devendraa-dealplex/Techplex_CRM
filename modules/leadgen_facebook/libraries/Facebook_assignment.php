<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Who a new Facebook lead is assigned to.
 *
 * WHAT WAS THERE BEFORE: NOTHING
 * ------------------------------
 * The insert set no `assigned` value at all, so every Facebook lead was
 * created against staff id 0 — nobody. Combined with a status of "Cold" this is
 * why the integration "did not show": the leads were not in a column anybody
 * watches and were not on anybody's list.
 *
 * The obvious fix is to hand them to the round-robin module. That module —
 * `leadgen_distribution` — is **disabled and has never loaded**: its folder and
 * bootstrap filename do not match, so Perfex never requires it, its hooks are
 * never registered, and its `tblmodules.active = 1` row is orphaned metadata.
 * Its last automatic assignment ran on 2026-09-06. Depending on it would mean
 * depending on something that does not run.
 *
 * So assignment is decided here, in three modes, and the third one is the
 * important one:
 *
 *   unassigned   deliberately nobody — but the lead goes into a **visible
 *                review queue** and an administrator is alerted, rather than
 *                silently sitting at staff 0
 *   fixed        one named sales employee or team inbox
 *   round_robin  a configured pool, rotated deterministically
 *
 * THE RULE THAT MATTERS MOST
 * --------------------------
 * When a mode is configured but cannot be satisfied — the named assignee has
 * left, the pool is empty, every pool member is inactive — the answer is NOT
 * "fall back to somebody who is available". Handing a lead to an arbitrary
 * person because the intended one is gone is how leads end up with someone who
 * does not know they own them. The answer is the review queue, with the reason
 * recorded, so a human decides.
 *
 * Pure functions: the caller supplies the candidate staff list and the last
 * assigned id, and gets back a decision. No database, no options.
 */
class Facebook_assignment
{
    const MODE_UNASSIGNED = 'unassigned';
    const MODE_FIXED = 'fixed';
    const MODE_ROUND_ROBIN = 'round_robin';

    /* ---- decisions ---- */

    /** Assigned to a specific person. */
    const ASSIGNED = 'assigned';
    /** Nobody, by configuration. Goes to the review queue. */
    const REVIEW_BY_CONFIG = 'review_unassigned_mode';
    /** Nobody, because the configuration could not be satisfied. */
    const REVIEW_NO_VALID_ASSIGNEE = 'review_no_valid_assignee';
    /** Nobody, because the configured mode is not one this class knows. */
    const REVIEW_BAD_MODE = 'review_unrecognised_mode';

    /**
     * Reasons the review queue was used. Every one of these must alert an
     * administrator, because each means a lead has arrived that nobody owns.
     */
    const REVIEW_DECISIONS = array(
        self::REVIEW_BY_CONFIG,
        self::REVIEW_NO_VALID_ASSIGNEE,
        self::REVIEW_BAD_MODE,
    );

    /**
     * Decide the assignee.
     *
     * @param string $mode         one of the MODE_* constants
     * @param mixed  $fixed        configured default assignee id
     * @param array  $pool         configured round-robin staff ids
     * @param mixed  $lastAssigned the staff id the previous lead went to
     * @param array  $eligible     staff ids that may currently receive a lead
     *                             (active, not the "not staff" placeholder)
     *
     * @return array staff_id (int, 0 = nobody), decision, reason, needs_review (bool)
     */
    public static function decide($mode, $fixed, array $pool, $lastAssigned, array $eligible)
    {
        $eligible = self::validIds($eligible);

        if ($mode === self::MODE_UNASSIGNED) {
            return self::review(self::REVIEW_BY_CONFIG,
                'Assignment mode is "unassigned"; lead queued for manual allocation.');
        }

        if ($mode === self::MODE_FIXED) {
            $id = self::oneId($fixed);

            if ($id === null) {
                return self::review(self::REVIEW_NO_VALID_ASSIGNEE,
                    'Assignment mode is "fixed" but no default assignee is configured.');
            }

            if (!in_array($id, $eligible, true)) {
                /*
                 * The configured person exists in the setting and not in the
                 * eligible list: deactivated, deleted, or never a staff member.
                 * This is the case that must not silently redirect.
                 */
                return self::review(self::REVIEW_NO_VALID_ASSIGNEE,
                    'The configured default assignee is not an active staff member.');
            }

            return array('staff_id' => $id, 'decision' => self::ASSIGNED,
                         'reason' => 'Fixed assignee.', 'needs_review' => false);
        }

        if ($mode === self::MODE_ROUND_ROBIN) {
            $candidates = array();

            foreach (self::validIds($pool) as $id) {
                if (in_array($id, $eligible, true)) {
                    $candidates[] = $id;
                }
            }

            if (empty($candidates)) {
                return self::review(self::REVIEW_NO_VALID_ASSIGNEE,
                    'Round-robin is configured but no pool member is an active staff member.');
            }

            sort($candidates);

            /*
             * The next id above the last one used, wrapping to the lowest.
             *
             * Deterministic and stateless apart from `lastAssigned`, which is
             * read from and written to one option row. Two deliveries arriving
             * together can therefore both read the same "last" value and both
             * pick the same person — the rotation slips by one, which is a
             * fairness question, not a correctness one. It is emphatically NOT
             * a duplicate-lead risk: that is prevented by the unique claim on
             * the delivery reference, not by this.
             */
            $last = self::oneId($lastAssigned);

            if ($last !== null) {
                foreach ($candidates as $id) {
                    if ($id > $last) {
                        return array('staff_id' => $id, 'decision' => self::ASSIGNED,
                                     'reason' => 'Round-robin.', 'needs_review' => false);
                    }
                }
            }

            return array('staff_id' => $candidates[0], 'decision' => self::ASSIGNED,
                         'reason' => 'Round-robin (wrapped).', 'needs_review' => false);
        }

        return self::review(self::REVIEW_BAD_MODE,
            'Configured assignment mode is not recognised; lead queued for review.');
    }

    private static function review($decision, $reason)
    {
        return array('staff_id' => 0, 'decision' => $decision,
                     'reason' => $reason, 'needs_review' => true);
    }

    /**
     * A single staff id, or null.
     *
     * Validated, never cast. `(int) '7abc' === 7`, so a setting that picked up
     * stray characters would otherwise hand leads to staff 7. Zero and negative
     * values are not ids; zero is Perfex's "nobody", which is what the review
     * queue is for and must not arrive here disguised as a person.
     */
    public static function oneId($value)
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        $v = trim((string) $value);

        return preg_match('/\A[1-9][0-9]*\z/', $v) === 1 ? (int) $v : null;
    }

    /** The same validation over a list, de-duplicated and sorted. */
    public static function validIds(array $values)
    {
        $out = array();

        foreach ($values as $v) {
            $id = self::oneId($v);

            if ($id !== null && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        sort($out);

        return $out;
    }

    public static function needsReview(array $decision)
    {
        return !empty($decision['needs_review'])
            || in_array(isset($decision['decision']) ? $decision['decision'] : '',
                        self::REVIEW_DECISIONS, true);
    }
}
