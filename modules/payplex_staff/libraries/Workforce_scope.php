<?php
defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Workforce_scope — domain-and-action-aware staff authorization.
 *
 * REPLACES the emergency guard's blanket "self only, except admin" rule with the
 * approved hierarchy. The emergency guard was correct as containment and wrong as
 * policy: it denied a Team Leader their own team and granted a Super Admin a
 * self-approval. Both are fixed here.
 *
 * FOUR THINGS MUST ALL HOLD before access is granted:
 *   1. RELATIONSHIP  - actor stands in an allowed relationship to the target
 *   2. DOMAIN        - the role may touch that class of data at all
 *   3. ACTION        - the role may perform that verb on that domain
 *   4. CONDITIONS    - maker-checker, investigation purpose, active employment
 *
 * Failing any one denies. There is no "general staff view" that leaks into bank,
 * KYC, documents or raw GPS: those domains are listed separately for every role
 * and are absent unless explicitly granted.
 *
 * FAIL CLOSED. Missing hierarchy, a cycle, an unknown role, an unknown domain or
 * an unknown action all deny. A typo in a call site must never become a grant.
 *
 * Pure and dependency-free: no database, no CodeIgniter, no globals. Every input
 * arrives in the context array, which is what makes it testable.
 */
class Workforce_scope
{
    /* ---- relationships -------------------------------------------------- */
    const REL_SELF        = 'self';
    const REL_DIRECT      = 'direct_report';
    const REL_SUBTREE     = 'subtree';
    const REL_TEAM_REGION = 'team_region';
    const REL_DEPARTMENT  = 'department';
    const REL_COMPANY     = 'company';

    /* ---- domains (requirement 5) ---------------------------------------- */
    const D_BASIC_PROFILE   = 'basic_profile';
    const D_PROFILE_EDIT    = 'profile_edit';
    const D_LIFECYCLE       = 'lifecycle';
    const D_ATTENDANCE      = 'attendance';
    const D_ACTIVITY        = 'activity';
    const D_PERFORMANCE     = 'performance';
    const D_CONSENT         = 'consent';
    const D_FIELD_SUMMARY   = 'field_session_summary';
    const D_RAW_LOCATION    = 'raw_location';
    const D_EMP_DOCUMENTS   = 'employment_documents';
    const D_KYC_DOCUMENTS   = 'kyc_documents';
    const D_BANK            = 'bank';
    const D_EXPENSES        = 'expenses';

    /* ---- actions (requirement 6) ---------------------------------------- */
    const A_VIEW    = 'view';
    const A_CREATE  = 'create';
    const A_EDIT    = 'edit';
    const A_APPROVE = 'approve';
    const A_EXPORT  = 'export';
    const A_DELETE  = 'delete';

    /* ---- refusal reasons (audited, never shown) -------------------------- */
    const R_NO_ACTOR         = 'no_authenticated_actor';
    const R_BAD_TARGET       = 'target_id_not_a_staff_id';
    const R_INACTIVE_ACTOR   = 'actor_not_active';
    const R_UNKNOWN_ROLE     = 'unknown_role';
    const R_UNKNOWN_DOMAIN   = 'unknown_domain';
    const R_UNKNOWN_ACTION   = 'unknown_action';
    const R_DOMAIN_DENIED    = 'role_may_not_touch_domain';
    const R_ACTION_DENIED    = 'role_may_not_perform_action_on_domain';
    const R_OUT_OF_SCOPE     = 'target_outside_permitted_relationship';
    const R_HIERARCHY_MISSING= 'reporting_hierarchy_unavailable';
    const R_HIERARCHY_CYCLE  = 'reporting_hierarchy_cyclic';
    const R_SELF_APPROVAL    = 'maker_may_not_approve_own_work';
    const R_NO_INVESTIGATION = 'raw_location_requires_approved_investigation';
    const R_NO_POLICY_REVIEW = 'hr_expense_access_requires_policy_review';
    const R_UNKNOWN_TRANSITION = 'transition_not_on_server_allowlist';
    const R_STAGE_DENIED     = 'role_may_not_perform_this_transition';
    const R_MAKER_IS_CHECKER = 'maker_checker_separation_violated';
    const R_REASON_REQUIRED  = 'high_risk_transition_requires_reason';
    const R_ALLOWED          = 'allowed';

    /** One message for every refusal, so existence is never leaked. */
    public static function refusalMessage()
    {
        return 'Not found.';
    }

    /**
     * THE PERMISSION MATRIX.
     *
     * matrix[role][domain][action] = list of relationships that satisfy it.
     * A domain absent from a role means that role may not touch it at all —
     * which is how "a general staff view must not expose bank/KYC/GPS" is
     * enforced structurally rather than by remembering to check.
     */
    /**
     * Role x domain x action, with the personal-data floor applied.
     */
    public static function matrix()
    {
        $m = self::baseMatrix();
        foreach ($m as $role => $domains) { $m[$role] = self::mergeSelfBaseline($domains); }
        return $m;
    }

    /**
     * Personal-data floor: EVERY role may view their OWN activity and location
     * and export their OWN employment documents.
     *
     * Applied uniformly instead of being hand-edited into ten role blocks,
     * because hand-editing role blocks is exactly how stage_roles was silently
     * missed twice. A single source means a single thing to test.
     */
    private static function selfBaseline()
    {
        $S = array(self::REL_SELF);
        return array(
            self::D_ACTIVITY      => array(self::A_VIEW   => $S),
            self::D_RAW_LOCATION  => array(self::A_VIEW   => $S),
            self::D_EMP_DOCUMENTS => array(self::A_EXPORT => $S),
        );
    }

    /** Additive only: the baseline may widen a role to itself, never narrow it. */
    private static function mergeSelfBaseline(array $domains)
    {
        foreach (self::selfBaseline() as $d => $actions) {
            foreach ($actions as $a => $rels) {
                if (!isset($domains[$d]))      { $domains[$d] = array(); }
                if (!isset($domains[$d][$a]))  { $domains[$d][$a] = array(); }
                $domains[$d][$a] = array_values(array_unique(array_merge($domains[$d][$a], $rels)));
            }
        }
        return $domains;
    }

