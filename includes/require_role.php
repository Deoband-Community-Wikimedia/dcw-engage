<?php
/**
 * DCW Engage - Role guard for reimbursement admin/finance views
 *
 * Wired to your real Auth class and the real role names revealed by
 * InviteModel.php ('owner', 'organizer', 'finance') — an improvement over
 * the earlier placeholder, which guessed at 'admin'/'finance' and a raw
 * $_SESSION shape.
 *
 * REMAINING ASSUMPTION: I don't have includes/auth.php itself, only what
 * InviteModel.php's comments reveal about it — that Auth::check() exists
 * and "re-reads the row" (i.e. it queries admin_users fresh rather than
 * trusting a stale session array, which is exactly why a removed
 * organizer's session dies immediately). I'm assuming Auth::check()
 * returns an array with at least 'id', 'email', and 'role' keys, or null
 * when not logged in — the same shape InviteModel::redeem() returns and
 * admin_users itself has. If Auth::check()'s real return shape differs
 * (e.g. an object instead of an array, or different key names), send me
 * auth.php and I'll correct this.
 */

require_once __DIR__ . '/auth.php';

/**
 * @param string|array $allowedRoles One role, or an array of acceptable
 *   roles (e.g. requireRole(['owner', 'organizer']) for a page both should
 *   reach, or requireRole('finance') for finance-only).
 */
function requireRole($allowedRoles): void {
    $allowedRoles = (array) $allowedRoles;
    $user = Auth::check();

    if (!$user) {
        header('Location: /admin/login');
        exit;
    }

    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        die("You don't have access to this page.");
    }
}

function currentAdminIdentifier(): string {
    $user = Auth::check();
    return $user['email'] ?? 'unknown';
}
