<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_claim — who may contact this business, and who may not.
 *
 * THE POINT OF A CLAIM
 * --------------------
 * Two employees ringing the same school in the same hour is the failure this
 * prevents. Everything else — limits, expiry, reassignment — exists to stop the
 * lock becoming a way to hoard prospects instead.
 *
 * PURE ON PURPOSE
 * ---------------
 * No database, no clock, no session. Time is passed in. That is not neatness:
 * a lock whose expiry is computed from `time()` inside the class can only be
 * tested by waiting, so in practice it never gets tested at the boundary, which
 * is the only place a lock is ever actually wrong.
 *
 * FAIL CLOSED
 * -----------
 * Every unrecognised condition denies. A claim system that defaults to "allow"
 * when it cannot tell produces exactly the double-calling it was built to stop.
 */
class Leadfinder_claim
{
    /* outcomes */
    const OK              = 'granted';
    const R_ALREADY_MINE  = 'already_claimed_by_you';
    const R_HELD          = 'held_by_another_employee';
    const R_NO_ACTOR      = 'no_authenticated_actor';
    const R_BAD_PROSPECT  = 'prospect_id_not_valid';
    const R_DAILY_LIMIT   = 'daily_claim_limit_reached';
    const R_TERMINAL      = 'prospect_in_a_terminal_state';
    const R_SUPPRESSED    = 'prospect_is_do_not_contact';
    const R_NOT_OWNER     = 'not_the_owner';
    const R_NOT_MANAGER   = 'requires_manager';
    const R_NOT_CLAIMED   = 'prospect_is_not_claimed';

    /**
     * States in which a prospect may not be claimed at all. Deliberately a
     * short, explicit list rather than "anything not in the open list": a new
     * status added later should keep working, not silently become unclaimable.
     */
    public static function terminalStates()
    {
        return array('converted_to_lead', 'rejected', 'duplicate');
    }

    /**
     * Is the existing claim still in force?
     *
     * @param array $p       prospect row: claimed_by, claimed_at, last_touch_at
     * @param int   $now     unix seconds
     * @param array $cfg     idle_release_seconds (0 = never auto-release)
     */
    public static function isLocked(array $p, $now, array $cfg)
    {
        $by = isset($p['claimed_by']) ? (int) $p['claimed_by'] : 0;
        if ($by <= 0) { return false; }

        $idle = isset($cfg['idle_release_seconds']) ? (int) $cfg['idle_release_seconds'] : 0;
        if ($idle <= 0) { return true; }          // never auto-releases

        /* Activity, not acquisition. Expiring from claimed_at would drop the
           lock out from under an employee who is mid-call on a long
           conversation — which is the one moment the lock is doing real work. */
        $touched = isset($p['last_touch_at']) && (int) $p['last_touch_at'] > 0
                   ? (int) $p['last_touch_at']
                   : (isset($p['claimed_at']) ? (int) $p['claimed_at'] : 0);
        if ($touched <= 0) { return true; }       // claimed but no timestamp: stay locked

        return ($now - $touched) < $idle;
    }

    /** Seconds until auto-release, or null when it never releases. */
    public static function secondsUntilRelease(array $p, $now, array $cfg)
    {
        $idle = isset($cfg['idle_release_seconds']) ? (int) $cfg['idle_release_seconds'] : 0;
        if ($idle <= 0 || (int) (isset($p['claimed_by']) ? $p['claimed_by'] : 0) <= 0) { return null; }
        $touched = isset($p['last_touch_at']) && (int) $p['last_touch_at'] > 0
                   ? (int) $p['last_touch_at']
                   : (isset($p['claimed_at']) ? (int) $p['claimed_at'] : 0);
        if ($touched <= 0) { return null; }
        return max(0, $idle - ($now - $touched));
    }

