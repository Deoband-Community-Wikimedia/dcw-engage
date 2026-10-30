-- ============================================================================
-- DCW Engage Portal - complete database schema
-- ============================================================================
--
-- This file creates every table the application uses. For a fresh install
-- it is the only SQL file you need:
--
--     mysql -u root -p your_database_name < database.sql
--
-- (Create the empty database first. On shared hosting you can import this
-- file through phpMyAdmin instead.)
--
-- WHAT IS IN HERE
--
--   1. Organizer accounts, roles and security   admin_users, admin_user_roles,
--                                               admin_invites, password_resets,
--                                               audit_log
--   2. Forms and applications                   forms, applications,
--                                               application_notes, magic_links,
--                                               email_verifications,
--                                               feedback_responses,
--                                               scholarship_letters,
--                                               award_confirm_settings,
--                                               award_confirmations
--   3. Membership                               members, member_password_tokens,
--                                               member_profile, member_strikes,
--                                               membership_scopes,
--                                               membership_decisions,
--                                               membership_id_links
--   4. Membership cards                         member_cards, member_card_hits
--   5. Member support                           member_tickets,
--                                               member_ticket_messages,
--                                               internal_notes
--   6. Internet support                         internet_requests,
--                                               internet_request_messages,
--                                               internet_settings
--   7. Reimbursements                           reimbursement_forms,
--                                               reimbursement_eligibility,
--                                               reimbursement_requests,
--                                               reimbursement_line_items,
--                                               reimbursement_settings,
--                                               reimbursement_global_eligibility,
--                                               reimbursement_request_messages
--   8. Tech issues                              tech_issues, tech_issue_messages
--   9. Community records                        ch_sessions, ch_attendance,
--                                               ch_eligibility_snapshots,
--                                               tech_contributors
--  10. Goodies (member points)                  activity_types, events,
--                                               event_attendance, point_awards,
--                                               goodies, redemptions,
--                                               points_ledger, ch_session_hosts
--  11. Event cards (printed participation       card_events,
--      cards, e.g. Train a Wikipedian)          card_participants
--
-- Tables are ordered so that every foreign key points at a table created
-- earlier in the file.
--
-- GOOD TO KNOW
--
--   * Schema only, apart from the starter rows at the very end of the file
--     (see "Default settings rows": two settings rows, plus a draft set of
--     Goodies activity types and inactive goodies). There are no
--     AUTO_INCREMENT counters, no DEFINER clauses and no credentials. Real
--     configuration lives in the git-ignored includes/config.php (and, if you
--     use them, includes/certs_db.php and includes/events_db.php).
--
--   * Safe to re-run. Every table uses CREATE TABLE IF NOT EXISTS and the
--     default rows use INSERT IGNORE, so importing again will not touch
--     tables (or data) that already exist.
--
--   * Portable. Tables use the utf8mb4_unicode_ci collation and JSON
--     columns, which work on MySQL 8.0+ and MariaDB 10.4+.
--
--   * No organizer account is seeded: a committed password hash is a
--     password everyone has. After importing, create the first owner from
--     the terminal:
--
--         php bin/create_admin.php admin@example.com
--
--   * migrations/ is for UPGRADING an install that was set up before a table
--     or column existed. Do not run it on a database you just created from
--     this file.
--
-- ============================================================================

SET NAMES utf8mb4;

