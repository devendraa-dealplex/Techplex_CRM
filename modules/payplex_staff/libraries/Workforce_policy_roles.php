<?php
defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Workforce_policy_roles — the canonical policy-role vocabulary, the rules for
 * when an assignment counts, and the rules for who may assign one.
 *
 * Everything here is PURE: no database, no request, no clock of its own. The
 * current time is always passed in. That is deliberate — these are the rules a
 * mutation harness has to be able to break, and a rule that needs a live
 * database to exercise is a rule nobody tests.
 *
 * WHY A TABLE AND NOT A SETTINGS ROW
 * ----------------------------------
 * The previous build resolved HR and finance roles from a free-form JSON value
 * in the settings table. That made an authorization input a mutable option
 * string with no history, no expiry, no approval and no audit. Assignments now
 * live in their own table; this class holds the logic that reads them.
 */
class Workforce_policy_roles
{
    /* ---------------- canonical vocabulary (12) ---------------- */
    const R_SUPER_ADMIN     = 'super_admin';
    const R_HR_HEAD         = 'hr_head';
    const R_HR_MANAGER      = 'hr_manager';
    const R_HR_EXECUTIVE    = 'hr_executive';
    const R_FINANCE_HEAD    = 'finance_head';
    const R_FINANCE_MAKER   = 'finance_maker';
    const R_FINANCE_CHECKER = 'finance_checker';
    const R_FIELD_MANAGER   = 'field_manager';
    const R_MANAGER         = 'manager';
    const R_TEAM_LEADER     = 'team_leader';
    const R_EMPLOYEE        = 'employee';
    const R_AUDITOR         = 'auditor';

    /* ---------------- resolution sources ---------------- */
    const SRC_ADMIN     = 'admin';
    const SRC_ASSIGNED  = 'assigned';
    const SRC_TEMPORARY = 'temporary';
    const SRC_DERIVED   = 'derived';
    const SRC_DEFAULT   = 'default';
    const SRC_UNMAPPED  = 'unmapped';
    const SRC_CONFLICT  = 'conflict';

    /** The least-privileged role. Every failure path lands here. */
    public static function leastPrivilege() { return self::R_EMPLOYEE; }

    public static function vocabulary()
    {
        return array(
            self::R_SUPER_ADMIN, self::R_HR_HEAD, self::R_HR_MANAGER, self::R_HR_EXECUTIVE,
            self::R_FINANCE_HEAD, self::R_FINANCE_MAKER, self::R_FINANCE_CHECKER,
            self::R_FIELD_MANAGER, self::R_MANAGER, self::R_TEAM_LEADER,
            self::R_EMPLOYEE, self::R_AUDITOR,
        );
    }

    public static function isKnownRole($role)
    {
        return is_string($role) && in_array(strtolower(trim($role)), self::vocabulary(), true);
    }

    /** Roles whose assignment needs a second person. */
    public static function sensitiveRoles()
    {
        return array(self::R_HR_HEAD, self::R_FINANCE_HEAD, self::R_FINANCE_CHECKER,
                     self::R_FINANCE_MAKER, self::R_AUDITOR);
    }

    /* ===================================================================
     * LEGACY COMPATIBILITY — explicit, never automatic
     * =================================================================== */

    /**
     * Old module labels map to NOTHING on their own.
     *
     * `hr_admin` is not `hr_head` and `finance_approver` is not `finance_head`:
     * both of those would silently hand somebody company-wide authority during
     * an upgrade. A legacy label is recognised only so it can be reported as
     * needing a decision, never so it can be honoured.
     */
    public static function legacyLabels()
    {
        return array('hr_admin', 'finance_approver', 'commission_employee', 'auditor_legacy');
    }

    /**
     * Returns array('role' => string|null, 'source' => string, 'note' => string).
     * A null role means: resolve to least privilege and raise a warning.
     */
    public static function mapLegacyRole($legacy)
    {
        $l = is_string($legacy) ? strtolower(trim($legacy)) : '';
        if ($l === '') {
            return array('role' => null, 'source' => self::SRC_UNMAPPED, 'note' => 'empty_legacy_label');
        }
        /* An exact canonical name is itself, and nothing else is translated. */
        if (in_array($l, self::vocabulary(), true)) {
            return array('role' => $l, 'source' => self::SRC_ASSIGNED, 'note' => 'canonical');
        }
        return array('role' => null, 'source' => self::SRC_UNMAPPED,
                     'note' => 'legacy_label_requires_explicit_assignment');
    }

