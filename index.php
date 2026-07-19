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

// Include necessary bootstraps (to be implemented)
// require_once __DIR__ . '/includes/config.php';
// require_once __DIR__ . '/includes/db.php';
// require_once __DIR__ . '/includes/csrf.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = '/'; // Update this depending on subdirectory hosting

$route = str_replace($basePath, '/', $requestPath);
$route = rtrim($route, '/');
if ($route === '') $route = '/';

// Basic Routing Engine
switch ($route) {
    case '/':
    case '/index.php':
        require __DIR__ . '/views/home.php';
        break;
        
    case '/scholarship':
        // require __DIR__ . '/models/ScholarshipModel.php';
        echo "<h1>Scholarship Application</h1>";
        break;

    case '/fellowship':
        // require __DIR__ . '/models/FellowshipModel.php';
        echo "<h1>Heritage Lens Fellowship</h1>";
        break;

    case '/internship':
        echo "<h1>Internship Opportunities</h1>";
        break;
        
    case '/volunteer':
        echo "<h1>Volunteering Opportunities</h1>";
        break;

    default:
        // Handle Magic Links routing (e.g. /resume/TOKEN)
        if (preg_match('/^\/resume\/([a-zA-Z0-9_-]+)$/', $route, $matches)) {
            $token = $matches[1];
            // require __DIR__ . '/models/ResumeModel.php';
            echo "<h1>Resuming Application...</h1><p>Token: " . htmlspecialchars($token) . "</p>";
        } else {
            http_response_code(404);
            echo "<h1>404 - Page Not Found</h1>";
        }
        break;
}
