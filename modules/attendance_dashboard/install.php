<?php

defined('BASEPATH') or exit('No direct script access allowed');

$p  = db_prefix();
$cs = $CI->db->char_set;

$CI->db->query("CREATE TABLE IF NOT EXISTS `{$p}att_workplaces` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `address` VARCHAR(255) NULL,
  `pincode` VARCHAR(12) NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `radius_m` INT NOT NULL DEFAULT 100,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET={$cs};");

$CI->db->query("CREATE TABLE IF NOT EXISTS `{$p}att_employees` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `staff_id` INT NULL,
  `emp_code` VARCHAR(50) NOT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `role` VARCHAR(100) NULL,
  `department` VARCHAR(100) NULL,
  `workplace_id` INT NOT NULL,
  `phone` VARCHAR(50) NULL,
  `email` VARCHAR(150) NULL,
  `photo` VARCHAR(100) NULL,
  `notes` TEXT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `emp_code` (`emp_code`),
  UNIQUE KEY `staff_id` (`staff_id`),
  KEY `workplace_id` (`workplace_id`)
) ENGINE=InnoDB DEFAULT CHARSET={$cs};");

$CI->db->query("CREATE TABLE IF NOT EXISTS `{$p}att_schedules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT NOT NULL,
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `working_days` VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  UNIQUE KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET={$cs};");

// `unique_key` is 1 for a verified record and NULL for failed attempts (or when duplicates
// are allowed), so the unique index blocks a second verified record per employee/day even
// under concurrent requests, while failed attempts stay logged.
$CI->db->query("CREATE TABLE IF NOT EXISTS `{$p}att_records` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT NOT NULL,
  `workplace_id` INT NOT NULL,
  `att_date` DATE NOT NULL,
  `att_type` VARCHAR(3) NOT NULL DEFAULT 'in',
  `timestamp` DATETIME NOT NULL,
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `accuracy_m` INT NULL,
  `distance_m` INT NULL,
  `captured_image` VARCHAR(100) NULL,
  `image_hash` CHAR(64) NULL,
  `verification_status` VARCHAR(10) NOT NULL,
  `verification_reason` VARCHAR(255) NOT NULL,
  `is_late` TINYINT(1) NOT NULL DEFAULT 0,
  `is_early_out` TINYINT(1) NOT NULL DEFAULT 0,
  `unique_key` TINYINT(1) NULL,
  `ip_address` VARCHAR(45) NULL,
  `marked_by` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `edited_by` INT NULL,
  `edited_at` DATETIME NULL,
  `edit_note` VARCHAR(255) NULL,
  UNIQUE KEY `one_per_day` (`employee_id`, `att_date`, `unique_key`),
  KEY `att_date` (`att_date`),
  KEY `workplace_id` (`workplace_id`),
  KEY `image_hash` (`image_hash`)
) ENGINE=InnoDB DEFAULT CHARSET={$cs};");

$CI->db->query("CREATE TABLE IF NOT EXISTS `{$p}att_leaves` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT NOT NULL,
  `from_date` DATE NOT NULL,
  `to_date` DATE NOT NULL,
  `is_half_day` TINYINT(1) NOT NULL DEFAULT 0,
  `half_session` VARCHAR(10) NULL,
  `days` DECIMAL(4,1) NOT NULL,
  `leave_type` VARCHAR(20) NOT NULL DEFAULT 'other',
  `reason` VARCHAR(500) NULL,
  `status` VARCHAR(10) NOT NULL DEFAULT 'pending',
  `applied_by` INT NOT NULL DEFAULT 0,
  `reviewed_by` INT NULL,
  `reviewed_at` DATETIME NULL,
  `review_note` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  KEY `employee_id` (`employee_id`),
  KEY `status` (`status`),
  KEY `date_range` (`from_date`, `to_date`)
) ENGINE=InnoDB DEFAULT CHARSET={$cs};");

// Files are never web-served directly; the controller streams them after a permission check.
$uploads = FCPATH . 'uploads/attendance/';
if (!is_dir($uploads)) {
    @mkdir($uploads, 0755, true);
}
@file_put_contents($uploads . '.htaccess', "Require all denied\nDeny from all\n");
@file_put_contents($uploads . 'index.html', '');
@file_put_contents($uploads . 'web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*"/></authorization></system.webServer></configuration>');

$defaults = ['att_allow_duplicate' => '0', 'att_early_minutes' => '30', 'att_late_grace_minutes' => '10', 'att_max_accuracy_m' => '100', 'att_checkout_late_minutes' => '240'];
foreach ($defaults as $k => $v) {
    if (get_option($k) === '') {
        add_option($k, $v);
    }
}
