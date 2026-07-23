<?php
/**
 * DCW Engage - Admin Authentication
 *
 * Session based login for the organizer workspace.
 * Every view under /admin must call Auth::requireLogin() before it renders
 * anything, including before it reads $_GET or touches the database.
 */

class Auth {
    /** Failed attempts from one session before a cooldown starts. */
    private const MAX_ATTEMPTS = 5;

    /** How long that cooldown lasts, in seconds. */
    private const LOCKOUT_SECONDS = 900;

    /**
     * A structurally valid bcrypt hash that no password matches.
     * Verified against when the email is unknown so that the response time
     * does not reveal which addresses belong to real organizers.
     */
    private const DUMMY_HASH = '$2y$10$OuT1..v46RCs9DMbVq3KAOKlt3.02ymH0mlNsAbFT1dmyo8h4gcc.';

    /**
     * Attempt a login. Returns null on success, or a message on failure.
     * The message is deliberately vague — it never distinguishes an unknown
     * email from a wrong password.
     */
    public static function attempt($email, $password) {
        $wait = self::lockoutRemaining();
        if ($wait > 0) {
            return "Too many failed attempts. Try again in " . ceil($wait / 60) . " minute(s).";
        }

        $db = DB::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, email, password_hash FROM admin_users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch();

        // Always run the comparison, even when there is no such admin.
        $passwordOk = password_verify($password, $admin['password_hash'] ?? self::DUMMY_HASH);

        if (!$admin || !$passwordOk) {
            self::recordFailure();
            return "Incorrect email or password.";
        }

        self::clearFailures();

        // New session id on privilege change, so a fixated cookie is useless.
        session_regenerate_id(true);

        $_SESSION['admin_id']    = (int) $admin['id'];
        $_SESSION['admin_email'] = $admin['email'];

        $db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = :id")
           ->execute(['id' => $admin['id']]);

        return null;
    }

    /**
     * Is there an authenticated admin on this session?
     */
    public static function check() {
        return !empty($_SESSION['admin_id']);
    }

    /**
     * Halt the request and send the visitor to the login screen unless they
     * are signed in. Call this at the very top of every admin view.
     */
    public static function requireLogin() {
        if (self::check()) {
            return;
        }

        $next = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: /admin/login' . (self::isSafeNext($next) ? '?next=' . urlencode($next) : ''));
        exit;
    }

    public static function id() {
        return $_SESSION['admin_id'] ?? null;
    }

    public static function email() {
        return $_SESSION['admin_email'] ?? null;
    }

    /**
     * Clear the session and its cookie completely.
     */
    public static function logout() {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Only ever redirect back to a path inside /admin on this host.
     * Rejects protocol relative values like //evil.com that the browser
     * would otherwise treat as an absolute URL.
     */
    public static function isSafeNext($next) {
        return is_string($next)
            && strpos($next, '/admin/') === 0
            && strpos($next, '//') !== 0
            && strpos($next, "\n") === false
            && strpos($next, "\r") === false;
    }

    private static function recordFailure() {
        $_SESSION['login_failures'] = ($_SESSION['login_failures'] ?? 0) + 1;
        if ($_SESSION['login_failures'] >= self::MAX_ATTEMPTS) {
            $_SESSION['login_locked_until'] = time() + self::LOCKOUT_SECONDS;
        }
    }

    private static function clearFailures() {
        unset($_SESSION['login_failures'], $_SESSION['login_locked_until']);
    }

    /**
     * Seconds left on the cooldown, or 0 if there is none.
     */
    private static function lockoutRemaining() {
        $until = $_SESSION['login_locked_until'] ?? 0;

        if ($until <= time()) {
            // Expired, so let them start fresh.
            if ($until) {
                self::clearFailures();
            }
            return 0;
        }

        return $until - time();
    }
}
