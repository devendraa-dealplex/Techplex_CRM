<?php
defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Workforce_shadow — SHADOW-MODE COMPARISON ONLY.
 *
 * WHAT THIS DOES AND, MORE IMPORTANTLY, WHAT IT DOES NOT
 * ------------------------------------------------------
 * It computes what Workforce_scope WOULD decide, records a metadata-only
 * comparison against the emergency guard, and returns nothing the caller can
 * act on. The emergency guard stays authoritative. Nothing here can grant
 * access: `compare()` returns void, and the controller never reads a value
 * from it.
 *
 * SAFETY PROPERTIES, each enforced rather than intended:
 *   - NO WRITES to application data. The only write is an append to a log file
 *     outside the web root, and only on staging.
 *   - NO REPEATED ACTION. Workforce_scope::decide() is pure; the context loader
 *     performs read-only SELECTs and is memoised per request.
 *   - FAILS CLOSED AND SILENT. Every path is wrapped: if anything throws, the
 *     shadow is abandoned and the emergency guard's decision stands untouched.
 *   - KILL SWITCH. Logging happens only while the flag file exists. Deleting it
 *     stops all shadow logging and leaves the emergency guard fully operative.
 *   - NO SENSITIVE DATA. Only the fields listed in the approved schema are
 *     written: never names, bank details, document contents, GPS coordinates,
 *     tokens or request bodies.
 *
 * ROLE RESOLUTION — READ THIS BEFORE TRUSTING A SHADOW LOG
 * -------------------------------------------------------
 * The previous version of context() assigned `super_admin` when is_admin() was
 * true and `employee` to everybody else, with an empty hierarchy and no
 * department or branch. That was correct for exactly one role — the only role
 * it had ever been exercised with. For a Team Leader it produced the wrong
 * verdict in 5 of 6 sampled cases, and every wrong verdict was a DENY that
 * happened to agree with the blanket emergency guard. A log full of agreement
 * would have been read as "no dangerous widening" while measuring nothing.
 *
 * So resolution is explicit, ordered, and every line records WHICH source was
 * used in the `rsrc` field:
 *
 *   admin      trusted Perfex Admin            -> super_admin
 *   assigned   active explicit assignment      -> that role
 *   temporary  valid time-boxed assignment     -> that role
 *   derived    reporting lines                 -> team_leader / manager
 *   default    nothing matched                 -> employee   (least privilege)
 *   unmapped   role name not in the vocabulary -> employee + Admin warning
 *   conflict   two different live assignments  -> employee + Admin warning
 *
 * Assignments come from `payplex_staff_policy_roles`, not from a settings
 * string: an authorization input needs history, expiry, approval and an audit
 * trail, and a free-form option row has none of those.
 *
 * `rsrc=default` on a person who is really an HR Manager means the comparison
 * on that line is meaningless. Read the distribution of `rsrc` before reading
 * the distribution of `agree`.
 */
/* Hard dependency. Required here rather than from the controller so the
 * deployed Staff.php needs no change and the class is usable standalone. */
if (!class_exists('Workforce_policy_roles')) {
    require_once __DIR__ . '/Workforce_policy_roles.php';
}

class Workforce_shadow
{
    /** Staging-only log directory, outside the public web root. */
    const LOG_DIR  = '/home/y4l6swih97e7/crm_logs_staging';
    const LOG_FILE = '/home/y4l6swih97e7/crm_logs_staging/shadow_policy.log';
    /** Kill switch: logging runs only while this file exists. */
    const FLAG     = '/home/y4l6swih97e7/crm_logs_staging/shadow_enabled';
    /** Hard cap so a runaway loop cannot fill the disk. */
    const MAX_BYTES = 5242880;          // 5 MB
    const MAX_PER_REQUEST = 40;
    /** Upper bound on rows pulled for the reporting map. */
    const MAX_HIER_ROWS = 2000;
    /** Upper bound on assignment history read per staff member. */
    const MAX_ASSIGN_ROWS = 200;

    /** One vocabulary, defined once, in Workforce_policy_roles. */
    public static function policyRoles()
    {
        return Workforce_policy_roles::vocabulary();
    }

    /** Configuration problems found while resolving. Surfaced on the Admin screen. */
    private static $warnings = array();
    public static function warnings() { return self::$warnings; }
    private static function warn($staffId, $code, $detail = '')
    {
        $k = (int) $staffId . '|' . $code . '|' . $detail;
        self::$warnings[$k] = array('staff_id' => (int) $staffId, 'code' => $code, 'detail' => $detail);
    }

    private static $seen = array();     // dedupe within a request
    private static $written = 0;
    private static $ctxCache = array(); // memoised read-only context
    private static $hier = null;        // memoised reporting map
    private static $assign = array();   // memoised policy-role assignments
    private static $profiles = array(); // memoised per-staff profile fields

