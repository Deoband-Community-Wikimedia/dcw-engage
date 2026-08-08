<?php
/**
 * DCW Engage - Form Model
 * 
 * Handles parsing and validation of dynamic JSON form schemas.
 */

class FormModel {
    private $db;

    public function __construct() {
        $this->db = DB::getInstance()->getConnection();
    }

    /**
     * Fetch a form by its type (e.g., 'scholarship')
     */
    public function getFormByType($formType) {
        $stmt = $this->db->prepare("SELECT * FROM forms WHERE form_type = :type AND is_active = 1");
        $stmt->execute(['type' => $formType]);
        $form = $stmt->fetch();
        
        if ($form) {
            $form['schema'] = json_decode($form['schema_json'], true);
        }
        
        return $form;
    }

    /**
     * Fetch a form by type regardless of its active state.
     * Used to tell a closed form apart from one that never existed, so the
     * public renderer can show the right message instead of a blanket 404.
     */
    public function getAnyFormByType($formType) {
        $stmt = $this->db->prepare("SELECT * FROM forms WHERE form_type = :type");
        $stmt->execute(['type' => $formType]);
        $form = $stmt->fetch();

        if ($form) {
            $form['schema'] = json_decode($form['schema_json'], true);
        }

        return $form;
    }

    public function validateSubmission($schema, $postData, $skipRequired = false) {
        $errors = [];

        foreach ($schema['fields'] as $field) {
            $name = $field['name'];
            $isRequired = $field['required'] ?? false;
            $type = $field['type'] ?? 'text';

            // Check required — skipped entirely for a draft save, which is
            // allowed to be incomplete by definition.
            if ($isRequired && !$skipRequired) {
                if ($type === 'file') {
                    // Check if file was uploaded or if existing path is provided (for resume portal)
                    if (empty($_FILES[$name]['name']) && empty($postData[$name])) {
                        $errors[$name] = ($field['label'] ?? $name) . " is required.";
                    }
                } else {
                    if (empty($postData[$name])) {
                        $errors[$name] = ($field['label'] ?? $name) . " is required.";
                    }
                }
            }
        }
        
        return $errors;
    }

    /**
     * Fetch all forms for the admin grid
     */
    public function getAllForms() {
        $stmt = $this->db->query("
            SELECT f.*,
                   JSON_UNQUOTE(JSON_EXTRACT(f.schema_json, '$.title')) as title,
                   (SELECT COUNT(*) FROM applications a WHERE a.form_id = f.id) as applicant_count
            FROM forms f
            ORDER BY f.created_at DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Active forms only, with title and description pulled from the schema.
     * Powers the public homepage listing at engage.dcwwiki.org.
     */
    public function getActiveForms() {
        $stmt = $this->db->query("
            SELECT form_type,
                   JSON_UNQUOTE(JSON_EXTRACT(schema_json, '$.title')) as title,
                   JSON_UNQUOTE(JSON_EXTRACT(schema_json, '$.description')) as description
            FROM forms
            WHERE is_active = 1
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Fetch a form by ID
     */
    public function getFormById($id) {
        $stmt = $this->db->prepare("SELECT * FROM forms WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $form = $stmt->fetch();
        
        if ($form) {
            $form['schema'] = json_decode($form['schema_json'], true);
            $form['title'] = $form['schema']['title'] ?? 'Untitled Form';
        }
        
        return $form;
    }

    /**
     * Toggle active status
     */
    public function toggleFormStatus($id, $isActive) {
        $stmt = $this->db->prepare("UPDATE forms SET is_active = :status WHERE id = :id");
        return $stmt->execute(['status' => $isActive ? 1 : 0, 'id' => $id]);
    }

    /**
     * Delete a form entirely (Cascades to applications)
     */
    public function deleteForm($id) {
        $stmt = $this->db->prepare("DELETE FROM forms WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }
}
