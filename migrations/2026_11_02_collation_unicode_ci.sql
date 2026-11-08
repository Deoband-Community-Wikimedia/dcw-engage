-- ============================================================================
-- Migration: bring every table to utf8mb4_unicode_ci
-- ============================================================================
--
-- For installs that were set up before this change. Do NOT run on a database
-- you just created from database.sql: those tables already use
-- utf8mb4_unicode_ci, so there is nothing to do.
--
-- WHY
--   Some tables on existing installs were created without an explicit
--   collation, so they took the server default. On newer MariaDB (11.4+)
--   that default is utf8mb4_uca1400_ai_ci. Comparing such a column with a
--   utf8mb4_unicode_ci column (or a bound parameter) fails with
--   "SQLSTATE[HY000]: 1267 Illegal mix of collations", which is what broke
--   "Directory opt-out list".
--
-- BEFORE YOU RUN IT
--   Take a backup (phpMyAdmin > Export). Each CONVERT rewrites its table.
--
-- NOTES
--   * Safe to re-run. Converting a table already on utf8mb4_unicode_ci
--     changes nothing.
--   * Every ALTER names a table from database.sql. If your install does not
--     have one of them yet (for example a feature you never migrated), run
--     that feature's migration first, or delete its line here.
--   * legacy_members.username must stay case-sensitive (Wikimedia usernames
--     are), so it is put back to utf8mb4_bin at the end.
--   * JSON columns (applications.form_data, forms.schema_json, ...) may
--     change from utf8mb4_bin to utf8mb4_unicode_ci. They stay valid JSON
--     and the app does not depend on their collation.
--   * Backup tables (e.g. applications_backup_20261003) are not touched.
--
-- Check afterwards (replace the schema name if needed). Expect only
-- utf8mb4_bin rows (JSON columns and legacy_members.username):
--
--   SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME
--   FROM information_schema.COLUMNS
--   WHERE TABLE_SCHEMA = DATABASE()
--     AND COLLATION_NAME IS NOT NULL
--     AND COLLATION_NAME <> 'utf8mb4_unicode_ci'
--     AND TABLE_NAME NOT LIKE '%backup%';
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Default for tables created from now on (works whatever the database is called).
SET @sql = CONCAT('ALTER DATABASE `', DATABASE(), '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Organizer accounts, roles and security
ALTER TABLE `admin_users`                      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `admin_user_roles`                 CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `admin_invites`                    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `password_resets`                  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `audit_log`                        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Forms and applications
ALTER TABLE `forms`                            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `applications`                     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `application_notes`                CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `magic_links`                      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `email_verifications`              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `feedback_responses`               CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `scholarship_letters`              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `award_confirm_settings`           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `award_confirmations`              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Membership
ALTER TABLE `members`                          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `member_password_tokens`           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `member_profile`                   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `member_strikes`                   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `membership_scopes`                CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `membership_decisions`             CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `membership_id_links`              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `member_directory_optout`          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `legacy_members`                   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- legacy_members.username is case-sensitive by design: restore it.
ALTER TABLE `legacy_members`
  MODIFY `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;

-- Membership cards
ALTER TABLE `member_cards`                     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `member_card_hits`                 CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Member support
ALTER TABLE `member_tickets`                   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `member_ticket_messages`           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `internal_notes`                   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Internet support
ALTER TABLE `internet_requests`                CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `internet_request_messages`        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `internet_settings`                CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Reimbursements
ALTER TABLE `reimbursement_forms`              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `reimbursement_eligibility`        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `reimbursement_requests`           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `reimbursement_line_items`         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `reimbursement_settings`           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `reimbursement_global_eligibility` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `reimbursement_request_messages`   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Tech issues
ALTER TABLE `tech_issues`                      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `tech_issue_messages`              CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Community records
ALTER TABLE `ch_sessions`                      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `ch_attendance`                    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `ch_eligibility_snapshots`         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `tech_contributors`                CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Goodies
ALTER TABLE `activity_types`                   CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `events`                           CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `event_attendance`                 CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `point_awards`                     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `goodies`                          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `redemptions`                      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `points_ledger`                    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `ch_session_hosts`                 CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Event cards
ALTER TABLE `card_events`                      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `card_participants`                CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
