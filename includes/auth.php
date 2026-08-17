<?php
/**
 * DCW Engage - Admin Authentication
 *
 * Session based login for the organizer workspace.
 * Every view under /admin must call Auth::requireLogin() before it renders
 * anything, including before it reads $_GET or touches the database.
 */

class Auth {
    /**
     * Minimum password length, and the single place it is defined.
     *
     * It used to be hard-coded in bin/create_admin.php and again in
     * InviteModel, which is how two account-creation paths end up disagreeing
     * with each other. Everything now reads this.
     */
    public const MIN_PASSWORD_LENGTH = 8;

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
        $stmt = $db->prepare("SELECT id, email, password_hash, role, password_changed_at FROM admin_users WHERE email = :email");
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
        $_SESSION['admin_role']  = $admin['role'] ?? 'organizer';
        // Remembered so a later password change can invalidate this session.
        $_SESSION['admin_pw_stamp'] = $admin['password_changed_at'];

        $db->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = :id")
           ->execute(['id' => $admin['id']]);

        return null;
    }

    /**
     * Is there an authenticated admin on this session?
     *
     * Also confirms the account has not changed password since this session
     * started. PHP has no way to enumerate and delete another user's session
     * files, so instead each session carries the password_changed_at value it
     * was issued under, and a mismatch ends it here. That is what makes
     * "reset my password" actually evict a session somebody else is holding.
     */
    public static function check() {
        if (empty($_SESSION['admin_id'])) {
            return false;
        }

        // One indexed lookup per admin request. The workspace has a handful of
        // accounts, so this is far cheaper than the alternative of leaving a
        // compromised session alive.
        $stmt = DB::getInstance()->getConnection()
            ->prepare("SELECT password_changed_at FROM admin_users WHERE id = :id");
        $stmt->execute(['id' => $_SESSION['admin_id']]);
        $row = $stmt->fetch();

        // Account deleted underneath the session.
        if (!$row) {
            self::logout();
            return false;
        }

        $current = $row['password_changed_at'];
        $issued  = $_SESSION['admin_pw_stamp'] ?? null;

        if ($current !== $issued) {
            self::logout();
            return false;
        }

        return true;
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
     * The signed-in organizer's role. Anything unrecognised is treated as the
     * lower privilege level, so a session written before roles existed cannot
     * accidentally grant team management.
     */
    public static function role() {
        $role = $_SESSION['admin_role'] ?? 'organizer';
        return $role === 'owner' ? 'owner' : 'organizer';
    }

    public static function isOwner() {
        return self::role() === 'owner';
    }

    /**
     * Gate for team management. Call at the top of any view that can create
     * or revoke access, after requireLogin().
     *
     * A signed-in organizer who is not an owner gets 403 rather than a
     * redirect to the login screen — they are authenticated, just not
     * authorised, and bouncing them to a form they have already passed reads
     * as a broken page.
     */
    public static function requireOwner() {
        self::requireLogin();

        if (self::isOwner()) {
            return;
        }

        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
           . '<link rel="icon" type="image/png" href="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
           . '<meta name="robots" content="noindex, nofollow">'
           . '<title>Not allowed - DCW Engage</title></head><body '
           . 'style="font-family: Inter, -apple-system, sans-serif; background:#f8fafc; color:#1e293b; '
           . 'display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:20px;">'
           . '<div style="max-width:420px; text-align:center;">'
           . '<h1 style="color:#106b9a; font-size:20px; margin:0 0 8px;">Not allowed</h1>'
           . '<p style="color:#64748b; font-size:14px; line-height:1.6; margin:0 0 20px;">'
           . 'Managing the organizer team is limited to workspace owners. '
           . 'Ask an owner if you need access.</p>'
           . '<a href="/admin/dashboard" style="color:#106b9a; font-size:14px;">Back to workspace</a>'
           . '</div></body></html>';
        exit;
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
