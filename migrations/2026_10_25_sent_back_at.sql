-- Sent-back applications can be resubmitted after the deadline (#161).
--
-- applications.sent_back_at is set when staff use "Needs information" (application review) or
-- "Ask for more information" (membership review). A Draft with sent_back_at set may be submitted
-- even when its form is closed; the applicant's own unfinished drafts still can't.
--
-- Run BEFORE deploying the new code (it writes this column). Safe for the old code: it ignores it.

ALTER TABLE applications ADD COLUMN sent_back_at DATETIME NULL DEFAULT NULL AFTER letter_failed;

-- Backfill: Drafts that staff already sent back, so those applicants can answer too.
UPDATE applications a
   SET a.sent_back_at = (SELECT MAX(l.created_at) FROM audit_log l
                          WHERE l.action = 'application.info' AND l.target = CONCAT('Application #', a.id))
 WHERE a.status = 'Draft' AND a.sent_back_at IS NULL
   AND EXISTS (SELECT 1 FROM audit_log l
                WHERE l.action = 'application.info' AND l.target = CONCAT('Application #', a.id));

UPDATE applications a
   SET a.sent_back_at = (SELECT MAX(d.decided_at) FROM membership_decisions d
                          WHERE d.application_id = a.id AND d.decision = 'info_requested')
 WHERE a.status = 'Draft' AND a.sent_back_at IS NULL
   AND EXISTS (SELECT 1 FROM membership_decisions d
                WHERE d.application_id = a.id AND d.decision = 'info_requested');

-- Check: SELECT COUNT(*) FROM applications WHERE sent_back_at IS NOT NULL;
