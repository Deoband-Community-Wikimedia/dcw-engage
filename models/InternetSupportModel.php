<?php
require_once __DIR__ . '/../includes/crypto.php';

/**
 * DCW Engage - Internet Support Requests
 *
 * A volunteer asks for help paying for a data pack. The flow, and who may
 * move a request at each step:
 *
 *   Submitted --(support reviewer)--> Approved for Support | Rejected | Discarded
 *   Approved for Support --(finance)--> Awaiting Receipt | Recharge Failed
 *   Recharge Failed --(support reviewer)--> Approved for Support | Rejected | Discarded
 *   Awaiting Receipt --(applicant, via /track)--> Receipt Submitted
 *   Receipt Submitted --(finance)--> Closed | Awaiting Receipt (receipt bounced)
 *
 * Same conventions as ReimbursementModel: every transition is a conditional
 * UPDATE that re-checks the current status and reports success via
 * rowCount() === 1, so a double click or two people acting at once can never
 * move a request twice. Views must treat a false return as "someone else got
 * there first".
 *
 * What each role may see is enforced in the SELECT lists, not the markup:
 *   - reviewers: reason and package, never the phone number
 *   - finance:   phone and package, never the reason
 * A column that is fetched but not printed can still leak through a later
 * template change or an error trace.
 *
 * Discard is silent, as with reimbursements: no email, and a discarded
 * request looks identical to "no record found" on /track.
 */
class InternetSupportModel {
    public const MIN_REASON_LENGTH = 15;
    public const MAX_REASON_LENGTH = 1000;

    /** One request per email per this many days (rejected/discarded ones don't count). Policy default: change freely. */
    public const MIN_DAYS_BETWEEN_REQUESTS = 30;

    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    // ------------------------------------------------------------------
    // Settings (single row, id = 1)
    // ------------------------------------------------------------------

    public function getSettings() {
        $row = $this->db->query(
            "SELECT is_active, max_amount_paise FROM internet_settings WHERE id = 1"
        )->fetch();
        return $row ?: null;
    }

    /** True when the programme is switched on. A missing settings row counts as closed. */
    public function isOpen() {
        $settings = $this->getSettings();
        return $settings && (int) $settings['is_active'] === 1;
    }

    // ------------------------------------------------------------------
    // Submission
    // ------------------------------------------------------------------