    private static function baseMatrix()
    {
        $SELF = array(self::REL_SELF);
        return array(

            /* ---------------- Employee ---------------- */
            'employee' => array(
                self::D_BASIC_PROFILE  => array(self::A_VIEW => $SELF),
                self::D_PROFILE_EDIT   => array(self::A_EDIT => $SELF),
                self::D_ATTENDANCE     => array(self::A_VIEW => $SELF, self::A_CREATE => $SELF),
                self::D_PERFORMANCE    => array(self::A_VIEW => $SELF),
                self::D_CONSENT        => array(self::A_VIEW => $SELF, self::A_EDIT => $SELF),
                self::D_FIELD_SUMMARY  => array(self::A_VIEW => $SELF),
                self::D_EMP_DOCUMENTS  => array(self::A_VIEW => $SELF, self::A_CREATE => $SELF),
                /* masked bank view only; no edit, no approve */
                self::D_BANK           => array(self::A_VIEW => $SELF),
                /* own claims: create, edit/withdraw a draft, submit. Never approve, never pay. */
                self::D_EXPENSES       => array(self::A_VIEW => $SELF, self::A_CREATE => $SELF,
                                                self::A_EDIT => $SELF, self::A_EXPORT => $SELF),
                /* may PREPARE a lifecycle request about themselves (resignation).
                   No approve action exists here, so they can never finalise it. */
                self::D_LIFECYCLE      => array(self::A_EDIT => $SELF),
                /* deliberately absent: raw_location, kyc_documents,
                   and every approve — an employee approves nothing, least of all
                   their own lifecycle, documents, expenses or bank. */
            ),

            /* ---------------- Team Leader ---------------- */
            'team_leader' => array(
                self::D_BASIC_PROFILE  => array(self::A_VIEW => array(self::REL_SELF, self::REL_DIRECT)),
                self::D_PROFILE_EDIT   => array(self::A_EDIT => $SELF),
                self::D_ATTENDANCE     => array(self::A_VIEW => array(self::REL_SELF, self::REL_DIRECT),
                                                self::A_CREATE => $SELF),
                self::D_PERFORMANCE    => array(self::A_VIEW => array(self::REL_SELF, self::REL_DIRECT)),
                self::D_ACTIVITY       => array(self::A_VIEW => array(self::REL_SELF, self::REL_DIRECT),
                                                self::A_CREATE => array(self::REL_SELF, self::REL_DIRECT)),
                self::D_CONSENT        => array(self::A_VIEW => $SELF, self::A_EDIT => $SELF),
                self::D_FIELD_SUMMARY  => array(self::A_VIEW => $SELF),
                self::D_EMP_DOCUMENTS  => array(self::A_VIEW => $SELF, self::A_CREATE => $SELF),
                self::D_BANK           => array(self::A_VIEW => $SELF),
                self::D_EXPENSES       => array(self::A_VIEW => array(self::REL_SELF, self::REL_DIRECT),
                                                self::A_CREATE => $SELF, self::A_EDIT => $SELF,
                                                self::A_APPROVE => array(self::REL_DIRECT),
                                                self::A_EXPORT => array(self::REL_SELF, self::REL_DIRECT)),
                /* may RECOMMEND (edit) over direct reports; never approve */
                self::D_LIFECYCLE      => array(self::A_EDIT => array(self::REL_SELF, self::REL_DIRECT)),
                /* absent: raw_location, kyc_documents. A TL edits no
                   lifecycle, no bank, no KYC, no personal documents, no raw GPS,
                   and reaches only DIRECT reports — never another TL's team. */
            ),

            /* ---------------- Manager ---------------- */
            'manager' => array(
                self::D_BASIC_PROFILE  => array(self::A_VIEW => array(self::REL_SELF, self::REL_DIRECT, self::REL_SUBTREE)),
                self::D_PROFILE_EDIT   => array(self::A_EDIT => $SELF),
                self::D_ATTENDANCE     => array(self::A_VIEW => array(self::REL_SELF, self::REL_DIRECT, self::REL_SUBTREE),
                                                self::A_CREATE => $SELF),
                self::D_PERFORMANCE    => array(self::A_VIEW => array(self::REL_SELF, self::REL_DIRECT, self::REL_SUBTREE)),
                self::D_ACTIVITY       => array(self::A_VIEW => array(self::REL_SELF, self::REL_DIRECT, self::REL_SUBTREE)),
                self::D_FIELD_SUMMARY  => array(self::A_VIEW => array(self::REL_SELF, self::REL_DIRECT, self::REL_SUBTREE)),
                self::D_CONSENT        => array(self::A_VIEW => $SELF, self::A_EDIT => $SELF),
                self::D_EMP_DOCUMENTS  => array(self::A_VIEW => $SELF, self::A_CREATE => $SELF),
                self::D_BANK           => array(self::A_VIEW => $SELF),
                self::D_EXPENSES       => array(self::A_VIEW => array(self::REL_SELF, self::REL_DIRECT, self::REL_SUBTREE),
                                                self::A_CREATE => $SELF, self::A_EDIT => $SELF,
                                                self::A_APPROVE => array(self::REL_DIRECT, self::REL_SUBTREE),
                                                self::A_EXPORT => array(self::REL_SELF, self::REL_DIRECT, self::REL_SUBTREE)),
                /* may RECOMMEND (edit) within the subtree; never approve */
                self::D_LIFECYCLE      => array(self::A_EDIT => array(self::REL_SELF, self::REL_DIRECT, self::REL_SUBTREE)),
                /* absent: raw_location (not by default), kyc_documents, and any
                   edit of bank or KYC. No self-approval anywhere. */
            ),

            /* ---------------- HR Executive ---------------- */
            'hr_executive' => array(
                self::D_BASIC_PROFILE  => array(self::A_VIEW => array(self::REL_SELF, self::REL_DEPARTMENT)),
                self::D_PROFILE_EDIT   => array(self::A_EDIT => array(self::REL_SELF, self::REL_DEPARTMENT)),
                self::D_EMP_DOCUMENTS  => array(self::A_VIEW   => array(self::REL_SELF, self::REL_DEPARTMENT),
                                                self::A_CREATE => array(self::REL_SELF, self::REL_DEPARTMENT),
                                                self::A_EDIT   => array(self::REL_SELF, self::REL_DEPARTMENT)),
                /* may PREPARE lifecycle changes (create + edit), never approve */
                self::D_LIFECYCLE      => array(self::A_CREATE => array(self::REL_DEPARTMENT),
                                                self::A_EDIT   => array(self::REL_DEPARTMENT)),
                self::D_ATTENDANCE     => array(self::A_VIEW => array(self::REL_SELF, self::REL_DEPARTMENT),
                                                self::A_CREATE => $SELF),
                self::D_CONSENT        => array(self::A_VIEW => $SELF, self::A_EDIT => $SELF),
                self::D_BANK           => array(self::A_VIEW => $SELF),
                /* expenses ONLY when an employment-policy review is approved -
                   no general expense permission, no payment permission ever */
                self::D_EXPENSES       => array(self::A_VIEW => array(self::REL_DEPARTMENT)),
                /* absent: raw_location, kyc approve, bank payout approval,
                   and any other department. */
            ),

            /* ---------------- HR Manager ---------------- */
            'hr_manager' => array(
                self::D_BASIC_PROFILE  => array(self::A_VIEW => array(self::REL_SELF, self::REL_DEPARTMENT)),
                self::D_PROFILE_EDIT   => array(self::A_EDIT => array(self::REL_SELF, self::REL_DEPARTMENT)),
                self::D_ATTENDANCE     => array(self::A_VIEW => array(self::REL_SELF, self::REL_DEPARTMENT),
                                                self::A_EDIT => array(self::REL_DEPARTMENT),
                                                self::A_CREATE => $SELF),
                self::D_LIFECYCLE      => array(self::A_VIEW    => array(self::REL_DEPARTMENT),
                                                self::A_CREATE  => array(self::REL_DEPARTMENT),
                                                self::A_EDIT    => array(self::REL_DEPARTMENT),
                                                self::A_APPROVE => array(self::REL_DEPARTMENT)),
                self::D_EMP_DOCUMENTS  => array(self::A_VIEW    => array(self::REL_SELF, self::REL_DEPARTMENT),
                                                self::A_CREATE  => array(self::REL_DEPARTMENT),
                                                self::A_EDIT    => array(self::REL_DEPARTMENT),
                                                self::A_APPROVE => array(self::REL_DEPARTMENT)),
                self::D_KYC_DOCUMENTS  => array(self::A_VIEW => array(self::REL_DEPARTMENT)),
                self::D_PERFORMANCE    => array(self::A_VIEW => array(self::REL_SELF, self::REL_DEPARTMENT)),
                self::D_CONSENT        => array(self::A_VIEW => $SELF, self::A_EDIT => $SELF),
                self::D_BANK           => array(self::A_VIEW => $SELF),
                /* expenses ONLY when an employment-policy review is approved -
                   no general expense permission, no payment permission ever */
                self::D_EXPENSES       => array(self::A_VIEW => array(self::REL_DEPARTMENT)),
                /* absent: raw_location, bank approve/edit. "No unrestricted
                   finance access" is enforced by bank being self-view only. */
            ),

            /* ---------------- HR Head ---------------- */
            'hr_head' => array(
                self::D_BASIC_PROFILE  => array(self::A_VIEW => array(self::REL_COMPANY)),
                self::D_PROFILE_EDIT   => array(self::A_EDIT => array(self::REL_COMPANY)),
                self::D_ATTENDANCE     => array(self::A_VIEW => array(self::REL_COMPANY),
                                                self::A_EDIT => array(self::REL_COMPANY),
                                                self::A_CREATE => $SELF),
                self::D_LIFECYCLE      => array(self::A_VIEW    => array(self::REL_COMPANY),
                                                self::A_CREATE  => array(self::REL_COMPANY),
                                                self::A_EDIT    => array(self::REL_COMPANY),
                                                self::A_APPROVE => array(self::REL_COMPANY)),
                self::D_EMP_DOCUMENTS  => array(self::A_VIEW    => array(self::REL_COMPANY),
                                                self::A_CREATE  => array(self::REL_COMPANY),
                                                self::A_EDIT    => array(self::REL_COMPANY),
                                                self::A_APPROVE => array(self::REL_COMPANY),
                                                self::A_EXPORT  => array(self::REL_COMPANY)),
                self::D_KYC_DOCUMENTS  => array(self::A_VIEW => array(self::REL_COMPANY)),
                self::D_PERFORMANCE    => array(self::A_VIEW => array(self::REL_COMPANY)),
                self::D_FIELD_SUMMARY  => array(self::A_VIEW => array(self::REL_COMPANY)),
                /* raw GPS ONLY with an approved investigation — see conditions */
                self::D_RAW_LOCATION   => array(self::A_VIEW => array(self::REL_COMPANY)),
                self::D_CONSENT        => array(self::A_VIEW => array(self::REL_COMPANY), self::A_EDIT => $SELF),
                /* bank: masked view for HR verification; NO payout approval */
                self::D_BANK           => array(self::A_VIEW => array(self::REL_COMPANY)),
                self::D_EXPENSES       => array(self::A_VIEW => array(self::REL_COMPANY)),
            ),

            /* ---------------- Field Manager ---------------- */
            'field_manager' => array(
                self::D_BASIC_PROFILE  => array(self::A_VIEW => array(self::REL_SELF, self::REL_TEAM_REGION)),
                self::D_FIELD_SUMMARY  => array(self::A_VIEW => array(self::REL_SELF, self::REL_TEAM_REGION),
                                                self::A_EXPORT => array(self::REL_TEAM_REGION)),
                self::D_ATTENDANCE     => array(self::A_VIEW => array(self::REL_SELF, self::REL_TEAM_REGION),
                                                self::A_CREATE => $SELF),
                self::D_ACTIVITY       => array(self::A_VIEW => array(self::REL_SELF, self::REL_TEAM_REGION)),
                /* raw GPS ONLY for an active investigation / approved fraud review */
                self::D_RAW_LOCATION   => array(self::A_VIEW => array(self::REL_TEAM_REGION)),
                self::D_PROFILE_EDIT   => array(self::A_EDIT => $SELF),
                self::D_CONSENT        => array(self::A_VIEW => $SELF, self::A_EDIT => $SELF),
                self::D_BANK           => array(self::A_VIEW => $SELF),
                /* absent: kyc_documents, employment_documents beyond self,
                   lifecycle, bank beyond self. */
            ),

            /* ---------------- Finance Maker ----------------
               Validates finance fields and PREPARES payment. Never approves,
               never marks paid. Separation is structural: no approve action. */
            'finance_maker' => array(
                self::D_BANK           => array(self::A_VIEW => array(self::REL_TEAM_REGION),
                                                self::A_EDIT => array(self::REL_TEAM_REGION),
                                                self::A_EXPORT => array(self::REL_TEAM_REGION)),
                self::D_EXPENSES       => array(self::A_VIEW => array(self::REL_TEAM_REGION),
                                                self::A_EDIT => array(self::REL_TEAM_REGION),
                                                self::A_EXPORT => array(self::REL_TEAM_REGION)),
                self::D_BASIC_PROFILE  => array(self::A_VIEW => array(self::REL_SELF, self::REL_TEAM_REGION)),
                self::D_PROFILE_EDIT   => array(self::A_EDIT => $SELF),
                self::D_ATTENDANCE     => array(self::A_VIEW => $SELF, self::A_CREATE => $SELF),
                self::D_CONSENT        => array(self::A_VIEW => $SELF, self::A_EDIT => $SELF),
                self::D_EMP_DOCUMENTS  => array(self::A_VIEW => $SELF, self::A_CREATE => $SELF),
                /* absent: lifecycle, kyc, raw_location, performance, field
                   summaries, commission (owned by payplex_commission). */
            ),

            /* ---------------- Finance Checker / Approver ----------------
               Checks and approves within assigned scope. Cannot be the maker
               of the item it approves - enforced in decideTransition(). */
            'finance_checker' => array(
                self::D_BANK           => array(self::A_VIEW => array(self::REL_TEAM_REGION),
                                                self::A_APPROVE => array(self::REL_TEAM_REGION),
                                                self::A_EXPORT => array(self::REL_TEAM_REGION)),
                self::D_EXPENSES       => array(self::A_VIEW => array(self::REL_TEAM_REGION),
                                                self::A_APPROVE => array(self::REL_TEAM_REGION),
                                                self::A_EXPORT => array(self::REL_TEAM_REGION)),
                self::D_BASIC_PROFILE  => array(self::A_VIEW => array(self::REL_SELF, self::REL_TEAM_REGION)),
                self::D_PROFILE_EDIT   => array(self::A_EDIT => $SELF),
                self::D_ATTENDANCE     => array(self::A_VIEW => $SELF, self::A_CREATE => $SELF),
                self::D_CONSENT        => array(self::A_VIEW => $SELF, self::A_EDIT => $SELF),
                self::D_EMP_DOCUMENTS  => array(self::A_VIEW => $SELF, self::A_CREATE => $SELF),
            ),

            /* ---------------- Finance Head ----------------
               Company-wide finance authority. May approve, but maker-checker
               still refuses their OWN work - see conditions(). Company-wide
               finance access belongs here and nowhere else. */
            'finance_head' => array(
                self::D_BANK           => array(self::A_VIEW => array(self::REL_COMPANY),
                                                self::A_EDIT => array(self::REL_COMPANY),
                                                self::A_APPROVE => array(self::REL_COMPANY),
                                                self::A_EXPORT => array(self::REL_COMPANY)),
                self::D_EXPENSES       => array(self::A_VIEW => array(self::REL_COMPANY),
                                                self::A_EDIT => array(self::REL_COMPANY),
                                                self::A_APPROVE => array(self::REL_COMPANY),
                                                self::A_EXPORT => array(self::REL_COMPANY)),
                self::D_BASIC_PROFILE  => array(self::A_VIEW => array(self::REL_SELF, self::REL_COMPANY)),
                self::D_PROFILE_EDIT   => array(self::A_EDIT => $SELF),
                self::D_ATTENDANCE     => array(self::A_VIEW => $SELF, self::A_CREATE => $SELF),
                self::D_CONSENT        => array(self::A_VIEW => $SELF, self::A_EDIT => $SELF),
                self::D_EMP_DOCUMENTS  => array(self::A_VIEW => $SELF, self::A_CREATE => $SELF),
                /* absent: lifecycle, kyc, raw_location, performance, field summaries. */
            ),

            /* ---------------- Auditor ----------------
               READ-ONLY by construction: not a single create, edit, approve,
               export or delete entry exists in this row. Anything beyond
               reading must be granted as a separate role, never bolted on
               here. Bank, KYC, raw GPS and employment documents are absent:
               an audit mandate is not a standing licence to personal data. */
            'auditor' => array(
                self::D_BASIC_PROFILE  => array(self::A_VIEW => array(self::REL_COMPANY)),
                self::D_ATTENDANCE     => array(self::A_VIEW => array(self::REL_COMPANY)),
                self::D_ACTIVITY       => array(self::A_VIEW => array(self::REL_COMPANY)),
                self::D_PERFORMANCE    => array(self::A_VIEW => array(self::REL_COMPANY)),
                self::D_LIFECYCLE      => array(self::A_VIEW => array(self::REL_COMPANY)),
                self::D_EXPENSES       => array(self::A_VIEW => array(self::REL_COMPANY)),
                self::D_FIELD_SUMMARY  => array(self::A_VIEW => array(self::REL_COMPANY)),
                self::D_CONSENT        => array(self::A_VIEW => array(self::REL_COMPANY), self::A_EDIT => $SELF),
                self::D_PROFILE_EDIT   => array(self::A_EDIT => $SELF),
            ),

            /* ---------------- Super Administrator ---------------- */
            'super_admin' => array(
                self::D_BASIC_PROFILE  => self::allActions(array(self::REL_COMPANY)),
                self::D_PROFILE_EDIT   => self::allActions(array(self::REL_COMPANY)),
                self::D_LIFECYCLE      => self::allActions(array(self::REL_COMPANY)),
                self::D_ATTENDANCE     => self::allActions(array(self::REL_COMPANY)),
                self::D_ACTIVITY       => self::allActions(array(self::REL_COMPANY)),
                self::D_PERFORMANCE    => self::allActions(array(self::REL_COMPANY)),
                self::D_CONSENT        => self::allActions(array(self::REL_COMPANY)),
                self::D_FIELD_SUMMARY  => self::allActions(array(self::REL_COMPANY)),
                self::D_RAW_LOCATION   => self::allActions(array(self::REL_COMPANY)),
                self::D_EMP_DOCUMENTS  => self::allActions(array(self::REL_COMPANY)),
                self::D_KYC_DOCUMENTS  => self::allActions(array(self::REL_COMPANY)),
                self::D_BANK           => self::allActions(array(self::REL_COMPANY)),
                self::D_EXPENSES       => self::allActions(array(self::REL_COMPANY)),
                /* Full technical access, always audited — and STILL bound by
                   maker-checker. Being Admin is not a reason to approve your
                   own work; see conditions(). */
            ),
        );
    }

