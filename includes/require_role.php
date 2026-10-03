<?php
/**
 * DCW Engage - Role guard for admin views
 *
 * Uses Auth's real API rather than an assumed return shape from
 * Auth::check(), which is a plain boolean: true if the session is valid,
 * false otherwise. It never carries role, email or id. Those come from
 * Auth::roles(), Auth::email() and Auth::id().
 *
 * Auth::requireLogin() already redirects to /admin/login when nobody is
 * signed in, so this defers to it instead of duplicating that logic.
 *
 * An account can hold several roles. Access is granted when it holds ANY of
 * the allowed ones.
 */

require_once __DIR__ . '/auth.php';

/**
 * @param string|array $allowedRoles One role, or an array of acceptable
 *   roles (e.g. requireRole(['owner', 'organizer']) for a page both should
 *   reach, or requireRole('finance') for finance-only).
 */
function requireRole($allowedRoles): void {
    Auth::requireLogin();

    if (!Auth::hasAnyRole($allowedRoles)) {
        http_response_code(403);
        die("You don't have access to this page. Please contact DCW maintainers.");
    }
}

function currentAdminIdentifier(): string {
    return Auth::email() ?? 'unknown';
}
