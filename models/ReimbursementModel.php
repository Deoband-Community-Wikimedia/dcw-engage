<?php
require_once __DIR__ . '/../includes/crypto.php';

/**
 * DCW Engage - Reimbursement Requests
 *
 * Handles the applicant-facing submission (eligibility, server-computed
 * total, encrypted bank details) and the two-stage approval flow:
 *   - an ADMIN/ORGANIZER decides whether the claim is valid (Approved for
 *     Payment / Rejected / Discarded) — see reject() vs discard() below
 *   - FINANCE (which includes owners), a separate role, decides whether the
 *     transfer actually went through (Paid / Payment Failed) — see
 *     listForFinanceQueue(), which deliberately excludes line items and
 *     receipts. Finance needs to know how much and where to send it, not
 *     what was bought.
 *
 * One global form now, not one per event: the applicant types the event
 * name themselves (event_name, free text) rather than this being scoped to
 * a specific row in `forms`. That's why nothing here takes a $formId or
 * $reimbursementFormId anymore, and why there's no join back to `forms` —
 * event_name lives directly on reimbursement_requests.
 *
 * isEligible() is no longer a submission gate — any verified email can
 * submit a request now. It stays here as an informational signal for the
 * admin/organizer review queue (see listForAdminReview()'s use of it in
 * reimbursement_review.php), so a reviewer can see at a glance whether a
 * request came from someone with a prior accepted application or an
 * explicit allowlist entry, versus someone with neither — the latter is
 * exactly the case discard() exists for.
 */
class ReimbursementModel {
    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    // ------------------------------------------------------------------
    // Eligibility (informational only — see class docblock)
    // ------------------------------------------------------------------

