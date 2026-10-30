-- ============================================================================
-- Migration: event cards (printed participation cards, e.g. Train a Wikipedian)
-- ============================================================================
--
-- For an install that was set up BEFORE these tables existed. A fresh install
-- gets them from database.sql; do not run this on a database just created from it.
--
--     mysql -u root -p your_database_name < migrations/event_cards.sql
--
-- Safe to re-run (CREATE TABLE IF NOT EXISTS). Needs the applications table, which
-- every install already has. The column notes are in the "Event cards" section of
-- database.sql.
-- ============================================================================

SET NAMES utf8mb4;

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