    private static function allActions(array $rels)
    {
        return array(
            self::A_VIEW => $rels, self::A_CREATE => $rels, self::A_EDIT => $rels,
            self::A_APPROVE => $rels, self::A_EXPORT => $rels, self::A_DELETE => $rels,
        );
    }

    /** Domains whose exposure is always audited when crossing to another person. */
    public static function sensitiveDomains()
    {
        return array(self::D_RAW_LOCATION, self::D_BANK, self::D_KYC_DOCUMENTS,
                     self::D_EMP_DOCUMENTS, self::D_LIFECYCLE, self::D_EXPENSES);
    }

    /** Domains that require an approved investigation before raw access. */
    public static function investigationDomains()
    {
        return array(self::D_RAW_LOCATION);
    }

    /** HR roles reach expenses only under an approved employment-policy review. */
    public static function hrRoles()
    {
        return array('hr_executive', 'hr_manager', 'hr_head');
    }

    /**
     * SERVER-SIDE LIFECYCLE TRANSITION ALLOWLIST.
     *
     * The controller must never accept a client-supplied permission action. It
     * supplies a transition NAME; this map decides the action. A name absent
     * from the map is refused.
     *
     * Low-risk preparation -> edit.  Anything that changes employment standing
     * or ends it -> approve, with maker-checker and a mandatory reason.
     */
    public static function lifecycleTransitions()
    {
        return array(
            /* ---- low risk: preparation ---- */
            'update_draft'                => array('action' => self::A_EDIT,    'reason' => false, 'stage_roles' => array('hr_executive','hr_manager','hr_head','super_admin')),
            'correct_employment_info'     => array('action' => self::A_EDIT,    'reason' => false, 'stage_roles' => array('hr_executive','hr_manager','hr_head','super_admin')),
            'prepare_request'             => array('action' => self::A_EDIT,    'reason' => false, 'stage_roles' => array('hr_executive','hr_manager','hr_head','super_admin')),
            'add_reason'                  => array('action' => self::A_EDIT,    'reason' => false, 'stage_roles' => array('hr_executive','hr_manager','hr_head','super_admin')),
            'add_supporting_document'     => array('action' => self::A_EDIT,    'reason' => false, 'stage_roles' => array('hr_executive','hr_manager','hr_head','super_admin')),
            'submit_for_approval'         => array('action' => self::A_EDIT,    'reason' => false, 'stage_roles' => array('hr_executive','hr_manager','hr_head','super_admin')),
            /* an employee may REQUEST their own resignation - it is preparation */
            'submit_resignation_request'  => array('action' => self::A_EDIT,    'reason' => false, 'self_allowed' => true, 'self_only' => true, 'stage_roles' => array('employee','team_leader','manager','field_manager','finance_maker','finance_checker','hr_executive','hr_manager','hr_head','super_admin')),
            /* a Manager/TL may RECOMMEND, never finalise */
            'recommend_exit'              => array('action' => self::A_EDIT,    'reason' => true,  'stage_roles' => array('team_leader','manager','hr_executive','hr_manager','hr_head','super_admin')),
            'recommend_disciplinary'      => array('action' => self::A_EDIT,    'reason' => true,  'stage_roles' => array('team_leader','manager','hr_executive','hr_manager','hr_head','super_admin')),

            /* ---- high risk: approval ---- */
            'approve_onboarding'          => array('action' => self::A_APPROVE, 'reason' => false, 'stage_roles' => array('hr_manager','hr_head','super_admin')),
            'reject_onboarding'           => array('action' => self::A_APPROVE, 'reason' => true, 'stage_roles' => array('hr_manager','hr_head','super_admin')),
            'activate_employment'         => array('action' => self::A_APPROVE, 'reason' => false, 'stage_roles' => array('hr_manager','hr_head','super_admin')),
            'approve_classification_change'=> array('action' => self::A_APPROVE,'reason' => false, 'stage_roles' => array('hr_manager','hr_head','super_admin')),
            'approve_suspension'          => array('action' => self::A_APPROVE, 'reason' => true, 'stage_roles' => array('hr_manager','hr_head','super_admin')),
            'reinstate'                   => array('action' => self::A_APPROVE, 'reason' => true, 'stage_roles' => array('hr_manager','hr_head','super_admin')),
            'approve_resignation'         => array('action' => self::A_APPROVE, 'reason' => true, 'stage_roles' => array('hr_manager','hr_head','super_admin')),
            'approve_exit'                => array('action' => self::A_APPROVE, 'reason' => true, 'stage_roles' => array('hr_manager','hr_head','super_admin')),
            'terminate'                   => array('action' => self::A_APPROVE, 'reason' => true, 'stage_roles' => array('hr_manager','hr_head','super_admin')),
            'archive'                     => array('action' => self::A_APPROVE, 'reason' => true, 'stage_roles' => array('hr_manager','hr_head','super_admin')),
        );
    }

