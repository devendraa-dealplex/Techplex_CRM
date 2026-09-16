<?php
defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Who can see whose records, and which grants nobody can see at all.
 *
 * WHAT WAS MEASURED ON STAGING, 2026-09-11
 * ----------------------------------------
 * Eighteen staff accounts. Reading tblstaff against tblstaff_permissions:
 *
 *  1. SIX ACCOUNTS HOLD CAPABILITIES WITH NO ROLE. Riya Kumari (#23), active,
 *     holds fifteen capabilities across eight features with role = 0. Those
 *     grants were written directly onto the staff member, so they appear on no
 *     Roles screen, belong to no role, and changing her role would change
 *     nothing. Nobody reviewing roles can see them. This is precisely what §4 of
 *     the brief means by keeping employment classification and system
 *     permissions separate and governed — a grant that exists outside the role
 *     system is ungoverned by definition.
 *
 *  2. THREE DEACTIVATED ACCOUNTS STILL HOLD THEIR CAPABILITIES (7, 9 and 11 of
 *     them). Harmless while the account cannot sign in, and instantly live again
 *     the moment somebody reactivates it — without anyone re-approving anything.
 *
 *  3. AN ACCOUNT FLAGGED "NOT A STAFF MEMBER" HOLDS NINETEEN CAPABILITIES.
 *     ARK Gupta (#29) carries is_not_staff = 1, a Sales Executive role and
 *     nineteen grants including the lead-generation control tower.
 *
 *  4. FIVE OF THE SEVEN PAYPLEX MODULES REACH NOBODY. Only payplex_commission
 *     (4 grants) and staff_classification (1) appear in tblstaff_permissions at
 *     all. payplex_staff, payplex_reports, payplex_ai_agents, payplex_all_leads
 *     and sales_targets have zero — every one of them is administrator-only in
 *     practice, whatever their Roles screen offers.
 *
 * None of the four is visible from any screen in this CRM. That is the point of
 * this library: the rules are pure and testable, and the module renders what
 * they find rather than leaving it to somebody to notice.
 *
 * DATA SCOPE
 * ----------
 * §4 of the brief lists seven scopes. Perfex offers two ('view' and 'view_own'),
 * and the Payplex modules added a third ('view_all'). The ladder below is the
 * full set, ordered, so a scope can be compared rather than guessed at, and so a
 * module asking "may this person see that row" gets one answer from one place.
 */
class Workforce_access
{
    /* Ordered weakest to strongest. Position matters: atLeast() compares it. */
    const SCOPE_NONE       = 'none';
    const SCOPE_OWN        = 'own';
    const SCOPE_ASSIGNED   = 'assigned';
    const SCOPE_TEAM       = 'team';
    const SCOPE_DEPARTMENT = 'department';
    const SCOPE_BRANCH     = 'branch';
    const SCOPE_COMPANY    = 'company';
    const SCOPE_ALL        = 'all';

    public static function ladder()
    {
        return array(self::SCOPE_NONE, self::SCOPE_OWN, self::SCOPE_ASSIGNED, self::SCOPE_TEAM,
                     self::SCOPE_DEPARTMENT, self::SCOPE_BRANCH, self::SCOPE_COMPANY, self::SCOPE_ALL);
    }

    public static function rank($scope)
    {
        $i = array_search((string) $scope, self::ladder(), true);
        return $i === false ? 0 : $i;
    }

    /** Does $have reach at least as far as $need? */
    public static function atLeast($have, $need)
    {
        return self::rank($have) >= self::rank($need);
    }

    /**
     * The scope a viewer actually holds, from the capabilities they actually
     * have. An administrator is 'all'; nothing else is assumed.
     *
     * @param array $caps   capability names held for the feature in question
     * @param bool  $isAdmin
     */
    public static function scopeFrom(array $caps, $isAdmin = false)
    {
        if ($isAdmin) { return self::SCOPE_ALL; }
        $map = array(
            'view_all'        => self::SCOPE_ALL,
            'view_company'    => self::SCOPE_COMPANY,
            'view_branch'     => self::SCOPE_BRANCH,
            'view_department' => self::SCOPE_DEPARTMENT,
            'view_team'       => self::SCOPE_TEAM,
            'view'            => self::SCOPE_ASSIGNED,
            'view_own'        => self::SCOPE_OWN,
        );
        $best = self::SCOPE_NONE;
        foreach ($caps as $c) {
            $c = strtolower(trim((string) $c));
            if (!isset($map[$c])) { continue; }
            if (self::rank($map[$c]) > self::rank($best)) { $best = $map[$c]; }
        }
        return $best;
    }

    /**
     * Which staff ids a viewer at $scope may see.
     *
     * @param int   $viewerId
     * @param string $scope
     * @param array $org  staff_id => array(manager_id, department_id, branch, company)
     * @return array|null list of staff ids, or null meaning "everyone"
     */
    public static function visibleStaffIds($viewerId, $scope, array $org)
    {
        $viewerId = (int) $viewerId;
        if (self::atLeast($scope, self::SCOPE_ALL)) { return null; }
        if ($scope === self::SCOPE_NONE) { return array(); }

        $me = isset($org[$viewerId]) ? $org[$viewerId] : array();
        $out = array($viewerId);

        if (self::atLeast($scope, self::SCOPE_TEAM)) {
            foreach ($org as $id => $row) {
                if ((int) (isset($row['manager_id']) ? $row['manager_id'] : 0) === $viewerId) { $out[] = (int) $id; }
            }
        }
        foreach (array(self::SCOPE_DEPARTMENT => 'department_id',
                       self::SCOPE_BRANCH     => 'branch',
                       self::SCOPE_COMPANY    => 'company') as $level => $key) {
            if (!self::atLeast($scope, $level)) { continue; }
            $mine = isset($me[$key]) ? $me[$key] : null;
            if ($mine === null || $mine === '') { continue; }
            foreach ($org as $id => $row) {
                if (isset($row[$key]) && (string) $row[$key] === (string) $mine) { $out[] = (int) $id; }
            }
        }
        return array_values(array_unique($out));
    }

    /* ==================================================================== *
     * The four things nobody could see
     * ==================================================================== */

    /**
     * Classify one staff row's grants.
     *
     * @param array $row staffid, admin, active, is_not_staff, role, caps
     * @return array list of findings: code, severity, message
     */
    public static function grantAnomalies(array $row)
    {
        $out     = array();
        $admin   = (int) (isset($row['admin']) ? $row['admin'] : 0) === 1;
        $active  = (int) (isset($row['active']) ? $row['active'] : 0) === 1;
        $notStaff= (int) (isset($row['is_not_staff']) ? $row['is_not_staff'] : 0) === 1;
        $role    = (int) (isset($row['role']) ? $row['role'] : 0);
        $caps    = (int) (isset($row['caps']) ? $row['caps'] : 0);

        if ($admin) {
            /* An administrator passes every gate on is_admin(); capability rows
               are irrelevant, and reporting them as anomalies is noise. */
            return $out;
        }

        if ($caps > 0 && $role <= 0) {
            $out[] = array('code' => 'ungoverned_grants', 'severity' => 'high',
                'message' => $caps . ' capabilit(ies) granted directly to this person, with no role. '
                           . 'They appear on no Roles screen and belong to no role, so changing the '
                           . 'role changes nothing and nobody reviewing roles can see them.');
        }
        if ($caps > 0 && !$active) {
            $out[] = array('code' => 'grants_on_inactive', 'severity' => 'medium',
                'message' => 'This account is deactivated and still holds ' . $caps . ' capabilit(ies). '
                           . 'They become live again the moment somebody reactivates it, with nobody '
                           . 're-approving anything.');
        }
        if ($caps > 0 && $notStaff) {
            $out[] = array('code' => 'grants_on_non_staff', 'severity' => 'high',
                'message' => 'This account is flagged "not a staff member" and holds ' . $caps
                           . ' capabilit(ies). Either the flag is wrong or the grants are.');
        }
        if ($role > 0 && $caps === 0) {
            $out[] = array('code' => 'role_without_grants', 'severity' => 'high',
                'message' => 'This person holds a role but no capability rows. Perfex copies a role\'s '
                           . 'capabilities onto the staff member when the role is assigned through its '
                           . 'own model; a row inserted another way skips that. Every gate answers no, '
                           . 'and the Roles screen says otherwise.');
        }
        return $out;
    }

    /**
     * The capabilities each Payplex module registers with Perfex.
     *
     * A literal map, and deliberately so: working it out at runtime means
     * parsing other modules' register_staff_capabilities() calls, and a parser
     * that quietly mis-reads one is worse than a list somebody has to keep.
     * Every name below was read from the modules' own registration on this
     * install on 2026-09-11.
     *
     * Measured the same day: of the 75 capabilities here, exactly 5 are held by
     * anybody — four on payplex_commission and one on staff_classification.
     * Seventy reach nobody, which is why every one of those modules is
     * administrator-only in practice however complete its Roles screen looks.
     */
    public static function payplexCapabilityMap()
    {
        return array(
            'payplex_staff' => array('view', 'manage', 'submit', 'verify', 'approve', 'lifecycle',
                'perm_templates', 'audit', 'activity', 'performance', 'kpi_config',
                'field_tracking', 'privacy_admin'),
            'payplex_commission' => array('view_own', 'view_all', 'compute', 'approve', 'pay',
                'rules', 'rules_approve', 'source_policy', 'source_policy_approve', 'review',
                'reject', 'audit_view', 'clawback', 'payout', 'payout_approve'),
            'payplex_reports' => array('view', 'view_all', 'export'),
            'payplex_aicalling' => array('view', 'view_all', 'create', 'cancel', 'retry',
                'recording_access', 'settings', 'campaign_view', 'campaign_create',
                'campaign_approve', 'consent_view', 'consent_manage', 'reconcile_view', 'reconcile_run'),
            'payplex_ai_agents' => array('view', 'create', 'edit', 'test', 'submit', 'approve',
                'activate', 'budgets', 'logs', 'knowledge', 'decisions', 'chairman', 'companies',
                'exec_knowledge', 'exec_knowledge_approve', 'goals', 'comms', 'council_vote', 'ratify'),
            'sales_targets' => array('view_team', 'view_all', 'create', 'edit_draft', 'submit',
                'approve_activate', 'revise_active', 'lock_complete', 'cancel_archive'),
            'staff_classification' => array('view', 'edit'),
        );
    }

    /**
     * Which registered capabilities reach nobody.
     *
     * @param array $registered feature => list of capability names
     * @param array $granted    feature => list of capability names held by someone
     */
    public static function unreachedCapabilities(array $registered, array $granted)
    {
        $out = array();
        foreach ($registered as $feature => $caps) {
            $have = isset($granted[$feature]) ? array_map('strtolower', (array) $granted[$feature]) : array();
            $missing = array();
            foreach ((array) $caps as $c) {
                if (!in_array(strtolower((string) $c), $have, true)) { $missing[] = $c; }
            }
            if ($missing) {
                $out[$feature] = array(
                    'unreached' => $missing,
                    'total'     => count((array) $caps),
                    'message'   => count($missing) . ' of ' . count((array) $caps) . ' capabilities in "'
                                 . $feature . '" are held by nobody. Until somebody holds one, the '
                                 . 'feature is administrator-only whatever its Roles screen offers.',
                );
            }
        }
        return $out;
    }
}
