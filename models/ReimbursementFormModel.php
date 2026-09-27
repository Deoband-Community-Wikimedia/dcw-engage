<?php
/**
 * DCW Engage - Reimbursement Form Configuration
 *
 * Each event (row in `forms`) can have at most one reimbursement config,
 * created and edited by an admin: the expense categories on offer, the cash
 * threshold above which cash is no longer an allowed payment method, and
 * an admin-added eligibility list for people who should be able to claim
 * without an 'Accepted' application row (volunteers, speakers, staff).
 */

class ReimbursementFormModel {
    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    public function getByFormId($formId) {
        $stmt = $this->db->prepare(
            "SELECT * FROM reimbursement_forms WHERE form_id = :form_id"
        );
        $stmt->execute(['form_id' => $formId]);
        $row = $stmt->fetch();

        if ($row) {
            $row['expense_categories'] = json_decode($row['expense_categories'], true) ?: [];
        }

        return $row ?: null;
    }

    public function getById($id) {
        $stmt = $this->db->prepare("SELECT * FROM reimbursement_forms WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row) {
            $row['expense_categories'] = json_decode($row['expense_categories'], true) ?: [];
        }

        return $row ?: null;
    }

    /**
     * Create or replace the reimbursement config for an event. One config
     * per event (see UNIQUE(form_id)), so this is effectively upsert.
     */
    public function save($formId, array $data) {
        $cashThresholdPaise = (int) round(($data['cash_threshold_rupees'] ?? 800) * 100);
        $categories = array_values(array_filter(array_map('trim', $data['expense_categories'] ?? [])));

        if (empty($categories)) {
            throw new \InvalidArgumentException('At least one expense category is required.');
        }

        $existing = $this->getByFormId($formId);

        if ($existing) {
            $stmt = $this->db->prepare(
                "UPDATE reimbursement_forms
                 SET is_active = :active, cash_threshold_paise = :threshold,
                     expense_categories = :categories, instructions = :instructions
                 WHERE form_id = :form_id"
            );
            $stmt->execute([
                'active'       => !empty($data['is_active']) ? 1 : 0,
                'threshold'    => $cashThresholdPaise,
                'categories'   => json_encode($categories),
                'instructions' => $data['instructions'] ?? null,
                'form_id'      => $formId,
            ]);
            return $existing['id'];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO reimbursement_forms (form_id, is_active, cash_threshold_paise, expense_categories, instructions)
             VALUES (:form_id, :active, :threshold, :categories, :instructions)"
        );
        $stmt->execute([
            'form_id'      => $formId,
            'active'       => !empty($data['is_active']) ? 1 : 0,
            'threshold'    => $cashThresholdPaise,
            'categories'   => json_encode($categories),
            'instructions' => $data['instructions'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function addEligibility($reimbursementFormId, $email, $addedBy) {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Not a valid email address: $email");
        }

        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO reimbursement_eligibility (reimbursement_form_id, email, added_by)
             VALUES (:rid, :email, :added_by)"
        );
        $stmt->execute(['rid' => $reimbursementFormId, 'email' => $email, 'added_by' => $addedBy]);
    }

    public function removeEligibility($reimbursementFormId, $email) {
        $stmt = $this->db->prepare(
            "DELETE FROM reimbursement_eligibility WHERE reimbursement_form_id = :rid AND email = :email"
        );
        $stmt->execute(['rid' => $reimbursementFormId, 'email' => strtolower(trim($email))]);
    }

    public function listEligibility($reimbursementFormId) {
        $stmt = $this->db->prepare(
            "SELECT email, added_by, created_at FROM reimbursement_eligibility
             WHERE reimbursement_form_id = :rid ORDER BY created_at DESC"
        );
        $stmt->execute(['rid' => $reimbursementFormId]);
        return $stmt->fetchAll();
    }
}