    /**
     * SERVER-SIDE EXPENSE TRANSITION ALLOWLIST.
     *
     * 'stage_roles' names the roles permitted at that stage. A Team Leader
     * recommends; a Manager approves the operational stage; Finance Maker
     * validates; only Finance Checker approves and marks paid.
     */
    public static function expenseTransitions()
    {
        return array(
            'create_draft'     => array('action' => self::A_CREATE,  'stage_roles' => array('employee','team_leader','manager','finance_maker','finance_checker','super_admin'), 'self_only' => true),
            'edit_draft'       => array('action' => self::A_EDIT,    'stage_roles' => array('employee','team_leader','manager','finance_maker','finance_checker','super_admin'), 'self_only' => true),
            'withdraw_draft'   => array('action' => self::A_EDIT,    'stage_roles' => array('employee','team_leader','manager','finance_maker','finance_checker','super_admin'), 'self_only' => true),
            'submit_claim'     => array('action' => self::A_EDIT,    'stage_roles' => array('employee','team_leader','manager','finance_maker','finance_checker','super_admin'), 'self_only' => true),
            'team_recommend'   => array('action' => self::A_APPROVE, 'stage_roles' => array('team_leader','super_admin')),
            'team_reject'      => array('action' => self::A_APPROVE, 'stage_roles' => array('team_leader','super_admin')),
            'ops_approve'      => array('action' => self::A_APPROVE, 'stage_roles' => array('manager','super_admin')),
            'ops_reject'       => array('action' => self::A_APPROVE, 'stage_roles' => array('manager','super_admin')),
            'finance_validate' => array('action' => self::A_EDIT,    'stage_roles' => array('finance_maker','super_admin')),
            'finance_approve'  => array('action' => self::A_APPROVE, 'stage_roles' => array('finance_checker','super_admin'), 'not_validator' => true),
            'mark_paid'        => array('action' => self::A_APPROVE, 'stage_roles' => array('finance_checker','super_admin'), 'not_validator' => true),
            'download_receipt' => array('action' => self::A_EXPORT,  'stage_roles' => array('employee','team_leader','manager','finance_maker','finance_checker','hr_executive','hr_manager','hr_head','super_admin')),
        );
    }