    /* ===================================================================
     * WHEN AN ASSIGNMENT COUNTS
     * =================================================================== */

    /**
     * An assignment is live only if every one of these holds. Each is a
     * separate reason string so a refusal says which rule failed.
     */
    public static function assignmentState(array $row, $nowTs)
    {
        $nowTs = (int) $nowTs;
        if (empty($row['is_active']) || (int) $row['is_active'] !== 1) {
            return array('live' => false, 'reason' => 'inactive');
        }
        if (!empty($row['revoked_at'])) {
            return array('live' => false, 'reason' => 'revoked');
        }
        $from = isset($row['effective_from']) ? self::ts($row['effective_from']) : null;
        if ($from === false) {
            return array('live' => false, 'reason' => 'unreadable_date');
        }
        if ($from !== null && $nowTs < $from) {
            return array('live' => false, 'reason' => 'not_yet_effective');
        }
        $until = isset($row['expires_at']) ? self::ts($row['expires_at']) : null;
        if ($until === false) {
            return array('live' => false, 'reason' => 'unreadable_date');
        }
        if ($until !== null && $nowTs >= $until) {
            return array('live' => false, 'reason' => 'expired');
        }
        if (!self::isKnownRole(isset($row['policy_role']) ? $row['policy_role'] : '')) {
            return array('live' => false, 'reason' => 'unknown_role');
        }
        return array('live' => true, 'reason' => 'live', 'temporary' => ($until !== null));
    }

    /**
     * '' / null / 0 all mean "no bound". Returns null for no bound, an int for
     * a bound, and FALSE when the value cannot be read.
     *
     * An unreadable date is never silently converted to a bound. The first
     * version mapped it to PHP_INT_MAX, which made an unreadable expiry a date
     * in the far future - so corrupt data became a permanent grant, the exact
     * opposite of failing closed. Callers must handle false explicitly.
     */
    private static function ts($v)
    {
        if ($v === null || $v === '' || $v === 0 || $v === '0') { return null; }
        if (is_int($v)) { return $v; }
        $t = strtotime((string) $v);
        return ($t === false) ? false : $t;
    }

    /**
     * Pick the one role that applies.
     *
     * Returns array('role','source','scope','conflict','notes').
     * Two live assignments are a configuration conflict, not a merge: combining
     * them is exactly how somebody ends up with more authority than anyone
     * granted. Conflict resolves to least privilege.
     */
    public static function selectAssignment(array $rows, $nowTs)
    {
        $live = array(); $sawUnknown = false;
        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $st = self::assignmentState($row, $nowTs);
            if (!empty($st['live'])) {
                $row['__temporary'] = !empty($st['temporary']);
                $live[] = $row;
            } elseif ($st['reason'] === 'unknown_role') {
                $sawUnknown = true;
            }
        }

        if (count($live) > 1) {
            $roles = array();
            foreach ($live as $r) { $roles[] = strtolower(trim($r['policy_role'])); }
            $roles = array_values(array_unique($roles));
            /* Identical duplicates of the same role are still a data problem,
               but they are not an escalation, so they do not fail closed. */
            if (count($roles) > 1) {
                sort($roles);
                return array('role' => self::leastPrivilege(), 'source' => self::SRC_CONFLICT,
                             'scope' => array(), 'conflict' => true,
                             'notes' => 'conflicting_active_roles:' . implode('|', $roles));
            }
        }

        if (count($live) >= 1) {
            $r = $live[0];
            return array(
                'role'     => strtolower(trim($r['policy_role'])),
                'source'   => !empty($r['__temporary']) ? self::SRC_TEMPORARY : self::SRC_ASSIGNED,
                'scope'    => self::scopeOf($r),
                'conflict' => false,
                'notes'    => '',
            );
        }

