<?php
/**
 * DCW Engage Portal - Main Router
 * 
 * Strict Vanilla PHP 8.x + PDO implementation.
 * Ensures clean architecture, separating routing from business logic.
 */

// Initialize application (Session, DB, CSRF, Configuration).
// The session is started inside init.php so that every entry point gets the
// same hardened cookie flags. Starting one here first would win and quietly
// drop SameSite=Strict, which the admin login depends on.
require_once __DIR__ . '/includes/init.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = '/'; // Update this depending on subdirectory hosting

$route = str_replace($basePath, '/', $requestPath);
$route = rtrim($route, '/');
if ($route === '') $route = '/';

// Modern Dynamic Routing Engine
if ($route === '/' || $route === '/index.php') {
    require __DIR__ . '/views/home.php';
} elseif ($route === '/admin/login') {
    require __DIR__ . '/views/admin/login.php';
} elseif ($route === '/admin/logout') {
    require __DIR__ . '/views/admin/logout.php';
} elseif ($route === '/admin/forgot-password') {
    // Public: the whole point is that the visitor cannot sign in.
    require __DIR__ . '/views/admin/forgot_password.php';
} elseif ($route === '/admin/reset-password') {
    require __DIR__ . '/views/admin/reset_password.php';
} elseif ($route === '/admin/accept-invite') {
    // Public on purpose: the visitor has no account yet. The invite token in
    // the query string is what authorises this page.
    require __DIR__ . '/views/admin/accept_invite.php';
} elseif ($route === '/admin/team') {
    require __DIR__ . '/views/admin/team.php';
} elseif ($route === '/admin/audit') {
    require __DIR__ . '/views/admin/audit.php';
} elseif ($route === '/admin/dashboard') {
    require __DIR__ . '/views/admin/dashboard.php';
} elseif ($route === '/admin/form_manager') {
    require __DIR__ . '/views/admin/form_manager.php';
} elseif ($route === '/admin/builder') {
    require __DIR__ . '/views/admin/builder.php';
} elseif ($route === '/admin/preview_form') {
    require __DIR__ . '/views/admin/preview_form.php';
} elseif ($route === '/track') {
    // Public "check my application status" lookup (see #32) — a form's
    // slug is matched by the catch-all below, so this has to come before it.
    require __DIR__ . '/views/track.php';
} elseif (preg_match('/^\/resume\/([a-zA-Z0-9_-]+)$/', $route, $matches)) {
    $token = $matches[1];
    global $resumeToken;
    $resumeToken = $token;
    require __DIR__ . '/views/forms/resume.php';

// --- Reimbursements ---------------------------------------------------
// One global form now, not one per event — the applicant types the event
// name themselves inside the form. A literal route, so it must come before
// the catch-all dynamic form route below or '/reimbursement' would be
// treated as a form type of its own.
} elseif ($route === '/reimbursement') {
    require __DIR__ . '/views/reimbursement.php';

// Admin/organizer substance review — global too, not per event. Auth/role
// check happens inside the view itself (requireRole(['owner','organizer'])),
// same pattern as the rest of /admin.
} elseif ($route === '/admin/reimbursements/review') {
    require __DIR__ . '/views/admin/reimbursement_review.php';

// --- Finance (combined) -----------------------------------------------
// One queue for reimbursement payments AND internet support recharges /
// receipt checks, with a tab for each. Deliberately NOT under /admin — see
// the note in require_role.php about keeping this off any admin-facing
// navigation. Role check is inside the views: requireRole(['finance', 'owner']).
} elseif ($route === '/finance') {
    require __DIR__ . '/views/finance/queue.php';

// Combined index of closed requests (reimbursements paid + internet support
// closed), each linking to its own receipt PDF.
} elseif ($route === '/finance/closed') {
    require __DIR__ . '/views/finance/closed.php';

// Legacy finance URLs. The view files behind these are now thin stubs that
// set $financeDefaultTab and include queue.php / closed.php, so old
// bookmarks and links keep landing on the right tab.
} elseif ($route === '/finance/reimbursements') {
    require __DIR__ . '/views/finance/reimbursement_queue.php';

} elseif ($route === '/finance/reimbursements/paid') {
    require __DIR__ . '/views/finance/reimbursement_paid.php';

// Payment-confirmation PDF for one Paid request, generated on demand and
// streamed straight to the browser — nothing is saved to the server. Auth/
// role check happens inside the view (requireRole(['finance', 'owner'])).
} elseif (preg_match('/^\/finance\/reimbursements\/receipt\/(\d+)$/', $route, $matches)) {
    global $reimbursementReceiptId;
    $reimbursementReceiptId = (int) $matches[1];
    require __DIR__ . '/views/finance/reimbursement_receipt.php';

// --- Internet support -------------------------------------------------
// Volunteer request form (public, email-verified inside the view). A
// literal route, so like '/reimbursement' it has to come before the
// catch-all below or it would be treated as a form type.
} elseif ($route === '/internet-support') {
    require __DIR__ . '/views/internet_support.php';

// Support reviewers decide whether a request is reasonable. Auth/role check
// is inside the view: requireRole(['support_reviewer', 'owner']). Under
// /admin so Auth::isSafeNext() lets the login redirect bring people back.
} elseif ($route === '/admin/internet-review') {
    require __DIR__ . '/views/admin/internet_review.php';

// Legacy finance URL for internet support: now a stub that opens the
// combined queue on the Internet support tab.
} elseif ($route === '/finance/internet-support') {
    require __DIR__ . '/views/finance/internet_support.php';

// Legacy closed-requests URL: stub that opens /finance/closed on the
// Internet support tab.
} elseif ($route === '/finance/internet-support/closed') {
    require __DIR__ . '/views/finance/internet_closed.php';

// Receipt PDF for one Closed request, generated on demand and streamed to
// the browser; nothing is saved to the server. Role check is inside the view.
} elseif (preg_match('/^\/finance\/internet-support\/receipt\/(\d+)$/', $route, $matches)) {
    global $internetReceiptId;
    $internetReceiptId = (int) $matches[1];
    require __DIR__ . '/views/finance/internet_receipt.php';

} else {
    // Dynamic form routing
    // Extract the form type from the route (e.g., '/scholarship' -> 'scholarship')
    global $formType;
    $formType = trim($route, '/');
    
    // Pass control to the public form renderer
    // The renderer will handle checking the database for the schema and rendering it.
    require __DIR__ . '/views/forms/renderer.php';
}
