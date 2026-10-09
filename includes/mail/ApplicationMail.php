<?php
// includes/mail/ApplicationMail.php
// Emails for event applications (tracking IDs start "DCW-"): verify email,
// magic link, confirmation, status updates, and the organizer alert.
require_once __DIR__ . '/CoreMail.php';

class ApplicationMail {
    /** Name as it should appear in a greeting: age removed, "Applicant" when nothing usable is left. */
    private static function displayName($name) {
        $clean = CoreMail::cleanName($name);
        return $clean !== '' ? $clean : 'Applicant';
    }

    /**
     * The "verify your email" link that opens an application form (#67). Callers must not let the result change what the visitor sees.
     * $name is the applicant's name from the gate form; when empty the greeting falls back to "Applicant".
     */
    public static function emailVerification($email, $formTitle, $verifyUrl, $expiresAt, $name = '') {
        $e = fn($s) => CoreMail::e($s);
        $expiresTime = CoreMail::formatExpiryIST($expiresAt);
        $url = $e($verifyUrl);
        // Form titles are organizer-typed; never drop them into HTML raw.
        $safeTitle = $e($formTitle);

        // Greet by name when we have one; fall back to the generic greeting otherwise.
        $name = CoreMail::cleanName($name);   // drops an age typed after the name
        $greetName = $name !== '' ? $name : 'Applicant';
        $greetHtml = $name !== '' ? '<strong>' . $e($name) . '</strong>' : 'Applicant';

        $inner = "
            <p>Dear $greetHtml,</p>
            <p>Please verify your email address to initiate your application for <strong>$safeTitle</strong>.</p>
            <div class='btn-wrapper'><a href='$url' class='btn'>Verify Email Address</a></div>
            <p>If the button above does not work, please copy and paste the following link into your browser:<br><br><a href='$url' style='color: #106b9a; word-break: break-all;'>$url</a></p>
            <p><strong>Note: This link is valid until $expiresTime IST</strong> and can only be used once.</p>
            <p style='margin-bottom:0;'>If you did not initiate this request, please ignore this email. No further action will be taken and no application will be created.</p>";

        $alt = "Dear $greetName,\n\nPlease verify your email address to initiate your application for $formTitle.\n\nVerify your email here:\n$verifyUrl\n\nNote: This link is valid until $expiresTime IST and can only be used once.\n\nIf you did not initiate this request, please ignore this email. No further action will be taken.\n\nDeoband Community Wikimedia";

        $mail = [
            'to' => $email, 'subject' => 'Verify your email address to begin application',
            'html' => $inner, 'alt' => $alt,
            'dev_result' => false, 'dev_log' => "Email verification for $email: $verifyUrl",
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
            <p>Your application draft has been successfully saved. We have generated a secure access link for you to resume and update your application prior to the deadline.</p>
            <div class='btn-wrapper'><a href='$url' class='btn'>Access My Application</a></div>
            <p>If the button above does not work, please copy and paste the following link into your browser:<br><br><a href='$url' style='color: #106b9a; word-break: break-all;'>$url</a></p>
            <p style='margin-bottom:0;'>For security purposes, this link will expire automatically. Kindly do not share this link with anyone.</p>";

        $alt = "Dear $applicantName,\n\nYour application draft has been successfully saved. Please use the link below to resume your application:\n$appUrl\n\nFor security purposes, this link will expire automatically. Kindly do not share this link with anyone.\n\nWarm regards,\nDeoband Community Wikimedia";

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
        $inner = "
            <p>Dear <strong>" . $e($applicantName) . "</strong>,</p>
            <p>Thank you for submitting your application for <strong>" . $e($formTitle) . "</strong>. We have successfully received your application details.</p>
            <p><strong>Tracking ID:</strong> " . $e($trackingId) . "</p>
            <p style='margin-bottom:0;'>We will notify you via email once a decision has been taken. We appreciate your interest and participation.</p>";

        $alt = "Dear $applicantName,\n\nThank you for submitting your application for $formTitle. We have successfully received your application details.\nTracking ID: $trackingId\n\nWe will notify you via email once a decision has been taken.";

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => "Application Received - $formTitle",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true, 'dev_log' => "Confirmation email for $email ($trackingId, $formTitle)",
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
            'dev_log' => "Status update email for $email: $trackingId ($formTitle) is now '$status'" . ($note ? " | note: $note" : ''),
        ]);
    }

    /**
     * Alerts the organizer(s) in charge of a form that a new application
     * arrived. notify_emails may be comma-separated. Returns true when there
     * is nobody to notify.
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
