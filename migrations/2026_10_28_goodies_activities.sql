-- ============================================================
-- Goodies: activities and their points are managed in the workspace (#208).
--
-- Run once in phpMyAdmin BEFORE the code is merged. Safe for the code that is live now (it doesn't read
-- the new columns, and the new ledger value is only written by the new code). Re-running it changes
-- nothing except setting first_edit and taw_graduate back to one-time.
--
--   activity_types.one_time     "can be awarded only once" becomes a setting (was a list in the code)
--   activity_types name unique  so two activities can't share a name
--   point_awards.tier           which value a Conversation Hour host award used ('below_min' under the
--                               minimum attendance), so revising points revises it correctly
--   points_ledger 'revaluation' the ledger row that moves a balance when an activity's points change
--
-- The same as in database.sql (fresh installs).
-- ============================================================

ALTER TABLE `activity_types`
  ADD COLUMN IF NOT EXISTS `one_time` tinyint(1) NOT NULL DEFAULT 0 AFTER `needs_evidence`,
  ADD UNIQUE KEY IF NOT EXISTS `uniq_activity_name` (`name`);

-- Keep today's behaviour: these two were one-time in the code.
UPDATE `activity_types` SET `one_time` = 1 WHERE `code` IN ('first_edit', 'taw_graduate');

ALTER TABLE `point_awards`
  ADD COLUMN IF NOT EXISTS `tier` enum('standard','below_min') NOT NULL DEFAULT 'standard' AFTER `points`;

-- Host awards already made under the minimum attendance.
UPDATE `point_awards` a JOIN `activity_types` t ON t.id = a.activity_type_id
   SET a.tier = 'below_min'
 WHERE a.source_ref IS NOT NULL AND t.below_min_points IS NOT NULL
   AND t.below_min_points <> t.points AND a.points = t.below_min_points;

ALTER TABLE `points_ledger`
  MODIFY `entry_type` enum('award','redemption','refund','reversal','revaluation') NOT NULL;
