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
", "", "
"], ' | ', (string) $message);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    @file_put_contents(__DIR__ . '/../app_errors.log', $line, FILE_APPEND | LOCK_EX);
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
