<?php
// includes/mail/ApplicationMail.php
// Emails for event applications (tracking IDs start "DCW-"): verification code,
// magic link, confirmation, status updates, "sent back for more information",
// committee-written decision letters, and the organizer alert.
//
// Emails to the applicant name the event only (CoreMail::eventTitle()), e.g. "Train-a-Wikipedian"
// rather than "Scholarship Application Form: Train-a-Wikipedian, 4th Edition". The organizer
// alert and the dev logs keep the full form title.
require_once __DIR__ . '/CoreMail.php';
require_once __DIR__ . '/../wikitext.php';

class ApplicationMail {
    /** Name as it should appear in a greeting: age removed, "Applicant" when nothing usable is left. */
    private static function displayName($name) {
        $clean = CoreMail::cleanName($name);
        return $clean !== '' ? $clean : 'Applicant';
    }

    /**
     * The verification code that opens an application form (#67). Callers must not let the result change what the visitor sees.
     * $code is the raw 8-character code from EmailVerificationModel::request(); it is shown as ABCD-EFGH.
     * $name is the applicant's name from the gate form; when empty the greeting falls back to "Applicant".
     * The subject deliberately does not contain the code.
     */
    public static function emailVerification($email, $formTitle, $code, $expiresAt, $name = '') {
        $e = fn($s) => CoreMail::e($s);
        $expiresTime = CoreMail::formatExpiryIST($expiresAt);
        $shownCode = substr((string) $code, 0, 4) . '-' . substr((string) $code, 4);
        // Event name only; form titles are organizer-typed, so never drop them into HTML raw.
        $formTitle = CoreMail::eventTitle($formTitle);
        $safeTitle = $e($formTitle);

        // Greet by name when we have one; fall back to the generic greeting otherwise.
        $name = CoreMail::cleanName($name);   // drops an age typed after the name
        $greetName = $name !== '' ? $name : 'Applicant';
        $greetHtml = $name !== '' ? '<strong>' . $e($name) . '</strong>' : 'Applicant';

        $inner = "
            <p>Dear $greetHtml,</p>
            <p>Please use the verification code below to initiate your application for <strong>$safeTitle</strong>. Enter it on the page where you provided your email address.</p>
            <div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:16px 18px; margin:0 0 20px; text-align:center;'>
                <span style='font-family:Menlo,Consolas,monospace; font-size:28px; font-weight:bold; letter-spacing:4px; color:#0f172a;'>" . $e($shownCode) . "</span>
            </div>
            <p><strong>Note: This code is valid until $expiresTime</strong> and can be used only once. Please do not share it with anyone.</p>
            <p style='margin-bottom:0;'>If you did not initiate this request, you may safely ignore this email. No further action will be taken and no application will be created.</p>";

        $alt = "Dear $greetName,\n\nPlease use the verification code below to initiate your application for $formTitle. Enter it on the page where you provided your email address.\n\nVerification code: $shownCode\n\nNote: This code is valid until $expiresTime and can be used only once. Please do not share it with anyone.\n\nIf you did not initiate this request, you may safely ignore this email. No further action will be taken.\n\nDeoband Community Wikimedia";

        $mail = [
            'to' => $email, 'subject' => 'Your verification code - DCW Engage',
            'html' => $inner, 'alt' => $alt,
            // Dev mode sends nothing, so the code is written to the dev log only so it can be tested.
            'dev_result' => false, 'dev_log' => "Email verification code for $email: $shownCode",
        ];
        if ($name !== '') {
            $mail['to_name'] = $name;   // shows the person's name on the To: line
        }

        return CoreMail::send($mail);
    }

