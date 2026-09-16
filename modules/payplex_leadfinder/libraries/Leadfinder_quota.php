<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_quota — may this request be made, and what should the operator be
 * told about it.
 *
 * THE STATE THIS REPLACES
 * -----------------------
 * `usageFor()`, `staffCallsToday()` and `recordUsage()` all
 * existed in the model and **no controller called any of them**. The profile
 * carried 20 search / 10 detail / 30 daily and a per-staff limit of 10, all
 * stored, all readable, none consulted. A limit nothing reads is not a limit;
 * it is a number in a database that makes an administrator believe they have
 * capped spending.
 *
 * WHY RESERVATION AND NOT "CHECK, THEN CALL"
 * ------------------------------------------
 * Check-then-call is the same race this workspace has already paid for twice —
 * the follow-up stage claim, and the webhook insert. Two employees searching at
 * the same moment both read "19 of 20 used" and both proceed, and the ceiling
 * the administrator set is exceeded by exactly as many requests as happen to
 * overlap. Google bills for every one of them.
 *
 * So the counter is incremented **before** the request goes out, and released if
 * the request never reached Google. That direction is deliberate: the failure
 * mode of reserving first is under-counting the ceiling by one on a crash, which
 * costs nothing. The failure mode of counting afterwards is paid overage.
 *
 * `release()` is only ever correct for a request that did not reach Google —
 * a connection failure, a refused key. An HTTP 400 from Google is a *billed*
 * request on some SKUs and is not released; `isBillable()` draws that line and
 * a test pins both sides of it.
 *
 * This class holds no state and no I/O. It answers questions about numbers.
 */
class Leadfinder_quota
{
    /* Request kinds. These map to the SKU tiers in Leadfinder_fieldmask. */
    const KIND_SEARCH = 'search';
    const KIND_DETAIL = 'detail';

    /* Decisions. */
    const OK              = 'ok';
    const BLOCK_NO_KEY    = 'no_key';
    const BLOCK_INACTIVE  = 'profile_inactive';
    const BLOCK_MONTHLY   = 'monthly_limit_reached';
    const BLOCK_DAILY     = 'daily_limit_reached';
    const BLOCK_PER_STAFF = 'staff_daily_limit_reached';
    const BLOCK_NO_LIMIT  = 'limit_not_configured';

    /* Warning bands. Percentages of a ceiling, not business rates. */
    const WARN_AT     = 80;
    const CRITICAL_AT = 90;
    const STOP_AT     = 100;

    /**
     * Percentage of a ceiling consumed, 0-100+.
     *
     * An unconfigured or non-positive ceiling returns null rather than 0 or 100.
     * Both of those would be a lie: 0 reads as "plenty left" and 100 as
     * "exhausted", and the truth is "there is no ceiling here to measure
     * against". Callers must handle null, and a test asserts they do.
     */
    public static function percentUsed($used, $limit)
    {
        $limit = (int) $limit;

        if ($limit <= 0) {
            return null;
        }

        return (int) floor(((int) $used / $limit) * 100);
    }

    /** none | warning | critical | exhausted */
    public static function band($used, $limit)
    {
        $pct = self::percentUsed($used, $limit);

        if ($pct === null) {
            return 'none';
        }

        if ($pct >= self::STOP_AT)     { return 'exhausted'; }
        if ($pct >= self::CRITICAL_AT) { return 'critical'; }
        if ($pct >= self::WARN_AT)     { return 'warning'; }

        return 'none';
    }

