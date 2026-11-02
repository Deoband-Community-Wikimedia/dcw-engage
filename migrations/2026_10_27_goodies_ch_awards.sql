-- ============================================================
-- Goodies phase 2: Conversation Hour awards (#196).
--
-- Run once in phpMyAdmin BEFORE the code is merged. Safe to re-run (IF NOT EXISTS everywhere), and safe
-- for the code that is live now: it does not read either change.
--
--   point_awards.source_ref  where an automatic award came from ('ch-session-14'). With the new unique key,
--                            a member gets each activity once per session, so "Award now" can be re-run
--                            and only picks up people who were logged since the last run.
--   ch_session_hosts         who hosted each session, so hosts can be awarded too.
--
-- The same as in database.sql (fresh installs). Keep the two identical.
-- ============================================================

ALTER TABLE `point_awards`
  ADD COLUMN IF NOT EXISTS `source_ref` varchar(60) DEFAULT NULL AFTER `event_id`,
  ADD UNIQUE KEY IF NOT EXISTS `uniq_award_source` (`member_id`,`activity_type_id`,`source_ref`);

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