    /**
     * May $actorId claim this prospect?
     *
     * @param array $p         prospect row (id, status, claimed_by, claimed_at, last_touch_at)
     * @param int   $actorId
     * @param int   $claimsToday how many this employee has already claimed today
     * @param int   $now
     * @param array $cfg       idle_release_seconds, daily_claim_limit (0 = unlimited)
     */
    public static function canClaim(array $p, $actorId, $claimsToday, $now, array $cfg)
    {
        $actor = self::id($actorId);
        if ($actor <= 0)                 { return self::no(self::R_NO_ACTOR); }
        if (self::id(isset($p['id']) ? $p['id'] : 0) <= 0) { return self::no(self::R_BAD_PROSPECT); }

        $status = isset($p['status']) ? (string) $p['status'] : '';
        if ($status === 'do_not_contact')                     { return self::no(self::R_SUPPRESSED); }
        if (in_array($status, self::terminalStates(), true))  { return self::no(self::R_TERMINAL); }

        $held = self::id(isset($p['claimed_by']) ? $p['claimed_by'] : 0);
        if ($held > 0 && self::isLocked($p, $now, $cfg)) {
            /* Re-claiming your own is allowed and is not a second claim — it
               must not consume another slot against the daily limit. */
            return $held === $actor ? self::yes(self::R_ALREADY_MINE, false)
                                    : self::no(self::R_HELD);
        }

        $limit = isset($cfg['daily_claim_limit']) ? (int) $cfg['daily_claim_limit'] : 0;
        if ($limit > 0 && (int) $claimsToday >= $limit) { return self::no(self::R_DAILY_LIMIT); }

        return self::yes(self::OK, true);
    }

    /**
     * May $actorId release this claim?
     *
     * The owner may let go of their own. A manager may release anyone's — that
     * is the whole reason the role exists here, so a lock cannot strand a
     * prospect when someone goes on leave.
     */
    public static function canRelease(array $p, $actorId, $isManager)
    {
        $actor = self::id($actorId);
        if ($actor <= 0) { return self::no(self::R_NO_ACTOR); }

        $held = self::id(isset($p['claimed_by']) ? $p['claimed_by'] : 0);
        if ($held <= 0) { return self::no(self::R_NOT_CLAIMED); }
        if ($held === $actor)   { return self::yes('released_own', true); }
        if ($isManager === true) { return self::yes('released_by_manager', true); }
        return self::no(self::R_NOT_OWNER);
    }

    /**
     * May $actorId hand this prospect to $toStaffId?
     *
     * Manager-only, deliberately. Employee-to-employee handover with no manager
     * in the path is indistinguishable from an employee dumping prospects they
     * did not want to work, and it breaks the ownership trail §7 requires.
     */
    public static function canReassign(array $p, $actorId, $toStaffId, $isManager)
    {
        if (self::id($actorId) <= 0)     { return self::no(self::R_NO_ACTOR); }
        if ($isManager !== true)         { return self::no(self::R_NOT_MANAGER); }
        if (self::id($toStaffId) <= 0)   { return self::no(self::R_BAD_PROSPECT); }

        $status = isset($p['status']) ? (string) $p['status'] : '';
        if ($status === 'do_not_contact')                    { return self::no(self::R_SUPPRESSED); }
        if (in_array($status, self::terminalStates(), true)) { return self::no(self::R_TERMINAL); }

        return self::yes('reassigned', true);
    }

    /**
     * The ownership-history row for any claim event. Append-only by
     * construction: it records what happened, never the current state, so
     * replaying it reconstructs the chain §7 asks to be preserved.
     */
    public static function historyEntry($event, array $p, $actorId, $toStaffId, $now, $reason = '')
    {
        return array(
            'prospect_id' => self::id(isset($p['id']) ? $p['id'] : 0),
            'event'       => (string) $event,
            'from_staff'  => self::id(isset($p['claimed_by']) ? $p['claimed_by'] : 0),
            'to_staff'    => self::id($toStaffId),
            'actor_id'    => self::id($actorId),
            'reason'      => (string) $reason,
            'at'          => (int) $now,
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * A staff id, or 0. Validated, not cast: `(int) '7abc'` is 7, so a tampered
     * value would resolve to a real colleague. Same reasoning, same fix, as the
     * workforce emergency guard.
     */
    private static function id($v)
    {
        if (is_int($v)) { return $v > 0 ? $v : 0; }
        if (is_string($v) && preg_match('/^[0-9]{1,18}\z/', $v)) {
            return (int) $v > 0 ? (int) $v : 0;
        }
        return 0;
    }

    private static function yes($reason, $consumesQuota)
    {
        return array('allowed' => true, 'reason' => $reason,
                     'consumes_daily_quota' => (bool) $consumesQuota);
    }

    private static function no($reason)
    {
        return array('allowed' => false, 'reason' => $reason,
                     'consumes_daily_quota' => false);
    }
}
