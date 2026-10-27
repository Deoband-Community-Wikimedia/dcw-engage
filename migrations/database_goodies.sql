-- ============================================================
-- Goodies: migration for an EXISTING database (#196, #197).
--
-- Run once in phpMyAdmin before the Goodies code is merged. It only creates the seven new
-- tables and seeds them; nothing existing is touched. Safe to re-run: CREATE TABLE IF NOT
-- EXISTS and INSERT IGNORE leave existing tables and rows alone.
--
-- This is the same as section 10 of database.sql (fresh installs). Keep the two identical.
-- ============================================================

-- member_id is the Member ID from the members table: one letter and 8 digits
-- (for example A48213977), shared by every chapter a person holds, so points
-- follow the person. It is VARCHAR(40) with no foreign key because members
-- has one row per chapter under the same ID. Same utf8mb4_unicode_ci
-- collation as members, so joining on member_id is safe.
--
-- Points are never edited. points_ledger is append-only: the balance
-- is SUM(points) per member, and corrections are new rows.
-- Timestamps are stored in UTC and shown in IST.

CREATE TABLE IF NOT EXISTS `activity_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(160) NOT NULL,
  `category` enum('learn','contribute','community','organisation') NOT NULL,
  `points` int(10) UNSIGNED NOT NULL,
  `needs_evidence` tinyint(1) NOT NULL DEFAULT 0,
  -- Used from Phase 2 (event finalise): a meetup under the minimum pays below_min_points.
  `min_attendees` int(10) UNSIGNED DEFAULT NULL,
  `below_min_points` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
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
  `points` int(10) UNSIGNED NOT NULL,               -- snapshot of the activity's points at creation
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
  `entry_type` enum('award','redemption','refund','reversal') NOT NULL,
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

-- Goodies starter data: a draft to adjust, not a decision. Goodies are seeded
-- INACTIVE so nothing shows to members until a support reviewer has set real
-- prices and switched them on. INSERT IGNORE (activity_types.code and
-- goodies.name are unique) means a re-run never duplicates or overwrites rows.
INSERT IGNORE INTO `activity_types` (`code`,`name`,`category`,`points`,`needs_evidence`,`min_attendees`,`below_min_points`) VALUES
  ('ch_attend',            'Attend a Conversation Hour',                'learn',      20,  0, NULL, NULL),
  ('ch_host',              'Host a Conversation Hour',                  'community',  100, 0, 3,    50),
  ('mehfil_attend',        'Attend a Mehfil-e-Wiki',                    'learn',      30,  0, 6,    15),
  ('mehfil_host',          'Host a Mehfil-e-Wiki',                      'community',  120, 0, 6,    60),
  ('taw_graduate',         'Train a Wikipedian: graduated (April 2026 onwards)', 'learn', 140, 1, NULL, NULL),
  ('first_edit',           'First verified edit (one-time)',            'contribute', 30,  1, NULL, NULL),
  ('mentor_first_edit',    'Mentor a newcomer to their first edit',     'community',  100, 1, NULL, NULL),
  ('mentor_first_article', 'Mentor a newcomer to their first article',  'community',  150, 1, NULL, NULL);

INSERT IGNORE INTO `goodies` (`name`,`description`,`points_cost`,`variants_json`,`is_active`) VALUES
  ('Sticker pack', 'DCW stickers.',       50,  NULL, 0),
  ('Notebook',     'DCW kraft notebook.', 150, NULL, 0),
  ('Mug',          'DCW mug.',            300, NULL, 0),
  ('T-shirt',      'DCW T-shirt.',        500, JSON_ARRAY('S','M','L','XL','XXL'), 0);
