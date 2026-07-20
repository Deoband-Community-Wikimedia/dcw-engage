<?php
/**
 * DCW Engage - Initialization Bootstrap
 * 
 * Safely bootstraps the environment before rendering views or processing logic.
 */

// 1. Strict Session Management
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on', // True if HTTPS
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
    ]);
}

// 2. Check for Configuration
if (!file_exists(__DIR__ . '/config.php')) {
    die("Configuration missing. Please duplicate config.example.php to config.php and update credentials.");
}

// 3. Load Core Classes
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

// 4. Initialize Database Connection safely
// This will halt execution if DB is unreachable.
$db = DB::getInstance()->getConnection();
