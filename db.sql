-- Database: capstone_db

CREATE DATABASE IF NOT EXISTS `capstone_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `capstone_db`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `fullname` VARCHAR(255) NOT NULL,
  `address` VARCHAR(255),
  `barangay` VARCHAR(100),
  `position` VARCHAR(100),
  `verification_file` VARCHAR(255),
  `profile_logo` VARCHAR(255),
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('user','official','admin','head_admin') DEFAULT 'user',
  `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `inventory` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_description` VARCHAR(255) NOT NULL,
  `section` VARCHAR(100) NOT NULL DEFAULT 'Other',
  `status` VARCHAR(50) DEFAULT '',
  `required_quantity` INT DEFAULT 0,
  `current_stock` INT DEFAULT 0,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default DRRMC Admin account (role: admin).
-- Email: admin@caloocan.gov
-- Password: admin123456
INSERT INTO `users` (`fullname`, `address`, `barangay`, `position`, `verification_file`, `email`, `password`, `role`, `status`)
VALUES (
  'DRRMC Admin',
  'Municipal Hall',
  'Caloocan',
  'DRRMC Admin',
  '',
  'admin@caloocan.gov',
  '$2y$10$UxJYU.ufu7CcyNlB9NCK7e4ju2n0.7.7o/Dgmaf0Bs1WbGgUXCNzW',
  'admin',
  'approved'
)
ON DUPLICATE KEY UPDATE
  `fullname` = VALUES(`fullname`),
  `address` = VALUES(`address`),
  `barangay` = VALUES(`barangay`),
  `position` = VALUES(`position`),
  `verification_file` = VALUES(`verification_file`),
  `password` = VALUES(`password`),
  `role` = VALUES(`role`),
  `status` = VALUES(`status`);

-- Default City Head Admin account (role: head_admin). Separate from the DRRMC Admin above.
-- Email: headadmin@caloocan.gov
-- Password: HeadAdmin@2026  (change after first login)
INSERT INTO `users` (`fullname`, `address`, `barangay`, `position`, `verification_file`, `email`, `password`, `role`, `status`)
VALUES (
  'City Head Admin',
  'City Hall, Caloocan',
  'Caloocan',
  'City DRRMC Head Admin',
  '',
  'headadmin@caloocan.gov',
  '$2y$10$xhJXwwygxAgh//kZ3O.ME.rkK7DyM7cIAEe6vIgZ5QQqy/li6tgc6',
  'head_admin',
  'approved'
)
ON DUPLICATE KEY UPDATE
  `role` = VALUES(`role`),
  `status` = VALUES(`status`);

-- Equipment reports table for user submissions
CREATE TABLE IF NOT EXISTS `equipment_reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `ambulance_count` INT DEFAULT 0,
  `fire_truck_count` INT DEFAULT 0,
  `portable_oxygen` VARCHAR(10) DEFAULT '',
  `portable_oxygen_remarks` TEXT,
  `stretcher_scoop` VARCHAR(10) DEFAULT '',
  `stretcher_scoop_remarks` TEXT,
  `spider_strap` VARCHAR(10) DEFAULT '',
  `spider_strap_remarks` TEXT,
  `bp_automatic` VARCHAR(10) DEFAULT '',
  `bp_automatic_remarks` TEXT,
  `pulse_oximeter` VARCHAR(10) DEFAULT '',
  `pulse_oximeter_remarks` TEXT,
  `facemask` VARCHAR(10) DEFAULT '',
  `facemask_remarks` TEXT,
  `elastic_bandage` VARCHAR(10) DEFAULT '',
  `elastic_bandage_remarks` TEXT,
  `gloves` VARCHAR(10) DEFAULT '',
  `gloves_remarks` TEXT,
  `betadine_dropper` VARCHAR(10) DEFAULT '',
  `betadine_dropper_remarks` TEXT,
  `surgical_tape` VARCHAR(10) DEFAULT '',
  `surgical_tape_remarks` TEXT,
  `gauze_pad` VARCHAR(10) DEFAULT '',
  `gauze_pad_remarks` TEXT,
  `nasal_cannula` VARCHAR(10) DEFAULT '',
  `nasal_cannula_remarks` TEXT,
  `oxygen_mask` VARCHAR(10) DEFAULT '',
  `oxygen_mask_remarks` TEXT,
  `t_bandage` VARCHAR(10) DEFAULT '',
  `t_bandage_remarks` TEXT,
  `s_splint` VARCHAR(10) DEFAULT '',
  `s_splint_remarks` TEXT,
  `w_splint` VARCHAR(10) DEFAULT '',
  `w_splint_remarks` TEXT,
  `umbrella` VARCHAR(10) DEFAULT '',
  `umbrella_remarks` TEXT,
  `cold_hot_compress` VARCHAR(10) DEFAULT '',
  `cold_hot_compress_remarks` TEXT,
  `cervical_collar` VARCHAR(10) DEFAULT '',
  `cervical_collar_remarks` TEXT,
  `first_aid_kit` VARCHAR(10) DEFAULT '',
  `first_aid_kit_remarks` TEXT,
  `stretcher` VARCHAR(10) DEFAULT '',
  `stretcher_remarks` TEXT,
  `backboard` VARCHAR(10) DEFAULT '',
  `backboard_remarks` TEXT,
  `resuscitator` VARCHAR(10) DEFAULT '',
  `resuscitator_remarks` TEXT,
  `defibrillator` VARCHAR(10) DEFAULT '',
  `defibrillator_remarks` TEXT,
  `aspirator` VARCHAR(10) DEFAULT '',
  `aspirator_remarks` TEXT,
  `flashlight` VARCHAR(10) DEFAULT '',
  `flashlight_remarks` TEXT,
  `rope` VARCHAR(10) DEFAULT '',
  `rope_remarks` TEXT,
  `carabiner` VARCHAR(10) DEFAULT '',
  `carabiner_remarks` TEXT,
  `harness` VARCHAR(10) DEFAULT '',
  `harness_remarks` TEXT,
  `life_jacket` VARCHAR(10) DEFAULT '',
  `life_jacket_remarks` TEXT,
  `helmet` VARCHAR(10) DEFAULT '',
  `helmet_remarks` TEXT,
  `impact_resistant_boots` VARCHAR(10) DEFAULT '',
  `impact_resistant_boots_remarks` TEXT,
  `hazmat_suit` VARCHAR(10) DEFAULT '',
  `hazmat_suit_remarks` TEXT,
  `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
);

-- Resource requests submitted by barangay personnel.
CREATE TABLE IF NOT EXISTS `resource_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `request_type` VARCHAR(100) NOT NULL,
  `items_requested` TEXT NOT NULL,
  `reason` TEXT NOT NULL,
  `status` ENUM('pending','approved','rejected','deleted') NOT NULL DEFAULT 'pending',
  `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `reviewed_by` INT NULL,
  `seed_key` VARCHAR(100) NULL UNIQUE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_resource_requests_user` (`user_id`),
  INDEX `idx_resource_requests_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `training_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `training_name` VARCHAR(150) NOT NULL,
  `requested_date` DATE NOT NULL,
  `scheduled_at` DATETIME NULL,
  `location` VARCHAR(255) NOT NULL,
  `details` TEXT NOT NULL,
  `admin_reply` TEXT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `reviewed_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_training_requests_user` (`user_id`),
  INDEX `idx_training_requests_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `advertisements` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(180) NOT NULL,
  `image_path` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_advertisements_active` (`is_active`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `training_drills` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `training_name` VARCHAR(150) NOT NULL,
  `scheduled_at` DATETIME NOT NULL,
  `location` VARCHAR(255) NOT NULL,
  `scope` TEXT NOT NULL,
  `participants` VARCHAR(255) NOT NULL,
  `duration` VARCHAR(100) NOT NULL,
  `status` ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `request_id` INT NULL,
  `created_by` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`request_id`) REFERENCES `training_requests`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_training_drills_date` (`scheduled_at`),
  INDEX `idx_training_drills_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Demo data: exactly 10 approved users, one per barangay (Barangays 1 through 10),