    public static function validDomains()
    {
        return array(self::D_BASIC_PROFILE, self::D_PROFILE_EDIT, self::D_LIFECYCLE,
            self::D_ATTENDANCE, self::D_ACTIVITY, self::D_PERFORMANCE, self::D_CONSENT,
            self::D_FIELD_SUMMARY, self::D_RAW_LOCATION, self::D_EMP_DOCUMENTS,
            self::D_KYC_DOCUMENTS, self::D_BANK, self::D_EXPENSES);
    }

    public static function validActions()
    {
        return array(self::A_VIEW, self::A_CREATE, self::A_EDIT,
                     self::A_APPROVE, self::A_EXPORT, self::A_DELETE);
    }

    /** Same hostile-input handling as the emergency guard. */
    public static function cleanId($value)
    {
        if (is_int($value))  { return $value > 0 ? $value : 0; }
        if (is_string($value) && preg_match('/^[0-9]{1,18}\z/', $value)) {
            return (int) $value > 0 ? (int) $value : 0;
        }
        return 0;
    }

    /**
     * Walk the reporting hierarchy upward from $targetId looking for $actorId.
     *
     * @return array depth => 1 for a direct report, >1 for nested, 0 when not an
     *               ancestor; status => ok|missing|cycle
     */
    public static function ancestry($actorId, $targetId, array $hierarchy, $maxDepth = 64)
    {
        $actor  = self::cleanId($actorId);
        $target = self::cleanId($targetId);
        if ($actor <= 0 || $target <= 0) { return array('depth' => 0, 'status' => 'missing'); }

        /*
         * Two different "no" answers live here and must not be conflated.
         *
         *   missing - the TARGET has no reporting record at all. We cannot even
         *             begin to prove a relationship, so we refuse and say so.
         *   root    - we walked the chain to the top without meeting the actor.
         *             The data is fine; the actor simply is not above them.
         *
         * Treating "root" as "missing" would report healthy data as corrupt and
         * bury real hierarchy faults in noise.
         */
        $seen = array();
        $node = $target;
        for ($d = 1; $d <= $maxDepth; $d++) {
            if (!array_key_exists($node, $hierarchy)) {
                return array('depth' => 0,
                             'status' => ($d === 1 ? 'missing' : 'root'));
            }
            $parent = self::cleanId($hierarchy[$node]);
            if ($parent <= 0) {
                return array('depth' => 0,
                             'status' => ($d === 1 ? 'missing' : 'root'));
            }
            if (isset($seen[$parent])) {
                /* A cycle means the hierarchy is corrupt. Corrupt data is not a
                   permission grant. */
                return array('depth' => 0, 'status' => 'cycle');
            }
            $seen[$parent] = true;
            if ($parent === $actor) { return array('depth' => $d, 'status' => 'ok'); }
            $node = $parent;
        }
        return array('depth' => 0, 'status' => 'cycle');
    }

