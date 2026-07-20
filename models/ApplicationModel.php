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
}