        if ($sawUnknown) {
            return array('role' => self::leastPrivilege(), 'source' => self::SRC_UNMAPPED,
                         'scope' => array(), 'conflict' => false, 'notes' => 'unknown_role_in_assignment');
        }
        return array('role' => null, 'source' => '', 'scope' => array(),
                     'conflict' => false, 'notes' => '');
    }

    /** Scope carried by an assignment. Absent fields stay absent — never widened. */
    public static function scopeOf(array $row)
    {
        $s = array();
        foreach (array('business_entity_id', 'branch', 'department', 'region') as $k) {
            if (isset($row[$k]) && (string) $row[$k] !== '') { $s[$k] = (string) $row[$k]; }
        }
        return $s;
    }

    /* ===================================================================
     * WHO MAY ASSIGN — maker-checker on the assignment itself
     * =================================================================== */

    /** Company-wide or multi-entity scope is sensitive regardless of the role. */
    public static function isCompanyWideScope(array $scope)
    {
        $hasEntity = isset($scope['business_entity_id']) && $scope['business_entity_id'] !== '';
        $hasBranch = isset($scope['branch']) && $scope['branch'] !== '';
        $hasRegion = isset($scope['region']) && $scope['region'] !== '';
        $hasDept   = isset($scope['department']) && $scope['department'] !== '';
        return !($hasEntity || $hasBranch || $hasRegion || $hasDept);
    }

    public static function requiresMakerChecker($role, array $scope)
    {
        $role = is_string($role) ? strtolower(trim($role)) : '';
        if (in_array($role, self::sensitiveRoles(), true)) { return true; }
        if (self::isCompanyWideScope($scope)) { return true; }
        return false;
    }

    /**
     * Validate a proposed assignment. Returns array('ok','errors').
     * The admin screen calls this; so do the tests, which is the point.
     */
    public static function validateAssignment(array $d, array $ctx = array())
    {
        $e = array();
        $staffId  = isset($d['staff_id']) ? (int) $d['staff_id'] : 0;
        $role     = isset($d['policy_role']) ? strtolower(trim((string) $d['policy_role'])) : '';
        $actorId  = isset($ctx['actor_id']) ? (int) $ctx['actor_id'] : 0;

        if ($staffId <= 0)              { $e[] = 'staff_id_required'; }
        if (!self::isKnownRole($role))  { $e[] = 'unknown_policy_role'; }
        if (empty($d['assignment_reason']) || strlen(trim((string) $d['assignment_reason'])) < 5) {
            $e[] = 'assignment_reason_required';
        }
        /* Inactive staff may not hold a policy role. */
        if (isset($ctx['target_active']) && !$ctx['target_active']) { $e[] = 'staff_inactive'; }

        /* Self-elevation into a sensitive role is refused outright — the same
           maker-checker rule the rest of the system runs on, applied to the
           thing that hands out authority. */
        $scope = self::scopeOf($d);
        if (self::requiresMakerChecker($role, $scope) && $actorId > 0 && $actorId === $staffId) {
            $e[] = 'self_elevation_refused';
        }
        /* Dates must be coherent. */
        $from  = isset($d['effective_from']) ? self::ts($d['effective_from']) : null;
        $until = isset($d['expires_at'])     ? self::ts($d['expires_at'])     : null;
        if ($from === false || $until === false) { $e[] = 'unreadable_date'; }
        if (is_int($from) && is_int($until) && $until <= $from) { $e[] = 'expiry_before_effective'; }

        /* A conflicting live assignment must be revoked first, not stacked. */
        if (!empty($ctx['existing']) && is_array($ctx['existing'])) {
            $now = isset($ctx['now']) ? (int) $ctx['now'] : time();
            foreach ($ctx['existing'] as $row) {
                if (!is_array($row)) { continue; }
                $st = self::assignmentState($row, $now);
                if (!empty($st['live']) && strtolower(trim($row['policy_role'])) !== $role) {
                    $e[] = 'conflicting_active_assignment'; break;
                }
            }
        }

        return array('ok' => empty($e), 'errors' => array_values(array_unique($e)),
                     'requires_approval' => self::requiresMakerChecker($role, $scope));
    }

    /**
     * Revocation never deletes. It stamps the existing row.
     * Returns the field set to UPDATE with, or null when the input is unusable.
     */
    public static function revocationFields($actorId, $reason, $nowTs)
    {
        $actorId = (int) $actorId;
        $reason  = trim((string) $reason);
        if ($actorId <= 0 || strlen($reason) < 5) { return null; }
        return array(
            'is_active'         => 0,
            'revoked_at'        => date('Y-m-d H:i:s', (int) $nowTs),
            'revoked_by'        => $actorId,
            'revocation_reason' => substr($reason, 0, 255),
            'updated_at'        => date('Y-m-d H:i:s', (int) $nowTs),
        );
    }
}
