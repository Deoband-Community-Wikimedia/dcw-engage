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
 * AMOUNTS. Three figures can exist for one request:
 *   - claimed:  total_amount_paise, summed from the applicant's line items
 *   - approved: approved_amount_paise, set by the reviewer when approving
 *               (never above the claimed total). NULL means "approved as claimed"
 *               (see approvedAmountPaise()).
 *   - paid:     paid_amount_paise, set by finance when marking the request paid.
 *               NULL means "paid as approved" (older rows).
 * Whenever a figure differs from the one before it, a reason is stored
 * (approved_amount_note, paid_amount_note). Those reasons ARE shown to the
 * applicant; staff-only remarks live in internal_notes (InternalNoteModel).
 *
 * Send-back-as-draft: a reviewer may return a claim with a question
 * (requestInfo(): Submitted/Under Review -> Info Requested). On /track the
 * applicant then sees the question plus their own editable claim
 * (getDraftForApplicant()), corrects it, adds a note and resubmits in one
 * step (resubmitDraft(): Info Requested -> Submitted). Payment details,
 * expense categories and receipt files are never shown or changed from
 * there. While Info Requested, a claim can only be rejected or discarded;
 * it cannot be approved until it comes back.
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
    /** Claims above this total must be paid by bank transfer; at or below, UPI is the default. */
    public const UPI_MAX_PAISE = 80000; // ₹800

    /** A claim is only valid if filed within this many days of the event. */
    public const CLAIM_WINDOW_DAYS = 5;

    /** Bounds for reviewer questions and applicant replies (same as internet support). */
    public const MIN_MESSAGE_LENGTH = 5;
    public const MAX_MESSAGE_LENGTH = 1000;

    /** Cap for an expense description edited from /track. */
    public const MAX_ITEM_DESCRIPTION_LENGTH = 500;

    /** Longest reason a reviewer or finance may give for changing an amount. */
    public const MAX_AMOUNT_NOTE_LENGTH = 500;

    /** "Today" for the claim window is judged in India time, not server time. */
    private const CLAIM_TIMEZONE = 'Asia/Kolkata';

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
     * (receipt_path is optional — '' when the applicant didn't attach one)
     *
     * $eventDate: 'YYYY-MM-DD'. Must not be in the future, and the claim must
     * be filed within CLAIM_WINDOW_DAYS of it.
     * $payment: ['method' => 'upi'|'bank', 'upi_id' => ?, 'bank_account_name' => ?, 'bank_account_number' => ?, 'bank_ifsc' => ?]
     * Cash is not an option. UPI is the default up to UPI_MAX_PAISE;
     * above that, bank transfer is required.
     *
     * The total is summed HERE from the line items, never taken from a
     * client-supplied total field. The UPI cap is re-verified here too —
     * the form disables UPI client-side once the running total crosses
     * it, but that's a UX hint, not enforcement.
     *
     * No eligibility check happens here — any verified email may submit.
     * A reviewer sorts out legitimacy afterwards via approveForPayment(),
     * reject(), or discard().
     *
     * @param array $settings The single row from ReimbursementSettingsModel::get()
     * @throws \InvalidArgumentException on any validation failure
     * @return array ['id' => int, 'tracking_id' => string, 'total_paise' => int]
     */
    public function createRequest($settings, $email, $applicantName, $eventName, $eventDate, array $payment, array $lineItems) {
        $eventName = trim($eventName);
        if ($eventName === '') {
            throw new \InvalidArgumentException('Please enter the name of the event.');
        }
        if (mb_strlen($eventName) > 255) {
            throw new \InvalidArgumentException('Event name is too long (255 characters max).');
        }

        $eventDate = $this->validateEventDate($eventDate);

        if (empty($lineItems)) {
            throw new \InvalidArgumentException('At least one expense line item is required.');
        }

        $totalPaise = 0;
        foreach ($lineItems as $item) {
            if (empty($item['category']) || empty($item['description'])) {
                throw new \InvalidArgumentException('Every expense needs a category and a description.');
            }
            $amount = (int) $item['amount_paise'];
            if ($amount <= 0) {
                throw new \InvalidArgumentException('Every expense amount must be greater than zero.');
            }
            $totalPaise += $amount;
        }

        $method = $payment['method'] ?? '';
        if (!in_array($method, ['upi', 'bank'], true)) {
            throw new \InvalidArgumentException('Please select a payment method.');
        }

        // UPI is the default up to the cap; above it, bank transfer only.
        // The form disables UPI client-side once the total crosses this,
        // but this is the check that actually enforces it.
        if ($method === 'upi' && $totalPaise > self::UPI_MAX_PAISE) {
            $rupees = number_format(self::UPI_MAX_PAISE / 100);
            throw new \InvalidArgumentException("For claims above ₹$rupees, please provide bank account details instead of UPI.");
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
                    (email, applicant_name, event_name, event_date, total_amount_paise,
                     payment_method, upi_id, bank_account_name, bank_account_number_enc, bank_ifsc,
                     tracking_id)
                 VALUES
                    (:email, :name, :event_name, :event_date, :total,
                     :method, :upi_id, :bank_name, :bank_acct_enc, :bank_ifsc,
                     :tracking_id)"
            );
            $stmt->execute([
                'email'         => strtolower(trim($email)),
                'name'          => $applicantName,
                'event_name'    => $eventName,
                'event_date'    => $eventDate,
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
                    'receipt_path' => (string) ($item['receipt_path'] ?? ''),
                ]);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['id' => $requestId, 'tracking_id' => $trackingId, 'total_paise' => $totalPaise];
    }

    /**
     * The event must have already happened, and the claim must be filed
     * within CLAIM_WINDOW_DAYS of it. Judged in India time so a claim filed
     * late in the evening isn't affected by the server's own time zone.
     * Returns the normalised 'YYYY-MM-DD' string.
     */
    private function validateEventDate($raw) {
        $raw = trim((string) $raw);
        $tz = new \DateTimeZone(self::CLAIM_TIMEZONE);

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $tz);
        if (!$date || $date->format('Y-m-d') !== $raw) {
            throw new \InvalidArgumentException('Please enter the date of the event.');
        }

        $today = new \DateTimeImmutable('today', $tz);
        if ($date > $today) {
            throw new \InvalidArgumentException('The event date cannot be in the future.');
        }

        if ((int) $date->diff($today)->days > self::CLAIM_WINDOW_DAYS) {
            throw new \InvalidArgumentException(
                'Reimbursement requests must be submitted within ' . self::CLAIM_WINDOW_DAYS . ' days of the event.'
            );
        }

        return $raw;
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
    // Amounts
    // ------------------------------------------------------------------

    /** What the applicant claimed (the sum of their line items). */
    public function claimedAmountPaise($requestId) {
        $stmt = $this->db->prepare("SELECT total_amount_paise FROM reimbursement_requests WHERE id = :id");
        $stmt->execute(['id' => (int) $requestId]);
        return (int) $stmt->fetchColumn();
    }

    /** What the reviewer approved: the claimed total unless they changed it. */
    public function approvedAmountPaise($requestId) {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(approved_amount_paise, total_amount_paise)
             FROM reimbursement_requests WHERE id = :id"
        );
        $stmt->execute(['id' => (int) $requestId]);
        return (int) $stmt->fetchColumn();
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
     *
     * Each request also carries its reviewer <-> applicant conversation
     * under 'messages' (see getMessagesForReview()).
     *
     * approved_amount_paise is NULL until a reviewer has approved; for a
     * 'Payment Failed' request it holds the amount approved the first time.
     */
    public function listForAdminReview($status = null) {
        $sql = "SELECT id, email, applicant_name, event_name, event_date,
                       total_amount_paise, approved_amount_paise, status, tracking_id,
                       sent_back_note, sent_back_at,
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
            $req['messages'] = $this->getMessagesForReview($req['id']);
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

    /**
     * Approve the claim for $amountPaise (null = approve the full claimed total).
     * $amountNote is the reason the amount differs from the claim; the
     * applicant sees it. Pass '' when the amount is unchanged. The caller is
     * responsible for checking $amountPaise is positive and not above the claim.
     */
    public function approveForPayment($requestId, $adminIdentifier, $amountPaise = null, $amountNote = '') {
        $amountNote = trim((string) $amountNote);
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_requests
             SET status = 'Approved for Payment', decided_by = :who, decided_at = NOW(),
                 approved_amount_paise = :amt, approved_amount_note = :note,
                 sent_back_by = NULL, sent_back_at = NULL, sent_back_note = NULL
             WHERE id = :id AND status IN ('Submitted', 'Under Review', 'Payment Failed')"
        );
        $stmt->execute([
            'who'  => $adminIdentifier,
            'amt'  => $amountPaise !== null ? (int) $amountPaise : null,
            'note' => $amountNote !== '' ? mb_substr($amountNote, 0, self::MAX_AMOUNT_NOTE_LENGTH) : null,
            'id'   => $requestId,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Finance sends an approved claim BACK to the reviewers (for example an
     * accidental approval, or a claim finance has doubts about). Moves
     * 'Approved for Payment' -> 'Submitted', so it lands in the reviewers'
     * "Awaiting review" list as if new, where they can approve, ask the
     * applicant for information, reject or discard it again.
     *
     * The earlier approval is wiped (approved amount and its reason), so the
     * reviewer decides afresh from the claimed total. $note is shown to the
     * reviewers only. NO applicant email: like 'Payment Failed', this is
     * finance-to-reviewer plumbing, and on the member side the request simply
     * reads "In review" again. Returns false if the claim is no longer
     * 'Approved for Payment' (someone else got there first).
     */
    public function sendBackToReview($requestId, $financeIdentifier, $note) {
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_requests
             SET status = 'Submitted',
                 approved_amount_paise = NULL, approved_amount_note = NULL,
                 sent_back_by = :who, sent_back_at = NOW(), sent_back_note = :note
             WHERE id = :id AND status = 'Approved for Payment'"
        );
        $stmt->execute([
            'who'  => $financeIdentifier,
            'note' => mb_substr(trim((string) $note), 0, 1000),
            'id'   => (int) $requestId,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * The claim was reviewed and found invalid on its substance (wrong
     * amount, missing receipt, expense not covered, etc). The applicant
     * DID plausibly belong here, so they're notified — the caller
     * (reimbursement_review.php) sends the rejection email after this
     * returns true. Also allowed while 'Info Requested', for an applicant
     * who never replies.
     */
    public function reject($requestId, $adminIdentifier, $notes) {
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_requests
             SET status = 'Rejected', decided_by = :who, decided_at = NOW(), admin_notes = :notes
             WHERE id = :id AND status IN ('Submitted', 'Under Review', 'Payment Failed', 'Info Requested')"
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
     *
     * Because this is meant to be invisible to the applicant, it is also
     * excluded from getStatusForApplicant() below — a discarded request
     * looks identical to "no record found" from the tracking page, the
     * same as it looks identical to "no notification sent" from their
     * inbox. Silent means silent everywhere, not just in email.
     */
    public function discard($requestId, $adminIdentifier, $notes = null) {
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_requests
             SET status = 'Discarded', decided_by = :who, decided_at = NOW(), admin_notes = :notes
             WHERE id = :id AND status IN ('Submitted', 'Under Review', 'Payment Failed', 'Info Requested')"
        );
        $stmt->execute(['who' => $adminIdentifier, 'notes' => $notes, 'id' => $requestId]);
        return $stmt->rowCount() === 1;
    }

    // ------------------------------------------------------------------
    // Reviewer <-> applicant messages ("Info Requested")
    //
    // The applicant only ever sees "DCW reviewer". The reviewer's identifier
    // is stored for audit and is deliberately NOT in any applicant-facing
    // SELECT.
    // ------------------------------------------------------------------

    private function cleanMessage($message) {
        $message = trim((string) $message);
        $len = mb_strlen($message);
        if ($len < self::MIN_MESSAGE_LENGTH) {
            throw new \InvalidArgumentException('Please write a little more.');
        }
        if ($len > self::MAX_MESSAGE_LENGTH) {
            throw new \InvalidArgumentException('Message is too long (' . self::MAX_MESSAGE_LENGTH . ' characters max).');
        }
        return $message;
    }

    private function insertMessage($requestId, $sender, $author, $body) {
        $stmt = $this->db->prepare(
            "INSERT INTO reimbursement_request_messages (request_id, sender, author, body)
             VALUES (:rid, :sender, :author, :body)"
        );
        $stmt->execute([
            'rid'    => (int) $requestId,
            'sender' => $sender,
            'author' => $author,
            'body'   => $body,
        ]);
    }

    /** "250" or "250.50" -> paise, or null if it isn't a positive amount. */
    public static function rupeesToPaise($raw) {
        $raw = trim((string) $raw);
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $raw)) {
            return null;
        }
        $paise = (int) round(((float) $raw) * 100);
        return $paise > 0 ? $paise : null;
    }

    /**
     * Reviewer needs more input. Moves Submitted/Under Review -> Info
     * Requested and stores the question in one transaction. Returns false if
     * someone else got there first. The caller should email the applicant a
     * "please check your request" nudge WITHOUT the message text.
     *
     * @throws \InvalidArgumentException if the message is too short or long
     */
    public function requestInfo($requestId, $reviewerIdentifier, $message) {
        $message = $this->cleanMessage($message);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE reimbursement_requests SET status = 'Info Requested'
                 WHERE id = :id AND status IN ('Submitted', 'Under Review')"
            );
            $stmt->execute(['id' => (int) $requestId]);
            if ($stmt->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $this->insertMessage($requestId, 'reviewer', $reviewerIdentifier, $message);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Full thread for the reviewer screen, oldest first. Includes who asked, for audit. */
    public function getMessagesForReview($requestId) {
        $stmt = $this->db->prepare(
            "SELECT sender, author, body, created_at
             FROM reimbursement_request_messages
             WHERE request_id = :id
             ORDER BY id ASC"
        );
        $stmt->execute(['id' => (int) $requestId]);
        return $stmt->fetchAll();
    }

    /** Thread for /track. No author column: reviewers stay anonymous. Discarded looks like "no record". */
    public function getMessagesForApplicant($trackingId, $email) {
        $stmt = $this->db->prepare(
            "SELECT m.sender, m.body, m.created_at
             FROM reimbursement_request_messages m
             JOIN reimbursement_requests r ON r.id = m.request_id
             WHERE r.tracking_id = :t AND r.email = :email AND r.status <> 'Discarded'
             ORDER BY m.id ASC"
        );
        $stmt->execute(['t' => $trackingId, 'email' => strtolower(trim($email))]);
        return $stmt->fetchAll();
    }

    /**
     * The applicant's own editable claim, for the draft form on /track. Only
     * returned while 'Info Requested' and the tracking ID + email pair
     * matches. Event name/date and each expense's category, description and
     * amount. No payment method, UPI/bank fields or receipt paths.
     */
    public function getDraftForApplicant($trackingId, $email) {
        $stmt = $this->db->prepare(
            "SELECT id, event_name, event_date
             FROM reimbursement_requests
             WHERE tracking_id = :t AND email = :email AND status = 'Info Requested'"
        );
        $stmt->execute(['t' => $trackingId, 'email' => strtolower(trim($email))]);
        $req = $stmt->fetch();
        if (!$req) {
            return null;
        }

        $items = $this->db->prepare(
            "SELECT id, category, description, amount_paise
             FROM reimbursement_line_items WHERE request_id = :rid ORDER BY id ASC"
        );
        $items->execute(['rid' => (int) $req['id']]);

        return [
            'event_name' => $req['event_name'],
            'event_date' => $req['event_date'],
            'items'      => $items->fetchAll(),
        ];
    }

    /**
     * A corrected event date must still be a real past date, and within the
     * claim window measured from when the claim was ORIGINALLY filed (the
     * window was met then; resubmitting later must not retroactively fail).
     */
    private function validateEditedEventDate($raw, $createdAt) {
        $raw = trim((string) $raw);
        $tz = new \DateTimeZone(self::CLAIM_TIMEZONE);

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $tz);
        if (!$date || $date->format('Y-m-d') !== $raw) {
            throw new \InvalidArgumentException('Please enter the date of the event.');
        }
        $today = new \DateTimeImmutable('today', $tz);
        if ($date > $today) {
            throw new \InvalidArgumentException('The event date cannot be in the future.');
        }
        $filed = (new \DateTimeImmutable((string) $createdAt, $tz))->setTime(0, 0);
        if ($date < $filed->modify('-' . self::CLAIM_WINDOW_DAYS . ' days')) {
            throw new \InvalidArgumentException(
                'The event date must be within ' . self::CLAIM_WINDOW_DAYS . ' days of when you first submitted this request.'
            );
        }
        return $raw;
    }

    /**
     * Applicant corrects the claim and answers the reviewer, via /track.
     * Saves the event name/date and each expense's description and amount,
     * recomputes the total HERE, stores the note (with a line listing what
     * changed, visible to the reviewer) and moves Info Requested -> Submitted,
     * all in one transaction. Returns false if the pair doesn't match or the
     * request is no longer waiting on the applicant.
     *
     * Not editable here: payment method/details, expense categories, receipt
     * files and the number of expenses. If the new total would exceed the UPI
     * cap on a UPI claim, it is refused (the payment method can't be changed
     * from this page).
     *
     * @param array $items id => ['description' => string, 'amount_paise' => int]
     *                     (every existing expense must be present)
     * @throws \InvalidArgumentException on any validation failure
     */
    public function resubmitDraft($trackingId, $email, $eventName, $eventDate, array $items, $note) {
        $note = $this->cleanMessage($note);

        $eventName = trim(preg_replace('/\s+/', ' ', (string) $eventName));
        if ($eventName === '') {
            throw new \InvalidArgumentException('Please enter the name of the event.');
        }
        if (mb_strlen($eventName) > 255) {
            throw new \InvalidArgumentException('Event name is too long (255 characters max).');
        }

        $this->db->beginTransaction();
        try {
            $find = $this->db->prepare(
                "SELECT id, email, event_name, event_date, total_amount_paise, payment_method, created_at
                 FROM reimbursement_requests
                 WHERE tracking_id = :t AND email = :email AND status = 'Info Requested'
                 FOR UPDATE"
            );
            $find->execute(['t' => $trackingId, 'email' => strtolower(trim($email))]);
            $req = $find->fetch();
            if (!$req) {
                $this->db->rollBack();
                return false;
            }

            $eventDate = $this->validateEditedEventDate($eventDate, $req['created_at']);

            // Same duplicate rule as a fresh claim, ignoring this request itself.
            if ($eventName !== $req['event_name']) {
                $dup = $this->db->prepare(
                    "SELECT 1 FROM reimbursement_requests
                     WHERE email = :email AND event_name = :event_name AND id <> :id
                       AND status NOT IN ('Rejected', 'Discarded')"
                );
                $dup->execute(['email' => $req['email'], 'event_name' => $eventName, 'id' => (int) $req['id']]);
                if ($dup->fetchColumn()) {
                    throw new \InvalidArgumentException('You already have a request for an event with that name.');
                }
            }

            $cur = $this->db->prepare(
                "SELECT id, description, amount_paise FROM reimbursement_line_items
                 WHERE request_id = :rid ORDER BY id ASC FOR UPDATE"
            );
            $cur->execute(['rid' => (int) $req['id']]);
            $existing = $cur->fetchAll();

            $changes = [];
            if ($eventName !== $req['event_name']) { $changes[] = 'Event name'; }
            if ($eventDate !== $req['event_date']) { $changes[] = 'Event date'; }

            $total = 0;
            $updates = [];
            foreach ($existing as $n => $row) {
                $in = $items[(int) $row['id']] ?? null;
                $desc = trim((string) ($in['description'] ?? ''));
                $amount = (int) ($in['amount_paise'] ?? 0);
                if ($desc === '') {
                    throw new \InvalidArgumentException('Every expense needs a description.');
                }
                if (mb_strlen($desc) > self::MAX_ITEM_DESCRIPTION_LENGTH) {
                    throw new \InvalidArgumentException('An expense description is too long (' . self::MAX_ITEM_DESCRIPTION_LENGTH . ' characters max).');
                }
                if ($amount <= 0) {
                    throw new \InvalidArgumentException('Every expense amount must be greater than zero.');
                }
                if ($desc !== $row['description']) { $changes[] = 'Expense ' . ($n + 1) . ' description'; }
                if ($amount !== (int) $row['amount_paise']) { $changes[] = 'Expense ' . ($n + 1) . ' amount'; }
                $total += $amount;
                $updates[] = [(int) $row['id'], $desc, $amount];
            }

            if ($req['payment_method'] === 'upi' && $total > self::UPI_MAX_PAISE) {
                $rupees = number_format(self::UPI_MAX_PAISE / 100);
                throw new \InvalidArgumentException(
                    "This total is above ₹$rupees, which is the limit for UPI payouts, and the payment method can't be changed here. "
                    . "Please lower the amounts or write to the organisers."
                );
            }
            if ($total !== (int) $req['total_amount_paise']) {
                $changes[] = 'Total (₹' . number_format($req['total_amount_paise'] / 100, 2)
                           . ' → ₹' . number_format($total / 100, 2) . ')';
            }

            $upItem = $this->db->prepare(
                "UPDATE reimbursement_line_items SET description = :d, amount_paise = :a WHERE id = :id AND request_id = :rid"
            );
            foreach ($updates as [$itemId, $desc, $amount]) {
                $upItem->execute(['d' => $desc, 'a' => $amount, 'id' => $itemId, 'rid' => (int) $req['id']]);
            }

            $upReq = $this->db->prepare(
                "UPDATE reimbursement_requests
                 SET event_name = :name, event_date = :date, total_amount_paise = :total, status = 'Submitted'
                 WHERE id = :id AND status = 'Info Requested'"
            );
            $upReq->execute(['name' => $eventName, 'date' => $eventDate, 'total' => $total, 'id' => (int) $req['id']]);
            if ($upReq->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $body = $note . "\n\n" . ($changes
                ? '[Updated: ' . implode(', ', $changes) . ']'
                : '[No details changed]');
            $this->insertMessage($req['id'], 'applicant', null, $body);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Finance (includes owners): payment execution (did the transfer go through?)
    // ------------------------------------------------------------------

    /**
     * Deliberately narrow: payment details, plus the receipt FILES (paths
     * only) so finance can download them for their records before marking
     * a request paid. Still no line items, categories or expense
     * descriptions — finance needs to know how much, where to send it, and
     * to keep the paperwork, not what each expense was for. Bank account
     * numbers are decrypted only here, only for rows finance is actively
     * meant to pay.
     *
     * approved_paise is the amount the reviewer approved (the claimed total
     * unless they changed it); total_amount_paise stays as the applicant's
     * own claim.
     */
    public function listForFinanceQueue() {
        $stmt = $this->db->query(
            "SELECT id, tracking_id, applicant_name, email, event_name, total_amount_paise,
                    COALESCE(approved_amount_paise, total_amount_paise) AS approved_paise,
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
            $row['receipts'] = $this->getReceiptPaths($row['id']);
        }

        return $rows;
    }

    /** Receipt file paths actually on file for a request ('' means none was attached). */
    private function getReceiptPaths($requestId) {
        $stmt = $this->db->prepare(
            "SELECT receipt_path FROM reimbursement_line_items
             WHERE request_id = :rid AND receipt_path IS NOT NULL AND receipt_path <> ''
             ORDER BY id ASC"
        );
        $stmt->execute(['rid' => $requestId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function hasReceipts($requestId) {
        return count($this->getReceiptPaths($requestId)) > 0;
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

    /**
     * $receiptsDownloaded: finance confirmed they saved the receipts. That
     * timestamp is what makes the receipt files eligible for the purge cron
     * (bin/purge_receipts.php) — nothing is ever deleted without it.
     *
     * $paidPaise is the amount actually paid; $amountNote is the reason it
     * differs from the approved amount (the applicant sees it; '' if it
     * doesn't differ). The caller checks the amount; this just stores it.
     */
    public function markPaid($requestId, $financeIdentifier, $paymentReference, $receiptsDownloaded = false, $paidPaise = null, $amountNote = '') {
        $amountNote = trim((string) $amountNote);
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_requests
             SET status = 'Paid', paid_by = :who, paid_at = NOW(), payment_reference = :ref,
                 receipts_downloaded_at = IF(:dl = 1, NOW(), NULL),
                 paid_amount_paise = :paid, paid_amount_note = :pnote
             WHERE id = :id AND status = 'Approved for Payment'"
        );
        $stmt->execute([
            'who'   => $financeIdentifier,
            'ref'   => $paymentReference,
            'dl'    => $receiptsDownloaded ? 1 : 0,
            'paid'  => $paidPaise !== null ? (int) $paidPaise : null,
            'pnote' => $amountNote !== '' ? mb_substr($amountNote, 0, self::MAX_AMOUNT_NOTE_LENGTH) : null,
            'id'    => $requestId,
        ]);
        return $stmt->rowCount() === 1;
    }

    // ------------------------------------------------------------------
    // Receipt retention (used by bin/purge_receipts.php)
    // ------------------------------------------------------------------

    /**
     * Receipt files that are safe to delete: the request is Paid AND
     * finance confirmed they downloaded the receipts.
     */
    public function listReceiptsReadyForPurge() {
        return $this->db->query(
            "SELECT li.id, li.receipt_path
             FROM reimbursement_line_items li
             JOIN reimbursement_requests r ON r.id = li.request_id
             WHERE r.status = 'Paid'
               AND r.receipts_downloaded_at IS NOT NULL
               AND li.receipt_path IS NOT NULL AND li.receipt_path <> ''"
        )->fetchAll();
    }

    /** Blank the stored path once its file is gone, so it is never retried or linked. */
    public function markReceiptPurged($lineItemId) {
        $stmt = $this->db->prepare(
            "UPDATE reimbursement_line_items SET receipt_path = '' WHERE id = :id"
        );
        $stmt->execute(['id' => (int) $lineItemId]);
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
     * Lightweight list of closed (Paid) requests for the "download receipts"
     * page — applicant, amount, when it was processed, and enough to link
     * to getPaidRequestForReceipt()'s PDF for each row. Deliberately doesn't
     * include payment_method, upi_id, bank details, or line items: this is
     * an index to click into a receipt from, not a place to read payment
     * details directly. Most recently paid first.
     *
     * total_amount_paise is the claim; paid_paise is what was actually paid.
     */
    public function listPaidForFinance() {
        return $this->db->query(
            "SELECT id, tracking_id, applicant_name, event_name, total_amount_paise,
                    COALESCE(paid_amount_paise, approved_amount_paise, total_amount_paise) AS paid_paise,
                    paid_by, paid_at
             FROM reimbursement_requests
             WHERE status = 'Paid'
             ORDER BY paid_at DESC"
        )->fetchAll();
    }

    /**
     * For the payment-confirmation PDF, generated on demand when a finance
     * officer wants it — never written to disk, streamed straight to the
     * browser (see views/finance/reimbursement_receipt.php). Scoped to
     * status = 'Paid' only — there's no "confirmation" for a request that
     * hasn't actually been paid.
     *
     * UPDATE: this now includes line items (category, description, amount —
     * receipt file paths are NOT included, this isn't a place to browse
     * uploaded files from), unlike listForFinanceQueue(), which stays
     * itemless. The distinction: listForFinanceQueue() is the *working*
     * queue finance acts on before a payment exists, where "how much and
     * where to send it" is genuinely all that's needed; this method backs
     * the *finished* receipt handed out afterward, where showing what the
     * payment covered is the point of the document.
     *
     * It also carries the approved/paid amounts and their reasons, so the
     * receipt can show claimed vs approved vs paid when they differ.
     * Internal notes are never included.
     */
    public function getPaidRequestForReceipt($requestId) {
        $stmt = $this->db->prepare(
            "SELECT id, tracking_id, applicant_name, email, event_name, event_date,
                    total_amount_paise,
                    approved_amount_paise, approved_amount_note,
                    paid_amount_paise, paid_amount_note,
                    payment_method, upi_id, bank_account_name,
                    bank_account_number_enc, bank_ifsc, payment_reference,
                    decided_by, decided_at, paid_by, paid_at
             FROM reimbursement_requests
             WHERE id = :id AND status = 'Paid'"
        );
        $stmt->execute(['id' => (int) $requestId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        // Masked, not full — this becomes a standalone file a finance
        // officer can save, email, or print outside the app, so it gets the
        // same "last 4 digits" treatment a bank statement or receipt would
        // use, even though they already saw the full number once while
        // processing the payment.
        if ($row['bank_account_number_enc'] !== null) {
            $full = Crypto::decrypt($row['bank_account_number_enc']);
            $row['bank_account_number_masked'] = str_repeat('X', max(0, strlen($full) - 4)) . substr($full, -4);
        } else {
            $row['bank_account_number_masked'] = null;
        }
        unset($row['bank_account_number_enc']);

        $row['line_items'] = array_map(function ($item) {
            unset($item['receipt_path']);
            return $item;
        }, $this->getLineItems($row['id']));

        return $row;
    }

    /**
     * For the public /track lookup. Requires BOTH the tracking ID and the
     * email it was submitted with — same reasoning as
     * ApplicationModel::getApplicationByTrackingIdAndEmail(): a tracking ID
     * alone is unguessable, but an email address often isn't a secret, so
     * the pair is what keeps a single leaked/guessed value from being
     * enough to pull up someone's request.
     *
     * Status-only, deliberately: no payment_method, upi_id, bank_account_*,
     * or bank_ifsc. An applicant checking on their own request doesn't need
     * those echoed back, and payment_reference is only ever surfaced once
     * Paid — that's on your own successful payment, not a preview of it.
     *
     * approved_paise / paid_paise always hold a figure (falling back to the
     * claimed total) so callers can compare them; the page decides when each
     * is meaningful for the current status. The two *_amount_note columns are
     * the reasons the applicant is meant to see. Staff-only internal notes
     * are never selected here.
     *
     * Excludes 'Discarded' requests on purpose — see discard()'s docblock.
     * A discarded request must look exactly like "no record found" here,
     * the same way it never generates a notification.
     */
    public function getStatusForApplicant($trackingId, $email) {
        $stmt = $this->db->prepare(
            "SELECT tracking_id, event_name, event_date, total_amount_paise, status,
                    admin_notes, payment_reference, paid_at, created_at,
                    COALESCE(approved_amount_paise, total_amount_paise) AS approved_paise,
                    approved_amount_note,
                    COALESCE(paid_amount_paise, approved_amount_paise, total_amount_paise) AS paid_paise,
                    paid_amount_note
             FROM reimbursement_requests
             WHERE tracking_id = :tracking_id AND email = :email
               AND status <> 'Discarded'"
        );
        $stmt->execute([
            'tracking_id' => $trackingId,
            'email'       => strtolower(trim($email)),
        ]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Every reimbursement request this email has made, newest first, for
     * the member dashboard. Same privacy rules as getStatusForApplicant():
     * no payment details and no line items, and Discarded requests are left
     * out so they look like "no record".
     *
     * paid_paise is only filled once the request is Paid; it is NULL before that.
     */
    public function listForMember($email) {
        $stmt = $this->db->prepare(
            "SELECT tracking_id, event_name, total_amount_paise, status, created_at,
                    COALESCE(approved_amount_paise, total_amount_paise) AS approved_paise,
                    CASE WHEN status = 'Paid'
                         THEN COALESCE(paid_amount_paise, approved_amount_paise, total_amount_paise)
                    END AS paid_paise
             FROM reimbursement_requests
             WHERE email = :email AND status <> 'Discarded'
             ORDER BY created_at DESC"
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        return $stmt->fetchAll();
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
