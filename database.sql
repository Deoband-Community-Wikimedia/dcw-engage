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


-- ============================================================
-- Organizer roles and invitations
--
-- `role` splits the workspace into two levels:
--   owner     - everything an organizer can do, plus inviting other
--               organizers and revoking pending invitations
--   organizer - normal workspace access, no team management
--
-- Both statements below are live, and run top-to-bottom on a fresh import
-- (same pattern as `notify_emails` above). On a database created before
-- this block existed, run these two by hand, once, then promote yourself:
--
--   UPDATE admin_users SET role = 'owner' WHERE email = 'you@example.org';
--
-- Without at least one owner, nobody can send invitations and the only way
-- to add an account stays `php bin/create_admin.php`.
-- ============================================================
ALTER TABLE `admin_users`
    ADD COLUMN `role` ENUM('owner','organizer') NOT NULL DEFAULT 'organizer' AFTER `password_hash`;

-- Pending invitations to join the organizer workspace.
--
-- Only the SHA-256 of the token is stored, never the token itself. The raw
-- value exists in exactly one place: the email we send. A leaked database
-- backup therefore cannot be replayed into a new organizer account.
-- (`magic_links` above still stores raw tokens; those are applicant-scoped
-- and short lived, but this table is the pattern to follow for anything new.)
CREATE TABLE `admin_invites` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(255) NOT NULL,
    `token_hash` CHAR(64) NOT NULL UNIQUE,
    `role` ENUM('owner','organizer') NOT NULL DEFAULT 'organizer',
    -- Snapshot kept for the audit trail even if the inviting account is
    -- later deleted, mirroring application_notes.admin_email_snapshot.
    `invited_by` INT NULL,
    `invited_by_email` VARCHAR(255) NOT NULL,
    -- DATETIME, not TIMESTAMP. MySQL/MariaDB give the first NOT NULL TIMESTAMP
    -- column an implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`,
    -- which would silently reset the expiry every time the row is marked
    -- accepted or revoked. The value is always computed in PHP anyway.
    `expires_at` DATETIME NOT NULL,
    `accepted_at` DATETIME NULL,
    `revoked_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_invite_email` (`email`),
    FOREIGN KEY (`invited_by`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
);

-- ============================================================
-- Password resets
--
-- Both statements are live and run on a fresh import. On a database created
-- before this block existed, run them by hand, once.
--
-- password_changed_at is what lets a reset log out sessions that are already
-- open elsewhere. Auth stamps the session with this value at login and
-- rechecks it on each request, so a stolen session dies the moment its
-- account changes password — which is the main reason people reset one.
-- ============================================================
ALTER TABLE `admin_users`
    ADD COLUMN `password_changed_at` DATETIME NULL AFTER `role`;

-- Same design as admin_invites: only the SHA-256 of the token is stored, the
-- raw value exists solely in the email. Expiry is deliberately much shorter
-- than an invitation, because a reset link is a live key to an existing
-- account rather than an offer to create one.
CREATE TABLE `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT NOT NULL,
    `token_hash` CHAR(64) NOT NULL UNIQUE,
    -- DATETIME, not TIMESTAMP: the first NOT NULL TIMESTAMP column would pick
    -- up an implicit ON UPDATE CURRENT_TIMESTAMP and reset its own expiry.
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `invalidated_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_reset_admin` (`admin_id`),
    FOREIGN KEY (`admin_id`) REFERENCES `admin_users`(`id`) ON DELETE CASCADE
);

-- ============================================================
-- Audit log
--
-- Append-only history of sensitive workspace actions: who invited or removed
-- whom, who accepted an invitation, who reset a password. The application only
-- ever inserts and reads here — nothing edits or deletes a row — so the record
-- stays trustworthy months later when someone asks "who did this".
--
-- actor_email is a snapshot (same reasoning as application_notes and
-- admin_invites): the log must still name the person after their account is
-- removed, which is exactly the moment it matters most. actor_id is kept as a
-- soft link and set NULL if that account is later deleted.
--
-- Live on a fresh import. On a database created before this block existed, run
-- the CREATE TABLE below by hand, once.
-- ============================================================
CREATE TABLE `audit_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `actor_id` INT NULL,
    `actor_email` VARCHAR(255) NOT NULL,
    `action` VARCHAR(64) NOT NULL,
    `target` VARCHAR(255) NULL,
    `detail` VARCHAR(500) NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_audit_created` (`created_at`),
    KEY `idx_audit_action` (`action`),
    FOREIGN KEY (`actor_id`) REFERENCES `admin_users`(`id`) ON DELETE SET NULL
);
