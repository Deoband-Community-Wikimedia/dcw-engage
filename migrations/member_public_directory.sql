-- DCW Engage - public members directory (/members).
-- Upgrade script for an install that was created before these tables existed.
-- (A fresh install gets both tables from database.sql. Running this on top is harmless: IF NOT EXISTS.)
--
-- member_directory_optout: a row = "this Member ID asked not to be listed". Delete the row to list them again.
-- legacy_members: people imported once from the old Meta-Wiki list (Template:DCW/Members). See
--                 legacy_members_import.sql. Hidden automatically once the same username is on Engage.
--
-- Collation matches the rest of the schema (utf8mb4_unicode_ci) so joins with members/member_profile are safe.
-- legacy_members.username is utf8mb4_bin on purpose: Wikimedia usernames are case-sensitive.

CREATE TABLE IF NOT EXISTS `member_directory_optout` (
  `member_id` varchar(32) NOT NULL,
  `hidden_at` datetime NOT NULL,
  `hidden_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `legacy_members` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `chapter` varchar(20) NOT NULL DEFAULT '',          -- generic | amu | jamia | photographers | '' (club not known)
  `added_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_legacy_user_chapter` (`username`,`chapter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
