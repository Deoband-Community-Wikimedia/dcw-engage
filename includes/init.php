<?php
/**
 * DCW Engage - Initialization Bootstrap
 * 
 * Safely bootstraps the environment before rendering views or processing logic.
 */

// 0. One timezone for the whole app. Deadlines (and everything else PHP writes
// or compares) are stored in UTC; they are converted to IST only for display
// and input, via FormModel::formatIst() / utcToIstInput() / istInputToTs().
date_default_timezone_set('UTC');

// 0b. Errors are logged, never shown (#183). Visitors see a short reference instead of file paths or SQL;
// the same reference is in app_errors.log. Uncaught exceptions and fatal errors both end up here.
require_once __DIR__ . '/app_log.php';   // app_log() / app_log_exception(), available on every page
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1');   // stack traces never carry argument values (passwords, tokens)

function engage_error_page(string $ref): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error (ref $ref). Details are in app_errors.log.\n");
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>Something went wrong - DCW Engage</title></head>'
       . '<body style="font-family:system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 20px;color:#1e293b;">'
       . '<h1 style="font-size:22px;">Something went wrong</h1>'
       . '<p>Please try again in a moment. If it keeps happening, tell the DCW team this reference: <strong>'
       . htmlspecialchars($ref) . '</strong></p><p><a href="/">Back to DCW Engage</a></p></body></html>';
}

set_exception_handler(function (Throwable $e): void {
    engage_error_page(app_log_exception('Uncaught', $e));
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $ref = strtoupper(bin2hex(random_bytes(4)));
        app_log("[ref $ref] Fatal: " . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')');
        engage_error_page($ref);
    }
});

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

// 3. Load Core Classes (app_log.php is loaded above, with the error handling)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/applicant_name.php';

// 4. Initialize Database Connection safely
// This will halt execution if DB is unreachable.
$db = DB::getInstance()->getConnection();
