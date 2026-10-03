<?php
// includes/membership_mailer.php
// Emails for the membership review flow. Standalone so includes/mailer.php stays untouched.
// Same transport rules as Mailer: no PHPMailer or the placeholder host smtp.example.com means
// "log instead of send". Every method returns true only when a message really went out.

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

class MembershipMailer {
    /**
     * Decision for the applicant. $decision: 'approved' | 'rejected'.
     * Approved: $memberId and $detail (expiry, 'Y-m-d H:i:s') are shown; only the day is displayed.
     * Rejected: $detail is the reason the coordinator wrote.
     */
    public static function sendDecision($email, $name, $decision, $memberId = null, $detail = '') {
        $n = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');

        if ($decision === 'approved') {
            $until = ($detail !== '' && $detail !== null) ? date('j M Y', strtotime($detail)) : '';
            $id = htmlspecialchars((string) $memberId, ENT_QUOTES, 'UTF-8');
            $subject = 'Your DCW membership is confirmed';
            $inner = "
                <p>Hello <strong>$n</strong>,</p>
                <p>Your DCW membership has been approved. Welcome!</p>
                <p><strong>Member ID:</strong> $id" . ($until ? "<br><strong>Valid until:</strong> $until" : '') . "</p>
                <p style='margin-bottom:0;'>Keep your member ID: it is useful when you renew.</p>";
            $alt = "Hello $name,\n\nYour DCW membership has been approved. Welcome!\n\nMember ID: $memberId"
                 . ($until ? "\nValid until: $until" : '') . "\n\nKeep your member ID: it is useful when you renew.";
        } else {
            $subject = 'Update on your DCW membership application';
            $reason = ($detail !== '' && $detail !== null)
                ? "<p><strong>Reviewer notes:</strong><br>" . nl2br(htmlspecialchars((string) $detail, ENT_QUOTES, 'UTF-8')) . "</p>" : '';
            $inner = "
                <p>Hello <strong>$n</strong>,</p>
                <p>Thank you for applying. We are unable to approve your DCW membership application at this time.</p>
                $reason";
            $alt = "Hello $name,\n\nThank you for applying. We are unable to approve your DCW membership application at this time."
                 . ($detail ? "\n\nReviewer notes:\n$detail" : '');
        }

        return self::send($email, $name, $subject, $inner, $alt);
    }

    /**
     * Sends an application back with the coordinator's message and a fresh link to edit and
     * resubmit it (the existing /resume/{token} page).
     */
    public static function sendInfoRequest($email, $name, $message, $token) {
        $config = require __DIR__ . '/config.php';
        $url = $config['app']['url'] . '/resume/' . $token;
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $n = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
        $m = nl2br(htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8'));

        $inner = "
            <p>Hello <strong>$n</strong>,</p>
            <p>Thank you for applying to DCW. Before we can decide, a reviewer needs a little more from you:</p>
            <p style='border-left:3px solid #106b9a; padding-left:14px;'>$m</p>
            <div class='btn-wrapper'><a href='$safeUrl' class='btn'>Update my application</a></div>
            <p>If the button doesn't work, copy and paste this link into your browser:<br><br>
               <a href='$safeUrl' style='color: #106b9a; word-break: break-all;'>$safeUrl</a></p>
            <p style='margin-bottom:0;'>This link is private to you and will expire, so please do not share it.</p>";

        $alt = "Hello $name,\n\nThank you for applying to DCW. Before we can decide, a reviewer needs a little more from you:\n\n$message\n\nUpdate your application here:\n$url\n\nThis link is private to you and will expire.";

        return self::send($email, $name, 'We need a little more information for your DCW membership', $inner, $alt);
    }

    private static function send($email, $name, $subject, $innerHtml, $altBody) {
        $config = require __DIR__ . '/config.php';
        $mailConfig = $config['mail'];

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log("DEV MODE: Membership email to $email: $subject");
            return false;
        }
        if ($mailConfig['host'] === 'smtp.example.com') {
            error_log("DEV MODE: Membership email to $email: $subject\n$altBody");
            return false;
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $mailConfig['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $mailConfig['user'];
            $mail->Password   = $mailConfig['pass'];
            $mail->Port       = $mailConfig['port'];
            $secure = $mailConfig['secure'] ?? ((int)$mailConfig['port'] === 465 ? 'ssl' : 'tls');
            if (!empty($secure)) {
                $mail->SMTPSecure = $secure;
            }

            $mail->setFrom($mailConfig['user'], 'DCW Engage');
            $mail->addAddress($email, (string) $name);

            $mail->isHTML(true);
            $mail->Subject = $subject;   // plain text: never HTML-escape a subject line
            $mail->Body    = self::template($innerHtml);
            $mail->AltBody = $altBody;

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Membership email could not be sent. Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }

    /** Same look as the other DCW emails. $innerHtml must already be escaped by the caller. */
    private static function template($innerHtml) {
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
                <div class='header'><h1>DCW Engage</h1></div>
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
