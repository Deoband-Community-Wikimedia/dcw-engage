<?php
// includes/mail/MemberMessageMail.php
//
// A message typed by a support reviewer or owner on /admin/email-member and sent to one member,
// optionally with one PDF attached (for example a support letter).
//
// Rules this class keeps:
//   * No Reply-To is set, so no staff address ever reaches the member. The email ends with a fixed line telling
//     the member to sign in and write to DCW Support (/member/talk), with a button to it.
//   * The standard footer ("This is an automated message; please do not reply.") is kept.
//   * The attachment is read from the path given (PHP's own temporary upload file) while the email is sent.
//     Nothing is copied or stored by this class.
//   * Returns true only when a message really went out. dev_result is false, so the caller tells the reviewer it
//     was not sent in dev mode instead of reporting a send that never happened.

require_once __DIR__ . '/CoreMail.php';

class MemberMessageMail {
    /**
     * ($email, $name, $subject, $message, $attachment = null)
     *   $subject, $message  typed by the reviewer; escaped here (the subject is never HTML-escaped, but control
     *                       characters are removed so it cannot carry extra headers)
     *   $attachment         null, or ['path' => temp file, 'name' => file name shown to the member, 'type' => MIME type]
     */
    public static function send($email, $name, $subject, $message, ?array $attachment = null) {
        $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $subject));
        $message = trim(str_replace(["\r\n", "\r"], "\n", (string) $message));
        $supportUrl = CoreMail::appUrl() . '/member/talk';

        $fileHtml = '';
        $filePlain = '';
        if ($attachment !== null) {
            $fileName = (string) ($attachment['name'] ?? 'document.pdf');
            $fileHtml  = "<p style='font-size:14px; color:#475569;'><strong>Attached:</strong> " . CoreMail::e($fileName) . "</p>";
            $filePlain = "Attached: $fileName\n\n";
        }

        $html = '<p>' . CoreMail::greeting($name) . '</p>'
              . '<p>' . nl2br(CoreMail::e($message)) . '</p>'
              . $fileHtml
              . "<p style='font-size:14px; color:#64748b;'>This message was sent to you by the DCW team through DCW Engage.</p>"
              . '<p>To request further assistance, please sign in to your DCW account and write to DCW Support.</p>'
              . CoreMail::button($supportUrl, 'Write to DCW Support')
              . "<p style='font-size:14px; color:#64748b;'>If the button does not work, copy this link into your browser:<br>"
              . "<a href='" . CoreMail::e($supportUrl) . "' style='color:#106b9a; word-break:break-all;'>" . CoreMail::e($supportUrl) . '</a></p>';

        $alt = CoreMail::greeting($name, false) . "\n\n"
             . $message . "\n\n"
             . $filePlain
             . "This message was sent to you by the DCW team through DCW Engage.\n\n"
             . "To request further assistance, please sign in to your DCW account and write to DCW Support:\n$supportUrl\n";

        $opts = [
            'to'         => $email,
            'to_name'    => (string) $name,
            'subject'    => $subject,
            'html'       => $html,
            'alt'        => $alt,
            'dev_result' => false,   // dev mode sends nothing, so the reviewer is told it was not sent
            'dev_log'    => "Member message to $email: $subject" . ($attachment !== null ? ' (with a PDF)' : ''),
        ];
        if ($attachment !== null) {
            $opts['attachments'] = [[
                'path' => (string) $attachment['path'],
                'name' => (string) ($attachment['name'] ?? 'document.pdf'),
                'type' => (string) ($attachment['type'] ?? 'application/pdf'),
            ]];
        }
        return CoreMail::send($opts);
    }
}
