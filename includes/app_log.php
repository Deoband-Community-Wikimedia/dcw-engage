<?php
/**
 * DCW Engage - Application Log
 *
 * PHP's error_log() writes to whatever the server's `error_log` ini
 * directive points at. On this host we could not locate that destination
 * from any account path we tried (incident 2026-09-16), even though the
 * calls were confirmed to be firing — so failures had no visible trail.
 *
 * Rather than keep guessing server configuration, write our own log to an
 * explicit file we control: the engage app root, next to index.php. That
 * path is blocked from being served over HTTP by the *.log rule in
 * .htaccess.
 */
function app_log($message) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(__DIR__ . '/../app_errors.log', $line, FILE_APPEND | LOCK_EX);
}
