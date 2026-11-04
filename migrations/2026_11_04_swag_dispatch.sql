-- ============================================================================
-- Swag "Dispatched" status and form kinds
-- Run ONCE on an install that already exists. A fresh install uses database.sql instead.
--
-- BEFORE YOU RUN IT
--   1. Take a backup export.
--   2. Click your database in phpMyAdmin (so it is the selected one), then check that every status in use
--      is one of the six kept below:
--          SELECT status, COUNT(*) FROM applications GROUP BY status;
--      (Expected: Draft, New, Under Review, Accepted, Rejected, Discarded.)
--
-- Safe to run twice: MODIFY, CREATE IF NOT EXISTS and the form UPDATEs can all be repeated.
-- ============================================================================

SET NAMES utf8mb4;

-- 1. New application status. The six existing values are kept exactly as they are.
ALTER TABLE `applications`
  MODIFY COLUMN `status` ENUM('Draft','New','Under Review','Accepted','Rejected','Discarded','Dispatched') DEFAULT 'New';

-- 2. Courier details of a dispatched swag application: one row per application.
--    Deleted with the application (ON DELETE CASCADE), and by the PII scrub (cron/scrub_pii.php).
CREATE TABLE IF NOT EXISTS `application_dispatches` (
  `application_id` int(11) NOT NULL,
  `courier` varchar(60) NOT NULL,
  `awb` varchar(80) NOT NULL,                      -- the courier's consignment number (not the DCW- tracking ID)
  `tracking_url` varchar(500) DEFAULT NULL,        -- optional, pasted by the organizer from the courier's site
  `dispatched_by` varchar(255) NOT NULL,
  `dispatched_at` datetime NOT NULL,               -- UTC
  `email_sent_at` datetime DEFAULT NULL,           -- UTC; NULL = the applicant has not been emailed yet
  `email_failed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`application_id`),
  CONSTRAINT `fk_dispatch_application` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Give the existing forms a kind (the builder locks the type of a form after it is created).
--    Check the slugs first:  SELECT form_type FROM forms WHERE form_type LIKE '%swag%' OR form_type LIKE 'call-for-fellows%';
UPDATE `forms` SET `schema_json` = JSON_SET(`schema_json`, '$.kind', 'swag')
 WHERE `form_type` = 'visual-communication-principles-and-practice-for-digital-design-swag';

-- Every "Call for Fellows" iteration (also the closed ones) is a scholarship / fellowship form.
UPDATE `forms` SET `schema_json` = JSON_SET(`schema_json`, '$.kind', 'scholarship')
 WHERE `form_type` LIKE 'call-for-fellows-%';
