<?php
defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Workforce_emergency_guard — TEMPORARY containment for a live IDOR.
 *
 * WHY THIS EXISTS, AND WHY IT IS NOT THE REAL FIX
 * -----------------------------------------------
 * `Staff::attendance($id)`, `activity($id)`, `performance($id)` and `view($id)`
 * are live on staging returning whoever the URL names, and six write paths —
 * including the lifecycle transition that can exit an employee, and the bank
 * verification that releases money to an account — are gated by capability
 * alone. Verified against the running server: `/staff/performance/33` returns
 * 200 with that person's data.
 *
 * The permanent fix is `Workforce_scope`: entity-aware, hierarchy-aware,
 * audited, 220 tests. It cannot be deployed yet because it needs migration 016
 * (the `entity_id` column), an entity assignment and an approved manager
 * mapping, and none of those exist.
 *
 * So this is a stopgap with ONE job: make the hole small enough to live with
 * until the real layer lands. It is deliberately cruder than the real thing.
 *
 * THE TEMPORARY POLICY, EXACTLY
 * -----------------------------
 *   - Normal staff: their OWN record only. target === actor, or nothing.
 *   - Full administrators (`is_admin()`): any record, temporarily, and every
 *     such access is written to the audit trail.
 *   - Manager / TL / team access: DISABLED. A manager gets no more than an
 *     employee does. That is a deliberate regression — team visibility is a
 *     convenience, and it comes back with the scope layer that can express it
 *     safely. Being briefly too strict is recoverable; being too loose is what
 *     we are fixing.
 *
 * WHAT IT DEPENDS ON: nothing.
 * ---------------------------
 * No migration, no schema change, no `entity_id`, no manager field, no new
 * table, no setting. It reads the session's staff id and `is_admin()`. That is
 * the whole input, and it is why this can ship today.
 *
 * IF ADMIN CANNOT BE IDENTIFIED
 * -----------------------------
 * `is_admin()` is a Perfex core function. If it is absent, this class DENIES
 * cross-staff access to everybody, administrators included, rather than
 * guessing. An instruction to guard without guessing is only honoured if the
 * uncertain case is the closed one.
 *
 * REMOVAL
 * -------
 * Delete this file and the `guardStaffTarget()` / `guardRecordOwner()` calls
 * once `Workforce_scope` is deployed and its UAT has passed. Until then it
 * stays. `isTemporary()` exists so a test can assert nobody has quietly
 * promoted it to the permanent answer.
 */
class Workforce_emergency_guard
{
    /** Reasons — audited, never rendered. */
    const R_SELF            = 'self';
    const R_ADMIN           = 'admin_override';
    const R_NOT_SELF        = 'not_self_and_not_admin';
    const R_BAD_TARGET      = 'target_id_not_a_staff_id';
    const R_NO_ACTOR        = 'no_authenticated_actor';
    const R_ADMIN_UNKNOWABLE= 'admin_detection_unavailable';
    const R_NO_OWNER        = 'record_has_no_resolvable_owner';

    /** This is a stopgap. A test asserts it still says so. */
    public static function isTemporary() { return true; }

    /**
     * One message for every refusal.
     *
     * Identical whether the record is somebody else's, does not exist, or the
     * id was malformed. A refusal that varies is a staff directory: send ids
     * until the wording changes.
     */
    public static function refusalMessage()
    {
        return 'No such staff record, or it is not yours to open.';
    }

    /**
     * A staff id, or 0 if the value is not cleanly one.
     *
     * `(int) '4abc'` is 4, so a tampered path segment resolves to a real person
     * under a plain cast. And `\z` rather than `$`: PCRE's `$` matches before a
     * trailing newline, so "4\n" passes a `/^[0-9]+$/` check and then casts to
     * 4 — a real id reached through a value the filter believed it rejected.
     * Both were found by hostile-input tests on the permanent layer, not by
     * reading the code, and both are carried here deliberately.
     */
    public static function cleanId($value)
    {
        if (is_int($value))  { return $value > 0 ? $value : 0; }
        if (is_string($value) && preg_match('/^[0-9]{1,18}\z/', $value)) {
            return (int) $value > 0 ? (int) $value : 0;
        }
        return 0;
    }

    /** Is administrator identification actually available in this request? */
    public static function adminDetectable()
    {
        return function_exists('is_admin');
    }

    /**
     * May $actorId open $targetId's record?
     *
     * @param mixed $targetId RAW — passed through untouched so a tampered value
     *                        is rejected rather than cast.
     * @param bool|null $isAdmin  result of is_admin(), or null when unknown.
     * @return array allowed, reason (audited), public (shown)
     */
    public static function decide($actorId, $targetId, $isAdmin = null, $adminDetectable = true)
    {
        $me     = self::cleanId($actorId);
        $target = self::cleanId($targetId);

        if ($me <= 0)     { return self::no(self::R_NO_ACTOR); }
        if ($target <= 0) { return self::no(self::R_BAD_TARGET); }

        /* Self first, and without consulting anything else. A person reading
           their own record must not depend on admin detection working. */
        if ($target === $me) { return self::yes(self::R_SELF); }

        /*
         * Everything below is cross-staff. If we cannot tell who is an
         * administrator, nobody is treated as one — including somebody who
         * really is. Denying an admin for an hour is an inconvenience;
         * admitting a non-admin because detection failed is the incident.
         */
        if (!$adminDetectable) { return self::no(self::R_ADMIN_UNKNOWABLE); }
        if ($isAdmin === true) { return self::yes(self::R_ADMIN); }

        /*
         * No manager rung, no TL rung, no team rung. Not an oversight — team
         * access needs a hierarchy this build cannot evaluate, and inventing
         * one here would be the same mistake in a smaller box.
         */
        return self::no(self::R_NOT_SELF);
    }

    /** Should this decision be written to the audit trail? */
    public static function shouldAudit(array $d, $actorId, $targetId)
    {
        /* Self-reads of one's own record are not audit events — logging every
           page view would bury the rows that matter. Everything else is. */
        if (!empty($d['allowed']) && self::cleanId($actorId) === self::cleanId($targetId)) {
            return false;
        }
        return true;
    }

    /** The audit payload. The TRUE reason goes here; never to the response. */
    public static function auditData(array $d, $targetId, $resource)
    {
        return array(
            'guard'     => 'emergency_idor_guard',
            'resource'  => (string) $resource,
            'target'    => self::cleanId($targetId),
            /* what was actually sent, when it differs from what it parsed to */
            'raw'       => (string) (int) self::cleanId($targetId) === (string) $targetId
                           ? null
                           : substr(preg_replace('/[^\x20-\x7E]/', '?', (string) $targetId), 0, 40),
            'allowed'   => !empty($d['allowed']),
            'reason'    => isset($d['reason']) ? $d['reason'] : '',
            'temporary' => true,
        );
    }

    private static function no($reason)
    {
        return array('allowed' => false, 'reason' => $reason,
                     'public' => self::refusalMessage());
    }
    private static function yes($reason)
    {
        return array('allowed' => true, 'reason' => $reason, 'public' => '');
    }
}
