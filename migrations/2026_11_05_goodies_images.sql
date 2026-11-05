-- ============================================================
-- Goodies: pictures for catalogue items.
--
-- Run once in phpMyAdmin for an EXISTING database, before the image-upload code is merged.
-- It adds one column to goodies and touches nothing else. Safe to re-run: the column is only
-- added when it is missing. Do not run this on a database you just created from database.sql
-- (that file already has the column; running it again is harmless but unnecessary).
--
-- goodies.images_json holds the pictures of one goody, in display order. The first one is the
-- cover shown on the member's catalogue card; all of them can be flipped through in the redeem dialog:
--
--   [{"file":"7f3a9c1e5b2d4a60.jpg","alt":"Front of the DCW mug"},
--    {"file":"c41d8e0a9b7f3265.jpg","alt":"Mug with the logo facing left"}]
--
--   file  the stored file name inside uploads/goodies/ (random, never the uploaded name)
--   alt   short description for screen readers; may be empty (the page then falls back to the goody's name)
--
-- NULL = no pictures (members keep seeing the initial-letter placeholder).
-- Kept as text, like stock_json, so it works on MySQL 8.0+ and MariaDB 10.4+.
-- ============================================================

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'goodies' AND COLUMN_NAME = 'images_json'
);

SET @sql := IF(@has_col = 0,
  'ALTER TABLE `goodies` ADD COLUMN `images_json` text DEFAULT NULL AFTER `stock_json`',
  'SELECT ''goodies.images_json already exists, nothing to do'' AS info');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
