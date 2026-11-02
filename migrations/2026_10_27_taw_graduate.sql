-- ============================================================
-- Goodies: Train a Wikipedian becomes one "graduate" award (#196).
--
-- Replaces the per-day TaW activity with a single award for graduating from the whole programme:
-- 140 points, once per member, from the April 2026 TaW onwards (earlier editions don't count).
-- The evidence link must be the TaW's page on dcwwiki.org; PointsModel checks the edition.
--
-- Run in phpMyAdmin BEFORE the code is merged. Safe to re-run. The live code reads activity
-- types by id, so renaming the row is safe for it too.
-- ============================================================

UPDATE `activity_types`
   SET `code` = 'taw_graduate', `name` = 'Train a Wikipedian: graduated (April 2026 onwards)',
       `points` = 140, `needs_evidence` = 1
 WHERE `code` = 'taw_day';

-- Only for a database that never had taw_day (does nothing once the row exists).
INSERT IGNORE INTO `activity_types` (`code`,`name`,`category`,`points`,`needs_evidence`,`min_attendees`,`below_min_points`) VALUES
  ('taw_graduate', 'Train a Wikipedian: graduated (April 2026 onwards)', 'learn', 140, 1, NULL, NULL);
