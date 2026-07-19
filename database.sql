-- DCW Engage Portal - Database Schema
-- Architecture for vanilla PHP + PDO

CREATE TABLE `admin_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_login` TIMESTAMP NULL
);

CREATE TABLE `forms` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `form_type` VARCHAR(100) NOT NULL UNIQUE, -- e.g., 'scholarship', 'fellowship'
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `applications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `form_id` INT NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `applicant_name` VARCHAR(255),
    `status` ENUM('Draft', 'New', 'Under Review', 'Accepted', 'Rejected') DEFAULT 'New',
    `form_data` JSON, -- Store dynamic answers securely
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`form_id`) REFERENCES `forms`(`id`) ON DELETE CASCADE
);

CREATE TABLE `magic_links` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `application_id` INT NOT NULL,
    `token` VARCHAR(128) NOT NULL UNIQUE,
    `expires_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`application_id`) REFERENCES `applications`(`id`) ON DELETE CASCADE
);

-- Note: Data retention cron should periodically delete applications with status 'Rejected' or stale 'Draft' records.
