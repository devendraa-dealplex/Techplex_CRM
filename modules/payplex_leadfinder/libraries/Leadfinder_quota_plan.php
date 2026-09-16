<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_quota_plan — which ceilings apply to one request, in what order
 * they must be locked, and what the operator should be told about what is left.
 *
 * WHAT THE AUDIT FOUND, AND WHY THIS CLASS EXISTS
 * ----------------------------------------------
 * `Leadfinder_model::reserveQuota()` carried a long comment explaining that
 * check-then-call had been replaced by reserve-then-call, and that the race was
 * therefore closed. It was not. The method reads the profile, reads the usage
 * totals, evaluates the ceilings, and *then* increments — four statements, no
 * transaction, no row lock. `grep -rn 'trans_begin\|FOR UPDATE' models/ libraries/`
 * returned nothing across the whole module.
 *
 * The single-statement `INSERT … ON DUPLICATE KEY UPDATE` in `recordUsage()`
 * makes the *counter arithmetic* safe: two increments never lose one another.
 * It does nothing about the *ceiling*, because the comparison happened earlier,
 * against a value that was already stale by the time the increment landed. Two
 * employees searching at 19 of 20 both read 19, both evaluate OK, and the
 * counter ends at 21. An atomic increment past a limit is still past the limit.
 *
 * So the check and the increment have to happen under the same lock. That is a
 * model concern — it needs a transaction and `SELECT … FOR UPDATE`. This class
 * is the part that needs no database at all: given a request, it says which
 * ceilings exist, in which deterministic order they must be taken, and what the
 * numbers mean. Keeping it free of I/O is what lets the concurrency rules be
 * tested without a database, which is the only reason those tests exist.
 *
 * WHY LOCK ORDER IS A CORRECTNESS PROPERTY, NOT A DETAIL
 * -----------------------------------------------------
 * A request is capped by up to six ceilings at once: global and profile and
 * per-employee, each daily and monthly. If one request locks the profile row
 * then the staff row, and another locks the staff row then the profile row,
 * InnoDB detects the cycle and kills one of them — under load, at random, with
 * a deadlock error the employee sees as "search failed". Every caller taking
 * the rows in the same ascending key order makes the cycle impossible rather
 * than merely unlikely.
 *
 * WHAT IS DELIBERATELY NOT HERE
 * -----------------------------
 * Google's prices. They change, this environment cannot reach Google's pricing
 * page, and a wrong number on a spending screen is worse than no number. Rates
 * are administrator-configured; an unconfigured rate yields null, and the view
 * must print "not configured" rather than a zero that reads as "free".
 */
class Leadfinder_quota_plan
{
    /* Scopes, widest first. The order of these constants is not the lock order. */
    const SCOPE_GLOBAL  = 'global';
    const SCOPE_PROFILE = 'profile';
    const SCOPE_STAFF   = 'staff';

    const PERIOD_DAY   = 'day';
    const PERIOD_MONTH = 'month';

    /**
     * The one ceiling that must be configured.
     *
     * Migration 106's brief said "never permit automatic paid overage". An
     * unconfigured ceiling at every scope would mean no ceiling at all, so one
     * of them has to be mandatory, and the profile's monthly limit is the right
     * one: it is per-key, an administrator sets it when they add the key, and it
     * bounds the month no matter how the daily and per-employee limits are left.
     *
     * The others may be 0, meaning "no ceiling at this scope" — which is safe
     * precisely because the mandatory one is still there underneath.
     */
    const MANDATORY = 'profile_monthly';

