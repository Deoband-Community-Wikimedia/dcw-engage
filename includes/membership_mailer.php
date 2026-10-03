<?php
// includes/membership_mailer.php
// Emails for the membership review flow. Standalone so includes/mailer.php stays untouched.
// Same transport rules as Mailer: no PHPMailer or the placeholder host smtp.example.com means
// "log instead of send". Every method returns true only when a message really went out.
//
// Chapters: emails name the club the applicant applied to (MemberModel::CHAPTER_NAMES).
// DCW itself (the 'generic' chapter, or an unknown chapter) is not a separate club:
// it reads "Deoband Community Wikimedia" and is signed by the Membership Coordinators.
// Clubs are signed by the Core Organising Team of that club. See MemberModel::isDcw().
//
// Sign-off examples:
//   Best regards,                       Best regards,
//   Core Organising Team                Membership Coordinators
//   Wiki Club AMU                       Deoband Community Wikimedia

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../models/MemberModel.php';

class MembershipMailer {
    /** Full name of DCW, used wherever the applicant is joining DCW itself. */
    private const DCW_NAME = 'Deoband Community Wikimedia';

    /** Signing team for DCW itself (generic chapter / unknown chapter). */
    private const DCW_TEAM = 'Membership Coordinators';

    /** Signing team for a club (shown above the club's name). */
    private const CLUB_TEAM = 'Core Organising Team';

    /**
     * Optional reply-to address per chapter key, for when each club has its own
     * mailbox. Empty for now, so replies are not routed anywhere yet. Example:
     *   'amu' => 'amu@example.org'
     */
    private const CONTACT_EMAILS = [];

    /**
     * Display name of a separate club ('amu' -> 'Wiki Club AMU').
     * Returns null for DCW itself (generic chapter) or an unknown chapter.
     */
    private static function club($chapter) {
        if (!$chapter || MemberModel::isDcw($chapter)) {
            return null;
        }
        return MemberModel::CHAPTER_NAMES[$chapter] ?? null;
    }

    /** What the applicant is joining: the club's name, or Deoband Community Wikimedia. */
    private static function org($chapter) {
        return self::club($chapter) ?? self::DCW_NAME;
    }

    /** [team, organisation] for the sign-off. */
    private static function signoff($chapter) {
        return self::club($chapter)
            ? [self::CLUB_TEAM, self::club($chapter)]
            : [self::DCW_TEAM, self::DCW_NAME];
    }

    /** HTML sign-off block. */
    private static function signHtml($chapter) {
        [$team, $org] = self::signoff($chapter);
        return "<p style='margin-bottom:0;'>Best regards,<br><strong>"
             . htmlspecialchars($team, ENT_QUOTES, 'UTF-8') . "</strong><br>"
             . htmlspecialchars($org, ENT_QUOTES, 'UTF-8') . "</p>";
    }

    /** Plain-text sign-off block. */
    private static function signText($chapter) {
        [$team, $org] = self::signoff($chapter);
        return "Best regards,\n$team\n$org";
    }

    /**
     * Decision for the applicant. $decision: 'approved' | 'rejected'.
     * Approved: $memberId and $detail (expiry, 'Y-m-d H:i:s') are shown; only the day is displayed.
     * Rejected: $detail is the reason the coordinator wrote.
     * $chapter is the chapter key (MemberModel::chapterOf($app)), or null.
     */
    public static function sendDecision($email, $name, $decision, $memberId = null, $detail = '', $chapter = null) {
        $n = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
        $org = self::org($chapter);
        $safeOrg = htmlspecialchars($org, ENT_QUOTES, 'UTF-8');
        $hasDetail = ($detail !== '' && $detail !== null);

        if ($decision === 'approved') {
            $until = $hasDetail ? date('j M Y', strtotime($detail)) : '';
            $id = htmlspecialchars((string) $memberId, ENT_QUOTES, 'UTF-8');
            $subject = "Welcome to $org: your membership is confirmed";
            $inner = "
                <p>Hello <strong>$n</strong>,</p>
                <p>Thanks for your interest in joining <strong>$safeOrg</strong>. Good news: your membership is confirmed, and we're glad to have you with us!</p>
                <div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:14px 18px; margin:0 0 20px; line-height:1.7;'>
                    <strong>Member ID:</strong> $id" . ($until ? "<br><strong>Valid until:</strong> $until" : '') . "
                </div>
                <p>Keep your member ID handy: you'll need it when you renew.</p>
                " . self::signHtml($chapter);
            $alt = "Hello $name,\n\nThanks for your interest in joining $org. Good news: your membership is confirmed, and we're glad to have you with us!\n\nMember ID: $memberId"
                 . ($until ? "\nValid until: $until" : '') . "\n\nKeep your member ID handy: you'll need it when you renew.\n\n" . self::signText($chapter);
        } else {
            $subject = "Your $org membership application";
            $reason = $hasDetail
                ? "<p><strong>Reviewer notes:</strong><br>" . nl2br(htmlspecialchars((string) $detail, ENT_QUOTES, 'UTF-8')) . "</p>" : '';
            $inner = "
                <p>Hello <strong>$n</strong>,</p>
                <p>Thanks for your interest in joining <strong>$safeOrg</strong>, and for the time you put into your application. After review, we're not able to approve your membership at this time.</p>
                $reason
                <p>We appreciate your interest and hope our paths cross again.</p>
                " . self::signHtml($chapter);
            $alt = "Hello $name,\n\nThanks for your interest in joining $org, and for the time you put into your application. After review, we're not able to approve your membership at this time."
                 . ($hasDetail ? "\n\nReviewer notes:\n$detail" : '')
                 . "\n\nWe appreciate your interest and hope our paths cross again.\n\n" . self::signText($chapter);
        }

        return self::send($email, $name, $subject, $inner, $alt, $chapter);
    }

