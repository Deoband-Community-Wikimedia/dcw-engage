<?php
// includes/mail/MemberMessageMail.php
//
// A message typed by a support reviewer or owner on /admin/email-member and sent to one member,
// optionally with one PDF attached (for example a support letter).
//
// Rules this class keeps:
//   * No Reply-To is set, so no staff address ever reaches the member. The email ends with a support block telling
//     the member to sign in and write to DCW Support (/member/talk), with a button to it.
//   * The standard footer ("This is an automated message; please do not reply.") is added by CoreMail, as before.
//   * The attachment is read from the path given (PHP's own temporary upload file) while the email is sent.
//     Nothing is copied or stored by this class.
//   * The attachment is always named "<Member name> - <Document name>.pdf". The uploaded file's own name is ignored.
//   * Returns true only when a message really went out. dev_result is false, so the caller tells the reviewer it
//     was not sent in dev mode instead of reporting a send that never happened.

require_once __DIR__ . '/CoreMail.php';

class MemberMessageMail {

    const BRAND   = '#106b9a';
    const TEXT    = '#1e293b';
    const MUTED   = '#64748b';
    const BORDER  = '#e2e8f0';
    const PANEL   = '#f1f5f9';
    const PAGE_BG = '#f4f6f8';

    /** Choices for the admin dropdown: key => label. "other" lets the reviewer type a name. */
    public static function documentTypes(): array {
        return [
            'support_letter' => 'Support Letter',
            'certificate'    => 'Certificate',
            'statement'      => 'Statement',
            'other'          => 'Other',
        ];
    }

