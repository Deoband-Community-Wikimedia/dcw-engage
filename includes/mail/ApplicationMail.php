<?php
// includes/mail/ApplicationMail.php
// Emails for event applications (tracking IDs start "DCW-"): verify email,
// magic link, confirmation, status updates, and the organizer alert.
require_once __DIR__ . '/CoreMail.php';

class ApplicationMail {
    /** The "verify your email" link that opens an application form (#67). Callers must not let the result change what the visitor sees. */
    public static function emailVerification($email, $formTitle, $verifyUrl, $expiresAt) {
        $e = fn($s) => CoreMail::e($s);
        $expiresTime = CoreMail::formatExpiryIST($expiresAt);
        $url = $e($verifyUrl);
        // Form titles are organizer-typed; never drop them into HTML raw.
        $safeTitle = $e($formTitle);

        $inner = "
            <p>Hello,</p>
            <p>Please confirm this email address to start your application for <strong>$safeTitle</strong>.</p>
            <div class='btn-wrapper'><a href='$url' class='btn'>Verify My Email</a></div>
            <p>If the button doesn't work, copy and paste this link into your browser:<br><br><a href='$url' style='color: #106b9a; word-break: break-all;'>$url</a></p>
            <p><strong>This link expires at $expiresTime</strong> and can only be used once.</p>
            <p style='margin-bottom:0;'>If you did not ask for this, ignore this email. Nothing has been submitted and no application was created.</p>";

        $alt = "Hello,\n\nPlease confirm this email address to start your application for $formTitle.\n\nVerify your email here:\n$verifyUrl\n\nThis link expires at $expiresTime and can only be used once.\n\nIf you did not ask for this, ignore this email. Nothing has been submitted.\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'subject' => 'Verify your email to start your application',
            'html' => $inner, 'alt' => $alt,
            'dev_result' => false, 'dev_log' => "Email verification for $email: $verifyUrl",
        ]);
    }

    /** Magic link so an applicant can return and edit a saved application. */
    public static function magicLink($email, $applicantName, $token) {
        $e = fn($s) => CoreMail::e($s);
        $appUrl = CoreMail::appUrl() . '/resume/' . $token;
        $url = $e($appUrl);
        $name = $e($applicantName);

        $inner = "
            <p>Hello <strong>$name</strong>,</p>
            <p>Your application has been successfully saved to our system. We have generated a secure magic link for you so that you can return and edit your application at any time before the deadline.</p>
            <div class='btn-wrapper'><a href='$url' class='btn'>Access My Application</a></div>
            <p>If the button doesn't work, you can copy and paste this link into your browser:<br><br><a href='$url' style='color: #106b9a; word-break: break-all;'>$url</a></p>
            <p style='margin-bottom:0;'>For security reasons, this link will expire automatically. Please do not share this link with anyone.</p>";

        $alt = "Hello $applicantName,\n\nYour application was successfully saved! Here is your magic link to access it:\n$appUrl\n\nThis link will expire soon for security reasons.\n\nBest,\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => 'Magic link for your application on DCW Engage',
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
        $inner = "
            <p>Hello <strong>" . $e($applicantName) . "</strong>,</p>
            <p>Thank you for applying to <strong>" . $e($formTitle) . "</strong>. We have received your application.</p>
            <p><strong>Tracking ID:</strong> " . $e($trackingId) . "</p>
            <p style='margin-bottom:0;'>We'll be in touch once a decision has been made. We appreciate your interest.</p>";

        $alt = "Hello $applicantName,\n\nThank you for applying to $formTitle. We have received your application.\nTracking ID: $trackingId\n\nWe'll be in touch once a decision has been made.";

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
        $noteHtml = $note !== ''
            ? "<p><strong>Reviewer notes:</strong><br>" . nl2br($e($note)) . "</p>"
            : '';

        $inner = "
            <p>Hello <strong>" . $e($applicantName) . "</strong>,</p>
            <p>Your application to <strong>" . $e($formTitle) . "</strong>, with tracking ID " . $e($trackingId) . " has been marked as <strong>" . $e($status) . "</strong>.</p>
            $noteHtml";

        $alt = "Hello $applicantName,\n\nYour application to $formTitle (Tracking ID: $trackingId) has been marked as $status." . ($note ? "\n\nReviewer notes:\n$note" : '');

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => "Update on your $formTitle application - $status",
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
            <p>Hello,</p>
            <p>A new application has been submitted for <strong>" . $e($formTitle) . "</strong>.</p>
            <p><strong>Tracking ID:</strong> " . $e($trackingId) . "<br>
            <strong>Applicant:</strong> " . $e($applicantName) . "<br>
            <strong>Email:</strong> " . $e($applicantEmail) . "</p>
            " . CoreMail::button($manageUrl, 'Review Application');

        $alt = "New application submitted for $formTitle.\nTracking ID: $trackingId\nApplicant: $applicantName ($applicantEmail)\n\nReview it here: $manageUrl";

        return CoreMail::send([
            'to' => $recipients,
            'subject' => "New Application: $formTitle ($trackingId)",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true,
            'dev_log' => "Organizer alert for form '{$form['form_type']}' ($trackingId) would be sent to: " . implode(', ', $recipients),
        ]);
    }
}
