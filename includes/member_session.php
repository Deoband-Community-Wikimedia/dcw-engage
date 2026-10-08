<?php
/**
 * DCW Engage - Member session (Member ID + password login).
 *
 * The member-side counterpart of Auth (which is for organizers). It uses its own session keys,
 * so a member login and an organizer login never interfere, and logging out of one leaves the
 * other alone. Do NOT call Auth::logout() for members: it destroys the whole session.
 *
 * Gate a page with MemberSession::requireLogin() (see member/support.php, member/request.php).
 * MemberAuthModel checks credentials; this class only keeps the session.
 */
require_once __DIR__ . '/../models/MemberAuthModel.php';

class MemberSession {
    /** Member row loaded by check() for this request. */
    private static $row = null;

    /** Memberships held under the signed-in Member ID, cached for this request. */
    private static $held = null;

    /**
     * Start a member session after MemberAuthModel::authenticate() returned 'ok'.
     * The session id changes on login so a fixated cookie is useless. The password stamp is
     * remembered so that changing the password ends sessions that were opened before it.
     */
    public static function login(array $member): void {
        session_regenerate_id(true);
        $_SESSION['member_row_id']  = (int) $member['id'];
        $_SESSION['member_pw_stamp'] = $member['password_set_at'] ?? null;
        self::$row = null;
        self::$held = null;
    }

    /** Is a member signed in on this session? Re-reads the member row on each request. */
    public static function check(): bool {
        if (self::$row !== null) return true;
        if (empty($_SESSION['member_row_id'])) return false;

        $st = DB::getInstance()->getConnection()->prepare(
            'SELECT id, member_id, full_name, email, chapter, status, expires_at, password_set_at FROM members WHERE id = :id');
        $st->execute(['id' => $_SESSION['member_row_id']]);
        $row = $st->fetch();

        // Member removed, or the password changed since this session began.
        if (!$row || ($row['password_set_at'] ?? null) !== ($_SESSION['member_pw_stamp'] ?? null)) {
            self::logout();
            return false;
        }
        self::$row = $row;
        return true;
    }

    /** The signed-in member's row, or null. */
    public static function current(): ?array {
        return self::check() ? self::$row : null;
    }

    /**
     * Memberships the signed-in Member ID already has, keyed by chapter key:
     *   ['amu' => ['status' => 'approved', 'expires_at' => '2027-03-31'], ...]
     * Rejected rows are ignored (that application never took effect). Expired and pending rows
     * DO count: an expired member should renew, and a pending one should not apply twice.
     * Empty array when nobody is signed in. Used by /membership and by the form renderer.
     */
    public static function heldChapters(): array {
        if (self::$held !== null) return self::$held;
        $m = self::current();
        if ($m === null) return self::$held = [];

        $held = [];
        try {
            $st = DB::getInstance()->getConnection()->prepare(
                "SELECT chapter, status, expires_at FROM members
                 WHERE member_id = :mid AND status <> 'rejected'");
            $st->execute(['mid' => $m['member_id']]);
            foreach ($st->fetchAll() as $r) {
                $held[(string) $r['chapter']] = [
                    'status'     => (string) $r['status'],
                    'expires_at' => $r['expires_at'] ?? null,
                ];
            }
        } catch (Throwable $ex) {
            // Fall back to the row that is loaded, so the page still works.
            $held[(string) $m['chapter']] = [
                'status'     => (string) $m['status'],
                'expires_at' => $m['expires_at'] ?? null,
            ];
        }
        return self::$held = $held;
    }

    /**
     * Active = approved and not past the expiry date, on ANY membership under this Member ID
     * (the Generic Community or a club). Use this, not just check(), for anything that is only
     * for current members (such as support requests).
     */
    public static function isActive(): bool {
        $m = self::current();
        return $m !== null && (MemberAuthModel::isActive($m)
            || (new MemberAuthModel())->anyActive((string) $m['member_id']));
    }

    /** Send the visitor to the member login unless they are signed in. Call at the top of a gated page. */
    public static function requireLogin(): void {
        if (self::check()) return;
        $next = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: /member/login' . (self::isSafeNext($next) ? '?next=' . urlencode($next) : ''));
        exit;
    }

    /** End the member session only (organizer login, if any, stays). */
    public static function logout(): void {
        unset($_SESSION['member_row_id'], $_SESSION['member_pw_stamp']);
        self::$row = null;
        self::$held = null;
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    }

    /**
     * Dashboard
     */
    public static function isSafeNext($next): bool {
        if (!is_string($next)) return false;
        if (strpos($next, "\n") !== false || strpos($next, "\r") !== false) return false;
        if (strpos($next, '\\') !== false) return false;          // /\evil.com is treated as //evil.com by browsers
        if (strpos($next, '//') === 0) return false;
        if (preg_match('#^/member/(login|logout)(/|\?|$)#', $next) === 1) return false;
        return preg_match('#^/(support|member(/[A-Za-z0-9_\-]+)*)(/|\?|$)#', $next) === 1;
    }
}
