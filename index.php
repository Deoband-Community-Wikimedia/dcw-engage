<?php
/**
 * DCW Engage Portal - Main Router
 * 
 * Strict Vanilla PHP 8.x + PDO implementation.
 * Ensures clean architecture, separating routing from business logic.
 */

// Start session securely
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'use_strict_mode' => true,
]);

// Initialize application (DB, Sessions, CSRF, Configuration)
require_once __DIR__ . '/includes/init.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = '/'; // Update this depending on subdirectory hosting

$route = str_replace($basePath, '/', $requestPath);
$route = rtrim($route, '/');
if ($route === '') $route = '/';

// Modern Dynamic Routing Engine
if ($route === '/' || $route === '/index.php') {
    require __DIR__ . '/views/home.php';
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
