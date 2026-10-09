-- DCW Engage reads this view, and nothing else, from the certificates database.
-- Run it in phpMyAdmin on the CERTIFICATES database. Re-run it after any portal schema change.
-- Multi-organisation later: add columns (org_name, base_url) and an organisation filter here.

CREATE OR REPLACE VIEW member_certificates_v AS
SELECT
    ep.certificate_id                                                  AS certificate_id,
    LOWER(p.email)                                                     AS email,
    e.name                                                             AS event_name,
    er.role_name                                                       AS role_name,
    COALESCE(ep.issue_date, e.certificate_issue_date, ep.created_at)   AS issued_at
FROM event_participants ep
JOIN participants p       ON p.id  = ep.participant_id
JOIN events e             ON e.id  = ep.event_id
LEFT JOIN event_roles er  ON er.id = ep.role_id
WHERE ep.certificate_id IS NOT NULL
  AND ep.certificate_id <> '';
