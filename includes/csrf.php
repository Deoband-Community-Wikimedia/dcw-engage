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

    /**
     * Single-use submit tokens.
     *
     * The CSRF token above lives for the whole session, which is what makes it
     * work across several tabs — but it also means the same form can be posted
     * twice. A double click fires two requests that are both perfectly valid,
     * and both do the work.
     *
     * These tokens are issued per form render and destroyed on first use, so
     * the second request finds nothing to consume and is dropped. Distinct
     * from CSRF: that proves the request came from our page, this proves it is
     * the first time we have seen it.
     */

    /** Most tokens to remember. Enough for several open tabs, bounded so the
     *  session cannot grow without limit. */
    private const MAX_SUBMIT_TOKENS = 25;

    public static function issueSubmitToken() {
        if (!isset($_SESSION['submit_tokens']) || !is_array($_SESSION['submit_tokens'])) {
            $_SESSION['submit_tokens'] = [];
        }

        $token = bin2hex(random_bytes(16));
        $_SESSION['submit_tokens'][] = $token;

        // Drop the oldest once we are over the cap.
        if (count($_SESSION['submit_tokens']) > self::MAX_SUBMIT_TOKENS) {
            $_SESSION['submit_tokens'] = array_slice(
                $_SESSION['submit_tokens'],
                -self::MAX_SUBMIT_TOKENS
            );
        }

        return $token;
    }

    /**
     * Returns true exactly once per issued token. Every later call with the
     * same value returns false, which is what makes a repeated submission a
     * no-op rather than a second write.
     */
    public static function consumeSubmitToken($token) {
        if (empty($token) || empty($_SESSION['submit_tokens'])) {
            return false;
        }

        $index = array_search($token, $_SESSION['submit_tokens'], true);

        if ($index === false) {
            return false;
        }

        unset($_SESSION['submit_tokens'][$index]);
        $_SESSION['submit_tokens'] = array_values($_SESSION['submit_tokens']);

        return true;
    }

    /** Hidden input carrying a fresh single-use token. */
    public static function getSubmitField() {
        $token = self::issueSubmitToken();
        return '<input type="hidden" name="submit_token" value="'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}
