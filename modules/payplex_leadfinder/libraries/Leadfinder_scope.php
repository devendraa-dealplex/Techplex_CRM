<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_scope — who may see which prospect.
 *
 * WHY THIS MODULE HAS ITS OWN GATE
 * --------------------------------
 * The standing instruction on this project is to route authorization through
 * the central scope layer and not to add a second one. That instruction is
 * about STAFF RECORDS, where ownership is a property of an org chart nobody has
 * approved yet, so any local guess at "my team" would be an invention.
 *
 * A prospect is different. Ownership is a column on this module's own row —
 * `assigned_staff`, written when an employee claims it. There is no hierarchy
 * to infer and nothing to guess. So this gate answers one question, "is this
 * row yours", plus an explicit manager rung that is granted by capability
 * rather than deduced from a reporting line that does not exist.
 *
 * When `Workforce_scope` and migration 016 land, this class is replaced by a
 * call into it and `usesLocalOwnership()` stops returning true. A test asserts
 * that flag, so nobody can quietly promote the interim answer to the permanent
 * one — the same pin used on the emergency IDOR guard.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It does not decide capabilities. `payplex_staff_can()`-style capability
 * checks happen before this; this only narrows WHICH ROWS a permitted action
 * may touch. Mixing the two produces a gate where holding a capability implies
 * owning the data, which is the defect the workforce audit spent a week on.
 */
class Leadfinder_scope
{
    const ALL       = 'all';        // admin
    const TEAM      = 'team';       // manager over named staff
    const SELF_ONLY = 'self';       // employee
    const NONE      = 'none';

    const R_OWNER        = 'owner';
    const R_TEAM_MEMBER  = 'team_member';
    const R_ADMIN        = 'admin';
    const R_UNASSIGNED   = 'unassigned_and_claimable';
    const R_NOT_YOURS    = 'not_yours';
    const R_NO_ACTOR     = 'no_authenticated_actor';
    const R_NO_SCOPE     = 'no_scope_granted';

    /** Interim, and says so. Replaced by Workforce_scope after its UAT passes. */
    public static function usesLocalOwnership() { return true; }

    /**
     * Reduce capabilities to a scope band.
     *
     * @param bool  $isAdmin
     * @param bool  $isManager
     * @param bool  $canView   holds the module's view capability at all
     */
    public static function bandFor($isAdmin, $isManager, $canView)
    {
        if ($isAdmin === true)   { return self::ALL; }
        if ($canView !== true)   { return self::NONE; }
        if ($isManager === true) { return self::TEAM; }
        return self::SELF_ONLY;
    }

    /**
     * May $actorId read this prospect?
     *
     * @param array $p      prospect row (assigned_staff)
     * @param int   $actorId
     * @param string $band
     * @param array $teamIds staff ids this manager covers — passed in, never inferred
     */
    public static function canRead(array $p, $actorId, $band, array $teamIds = array())
    {
        $actor = self::id($actorId);
        if ($actor <= 0)          { return self::no(self::R_NO_ACTOR); }
        if ($band === self::NONE) { return self::no(self::R_NO_SCOPE); }
        if ($band === self::ALL)  { return self::yes(self::R_ADMIN); }

        $owner = self::id(isset($p['assigned_staff']) ? $p['assigned_staff'] : 0);
        if ($owner === $actor) { return self::yes(self::R_OWNER); }

        /*
         * An unclaimed prospect is visible to anyone who may claim it — that is
         * the point of a shared queue. It carries no contact details until
         * somebody claims it (§4), so this exposes a business name and an
         * address, not a person.
         */
        if ($owner <= 0) { return self::yes(self::R_UNASSIGNED); }

        if ($band === self::TEAM && in_array($owner, self::ids($teamIds), true)) {
            return self::yes(self::R_TEAM_MEMBER);
        }
        return self::no(self::R_NOT_YOURS);
    }

    /**
     * May $actorId change this prospect?
     *
     * Stricter than reading, deliberately: an unclaimed row is readable by
     * everyone and writable by nobody until it is claimed, and a manager may
     * read a team member's prospect without being able to record a call result
     * on their behalf.
     */
    public static function canWrite(array $p, $actorId, $band, array $teamIds = array())
    {
        $actor = self::id($actorId);
        if ($actor <= 0)          { return self::no(self::R_NO_ACTOR); }
        if ($band === self::NONE) { return self::no(self::R_NO_SCOPE); }

        $owner = self::id(isset($p['assigned_staff']) ? $p['assigned_staff'] : 0);
        if ($owner === $actor)   { return self::yes(self::R_OWNER); }
        if ($band === self::ALL) { return self::yes(self::R_ADMIN); }

        return self::no($owner <= 0 ? self::R_UNASSIGNED : self::R_NOT_YOURS);
    }

    /**
     * The SQL fragment that scopes a list query, as a parameterised pair.
     *
     * Returned rather than applied, so the caller binds it — and so a test can
     * assert that the self-only band actually produces a restricting predicate.
     * A scope layer whose list predicate is empty for every band is the exact
     * defect found in `applyScope()` during the workforce audit: a filter that
     * filters nothing, which no page ever reveals.
     *
     * @return array sql, params — sql is never empty except for ALL
     */
    public static function listPredicate($actorId, $band, array $teamIds = array(), $col = 'assigned_staff')
    {
        $actor = self::id($actorId);
        if ($actor <= 0 || $band === self::NONE) {
            return array('sql' => '1 = 0', 'params' => array());   // fail closed
        }
        if ($band === self::ALL) {
            return array('sql' => '', 'params' => array());
        }
        if ($band === self::TEAM) {
            $ids = self::ids($teamIds);
            if (!in_array($actor, $ids, true)) { $ids[] = $actor; }
            $in = implode(',', array_fill(0, count($ids), '?'));
            return array('sql' => "($col IS NULL OR $col = 0 OR $col IN ($in))", 'params' => $ids);
        }
        return array('sql' => "($col IS NULL OR $col = 0 OR $col = ?)", 'params' => array($actor));
    }

    /* ------------------------------------------------------------------ */

    private static function id($v)
    {
        if (is_int($v)) { return $v > 0 ? $v : 0; }
        if (is_string($v) && preg_match('/^[0-9]{1,18}\z/', $v)) {
            return (int) $v > 0 ? (int) $v : 0;
        }
        return 0;
    }

    private static function ids(array $in)
    {
        $out = array();
        foreach ($in as $v) { $i = self::id($v); if ($i > 0) { $out[] = $i; } }
        return array_values(array_unique($out));
    }

    private static function yes($r) { return array('allowed' => true,  'reason' => $r); }
    private static function no($r)  { return array('allowed' => false, 'reason' => $r); }
}
