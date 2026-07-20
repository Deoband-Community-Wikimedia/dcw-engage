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
     * Validate incoming POST data against the JSON schema
     */
    public function validateSubmission($schema, $postData) {
        $errors = [];
        
        foreach ($schema['fields'] as $field) {
            $name = $field['name'];
            $isRequired = $field['required'] ?? false;
            
            // Check required
            if ($isRequired && empty($postData[$name])) {
                $errors[$name] = ($field['label'] ?? $name) . " is required.";
                continue;
            }
            
            // Further type validations (e.g. email, length) can be added here
        }
        
        return $errors;
    }
}