    /** Magic link so an applicant can return and edit a saved application. */
    public static function magicLink($email, $applicantName, $token) {
        $e = fn($s) => CoreMail::e($s);
        $appUrl = CoreMail::appUrl() . '/resume/' . $token;
        $url = $e($appUrl);
        $applicantName = self::displayName($applicantName);   // no age, "Applicant" if empty
        $name = $e($applicantName);

        $inner = "
            <p>Dear <strong>$name</strong>,</p>
            <p>Your application draft has been saved successfully. We have generated a secure access link for you to resume and update your application prior to the deadline.</p>
            <div class='btn-wrapper'><a href='$url' class='btn'>Access My Application</a></div>
            <p>If the button above does not work, please copy and paste the following link into your browser:<br><br><a href='$url' style='color: #106b9a; word-break: break-all;'>$url</a></p>
            <p style='margin-bottom:0;'>For security purposes, this link will expire automatically. Kindly do not share this link with anyone.</p>";

        $alt = "Dear $applicantName,\n\nYour application draft has been saved successfully. Please use the link below to resume your application:\n$appUrl\n\nFor security purposes, this link will expire automatically. Kindly do not share this link with anyone.\n\nWarm regards,\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => 'Access link to resume your application - DCW Engage',
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true, 'dev_log' => "Magic link token generated for $email: $token",
        ]);
    }

    /**
     * Plain confirmation once an applicant finishes an application (a saved
     * draft gets the magic link instead). No edit token here: returning
     * applicants use the "Resend Magic Link" flow on the form page.
     */
    public static function received($email, $applicantName, $trackingId, $formTitle) {
        $e = fn($s) => CoreMail::e($s);
        $applicantName = self::displayName($applicantName);
        $fullTitle = $formTitle;                         // for the dev log
        $formTitle = CoreMail::eventTitle($formTitle);   // what the applicant sees
        $inner = "
            <p>Dear <strong>" . $e($applicantName) . "</strong>,</p>
            <p>Thank you for submitting your application for <strong>" . $e($formTitle) . "</strong>. We have successfully received your details.</p>
            <p><strong>Tracking ID:</strong> " . $e($trackingId) . "</p>
            <p style='margin-bottom:0;'>We will notify you via email once a decision has been taken. We appreciate your interest and participation.</p>";

        $alt = "Dear $applicantName,\n\nThank you for submitting your application for $formTitle. We have successfully received your details.\nTracking ID: $trackingId\n\nWe will notify you via email once a decision has been taken.";

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => "Application Received - $formTitle",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true, 'dev_log' => "Confirmation email for $email ($trackingId, $fullTitle)",
        ]);
    }

    /**
     * Tells an applicant an organizer changed their application's status
     * (Under Review / Accepted / Rejected). $note is an optional one-off
     * message typed for this email only, distinct from the persisted
     * internal organizer notes thread (NotesModel).
     */
    public static function statusUpdate($email, $applicantName, $status, $trackingId, $formTitle, $note = '') {
        $e = fn($s) => CoreMail::e($s);
        $applicantName = self::displayName($applicantName);
        $fullTitle = $formTitle;                         // for the dev log
        $formTitle = CoreMail::eventTitle($formTitle);   // what the applicant sees
        $noteHtml = $note !== ''
            ? "<p><strong>Remarks / Reviewer Notes:</strong><br>" . nl2br($e($note)) . "</p>"
            : '';

        $inner = "
            <p>Dear <strong>" . $e($applicantName) . "</strong>,</p>
            <p>The status of your application for <strong>" . $e($formTitle) . "</strong> (Tracking ID: " . $e($trackingId) . ") has been updated to: <strong>" . $e($status) . "</strong>.</p>
            $noteHtml";

        $alt = "Dear $applicantName,\n\nThe status of your application for $formTitle (Tracking ID: $trackingId) has been updated to: $status." . ($note ? "\n\nRemarks / Reviewer Notes:\n$note" : '');

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => "Update regarding your $formTitle application - $status",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true,
            'dev_log' => "Status update email for $email: $trackingId ($fullTitle) is now '$status'" . ($note ? " | note: $note" : ''),
        ]);
    }

    /**
     * An organizer sent the application back ("Needs information"). Emails the
     * applicant what is needed, plus the one-time /resume/<token> link they use
     * to edit and resubmit. $message is required text typed by the organizer.
     *
     * dev_result is false on purpose: the caller (application review) shows the
     * link on screen when this returns false, so an unsent email in dev mode
     * or after an SMTP failure never leaves the organizer without the link.
     */
    public static function sentBack($email, $applicantName, $trackingId, $formTitle, $message, $token) {
        $e = fn($s) => CoreMail::e($s);
        $applicantName = self::displayName($applicantName);
        $fullTitle = $formTitle;                         // for the dev log
        $formTitle = CoreMail::eventTitle($formTitle);   // what the applicant sees
        $appUrl = CoreMail::appUrl() . '/resume/' . $token;
        $url = $e($appUrl);

        $inner = "
            <p>Dear <strong>" . $e($applicantName) . "</strong>,</p>
            <p>The reviewers require additional information regarding your application for <strong>" . $e($formTitle) . "</strong> (Tracking ID: " . $e($trackingId) . ").</p>
            <p><strong>Required Information:</strong><br>" . nl2br($e($message)) . "</p>
            <p>Please use the secure link below to update your application and resubmit it at the earliest.</p>
            <div class='btn-wrapper'><a href='$url' class='btn'>Update My Application</a></div>
            <p>If the button above does not work, please copy and paste the following link into your browser:<br><br><a href='$url' style='color: #106b9a; word-break: break-all;'>$url</a></p>
            <p style='margin-bottom:0;'>For security purposes, this link will expire automatically. Kindly do not share this link with anyone.</p>";

        $alt = "Dear $applicantName,\n\nThe reviewers require additional information regarding your application for $formTitle (Tracking ID: $trackingId).\n\nRequired Information:\n$message\n\nPlease update and resubmit your application here:\n$appUrl\n\nFor security purposes, this link will expire automatically. Kindly do not share this link with anyone.\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => "Additional information required for your $formTitle application",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => false,
            'dev_log' => "Send-back email for $email ($trackingId, $fullTitle): $appUrl",
        ]);
    }

    /**
     * Committee-written decision letter (scholarship award or decline). Replaces the standard status
     * email on programs that have scholarship letters switched on.
     *
     * Only the body is the committee's: the greeting ("Dear <name>,") and the signature are added here.
     * The system never writes an outcome word, so the email says only what the committee wrote.
     * "{event}" in the subject or body is replaced by the short event name (CoreMail::eventTitle()).
     * "{confirm_link}" in the body is replaced by $confirmUrl, the awardee's private confirmation link; write it as
     * [{confirm_link} Confirm your place]. With no $confirmUrl the placeholder (and a link around it) is dropped.
     * $body is the wikitext subset rendered by MiniWikiText.
     *
     * dev_result is false on purpose: the caller treats false as "not sent" and shows a resend button.
     */
    public static function decisionLetter($email, $applicantName, $formTitle, $subject, $body, $replyTo, $replyToName = '', $confirmUrl = '') {
        [$subj, $inner, $alt] = self::letterParts($applicantName, $formTitle, $subject, $body, (string) $confirmUrl);
        $name = self::displayName($applicantName);

        return CoreMail::send([
            'to' => $email, 'to_name' => $name,
            'from_name' => $replyToName !== '' ? $replyToName : 'DCW Scholarship Review Committee',
            'reply_to' => [$replyTo, (string) $replyToName],
            'footer_note' => '',   // people can reply (Reply-To is set), so no "automated message" line
            'subject' => $subj,
            'html' => $inner, 'alt' => $alt,
            'dev_result' => false,
            'dev_log' => "Decision letter for $email ($formTitle): $subj",
        ]);
    }

    /** The same email as a full HTML page, for the "Preview" buttons. Sends nothing. */
    public static function decisionLetterPreview($applicantName, $formTitle, $subject, $body, $replyTo, $replyToName = '') {
        // A sample link, so the preview shows where the confirmation link will sit. It goes nowhere.
        [$subj, $inner] = self::letterParts($applicantName, $formTitle, $subject, $body, CoreMail::appUrl() . '/confirm/preview-only');
        $e = fn($s) => CoreMail::e($s);
        $banner ="<p style='font-size:13px; color:#64748b; border-bottom:1px solid #e2e8f0; padding-bottom:12px;'>"
                . "Preview only. Nothing was sent.<br><strong>Subject:</strong> " . $e($subj)
                . "<br><strong>Reply-To:</strong> " . $e($replyTo !== '' ? $replyTo : 'not set (add mail.src_reply_to to config.php)') . "</p>";
        return CoreMail::template($banner . $inner, null, '');
    }

    /** Subject, inner HTML and plain text of a decision letter. */
    private static function letterParts($applicantName, $formTitle, $subject, $body, $confirmUrl = '') {
        $e = fn($s) => CoreMail::e($s);
        $name = self::displayName($applicantName);
        $event = CoreMail::eventTitle($formTitle);

        $subject = str_replace('{confirm_link}', '', str_replace('{event}', $event, (string) $subject));
        $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject));
        $body = trim(str_replace(["\r\n", "\r"], "\n", str_replace('{event}', $event, (string) $body)));
        if ($confirmUrl !== '') {
            $body = str_replace('{confirm_link}', $confirmUrl, $body);
        } else {
            // No link for this email: drop the placeholder, and a link written around it, instead of leaving "[ text]".
            $body = preg_replace('/\[\{confirm_link\}[^\]]*\]/', '', $body);
            $body = trim(str_replace('{confirm_link}', '', $body));
        }

        $inner = "<p>Dear <strong>" . $e($name) . "</strong>,</p>"
               . "<div style='font-size:16px; line-height:1.6;'>" . MiniWikiText::render($body) . "</div>"
               . self::signatureHtml();

        $alt = "Dear $name,\n\n" . self::wikiToPlain($body) . "\n\n--\nScholarship Review Committee\nDeoband Community Wikimedia\n";

        return [$subject, $inner, $alt];
    }

    /**
     * Signature: a short brand-colour rule, then the committee in bold and the organisation
     * in brand-colour small caps, so it reads as a sign-off and not as more body text.
     */
    private static function signatureHtml() {
        return "<div style='width:48px; height:3px; background:#106b9a; margin:32px 0 14px; line-height:3px; font-size:0;'>&nbsp;</div>"
             . "<p style='margin:0; font-size:17px; line-height:1.3; font-weight:bold; color:#0f172a;'>Scholarship Review Committee</p>"
             . "<p style='margin:3px 0 0; font-size:13px; line-height:1.3; font-weight:bold; letter-spacing:1px; text-transform:uppercase; color:#106b9a;'>Deoband Community Wikimedia</p>";
    }

    /**
     * Asks an awardee to confirm their scholarship (the first link for someone accepted before confirmation was
     * switched on, or a reminder). System wording, committee sign-off, sent with the committee Reply-To.
     * $deadlineLabel is the deadline already formatted in IST ('' when there is none).
     * dev_result is false: the caller treats false as "not sent".
     */
    public static function confirmReminder($email, $applicantName, $formTitle, $deadlineLabel, $url, $replyTo, $replyToName = '') {
        $e = fn($s) => CoreMail::e($s);
        $name = self::displayName($applicantName);
        $event = CoreMail::eventTitle($formTitle);
        $by = $deadlineLabel !== '' ? " by <strong>" . $e($deadlineLabel) . "</strong>" : '';
        $byPlain = $deadlineLabel !== '' ? " by $deadlineLabel" : '';

        $inner = "<p>Dear <strong>" . $e($name) . "</strong>,</p>"
               . "<p>We have not yet heard from you about your scholarship for <strong>" . $e($event) . "</strong>. "
               . "Please confirm whether you will take up your place, and share your travel and stay details$by.</p>"
               . CoreMail::button($url, 'Confirm my scholarship')
               . "<p>If the button does not work, copy this link into your browser:<br><a href='" . $e($url) . "' style='color:#106b9a; word-break:break-all;'>" . $e($url) . "</a></p>"
               . "<p style='margin-bottom:0;'>This link is private to you. Please do not share it.</p>"
               . self::signatureHtml();

        $alt = "Dear $name,\n\nWe have not yet heard from you about your scholarship for $event. Please confirm whether you will take up your place, and share your travel and stay details$byPlain.\n\n$url\n\nThis link is private to you. Please do not share it.\n\n--\nScholarship Review Committee\nDeoband Community Wikimedia\n";

        return CoreMail::send([
            'to' => $email, 'to_name' => $name,
            'from_name' => $replyToName !== '' ? $replyToName : 'DCW Scholarship Review Committee',
            'reply_to' => [$replyTo, (string) $replyToName],
            'footer_note' => '',
            'subject' => "Please confirm your scholarship: $event",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => false,
            'dev_log' => "Confirmation link for $email ($formTitle): $url",
        ]);
    }

    /**
     * Receipt for an awardee's response (confirmed or declined), with the same private link so they can update
     * their answers until the deadline. Failure to send never undoes the response: the caller only logs it.
     */
    public static function awardReceipt($email, $applicantName, $formTitle, $response, $deadlineLabel, $url, $replyTo, $replyToName = '') {
        $e = fn($s) => CoreMail::e($s);
        $name = self::displayName($applicantName);
        $event = CoreMail::eventTitle($formTitle);

        if ($response === 'confirmed') {
            $line = "Thank you. We have recorded that you will take up your scholarship for <strong>" . $e($event) . "</strong>, along with your travel and stay details."
                  . ($deadlineLabel !== '' ? " You can change your answers until <strong>" . $e($deadlineLabel) . "</strong> using your private link." : '');
            $linePlain = "Thank you. We have recorded that you will take up your scholarship for $event, along with your travel and stay details."
                  . ($deadlineLabel !== '' ? " You can change your answers until $deadlineLabel using your private link." : '');
            $button = CoreMail::button($url, 'Review or update my answers');
            $subject = "Scholarship confirmed: $event";
        } else {
            $line = "We have recorded that you are unable to take up your scholarship for <strong>" . $e($event) . "</strong>. Thank you for letting us know. If this was a mistake, please reply to this email.";
            $linePlain = "We have recorded that you are unable to take up your scholarship for $event. Thank you for letting us know. If this was a mistake, please reply to this email.";
            $button = '';
            $subject = "Scholarship declined: $event";
        }

        $inner = "<p>Dear <strong>" . $e($name) . "</strong>,</p><p>$line</p>" . $button . self::signatureHtml();
        $alt = "Dear $name,\n\n$linePlain" . ($response === 'confirmed' ? "\n\n$url" : '') . "\n\n--\nScholarship Review Committee\nDeoband Community Wikimedia\n";

        return CoreMail::send([
            'to' => $email, 'to_name' => $name,
            'from_name' => $replyToName !== '' ? $replyToName : 'DCW Scholarship Review Committee',
            'reply_to' => [$replyTo, (string) $replyToName],
            'footer_note' => '',
            'subject' => $subject,
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true,
            'dev_log' => "Award $response receipt for $email ($formTitle)",
        ]);
    }

    /**
     * Tells the program's organizers an awardee declined, so the place can be offered to someone else.
     * $notifyEmails is the form's comma-separated notify_emails. Returns true when there is nobody to tell.
     * The reason is the awardee's own words, so it is escaped here.
     */
    public static function awardDeclinedAlert($notifyEmails, $applicantName, $trackingId, $formTitle, $reason, $reviewUrl) {
        $e = fn($s) => CoreMail::e($s);
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) $notifyEmails))));
        if (!$recipients) return true;
        $name = self::displayName($applicantName);

        $reasonHtml = trim((string) $reason) !== '' ? "<p><strong>Reason given:</strong><br>" . nl2br($e($reason)) . "</p>" : '';
        $inner = "<p>Dear Organiser,</p>"
               . "<p><strong>" . $e($name) . "</strong> (" . $e($trackingId) . ") has declined their scholarship for <strong>" . $e($formTitle) . "</strong>. Their place is free.</p>"
               . $reasonHtml
               . "<p>You can accept another applicant from the review queue whenever you are ready.</p>"
               . CoreMail::button($reviewUrl, 'Open application');
        $alt = "Dear Organiser,\n\n$name ($trackingId) has declined their scholarship for $formTitle. Their place is free."
             . (trim((string) $reason) !== '' ? "\n\nReason given:\n$reason" : '')
             . "\n\nYou can accept another applicant from the review queue whenever you are ready.\n$reviewUrl";

        return CoreMail::send([
            'to' => $recipients,
            'subject' => "Scholarship declined: $formTitle ($trackingId)",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true,
            'dev_log' => "Award declined alert for $trackingId would be sent to: " . implode(', ', $recipients),
        ]);
    }

    /** Wikitext subset -> readable plain text: lists keep their markers, links show their address. */
    private static function wikiToPlain($text) {
        $out = [];
        $n = 0;
        foreach (preg_split('/\n/', (string) $text) as $line) {
            if (preg_match('/^#\s*(.*)$/', $line, $m)) {
                $n++;
                $line = $n . '. ' . $m[1];
            } else {
                $n = 0;
                if (preg_match('/^\*\s*(.*)$/', $line, $m)) {
                    $line = '- ' . $m[1];
                } else {
                    $line = preg_replace(['/^={2,3}\s+(.*?)\s+={2,3}$/', '/^:+\s*/'], ['$1', ''], $line);
                }
            }
            $out[] = $line;
        }
        $t = implode("\n", $out);
        $t = preg_replace('/\[(https?:\/\/[^\s\]]+)\s+([^\]]+)\]/', '$2 ($1)', $t);
        $t = preg_replace("/'{5}(.+?)'{5}/", '$1', $t);
        $t = preg_replace("/'{3}(.+?)'{3}/", '$1', $t);
        $t = preg_replace("/'{2}(.+?)'{2}/", '$1', $t);
        return trim($t);
    }

    /**
     * Alerts the organizer(s) in charge of a form that a new application
     * arrived. notify_emails may be comma-separated. Returns true when there
     * is nobody to notify.
     * Keeps the full form title: organizers may run several forms for one event.
     */
    public static function organizerAlert($form, $applicantEmail, $applicantName, $trackingId) {
        $e = fn($s) => CoreMail::e($s);
        $notifyEmails = trim($form['notify_emails'] ?? '');
        if ($notifyEmails === '') {
            return true;   // no organizer configured for this form yet
        }
        $recipients = array_values(array_filter(array_map('trim', explode(',', $notifyEmails))));
        if (!$recipients) {
            return true;
        }

        // Backward compatibility: an older caller may pass a numeric ID.
        if (is_numeric($trackingId)) {
            $trackingId = 'DCW-' . str_pad((string) $trackingId, 5, '0', STR_PAD_LEFT);
        }

        $formTitle = $form['title'] ?? $form['form_type'];
        $manageUrl = CoreMail::appUrl() . '/admin/form_manager?id=' . $form['id'];

        $inner = "
            <p>Dear Organiser,</p>
            <p>A new application has been submitted for <strong>" . $e($formTitle) . "</strong>.</p>
            <p><strong>Tracking ID:</strong> " . $e($trackingId) . "<br>
            <strong>Applicant Name:</strong> " . $e($applicantName) . "<br>
            <strong>Email Address:</strong> " . $e($applicantEmail) . "</p>
            " . CoreMail::button($manageUrl, 'Review Application');

        $alt = "Dear Organiser,\n\nA new application has been submitted for $formTitle.\nTracking ID: $trackingId\nApplicant Name: $applicantName ($applicantEmail)\n\nKindly review it here: $manageUrl";

        return CoreMail::send([
            'to' => $recipients,
            'subject' => "New Application Received: $formTitle ($trackingId)",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true,
            'dev_log' => "Organizer alert for form '{$form['form_type']}' ($trackingId) would be sent to: " . implode(', ', $recipients),
        ]);
    }
}
