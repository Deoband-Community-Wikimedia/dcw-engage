<?php
/**
 * DCW Engage - Application Model
 * 
 * Enforces the State Machine (Draft -> Submitted -> Under Review)
 * and handles Magic Link generation.
 */

class ApplicationModel {
    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    /**
     * Check if user already has an application for this form
     */
    public function getApplicationByEmail($formId, $email) {
        $stmt = $this->db->prepare("SELECT * FROM applications WHERE form_id = :form_id AND email = :email");
        $stmt->execute([
            'form_id' => $formId,
            'email' => $email
        ]);
        return $stmt->fetch();
    }

    /**
     * Save or update an application
     */
    public function saveApplication($formId, $email, $applicantName, $status, $formDataJson, $appId = null) {
        if ($appId) {
            // Check State Machine before allowing update
            $stmt = $this->db->prepare("SELECT status FROM applications WHERE id = :id");
            $stmt->execute(['id' => $appId]);
            $currentStatus = $stmt->fetchColumn();

            if (in_array($currentStatus, ['Under Review', 'Accepted', 'Rejected'])) {
                throw new Exception("This application is locked and cannot be edited.");
            }

            // tracking_id is deliberately not touched here — it's assigned
            // once at creation and stays the same across every Draft -> New
            // edit, so an applicant's tracking ID never changes underneath
            // them.
            $stmt = $this->db->prepare("UPDATE applications SET applicant_name = :name, status = :status, form_data = :data WHERE id = :id");
            $stmt->execute([
                'name' => $applicantName,
                'status' => $status,
                'data' => $formDataJson,
                'id' => $appId
            ]);
            return $appId;
        } else {
            // A handful of retries against the UNIQUE constraint, not a
            // check-then-insert — the space is ~1.1 trillion codes (see
            // generateTrackingId()), so a genuine collision is not expected
            // to ever actually happen; this only guards against it.
            $maxAttempts = 5;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $trackingId = self::generateTrackingId();
                try {
                    $stmt = $this->db->prepare("INSERT INTO applications (form_id, email, applicant_name, status, form_data, tracking_id) VALUES (:form_id, :email, :name, :status, :data, :tracking_id)");
                    $stmt->execute([
                        'form_id' => $formId,
                        'email' => $email,
                        'name' => $applicantName,
                        'status' => $status,
                        'data' => $formDataJson,
                        'tracking_id' => $trackingId
                    ]);
                    return $this->db->lastInsertId();
                } catch (PDOException $e) {
                    // Only retry a tracking_id clash specifically — a
                    // duplicate (form_id, email) race on uniq_form_email is
                    // a real error the caller needs to see, not something a
                    // fresh tracking_id would ever fix.
                    $isTrackingIdClash = isset($e->errorInfo[2]) && str_contains($e->errorInfo[2], 'tracking_id');
                    if (!$isTrackingIdClash || $attempt === $maxAttempts) {
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * Random public-facing ID, e.g. 'DCW-7K4N9XQ2'. Deliberately unrelated
     * to the row's auto-increment id (see #32) so it can't be walked by
     * guessing neighbouring numbers or used to infer how many applications
     * a form has received.
     *
     * Alphabet excludes 0/O/1/I/L, which read alike when an applicant is
     * typing the code back in by hand on the tracking lookup page.
     */
    private static function generateTrackingId() {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'DCW-' . $code;
    }

    /**
     * Fetch just the tracking ID for a row — e.g. right after
     * saveApplication() hands back a freshly-inserted row's id, which is
     * all it returns.
     */
    public function getTrackingId($id) {
        $stmt = $this->db->prepare("SELECT tracking_id FROM applications WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetchColumn();
    }

    /**
     * Look up a single application by the combination of its tracking ID
     * and the email it was submitted with — the public "check my status"
     * lookup on the homepage (see #32). Both must match; a tracking ID
     * alone is not treated as sufficient, since (unlike the id it replaces)
     * it is otherwise unguessable but an applicant's email is often not a
     * secret, so requiring both keeps a single leaked/guessed value from
     * being enough on its own.
     */
    public function getApplicationByTrackingIdAndEmail($trackingId, $email) {
        $stmt = $this->db->prepare("
            SELECT a.*, JSON_UNQUOTE(JSON_EXTRACT(f.schema_json, '$.title')) as form_title
            FROM applications a
            JOIN forms f ON a.form_id = f.id
            WHERE a.tracking_id = :tracking_id AND a.email = :email
        ");
        $stmt->execute(['tracking_id' => $trackingId, 'email' => $email]);
        return $stmt->fetch();
    }

    /**
     * Generate a Magic Link for resuming/editing
     */
    public function generateMagicLink($applicationId, $isDraft = true) {
        $config = require __DIR__ . '/../includes/config.php';
        $expiryStr = $isDraft ? $config['security']['magic_link_expiry_draft'] : $config['security']['magic_link_expiry_edit'];
        $expiresAt = date('Y-m-d H:i:s', strtotime($expiryStr));
        
        $token = bin2hex(random_bytes(32));

        $stmt = $this->db->prepare("INSERT INTO magic_links (application_id, token, expires_at) VALUES (:app_id, :token, :expires)");
        $stmt->execute([
            'app_id' => $applicationId,
            'token' => $token,
            'expires' => $expiresAt
        ]);

        return $token;
    }

    /**
     * Get Application by Magic Link Token
     */
    public function getApplicationByToken($token) {
        $stmt = $this->db->prepare("
            SELECT a.*, f.schema_json, f.form_type, f.is_active, f.notify_emails, m.expires_at
            FROM magic_links m
            JOIN applications a ON m.application_id = a.id
            JOIN forms f ON a.form_id = f.id
            WHERE m.token = :token
        ");
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        
        if ($row && strtotime($row['expires_at']) > time()) {
            return $row;
        }
        return false;
    }

    /**
     * Get all applications with form details
     */
    public function getAllApplications() {
        $stmt = $this->db->query("
            SELECT a.*, JSON_UNQUOTE(JSON_EXTRACT(f.schema_json, '$.title')) as form_title, f.form_type 
            FROM applications a 
            JOIN forms f ON a.form_id = f.id 
            ORDER BY a.created_at DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Get a single application by ID
     */
    public function getApplicationById($id) {
        $stmt = $this->db->prepare("
            SELECT a.*, JSON_UNQUOTE(JSON_EXTRACT(f.schema_json, '$.title')) as form_title, f.form_type 
            FROM applications a 
            JOIN forms f ON a.form_id = f.id 
            WHERE a.id = :id
        ");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Update application status
     */
    public function updateStatus($id, $status) {
        $validStatuses = ['Draft', 'New', 'Submitted', 'Under Review', 'Accepted', 'Rejected'];
        if (!in_array($status, $validStatuses)) {
            throw new Exception("Invalid status.");
        }
        $stmt = $this->db->prepare("UPDATE applications SET status = :status WHERE id = :id");
        return $stmt->execute(['status' => $status, 'id' => $id]);
    }
    
    /**
     * Bulk-update status for many applications at once.
     * Validates the status against the same whitelist as updateStatus(), and
     * scopes every update to $formId so a request can only touch rows that
     * belong to the form being managed.
     */
    public function updateStatusBulk($applicationIds, $status, $formId) {
        $validStatuses = ['Draft', 'New', 'Submitted', 'Under Review', 'Accepted', 'Rejected'];
        if (!in_array($status, $validStatuses)) {
            throw new Exception("Invalid status.");
        }
        $stmt = $this->db->prepare("UPDATE applications SET status = :status WHERE id = :id AND form_id = :form_id");
        foreach ($applicationIds as $id) {
            $stmt->execute([
                'status' => $status,
                'id' => $id,
                'form_id' => $formId
            ]);
        }
    }

    /**
     * Get applications for a specific form
     */
    public function getApplicationsByFormId($formId) {
        $stmt = $this->db->prepare("
            SELECT *
            FROM applications
            WHERE form_id = :form_id
            ORDER BY created_at DESC
        ");
        $stmt->execute(['form_id' => $formId]);
        return $stmt->fetchAll();
    }

    /**
     * Assign a tracking_id to every row that predates the column (see #32
     * and the migration note in database.sql). Used by
     * bin/backfill_tracking_ids.php on an existing install; a fresh import
     * never has any rows to backfill since the column is on the CREATE
     * TABLE itself.
     *
     * Returns how many rows were updated.
     */
    public function backfillMissingTrackingIds() {
        $ids = $this->db->query("SELECT id FROM applications WHERE tracking_id IS NULL")->fetchAll(PDO::FETCH_COLUMN);

        $updated = 0;
        foreach ($ids as $id) {
            // Same collision-retry approach as saveApplication()'s insert
            // path — see generateTrackingId() for why a real clash isn't
            // expected to ever actually happen.
            $maxAttempts = 5;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $stmt = $this->db->prepare("UPDATE applications SET tracking_id = :tracking_id WHERE id = :id");
                    $stmt->execute(['tracking_id' => self::generateTrackingId(), 'id' => $id]);
                    $updated++;
                    break;
                } catch (PDOException $e) {
                    $isTrackingIdClash = isset($e->errorInfo[2]) && str_contains($e->errorInfo[2], 'tracking_id');
                    if (!$isTrackingIdClash || $attempt === $maxAttempts) {
                        throw $e;
                    }
                }
            }
        }

        return $updated;
    }
}
