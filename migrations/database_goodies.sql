-- ============================================================
-- Goodies: non-expiring member points, redeemable for DCW goodies
--
-- Live on a fresh import: append this file to database.sql.
-- On an existing database, run it by hand, once.
--
-- member_id is the Member ID from the members table: one letter and 8 digits
-- (for example A48213977), shared by every chapter a person holds, so points
-- follow the person. It is stored as VARCHAR(40) with no foreign key because
-- members has one row per chapter under the same ID. Check that its collation
-- matches members.member_id if you ever join the two.
--
-- Points are never edited. points_ledger is append-only: the balance
-- is SUM(points) per member, and corrections are new rows.
-- Timestamps are stored in UTC and shown in IST.
-- ============================================================

CREATE TABLE `activity_types` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(40) NOT NULL UNIQUE,
  `name` VARCHAR(160) NOT NULL,
  `category` ENUM('learn','contribute','community','organisation') NOT NULL,
  `points` INT UNSIGNED NOT NULL,
  `needs_evidence` TINYINT(1) NOT NULL DEFAULT 0,
  -- Used from Phase 2 (event finalise): a meetup under the minimum pays below_min_points.
  `min_attendees` INT UNSIGNED NULL,
  `below_min_points` INT UNSIGNED NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Used from Phase 2. Created now so point_awards can reference it.
CREATE TABLE `events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `event_ref` VARCHAR(40) NOT NULL UNIQUE,          -- 'ch-2026-11', 'mehfil-2026-11', 'taw-2026-11'
  `event_type` ENUM('conversation_hour','mehfil','taw','club_session','other') NOT NULL,
  `title` VARCHAR(160) NOT NULL,
  `held_on` DATE NOT NULL,
  `status` ENUM('Planned','Held','Finalised','Cancelled') NOT NULL DEFAULT 'Planned',
  `finalised_by_email` VARCHAR(255) NULL,
  `finalised_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `event_attendance` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT NOT NULL,
  `member_id` VARCHAR(40) NOT NULL,
  `role` ENUM('attendee','host','cohost','guest','organiser','logistics','trainer') NOT NULL DEFAULT 'attendee',
  `days_attended` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY `uniq_event_member` (`event_id`, `member_id`),
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
);

-- Everything that can become points passes through here first.
-- Under SECOND_APPROVAL_AT (150, see PointsModel) the creating reviewer's action is the approval.
-- At or over it, a different reviewer must approve before a ledger row exists.
CREATE TABLE `point_awards` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `member_id` VARCHAR(40) NOT NULL,
  `activity_type_id` INT NOT NULL,
  `event_id` INT NULL,
  `points` INT UNSIGNED NOT NULL,                   -- snapshot of the activity's points at creation
  `evidence_url` VARCHAR(500) NULL,
  `note` VARCHAR(500) NULL,
  `status` ENUM('Needs Second Approval','Approved','Rejected') NOT NULL,
  `created_by_email` VARCHAR(255) NOT NULL,
  `first_reviewer_email` VARCHAR(255) NOT NULL,
  `second_reviewer_email` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `decided_at` DATETIME NULL,
  -- Event awards can't be doubled up. (Manual awards have a NULL event_id, which MySQL treats as distinct.)
  UNIQUE KEY `uniq_award_event` (`member_id`, `activity_type_id`, `event_id`),
  KEY `idx_award_status` (`status`),
  FOREIGN KEY (`activity_type_id`) REFERENCES `activity_types`(`id`),
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`)
);

CREATE TABLE `goodies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `description` VARCHAR(500) NULL,
  `points_cost` INT UNSIGNED NOT NULL,
  `variants_json` JSON NULL,                        -- e.g. ["S","M","L","XL"]; NULL = no choice needed
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `redemptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tracking_id` VARCHAR(12) NOT NULL UNIQUE,        -- e.g. 'GD-7K4N9XQ2', unrelated to id
  `member_id` VARCHAR(40) NOT NULL,
  `goody_id` INT NOT NULL,
  `variant` VARCHAR(60) NULL,
  `points_spent` INT UNSIGNED NOT NULL,             -- snapshot of the price at redemption
  `shipping_json` JSON NULL,                        -- scrubbed after delivery: PointsModel::scrubShipping()
  `status` ENUM('Requested','Ordered','Shipped','Delivered','Cancelled') NOT NULL DEFAULT 'Requested',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_redemption_member` (`member_id`),
  KEY `idx_redemption_status` (`status`),
  FOREIGN KEY (`goody_id`) REFERENCES `goodies`(`id`)
);

-- Append-only. The application only inserts and reads here.
CREATE TABLE `points_ledger` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `member_id` VARCHAR(40) NOT NULL,
  `points` INT NOT NULL,                            -- + award or refund, - redemption or reversal
  `entry_type` ENUM('award','redemption','refund','reversal') NOT NULL,
  `award_id` INT NULL,
  `redemption_id` INT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `actor_email` VARCHAR(255) NOT NULL,              -- snapshot, like audit_log.actor_email
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_ledger_award` (`award_id`),                       -- one ledger row per approved award
  UNIQUE KEY `uniq_ledger_redemption` (`redemption_id`, `entry_type`), -- one debit and at most one refund
  KEY `idx_ledger_member` (`member_id`),
  FOREIGN KEY (`award_id`) REFERENCES `point_awards`(`id`),
  FOREIGN KEY (`redemption_id`) REFERENCES `redemptions`(`id`)
);

-- ============================================================
-- Starter data: a draft to adjust, not a decision.
-- Goodies are seeded INACTIVE so nothing shows to members until a
-- support reviewer has set real prices and switched them on.
-- ============================================================
INSERT INTO `activity_types` (`code`,`name`,`category`,`points`,`needs_evidence`,`min_attendees`,`below_min_points`) VALUES
  ('ch_attend',          'Attend a Conversation Hour',                 'learn',     20,  0, NULL, NULL),
  ('ch_host',            'Host a Conversation Hour',                   'community', 100, 0, 3,    50),
  ('mehfil_attend',      'Attend a Mehfil-e-Wiki',                     'learn',     30,  0, 6,    15),
  ('mehfil_host',        'Host a Mehfil-e-Wiki',                       'community', 120, 0, 6,    60),
  ('taw_day',            'Train a Wikipedian: one day attended',       'learn',     100, 0, NULL, NULL),
  ('first_edit',         'First verified edit (one-time)',             'contribute',30,  1, NULL, NULL),
  ('mentor_first_edit',  'Mentor a newcomer to their first edit',      'community', 100, 1, NULL, NULL),
  ('mentor_first_article','Mentor a newcomer to their first article',  'community', 150, 1, NULL, NULL);

INSERT INTO `goodies` (`name`,`description`,`points_cost`,`variants_json`,`is_active`) VALUES
  ('Sticker pack', 'DCW stickers.',        50,  NULL, 0),
  ('Notebook',     'DCW kraft notebook.',  150, NULL, 0),
  ('Mug',          'DCW mug.',             300, NULL, 0),
  ('T-shirt',      'DCW T-shirt.',         500, JSON_ARRAY('S','M','L','XL','XXL'), 0);
