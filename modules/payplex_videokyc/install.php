<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payplex Video KYC — install migration.
 * Creates only {prefix}payplex_vkyc_* tables. Touches NO Perfex core tables.
 * Idempotent: every statement is guarded, safe to re-run on re-activation.
 *
 * Table names use "vkyc" (not "kyc") to stay clear of the existing
 * payplex_cv_kyc_* tables owned by the contract-verification module.
 */

require_once __DIR__ . '/libraries/Payplex_kyc_scripts.php';

$CI      = &get_instance();
$prefix  = db_prefix();
$charset = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

/* 1. Script templates ------------------------------------------------------
 * Placeholders: {customer_name} {company} {date}. A request stores a RENDERED
 * COPY of the script, so editing a template later never rewrites what a
 * customer was actually asked to read. */
if (!$CI->db->table_exists($prefix . 'payplex_vkyc_templates')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_vkyc_templates` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(120) NOT NULL,
        `body` TEXT NOT NULL,
        `body_hi` TEXT NULL,
        `body_mr` TEXT NULL,
        `is_default` TINYINT(1) NOT NULL DEFAULT 0,
        `active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_by` INT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB {$charset};");

    $bodies = Payplex_kyc_scripts::defaultBodies();
    $CI->db->insert($prefix . 'payplex_vkyc_templates', [
        'name'       => 'Standard consent statement',
        'body'       => $bodies['en'],
        'body_hi'    => $bodies['hi'],
        'body_mr'    => $bodies['mr'],
        'is_default' => 1,
        'active'     => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/* 2. KYC requests ----------------------------------------------------------
 * The raw token is NEVER stored — only its SHA-256. A leaked database (or
 * backup) therefore cannot be replayed as working links. */
if (!$CI->db->table_exists($prefix . 'payplex_vkyc_requests')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_vkyc_requests` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `token_hash` CHAR(64) NOT NULL,
        `rel_type` ENUM('lead','customer') NOT NULL,
        `rel_id` INT UNSIGNED NOT NULL,
        `customer_name` VARCHAR(191) NOT NULL,
        `customer_email` VARCHAR(191) NULL,
        `customer_phone` VARCHAR(32) NULL,
        `template_id` INT UNSIGNED NULL,
        `dynamic_script` TEXT NOT NULL,
        `script_language` VARCHAR(5) NOT NULL DEFAULT 'en',
        `status` ENUM('pending','in_progress','submitted','approved','rejected','expired') NOT NULL DEFAULT 'pending',
        `expires_at` DATETIME NOT NULL,
        `upload_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `send_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        `opened_at` DATETIME NULL,
        `submitted_at` DATETIME NULL,
        `reviewed_at` DATETIME NULL,
        `created_by` INT NOT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_token_hash` (`token_hash`),
        KEY `ix_status` (`status`),
        KEY `ix_subject` (`rel_type`,`rel_id`),
        KEY `ix_expires` (`expires_at`),
        KEY `ix_created` (`created_at`)
    ) ENGINE=InnoDB {$charset};");
}

/* 3. Videos (a re-issued link can yield more than one per request; the
 * newest row is the current submission, older ones are kept as evidence). */
if (!$CI->db->table_exists($prefix . 'payplex_vkyc_videos')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_vkyc_videos` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `request_id` BIGINT UNSIGNED NOT NULL,
        `storage_path` VARCHAR(255) NOT NULL,
        `mime_type` VARCHAR(64) NOT NULL,
        `file_size` BIGINT UNSIGNED NOT NULL,
        `duration_sec` SMALLINT UNSIGNED NULL,
        `sha256` CHAR(64) NOT NULL,
        `uploaded_ip` VARCHAR(45) NULL,
        `user_agent` VARCHAR(255) NULL,
        `verified_by` INT NULL,
        `verified_at` DATETIME NULL,
        `review_notes` TEXT NULL,
        `checklist_json` TEXT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_request` (`request_id`)
    ) ENGINE=InnoDB {$charset};");
}

/* 4. Notification log (one row per channel per send attempt). */
if (!$CI->db->table_exists($prefix . 'payplex_vkyc_notifications')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_vkyc_notifications` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `request_id` BIGINT UNSIGNED NOT NULL,
        `channel` ENUM('email','sms','whatsapp') NOT NULL,
        `recipient` VARCHAR(191) NULL,
        `status` ENUM('sent','failed','skipped') NOT NULL,
        `provider_message_id` VARCHAR(80) NULL,
        `error` VARCHAR(255) NULL,
        `sent_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_request` (`request_id`)
    ) ENGINE=InnoDB {$charset};");
}