    /**
     * Accepts 9876543210, +91 98765 43210, 098765-43210 etc. and returns the
     * bare 10-digit Indian mobile number, or null if it isn't one.
     */
    private static function normalizePhone($raw) {
        $p = preg_replace('/[\s\-]/', '', (string) $raw);
        if (preg_match('/^(?:\+91|91|0)?([6-9]\d{9})$/', $p, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * True if this email has a request that hasn't reached a final state.
     */
    public function hasOpenRequest($email) {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM internet_requests
             WHERE email = :email AND status NOT IN ('Rejected', 'Discarded', 'Closed')"
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        return (bool) $stmt->fetchColumn();
    }

    private function hasRecentRequest($email) {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM internet_requests
             WHERE email = :email AND status NOT IN ('Rejected', 'Discarded')
               AND created_at > (NOW() - INTERVAL :days DAY)"
        );
        $stmt->execute([
            'email' => strtolower(trim($email)),
            'days'  => (int) self::MIN_DAYS_BETWEEN_REQUESTS,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Organisers can't know in advance which pack a volunteer needs, so the
     * operator, plan and amount are all stated by the applicant and are
     * UNVERIFIED. Reviewers judge whether the amount is reasonable; finance
     * confirms the operator's real price when doing the recharge.
     *
     * @param string $email        already verified (EmailVerificationModel, form_id NULL)
     * @param string $amountRupees e.g. "299" or "299.50"
     * @param mixed  $validityDays optional whole days, '' for none
     * @throws \InvalidArgumentException on any validation failure
     * @return array ['id' => int, 'tracking_id' => string]
     */
    public function createRequest($email, $applicantName, $phone, $operator, $packageName, $amountRupees, $validityDays, $reason) {
        $settings = $this->getSettings();
        if (!$settings || (int) $settings['is_active'] !== 1) {
            throw new \InvalidArgumentException('Internet support requests are closed right now.');
        }

        $email = strtolower(trim($email));
        $applicantName = trim($applicantName);
        $operator = trim(preg_replace('/\s+/', ' ', (string) $operator));
        $packageName = trim(preg_replace('/\s+/', ' ', (string) $packageName));
        $reason = trim($reason);

        if ($applicantName === '') {
            throw new \InvalidArgumentException('Please enter your name.');
        }
        if (mb_strlen($applicantName) > 255) {
            throw new \InvalidArgumentException('Name is too long (255 characters max).');
        }

        $phoneNormalized = self::normalizePhone($phone);
        if ($phoneNormalized === null) {
            throw new \InvalidArgumentException('Please enter a valid 10-digit Indian mobile number.');
        }

        if ($operator === '' || mb_strlen($operator) > 50) {
            throw new \InvalidArgumentException('Please enter your mobile operator (50 characters max).');
        }
        if (mb_strlen($packageName) < 3 || mb_strlen($packageName) > 120) {
            throw new \InvalidArgumentException('Please describe the pack you need, e.g. "1.5 GB/day, 28 days" (3 to 120 characters).');
        }

        $amountRupees = trim((string) $amountRupees);
        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $amountRupees) || (float) $amountRupees <= 0) {
            throw new \InvalidArgumentException('Please enter the pack price as a positive amount, e.g. 299.');
        }
        $amountPaise = (int) round(((float) $amountRupees) * 100);
        $maxPaise = (int) $settings['max_amount_paise'];
        if ($amountPaise > $maxPaise) {
            throw new \InvalidArgumentException('Requests are limited to Rs ' . number_format($maxPaise / 100) . '. Please choose a smaller pack.');
        }

        $validityDays = trim((string) $validityDays);
        if ($validityDays === '') {
            $validity = null;
        } elseif (ctype_digit($validityDays) && (int) $validityDays >= 1 && (int) $validityDays <= 365) {
            $validity = (int) $validityDays;
        } else {
            throw new \InvalidArgumentException('Validity must be a whole number of days between 1 and 365, or left blank.');
        }

        $len = mb_strlen($reason);
        if ($len < self::MIN_REASON_LENGTH) {
            throw new \InvalidArgumentException('Please tell us a little more about why you need this.');
        }
        if ($len > self::MAX_REASON_LENGTH) {
            throw new \InvalidArgumentException('Reason is too long (' . self::MAX_REASON_LENGTH . ' characters max).');
        }

        if ($this->hasOpenRequest($email)) {
            throw new \InvalidArgumentException('You already have an internet support request in progress. You can follow it on the tracking page.');
        }
        if ($this->hasRecentRequest($email)) {
            throw new \InvalidArgumentException('Internet support can be requested once every ' . self::MIN_DAYS_BETWEEN_REQUESTS . ' days.');
        }

        $trackingId = $this->generateTrackingId();

        $stmt = $this->db->prepare(
            "INSERT INTO internet_requests
                (tracking_id, email, applicant_name, phone_enc,
                 operator, package_name, package_price_paise, package_validity_days, reason)
             VALUES
                (:tracking_id, :email, :name, :phone_enc,
                 :operator, :package_name, :price, :validity, :reason)"
        );
        $stmt->execute([
            'tracking_id'  => $trackingId,
            'email'        => $email,
            'name'         => $applicantName,
            'phone_enc'    => Crypto::encrypt($phoneNormalized),
            'operator'     => $operator,
            'package_name' => $packageName,
            'price'        => $amountPaise,
            'validity'     => $validity,
            'reason'       => $reason,
        ]);

        return ['id' => (int) $this->db->lastInsertId(), 'tracking_id' => $trackingId];
    }

    private function generateTrackingId() {
        for ($i = 0; $i < 5; $i++) {
            $candidate = 'IS-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $this->db->prepare("SELECT 1 FROM internet_requests WHERE tracking_id = :t");
            $stmt->execute(['t' => $candidate]);
            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Could not generate a unique tracking ID after 5 attempts.');
    }

    // ------------------------------------------------------------------
    // Support reviewers: is the request reasonable?
    // ------------------------------------------------------------------

    /**
     * Deliberately has NO phone_enc, receipt or recharge-reference columns.
     * recharge_notes is included so that, for a 'Recharge Failed' request,
     * the reviewer can see why finance sent it back.
     * prior_recharges counts earlier requests from the same email that were
     * actually paid for, to help spot repeat asks.
     */
    public function listForReview($status = null) {
        $sql = "SELECT r.id, r.tracking_id, r.email, r.applicant_name,
                       r.operator, r.package_name, r.package_price_paise, r.package_validity_days,
                       r.reason, r.status, r.admin_notes, r.decided_by, r.decided_at,
                       r.recharge_notes, r.created_at,
                       (SELECT COUNT(*) FROM internet_requests p
                         WHERE p.email = r.email AND p.id <> r.id
                           AND p.status IN ('Awaiting Receipt', 'Receipt Submitted', 'Closed')) AS prior_recharges
                FROM internet_requests r";
        $params = [];

        if ($status !== null) {
            $sql .= " WHERE r.status = :status";
            $params['status'] = $status;
        } else {
            $sql .= " WHERE r.status IN ('Submitted', 'Recharge Failed')";
        }

        $sql .= " ORDER BY r.created_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function approve($requestId, $adminIdentifier) {
        $stmt = $this->db->prepare(
            "UPDATE internet_requests
             SET status = 'Approved for Support', decided_by = :who, decided_at = NOW()
             WHERE id = :id AND status IN ('Submitted', 'Recharge Failed')"
        );
        $stmt->execute(['who' => $adminIdentifier, 'id' => (int) $requestId]);
        return $stmt->rowCount() === 1;
    }

    /** Valid request, but not something DCW will fund. The applicant IS notified. */
    public function reject($requestId, $adminIdentifier, $notes) {
        $stmt = $this->db->prepare(
            "UPDATE internet_requests
             SET status = 'Rejected', decided_by = :who, decided_at = NOW(), admin_notes = :notes
             WHERE id = :id AND status IN ('Submitted', 'Recharge Failed')"
        );
        $stmt->execute(['who' => $adminIdentifier, 'notes' => $notes, 'id' => (int) $requestId]);
        return $stmt->rowCount() === 1;
    }

    /** Spam / duplicate / not a real request. Silent: the caller must NOT notify the applicant. */
    public function discard($requestId, $adminIdentifier, $notes = null) {
        $stmt = $this->db->prepare(
            "UPDATE internet_requests
             SET status = 'Discarded', decided_by = :who, decided_at = NOW(), admin_notes = :notes
             WHERE id = :id AND status IN ('Submitted', 'Recharge Failed')"
        );
        $stmt->execute(['who' => $adminIdentifier, 'notes' => $notes, 'id' => (int) $requestId]);
        return $stmt->rowCount() === 1;
    }

    // ------------------------------------------------------------------
    // Finance (includes owners): recharge, then receipt verification
    // ------------------------------------------------------------------

    /**
     * Approved requests waiting for a recharge. Phone is decrypted only
     * here, only for rows finance is actively meant to act on. The reason
     * is not selected: finance needs the number, operator and pack.
     */
    public function listForRechargeQueue() {
        $rows = $this->db->query(
            "SELECT id, tracking_id, applicant_name, email, phone_enc,
                    operator, package_name, package_price_paise, package_validity_days,
                    decided_by, decided_at
             FROM internet_requests
             WHERE status = 'Approved for Support'
             ORDER BY decided_at ASC"
        )->fetchAll();

        return $this->attachDecryptedPhones($rows);
    }

    /** Receipts the applicant has uploaded, waiting for finance to check them. */
    public function listForReceiptVerification() {
        $rows = $this->db->query(
            "SELECT id, tracking_id, applicant_name, email, phone_enc,
                    operator, package_name, package_price_paise,
                    recharge_reference, recharged_at, receipt_path, receipt_submitted_at
             FROM internet_requests
             WHERE status = 'Receipt Submitted'
             ORDER BY receipt_submitted_at ASC"
        )->fetchAll();

        return $this->attachDecryptedPhones($rows);
    }

    /**
     * Decrypts each row's phone number. A row that can't be decrypted (a
     * damaged value, or the encryption key having changed) gets phone = null
     * and phone_error = true instead of throwing, so one bad row can't take
     * the whole queue down. Views must check phone_error and must NOT offer
     * a recharge for such a row: we never guess at a number to pay.
     */
    private function attachDecryptedPhones(array $rows) {
        foreach ($rows as &$row) {
            try {
                $row['phone'] = Crypto::decrypt($row['phone_enc']);
                $row['phone_error'] = false;
            } catch (\RuntimeException $e) {
                $row['phone'] = null;
                $row['phone_error'] = true;
                // Tracking ID only: never log the number or the ciphertext.
                error_log('Internet support: could not decrypt phone for ' . ($row['tracking_id'] ?? '?') . ' - ' . $e->getMessage());
            }
            unset($row['phone_enc']);
        }
        unset($row);

        return $rows;
    }

    /** Finance recharged the number. The request now waits on the applicant's receipt. */
    public function markRechargeDone($requestId, $financeIdentifier, $reference) {
        $stmt = $this->db->prepare(
            "UPDATE internet_requests
             SET status = 'Awaiting Receipt', recharged_by = :who, recharged_at = NOW(),
                 recharge_reference = :ref, recharge_notes = NULL
             WHERE id = :id AND status = 'Approved for Support'"
        );
        $stmt->execute(['who' => $financeIdentifier, 'ref' => $reference, 'id' => (int) $requestId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * The recharge itself failed (wrong operator, number not recharge-able,
     * etc). Goes back to the support reviewers, NOT to the applicant — same
     * reasoning as ReimbursementModel::markPaymentFailed().
     */
    public function markRechargeFailed($requestId, $financeIdentifier, $notes) {
        $stmt = $this->db->prepare(
            "UPDATE internet_requests
             SET status = 'Recharge Failed', recharged_by = :who, recharged_at = NOW(), recharge_notes = :notes
             WHERE id = :id AND status = 'Approved for Support'"
        );
        $stmt->execute(['who' => $financeIdentifier, 'notes' => $notes, 'id' => (int) $requestId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Receipt isn't acceptable. Returns the old receipt's stored path (so the
     * caller can unlink the file) or false if the request wasn't in
     * 'Receipt Submitted'. Compare with === false: the path can be ''.
     */
    public function sendBackForReceipt($requestId, $financeIdentifier, $notes) {
        $stmt = $this->db->prepare(
            "SELECT receipt_path FROM internet_requests
             WHERE id = :id AND status = 'Receipt Submitted'"
        );
        $stmt->execute(['id' => (int) $requestId]);
        $oldPath = $stmt->fetchColumn();
        if ($oldPath === false) {
            return false;
        }

        $update = $this->db->prepare(
            "UPDATE internet_requests
             SET status = 'Awaiting Receipt', receipt_path = '', receipt_submitted_at = NULL,
                 finance_notes = :notes
             WHERE id = :id AND status = 'Receipt Submitted'"
        );
        $update->execute(['notes' => $notes, 'id' => (int) $requestId]);

        return $update->rowCount() === 1 ? (string) $oldPath : false;
    }

    /**
     * $receiptsDownloaded: finance confirmed they saved the receipt. That
     * timestamp is what makes the file eligible for the purge cron.
     */
    public function close($requestId, $financeIdentifier, $receiptsDownloaded = false) {
        $stmt = $this->db->prepare(
            "UPDATE internet_requests
             SET status = 'Closed', closed_by = :who, closed_at = NOW(),
                 receipts_downloaded_at = IF(:dl = 1, NOW(), NULL)
             WHERE id = :id AND status = 'Receipt Submitted'"
        );
        $stmt->execute([
            'who' => $financeIdentifier,
            'dl'  => $receiptsDownloaded ? 1 : 0,
            'id'  => (int) $requestId,
        ]);
        return $stmt->rowCount() === 1;
    }

    /** Index for the "closed requests" page. No phone, no reason. */
    public function listClosedForFinance() {
        return $this->db->query(
            "SELECT id, tracking_id, applicant_name, operator, package_name, package_price_paise,
                    recharge_reference, closed_by, closed_at
             FROM internet_requests
             WHERE status = 'Closed'
             ORDER BY closed_at DESC"
        )->fetchAll();
    }

    // ------------------------------------------------------------------
    // Applicant (public /track, tracking ID + email pair)
    // ------------------------------------------------------------------

    /**
     * Status-only view. No phone number, no reason, no internal notes except
     * admin_notes (shown on rejection, as with reimbursements) and
     * finance_notes (shown only so the applicant knows why a receipt was
     * sent back). 'Discarded' is excluded: it must look like "no record".
     */
    public function getStatusForApplicant($trackingId, $email) {
        $stmt = $this->db->prepare(
            "SELECT tracking_id, operator, package_name, package_price_paise, status,
                    admin_notes, finance_notes, recharge_reference, created_at
             FROM internet_requests
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
     * Attach the uploaded receipt. Only works while the request is
     * 'Awaiting Receipt' AND the tracking ID + email pair match, so the
     * upload link can't be used by someone who only knows one of them.
     * Call FileUploader AFTER confirming the request is in that state, and
     * unlink the file if this returns false.
     */
    public function submitReceipt($trackingId, $email, $receiptPath) {
        $stmt = $this->db->prepare(
            "UPDATE internet_requests
             SET status = 'Receipt Submitted', receipt_path = :path,
                 receipt_submitted_at = NOW(), finance_notes = NULL
             WHERE tracking_id = :tracking_id AND email = :email AND status = 'Awaiting Receipt'"
        );
        $stmt->execute([
            'path'        => $receiptPath,
            'tracking_id' => $trackingId,
            'email'       => strtolower(trim($email)),
        ]);
        return $stmt->rowCount() === 1;
    }

    // ------------------------------------------------------------------
    // Notifications
    // ------------------------------------------------------------------

    /** Context for sending the matching email right after a transition. */
    public function getForNotification($requestId) {
        $stmt = $this->db->prepare(
            "SELECT id, tracking_id, applicant_name, email, operator, package_name,
                    status, recharge_reference
             FROM internet_requests WHERE id = :id"
        );
        $stmt->execute(['id' => (int) $requestId]);
        return $stmt->fetch() ?: null;
    }

    // ------------------------------------------------------------------
    // Receipt retention (for a bin/purge_internet_receipts.php, same shape
    // as bin/purge_receipts.php)
    // ------------------------------------------------------------------

    public function listReceiptsReadyForPurge() {
        return $this->db->query(
            "SELECT id, receipt_path FROM internet_requests
             WHERE status = 'Closed'
               AND receipts_downloaded_at IS NOT NULL
               AND receipt_path <> ''"
        )->fetchAll();
    }

    public function markReceiptPurged($requestId) {
        $stmt = $this->db->prepare("UPDATE internet_requests SET receipt_path = '' WHERE id = :id");
        $stmt->execute(['id' => (int) $requestId]);
    }
}
