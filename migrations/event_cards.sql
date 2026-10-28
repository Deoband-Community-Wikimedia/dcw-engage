-- DCW Engage - event participation cards
--
-- Run once on a fresh or existing database. Table names carry a card_ prefix so they cannot clash with any
-- events table the portal may already have.
--
-- card_events        one row per event edition ("Train-a-Wikipedian", "November 2026")
-- card_participants  one row per person who gets a card. The token is random and says nothing about the
--                    person; the QR code on the back points at /event-card/<token>. Re-issuing a card
--                    overwrites the token, so the old QR code stops working at once. Removing a person
--                    deletes the row, which kills the token the same way.
--
-- application_id links a mentee back to the scholarship application they were imported from, and the
-- UNIQUE key stops the same application being imported twice into one event. Organisers and facilitators
-- have no application, so it stays NULL for them (several NULLs do not violate a UNIQUE key).
--
-- Retention: delete an event and its participants go with it (ON DELETE CASCADE).

CREATE TABLE `card_events` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(120) NOT NULL,
    `edition`    VARCHAR(80)  NOT NULL DEFAULT '',
    `logo_key`   VARCHAR(60)  NULL,          -- file name without extension in assets/chapters/ (png or svg), e.g. 'taw'
    `created_by` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `card_participants` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `event_id`       INT NOT NULL,
    `application_id` INT NULL,
    `name`           VARCHAR(120) NOT NULL,   -- the name exactly as printed on the card
    `role`           VARCHAR(32)  NOT NULL,   -- Mentee, Organiser, Facilitator (see EventCardModel::ROLES)
    `affiliation`    VARCHAR(120) NOT NULL DEFAULT '',   -- empty = "Deoband Community Wikimedia"
    `token`          CHAR(32) NOT NULL UNIQUE,
    `added_by`       VARCHAR(255) NOT NULL,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_event_application` (`event_id`, `application_id`),
    KEY `idx_event` (`event_id`),
    FOREIGN KEY (`event_id`)       REFERENCES `card_events`(`id`)   ON DELETE CASCADE,
    FOREIGN KEY (`application_id`) REFERENCES `applications`(`id`)  ON DELETE SET NULL
);
