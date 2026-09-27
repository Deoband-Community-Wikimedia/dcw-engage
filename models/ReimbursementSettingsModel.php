<?php
/**
 * DCW Engage - Reimbursement Settings (global, singleton)
 *
 * Replaces the earlier per-event ReimbursementFormModel now that there is
 * one reimbursement form covering every event, not one per event — the
 * applicant types the event name themselves rather than the form being
 * scoped to a specific one. There is exactly one settings row (id = 1,
 * enforced by a CHECK constraint in the migration), and one global
 * eligibility allowlist rather than one per event.
 */
class ReimbursementSettingsModel {
    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    public function get() {
        $stmt = $this->db->query("SELECT * FROM reimbursement_settings WHERE id = 1");
        $row = $stmt->fetch();

        if ($row) {
            $row['expense_categories'] = json_decode($row['expense_categories'], true) ?: [];
        }

        return $row ?: null;
    }

    public function save(array $data) {
        $cashThresholdPaise = (int) round(($data['cash_threshold_rupees'] ?? 800) * 100);
        $categories = array_values(array_filter(array_map('trim', $data['expense_categories'] ?? [])));

        if (empty($categories)) {
            throw new \InvalidArgumentException('At least one expense category is required.');
        }

        $stmt = $this->db->prepare(
            "UPDATE reimbursement_settings
             SET is_active = :active, cash_threshold_paise = :threshold,
                 expense_categories = :categories, instructions = :instructions
             WHERE id = 1"
        );
        $stmt->execute([
            'active'       => !empty($data['is_active']) ? 1 : 0,
            'threshold'    => $cashThresholdPaise,
            'categories'   => json_encode($categories),
            'instructions' => $data['instructions'] ?? null,
        ]);
    }

    public function addEligibility($email, $addedBy) {
        $email = strtolower(trim($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Not a valid email address: $email");
        }

        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO reimbursement_global_eligibility (email, added_by)
             VALUES (:email, :added_by)"
        );
        $stmt->execute(['email' => $email, 'added_by' => $addedBy]);
    }

    public function removeEligibility($email) {
        $stmt = $this->db->prepare(
            "DELETE FROM reimbursement_global_eligibility WHERE email = :email"
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
    }

    public function listEligibility() {
        return $this->db->query(
            "SELECT email, added_by, created_at FROM reimbursement_global_eligibility ORDER BY created_at DESC"
        )->fetchAll();
    }
}
