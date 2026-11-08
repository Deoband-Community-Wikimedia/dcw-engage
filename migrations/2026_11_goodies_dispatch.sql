-- ============================================================
-- Goodies: how an order was sent (courier + AWB, or by hand).
--
-- For an EXISTING database. Run once in phpMyAdmin (it fails with "Duplicate column" if run twice).
-- Fresh installs: add the same columns to CREATE TABLE `redemptions` in database.sql (section 10).
--
-- Marking an order Shipped now records:
--   delivery_method   'courier' (courier + consignment/AWB number, optional tracking link)
--                     or 'hand'  (handed over personally: who, where, when, in handover_note)
--   dispatched_by     the reviewer, dispatched_at the time (UTC, shown in IST)
--   email_sent_at     when the member was emailed; NULL = not emailed yet ("Send email again" is offered)
-- None of this is personal data like the address is, so it stays after shipping_json is scrubbed.
-- ============================================================

ALTER TABLE `redemptions`
  ADD COLUMN `delivery_method` enum('courier','hand') DEFAULT NULL AFTER `status`,
  ADD COLUMN `courier` varchar(60) DEFAULT NULL AFTER `delivery_method`,
  ADD COLUMN `awb` varchar(80) DEFAULT NULL AFTER `courier`,
  ADD COLUMN `tracking_url` varchar(500) DEFAULT NULL AFTER `awb`,
  ADD COLUMN `handover_note` varchar(255) DEFAULT NULL AFTER `tracking_url`,
  ADD COLUMN `dispatched_by` varchar(255) DEFAULT NULL AFTER `handover_note`,
  ADD COLUMN `dispatched_at` datetime DEFAULT NULL AFTER `dispatched_by`,
  ADD COLUMN `email_sent_at` datetime DEFAULT NULL AFTER `dispatched_at`;
