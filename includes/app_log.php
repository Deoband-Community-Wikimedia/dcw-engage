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
 *
 * Personal data and secrets never reach the file: every line goes through app_log_redact() first (#183).
 * Callers should still log IDs rather than emails; the redaction is a backstop, not a licence.
 */

/**
 * Remove personal data and secrets from a log line:
 *   email addresses             -> [email]
 *   link tokens (24+ hex chars) -> [token]   (resume, confirm, card, set-password, invite and reset links)
 *   verification codes          -> [code]    (shown as ABCD-EFGH)
 *   Indian mobile numbers       -> [phone]
 * Line breaks become " | " so one entry stays on one line and a value can't forge a new entry.
 */
function app_log_redact($message): string {
    $s = (string) $message;
    $s = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $s);
    $s = preg_replace('/\b[a-fA-F0-9]{24,}\b/', '[token]', $s);
    $s = preg_replace('/\b[A-Z0-9]{4}-[A-Z0-9]{4}\b/', '[code]', $s);
    $s = preg_replace('/(?<!\d)(?:\+?91[\s-]?)?[6-9]\d{4}[\s-]?\d{5}(?!\d)/', '[phone]', $s);
    return str_replace(["\r\n", "\r", "\n"], ' | ', $s);
}

/**
 * $redact = false is only for CoreMail's dev-mode lines when config app.debug is on (a developer's own machine,
 * where the verification code or link is needed to test a flow). Everything else is always redacted.
 */
function app_log($message, bool $redact = true) {
    $text = $redact ? app_log_redact($message) : str_replace(["
", "
", "
"], ' | ', (string) $message);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    $path = app_log_path();
    if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false && $path !== APP_LOG_DEFAULT) {
        // The configured file can't be written (missing folder, permissions): never lose the line.
        @file_put_contents(APP_LOG_DEFAULT, $line, FILE_APPEND | LOCK_EX);
    }
}

/** The app folder's own log, refused over HTTP by the *.log rule in .htaccess. */
define('APP_LOG_DEFAULT', __DIR__ . '/../app_errors.log');

/**
 * Where the log is written: config app.log_file when it is set (an absolute path, ideally outside
 * public_html so the web server can't serve it even if .htaccess stops applying), otherwise APP_LOG_DEFAULT.
 */
function app_log_path(): string {
    static $path = null;
    if ($path !== null) return $path;
    $path = APP_LOG_DEFAULT;
    $cfgFile = __DIR__ . '/config.php';
    if (is_file($cfgFile)) {
        $cfg = (static function (string $f) { return include $f; })($cfgFile);
        $custom = is_array($cfg) ? trim((string) ($cfg['app']['log_file'] ?? '')) : '';
        if ($custom !== '') $path = $custom;
    }
    return $path;
}

/**
 * Log an exception without its stack trace or arguments, and return a short reference to show the user
 * instead of the raw message, e.g. "Something went wrong (ref 3F9A1C2B)". The same reference is in the
 * log line, so a maintainer can find it.
 */
function app_log_exception(string $context, Throwable $e): string {
    $ref = strtoupper(bin2hex(random_bytes(4)));
    app_log("[ref $ref] $context: " . get_class($e) . ': ' . $e->getMessage()
        . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    return $ref;
}

/**
 * The message to show a user for a caught exception. Messages written for users (validation, "already
 * decided", "invalid file format": InvalidArgumentException or a plain Exception) are shown as they are.
 * System failures (RuntimeException, which includes every PDOException, and PHP Errors) are logged and
 * replaced with a generic message carrying the log reference, so SQL and file paths never reach the page.
 */
function engage_user_message(Throwable $e, string $context): string {
    if ($e instanceof RuntimeException || $e instanceof Error) {
        $ref = app_log_exception($context, $e);
        return "Something went wrong on our side. Please try again. If it keeps happening, quote reference $ref.";
    }
    return $e->getMessage();
}

/**
 * An exception whose message is written for the person using the page (e.g. "You have sent several
 * reports in the last hour"). engage_user_message() always shows it. Use it instead of RuntimeException
 * for anything a user should read, since RuntimeException messages are hidden as system failures.
 */
class UserFacingException extends Exception {}

/**
 * For errors that are caught and deliberately ignored (the page carries on without that piece).
 * They are still logged, so a real failure doesn't vanish, except "table doesn't exist" (SQLSTATE 42S02,
 * MySQL 1146), which is expected on an install where a migration hasn't been run yet.
 */
function app_log_ignored(string $context, Throwable $e): void {
    if ($e instanceof PDOException
        && ((string) $e->getCode() === '42S02' || strpos($e->getMessage(), '1146') !== false)) {
        return;
    }
    app_log("$context (ignored): " . get_class($e) . ': ' . $e->getMessage()
        . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
