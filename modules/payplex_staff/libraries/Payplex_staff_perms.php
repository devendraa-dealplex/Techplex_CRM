<?php

defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Payplex_staff_perms
 *
 * The permission model: a dedicated least-privilege profile for commission
 * employees, the role x domain x action matrix for the ten roles, and
 * validation for admin-defined custom permission templates (no code changes
 * needed to add one). Pure + dependency-free; the controller/model enforce it
 * server-side on UI, API, exports and direct URLs.
 *
 * Ownership matters: view_own is a self-scoped read, view_any is cross-staff.
 * A commission employee gets view_own on their own commission but never
 * view_any — hiding a menu is never sufficient, the check is enforced here.
 */
class Payplex_staff_perms
{
    /** The least-privilege capability profile for commission-based employees. */
    public static function commissionEmployeeProfile()
    {
        return array(
            'allowed' => array(
                'view_own_dashboard',
                'view_own_leads',
                'update_permitted_lead_stages',
                'add_call_notes',
                'create_permitted_followups',
                'view_own_tasks_targets',
                'submit_task_evidence',
                'view_own_verified_sales',
                'view_own_commission',
                'submit_own_expense',
                'view_own_claim_payout_status',
                'raise_dispute',
            ),
            'prohibited' => array(
                'view_other_commission',
                'view_company_financials',
                'view_edit_commission_rules',
                'approve_own_commission',
                'approve_own_expense',
                'change_sale_ownership',
                'delete_financial_history',
                'mark_invoice_paid',
                'mark_commission_paid',
                'modify_bank_after_verify',
                'export_bulk_customers',
                'view_api_credentials',
                'activate_ai_agents',
                'change_roles',
                'access_urls_without_permission',
            ),
        );
    }

    public static function roles()
    {
        return array(
            'super_admin', 'hr_admin', 'manager', 'field_manager', 'employee',
            'commission_employee', 'finance_maker', 'finance_checker', 'finance_approver', 'auditor',
        );
    }

    public static function domains()
    {
        return array(
            'staff_records', 'kyc', 'bank_details', 'leads', 'tasks', 'attendance',
            'location', 'targets', 'commission', 'expenses', 'tada', 'approvals',
            'payout', 'reports', 'exports', 'audit_logs', 'configuration',
        );
    }

    /**
     * Grant sets per role. A grant is "domain:action"; wildcards "*" (all),
     * "domain:*", and "*:action" are supported. Absent = denied.
     */
    private static function grants()
    {
        return array(
            'super_admin' => array('*'),

            'auditor' => array('*:view', '*:view_any', '*:view_own', 'audit_logs:view', 'reports:view'),

            'hr_admin' => array(
                'staff_records:*', 'kyc:view', 'kyc:view_any', 'kyc:edit', 'kyc:verify',
                'attendance:*', 'targets:view', 'targets:view_any', 'reports:view', 'reports:view_any',
                'leads:view', 'leads:view_any', 'tasks:view', 'tasks:view_any',
                'audit_logs:view', 'configuration:view',
            ),

            'manager' => array(
                'staff_records:view', 'staff_records:view_any',
                'leads:view', 'leads:view_any', 'leads:edit',
                'tasks:*', 'targets:view', 'targets:view_any', 'targets:edit',
                'attendance:view', 'attendance:view_any', 'approvals:verify',
                'reports:view', 'reports:view_any', 'commission:view', 'commission:view_any',
                'expenses:view', 'expenses:view_any', 'expenses:verify', 'tada:view', 'tada:view_any', 'tada:verify',
            ),

            'field_manager' => array(
                'staff_records:view', 'staff_records:view_any',
                'leads:view', 'leads:view_any', 'tasks:*',
                'attendance:view', 'attendance:view_any',
                'location:view', 'location:view_any', 'approvals:verify',
                'targets:view', 'targets:view_any', 'reports:view', 'reports:view_any',
                'expenses:view', 'expenses:view_any', 'expenses:verify', 'tada:view', 'tada:view_any', 'tada:verify',
            ),

            'employee' => array(
                'staff_records:view_own', 'leads:view_own', 'leads:edit_own',
                'tasks:view_own', 'tasks:edit_own', 'attendance:view_own', 'attendance:edit_own',
                'targets:view_own', 'commission:view_own', 'expenses:view_own', 'expenses:create',
                'tada:view_own', 'tada:create', 'location:view_own',
            ),

            'commission_employee' => array(
                'staff_records:view_own', 'leads:view_own', 'leads:edit_own',
                'tasks:view_own', 'tasks:edit_own', 'targets:view_own',
                'commission:view_own', 'expenses:view_own', 'expenses:create',
                'tada:view_own', 'tada:create',
            ),

            'finance_maker' => array(
                'commission:view', 'commission:view_any', 'commission:edit',
                'payout:view', 'payout:view_any', 'payout:create',
                'expenses:view', 'expenses:view_any', 'tada:view', 'tada:view_any',
                'bank_details:view', 'reports:view', 'reports:view_any',
            ),

            'finance_checker' => array(
                'commission:view', 'commission:view_any', 'payout:view', 'payout:view_any', 'payout:verify',
                'expenses:view', 'expenses:view_any', 'expenses:verify',
                'tada:view', 'tada:view_any', 'tada:verify',
                'bank_details:view', 'bank_details:verify', 'reports:view', 'reports:view_any',
            ),

            'finance_approver' => array(
                'commission:view', 'commission:view_any', 'commission:approve',
                'payout:view', 'payout:view_any', 'payout:approve',
                'expenses:view', 'expenses:view_any', 'expenses:approve',
                'tada:view', 'tada:view_any', 'tada:approve',
                'bank_details:view', 'reports:view', 'reports:view_any',
            ),
        );
    }

    /** Does $role permit $action on $domain? Server-side authority. */
    public static function can($role, $domain, $action)
    {
        $g = self::grants();
        if (!isset($g[$role])) { return false; }
        $set = $g[$role];
        $needle = $domain . ':' . $action;
        if (in_array('*', $set, true)) { return true; }
        if (in_array($needle, $set, true)) { return true; }
        if (in_array($domain . ':*', $set, true)) { return true; }
        if (in_array('*:' . $action, $set, true)) { return true; }
        return false;
    }

    /** Validate an admin-defined custom permission template. */
    public static function validateTemplate($tpl)
    {
        $errors = array();
        $name = isset($tpl['name']) ? trim((string) $tpl['name']) : '';
        if ($name === '') { $errors[] = 'name_required'; }
        $allowed = isset($tpl['allowed']) && is_array($tpl['allowed']) ? array_values(array_unique($tpl['allowed'])) : array();
        $prohibited = isset($tpl['prohibited']) && is_array($tpl['prohibited']) ? array_values(array_unique($tpl['prohibited'])) : array();
        $overlap = array_intersect($allowed, $prohibited);
        if (!empty($overlap)) { $errors[] = 'allow_deny_overlap'; }
        return array(
            'ok' => empty($errors),
            'errors' => $errors,
            'entry' => array('name' => substr($name, 0, 100), 'allowed' => $allowed, 'prohibited' => $prohibited),
        );
    }
}