-- with one equipment report and one resource request for each demo user.
DROP PROCEDURE IF EXISTS `seed_barangay_demo_data`;
DELIMITER $$
CREATE PROCEDURE `seed_barangay_demo_data`()
BEGIN
  DECLARE barangay_number INT DEFAULT 1;
  DECLARE demo_user_id INT;
  DECLARE demo_email VARCHAR(150);
  DECLARE demo_key VARCHAR(100);

  WHILE barangay_number <= 10 DO
      SET demo_email = CONCAT('demo.b', barangay_number, '@caloocan.local');
      SET demo_key = CONCAT('demo-b', barangay_number);

      INSERT INTO `users` (`fullname`, `address`, `barangay`, `position`, `verification_file`, `email`, `password`, `role`, `status`)
      VALUES (
        CONCAT('Barangay ', barangay_number, ' User'),
        CONCAT('Barangay ', barangay_number, ', Caloocan City'),
        CAST(barangay_number AS CHAR),
        'Barangay DRRM Staff',
        '',
        demo_email,
        '$2y$10$UxJYU.ufu7CcyNlB9NCK7e4ju2n0.7.7o/Dgmaf0Bs1WbGgUXCNzW',
        'user',
        'approved'
      )
      ON DUPLICATE KEY UPDATE
        `fullname` = VALUES(`fullname`),
        `address` = VALUES(`address`),
        `barangay` = VALUES(`barangay`),
        `position` = VALUES(`position`),
        `status` = 'approved';

      SELECT `id` INTO demo_user_id FROM `users` WHERE `email` = demo_email LIMIT 1;

      INSERT INTO `equipment_reports` (`user_id`, `ambulance_count`, `fire_truck_count`, `portable_oxygen`, `gloves`, `first_aid_kit`)
      SELECT demo_user_id, 1, 1, '5', '25', '10'
      WHERE NOT EXISTS (SELECT 1 FROM `equipment_reports` WHERE `user_id` = demo_user_id);

      INSERT INTO `resource_requests` (`user_id`, `request_type`, `items_requested`, `reason`, `status`, `seed_key`)
      VALUES (
        demo_user_id,
        'Emergency Supplies',
        '10 oxygen tanks, 25 sterile gauze packs, 5 emergency kits',
        CONCAT('Routine replenishment request for Barangay ', barangay_number, ' emergency readiness.'),
           CASE WHEN MOD(barangay_number, 3) = 0 THEN 'approved'
             WHEN MOD(barangay_number, 3) = 1 THEN 'pending'
             ELSE 'rejected' END,
        demo_key
      )
      ON DUPLICATE KEY UPDATE
        `user_id` = VALUES(`user_id`),
        `request_type` = VALUES(`request_type`),
        `items_requested` = VALUES(`items_requested`),
        `reason` = VALUES(`reason`),
        `status` = VALUES(`status`);
    SET barangay_number = barangay_number + 1;
  END WHILE;
END$$
DELIMITER ;
CALL `seed_barangay_demo_data`();
DROP PROCEDURE IF EXISTS `seed_barangay_demo_data`;