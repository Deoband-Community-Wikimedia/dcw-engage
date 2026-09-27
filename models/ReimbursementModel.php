<?php
require_once __DIR__ . '/../includes/crypto.php';

/**
 * DCW Engage - Reimbursement Requests
 *
 * Handles the applicant-facing submission (eligibility, server-computed
 * total, encrypted bank details) and the two-stage approval flow:
 *   - an ADMIN decides whether the claim is valid (Approved for Payment / Rejected)
 *   - FINANCE, a separate role, decides whether the transfer actually went
 *     through (Paid / Payment Failed) — see listForFinanceQueue(), which
 *     deliberately excludes line items and receipts. Finance needs to know
 *     how much and where to send it, not what was bought.
 */
class ReimbursementModel {
    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    // ------------------------------------------------------------------
    // Eligibility
    // ------------------------------------------------------------------

    /**
     * Eligible if either: accepted into the underlying event's application
     * process, or an admin explicitly added this email to this
     * reimbursement form's eligibility list.
     */
    public function isEligible($reimbursementFormId, $formId, $email) {
        $email = strtolower(trim($email));

        $stmt = $this->db->prepare(
            "SELECT 1 FROM applications WHERE form_id = :form_id AND email = :email AND status = 'Accepted'"
        );
        $stmt->execute(['form_id' => $formId, 'email' => $email]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        $stmt = $this->db->prepare(
            "SELECT 1 FROM reimbursement_eligibility WHERE reimbursement_form_id = :rid AND email = :email"
        );
        $stmt->execute(['rid' => $reimbursementFormId, 'email' => $email]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * True if this email already has a request that isn't Rejected. A
     * Rejected request doesn't block resubmission; anything else
     * (Submitted through Paid) does, since letting someone file a second
     * claim while a first is still live or already paid is exactly the
     * duplicate-payment risk this guards against.
     */
    public function hasOpenOrPaidRequest($reimbursementFormId, $email) {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM reimbursement_requests
             WHERE reimbursement_form_id = :rid AND email = :email AND status != 'Rejected'"
        );
        $stmt->execute(['rid' => $reimbursementFormId, 'email' => strtolower(trim($email))]);
        return (bool) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Submission
    // ------------------------------------------------------------------

    /**
     * Create a request with its line items in one transaction.
     *
     * $lineItems: array of ['category' => ..., 'description' => ..., 'amount_paise' => int, 'receipt_path' => string]
     * $payment: ['method' => 'cash'|'upi'|'bank', 'upi_id' => ?, 'bank_account_name' => ?, 'bank_account_number' => ?, 'bank_ifsc' => ?]
     *
     * The total is summed HERE from the line items, never taken from a
     * client-supplied total field. The cash-threshold check is re-verified
     * here too — the UI hides the cash option client-side once the running
     * total crosses the threshold, but that's a UX hint, not enforcement.
     *
     * @throws \InvalidArgumentException on any validation failure
     * @return array ['id' => int, 'tracking_id' => string]
     */
    public function createRequest($reimbursementForm, $formId, $email, $applicantName, array $payment, array $lineItems) {
        if (empty($lineItems)) {
            throw new \InvalidArgumentException('At least one expense line item is required.');
        }

        $totalPaise = 0;
        foreach ($lineItems as $item) {
            if (empty($item['category']) || empty($item['description']) || empty($item['receipt_path'])) {
                throw new \InvalidArgumentException('Every expense needs a category, description, and receipt.');
            }
            $amount = (int) $item['amount_paise'];
            if ($amount <= 0) {
                throw new \InvalidArgumentException('Every expense amount must be greater than zero.');
            }
            $totalPaise += $amount;
        }

        $method = $payment['method'] ?? '';
        if (!in_array($method, ['cash', 'upi', 'bank'], true)) {
            throw new \InvalidArgumentException('Please select a payment method.');
        }

        if ($method === 'cash' && $totalPaise > (int) $reimbursementForm['cash_threshold_paise']) {
            $rupees = number_format($reimbursementForm['cash_threshold_paise'] / 100, 2);
            throw new \InvalidArgumentException("Cash isn't available above ₹$rupees. Please choose UPI or bank transfer.");
        }

        $upiId = null;
        $bankAccountName = null;
        $bankAccountNumberEnc = null;
        $bankIfsc = null;

        if ($method === 'upi') {
            $upiId = trim($payment['upi_id'] ?? '');
            if (!preg_match('/^[\w.\-]{2,49}@[a-zA-Z]{2,64}$/', $upiId)) {
                throw new \InvalidArgumentException('Please enter a valid UPI ID (e.g. name@bank).');
            }
        } elseif ($method === 'bank') {
            $bankAccountName = trim($payment['bank_account_name'] ?? '');
            $accountNumber = trim($payment['bank_account_number'] ?? '');
            $accountNumberConfirm = trim($payment['bank_account_number_confirm'] ?? '');
            $bankIfsc = strtoupper(trim($payment['bank_ifsc'] ?? ''));

            if ($bankAccountName === '') {
                throw new \InvalidArgumentException('Account holder name is required.');
            }
            if (!preg_match('/^\d{6,20}$/', $accountNumber)) {
                throw new \InvalidArgumentException('Please enter a valid account number.');
            }
            if ($accountNumber !== $accountNumberConfirm) {
                throw new \InvalidArgumentException('Account number and confirmation do not match.');
            }
            if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $bankIfsc)) {
                throw new \InvalidArgumentException('Please enter a valid IFSC code.');
            }

            $bankAccountNumberEnc = Crypto::encrypt($accountNumber);
        }

        $trackingId = $this->generateTrackingId();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO reimbursement_requests
                    (reimbursement_form_id, email, applicant_name, total_amount_paise,
                     payment_method, upi_id, bank_account_name, bank_account_number_enc, bank_ifsc,
                     tracking_id)
                 VALUES
                    (:rid, :email, :name, :total,
                     :method, :upi_id, :bank_name, :bank_acct_enc, :bank_ifsc,
                     :tracking_id)"
            );
            $stmt->execute([
                'rid'           => $reimbursementForm['id'],
                'email'         => strtolower(trim($email)),
                'name'          => $applicantName,
                'total'         => $totalPaise,
                'method'        => $method,
                'upi_id'        => $upiId,
                'bank_name'     => $bankAccountName,
                'bank_acct_enc' => $bankAccountNumberEnc,
                'bank_ifsc'     => $bankIfsc,
                'tracking_id'   => $trackingId,
            ]);
            $requestId = (int) $this->db->lastInsertId();

            $itemStmt = $this->db->prepare(
                "INSERT INTO reimbursement_line_items (request_id, category, description, amount_paise, receipt_path)
                 VALUES (:request_id, :category, :description, :amount, :receipt_path)"
            );
            foreach ($lineItems as $item) {
                $itemStmt->execute([
                    'request_id'   => $requestId,
                    'category'     => $item['category'],
                    'description'  => $item['description'],
                    'amount'       => (int) $item['amount_paise'],
                    'receipt_path' => $item['receipt_path'],
                ]);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['id' => $requestId, 'tracking_id' => $trackingId, 'total_paise' => $totalPaise];
    }

    private function generateTrackingId() {
        // Retry on the rare collision rather than trusting randomness alone.
        for ($i = 0; $i < 5; $i++) {
            $candidate = 'RB-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $this->db->prepare("SELECT 1 FROM reimbursement_requests WHERE tracking_id = :t");
            $stmt->execute(['t' => $candidate]);
            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Could not generate a unique tracking ID after 5 attempts.');
    }

    // ------------------------------------------------------------------
    // Admin: substance review (is the claim valid?)
    // ------------------------------------------------------------------

    /**
     * Full detail for admin review: line items and receipts included,
     * bank account number NOT decrypted here — admin is judging the claim,
     * not executing payment, so plaintext account numbers have no reason
     * to pass through this view at all.
     */
    /**
     * Full detail for admin/organizer substance review: line items and
     * receipts included, but NO payment-execution fields at all —
     * payment_method, upi_id, bank_account_name, bank_ifsc,
     * bank_account_number_enc, payment_reference, paid_by, paid_at are all
     * deliberately absent from this SELECT, not just hidden in the markup.
     * "Nobody except finance sees payment details" has to be enforced at
     * the query level: a column that's fetched but merely not printed can
     * still leak through a future template change, a var_dump left in by
     * mistake, or an error trace. An admin/organizer judging whether a
     * claim is legitimate needs the amount and the receipts, not how or
     * where the money will be sent.
     */
    public function listForAdminReview($reimbursementFormId, $status = null) {
        $sql = "SELECT r.id, r.reimbursement_form_id, r.email, r.applicant_name,
                       r.total_amount_paise, r.status, r.tracking_id,
                       r.admin_notes, r.decided_by, r.decided_at,
                       r.payment_notes, r.created_at
                FROM reimbursement_requests r
                WHERE r.reimbursement_form_id = :rid";
        $params = ['rid' => $reimbursementFormId];

        if ($status !== null) {
            $sql .= " AND r.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY r.created_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll();

        $eventTitle = $this->getEventTitle($reimbursementFormId);

        foreach ($requests as &$req) {
            $req['line_items'] = $this->getLineItems($req['id']);
            $req['event_title'] = $eventTitle;
        }

        return $requests;
    }

    private function getLineItems($requestId) {
        $stmt = $this->db->prepare(
            "SELECT category, description, amount_paise, receipt_path
             FROM reimbursement_line_items WHERE request_id = :rid ORDER BY id ASC"
        );
        $stmt->execute(['rid' => $requestId]);
        return $stmt->fetchAll();
    }

    /**
     * The event title lives in forms.schema (JSON), not on the reimbursement
     * tables themselves — reused here rather than denormalized onto every
     * request row, since it's only needed for the handful of emails sent
     * around admin/finance decisions, not for every read of a request.
     */
    private function getEventTitle($reimbursementFormId) {
        $stmt = $this->db->prepare(
            "SELECT f.schema, f.form_type FROM forms f
             JOIN reimbursement_forms rf ON rf.form_id = f.id
             WHERE rf.id = :rid"
        );
        $stmt->execute(['rid' => $reimbursementFormId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $schema = json_decode($row['schema'], true) ?: [];
        return $schema['title'] ?? $row['form_type'];
    }

    public function approveForPayment($requestId, $adminIdentifier) {
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_requests
             SET status = 'Approved for Payment', decided_by = :who, decided_at = NOW()
             WHERE id = :id AND status IN ('Submitted', 'Under Review', 'Payment Failed')"
        );
        $stmt->execute(['who' => $adminIdentifier, 'id' => $requestId]);
        return $stmt->rowCount() === 1;
    }

    public function reject($requestId, $adminIdentifier, $notes) {
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_requests
             SET status = 'Rejected', decided_by = :who, decided_at = NOW(), admin_notes = :notes
             WHERE id = :id AND status IN ('Submitted', 'Under Review', 'Payment Failed')"
        );
        $stmt->execute(['who' => $adminIdentifier, 'notes' => $notes, 'id' => $requestId]);
        return $stmt->rowCount() === 1;
    }

    // ------------------------------------------------------------------
    // Finance: payment execution (did the transfer go through?)
    // ------------------------------------------------------------------

    /**
     * Deliberately narrow: payment details only, no line items, no
     * receipts, no expense descriptions. Finance needs to know how much
     * and where to send it, not what it was for. Bank account numbers are
     * decrypted only here, only for rows finance is actively meant to pay.
     */
    public function listForFinanceQueue() {
        $stmt = $this->db->query(
            "SELECT id, reimbursement_form_id, tracking_id, applicant_name, email, total_amount_paise,
                    payment_method, upi_id, bank_account_name, bank_account_number_enc, bank_ifsc,
                    decided_by, decided_at
             FROM reimbursement_requests
             WHERE status = 'Approved for Payment'
             ORDER BY decided_at ASC"
        );
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            if ($row['bank_account_number_enc'] !== null) {
                $row['bank_account_number'] = Crypto::decrypt($row['bank_account_number_enc']);
            }
            unset($row['bank_account_number_enc']);
            $row['event_title'] = $this->getEventTitle($row['reimbursement_form_id']);
        }

        return $rows;
    }

    /**
     * Fetch a single request with its email/name/tracking/event-title,
     * for use right after markPaid()/markPaymentFailed() to send the
     * corresponding notification without the caller needing to reconstruct
     * that context itself.
     */
    public function getForNotification($requestId) {
        $stmt = $this->db->prepare(
            "SELECT id, reimbursement_form_id, tracking_id, applicant_name, email, status
             FROM reimbursement_requests WHERE id = :id"
        );
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch();
        if ($row) {
            $row['event_title'] = $this->getEventTitle($row['reimbursement_form_id']);
        }
        return $row ?: null;
    }

    public function markPaid($requestId, $financeIdentifier, $paymentReference) {
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_requests
             SET status = 'Paid', paid_by = :who, paid_at = NOW(), payment_reference = :ref
             WHERE id = :id AND status = 'Approved for Payment'"
        );
        $stmt->execute(['who' => $financeIdentifier, 'ref' => $paymentReference, 'id' => $requestId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * The transfer itself failed (bad account number, bounced UPI, etc).
     * This is a finance signal, not a rejection of the claim's substance —
     * it goes back to an admin to fix payment details or otherwise
     * resolve, NOT back to the applicant.
     */
    public function markPaymentFailed($requestId, $financeIdentifier, $notes) {
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_requests
             SET status = 'Payment Failed', paid_by = :who, paid_at = NOW(), payment_notes = :notes
             WHERE id = :id AND status = 'Approved for Payment'"
        );
        $stmt->execute(['who' => $financeIdentifier, 'notes' => $notes, 'id' => $requestId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * For an APPLICANT looking up their own request by tracking ID — this
     * is the one place payment fields are legitimately returned in full,
     * since it's the applicant's own data. Never call this from an
     * admin/organizer/finance-facing view; those go through
     * listForAdminReview() or listForFinanceQueue() instead, which are
     * scoped to what each role should see.
     */
    public function getByTrackingId($trackingId) {
        $stmt = $this->db->prepare("SELECT * FROM reimbursement_requests WHERE tracking_id = :t");
        $stmt->execute(['t' => $trackingId]);
        $row = $stmt->fetch();
        if ($row) {
            unset($row['bank_account_number_enc']);
            $row['line_items'] = $this->getLineItems($row['id']);
        }
        return $row ?: null;
    }
}
