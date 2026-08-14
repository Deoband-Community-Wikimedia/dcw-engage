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
} elseif (preg_match('/^\/resume\/([a-zA-Z0-9_-]+)$/', $route, $matches)) {
    $token = $matches[1];
    global $resumeToken;
    $resumeToken = $token;
    require __DIR__ . '/views/forms/resume.php';
} else {
    // Dynamic form routing
    // Extract the form type from the route (e.g., '/scholarship' -> 'scholarship')
    global $formType;
    $formType = trim($route, '/');
    
    // Pass control to the public form renderer
    // The renderer will handle checking the database for the schema and rendering it.
    require __DIR__ . '/views/forms/renderer.php';
}
