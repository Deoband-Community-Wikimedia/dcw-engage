-- DCW Engage Portal - Database Schema
-- Architecture for vanilla PHP + PDO

-- Organizer accounts for the /admin workspace.
-- No rows are seeded here on purpose, a committed password hash is a
-- password everyone has. After importing this file, create the first
-- account from the terminal:
--
--     php bin/create_admin.php
--
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
    `schema_json` JSON NOT NULL, -- Defines the fields, types, and validation rules
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
    -- One application per email per form. The application layer checks for a
    -- duplicate before inserting, but that check-then-insert has a race under
    -- a double submit; this constraint is the actual guarantee.
    UNIQUE KEY `uniq_form_email` (`form_id`, `email`),
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

-- ============================================================
-- Notify Emails: Added to route submission alerts to the
-- organizer(s) in charge of a specific form. Supports a single
-- email or multiple comma-separated emails.
-- ============================================================
ALTER TABLE `forms`
    ADD COLUMN `notify_emails` VARCHAR(500) NULL AFTER `schema_json`;

-- ============================================================
-- Internal Organizer Notes: Append-only thread of private notes
-- left by admins on a specific application. Never edited/deleted
-- individually — full audit trail.
-- Notes are preserved even if the admin account is later removed;
-- admin_email_snapshot keeps a record of who wrote it.
-- ============================================================
CREATE TABLE `application_notes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `application_id` INT NOT NULL,
    `admin_id` INT NULL,
    `admin_email_snapshot` VARCHAR(255) NOT NULL,
    `note_text` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`application_id`) REFERENCES `applications`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`admin_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
);

-- ============================================================
-- Note for installs that predate `uniq_form_email`: a fresh import of
-- this file already has the constraint, inline, on `applications` above
-- — do NOT run the ALTER below against a fresh install, it will fail
-- with "Duplicate key name 'uniq_form_email'" (see #22).
--
-- Only run this by hand, once, against an existing database that was
-- created before the UNIQUE KEY was added to the CREATE TABLE:
--
--   ALTER TABLE `applications`
--       ADD UNIQUE KEY `uniq_form_email` (`form_id`, `email`);
--
-- If that errors with "Duplicate entry", the table already has
-- duplicate (form_id, email) rows from before the fix. Clear them
-- first, keeping the earliest of each pair, then re-run the ALTER:
--
--   DELETE a1 FROM applications a1
--   JOIN applications a2
--     ON a1.form_id = a2.form_id AND a1.email = a2.email
--    AND a1.id > a2.id;
-- ============================================================

-- Note: Data retention cron should periodically delete applications with status 'Rejected' or stale 'Draft' records.
