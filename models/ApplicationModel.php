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

            $stmt = $this->db->prepare("UPDATE applications SET applicant_name = :name, status = :status, form_data = :data WHERE id = :id");
            $stmt->execute([
                'name' => $applicantName,
                'status' => $status,
                'data' => $formDataJson,
                'id' => $appId
            ]);
            return $appId;
        } else {
            $stmt = $this->db->prepare("INSERT INTO applications (form_id, email, applicant_name, status, form_data) VALUES (:form_id, :email, :name, :status, :data)");
            $stmt->execute([
                'form_id' => $formId,
                'email' => $email,
                'name' => $applicantName,
                'status' => $status,
                'data' => $formDataJson
            ]);
            return $this->db->lastInsertId();
        }
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
}