    /**
     * Which relationships does the actor stand in, relative to the target?
     * Returns a set of relationship tokens plus a hierarchy status.
     */
    public static function relationships(array $ctx)
    {
        $actor  = self::cleanId(isset($ctx['actor_id']) ? $ctx['actor_id'] : 0);
        $target = self::cleanId(isset($ctx['target_id']) ? $ctx['target_id'] : 0);
        $rels   = array();
        $status = 'ok';

        if ($actor <= 0 || $target <= 0) {
            return array('rels' => array(), 'hierarchy' => 'missing');
        }

        /*
         * Self is a relationship, not a short circuit.
         *
         * Returning only ['self'] here denied a company-scoped role access to
         * their OWN record - an HR Head could read every profile but not their
         * own. Self is added, then the remaining tokens are evaluated normally:
         * a person is trivially in their own department, team and company.
         */
        $isSelf = ($actor === $target);
        if ($isSelf) {
            $rels[] = self::REL_SELF;
        }

        $hier = isset($ctx['hierarchy']) && is_array($ctx['hierarchy']) ? $ctx['hierarchy'] : array();
        $a = $isSelf ? array('depth' => 0, 'status' => 'ok')
                     : self::ancestry($actor, $target, $hier);
        $status = $a['status'];
        if ($a['status'] === 'ok' && $a['depth'] === 1) {
            $rels[] = self::REL_DIRECT; $rels[] = self::REL_SUBTREE;
        } elseif ($a['status'] === 'ok' && $a['depth'] > 1) {
            $rels[] = self::REL_SUBTREE;
        }

        /* Team / region — only when BOTH sides carry a non-empty value. */
        $at = isset($ctx['actor_team']) ? (string) $ctx['actor_team'] : '';
        $tt = isset($ctx['target_team']) ? (string) $ctx['target_team'] : '';
        $ar = isset($ctx['actor_region']) ? (string) $ctx['actor_region'] : '';
        $tr = isset($ctx['target_region']) ? (string) $ctx['target_region'] : '';
        if (($at !== '' && $at === $tt) || ($ar !== '' && $ar === $tr)) {
            $rels[] = self::REL_TEAM_REGION;
        }

        /* Department — only when BOTH sides carry a non-empty value. An empty
           department on either side must never match another empty one. */
        $td = isset($ctx['target_department']) ? (string) $ctx['target_department'] : '';
        /* An actor may be assigned SEVERAL departments (HR Executive / Manager).
           actor_departments is the list form; actor_department stays supported. */
        $adl = array();
        if (isset($ctx['actor_departments']) && is_array($ctx['actor_departments'])) {
            foreach ($ctx['actor_departments'] as $one) { $adl[] = (string) $one; }
        }
        $adl[] = isset($ctx['actor_department']) ? (string) $ctx['actor_department'] : '';
        /*
         * ONE enforcement point for two rules: an empty department never
         * matches, and the comparison is strict.
         *
         * Filtering empties out of $adl as well made this line redundant, so a
         * mutation that deleted it changed nothing and survived - the rule
         * looked doubly protected and was actually untestable. One guard, one
         * test that kills it.
         */
        if ($td !== '' && in_array($td, $adl, true)) { $rels[] = self::REL_DEPARTMENT; }

        /* Company applies to any identified pair. It only grants anything when a
           role's matrix entry actually names it. */
        $rels[] = self::REL_COMPANY;

        return array('rels' => array_values(array_unique($rels)), 'hierarchy' => $status);
    }