    /**
     * Period key for a ceiling.
     *
     * `$crmDate` is `Y-m-d` in the CRM's timezone, produced by
     * `Leadfinder_model::crmDate()`. It is never PHP's ambient date and never
     * MySQL's `CURDATE()`: on this host the database session runs 12h30m behind
     * the CRM clock, so for half of every day those three disagree about which
     * day it is, and a daily ceiling keyed on the wrong one resets at the wrong
     * midnight.
     */
    public static function periodKey($periodKind, $crmDate)
    {
        $d = (string) $crmDate;

        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $d, $m)) {
            return null;
        }

        /*
         * Shape is not validity.
         *
         * The first version stopped at the regex, so `2026-13-45` produced the
         * period key `2026-13-45` and a thirteenth month quietly got its own
         * counter row — a month with its own fresh allowance that no ceiling
         * would ever be reconciled against. `checkdate()` is the same rule
         * `Leadfinder_profile_select::validDate()` applies to `effective_from`
         * and `expires_on`, and for the same reason: a date that cannot exist
         * must be refused where it enters, not interpreted later.
         */
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $periodKind === self::PERIOD_MONTH ? substr($d, 0, 7) : $d;
    }

    /**
     * Every ceiling that applies to one request of `$requestClass`.
     *
     * `$limits` keys, all optional except the mandatory one:
     *   global_daily, global_monthly, profile_daily, profile_monthly,
     *   staff_daily, staff_monthly
     *
     * A ceiling whose limit is 0 or less is omitted entirely — with the single
     * exception of the mandatory one, which is returned with `limit => 0` so the
     * caller can refuse the request and say why. Silently omitting it would turn
     * a blank limit field into an uncapped spending account, which is the exact
     * failure this module was written to prevent.
     *
     * Returns a list; use `sortForLocking()` before taking any lock.
     */
    public static function ceilings($requestClass, $profileId, $staffId, $crmDate, array $limits)
    {
        $day   = self::periodKey(self::PERIOD_DAY, $crmDate);
        $month = self::periodKey(self::PERIOD_MONTH, $crmDate);

        if ($day === null || $month === null) {
            return array();
        }

        $spec = array(
            array('global_daily',    self::SCOPE_GLOBAL,  0,               self::PERIOD_DAY,   $day,   'Installation daily limit'),
            array('global_monthly',  self::SCOPE_GLOBAL,  0,               self::PERIOD_MONTH, $month, 'Installation monthly limit'),
            array('profile_daily',   self::SCOPE_PROFILE, (int) $profileId, self::PERIOD_DAY,   $day,   'This connection\'s daily limit'),
            array('profile_monthly', self::SCOPE_PROFILE, (int) $profileId, self::PERIOD_MONTH, $month, 'This connection\'s monthly limit'),
            array('staff_daily',     self::SCOPE_STAFF,   (int) $staffId,  self::PERIOD_DAY,   $day,   'Your daily limit'),
            array('staff_monthly',   self::SCOPE_STAFF,   (int) $staffId,  self::PERIOD_MONTH, $month, 'Your monthly limit'),
        );

        $out = array();

        foreach ($spec as $s) {
            list($name, $scope, $scopeId, $periodKind, $periodKey, $label) = $s;

            $limit = isset($limits[$name]) ? (int) $limits[$name] : 0;

            /* A per-employee ceiling with no employee is not a ceiling. */
            if ($scope === self::SCOPE_STAFF && (int) $staffId <= 0) {
                continue;
            }

            if ($limit <= 0 && $name !== self::MANDATORY) {
                continue;
            }

            $out[] = array(
                'name'          => $name,
                'scope'         => $scope,
                'scope_id'      => (int) $scopeId,
                'period_kind'   => $periodKind,
                'period_key'    => $periodKey,
                'request_class' => (string) $requestClass,
                'limit'         => $limit,
                'label'         => $label,
                'mandatory'     => $name === self::MANDATORY,
            );
        }

        return self::sortForLocking($out);
    }

    /**
     * The identity of one counter row. Also the lock ordering key.
     *
     * Deliberately a single string: comparing six tuples field by field in
     * several places is how two call sites end up ordering differently, and
     * "two call sites disagree about the order" is the deadlock.
     */
    public static function lockKey(array $c)
    {
        return implode('|', array(
            isset($c['scope']) ? $c['scope'] : '',
            (int) (isset($c['scope_id']) ? $c['scope_id'] : 0),
            isset($c['period_kind']) ? $c['period_kind'] : '',
            isset($c['period_key']) ? $c['period_key'] : '',
            isset($c['request_class']) ? $c['request_class'] : '',
        ));
    }

    /**
     * Ascending by lock key, stable and total.
     *
     * `strcmp`, not a locale comparison: the order has to be identical in every
     * process on every host, and a locale-aware collation is neither.
     */
    public static function sortForLocking(array $ceilings)
    {
        usort($ceilings, function ($a, $b) {
            return strcmp(Leadfinder_quota_plan::lockKey($a), Leadfinder_quota_plan::lockKey($b));
        });

        return $ceilings;
    }

    /**
     * The first ceiling that refuses this request, or null if all of them allow it.
     *
     * `$used` maps lock key → current count. A ceiling missing from `$used` is
     * treated as 0 used, which is correct for a counter row that does not exist
     * yet.
     *
     * The mandatory ceiling with a limit of 0 refuses, and says so as
     * "not configured" rather than "reached" — an administrator who has set no
     * limit needs to be told that, not told they are out of quota.
     */
    public static function firstRefusal(array $ceilings, array $used, $units = 1)
    {
        $units = max(1, (int) $units);

        foreach ($ceilings as $c) {
            $key = self::lockKey($c);
            $u   = isset($used[$key]) ? (int) $used[$key] : 0;

            if ($c['mandatory'] && (int) $c['limit'] <= 0) {
                return array('ceiling' => $c, 'used' => $u, 'reason' => 'not_configured');
            }

            if ((int) $c['limit'] > 0 && ($u + $units) > (int) $c['limit']) {
                return array('ceiling' => $c, 'used' => $u, 'reason' => 'reached');
            }
        }

        return null;
    }

    /**
     * How many calls are left under a ceiling. Null when there is no ceiling.
     *
     * Never negative: a counter that somehow ran past its limit reads as 0 left,
     * not as a negative allowance that a careless `if ($left)` would treat as
     * truthy.
     */
    public static function remaining($used, $limit)
    {
        $limit = (int) $limit;

        if ($limit <= 0) {
            return null;
        }

        return max(0, $limit - (int) $used);
    }

    /**
     * The tightest ceiling — the one that will actually stop the employee.
     *
     * Showing six numbers and letting the operator work out which one binds is
     * how a spending screen gets ignored. Null when nothing is configured.
     */
    public static function tightest(array $ceilings, array $used)
    {
        $best = null;

        foreach ($ceilings as $c) {
            $left = self::remaining(isset($used[self::lockKey($c)]) ? $used[self::lockKey($c)] : 0, $c['limit']);

            if ($left === null) {
                continue;
            }

            if ($best === null || $left < $best['remaining']) {
                $best = array(
                    'ceiling'   => $c,
                    'remaining' => $left,
                    'used'      => isset($used[self::lockKey($c)]) ? (int) $used[self::lockKey($c)] : 0,
                );
            }
        }

        return $best;
    }

    /**
     * Which alert bands this increment crossed.
     *
     * Crossed, not "is at". An alert that fires whenever usage is above 80%
     * fires on every request for the rest of the month, which trains the
     * recipient to filter it — and then the 100% alert is filtered too. Firing
     * on the transition means each band is announced once per period.
     *
     * Returns the bands in ascending order, so a single request that jumps from
     * 79% to 100% reports all three rather than only the highest.
     */
    public static function bandsCrossed($usedBefore, $usedAfter, $limit)
    {
        $limit = (int) $limit;

        if ($limit <= 0) {
            return array();
        }

        $out = array();

        foreach (array(Leadfinder_quota::WARN_AT, Leadfinder_quota::CRITICAL_AT, Leadfinder_quota::STOP_AT) as $band) {
            $threshold = ($band / 100) * $limit;

            if ((int) $usedBefore < $threshold && (int) $usedAfter >= $threshold) {
                $out[] = $band;
            }
        }

        return $out;
    }

    /**
     * Estimated spend, or null when the rate is not configured.
     *
     * Null is the point. Google's per-call prices change and this environment
     * cannot read them; a hard-coded rate would be wrong within a quarter and
     * would be believed because it appears on a screen labelled "cost". An
     * administrator who wants the figure enters the rate their contract gives
     * them, and until they do the screen says so.
     *
     * Rates are held in the smallest currency unit (paise, cents) as integers.
     * Floats are not used for money anywhere in this path.
     */
    public static function estimateMinorUnits($requestClass, $calls, array $rates)
    {
        $key = $requestClass === Leadfinder_fieldmask::CLASS_CONTACT
             ? 'contact' : 'search';

        if (!isset($rates[$key])) {
            return null;
        }

        $rate = (int) $rates[$key];

        if ($rate <= 0) {
            return null;
        }

        return $rate * max(0, (int) $calls);
    }

    /** "1,234.50" from minor units, or null. Never a currency symbol — the view owns that. */
    public static function formatMinorUnits($minor)
    {
        if ($minor === null) {
            return null;
        }

        return number_format(((int) $minor) / 100, 2, '.', ',');
    }

    /**
     * The sentence shown beside the search button.
     *
     * Says what is left and under which ceiling, because "18 remaining" without
     * "of your daily limit" sends the employee to an administrator who then has
     * to work out which of six numbers moved.
     */
    public static function allowanceLine(array $ceilings, array $used)
    {
        $t = self::tightest($ceilings, $used);

        if ($t === null) {
            return 'No spending ceiling is configured for this connection, so searching is blocked.';
        }

        return $t['remaining'] . ' of ' . (int) $t['ceiling']['limit']
             . ' remaining — ' . strtolower($t['ceiling']['label']) . '.';
    }
}
