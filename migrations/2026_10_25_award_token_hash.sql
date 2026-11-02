-- Award confirmation links: store only a hash (#182).
--
-- Until now award_confirmations.token held each awardee's private link in plain text. From now on only
-- sha256 hashes are stored:
--   token_hash      hash of the link the app sends from now on (an HMAC of the application id, keyed with
--                   security.otp_secret, so reminders can rebuild the same link; see AwardConfirmationModel)
--   old_token_hash  hash of a link that was emailed before this change, so those emails keep working
--
-- Run in TWO steps so the live site works at every moment:
--   STEP 1  before deploying the new code (the old code keeps working: `token` is still there)
--   STEP 2  after the new code is live (it no longer reads `token`)
-- Take a backup of award_confirmations first.

-- ---- STEP 1: before the deploy -------------------------------------------------------------------
ALTER TABLE award_confirmations
    ADD COLUMN token_hash     CHAR(64) NULL AFTER token,
    ADD COLUMN old_token_hash CHAR(64) NULL AFTER token_hash,
    MODIFY token CHAR(64) NULL,
    ADD UNIQUE KEY uniq_award_token_hash (token_hash),
    ADD KEY idx_award_old_token_hash (old_token_hash);

UPDATE award_confirmations SET old_token_hash = SHA2(token, 256) WHERE token IS NOT NULL;

-- ---- STEP 2: after the new code is live ---------------------------------------------------------
-- First keep any link the OLD code created between step 1 and the deploy, then remove the plain-text
-- links. Every emailed link still works through old_token_hash.
-- UPDATE award_confirmations SET old_token_hash = SHA2(token, 256) WHERE token IS NOT NULL AND old_token_hash IS NULL;
-- ALTER TABLE award_confirmations DROP INDEX uniq_award_token, DROP COLUMN token;