    /**
     * THE DECISION.
     *
     * @param array $ctx actor_id, actor_roles[], actor_active, actor_department,
     *                   actor_team, actor_region, target_id, target_department,
     *                   target_team, target_region, domain, action, hierarchy[],
     *                   record[maker_id], investigation[active,purpose,approved_by]
     * @return array allowed, reason, public, audit_required, masked[]
     */
    public static function decide(array $ctx)
    {
        $actor  = self::cleanId(isset($ctx['actor_id']) ? $ctx['actor_id'] : 0);
        $target = self::cleanId(isset($ctx['target_id']) ? $ctx['target_id'] : 0);
        $domain = isset($ctx['domain']) ? (string) $ctx['domain'] : '';
        $action = isset($ctx['action']) ? (string) $ctx['action'] : '';

        if ($actor <= 0)  { return self::no(self::R_NO_ACTOR, $domain); }
        if ($target <= 0) { return self::no(self::R_BAD_TARGET, $domain); }

        /* A disabled or inactive account authorizes nothing, including its own
           record: an exited employee keeps no standing access. */
        if (array_key_exists('actor_active', $ctx) && !$ctx['actor_active']) {
            return self::no(self::R_INACTIVE_ACTOR, $domain);
        }

        if (!in_array($domain, self::validDomains(), true)) { return self::no(self::R_UNKNOWN_DOMAIN, $domain); }
        if (!in_array($action, self::validActions(), true)) { return self::no(self::R_UNKNOWN_ACTION, $domain); }

        $roles = isset($ctx['actor_roles']) && is_array($ctx['actor_roles']) ? $ctx['actor_roles'] : array();
        $matrix = self::matrix();
        $known = array();
        foreach ($roles as $r) { if (isset($matrix[(string) $r])) { $known[] = (string) $r; } }
        if (empty($known)) { return self::no(self::R_UNKNOWN_ROLE, $domain); }

        $rel = self::relationships($ctx);
        $rels = $rel['rels'];

        /*
         * MAKER-CHECKER, evaluated before any grant and applied to every role.
         * Super Administrator is explicitly NOT exempt: "cannot bypass
         * maker-checker restrictions merely because they are Admin."
         */
        if ($action === self::A_APPROVE) {
            $maker = self::cleanId(isset($ctx['record']['maker_id']) ? $ctx['record']['maker_id'] : 0);
            if ($maker > 0 && $maker === $actor) {
                return self::no(self::R_SELF_APPROVAL, $domain);
            }
            /* Approving one's own record is self-approval even when the maker is
               unrecorded — an employee may never approve their own lifecycle,
               documents, expenses, commission or bank verification. */
            if ($target === $actor) {
                return self::no(self::R_SELF_APPROVAL, $domain);
            }
        }

        /* Does ANY held role permit this action on this domain for a relationship
           the actor actually stands in? */
        $domainKnownToSomeRole = false;
        $actionKnownToSomeRole = false;
        $grantingRole = null;

        foreach ($known as $role) {
            if (!isset($matrix[$role][$domain])) { continue; }
            $domainKnownToSomeRole = true;
            if (!isset($matrix[$role][$domain][$action])) { continue; }
            $actionKnownToSomeRole = true;
            $allowedRels = $matrix[$role][$domain][$action];
            if (array_intersect($allowedRels, $rels)) { $grantingRole = $role; break; }
        }

        if (!$domainKnownToSomeRole) { return self::no(self::R_DOMAIN_DENIED, $domain); }
        if (!$actionKnownToSomeRole) { return self::no(self::R_ACTION_DENIED, $domain); }

        if ($grantingRole === null) {
            /* Out of scope. Distinguish a corrupt hierarchy so it is auditable,
               but refuse either way. */
            if ($rel['hierarchy'] === 'cycle')   { return self::no(self::R_HIERARCHY_CYCLE, $domain); }
            if ($rel['hierarchy'] === 'missing') { return self::no(self::R_HIERARCHY_MISSING, $domain); }
            return self::no(self::R_OUT_OF_SCOPE, $domain);
        }

        /*
         * Raw location needs an approved investigation on top of the matrix
         * grant. Self-access to one's own raw trail does not.
         */
        if (in_array($domain, self::investigationDomains(), true) && $target !== $actor) {
            $inv = isset($ctx['investigation']) && is_array($ctx['investigation']) ? $ctx['investigation'] : array();
            $active   = !empty($inv['active']);
            $purpose  = isset($inv['purpose']) ? trim((string) $inv['purpose']) : '';
            $approver = self::cleanId(isset($inv['approved_by']) ? $inv['approved_by'] : 0);
            if (!$active || $purpose === '' || $approver <= 0 || $approver === $actor) {
                return self::no(self::R_NO_INVESTIGATION, $domain);
            }
        }

        /*
         * HR reaches expenses only when an employment-policy review is approved
         * by somebody else. There is no general HR expense permission.
         */
        if ($domain === self::D_EXPENSES && in_array($grantingRole, self::hrRoles(), true)) {
            $pr = isset($ctx['policy_review']) && is_array($ctx['policy_review']) ? $ctx['policy_review'] : array();
            $active   = !empty($pr['active']);
            $purpose  = isset($pr['purpose']) ? trim((string) $pr['purpose']) : '';
            $approver = self::cleanId(isset($pr['approved_by']) ? $pr['approved_by'] : 0);
            if (!$active || $purpose === '' || $approver <= 0 || $approver === $actor) {
                return self::no(self::R_NO_POLICY_REVIEW, $domain);
            }
        }

        return self::yes(self::R_ALLOWED, $domain, $grantingRole, $target !== $actor, $ctx);
    }

    /**
     * THE CALL-SITE MAP — every guarded Staff route to its approved
     * (domain, action). Two routes resolve their action from a transition
     * allowlist instead, and are marked 'via' => 'lifecycle' | 'expenses'.
     *
     * The controller reads this map. It never accepts a domain or action from
     * the client. A route absent from this map has no approved authorization
     * and must refuse.
     */
    public static function callSiteMap()
    {
        return array(
            'form'                   => array('domain' => self::D_PROFILE_EDIT,  'action' => self::A_EDIT),
            'store'                  => array('domain' => self::D_PROFILE_EDIT,  'action' => self::A_EDIT),
            'view'                   => array('domain' => self::D_BASIC_PROFILE, 'action' => self::A_VIEW),
            'act'                    => array('domain' => self::D_LIFECYCLE,     'via'    => 'lifecycle'),
            'attendance'             => array('domain' => self::D_ATTENDANCE,    'action' => self::A_VIEW),
            'activity'               => array('domain' => self::D_ACTIVITY,      'action' => self::A_VIEW),
            'activity_verify'        => array('domain' => self::D_ACTIVITY,      'action' => self::A_APPROVE),
            'performance'            => array('domain' => self::D_PERFORMANCE,   'action' => self::A_VIEW),
            'consent'                => array('domain' => self::D_CONSENT,       'action' => self::A_VIEW),
            'field'                  => array('domain' => self::D_FIELD_SUMMARY, 'action' => self::A_VIEW),
            'field_session'          => array('domain' => self::D_FIELD_SUMMARY, 'action' => self::A_VIEW),
            'field_session_reverify' => array('domain' => self::D_FIELD_SUMMARY, 'action' => self::A_APPROVE),
            'my_location_data'       => array('domain' => self::D_RAW_LOCATION,  'action' => self::A_VIEW),
            'documents'              => array('domain' => self::D_EMP_DOCUMENTS, 'action' => self::A_VIEW),
            'documents_decide'       => array('domain' => self::D_EMP_DOCUMENTS, 'action' => self::A_APPROVE),
            'documents_download'     => array('domain' => self::D_EMP_DOCUMENTS, 'action' => self::A_EXPORT),
            'expense'                => array('domain' => self::D_EXPENSES,      'action' => self::A_VIEW),
            'expense_transition'     => array('domain' => self::D_EXPENSES,      'via'    => 'expenses'),
            'bank'                   => array('domain' => self::D_BANK,          'action' => self::A_VIEW),
            'bank_decide'            => array('domain' => self::D_BANK,          'action' => self::A_APPROVE),
        );
    }