    /** Staging only. Production must never reach this code path. */
    public static function isStaging()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        return strpos($host, 'staging.') === 0;
    }

    /** Kill switch + environment gate. */
    public static function enabled()
    {
        if (!self::isStaging()) { return false; }
        return @is_file(self::FLAG);
    }

    /* ===================================================================
     * READ-ONLY LOADERS
     * =================================================================== */

    /** staff_id => reporting_manager_id, loaded once per request. */
    public static function hierarchy($CI)
    {
        if (self::$hier !== null) { return self::$hier; }
        self::$hier = array();
        try {
            if ($CI && isset($CI->db)) {
                $t = db_prefix() . 'payplex_staff_profiles';
                if ($CI->db->table_exists($t)) {
                    $rows = $CI->db->select('staff_id, reporting_manager_id')
                                   ->where('reporting_manager_id >', 0)
                                   ->limit(self::MAX_HIER_ROWS)
                                   ->get($t)->result_array();
                    foreach ($rows as $r) {
                        $s = (int) $r['staff_id'];
                        $m = (int) $r['reporting_manager_id'];
                        if ($s > 0 && $m > 0 && $s !== $m) { self::$hier[$s] = $m; }
                    }
                }
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        return self::$hier;
    }

    /**
     * Live-and-historic policy-role assignments for one staff member.
     *
     * Read-only. Ordered newest-first so a deterministic pick is possible, but
     * selection is done by Workforce_policy_roles, not by ORDER BY: "the most
     * recent row wins" is exactly the rule that lets an expired or revoked row
     * quietly keep working.
     */
    public static function assignments($CI, $staffId)
    {
        $staffId = (int) $staffId;
        if (isset(self::$assign[$staffId])) { return self::$assign[$staffId]; }
        $rows = array();
        try {
            if ($CI && isset($CI->db) && $staffId > 0) {
                $t = db_prefix() . 'payplex_staff_policy_roles';
                if ($CI->db->table_exists($t)) {
                    $rows = $CI->db->select('id, staff_id, policy_role, business_entity_id, branch,
                                             department, region, effective_from, expires_at,
                                             is_active, revoked_at')
                                   ->where('staff_id', $staffId)
                                   ->order_by('id', 'DESC')
                                   ->limit(self::MAX_ASSIGN_ROWS)
                                   ->get($t)->result_array();
                }
            }
        } catch (Exception $e) {
            $rows = array();
        } catch (Throwable $e) {
            $rows = array();
        }
        self::$assign[$staffId] = $rows;
        return $rows;
    }

    /** department / branch / active for one staff id. Read-only, memoised. */
    public static function profile($CI, $staffId)
    {
        $staffId = (int) $staffId;
        if (isset(self::$profiles[$staffId])) { return self::$profiles[$staffId]; }
        $p = array('department' => '', 'branch' => '', 'active' => true);
        try {
            if ($CI && isset($CI->db) && $staffId > 0) {
                $t = db_prefix() . 'payplex_staff_profiles';
                if ($CI->db->table_exists($t)) {
                    $row = $CI->db->select('department, branch')
                                  ->where('staff_id', $staffId)->limit(1)
                                  ->get($t)->row_array();
                    if ($row) {
                        $p['department'] = isset($row['department']) ? (string) $row['department'] : '';
                        $p['branch']     = isset($row['branch'])     ? (string) $row['branch']     : '';
                    }
                }
                $st = db_prefix() . 'staff';
                if ($CI->db->table_exists($st)) {
                    $row = $CI->db->select('active')->where('staffid', $staffId)->limit(1)
                                  ->get($st)->row_array();
                    if ($row && isset($row['active'])) { $p['active'] = ((int) $row['active'] === 1); }
                }
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
        self::$profiles[$staffId] = $p;
        return $p;
    }

    /* ===================================================================
     * ROLE RESOLUTION
     * =================================================================== */

    /**
     * Derive a supervisory role from the reporting lines alone.
     *
     * Someone with at least one direct report is a team_leader; someone whose
     * reports themselves have reports is a manager. Returns '' when the person
     * supervises nobody — never a privileged role.
     */
    public static function deriveRole($staffId, array $hier)
    {
        $staffId = (int) $staffId;
        if ($staffId <= 0) { return ''; }
        /* A self-referential line (s => s) is corrupt data, not a report.
           Left in, it makes anybody who appears as their own manager derive
           'manager'. Dropped here as well as in hierarchy(), because this
           method is public and must not depend on its caller having cleaned up. */
        $directs = array();
        foreach ($hier as $s => $m) {
            $s = (int) $s; $m = (int) $m;
            if ($s === $m) { continue; }
            if ($m === $staffId) { $directs[] = $s; }
        }
        if (empty($directs)) { return ''; }
        foreach ($hier as $s => $m) {
            $s = (int) $s; $m = (int) $m;
            if ($s === $m) { continue; }
            if ($s === $staffId) { continue; }
            if (in_array($m, $directs, true)) { return 'manager'; }
        }
        return 'team_leader';
    }

    /**
     * Ordered, fail-closed resolution.
     *
     *   1  trusted Perfex Admin            -> super_admin
     *   2  active explicit assignment      -> that role
     *   3  valid temporary assignment      -> that role (source 'temporary')
     *   4  derivable hierarchy role        -> team_leader / manager
     *   5  employee                        -> fallback
     *
     * Expired, revoked and inactive assignments are not used. Conflicting live
     * assignments are NOT merged - they fail closed to least privilege and
     * report a conflict, because merging roles is how somebody ends up with
     * authority nobody granted.
     *
     * Returns array('role','source','scope').
     */
    public static function resolveRole($staffId, $isAdmin, array $assignments, array $hier, $nowTs = null)
    {
        $P = 'Workforce_policy_roles';
        $nowTs = ($nowTs === null) ? time() : (int) $nowTs;
        $staffId = (int) $staffId;

        if ($isAdmin) {
            return array('role' => $P::R_SUPER_ADMIN, 'source' => $P::SRC_ADMIN, 'scope' => array());
        }

        /*
         * An assignment belongs to exactly one person. The loader already
         * filters by staff_id in SQL, but this method is public and takes rows
         * as an argument, so it re-checks rather than trusting its caller: a
         * row carrying somebody else's staff_id must never confer a role here.
         */
        $mine = array();
        foreach ($assignments as $row) {
            if (!is_array($row)) { continue; }
            if (isset($row['staff_id']) && (int) $row['staff_id'] !== $staffId) { continue; }
            $mine[] = $row;
        }

        $sel = $P::selectAssignment($mine, $nowTs);
        if ($sel['source'] === $P::SRC_CONFLICT) {
            self::warn($staffId, 'conflicting_active_roles', $sel['notes']);
            return array('role' => $P::leastPrivilege(), 'source' => $P::SRC_CONFLICT, 'scope' => array());
        }
        if ($sel['source'] === $P::SRC_UNMAPPED) {
            self::warn($staffId, 'unmapped_role', $sel['notes']);
            return array('role' => $P::leastPrivilege(), 'source' => $P::SRC_UNMAPPED, 'scope' => array());
        }
        if ($sel['role'] !== null && $P::isKnownRole($sel['role'])) {
            return array('role' => $sel['role'], 'source' => $sel['source'], 'scope' => $sel['scope']);
        }

        $d = self::deriveRole($staffId, $hier);
        if ($d !== '') {
            return array('role' => $d, 'source' => $P::SRC_DERIVED, 'scope' => array());
        }
        return array('role' => $P::leastPrivilege(), 'source' => $P::SRC_DEFAULT, 'scope' => array());
    }

    /**
     * Overlay an assignment's scope onto a context.
     *
     * Pure and public so the rules below are testable without a database:
     *   - a field the assignment STATES replaces the profile value;
     *   - a field it leaves blank keeps the profile value - blank means
     *     "says nothing", never "unrestricted";
     *   - each field lands in its own slot, so branch can never stand in for
     *     department, nor entity for either.
     */
    public static function applyScope(array $ctx, array $scope)
    {
        if (isset($scope['department']) && $scope['department'] !== '') {
            $ctx['actor_department'] = (string) $scope['department'];
        }
        if (isset($scope['region']) && $scope['region'] !== '') {
            $ctx['actor_region'] = (string) $scope['region'];
        }
        if (isset($scope['branch']) && $scope['branch'] !== '') {
            $ctx['actor_region'] = (string) $scope['branch'];
        }
        if (isset($scope['business_entity_id']) && $scope['business_entity_id'] !== '') {
            $ctx['actor_entity'] = (string) $scope['business_entity_id'];
        }
        return $ctx;
    }

    /**
     * Build the decision context with READ-ONLY queries, memoised per request.
     * Anything unavailable is simply absent, and Workforce_scope fails closed
     * on absence rather than assuming a relationship.
     */
    public static function context($CI, $actorId, $targetId)
    {
        $key = (int) $actorId . ':' . (int) $targetId;
        if (isset(self::$ctxCache[$key])) { return self::$ctxCache[$key]; }

        $ctx = array(
            'actor_id'     => (int) $actorId,
            'target_id'    => (int) $targetId,
            'actor_roles'  => array('employee'),
            'actor_active' => true,
            'hierarchy'    => array(),
            'role_source'  => 'default',
        );

        try {
            $isAdmin = (function_exists('is_admin') && is_admin());
            $hier    = self::hierarchy($CI);
            $rows    = self::assignments($CI, (int) $actorId);

            $rr = self::resolveRole((int) $actorId, $isAdmin, $rows, $hier);
            $ctx['actor_roles'] = array($rr['role']);
            $ctx['role_source'] = $rr['source'];
            $ctx['hierarchy']   = $hier;

            $ap = self::profile($CI, (int) $actorId);
            $tp = self::profile($CI, (int) $targetId);
            $ctx['actor_active']      = $ap['active'];
            $ctx['actor_department']  = $ap['department'];
            $ctx['actor_region']      = $ap['branch'];
            $ctx['target_department'] = $tp['department'];
            $ctx['target_region']     = $tp['branch'];

            /*
             * An assignment's own scope replaces the profile's, and only for the
             * fields it actually states. It never widens: a field the assignment
             * leaves blank keeps the profile value rather than becoming
             * "unrestricted", and department/entity/region are set independently
             * so one can never stand in for another.
             */
            $ctx = self::applyScope($ctx, isset($rr['scope']) ? $rr['scope'] : array());
        } catch (Exception $e) {
            /* Context stays partial; the scope engine denies on absence. */
        } catch (Throwable $e) {
            /* PHP 7+ errors must not escape a shadow path. */
        }

        self::$ctxCache[$key] = $ctx;
        return $ctx;
    }

    /**
     * Compute the shadow decision and record the comparison.
     *
     * Returns VOID by design. There is no value here for a caller to mistake
     * for an authorization result.
     */
    public static function compare($route, $domain, $action, $guardAllowed, array $ctx, $relationshipHint = null)
    {
        try {
            if (!self::enabled()) { return; }
            if (self::$written >= self::MAX_PER_REQUEST) { return; }
            if (!class_exists('Workforce_scope')) { return; }

            $c = $ctx;
            $c['domain'] = $domain;
            $c['action'] = $action;
            $d = Workforce_scope::decide($c);

            $rel = $relationshipHint;
            if ($rel === null) {
                $r = Workforce_scope::relationships($c);
                $rels = isset($r['rels']) ? $r['rels'] : array();
                $rel = empty($rels) ? 'none' : implode('+', $rels);
            }

            $rsrc = isset($c['role_source']) ? (string) $c['role_source'] : 'default';

            /* Dedupe identical comparisons inside one request. */
            $sig = $route . '|' . $domain . '|' . $action . '|' . (int) $guardAllowed
                 . '|' . (int) !empty($d['allowed']) . '|' . $rel . '|' . $rsrc;
            if (isset(self::$seen[$sig])) { return; }
            self::$seen[$sig] = true;

            self::write(array(
                'ts'         => gmdate('c'),
                'op'         => (string) $route,
                'domain'     => (string) $domain,
                'action'     => (string) $action,
                'guard'      => $guardAllowed ? 'allow' : 'deny',
                'policy'     => !empty($d['allowed']) ? 'allow' : 'deny',
                'agree'      => ((bool) $guardAllowed === (bool) !empty($d['allowed'])) ? 1 : 0,
                'reason'     => isset($d['reason']) ? (string) $d['reason'] : '',
                'role'       => implode('+', isset($c['actor_roles']) ? $c['actor_roles'] : array()),
                'rel'        => (string) $rel,
                /* How the role was resolved. A log of agreements from rsrc=default
                   proves nothing; this field is what makes that visible. */
                'rsrc'       => $rsrc,
            ));
        } catch (Exception $e) {
            /* swallowed on purpose: shadow must never affect the response */
        } catch (Throwable $e) {
            /* swallowed on purpose */
        }
    }

    /**
     * Append one metadata line. Nothing here carries identifying or sensitive
     * content — no names, ids of records, bank fields, document bodies, GPS,
     * tokens, cookies or request bodies.
     */
    private static function write(array $meta)
    {
        try {
            if (!@is_dir(self::LOG_DIR)) { return; }
            if (@file_exists(self::LOG_FILE) && @filesize(self::LOG_FILE) > self::MAX_BYTES) { return; }

            $safe = array();
            foreach ($meta as $k => $v) {
                $v = preg_replace('/[^A-Za-z0-9_\-\+:\.\, ]/', '', (string) $v);
                $safe[] = $k . '=' . substr($v, 0, 64);
            }
            @file_put_contents(self::LOG_FILE, implode(' ', $safe) . "\n",
                FILE_APPEND | LOCK_EX);
            @chmod(self::LOG_FILE, 0600);
            self::$written++;
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }
    }

    /** Test seam: reset per-request memo. */
    public static function reset()
    {
        self::$seen = array(); self::$written = 0; self::$ctxCache = array();
        self::$hier = null; self::$assign = array(); self::$profiles = array();
        self::$warnings = array();
    }
}
