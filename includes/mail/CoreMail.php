<?php
// includes/mail/CoreMail.php
//
// The one place that knows how to talk to SMTP and how an email looks.
// Every subject class (ApplicationMail, ReimbursementMail, MembershipMailer, ...) builds its
// own wording and hands it to CoreMail::send(); none of them touch PHPMailer.
//
// Note: ensure `composer install` has been run for PHPMailer.
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

class CoreMail {
    /** HTML-escape for anything that lands inside an email body. Never use on a Subject line. */
    public static function e($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    public static function config() {
        return require __DIR__ . '/../config.php';
    }

    /** Site base URL without a trailing slash. */
    public static function appUrl() {
        $config = self::config();
        return rtrim($config['app']['url'], '/');
    }

    /** Link to one internet support / reimbursement request on the member side (sign-in required). */
    public static function memberRequestUrl($trackingId) {
        return self::appUrl() . '/member/request?id=' . rawurlencode((string) $trackingId);
    }

    /**
     * Salutation for member emails: "Dear <strong>Name</strong>," or "Dear Member," when no name
     * is available. $html = false gives the plain-text version (no escaping, no tags).
     */
    public static function greeting($name, $html = true) {
        $name = trim((string) $name);
        if ($name === '') {
            return 'Dear Member,';
        }
        return $html ? 'Dear <strong>' . self::e($name) . '</strong>,' : "Dear $name,";
    }

    /**
     * Formats a database timestamp (stored in UTC) for people in India,
     * e.g. "28 Sep 2026, 6:55 PM IST". Always labelled, and uses explicit
     * zones so it doesn't depend on the server's default PHP time zone.
     */
    public static function formatExpiryIST($utcDatetime) {
        try {
            $dt = new DateTimeImmutable($utcDatetime, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M Y, g:i A') . ' IST';
        } catch (Exception $e) {
            return (string) $utcDatetime . ' UTC';
        }
    }

    /**
     * Sends one email. Options:
     *   to          string|array  recipient address(es)
     *   to_name     string        display name (single recipient only)
     *   subject     string        plain text, never HTML-escaped
     *   html        string        INNER html; already escaped by the caller
     *   alt         string        plain-text body
     *   from_name   string        optional. Display name on the From line (default 'DCW Engage').
     *                             The address is always the SMTP account's own mailbox.
     *   reply_to    array         optional. [address, name] for a Reply-To header.
     *   header_sub  string        optional. Small line under the title in the header
     *                             (e.g. a club name). Plain text; escaped here.
     *   dev_result  bool          what to return when nothing is really sent
     *                             (PHPMailer missing, or the placeholder host
     *                             smtp.example.com). Senders whose callers show
     *                             a fallback link when mail fails pass false;
     *                             the rest pass true. This matches how each
     *                             sender behaved before the split.
     *   dev_log     string        line written to the error log in that case
     *
     * Returns true only when a message really went out, except for the
     * dev_result case above.
     */
    public static function send(array $o) {
        $config = self::config();
        $mailConfig = $config['mail'];
        $to = (array) $o['to'];
        $devResult = (bool) ($o['dev_result'] ?? false);
        $devLog = $o['dev_log'] ?? ('Email to ' . implode(', ', $to) . ': ' . $o['subject']);

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer') || $mailConfig['host'] === 'smtp.example.com') {
            error_log("DEV MODE: $devLog");
            return $devResult;
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $mailConfig['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $mailConfig['user'];
            $mail->Password   = $mailConfig['pass'];
            $mail->Port       = $mailConfig['port'];
            // Encryption: port 465 uses implicit SSL, otherwise STARTTLS (e.g. 587).
            // Set 'secure' in the mail config to override.
            $secure = $mailConfig['secure'] ?? ((int) $mailConfig['port'] === 465 ? 'ssl' : 'tls');
            if (!empty($secure)) {
                $mail->SMTPSecure = $secure;
            }

            // Same mailbox as always (that is what the SMTP account may send as);
            // only the display name can change.
            $mail->setFrom($mailConfig['user'], $o['from_name'] ?? 'DCW Engage');
            if (!empty($o['reply_to'][0])) {
                $mail->addReplyTo($o['reply_to'][0], (string) ($o['reply_to'][1] ?? ''));
            }
            $name = count($to) === 1 ? (string) ($o['to_name'] ?? '') : '';
            foreach ($to as $address) {
                $mail->addAddress($address, $name);
            }

            $mail->isHTML(true);
            $mail->Subject = $o['subject'];   // plain text: never HTML-escape a subject line
            $mail->Body    = self::template($o['html'], $o['header_sub'] ?? null);
            $mail->AltBody = $o['alt'];

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email could not be sent ({$o['subject']}). Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }

    /** Standard call-to-action button. */
    public static function button($url, $label) {
        return "<div class='btn-wrapper'><a href='" . self::e($url) . "' class='btn'>" . self::e($label) . "</a></div>";
    }

    /**
     * The look shared by every DCW Engage email. $innerHtml must already be escaped by the caller.
     * $headerSub (plain text, optional) is shown under the title, e.g. a club name.
     */
    public static function template($innerHtml, $headerSub = null) {
        $subLine = ($headerSub !== null && $headerSub !== '')
            ? "<div style='margin-top:6px; font-size:15px; opacity:0.9;'>" . self::e($headerSub) . "</div>"
            : '';

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 0; color: #1e293b; }
                .email-container { max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
                .header { background-color: #106b9a; padding: 30px 20px; text-align: center; color: #ffffff; }
                .header h1 { margin: 0; font-size: 24px; font-weight: 600; letter-spacing: -0.5px; }
                .body-content { padding: 40px 30px; }
                .body-content p { font-size: 16px; line-height: 1.6; margin-bottom: 20px; }
                .btn-wrapper { text-align: center; margin: 30px 0; }
                .btn { display: inline-block; background-color: #106b9a; color: #ffffff !important; text-decoration: none; padding: 14px 28px; border-radius: 6px; font-size: 16px; font-weight: 600; }
                .footer { background-color: #f8fafc; padding: 20px; text-align: center; font-size: 13px; color: #64748b; border-top: 1px solid #e2e8f0; }
            </style>
        </head>
        <body>
            <div class='email-container'>
                <div class='header'><h1>DCW Engage</h1>$subLine</div>
                <div class='body-content'>$innerHtml</div>
                <div class='footer'>
                    &copy; " . date('Y') . " Deoband Community Wikimedia. All rights reserved.<br>
                    This is an automated message; please do not reply.
                </div>
            </div>
        </body>
        </html>";
    }
}
