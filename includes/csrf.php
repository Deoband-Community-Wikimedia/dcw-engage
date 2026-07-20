<?php
/**
 * DCW Engage - CSRF Protection
 * 
 * Generates and validates CSRF tokens for all state-changing forms.
 */

class CSRF {
    /**
     * Generate a new token and store in session.
     */
    public static function generate() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate a token provided in a request against the session token.
     */
    public static function validate($token) {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Helper to output a hidden input field for forms.
     */
    public static function getInputField() {
        $token = self::generate();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
