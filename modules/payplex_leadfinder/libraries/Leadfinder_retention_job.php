<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_retention_job — scheduling, locking and reporting for the §14.3
 * coordinate sweep.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * `Leadfinder_model::purgeExpiredCoordinates()` was written, unit-tested, and
 * **never called**. Searching the whole deployed module for its name returned
 * exactly one occurrence: its own definition. The module registered three hooks
 * — `admin_init` twice and `app_admin_footer` — and no cron hook at all.
 *
 * So the 30-day deletion of cached latitude and longitude required by the Google
 * Maps Platform Service Specific Terms §14.3 could not run. It had breached
 * nothing only because no coordinate had ever been stored; the first successful
 * search would have started a table filling with data nothing ever cleared, and
 * nothing anywhere would have reported a problem.
 *
 * This is the same defect shape this project keeps finding, in its most
 * expensive form: a control that looks correct, passes its tests, and can never
 * act.
 *
 * WHY NOT `admin_init`
 * --------------------
 * `admin_init` fires on every admin page load, which would make the sweep run
 * hundreds of times a day, at unpredictable moments, inside a request a person
 * is waiting on — and never at all during a quiet period, which is exactly when
 * a retention window expires. The brief rules it out and it deserves to be
 * ruled out. `after_cron_run` is the scheduler: it fires from the CRM's cron
 * endpoint, which this account runs every five minutes.
 *
 * `allowedSchedulerHooks()` is enforced by a test so the hook cannot quietly
 * migrate back to a page-load event.
 *
 * This class holds no I/O. It decides *whether* to run, *whether a lock is
 * stale*, and *what the run record says*. The model does the querying and the
 * bootstrap does the wiring, so each can be tested without the others.
 */
class Leadfinder_retention_job
{
    /** The row id in the lock table. One job, one lock. */
    const LOCK_KEY = 'retention_coordinates';

    /**
     * How long a lock stays valid.
     *
     * A process that dies mid-sweep leaves its lock behind. Without a TTL the
     * job would be wedged for ever and — because it fails silently by design —
     * nobody would notice until an audit. 15 minutes is comfortably longer than
     * a 500-row sweep and short enough that one crash costs at most three cron
     * ticks.
     */
    const LOCK_TTL_SECONDS = 900;

    /** Minimum gap between sweeps. The cron fires every 5 minutes; this does not. */
    const DEFAULT_INTERVAL_SECONDS = 3600;

    /** Hooks this job may be scheduled on. Anything else is a defect. */
    public static function allowedSchedulerHooks()
    {
        return array('after_cron_run');
    }

    /** Hooks that must never schedule it, named so a test can assert each one. */
    public static function forbiddenSchedulerHooks()
    {
        return array('admin_init', 'app_admin_footer', 'app_init', 'pre_admin_init',
                     'after_admin_head', 'app_admin_footer_start');
    }

    public static function isValidSchedulerHook($hook)
    {
        return in_array($hook, self::allowedSchedulerHooks(), true);
    }

    /**
     * Is the sweep due?
     *
     * A null or unparseable last-run means "never run", which is due. A last-run
     * in the future — a clock correction, a restored database — is treated as
     * due rather than blocking the job until the future catches up.
     */
    public static function shouldRun($lastRunAt, $now, $intervalSeconds = null)
    {
        $interval = $intervalSeconds === null ? self::DEFAULT_INTERVAL_SECONDS : (int) $intervalSeconds;

        if ($interval < 0) {
            $interval = self::DEFAULT_INTERVAL_SECONDS;
        }

        if ($lastRunAt === null || $lastRunAt === '' || !is_numeric($lastRunAt)) {
            return true;
        }

        $last = (int) $lastRunAt;
        $now  = (int) $now;

        if ($last > $now) {
            return true;
        }

        return ($now - $last) >= $interval;
    }

    /**
     * May this process take the lock?
     *
     * True when no lock is held, or when the held one is older than its TTL.
     * The caller must still take the lock with a conditional write — this
     * decides eligibility, it does not grant anything. Two processes can both
     * be told "yes" here; only one wins the UPDATE.
     */
    public static function lockAvailable($lockedAt, $now, $ttl = null)
    {
        $ttl = $ttl === null ? self::LOCK_TTL_SECONDS : (int) $ttl;

        if ($lockedAt === null || $lockedAt === '' || !is_numeric($lockedAt)) {
            return true;
        }

        return ((int) $now - (int) $lockedAt) >= $ttl;
    }

    /** True when a held lock has outlived its TTL — reported, not just reclaimed. */
    public static function lockIsStale($lockedAt, $now, $ttl = null)
    {
        if ($lockedAt === null || $lockedAt === '' || !is_numeric($lockedAt)) {
            return false;
        }

        return self::lockAvailable($lockedAt, $now, $ttl);
    }

    /**
     * The run record.
     *
     * `examined` and `purged` are separate because they answer different
     * questions: a sweep that examined 500 rows and purged 0 is healthy, while
     * one that examined 0 may mean the query is wrong. Recording only "purged"
     * would make those two indistinguishable — which is how a broken retention
     * job reports success for months.
     */
    public static function runRecord($startedAt, $finishedAt, $examined, $purged, $error = null, $mode = 'cron')
    {
        $started  = (int) $startedAt;
        $finished = (int) $finishedAt;

        return array(
            'started_at'   => $started,
            'finished_at'  => $finished,
            'duration_ms'  => max(0, ($finished - $started)) * 1000,
            'examined'     => max(0, (int) $examined),
            'purged'       => max(0, (int) $purged),
            'error'        => $error === null ? null : substr((string) $error, 0, 500),
            'mode'         => in_array($mode, array('cron', 'manual'), true) ? $mode : 'cron',
            'status'       => $error === null ? 'ok' : 'error',
        );
    }

    /**
     * Should someone be told?
     *
     * An error, obviously. But also a run that purged nothing while rows were
     * waiting to be purged — that is the silent-failure case, and it is the one
     * that matters, because it looks exactly like a healthy quiet day.
     */
    public static function needsAlert(array $record, $expiredWaiting = 0)
    {
        if ($record['status'] === 'error') {
            return true;
        }

        return ((int) $expiredWaiting) > 0 && (int) $record['purged'] === 0;
    }

    /**
     * Consecutive failures before the job stops retrying on its own.
     *
     * Retrying for ever against a schema error means the log fills and nobody
     * reads it. Stopping means an administrator has to look, which is the
     * correct outcome for a compliance job that cannot complete.
     */
    const MAX_CONSECUTIVE_FAILURES = 5;

    public static function shouldKeepRetrying($consecutiveFailures)
    {
        return (int) $consecutiveFailures < self::MAX_CONSECUTIVE_FAILURES;
    }
}