    /**
     * ($email, $name, $subject, $message, $attachment = null)
     *   $subject, $message  typed by the reviewer; escaped here (the subject is never HTML-escaped, but control
     *                       characters are removed so it cannot carry extra headers)
     *   $attachment         null, or [
     *                         'path'      => temp file,
     *                         'doc_type'  => key from documentTypes() (unknown keys fall back to "Document"),
     *                         'doc_other' => text typed when doc_type is "other" (optional),
     *                         'type'      => MIME type (optional, default application/pdf)
     *                       ]
     */
    public static function send($email, $name, $subject, $message, ?array $attachment = null) {
        $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $subject));
        $message = trim(str_replace(["\r\n", "\r"], "\n", (string) $message));
        $supportUrl = CoreMail::appUrl() . '/member/talk';

        // ---- Attachment: label, file name, sentence -------------------------------------------------------
        $fileName = '';
        $sentence = '';
        if ($attachment !== null) {
            $label    = self::documentLabel($attachment);
            $fileName = self::fileName((string) $name, $label);
            $sentence = 'Please find your ' . self::sentenceLabel($attachment, $label) . ' attached.';
        }

        // ---- HTML -------------------------------------------------------------------------------------------
        $preheader = self::preheader($message);

        $messageCard =
            "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='margin:0 0 24px 0;'><tr>"
          . "<td style='border-left:4px solid " . self::BRAND . "; background:#ffffff; padding:4px 0 4px 18px; "
          . "font-size:16px; line-height:1.65; color:" . self::TEXT . ";'>"
          . nl2br(CoreMail::e($message))
          . '</td></tr></table>';

        $attachmentBlock = '';
        if ($attachment !== null) {
            $attachmentBlock =
                "<p style='margin:0 0 12px 0; font-size:16px; line-height:1.6; color:" . self::TEXT . ";'>"
              . CoreMail::e($sentence) . '</p>'
              . "<table role='presentation' cellpadding='0' cellspacing='0' style='margin:0 0 28px 0; "
              . "border:1px solid " . self::BORDER . "; border-radius:8px; background:#ffffff;'><tr>"
              . "<td style='padding:12px 14px;' aria-hidden='true'>"
              . "<div style='background:" . self::BRAND . "; color:#ffffff; font-size:11px; font-weight:bold; "
              . "letter-spacing:0.5px; border-radius:4px; padding:6px 8px;'>PDF</div></td>"
              . "<td style='padding:12px 18px 12px 0; font-size:14px; color:" . self::TEXT . "; word-break:break-word;'>"
              . CoreMail::e($fileName) . '</td></tr></table>';
        }

        $supportPanel =
            "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' style='margin:8px 0 0 0;'><tr>"
          . "<td style='background:" . self::PANEL . "; border-radius:10px; padding:24px; text-align:center;'>"
          . "<p style='margin:0 0 6px 0; font-size:17px; font-weight:bold; color:" . self::TEXT . ";'>Need more help?</p>"
          . "<p style='margin:0 0 18px 0; font-size:15px; line-height:1.55; color:" . self::TEXT . ";'>"
          . 'Sign in to your DCW account and write to DCW Support.</p>'
          . CoreMail::button($supportUrl, 'Write to DCW Support')
          . '</td></tr></table>';

        $finePrint =
            "<p style='margin:24px 0 0 0; font-size:13px; line-height:1.6; color:" . self::MUTED . "; text-align:center;'>"
          . 'This message was sent to you by the DCW team through DCW Engage.<br>'
          . "Button not working? Paste this link into your browser:<br>"
          . "<a href='" . CoreMail::e($supportUrl) . "' style='color:" . self::BRAND . "; word-break:break-all;'>"
          . CoreMail::e($supportUrl) . '</a></p>';

        $body =
            "<p style='margin:0 0 20px 0; font-size:18px; line-height:1.5; color:" . self::TEXT . ";'>"
          . CoreMail::greeting($name) . '</p>'
          . $messageCard
          . $attachmentBlock
          . "<hr style='border:0; border-top:1px solid " . self::BORDER . "; margin:0 0 24px 0;'>"
          . $supportPanel
          . $finePrint;

        $html = self::shell($body, $preheader);

        // ---- Plain text -------------------------------------------------------------------------------------
        $rule = str_repeat('-', 40);
        $alt = CoreMail::greeting($name, false) . "\n\n"
             . $message . "\n\n"
             . ($attachment !== null ? "$sentence ($fileName)\n\n" : '')
             . "$rule\n\n"
             . "Need more help?\n"
             . "Sign in to your DCW account and write to DCW Support:\n$supportUrl\n\n"
             . "$rule\n"
             . "This message was sent to you by the DCW team through DCW Engage.\n";

        $opts = [
            'to'         => $email,
            'to_name'    => (string) $name,
            'subject'    => $subject,
            'html'       => $html,
            'alt'        => $alt,
            'dev_result' => false,   // dev mode sends nothing, so the reviewer is told it was not sent
            'dev_log'    => "Member message to $email: $subject" . ($attachment !== null ? " (with $fileName)" : ''),
        ];
        if ($attachment !== null) {
            $opts['attachments'] = [[
                'path' => (string) $attachment['path'],
                'name' => $fileName,
                'type' => (string) ($attachment['type'] ?? 'application/pdf'),
            ]];
        }
        return CoreMail::send($opts);
    }

    // ---- Layout shell: brand band, grey page, 600px card. If CoreMail already wraps content in its own
    // ---- layout, make this method return only $body (plus the hidden preheader).
    private static function shell(string $body, string $preheader): string {
        return
            "<div style='display:none; max-height:0; overflow:hidden; opacity:0; font-size:1px; line-height:1px;'>"
          . CoreMail::e($preheader) . '</div>'
          . "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' "
          . "style='background:" . self::PAGE_BG . "; padding:24px 12px;'><tr><td align='center'>"
          . "<table role='presentation' width='100%' cellpadding='0' cellspacing='0' "
          . "style='max-width:600px; background:#ffffff; border-radius:12px; overflow:hidden; "
          . "font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,Helvetica,Arial,sans-serif;'>"
          . "<tr><td style='background:" . self::BRAND . "; padding:18px 32px; color:#ffffff; font-size:16px; "
          . "font-weight:bold; letter-spacing:0.3px;'>DCW Engage</td></tr>"
          . "<tr><td style='padding:32px;'>" . $body . '</td></tr>'
          . '</table></td></tr></table>';
    }

    // ---- Helpers --------------------------------------------------------------------------------------------

    /** Document name for the file name and the row: from the dropdown, or typed text for "other". */
    private static function documentLabel(array $attachment): string {
        $types = self::documentTypes();
        $key   = (string) ($attachment['doc_type'] ?? '');
        if ($key === 'other') {
            $typed = self::cleanPart((string) ($attachment['doc_other'] ?? ''));
            return $typed !== '' ? $typed : 'Document';
        }
        return $types[$key] ?? 'Document';
    }

    /** Presets are lower-cased in the sentence ("support letter"); typed text is left as typed. */
    private static function sentenceLabel(array $attachment, string $label): string {
        $isPreset = isset(self::documentTypes()[(string) ($attachment['doc_type'] ?? '')])
                    && ($attachment['doc_type'] ?? '') !== 'other';
        return $isPreset ? mb_strtolower($label) : $label;
    }

    /** "<Member name> - <Document name>.pdf", cleaned, at most 80 characters. */
    private static function fileName(string $memberName, string $label): string {
        $label  = preg_replace('/\.pdf$/iu', '', self::cleanPart($label));
        $label  = $label !== '' ? $label : 'Document';
        $member = self::cleanPart($memberName);

        $max = 80 - 4;   // room for ".pdf"
        if ($member === '') {
            $base = mb_substr($label, 0, $max);
        } else {
            $room = $max - mb_strlen(' - ' . $label);
            if ($room < 8) {                        // very long document name: keep a short member name
                $room  = 8;
                $label = mb_substr($label, 0, $max - 3 - $room);
            }
            $base = rtrim(mb_substr($member, 0, $room)) . ' - ' . $label;
        }
        return trim($base) . '.pdf';
    }

    /** Remove characters that break file names or headers; keep accents and non-Latin letters. */
    private static function cleanPart(string $s): string {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        $s = preg_replace('#[\\\\/:*?"<>|\']+#u', '', $s);
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** First line of the message, shortened, for the inbox preview. */
    private static function preheader(string $message): string {
        $first = trim((string) preg_replace('/\s+/u', ' ', $message));
        return mb_strlen($first) > 110 ? rtrim(mb_substr($first, 0, 110)) . '…' : $first;
    }
}
