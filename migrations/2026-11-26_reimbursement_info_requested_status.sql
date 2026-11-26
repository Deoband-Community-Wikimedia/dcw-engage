-- Allow the "Info Requested" status on reimbursement claims.
--
-- Without this value, MySQL in non-strict mode silently stores a blank status ('')
-- when a reviewer sends a claim back, so the member sees "In review" and no edit form.
--
-- The new value is appended at the end, so the existing values keep their positions
-- and no existing rows change.

ALTER TABLE reimbursement_requests
  MODIFY status ENUM(
    'Submitted',
    'Under Review',
    'Rejected',
    'Approved for Payment',
    'Paid',
    'Payment Failed',
    'Discarded',
    'Info Requested'
  ) NOT NULL DEFAULT 'Submitted';