/* 4b. Identity documents (Step 1 of the customer KYC flow). One row per file.
 * The file itself lives under uploads/payplex_videokyc/documents/ (denied to the
 * web, served only through the permission-checked controller). The row is the
 * audit record: who uploaded it, when, from where, why, and the SHA-256 that is
 * re-verified before the file is ever served. Rows are never updated or deleted. */
if (!$CI->db->table_exists($prefix . 'payplex_vkyc_documents')) {
    $CI->db->query("CREATE TABLE `{$prefix}payplex_vkyc_documents` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `customer_id` INT UNSIGNED NOT NULL,
        `doc_type` VARCHAR(24) NOT NULL,
        `storage_path` VARCHAR(255) NOT NULL,
        `mime_type` VARCHAR(64) NOT NULL,
        `file_size` BIGINT UNSIGNED NOT NULL,
        `sha256` CHAR(64) NOT NULL,
        `upload_reason` VARCHAR(500) NOT NULL,
        `uploaded_by` INT NOT NULL,
        `uploaded_ip` VARCHAR(45) NULL,
        `user_agent` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `ix_customer` (`customer_id`)
    ) ENGINE=InnoDB {$charset};");
}

/* 5a. Upgrade path for installs created before multi-language support.
 * Guarded by field_exists(), so it is safe to run on every activation/upgrade. */
$tplTable = $prefix . 'payplex_vkyc_templates';
foreach (['body_hi', 'body_mr'] as $col) {
    if (!$CI->db->field_exists($col, $tplTable)) {
        $CI->db->query("ALTER TABLE `{$tplTable}` ADD COLUMN `{$col}` TEXT NULL AFTER `body`");
    }
}
$reqTable = $prefix . 'payplex_vkyc_requests';
if (!$CI->db->field_exists('script_language', $reqTable)) {
    $CI->db->query("ALTER TABLE `{$reqTable}` ADD COLUMN `script_language` VARCHAR(5) NOT NULL DEFAULT 'en' AFTER `dynamic_script`");
}
// Give the stock template its Hindi/Marathi text if it doesn't have any yet. Only the
// untouched stock template is filled; nothing an admin has written is overwritten.
$bodies = Payplex_kyc_scripts::defaultBodies();
foreach (['hi', 'mr'] as $l) {
    $CI->db->query(
        "UPDATE `{$tplTable}` SET `body_{$l}` = ? WHERE `name` = 'Standard consent statement' AND (`body_{$l}` IS NULL OR `body_{$l}` = '')",
        [$bodies[$l]]
    );
}

/* 5. Private storage. Videos live OUTSIDE any public URL: the directory
 * denies direct web access and files are only ever served through the
 * permission-checked admin controller. */
