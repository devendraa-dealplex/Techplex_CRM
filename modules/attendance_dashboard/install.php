<?php

defined('BASEPATH') or exit('No direct script access allowed');

$p  = db_prefix();
$cs = $CI->db->char_set;

$CI->db->query("CREATE TABLE IF NOT EXISTS `{$p}att_workplaces` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `address` VARCHAR(255) NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `radius_m` INT NOT NULL DEFAULT 100,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET={$cs};");

$CI->db->query("CREATE TABLE IF NOT EXISTS `{$p}att_employees` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
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
  `unique_key` TINYINT(1) NULL,
  `ip_address` VARCHAR(45) NULL,
  `marked_by` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  UNIQUE KEY `one_per_day` (`employee_id`, `att_date`, `unique_key`),
  KEY `att_date` (`att_date`),
  KEY `workplace_id` (`workplace_id`),
  KEY `image_hash` (`image_hash`)
) ENGINE=InnoDB DEFAULT CHARSET={$cs};");

// Files are never web-served directly; the controller streams them after a permission check.
$uploads = FCPATH . 'uploads/attendance/';
if (!is_dir($uploads)) {
    @mkdir($uploads, 0755, true);
}
@file_put_contents($uploads . '.htaccess', "Require all denied\nDeny from all\n");
@file_put_contents($uploads . 'index.html', '');
@file_put_contents($uploads . 'web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*"/></authorization></system.webServer></configuration>');

$defaults = ['att_allow_duplicate' => '0', 'att_early_minutes' => '30', 'att_late_grace_minutes' => '10', 'att_max_accuracy_m' => '100'];
foreach ($defaults as $k => $v) {
    if (get_option($k) === '') {
        add_option($k, $v);
    }
}
