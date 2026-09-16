<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_profile_select — which API connection spends, decided the same way
 * every time.
 *
 * WHY THIS IS A SEPARATE, PURE CLASS
 * ----------------------------------
 * Choosing a profile is choosing whose billing account is charged. That decision
 * needs to be reproducible from the inputs alone — "which key paid for this
 * search" has to be answerable a month later — and it needs to be impossible to
 * answer with a key the employee was never granted.
 *
 * THE RULE, IN ORDER
 * ------------------
 *   1. If the employee named a profile, it must be in their assigned set. If it
 *      is not, **refuse**. There is no substitution.
 *   2. If they named nothing, take the eligible set and pick the **lowest id**.
 *      Lowest id rather than "first returned" because a query without an
 *      ORDER BY has no defined order, and a selection that depends on how MySQL
 *      felt is not auditable.
 *   3. If the eligible set is empty, **refuse**. Never widen the set.
 *
 * Eligibility is: assigned to this employee, active, and inside its effective
 * window. A profile fails eligibility for exactly one named reason, so the
 * refusal can say which.
 *
 * NO FALLBACK. EVER.
 * ------------------
 * The tempting behaviour is "if their profile is unavailable, use the default
 * one so the search still works". That is the defect. It spends money on a key
 * nobody granted them, it attributes the cost to the wrong project, and — worst
 * — it makes an unassignment silently ineffective, so revoking someone's access
 * to an expensive key does nothing. A refused search is a visible, cheap,
 * correct outcome. `NO_FALLBACK` is asserted by a test that hands the selector a
 * single unassigned profile and requires a refusal rather than that profile.
 */
class Leadfinder_profile_select
{
    /* Outcomes. */
    const OK               = 'ok';
    const NOT_ASSIGNED     = 'profile_not_assigned';
    const NONE_ELIGIBLE    = 'no_eligible_profile';
    const INACTIVE         = 'profile_inactive';
    const NOT_YET_EFFECTIVE = 'profile_not_yet_effective';
    const EXPIRED          = 'profile_expired';

    /** This selector never substitutes. Stated as code so a test can assert it. */
    const NO_FALLBACK = true;

    /**
     * Is one profile usable by this employee today?
     *
     * `$profile` keys: id, active, effective_from, expires_on.
     * `$today` is `Y-m-d` in the CRM timezone — passed in, never taken here, so
     * the answer does not depend on which clock this class happens to read.
     *
     * Returns OK or the single reason it is not usable.
     */
    public static function eligibility(array $profile, array $assignedIds, $today)
    {
        $id = isset($profile['id']) ? (int) $profile['id'] : 0;

        if ($id <= 0 || !in_array($id, self::intIds($assignedIds), true)) {
            return self::NOT_ASSIGNED;
        }

        if ((int) (isset($profile['active']) ? $profile['active'] : 0) !== 1) {
            return self::INACTIVE;
        }

        $from = self::dateOrNull(isset($profile['effective_from']) ? $profile['effective_from'] : null);

        if ($from !== null && $today < $from) {
            return self::NOT_YET_EFFECTIVE;
        }

        $until = self::dateOrNull(isset($profile['expires_on']) ? $profile['expires_on'] : null);

        /*
         * `expires_on` is the last usable day, not the first unusable one. An
         * off-by-one here silently ends a key a day early or runs it a day past
         * an agreed window, and neither is visible until someone checks a bill.
         */
        if ($until !== null && $today > $until) {
            return self::EXPIRED;
        }

        return self::OK;
    }

