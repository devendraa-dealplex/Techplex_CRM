<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Migration 012 — workforce policy-role assignments.
 *
 * Creates `payplex_staff_policy_roles`: the audited, effective-dated source of
 * truth for who holds which policy role, replacing the free-form
 * `policy_role_map` settings string as an authorization input.
 *
 * IDEMPOTENT. Every statement is guarded, so re-running is a no-op:
 *   - CREATE TABLE IF NOT EXISTS
 *   - each index added only when absent
 *   - no data is written, updated or deleted
 *
 * NO BACKFILL. HR and finance roles are deliberately NOT populated from the
 * old labels. `hr_admin` is not `hr_head`; `finance_approver` is not
 * `finance_head`. Inferring either would hand somebody company-wide authority
 * during an upgrade, with nobody having approved it. The table ships empty and
 * every assignment is made explicitly, by a named person, with a reason.
 *
 * HISTORY IS PRESERVED. Revocation UPDATEs the row (is_active=0, revoked_at,
 * revoked_by, revocation_reason). Nothing deletes an assignment.
 */

$CI      = &get_instance();
$prefix  = db_prefix();
$table   = $prefix . 'payplex_staff_policy_roles';
$charset = 'DEFAULT CHARSET=' . $CI->db->char_set;

if (!$CI->db->table_exists($table)) {
    $CI->db->query('CREATE TABLE IF NOT EXISTS `' . $table . '` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `staff_id` INT(11) NOT NULL,
        `policy_role` VARCHAR(40) NOT NULL,

        /* Scope. Each field is independent: one never implies another, and a
           blank field is NOT "everything" - it simply states nothing, and the
           policy engine falls back to the profile value. */
        `business_entity_id` VARCHAR(60) NULL DEFAULT NULL,
        `branch` VARCHAR(100) NULL DEFAULT NULL,
        `department` VARCHAR(100) NULL DEFAULT NULL,
        `region` VARCHAR(100) NULL DEFAULT NULL,

        /* Effective dating. expires_at NULL means permanent; any value makes
           the assignment temporary. */
        `effective_from` DATETIME NULL DEFAULT NULL,
        `expires_at` DATETIME NULL DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,

        /* Who granted it, and why. Both mandatory at the application layer. */
        `assigned_by` INT(11) NOT NULL DEFAULT 0,
        `assignment_reason` VARCHAR(255) NULL DEFAULT NULL,

        /* Maker-checker on the assignment itself. A sensitive role or a
           company-wide scope stays unapproved - and therefore not live - until
           a second person approves it. */
        `approval_state` VARCHAR(20) NOT NULL DEFAULT "approved",
        `approved_by` INT(11) NULL DEFAULT NULL,
        `approved_at` DATETIME NULL DEFAULT NULL,

        /* Revocation stamps the row. It never removes it. */
        `revoked_at` DATETIME NULL DEFAULT NULL,
        `revoked_by` INT(11) NULL DEFAULT NULL,
        `revocation_reason` VARCHAR(255) NULL DEFAULT NULL,

        `created_at` DATETIME NULL DEFAULT NULL,
        `updated_at` DATETIME NULL DEFAULT NULL,

        PRIMARY KEY (`id`),
        KEY `idx_wpr_staff` (`staff_id`),
        KEY `idx_wpr_active` (`staff_id`, `is_active`),
        KEY `idx_wpr_role_active` (`policy_role`, `is_active`),
        KEY `idx_wpr_entity` (`business_entity_id`),
        KEY `idx_wpr_branch` (`branch`),
        KEY `idx_wpr_department` (`department`),
        KEY `idx_wpr_region` (`region`),
        KEY `idx_wpr_expiry` (`expires_at`),
        KEY `idx_wpr_window` (`staff_id`, `is_active`, `effective_from`, `expires_at`),
        KEY `idx_wpr_approval` (`approval_state`)
    ) ENGINE=InnoDB ' . $charset . ';');
}

/**
 * Guard against conflicting live PRIMARY roles at the storage layer.
 *
 * MySQL treats NULLs as distinct in a UNIQUE index, so `is_active` alone will
 * not do it. `active_key` is maintained by the application as staff_id while
 * the row is live and NULL once it is not, which makes "one live assignment per
 * staff member" a constraint the database enforces rather than a convention the
 * code is trusted to keep.
 *
 * The application ALSO fails closed on conflict, because a constraint that has
 * not been proven to fire is not a control.
 */
if ($CI->db->table_exists($table)) {
    $cols = $CI->db->list_fields($table);
    if (!in_array('active_key', $cols, true)) {
        $CI->db->query('ALTER TABLE `' . $table . '` ADD COLUMN `active_key` INT(11) NULL DEFAULT NULL');
    }

    $existing = array();
    foreach ($CI->db->query('SHOW INDEX FROM `' . $table . '`')->result_array() as $ix) {
        $existing[$ix['Key_name']] = true;
    }
    if (!isset($existing['uq_wpr_one_live_primary'])) {
        /* Only added when the data already satisfies it; a migration must not
           fail a deployment because of pre-existing rows. */
        $dupes = $CI->db->query('SELECT `active_key`, COUNT(*) c FROM `' . $table . '`
                                 WHERE `active_key` IS NOT NULL
                                 GROUP BY `active_key` HAVING c > 1')->num_rows();
        if ($dupes === 0) {
            $CI->db->query('ALTER TABLE `' . $table . '`
                            ADD UNIQUE KEY `uq_wpr_one_live_primary` (`active_key`)');
        }
    }
}