-- ============================================================
-- Organizer accounts, roles and security
-- ============================================================

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'organizer',
  `password_changed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_user_roles` (
  `admin_id` int(11) NOT NULL,
  `role` varchar(50) NOT NULL,
  PRIMARY KEY (`admin_id`,`role`),
  KEY `idx_role` (`role`),
  CONSTRAINT `fk_admin_user_roles_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_invites` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'organizer',
  `roles` varchar(255) NOT NULL DEFAULT '',
  `chapters` varchar(255) NOT NULL DEFAULT '',
  `invited_by` int(11) DEFAULT NULL,
  `invited_by_email` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_invite_email` (`email`),
  KEY `invited_by` (`invited_by`),
  CONSTRAINT `admin_invites_ibfk_1` FOREIGN KEY (`invited_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `invalidated_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_reset_admin` (`admin_id`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `actor_id` int(11) DEFAULT NULL,
  `actor_email` varchar(255) NOT NULL,
  `action` varchar(64) NOT NULL,
  `target` varchar(255) DEFAULT NULL,
  `detail` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_action` (`action`),
  KEY `actor_id` (`actor_id`),
  CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`actor_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Forms and applications
-- ============================================================

CREATE TABLE IF NOT EXISTS `forms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_type` varchar(100) NOT NULL,
  `schema_json` JSON NOT NULL,
  `notify_emails` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `deadline_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `form_type` (`form_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- letter_sent_at: when the scholarship award / decline letter went out.
-- letter_failed:  1 only when a decision was saved but its letter could not be sent
--                 (drives the "Resend letter" button). Applications decided before
--                 letters were switched on stay 0, so they never show a resend button.
CREATE TABLE IF NOT EXISTS `applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(12) DEFAULT NULL,
  `form_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `applicant_name` varchar(255) DEFAULT NULL,
  `status` enum('Draft','New','Under Review','Accepted','Rejected','Discarded') DEFAULT 'New',
  `form_data` JSON DEFAULT NULL,
  `letter_sent_at` datetime DEFAULT NULL,
  `letter_failed` tinyint(1) NOT NULL DEFAULT 0,
  `sent_back_at` datetime DEFAULT NULL,           -- staff asked for more information; may be resubmitted after the deadline
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_form_email` (`form_id`,`email`),
  UNIQUE KEY `tracking_id` (`tracking_id`),
  CONSTRAINT `applications_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `application_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `admin_email_snapshot` varchar(255) NOT NULL,
  `note_text` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `application_id` (`application_id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `application_notes_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `application_notes_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `magic_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `token` varchar(128) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `application_id` (`application_id`),
  CONSTRAINT `magic_links_ibfk_1` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email verification codes (8-character OTP typed into the page the visitor has open).
-- token_hash holds an HMAC-SHA256 of (form|email|code), 64 hex chars.
-- attempts counts wrong guesses spent against a code; the cap is enforced in
-- EmailVerificationModel::consume().
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_id` int(11) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `invalidated_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_verify_form_email` (`form_id`,`email`),
  CONSTRAINT `email_verifications_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feedback_responses` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(254) DEFAULT NULL,
  `answers_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `scrubbed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_form_created` (`form_id`,`created_at`),
  KEY `idx_form_email_created` (`form_id`,`email`,`created_at`),
  KEY `idx_scrub` (`scrubbed_at`,`created_at`),
  CONSTRAINT `fk_feedback_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scholarship letters: committee-written award / decline emails that replace the
-- standard status email when a scholarship application is Accepted / Rejected.
-- One row per form. No foreign key on purpose: keeps this independent of the
-- exact type of forms.id.
--   enabled: 1 = Accept / Reject send these letters instead of the status email
--   *_body:  wikitext subset, rendered by MiniWikiText
--   version: +1 on every save; written to the app log with each send
CREATE TABLE IF NOT EXISTS `scholarship_letters` (
  `form_id` int(11) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `award_subject` varchar(190) NOT NULL DEFAULT '',
  `award_body` text NOT NULL,
  `decline_subject` varchar(190) NOT NULL DEFAULT '',
  `decline_body` text NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `saved_by` varchar(190) NOT NULL DEFAULT '',
  `saved_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Award confirmation: awardees open a private link, accept or decline the scholarship, and share
-- travel and stay details. One settings row per form, one confirmation row per awarded application.
-- Both tables are removed together with the form / application (ON DELETE CASCADE), so no personal
-- answers are left behind.
--   award_confirm_settings.deadline_at   UTC, like forms.deadline_at; typed and shown in IST
--   award_confirm_settings.fields_text   the questions, one per line: Label | type | required/optional | choices
--   award_confirmations.token            the private link, /confirm/<token>
--   award_confirmations.answers_enc      encrypted JSON (AES-256-GCM, security.field_encryption_key)
CREATE TABLE IF NOT EXISTS `award_confirm_settings` (
  `form_id` int(11) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `deadline_at` datetime DEFAULT NULL,
  `intro_text` text DEFAULT NULL,
  `fields_text` text DEFAULT NULL,
  `saved_by` varchar(190) DEFAULT NULL,
  `saved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`form_id`),
  CONSTRAINT `fk_acs_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `award_confirmations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `token_hash` char(64) DEFAULT NULL,        -- sha256 of the private link (see AwardConfirmationModel)
  `old_token_hash` char(64) DEFAULT NULL,    -- sha256 of a link emailed before links were hashed
  `response` enum('pending','confirmed','declined') NOT NULL DEFAULT 'pending',
  `answers_enc` mediumtext DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `reminder_count` int(11) NOT NULL DEFAULT 0,
  `last_reminded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_award_app` (`application_id`),
  UNIQUE KEY `uniq_award_token_hash` (`token_hash`),
  KEY `idx_award_old_token_hash` (`old_token_hash`),
  CONSTRAINT `fk_ac_application` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Membership
-- ============================================================

CREATE TABLE IF NOT EXISTS `members` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` varchar(20) NOT NULL,
  `email` varchar(190) NOT NULL,
  `full_name` varchar(190) NOT NULL,
  `chapter` enum('generic','amu','jamia','photographers') NOT NULL,
  `status` enum('active','expired') NOT NULL DEFAULT 'active',
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `application_id` int(10) UNSIGNED DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `password_set_at` datetime DEFAULT NULL,
  `failed_logins` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_member_chapter` (`email`,`chapter`),
  UNIQUE KEY `uq_member_id_chapter` (`member_id`,`chapter`),
  KEY `idx_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_password_tokens` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_row_id` int(10) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `purpose` enum('set','reset') NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token_hash` (`token_hash`),
  KEY `idx_member` (`member_row_id`),
  CONSTRAINT `fk_mpt_member` FOREIGN KEY (`member_row_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_profile` (
  `member_id` varchar(32) NOT NULL,
  `wikimedia_username` varchar(255) DEFAULT NULL,
  `username_key` varchar(255) DEFAULT NULL,
  `username_verified_at` datetime DEFAULT NULL,
  `username_verified_by` varchar(255) DEFAULT NULL,
  `bio` varchar(280) DEFAULT NULL,
  `languages` varchar(500) DEFAULT NULL,
  `contribution_areas` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` varchar(255) DEFAULT NULL,
  `card_title` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`member_id`),
  UNIQUE KEY `uq_member_profile_username` (`username_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_strikes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` varchar(16) NOT NULL,
  `reason` text NOT NULL,
  `struck_by` varchar(190) NOT NULL,
  `struck_at` datetime NOT NULL,
  `lifted_at` datetime DEFAULT NULL,
  `lifted_by` varchar(190) DEFAULT NULL,
  `lift_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member_strikes_member` (`member_id`,`lifted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `membership_scopes` (
  `email` varchar(190) NOT NULL,
  `chapter` enum('generic','amu','jamia','photographers') NOT NULL,
  PRIMARY KEY (`email`,`chapter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `membership_decisions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` int(10) UNSIGNED NOT NULL,
  `decision` enum('approved','rejected','under_review','info_requested') NOT NULL,
  `reason` text DEFAULT NULL,
  `decided_by` varchar(190) NOT NULL,
  `decided_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_app` (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `membership_id_links` (
  `application_id` int(10) UNSIGNED NOT NULL,
  `member_id` varchar(20) NOT NULL,
  `linked_by` varchar(255) NOT NULL,
  `linked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`application_id`),
  KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Membership cards
-- ============================================================

CREATE TABLE IF NOT EXISTS `member_cards` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` varchar(16) NOT NULL,
  `token` char(32) NOT NULL,
  `created_at` datetime NOT NULL,
  `replaced_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member_cards_token` (`token`),
  KEY `idx_member_cards_member` (`member_id`,`replaced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_card_hits` (
  `ip_hash` char(64) NOT NULL,
  `window_start` datetime NOT NULL,
  `hits` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`ip_hash`,`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Member support (conversations and internal notes)
-- ============================================================

CREATE TABLE IF NOT EXISTS `member_tickets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(12) NOT NULL,
  `member_id` varchar(16) NOT NULL,
  `member_email` varchar(255) NOT NULL,
  `member_name` varchar(255) NOT NULL DEFAULT '',
  `type` enum('complaint','suggestion','question') NOT NULL,
  `subject` varchar(160) NOT NULL,
  `status` varchar(32) NOT NULL,
  `about_staff` tinyint(1) NOT NULL DEFAULT 0,
  `hide_name` tinyint(1) NOT NULL DEFAULT 0,
  `last_sender` enum('member','staff') NOT NULL DEFAULT 'member',
  `assigned_to` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_id` (`tracking_id`),
  KEY `idx_member` (`member_id`,`updated_at`),
  KEY `idx_staff` (`about_staff`,`status`,`updated_at`),
  KEY `idx_assigned` (`assigned_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_ticket_messages` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` int(10) UNSIGNED NOT NULL,
  `sender` enum('member','staff','note') NOT NULL,
  `author` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`,`id`),
  CONSTRAINT `fk_mtm_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `member_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `internal_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `queue` enum('reimbursement','internet') NOT NULL,
  `request_id` int(11) NOT NULL,
  `author_email` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_queue_request` (`queue`,`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Internet support
-- ============================================================

CREATE TABLE IF NOT EXISTS `internet_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(20) NOT NULL,
  `email` varchar(255) NOT NULL,
  `applicant_name` varchar(255) NOT NULL,
  `phone_enc` varbinary(512) NOT NULL,
  `operator` varchar(50) NOT NULL,
  `package_name` varchar(120) NOT NULL,
  `package_price_paise` int(11) NOT NULL,
  `package_validity_days` int(11) DEFAULT NULL,
  `reason` text NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Submitted',
  `decided_by` varchar(255) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `recharged_by` varchar(255) DEFAULT NULL,
  `recharged_at` datetime DEFAULT NULL,
  `recharge_reference` varchar(255) DEFAULT NULL,
  `recharge_notes` text DEFAULT NULL,
  `receipt_path` varchar(255) NOT NULL DEFAULT '',
  `receipt_submitted_at` datetime DEFAULT NULL,
  `finance_notes` text DEFAULT NULL,
  `closed_by` varchar(255) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `receipts_downloaded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `wikimedia_username` varchar(255) DEFAULT NULL,
  `edits_80` tinyint(1) DEFAULT NULL,
  `attended_ch` tinyint(1) DEFAULT NULL,
  `tech_contributor` tinyint(1) DEFAULT NULL,
  `contributions` text DEFAULT NULL,
  `plans` text DEFAULT NULL,
  `approved_amount_paise` int(10) UNSIGNED DEFAULT NULL,
  `approved_amount_note` varchar(500) DEFAULT NULL,
  `paid_amount_paise` int(10) UNSIGNED DEFAULT NULL,
  `paid_amount_note` varchar(500) DEFAULT NULL,
  `sent_back_by` varchar(255) DEFAULT NULL,
  `sent_back_at` datetime DEFAULT NULL,
  `sent_back_note` varchar(1000) DEFAULT NULL,
  `current_pack_ends_on` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_id` (`tracking_id`),
  KEY `idx_internet_status` (`status`),
  KEY `idx_internet_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `internet_request_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `sender` enum('reviewer','applicant') NOT NULL,
  `author` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`,`id`),
  CONSTRAINT `fk_irm_request` FOREIGN KEY (`request_id`) REFERENCES `internet_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `internet_settings` (
  `id` tinyint(4) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `max_amount_paise` int(11) NOT NULL DEFAULT 100000,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Reimbursements
-- ============================================================

CREATE TABLE IF NOT EXISTS `reimbursement_forms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `cash_threshold_paise` int(11) NOT NULL DEFAULT 80000,
  `expense_categories` JSON NOT NULL,
  `instructions` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `form_id` (`form_id`),
  CONSTRAINT `reimbursement_forms_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reimbursement_eligibility` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reimbursement_form_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `added_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reimbursement_form_id` (`reimbursement_form_id`,`email`),
  CONSTRAINT `reimbursement_eligibility_ibfk_1` FOREIGN KEY (`reimbursement_form_id`) REFERENCES `reimbursement_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reimbursement_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reimbursement_form_id` int(11) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `applicant_name` varchar(255) NOT NULL,
  `event_name` varchar(255) NOT NULL DEFAULT '',
  `event_date` date DEFAULT NULL,
  `total_amount_paise` int(11) NOT NULL,
  `payment_method` enum('upi','bank') NOT NULL,
  `upi_id` varchar(100) DEFAULT NULL,
  `bank_account_name` varchar(255) DEFAULT NULL,
  `bank_account_number_enc` varbinary(512) DEFAULT NULL,
  `bank_ifsc` varchar(11) DEFAULT NULL,
  `status` enum('Submitted','Under Review','Rejected','Approved for Payment','Paid','Payment Failed','Discarded','Info Requested') NOT NULL DEFAULT 'Submitted',
  `tracking_id` varchar(20) NOT NULL,
  `admin_notes` text DEFAULT NULL,
  `decided_by` varchar(255) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `payment_notes` text DEFAULT NULL,
  `payment_reference` varchar(255) DEFAULT NULL,
  `paid_by` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `receipts_downloaded_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_amount_paise` int(10) UNSIGNED DEFAULT NULL,
  `approved_amount_note` varchar(500) DEFAULT NULL,
  `paid_amount_paise` int(10) UNSIGNED DEFAULT NULL,
  `paid_amount_note` varchar(500) DEFAULT NULL,
  `sent_back_by` varchar(255) DEFAULT NULL,
  `sent_back_at` datetime DEFAULT NULL,
  `sent_back_note` varchar(1000) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_id` (`tracking_id`),
  KEY `idx_reimb_requests_form_email` (`reimbursement_form_id`,`email`),
  KEY `idx_reimb_requests_status` (`reimbursement_form_id`,`status`),
  CONSTRAINT `reimbursement_requests_ibfk_1` FOREIGN KEY (`reimbursement_form_id`) REFERENCES `reimbursement_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reimbursement_line_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `category` varchar(100) NOT NULL,
  `description` varchar(500) NOT NULL,
  `amount_paise` int(11) NOT NULL,
  `receipt_path` varchar(500) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `reimbursement_line_items_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `reimbursement_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reimbursement_settings` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `cash_threshold_paise` int(11) NOT NULL DEFAULT 80000,
  `expense_categories` JSON NOT NULL,
  `instructions` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reimbursement_global_eligibility` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `added_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reimbursement_request_messages` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `sender` enum('reviewer','applicant') NOT NULL,
  `author` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_request` (`request_id`),
  CONSTRAINT `fk_reimb_msg_request` FOREIGN KEY (`request_id`) REFERENCES `reimbursement_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tech issues
-- ============================================================

CREATE TABLE IF NOT EXISTS `tech_issues` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(16) NOT NULL,
  `reporter_type` varchar(10) NOT NULL,
  `reporter_ref` varchar(190) NOT NULL,
  `reporter_label` varchar(190) NOT NULL,
  `category` varchar(20) NOT NULL,
  `severity` varchar(10) NOT NULL,
  `title` varchar(160) NOT NULL,
  `page_url` varchar(255) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Open',
  `last_sender` varchar(10) NOT NULL DEFAULT 'reporter',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tech_tracking` (`tracking_id`),
  KEY `idx_tech_status` (`status`,`updated_at`),
  KEY `idx_tech_reporter` (`reporter_type`,`reporter_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tech_issue_messages` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `issue_id` int(10) UNSIGNED NOT NULL,
  `sender` varchar(10) NOT NULL,
  `body` text NOT NULL,
  `author_email` varchar(190) DEFAULT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_msg_issue` (`issue_id`,`id`),
  CONSTRAINT `fk_msg_issue` FOREIGN KEY (`issue_id`) REFERENCES `tech_issues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Community records (Conversation Hour attendance, tech contributors)
-- ============================================================

CREATE TABLE IF NOT EXISTS `ch_sessions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `held_on` date NOT NULL,
  `created_by` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_by` varchar(255) DEFAULT NULL,
  `edited_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_held_on` (`held_on`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ch_attendance` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` int(10) UNSIGNED NOT NULL,
  `person_key` varchar(300) NOT NULL,
  `member_id` varchar(20) DEFAULT NULL,
  `wikimedia_username` varchar(255) NOT NULL DEFAULT '',
  `status` enum('present','excused') NOT NULL DEFAULT 'present',
  `note` varchar(500) NOT NULL DEFAULT '',
  `logged_by` varchar(255) NOT NULL,
  `logged_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_session_person` (`session_id`,`person_key`),
  KEY `idx_member` (`member_id`),
  KEY `idx_username` (`wikimedia_username`),
  CONSTRAINT `fk_ch_att_session` FOREIGN KEY (`session_id`) REFERENCES `ch_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ch_eligibility_snapshots` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(40) NOT NULL,
  `member_id` varchar(20) NOT NULL,
  `snapshot_json` mediumtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tracking` (`tracking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tech_contributors` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `member_id` varchar(20) NOT NULL,
  `added_by` varchar(255) NOT NULL,
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason` varchar(500) NOT NULL,
  `confirmed_until` date DEFAULT NULL,
  `removed_by` varchar(255) DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  `removed_reason` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Goodies (non-expiring member points, redeemable for DCW goodies)
--
-- member_id is the Member ID from the members table: one letter and 8 digits
-- (for example A48213977), shared by every chapter a person holds, so points
-- follow the person. It is VARCHAR(40) with no foreign key because members
-- has one row per chapter under the same ID. Same utf8mb4_unicode_ci
-- collation as members, so joining on member_id is safe.
--
-- Points are never edited. points_ledger is append-only: the balance
-- is SUM(points) per member, and corrections are new rows.
-- Timestamps are stored in UTC and shown in IST.
-- ============================================================

CREATE TABLE IF NOT EXISTS `activity_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(160) NOT NULL,
  `category` enum('learn','contribute','community','organisation') NOT NULL,
  `points` int(10) UNSIGNED NOT NULL,
  `needs_evidence` tinyint(1) NOT NULL DEFAULT 0,
  `one_time` tinyint(1) NOT NULL DEFAULT 0,        -- can be awarded to a member only once
  -- Used from Phase 2 (event finalise): a meetup under the minimum pays below_min_points.
  `min_attendees` int(10) UNSIGNED DEFAULT NULL,
  `below_min_points` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  UNIQUE KEY `uniq_activity_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Used from Phase 2. Created now so point_awards can reference it.
CREATE TABLE IF NOT EXISTS `events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_ref` varchar(40) NOT NULL,                 -- 'ch-2026-11', 'mehfil-2026-11', 'taw-2026-11'
  `event_type` enum('conversation_hour','mehfil','taw','club_session','other') NOT NULL,
  `title` varchar(160) NOT NULL,
  `held_on` date NOT NULL,
  `status` enum('Planned','Held','Finalised','Cancelled') NOT NULL DEFAULT 'Planned',
  `finalised_by_email` varchar(255) DEFAULT NULL,
  `finalised_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_ref` (`event_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `event_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `member_id` varchar(40) NOT NULL,
  `role` enum('attendee','host','cohost','guest','organiser','logistics','trainer') NOT NULL DEFAULT 'attendee',
  `days_attended` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event_member` (`event_id`,`member_id`),
  CONSTRAINT `fk_event_attendance_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything that can become points passes through here first.
-- Under SECOND_APPROVAL_AT (150, see PointsModel) the creating reviewer's action is the approval.
-- At or over it, a different reviewer must approve before a ledger row exists.
CREATE TABLE IF NOT EXISTS `point_awards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` varchar(40) NOT NULL,
  `activity_type_id` int(11) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `source_ref` varchar(60) DEFAULT NULL,         -- where an automatic award came from, e.g. 'ch-session-14'
  `points` int(10) UNSIGNED NOT NULL,               -- the activity's points; revised when an organiser changes them
  `tier` enum('standard','below_min') NOT NULL DEFAULT 'standard',   -- below_min: a host award under the minimum attendance
  `evidence_url` varchar(500) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `status` enum('Needs Second Approval','Approved','Rejected') NOT NULL,
  `created_by_email` varchar(255) NOT NULL,
  `first_reviewer_email` varchar(255) NOT NULL,
  `second_reviewer_email` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  -- Event awards can't be doubled up. (Manual awards have a NULL event_id, which MySQL treats as distinct.)
  UNIQUE KEY `uniq_award_event` (`member_id`,`activity_type_id`,`event_id`),
  -- One award per member, activity and source, so "Award now" for a session can be re-run safely.
  UNIQUE KEY `uniq_award_source` (`member_id`,`activity_type_id`,`source_ref`),
  KEY `idx_award_status` (`status`),
  KEY `activity_type_id` (`activity_type_id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `fk_point_awards_activity` FOREIGN KEY (`activity_type_id`) REFERENCES `activity_types` (`id`),
  CONSTRAINT `fk_point_awards_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goodies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `points_cost` int(10) UNSIGNED NOT NULL,
  `variants_json` JSON DEFAULT NULL,                -- e.g. ["S","M","L","XL"]; NULL = no choice needed
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_goody_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `redemptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(12) NOT NULL,               -- e.g. 'GD-7K4N9XQ2', unrelated to id
  `member_id` varchar(40) NOT NULL,
  `goody_id` int(11) NOT NULL,
  `variant` varchar(60) DEFAULT NULL,
  `points_spent` int(10) UNSIGNED NOT NULL,         -- snapshot of the price at redemption
  `shipping_json` JSON DEFAULT NULL,                -- scrubbed after delivery: PointsModel::scrubShipping()
  `status` enum('Requested','Ordered','Shipped','Delivered','Cancelled') NOT NULL DEFAULT 'Requested',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_id` (`tracking_id`),
  KEY `idx_redemption_member` (`member_id`),
  KEY `idx_redemption_status` (`status`),
  KEY `goody_id` (`goody_id`),
  CONSTRAINT `fk_redemptions_goody` FOREIGN KEY (`goody_id`) REFERENCES `goodies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only. The application only inserts and reads here.
CREATE TABLE IF NOT EXISTS `points_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` varchar(40) NOT NULL,
  `points` int(11) NOT NULL,                        -- + award or refund, - redemption or reversal
  `entry_type` enum('award','redemption','refund','reversal','revaluation') NOT NULL,   -- revaluation: an activity's points were changed
  `award_id` int(11) DEFAULT NULL,
  `redemption_id` int(11) DEFAULT NULL,
  `reason` varchar(255) NOT NULL,
  `actor_email` varchar(255) NOT NULL,              -- snapshot, like audit_log.actor_email
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ledger_award` (`award_id`),                          -- one ledger row per approved award
  UNIQUE KEY `uniq_ledger_redemption` (`redemption_id`,`entry_type`),   -- one debit and at most one refund
  KEY `idx_ledger_member` (`member_id`),
  CONSTRAINT `fk_points_ledger_award` FOREIGN KEY (`award_id`) REFERENCES `point_awards` (`id`),
  CONSTRAINT `fk_points_ledger_redemption` FOREIGN KEY (`redemption_id`) REFERENCES `redemptions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who hosted each Conversation Hour session (ch_sessions has no host), so hosts can be awarded points.
-- Kept apart from the Community records tables: logging attendance and awarding points are different roles.
CREATE TABLE IF NOT EXISTS `ch_session_hosts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` int(10) UNSIGNED NOT NULL,
  `member_id` varchar(20) NOT NULL,
  `added_by` varchar(255) NOT NULL,
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_session_host` (`session_id`,`member_id`),
  CONSTRAINT `fk_ch_host_session` FOREIGN KEY (`session_id`) REFERENCES `ch_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Event cards (printed participation cards, e.g. Train a Wikipedian)
--
-- An organizer or owner creates an event, adds its people (mentees imported
-- from CONFIRMED scholarship awardees, organisers and facilitators added by
-- hand or by CSV) and downloads a print file. See EventCardModel.
--
-- Separate from the Goodies "events" table above on purpose: that one records
-- who attended for points; these tables exist only to print and verify cards.
-- The card_ prefix keeps the two apart.
--
--   card_participants.token           random 32 characters; the QR code on the back opens
--                                     /event-card/<token>. The token says nothing about the person.
--                                     Re-issuing a card overwrites it, so the old QR stops working;
--                                     removing a person deletes the row, which does the same.
--   card_participants.application_id  the scholarship application a mentee was imported from. The UNIQUE
--                                     key stops one application being imported twice into one event.
--                                     NULL for organisers and facilitators (several NULLs are allowed).
--   card_participants.name            the name exactly as printed; editable before printing
--   card_participants.affiliation     empty means "Deoband Community Wikimedia" on the card
--   card_events.logo_key              file name without extension in assets/chapters/ (png or svg)
--
-- Deleting an event deletes its participants (ON DELETE CASCADE), which is the retention rule:
-- delete the event once the cards are no longer needed.
-- ============================================================

CREATE TABLE IF NOT EXISTS `card_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `edition` varchar(80) NOT NULL DEFAULT '',
  `logo_key` varchar(60) DEFAULT NULL,
  `created_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `card_participants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `application_id` int(11) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `role` varchar(32) NOT NULL,                      -- Mentee, Organiser, Facilitator (see EventCardModel::ROLES)
  `affiliation` varchar(120) NOT NULL DEFAULT '',
  `token` char(32) NOT NULL,
  `added_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_card_participants_token` (`token`),
  UNIQUE KEY `uq_card_participants_event_app` (`event_id`,`application_id`),
  KEY `idx_card_participants_event` (`event_id`),
  CONSTRAINT `fk_card_participants_event` FOREIGN KEY (`event_id`) REFERENCES `card_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_card_participants_application` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Default settings rows
--
-- internet_settings and reimbursement_settings each hold a single row
-- (id = 1) that the app reads. Nothing else creates it, so a fresh install
-- would otherwise have both programmes stuck closed with no way to switch
-- them on. Both rows start SWITCHED OFF (is_active = 0); an organizer turns
-- them on from the admin workspace. INSERT IGNORE leaves any existing row
-- untouched.
-- ============================================================

INSERT IGNORE INTO `internet_settings` (`id`, `is_active`, `max_amount_paise`)
VALUES (1, 0, 100000);

INSERT IGNORE INTO `reimbursement_settings` (`id`, `is_active`, `cash_threshold_paise`, `expense_categories`, `instructions`)
VALUES (1, 0, 80000, JSON_ARRAY('Travel', 'Accommodation', 'Food', 'Other'), NULL);

-- Goodies starter data: a draft to adjust, not a decision. Goodies are seeded
-- INACTIVE so nothing shows to members until a support reviewer has set real
-- prices and switched them on. INSERT IGNORE (activity_types.code and
-- goodies.name are unique) means a re-run never duplicates or overwrites rows.
INSERT IGNORE INTO `activity_types` (`code`,`name`,`category`,`points`,`needs_evidence`,`min_attendees`,`below_min_points`,`one_time`) VALUES
  ('ch_attend',            'Attend a Conversation Hour',                'learn',      20,  0, NULL, NULL, 0),
  ('ch_host',              'Host a Conversation Hour',                  'community',  100, 0, 3,    50, 0),
  ('mehfil_attend',        'Attend a Mehfil-e-Wiki',                    'learn',      30,  0, 6,    15, 0),
  ('mehfil_host',          'Host a Mehfil-e-Wiki',                      'community',  120, 0, 6,    60, 0),
  ('taw_graduate',         'Train a Wikipedian: graduated (April 2026 onwards)', 'learn', 140, 1, NULL, NULL, 1),
  ('first_edit',           'First verified edit (one-time)',            'contribute', 30,  1, NULL, NULL, 1),
  ('mentor_first_edit',    'Mentor a newcomer to their first edit',     'community',  100, 1, NULL, NULL, 0),
  ('mentor_first_article', 'Mentor a newcomer to their first article',  'community',  150, 1, NULL, NULL, 0);

INSERT IGNORE INTO `goodies` (`name`,`description`,`points_cost`,`variants_json`,`is_active`) VALUES
  ('Sticker pack', 'DCW stickers.',       50,  NULL, 0),
  ('Notebook',     'DCW kraft notebook.', 150, NULL, 0),
  ('Mug',          'DCW mug.',            300, NULL, 0),
  ('T-shirt',      'DCW T-shirt.',        500, JSON_ARRAY('S','M','L','XL','XXL'), 0);