$dir = FCPATH . 'uploads/payplex_videokyc';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
if (is_dir($dir)) {
    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    if (!file_exists($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
}

/* 6. Defaults (add-only — never overwrite something an admin already set). */
$defaults = [
    'payplex_videokyc_link_ttl_hours'   => '48',
    'payplex_videokyc_max_attempts'     => '3',
    'payplex_videokyc_max_upload_mb'    => '30',
    'payplex_videokyc_max_record_sec'   => '90',
    'payplex_videokyc_min_record_sec'   => '5',
    'payplex_videokyc_whatsapp_provider' => 'twilio',
];
foreach ($defaults as $k => $v) {
    $existing = get_option($k);
    if ($existing === '' || $existing === null || $existing === false) {
        update_option($k, $v);
    }
}

/* 7. Sales roles. Give the stock sales roles the Video KYC capabilities they need
 * (everything except settings and "view all"), so sales staff work their own
 * customers' KYC without an admin ticking boxes.
 *
 * Perfex does NOT read permissions from the role at request time: staff_can() reads
 * {prefix}staff_permissions, and the role is only a template copied into it when a
 * role is saved in the UI. So the grant has to go to both places.
 *
 * Add-only: a role that already has a payplex_videokyc entry keeps it, and a staff
 * member who already has any payplex_videokyc row was configured by an admin and is
 * left alone. */
$salesCaps = ['view', 'generate', 'review', 'video_access', 'documents'];
foreach ($CI->db->where_in('name', ['Sales Manager', 'Team Leader', 'Sales Executive'])->get($prefix . 'roles')->result() as $role) {
    $perms = @unserialize($role->permissions);
    if (!is_array($perms)) {
        continue;
    }
    if (!isset($perms['payplex_videokyc'])) {
        $perms['payplex_videokyc'] = $salesCaps;
        $CI->db->where('roleid', $role->roleid)->update($prefix . 'roles', ['permissions' => serialize($perms)]);
    }
    $caps = (array) $perms['payplex_videokyc'];

    foreach ($CI->db->select('staffid')->where('role', $role->roleid)->where('admin', 0)->get($prefix . 'staff')->result() as $m) {
        $has = $CI->db->where('staff_id', $m->staffid)->where('feature', 'payplex_videokyc')
            ->count_all_results($prefix . 'staff_permissions');
        if ($has > 0) {
            continue;
        }
        foreach ($caps as $cap) {
            $CI->db->insert($prefix . 'staff_permissions', ['staff_id' => $m->staffid, 'feature' => 'payplex_videokyc', 'capability' => $cap]);
        }
    }
}

/* 8. "Ask again" decision: requests.status gains 'resubmit'. Guarded by the column
 * type, so it only alters the table once. */
$statusCol = $CI->db->query("SHOW COLUMNS FROM `{$reqTable}` LIKE 'status'")->row();
if ($statusCol && strpos((string) $statusCol->Type, "'resubmit'") === false) {
    $CI->db->query("ALTER TABLE `{$reqTable}` MODIFY `status` ENUM('pending','in_progress','submitted','approved','rejected','resubmit','expired') NOT NULL DEFAULT 'pending'");
}

/* 9. The onboarding email a converted lead receives (set password + Video KYC).
 * The branded layout lives in assets/onboarding_email.html and replaces the stock
 * English "contact-set-password" template. The marker comment at its top makes this
 * run once: after that the template is the admin's to edit in Setup > Email
 * Templates. Merge fields used: {contact_firstname} {contact_lastname}
 * {set_password_url} {logo_image_with_url} and {video_kyc_url} (registered in
 * payplex_videokyc.php). */
$tplMail = $prefix . 'emailtemplates';
$html    = @file_get_contents(__DIR__ . '/assets/onboarding_email.html');
if ($html && $CI->db->table_exists($tplMail)) {
    $CI->db->where('slug', 'contact-set-password')->where('type', 'client')->where('language', 'english')
        ->not_like('message', 'payplex-onboarding-email')
        ->update($tplMail, [
            'subject'   => 'Welcome to TechPlex! Set your password and complete your KYC',
            'message'   => $html,
            'plaintext' => 0,
        ]);
}

/* 10. Employee KYC: requests.rel_type gains 'staff' (an employee, verified via a link
 * HR sends). Guarded by the column type, so it only alters the table once. */
$relCol = $CI->db->query("SHOW COLUMNS FROM `{$reqTable}` LIKE 'rel_type'")->row();
if ($relCol && strpos((string) $relCol->Type, "'staff'") === false) {
    $CI->db->query("ALTER TABLE `{$reqTable}` MODIFY `rel_type` ENUM('lead','customer','staff') NOT NULL");
}

/* 11. HR role: may see, review and send links for ALL employees. Same two-place grant
 * (role template + each staff member's own permissions) and same add-only rule as
 * step 7. Managers need no permission: they review their own reports by relationship. */
$hrCaps = ['employee_all', 'employee_send'];
foreach ($CI->db->where('name', 'HR')->get($prefix . 'roles')->result() as $role) {
    $perms = @unserialize($role->permissions);
    if (!is_array($perms)) {
        continue;
    }
    if (!isset($perms['payplex_videokyc'])) {
        $perms['payplex_videokyc'] = $hrCaps;
        $CI->db->where('roleid', $role->roleid)->update($prefix . 'roles', ['permissions' => serialize($perms)]);
    }
    $caps = array_values(array_intersect((array) $perms['payplex_videokyc'], $hrCaps));
    foreach ($CI->db->select('staffid')->where('role', $role->roleid)->where('admin', 0)->get($prefix . 'staff')->result() as $m) {
        $has = $CI->db->where('staff_id', $m->staffid)->where('feature', 'payplex_videokyc')
            ->where_in('capability', $hrCaps)->count_all_results($prefix . 'staff_permissions');
        if ($has > 0) {
            continue;
        }
        foreach ($hrCaps as $cap) {
            $CI->db->insert($prefix . 'staff_permissions', ['staff_id' => $m->staffid, 'feature' => 'payplex_videokyc', 'capability' => $cap]);
        }
    }
}

/* 12. Admin-type ROLES ("Super Admin", "CRM Admin"): staff who hold these roles but are
 * not flagged as administrators get every Video KYC capability, including sending
 * employee links. (Staff flagged as administrators already pass every check.)
 * Add-only per capability, so anything an admin ticks by hand is kept, and a
 * capability is never granted twice. */
$adminCaps = ['view', 'view_all', 'generate', 'review', 'video_access', 'documents', 'settings', 'employee_all', 'employee_send'];
foreach ($CI->db->where_in('name', ['Super Admin', 'CRM Admin'])->get($prefix . 'roles')->result() as $role) {
    $perms = @unserialize($role->permissions);
    if (!is_array($perms)) {
        continue;
    }
    $perms['payplex_videokyc'] = array_values(array_unique(array_merge((array) (isset($perms['payplex_videokyc']) ? $perms['payplex_videokyc'] : []), $adminCaps)));
    $CI->db->where('roleid', $role->roleid)->update($prefix . 'roles', ['permissions' => serialize($perms)]);

    foreach ($CI->db->select('staffid')->where('role', $role->roleid)->get($prefix . 'staff')->result() as $m) {
        foreach ($adminCaps as $cap) {
            $exists = $CI->db->where('staff_id', $m->staffid)->where('feature', 'payplex_videokyc')->where('capability', $cap)
                ->count_all_results($prefix . 'staff_permissions');
            if (!$exists) {
                $CI->db->insert($prefix . 'staff_permissions', ['staff_id' => $m->staffid, 'feature' => 'payplex_videokyc', 'capability' => $cap]);
            }
        }
    }
}

/* 13. The "must complete KYC before using the account" lock (customers AND
 * employees) applies only to accounts created from this point on — never
 * retroactively to someone who already had full access. This single option
 * records that boundary, set once and never moved. */
if (get_option('payplex_videokyc_lock_cutoff') === false || get_option('payplex_videokyc_lock_cutoff') === '') {
    add_option('payplex_videokyc_lock_cutoff', date('Y-m-d H:i:s'));
}
