<?php
/**
 * DCW Engage - Role guard for reimbursement admin/finance views
 *
 * Uses Auth's real API (confirmed against includes/auth.php) rather than an
 * assumed return shape from Auth::check(). Auth::check() is a boolean —
 * true if the session is valid, false otherwise — it never carries role,
 * email, or id. Those come from Auth::role(), Auth::email(), and Auth::id()
 * respectively. The previous version read $user['role'] off that boolean,
 * which silently evaluated to null and made every requireRole() call fail
 * for every role, including owner.
 *
 * Auth::requireLogin() already does what the old code was trying to do
 * manually with Auth::check() (redirect to /admin/login if not signed in),
 * so this defers to it instead of duplicating that logic.
 */

require_once __DIR__ . '/auth.php';

/**
 * @param string|array $allowedRoles One role, or an array of acceptable
 *   roles (e.g. requireRole(['owner', 'organizer']) for a page both should
 *   reach, or requireRole('finance') for finance-only).
 */
function requireRole($allowedRoles): void {
    Auth::requireLogin();

    $allowedRoles = (array) $allowedRoles;

    if (!in_array(Auth::role(), $allowedRoles, true)) {
        http_response_code(403);
        die("You don't have access to this page.");
    }
}

function currentAdminIdentifier(): string {
    return Auth::email() ?? 'unknown';
}
