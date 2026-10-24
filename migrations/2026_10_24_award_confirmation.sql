-- Award confirmation: awardees confirm (or decline) their scholarship and share travel and stay details.
--
-- Run once, after 2026_10_23_scholarship_letters.sql. Until it has been run, the feature stays off:
-- AwardConfirmationModel returns "nothing" instead of failing, and application review shows no confirmation panel
-- state.
--
-- NOTE: the two foreign keys must use the same column type as forms.id and applications.id. They are written as
-- INT; if MySQL refuses them ("incompatible"), check `SHOW CREATE TABLE forms;` and change INT to match
-- (for example INT UNSIGNED or BIGINT). Keep the foreign keys: they delete the awardees' answers together with
-- the application, so nothing personal is left behind when a form is deleted.

-- One row per program: is confirmation on, by when, what to ask.
CREATE TABLE IF NOT EXISTS award_confirm_settings (
    form_id     INT NOT NULL PRIMARY KEY,
    enabled     TINYINT(1) NOT NULL DEFAULT 0,
    deadline_at DATETIME NULL,            -- UTC, like forms.deadline_at; typed and shown in IST
    intro_text  TEXT NULL,                -- shown at the top of the confirmation page (wikitext subset)
    fields_text TEXT NULL,                -- one question per line: Label | type | required/optional | options
    saved_by    VARCHAR(190) NULL,
    saved_at    DATETIME NULL,
    CONSTRAINT fk_acs_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per awarded application: its private link and the awardee's response.
CREATE TABLE IF NOT EXISTS award_confirmations (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    application_id   INT NOT NULL,
    token            CHAR(64) NOT NULL,   -- the private link: /confirm/<token>
    response         ENUM('pending','confirmed','declined') NOT NULL DEFAULT 'pending',
    answers_enc      MEDIUMTEXT NULL,     -- encrypted JSON: the answers and any reason given (never stored in plain text)
    responded_at     DATETIME NULL,       -- UTC
    reminder_count   INT NOT NULL DEFAULT 0,
    last_reminded_at DATETIME NULL,       -- UTC
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_award_app (application_id),
    UNIQUE KEY uniq_award_token (token),
    CONSTRAINT fk_ac_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
