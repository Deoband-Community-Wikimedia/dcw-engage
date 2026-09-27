-- Reimbursements: one config per event (forms.id), applicant-submitted
-- requests with line items and receipts, and a two-role approval flow
-- (admin decides whether to pay, finance decides how/when it's paid).

CREATE TABLE reimbursement_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,                              -- the event this config belongs to
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    cash_threshold_paise INT NOT NULL DEFAULT 80000,   -- ₹800; integer paise avoids float rounding
    expense_categories JSON NOT NULL,                  -- e.g. ["Travel","Accommodation","Food","Other"]
    instructions TEXT NULL,                            -- admin-authored note shown above the applicant form
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    UNIQUE (form_id)
);

-- Admin-added eligibility for people who should qualify without an
-- 'Accepted' application row (volunteers, speakers, staff, etc).
CREATE TABLE reimbursement_eligibility (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reimbursement_form_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    added_by VARCHAR(255) NOT NULL,                     -- admin identifier, for audit
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reimbursement_form_id) REFERENCES reimbursement_forms(id) ON DELETE CASCADE,
    UNIQUE (reimbursement_form_id, email)
);

CREATE TABLE reimbursement_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reimbursement_form_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    applicant_name VARCHAR(255) NOT NULL,
    total_amount_paise INT NOT NULL,                    -- computed server-side from line items, never trusted from client

    payment_method ENUM('cash','upi','bank') NOT NULL,
    upi_id VARCHAR(100) NULL,
    bank_account_name VARCHAR(255) NULL,
    bank_account_number_enc VARBINARY(512) NULL,        -- sodium_crypto_secretbox ciphertext; see includes/crypto.php
    bank_ifsc VARCHAR(11) NULL,

    -- Submitted -> Under Review -> Rejected
    --                            -> Approved for Payment -> Paid
    --                                                     -> Payment Failed (kicked back by finance)
    status ENUM(
        'Submitted', 'Under Review', 'Rejected',
        'Approved for Payment', 'Paid', 'Payment Failed'
    ) NOT NULL DEFAULT 'Submitted',

    tracking_id VARCHAR(20) NOT NULL,

    -- Substance decision: made by an admin, about whether the claim is valid.
    admin_notes TEXT NULL,
    decided_by VARCHAR(255) NULL,
    decided_at DATETIME NULL,

    -- Payment execution: made by finance, about whether the transfer went through.
    payment_notes TEXT NULL,
    payment_reference VARCHAR(255) NULL,                -- UTR / transaction ID for finance's own reconciliation
    paid_by VARCHAR(255) NULL,
    paid_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (reimbursement_form_id) REFERENCES reimbursement_forms(id) ON DELETE CASCADE,
    UNIQUE (tracking_id)

    -- Deliberately NOT a UNIQUE(reimbursement_form_id, email) constraint:
    -- a Rejected request should be resubmittable, so uniqueness is enforced
    -- in application code (ReimbursementModel::hasOpenOrPaidRequest) against
    -- any row not in ('Rejected') rather than at the DB level.
);

CREATE INDEX idx_reimb_requests_form_email ON reimbursement_requests (reimbursement_form_id, email);
CREATE INDEX idx_reimb_requests_status ON reimbursement_requests (reimbursement_form_id, status);

CREATE TABLE reimbursement_line_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    category VARCHAR(100) NOT NULL,
    description VARCHAR(500) NOT NULL,
    amount_paise INT NOT NULL,
    receipt_path VARCHAR(500) NOT NULL,                 -- reuses FileUploader; a receipt is mandatory per line item
    FOREIGN KEY (request_id) REFERENCES reimbursement_requests(id) ON DELETE CASCADE
);

-- NOTE: no ALTER TABLE admin_users needed. Your real InviteModel.php shows
-- admin_users.role is a plain VARCHAR column with validity enforced in
-- application code (InviteModel::create()'s whitelist), not a DB-level ENUM.
-- Adding 'finance' as a third role was therefore a one-line change to that
-- whitelist, not a schema migration — see models/InviteModel.php.