    /**
     * Pick the profile for this search.
     *
     * @param int   $requestedId 0 when the employee chose nothing
     * @param array $profiles    every profile row the caller loaded
     * @param array $assignedIds ids assigned to this employee
     * @param string $today      Y-m-d in the CRM timezone
     *
     * @return array decision, profile_id, reason
     */
    public static function choose($requestedId, array $profiles, array $assignedIds, $today)
    {
        $requestedId = (int) $requestedId;
        $byId        = array();

        foreach ($profiles as $p) {
            $pid = isset($p['id']) ? (int) $p['id'] : 0;
            if ($pid > 0) { $byId[$pid] = $p; }
        }

        /* 1. An explicit choice is honoured or refused. Never redirected. */
        if ($requestedId > 0) {
            if (!isset($byId[$requestedId])) {
                return self::result(self::NOT_ASSIGNED, null);
            }

            $e = self::eligibility($byId[$requestedId], $assignedIds, $today);

            return $e === self::OK
                ? self::result(self::OK, $requestedId)
                : self::result($e, null);
        }

        /* 2. No choice: the lowest ELIGIBLE id, so the answer is reproducible. */
        $eligible = array();

        foreach ($byId as $pid => $p) {
            if (self::eligibility($p, $assignedIds, $today) === self::OK) {
                $eligible[] = $pid;
            }
        }

        if (empty($eligible)) {
            return self::result(self::NONE_ELIGIBLE, null);
        }

        sort($eligible, SORT_NUMERIC);

        return self::result(self::OK, $eligible[0]);
    }

    /** The operator-facing sentence. Names no id and no key. */
    public static function message($decision)
    {
        switch ($decision) {
            case self::NOT_ASSIGNED:
                return 'That API connection is not assigned to you.';
            case self::INACTIVE:
                return 'That API connection is inactive.';
            case self::NOT_YET_EFFECTIVE:
                return 'That API connection is not effective yet.';
            case self::EXPIRED:
                return 'That API connection has expired.';
            case self::NONE_ELIGIBLE:
                return 'You have no usable API connection. An administrator must assign one.';
            default:
                return '';
        }
    }

    /**
     * The per-employee ceiling for a profile.
     *
     * The profile's own value wins when set; the global config is the fallback
     * for profiles that predate the column. Zero at both levels means "not set",
     * which the quota layer treats as no per-staff ceiling — the monthly ceiling
     * is still mandatory there, so this cannot open an uncapped account.
     */
    public static function perStaffLimit(array $profile, $configDefault)
    {
        $own = isset($profile['per_staff_daily_limit']) ? (int) $profile['per_staff_daily_limit'] : 0;

        return $own > 0 ? $own : (int) $configDefault;
    }

    private static function intIds($ids)
    {
        $out = array();

        foreach ((array) $ids as $i) {
            if (is_int($i) || (is_string($i) && preg_match('/\A\d+\z/', trim($i)))) {
                $n = (int) $i;
                if ($n > 0) { $out[] = $n; }
            }
        }

        return $out;
    }

    /**
     * A real `Y-m-d`, or null.
     *
     * The shape check is not enough on its own. `2026-13-45` matches
     * `\d{4}-\d{2}-\d{2}` perfectly, and because these dates are compared as
     * strings it sorts *after* every real date — so a profile carrying it read
     * as "not effective until some time in the thirteenth month", i.e.
     * permanently unusable, with no error anywhere. The test caught it; the
     * shape-only version had already passed the seven other junk values.
     *
     * `checkdate()` is the part that makes a well-formed nonsense date null
     * rather than a date in the far future.
     */
    /**
     * Public alias, so the SAVE path validates with the same rule the READ path
     * applies.
     *
     * Found while wiring Step 4: this check lived here and was consulted only
     * when a profile was read. The save path stored whatever was typed, so
     * `2026-02-31` was accepted, shown back as accepted, and then silently
     * treated as "no date set" by every reader. Rejecting a value on read is not
     * the same as rejecting it — the administrator has to be told while they can
     * still fix it.
     */
    public static function validDate($v)
    {
        return self::dateOrNull($v);
    }

    private static function dateOrNull($v)
    {
        if (!is_string($v)) { return null; }

        $v = trim($v);

        if ($v === '' || $v === '0000-00-00') { return null; }

        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $v, $m) !== 1) { return null; }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }

    private static function result($decision, $profileId)
    {
        return array(
            'decision'   => $decision,
            'profile_id' => $profileId === null ? null : (int) $profileId,
            'reason'     => $decision === self::OK ? null : $decision,
        );
    }
}
