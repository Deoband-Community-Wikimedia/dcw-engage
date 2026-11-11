-- Goodies: carts (several goodies in one order), refunds per unit, order limits per goody,
-- and repeating claims (for example "100+ edits in the last 30 days", claimable every 30 days).
-- Run ONCE on an install that was set up before this change. A fresh install gets all of it from database.sql.

-- 1. An order (one row in redemptions, one GD- tracking ID) holds its goodies as items.
CREATE TABLE IF NOT EXISTS `redemption_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `redemption_id` int(11) NOT NULL,
  `goody_id` int(11) NOT NULL,
  `variant` varchar(60) DEFAULT NULL,
  `qty` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `qty_refunded` smallint(5) UNSIGNED NOT NULL DEFAULT 0,   -- units refunded so far; qty - qty_refunded still ships
  `points_each` int(10) UNSIGNED NOT NULL,                  -- the price at order time
  PRIMARY KEY (`id`),
  KEY `idx_item_redemption` (`redemption_id`),
  KEY `idx_item_goody` (`goody_id`),
  CONSTRAINT `fk_items_redemption` FOREIGN KEY (`redemption_id`) REFERENCES `redemptions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_items_goody` FOREIGN KEY (`goody_id`) REFERENCES `goodies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. One row per refund action (a whole order, or some units of one line). Each has its own ledger row.
CREATE TABLE IF NOT EXISTS `redemption_refunds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `redemption_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty` smallint(5) UNSIGNED NOT NULL,
  `points` int(10) UNSIGNED NOT NULL,
  `reason` varchar(255) NOT NULL,                           -- shown to the member on My orders
  `restocked` tinyint(1) NOT NULL DEFAULT 0,
  `refunded_by` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_refund_redemption` (`redemption_id`),
  KEY `idx_refund_item` (`item_id`),
  CONSTRAINT `fk_refunds_redemption` FOREIGN KEY (`redemption_id`) REFERENCES `redemptions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_refunds_item` FOREIGN KEY (`item_id`) REFERENCES `redemption_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Old orders become orders with one item. redemptions.goody_id / variant stay for those old rows only.
ALTER TABLE `redemptions` MODIFY `goody_id` int(11) DEFAULT NULL;

INSERT INTO `redemption_items` (`redemption_id`, `goody_id`, `variant`, `qty`, `qty_refunded`, `points_each`)
SELECT r.`id`, r.`goody_id`, r.`variant`, 1, IF(r.`status` = 'Cancelled', 1, 0), r.`points_spent`
  FROM `redemptions` r
 WHERE r.`goody_id` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `redemption_items` i WHERE i.`redemption_id` = r.`id`);

-- Old cancelled orders: record the refund that already happened (its ledger row keeps refund_id 0).
INSERT INTO `redemption_refunds` (`redemption_id`, `item_id`, `qty`, `points`, `reason`, `restocked`, `refunded_by`, `created_at`)
SELECT r.`id`, i.`id`, 1, r.`points_spent`, 'Order cancelled', 1, 'system', r.`updated_at`
  FROM `redemptions` r JOIN `redemption_items` i ON i.`redemption_id` = r.`id`
 WHERE r.`status` = 'Cancelled'
   AND NOT EXISTS (SELECT 1 FROM `redemption_refunds` f WHERE f.`redemption_id` = r.`id`);

-- 4. Ledger: one debit per order (refund_id 0) and one row per refund (refund_id = redemption_refunds.id).
--    One ALTER, so the foreign key on redemption_id always has an index to use.
ALTER TABLE `points_ledger` ADD COLUMN `refund_id` int(11) NOT NULL DEFAULT 0 AFTER `redemption_id`;
ALTER TABLE `points_ledger`
  DROP INDEX `uniq_ledger_redemption`,
  ADD UNIQUE KEY `uniq_ledger_redemption` (`redemption_id`,`entry_type`,`refund_id`);

-- 5. Most units of a goody in one order (NULL = no limit). Big items 1, smaller items 2, stickers none.
ALTER TABLE `goodies` ADD COLUMN `max_per_order` tinyint(3) UNSIGNED DEFAULT NULL AFTER `images_json`;
UPDATE `goodies` SET `max_per_order` = 1 WHERE `name` IN ('Mug','T-shirt');
UPDATE `goodies` SET `max_per_order` = 2 WHERE `name` = 'Notebook';

-- 6. Repeating claims.
ALTER TABLE `activity_types`
  ADD COLUMN `cooldown_days` smallint(5) UNSIGNED DEFAULT NULL AFTER `member_claimable`,
  ADD COLUMN `needs_username` tinyint(1) NOT NULL DEFAULT 0 AFTER `cooldown_days`;

-- The 100+ edits activity: switched OFF until a reviewer sets its points and turns it on (Activities and points tab).
INSERT IGNORE INTO `activity_types`
  (`code`,`name`,`category`,`points`,`needs_evidence`,`one_time`,`member_claimable`,`cooldown_days`,`needs_username`,`is_active`)
VALUES
  ('edits_100_30d','Made 100+ edits in the last 30 days','contribute',100,1,0,1,30,1,0);