    /**
     * True if either: accepted into ANY event's application process
     * (status = 'Accepted' on any form), or an owner/organizer explicitly
     * added this email to the global eligibility list. No longer used to
     * block submission — see class docblock — but still useful context for
     * whoever is reviewing the claim.
     */
    public function isEligible($email) {
        $email = strtolower(trim($email));

        $stmt = $this->db->prepare(
            "SELECT 1 FROM applications WHERE email = :email AND status = 'Accepted'"
        );
        $stmt->execute(['email' => $email]);
        if ($stmt->fetchColumn()) {
            return true;
        }

        $stmt = $this->db->prepare(
            "SELECT 1 FROM reimbursement_global_eligibility WHERE email = :email"
        );
        $stmt->execute(['email' => $email]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * True if this email already has a non-Rejected, non-Discarded request
     * for THIS event name specifically. Scoped per (email, event_name)
     * rather than globally per email, since the same person can legitimately
     * claim reimbursement for two different events they attended — the
     * duplicate-payment risk this guards against is filing twice for the
     * *same* event, not ever submitting more than once at all.
     */
    public function hasOpenOrPaidRequest($email, $eventName) {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM reimbursement_requests
             WHERE email = :email AND event_name = :event_name
               AND status NOT IN ('Rejected', 'Discarded')"
        );
        $stmt->execute(['email' => strtolower(trim($email)), 'event_name' => trim($eventName)]);
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
     * No eligibility check happens here — any verified email may submit.
     * A reviewer sorts out legitimacy afterwards via approveForPayment(),
     * reject(), or discard().
     *
     * @param array $settings The single row from ReimbursementSettingsModel::get()
     * @throws \InvalidArgumentException on any validation failure
     * @return array ['id' => int, 'tracking_id' => string, 'total_paise' => int]
     */
    public function createRequest($settings, $email, $applicantName, $eventName, array $payment, array $lineItems) {
        $eventName = trim($eventName);
        if ($eventName === '') {
            throw new \InvalidArgumentException('Please enter the name of the event.');
        }
        if (mb_strlen($eventName) > 255) {
            throw new \InvalidArgumentException('Event name is too long (255 characters max).');
        }

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

        if ($method === 'cash' && $totalPaise > (int) $settings['cash_threshold_paise']) {
            $rupees = number_format($settings['cash_threshold_paise'] / 100, 2);
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
                    (email, applicant_name, event_name, total_amount_paise,
                     payment_method, upi_id, bank_account_name, bank_account_number_enc, bank_ifsc,
                     tracking_id)
                 VALUES
                    (:email, :name, :event_name, :total,
                     :method, :upi_id, :bank_name, :bank_acct_enc, :bank_ifsc,
                     :tracking_id)"
            );
            $stmt->execute([
                'email'         => strtolower(trim($email)),
                'name'          => $applicantName,
                'event_name'    => $eventName,
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
    // Admin/organizer: substance review (is the claim valid?)
    // ------------------------------------------------------------------

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
     * claim is legitimate needs the amount, the event name, and the
     * receipts — not how or where the money will be sent.
     *
     * Global now, not scoped to one event's config — one form covers every
     * event, so this lists every request across all of them, and
     * event_name (typed by the applicant) is just another column.
     */
    public function listForAdminReview($status = null) {
        $sql = "SELECT id, email, applicant_name, event_name,
                       total_amount_paise, status, tracking_id,
                       admin_notes, decided_by, decided_at,
                       payment_notes, created_at
                FROM reimbursement_requests";
        $params = [];

        if ($status !== null) {
            $sql .= " WHERE status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY created_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll();

        foreach ($requests as &$req) {
            $req['line_items'] = $this->getLineItems($req['id']);
            // Informational only (see isEligible()'s docblock) — helps a
            // reviewer spot the requests discard() exists for, but never
            // blocks anything itself.
            $req['previously_eligible'] = $this->isEligible($req['email']);
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

    public function approveForPayment($requestId, $adminIdentifier) {
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_requests
             SET status = 'Approved for Payment', decided_by = :who, decided_at = NOW()
             WHERE id = :id AND status IN ('Submitted', 'Under Review', 'Payment Failed')"
        );
        $stmt->execute(['who' => $adminIdentifier, 'id' => $requestId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * The claim was reviewed and found invalid on its substance (wrong
     * amount, missing receipt, expense not covered, etc). The applicant
     * DID plausibly belong here, so they're notified — the caller
     * (reimbursement_review.php) sends the rejection email after this
     * returns true.
     */
    public function reject($requestId, $adminIdentifier, $notes) {
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_requests
             SET status = 'Rejected', decided_by = :who, decided_at = NOW(), admin_notes = :notes
             WHERE id = :id AND status IN ('Submitted', 'Under Review', 'Payment Failed')"
        );
        $stmt->execute(['who' => $adminIdentifier, 'notes' => $notes, 'id' => $requestId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * The request never should have counted as a real claim in the first
     * place — spam, a duplicate, someone with no connection to any event
     * and no plausible reason to be submitting. Unlike reject(), this is
     * silent: the caller must NOT send the applicant any notification.
     * $notes is optional internal context for the audit log, not something
     * ever shown to the applicant.
     */
    public function discard($requestId, $adminIdentifier, $notes = null) {
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_requests
             SET status = 'Discarded', decided_by = :who, decided_at = NOW(), admin_notes = :notes
             WHERE id = :id AND status IN ('Submitted', 'Under Review', 'Payment Failed')"
        );
        $stmt->execute(['who' => $adminIdentifier, 'notes' => $notes, 'id' => $requestId]);
        return $stmt->rowCount() === 1;
    }

    // ------------------------------------------------------------------
    // Finance (includes owners): payment execution (did the transfer go through?)
    // ------------------------------------------------------------------

    /**
     * Deliberately narrow: payment details only, no line items, no
     * receipts, no expense descriptions. Finance needs to know how much
     * and where to send it, not what it was for. Bank account numbers are
     * decrypted only here, only for rows finance is actively meant to pay.
     */
    public function listForFinanceQueue() {
        $stmt = $this->db->query(
            "SELECT id, tracking_id, applicant_name, email, event_name, total_amount_paise,
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
        }

        return $rows;
    }

    /**
     * Fetch a single request with its email/name/tracking/event-name, for
     * use right after markPaid()/markPaymentFailed() to send the
     * corresponding notification without the caller needing to reconstruct
     * that context itself.
     */
    public function getForNotification($requestId) {
        $stmt = $this->db->prepare(
            "SELECT id, tracking_id, applicant_name, email, event_name, status
             FROM reimbursement_requests WHERE id = :id"
        );
        $stmt->execute(['id' => $requestId]);
        return $stmt->fetch() ?: null;
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
     * it goes back to an admin/organizer to fix payment details or
     * otherwise resolve, NOT back to the applicant.
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