    /**
     * May one request of `$kind` be made?
     *
     * Every ceiling is checked, and the FIRST one that blocks is reported — so
     * the message names the limit that actually stopped it rather than the last
     * one evaluated.
     *
     * A missing key blocks before any counter is read: there is no point
     * reserving quota for a request that cannot be sent.
     *
     * `$limits` keys: monthly_search, monthly_detail, daily, per_staff_daily.
     * `$used` keys:   month_search, month_detail, today_all, today_staff.
     */
    public static function evaluate($kind, array $limits, array $used, $hasKey, $active)
    {
        if (!$hasKey) {
            return self::decision(self::BLOCK_NO_KEY, null, null, null);
        }

        if (!$active) {
            return self::decision(self::BLOCK_INACTIVE, null, null, null);
        }

        $monthlyKey   = $kind === self::KIND_DETAIL ? 'monthly_detail' : 'monthly_search';
        $monthlyUsed  = $kind === self::KIND_DETAIL ? 'month_detail'   : 'month_search';
        $monthlyLimit = isset($limits[$monthlyKey]) ? (int) $limits[$monthlyKey] : 0;

        /*
         * An unconfigured ceiling is a BLOCK, not a pass.
         *
         * The opposite — treating 0 as unlimited — is how a profile created with
         * a blank limit field quietly becomes an uncapped spending account. The
         * brief's rule is "never permit automatic paid overage", and "no limit
         * set" is the clearest case of not knowing whether overage would occur.
         */
        if ($monthlyLimit <= 0) {
            return self::decision(self::BLOCK_NO_LIMIT, $monthlyKey, (int) self::at($used, $monthlyUsed), 0);
        }

        if (self::at($used, $monthlyUsed) >= $monthlyLimit) {
            return self::decision(self::BLOCK_MONTHLY, $monthlyKey, self::at($used, $monthlyUsed), $monthlyLimit);
        }

        $daily = isset($limits['daily']) ? (int) $limits['daily'] : 0;

        if ($daily > 0 && self::at($used, 'today_all') >= $daily) {
            return self::decision(self::BLOCK_DAILY, 'daily', self::at($used, 'today_all'), $daily);
        }

        $perStaff = isset($limits['per_staff_daily']) ? (int) $limits['per_staff_daily'] : 0;

        if ($perStaff > 0 && self::at($used, 'today_staff') >= $perStaff) {
            return self::decision(self::BLOCK_PER_STAFF, 'per_staff_daily', self::at($used, 'today_staff'), $perStaff);
        }

        return self::decision(self::OK, $monthlyKey, self::at($used, $monthlyUsed), $monthlyLimit);
    }

    public static function allows($kind, array $limits, array $used, $hasKey, $active)
    {
        $d = self::evaluate($kind, $limits, $used, $hasKey, $active);

        return $d['decision'] === self::OK;
    }

    /**
     * Was this request billable despite failing?
     *
     * A request Google answered — even with 400 or 403 — has reached the
     * service and may be charged. A request that never arrived (DNS, timeout,
     * connection refused, no key) has not. Releasing a reservation for the first
     * kind would under-count real spending, which is the error that matters.
     *
     * `$httpStatus` is null when nothing was sent.
     */
    public static function isBillable($httpStatus)
    {
        if ($httpStatus === null || $httpStatus === 0 || $httpStatus === '') {
            return false;
        }

        $code = (int) $httpStatus;

        /* Anything the service answered with. 5xx is Google failing, not us. */
        return $code >= 200 && $code < 500;
    }

    /** True when the reservation should be handed back. */
    public static function shouldRelease($httpStatus)
    {
        return !self::isBillable($httpStatus);
    }

    /**
     * The operator-facing sentence. Never contains a key, an id or a URL.
     */
    public static function message(array $decision)
    {
        switch ($decision['decision']) {
            case self::BLOCK_NO_KEY:
                return 'No API key is configured for this connection. Nothing was sent.';
            case self::BLOCK_INACTIVE:
                return 'This API connection is inactive. Nothing was sent.';
            case self::BLOCK_NO_LIMIT:
                return 'This connection has no limit configured for that request type, so it is '
                     . 'blocked rather than allowed to spend without a ceiling.';
            case self::BLOCK_MONTHLY:
                return 'The monthly limit for this connection has been reached ('
                     . (int) $decision['used'] . ' of ' . (int) $decision['limit'] . '). Nothing was sent.';
            case self::BLOCK_DAILY:
                return 'The daily limit for this connection has been reached ('
                     . (int) $decision['used'] . ' of ' . (int) $decision['limit'] . '). Nothing was sent.';
            case self::BLOCK_PER_STAFF:
                return 'Your personal daily limit has been reached ('
                     . (int) $decision['used'] . ' of ' . (int) $decision['limit'] . '). Nothing was sent.';
            default:
                return '';
        }
    }

    /** The banner shown before a ceiling is hit. Empty below the warning band. */
    public static function warning($used, $limit, $label)
    {
        $band = self::band($used, $limit);
        $pct  = self::percentUsed($used, $limit);

        if ($band === 'none' || $pct === null) {
            return '';
        }

        if ($band === 'exhausted') {
            return $label . ' is exhausted (' . (int) $used . ' of ' . (int) $limit . ').';
        }

        $prefix = $band === 'critical' ? 'Critical: ' : 'Warning: ';

        return $prefix . $label . ' is at ' . $pct . '% (' . (int) $used . ' of ' . (int) $limit . ').';
    }

    private static function at(array $a, $k)
    {
        return isset($a[$k]) ? (int) $a[$k] : 0;
    }

    private static function decision($d, $ceiling, $used, $limit)
    {
        return array(
            'decision' => $d,
            'ceiling'  => $ceiling,
            'used'     => $used === null ? null : (int) $used,
            'limit'    => $limit === null ? null : (int) $limit,
        );
    }
}
