-- Award confirmation: awardees confirm (or decline) their scholarship and share travel and stay details.
--
-- For an install that was set up before these tables existed (a fresh install gets them from database.sql).
-- Run once, after 2026_10_23_scholarship_letters.sql. Safe to re-run. Until it has been run, the feature
-- stays off: AwardConfirmationModel returns "nothing" instead of failing.
--
-- The foreign keys delete an awardee's answers together with the application (and a program's settings
-- together with the form), so nothing personal is left behind. They assume forms.id and applications.id are
-- int(11), as in database.sql.

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
  `token` char(64) NOT NULL,
  `response` enum('pending','confirmed','declined') NOT NULL DEFAULT 'pending',
  `answers_enc` mediumtext DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `reminder_count` int(11) NOT NULL DEFAULT 0,
  `last_reminded_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_award_app` (`application_id`),
  UNIQUE KEY `uniq_award_token` (`token`),
  CONSTRAINT `fk_ac_application` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
