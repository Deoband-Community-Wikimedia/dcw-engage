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
} elseif ($route === '/admin/report-problem') {
    require __DIR__ . '/views/admin/report_problem.php';
} elseif ($route === '/admin/tech-diagnostics') {
    require __DIR__ . '/views/admin/tech_diagnostics.php';

// --- Membership -------------------------------------------------------
// Public landing page: two dropdowns that send people to the right builder
// form (membership-generic, membership-amu, ... or the current renewal form).
// A literal route, so it must come before the catch-all dynamic form route
// below or 'membership' would be treated as a form slug of its own.
} elseif ($route === '/membership') {
    require __DIR__ . '/views/membership.php';

// --- Member login (Member ID + password) -------------------------------
// Public pages for members: sign in, sign out, and set or reset a password
// from an emailed one-time link. Separate from /admin: members and organizers
// have their own sessions. Literal routes (and the token pattern), so they must
// come before the catch-all dynamic form route below.
} elseif ($route === '/member/login') {
    require __DIR__ . '/views/member/login.php';
} elseif ($route === '/member/logout') {
    require __DIR__ . '/views/member/logout.php';
} elseif ($route === '/member/forgot') {
    require __DIR__ . '/views/member/forgot.php';
} elseif ($route === '/member/dashboard') {
    // Signed-in members only: the view calls MemberSession::requireLogin() itself.
    require __DIR__ . '/views/member/dashboard.php';
} elseif ($route === '/member/report-problem') {
    require __DIR__ . '/views/member/report_problem.php';

// Member support conversations (complaints, suggestions, questions).
// Signed-in members only; each view calls MemberSession::requireLogin().
} elseif ($route === '/member/talk') {
    require __DIR__ . '/views/member/ticket_new.php';
} elseif ($route === '/member/talk/ticket') {
    require __DIR__ . '/views/member/ticket.php';

} elseif (preg_match('/^\/member\/set-password\/([a-f0-9]{64})$/', $route, $matches)) {
    global $memberSetToken;
    $memberSetToken = $matches[1];
    require __DIR__ . '/views/member/set_password.php';

// Coordinator / reviewer queue for membership applications. Role check is
// inside the view: requireRole(['membership_coordinator', 'membership_reviewer',
// 'organizer', 'owner']); coordinators only ever see their assigned chapters.
// Under /admin so Auth::isSafeNext() lets the login redirect bring people back.
} elseif ($route === '/admin/membership-review') {
    require __DIR__ . '/views/admin/membership_review.php';

// Owner-only: which chapters each membership coordinator may see.
} elseif ($route === '/admin/membership-access') {
    require __DIR__ . '/views/admin/membership_access.php';

// DCW Support queue for member complaints, suggestions and questions.
// Role check is inside the view: requireRole(['member_support', 'owner']).
} elseif ($route === '/admin/member-support') {
    require __DIR__ . '/views/admin/member_support.php';

} elseif ($route === '/track') {
    // Public "check my application status" lookup (see #32) — a form's
    // slug is matched by the catch-all below, so this has to come before it.
    // Handles application (DCW-), reimbursement (RB-) and internet support (IS-) IDs.
    require __DIR__ . '/views/track.php';
} elseif (preg_match('/^\/resume\/([a-zA-Z0-9_-]+)$/', $route, $matches)) {
    $token = $matches[1];
    global $resumeToken;
    $resumeToken = $token;
    require __DIR__ . '/views/forms/resume.php';

// --- Unified support (public) -----------------------------------------
// One entry point for participants to ask for support: reimbursement and
// internet support today, more types later. The kind of support is chosen
// with ?type=internet|reimbursement (see views/support.php). Email
// verification happens once, inside the view. A literal route, so it must
// come before the catch-all dynamic form route below or '/support' would be
// treated as a form type of its own.
} elseif ($route === '/support') {
    require __DIR__ . '/views/support.php';

// Legacy public URLs. They no longer have pages of their own: send people to
// /support with the right type preselected. The query string is carried over
// so verification links already sitting in inboxes (?verify=...) keep working.
// 302 while rolling out; switch to 301 once everything has been confirmed.
} elseif ($route === '/reimbursement' || $route === '/internet-support') {
    $query = $_GET;
    $query['type'] = ($route === '/internet-support') ? 'internet' : 'reimbursement';
    header('Location: /support?' . http_build_query($query), true, 302);
    exit;

// --- Reimbursements (staff) -------------------------------------------
// Admin/organizer substance review — global, not per event. Auth/role
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

// --- Internet support (staff) -----------------------------------------
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