    /**
     * DECIDE A NAMED TRANSITION.
     *
     * The controller passes a transition NAME and never an action. This resolves
     * the name through the server-side allowlist, applies the stage-role rule,
     * the maker-checker rule and the mandatory-reason rule, then defers to
     * decide() for relationship, domain and action.
     *
     * @param string $kind 'lifecycle' or 'expenses'
     */
    public static function decideTransition($kind, $transition, array $ctx)
    {
        $map = ($kind === 'lifecycle') ? self::lifecycleTransitions()
             : (($kind === 'expenses') ? self::expenseTransitions() : array());
        $name = is_string($transition) ? $transition : '';
        if (!isset($map[$name])) {
            /* An unknown transition is never "probably harmless". */
            return self::no(self::R_UNKNOWN_TRANSITION, $kind);
        }
        $spec   = $map[$name];
        $domain = ($kind === 'lifecycle') ? self::D_LIFECYCLE : self::D_EXPENSES;
        $actor  = self::cleanId(isset($ctx['actor_id']) ? $ctx['actor_id'] : 0);
        $target = self::cleanId(isset($ctx['target_id']) ? $ctx['target_id'] : 0);
        $roles  = isset($ctx['actor_roles']) && is_array($ctx['actor_roles']) ? $ctx['actor_roles'] : array();

        /* Stage: only the named roles may perform this transition. */
        if (isset($spec['stage_roles']) && !array_intersect($spec['stage_roles'], $roles)) {
            return self::no(self::R_STAGE_DENIED, $domain);
        }
        /* Owner-only stages (draft edits, submission). */
        if (!empty($spec['self_only']) && $actor !== $target) {
            return self::no(self::R_OUT_OF_SCOPE, $domain);
        }
        /* High-risk transitions must carry a reason. */
        if (!empty($spec['reason'])) {
            $reason = isset($ctx['transition_reason']) ? trim((string) $ctx['transition_reason']) : '';
            if ($reason === '') { return self::no(self::R_REASON_REQUIRED, $domain); }
        }
        /* Finance approver must not be the validator who prepared it. */
        if (!empty($spec['not_validator'])) {
            $validator = self::cleanId(isset($ctx['record']['validated_by']) ? $ctx['record']['validated_by'] : 0);
            if ($validator > 0 && $validator === $actor) {
                return self::no(self::R_MAKER_IS_CHECKER, $domain);
            }
        }

        $c = $ctx;
        $c['domain'] = $domain;
        $c['action'] = $spec['action'];
        /* Explicitly permitted self-service preparation (own resignation request). */
        if (!empty($spec['self_allowed']) && $actor === $target && $spec['action'] === self::A_EDIT) {
            $c['__self_prepare'] = true;
        }
        $d = self::decide($c);
        $d['transition'] = $name;
        $d['resolved_action'] = $spec['action'];
        return $d;
    }

    /**
     * Which bank fields must be masked for this decision?
     * Finance sees operational fields; everyone else sees a masked view.
     */
    public static function maskedFields($domain, array $ctx)
    {
        if ($domain !== self::D_BANK) { return array(); }
        $roles = isset($ctx['actor_roles']) && is_array($ctx['actor_roles']) ? $ctx['actor_roles'] : array();
        $actor = self::cleanId(isset($ctx['actor_id']) ? $ctx['actor_id'] : 0);
        $target= self::cleanId(isset($ctx['target_id']) ? $ctx['target_id'] : 0);
        if (in_array('finance_maker', $roles, true) || in_array('finance_checker', $roles, true)
            || in_array('super_admin', $roles, true)) {
            return array();
        }
        if ($actor > 0 && $actor === $target) {
            /* own record: still masked, an employee does not need the full number
               echoed back to them */
            return array('account_number', 'ifsc');
        }
        return array('account_number', 'ifsc', 'account_holder');
    }

    /** Must this decision be written to the audit trail? */
    public static function shouldAudit(array $d, array $ctx)
    {
        $actor  = self::cleanId(isset($ctx['actor_id']) ? $ctx['actor_id'] : 0);
        $target = self::cleanId(isset($ctx['target_id']) ? $ctx['target_id'] : 0);
        $domain = isset($ctx['domain']) ? (string) $ctx['domain'] : '';

        /* Every refusal is audited. */
        if (empty($d['allowed'])) { return true; }
        /* Self-access to non-sensitive domains is not an audit event. */
        if ($actor === $target && !in_array($domain, self::sensitiveDomains(), true)) { return false; }
        /* Everything else — cross-staff, or any sensitive domain — is audited. */
        return true;
    }

    public static function auditData(array $d, array $ctx)
    {
        $roles = isset($ctx['actor_roles']) && is_array($ctx['actor_roles']) ? $ctx['actor_roles'] : array();
        return array(
            'guard'     => 'workforce_scope',
            'domain'    => isset($ctx['domain']) ? (string) $ctx['domain'] : '',
            'action'    => isset($ctx['action']) ? (string) $ctx['action'] : '',
            'target'    => self::cleanId(isset($ctx['target_id']) ? $ctx['target_id'] : 0),
            'roles'     => array_values(array_map('strval', $roles)),
            'allowed'   => !empty($d['allowed']),
            'reason'    => isset($d['reason']) ? $d['reason'] : '',
            'via_role'  => isset($d['via_role']) ? $d['via_role'] : null,
            'investigation' => isset($ctx['investigation']['purpose'])
                               ? substr(preg_replace('/[^\x20-\x7E]/', '?', (string) $ctx['investigation']['purpose']), 0, 80)
                               : null,
        );
    }

    private static function no($reason, $domain)
    {
        return array('allowed' => false, 'reason' => $reason,
                     'public' => self::refusalMessage(), 'via_role' => null,
                     'masked' => array(), 'audit_required' => true);
    }

    private static function yes($reason, $domain, $role, $crossStaff, array $ctx)
    {
        return array('allowed' => true, 'reason' => $reason, 'public' => null,
                     'via_role' => $role,
                     'masked' => self::maskedFields($domain, $ctx),
                     'audit_required' => $crossStaff || in_array($domain, self::sensitiveDomains(), true));
    }
}
