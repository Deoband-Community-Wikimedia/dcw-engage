-- ============================================================
-- Goodies: members can claim points (Phase 2).
--
-- For an EXISTING database. Run once in phpMyAdmin. The ALTER fails with "Duplicate column" if it
-- was already run; the two CREATE TABLE statements are safe to re-run.
-- Fresh installs: put the same three changes into section 10 of database.sql (see the end of this file).
--
-- A claim is a member's request for points for something DCW does not log itself (a first edit, mentoring
-- a newcomer, ...). A reviewer approves it, which creates an ordinary point_awards row with
-- source_ref 'claim-<claim id>' (so the second-approval rule, strikes, audit log and the unique key
-- that stops double awards all apply exactly as for any other award), or rejects it, or asks for more
-- information. Timestamps are UTC, shown in IST.
-- ============================================================

-- Which activities members may claim. Off by default: reviewers switch it on per activity
-- (Activities and points tab). The built-in ones (Conversation Hour, TaW) can never be claimed.
ALTER TABLE `activity_types`
  ADD COLUMN `member_claimable` tinyint(1) NOT NULL DEFAULT 0 AFTER `one_time`;

-- A sensible starting set; reviewers can change it.
UPDATE `activity_types` SET `member_claimable` = 1
 WHERE `code` IN ('first_edit','mentor_first_edit','mentor_first_article');

CREATE TABLE IF NOT EXISTS `point_claims` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tracking_id` varchar(12) NOT NULL,               -- e.g. 'PC-7K4N9XQ2'
  `member_id` varchar(40) NOT NULL,
  `activity_type_id` int(11) NOT NULL,
  `event_date` date NOT NULL,                       -- when the member did it (claims close 90 days after)
  `evidence_url` varchar(500) DEFAULT NULL,
  `note` varchar(1000) DEFAULT NULL,                -- the member's own words
  `status` enum('Submitted','Needs info','Approved','Rejected','Withdrawn') NOT NULL DEFAULT 'Submitted',
  `decided_by` varchar(255) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_note` varchar(1000) DEFAULT NULL,       -- the reason, shown to the member
  `award_id` int(11) DEFAULT NULL,                  -- the award an approval created
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_id` (`tracking_id`),
  KEY `idx_claim_member` (`member_id`,`status`),
  KEY `idx_claim_status` (`status`),
  KEY `activity_type_id` (`activity_type_id`),
  KEY `award_id` (`award_id`),
  CONSTRAINT `fk_point_claims_activity` FOREIGN KEY (`activity_type_id`) REFERENCES `activity_types` (`id`),
  CONSTRAINT `fk_point_claims_award` FOREIGN KEY (`award_id`) REFERENCES `point_awards` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The conversation on a claim ("Needs info" and the member's reply).
CREATE TABLE IF NOT EXISTS `point_claim_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `claim_id` int(11) NOT NULL,
  `sender` enum('reviewer','member') NOT NULL,
  `author` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_claim` (`claim_id`,`id`),
  CONSTRAINT `fk_claim_msg_claim` FOREIGN KEY (`claim_id`) REFERENCES `point_claims` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- FOR database.sql (fresh installs), section 10:
--   1. In CREATE TABLE `activity_types`, add this line after `one_time`:
--        `member_claimable` tinyint(1) NOT NULL DEFAULT 0,       -- members may claim it (reviewers decide)
--   2. Add the two CREATE TABLE statements above (point_claims, point_claim_messages) after `points_ledger`.
--   3. Add to the starter data, after the activity_types INSERT:
--        UPDATE `activity_types` SET `member_claimable` = 1 WHERE `code` IN ('first_edit','mentor_first_edit','mentor_first_article');
-- ------------------------------------------------------------