    /**
     * Sends an application back with the coordinator's message and a fresh link to edit and
     * resubmit it (the existing /resume/{token} page).
     * $chapter is the chapter key (MemberModel::chapterOf($app)), or null.
     */
    public static function sendInfoRequest($email, $name, $message, $token, $chapter = null) {
        $config = require __DIR__ . '/config.php';
        $url = $config['app']['url'] . '/resume/' . $token;
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $n = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
        $m = nl2br(htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8'));
        $org = self::org($chapter);
        $safeOrg = htmlspecialchars($org, ENT_QUOTES, 'UTF-8');

        $inner = "
            <p>Hello <strong>$n</strong>,</p>
            <p>Thanks for your interest in joining <strong>$safeOrg</strong>. We've looked at your application and need one more thing from you before we can decide:</p>
            <p style='border-left:3px solid #106b9a; padding-left:14px;'>$m</p>
            <div class='btn-wrapper'><a href='$safeUrl' class='btn'>Update my application</a></div>
            <p>If the button doesn't work, copy and paste this link into your browser:<br><br>
               <a href='$safeUrl' style='color: #106b9a; word-break: break-all;'>$safeUrl</a></p>
            <p>This link is private to you and will expire, so please don't share it.</p>
            " . self::signHtml($chapter);

        $alt = "Hello $name,\n\nThanks for your interest in joining $org. We've looked at your application and need one more thing from you before we can decide:\n\n$message\n\nUpdate your application here:\n$url\n\nThis link is private to you and will expire, so please don't share it.\n\n" . self::signText($chapter);

        return self::send($email, $name, "One more step for your $org membership application", $inner, $alt, $chapter);
    }

    private static function send($email, $name, $subject, $innerHtml, $altBody, $chapter = null) {
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

            // Same mailbox as always (that is what the SMTP account may send
            // as); only the display name changes, e.g. "Wiki Club AMU via DCW Engage".
            // DCW itself (club === null) is sent plainly as "DCW Engage".
            $club = self::club($chapter);
            $mail->setFrom($mailConfig['user'], $club ? $club . ' via DCW Engage' : 'DCW Engage');
            if ($chapter && !empty(self::CONTACT_EMAILS[$chapter])) {
                $mail->addReplyTo(self::CONTACT_EMAILS[$chapter], self::signoff($chapter)[0]);
            }
            $mail->addAddress($email, (string) $name);

            $mail->isHTML(true);
            $mail->Subject = $subject;   // plain text: never HTML-escape a subject line
            $mail->Body    = self::template($innerHtml, $club);
            $mail->AltBody = $altBody;

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Membership email could not be sent. Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }

    /**
     * Same look as the other DCW emails. $club (a separate club's name, or null for DCW)
     * is shown under the title. $innerHtml must already be escaped by the caller.
     */
    private static function template($innerHtml, $club = null) {
        $clubLine = $club
            ? "<div style='margin-top:6px; font-size:15px; opacity:0.9;'>" . htmlspecialchars($club, ENT_QUOTES, 'UTF-8') . "</div>"
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
                <div class='header'><h1>DCW Engage</h1>$clubLine</div>
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
