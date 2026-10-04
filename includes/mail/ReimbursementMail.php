<?php
// includes/mail/ReimbursementMail.php
// Emails for reimbursement requests (tracking IDs start "RB-").
require_once __DIR__ . '/CoreMail.php';

class ReimbursementMail {
    /**
     * The "verify your email" link that opens the reimbursement form. Same
     * shape as ApplicationMail::emailVerification(), kept separate so the copy
     * says "reimbursement request" without conditionals in the template.
     * Callers must not let the result change what the visitor sees.
     */
    public static function verification($email, $eventTitle, $verifyUrl, $expiresAt) {
        $e = fn($s) => CoreMail::e($s);
        $expiresTime = CoreMail::formatExpiryIST($expiresAt);
        $url = $e($verifyUrl);

        $inner = "
            <p>Hello,</p>
            <p>Please confirm this email address to initiate your reimbursement request.</p>
            <div class='btn-wrapper'><a href='$url' class='btn'>Verify My Email</a></div>
            <p>If the button doesn't work, copy and paste this link into your browser:<br><br><a href='$url' style='color: #106b9a; word-break: break-all;'>$url</a></p>
            <p><strong>This link expires at $expiresTime</strong> and can only be used once.</p>
            <p style='margin-bottom:0;'>If you did not ask for this, ignore this email. Nothing has been submitted, and no request was created.</p>";

        $alt = "Hello,\n\nPlease confirm this email address to initiate your reimbursement request.\n\nVerify your email here:\n$verifyUrl\n\nThis link expires at $expiresTime and can only be used once.\n\nIf you did not ask for this, ignore this email. Nothing has been submitted.\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'subject' => 'Verify your email to start your reimbursement request',
            'html' => $inner, 'alt' => $alt,
            'dev_result' => false, 'dev_log' => "Reimbursement email verification for $email: $verifyUrl",
        ]);
    }

    /** Plain confirmation once a request is submitted: an acknowledgment with the tracking ID. */
    public static function received($email, $applicantName, $trackingId, $eventTitle) {
        $e = fn($s) => CoreMail::e($s);
        $inner = "
            <p>Hello <strong>" . $e($applicantName) . "</strong>,</p>
            <p>We have received your reimbursement request for <strong>" . $e($eventTitle) . "</strong>.</p>
            <p><strong>Tracking ID:</strong> " . $e($trackingId) . "</p>
            <p style='margin-bottom:0;'>We'll email you again once it's been reviewed. No further action is needed right now.</p>";

        $alt = "Hello $applicantName,\n\nWe've received your reimbursement request for $eventTitle.\nTracking ID: $trackingId\n\nWe'll email you again once it's been reviewed.";

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => "Reimbursement Request Received - $eventTitle",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true, 'dev_log' => "Reimbursement confirmation email for $email ($trackingId, $eventTitle)",
        ]);
    }

    /**
     * Notifies an applicant that their request's status changed.
     *
     * CALLER CONTRACT: only call this for applicant-facing statuses:
     *   'Approved for Payment', 'Rejected', 'Paid', 'Info Requested'.
     * Never for 'Payment Failed': that is a signal from finance back to an
     * admin that the transfer needs fixing, not a message for the applicant,
     * who has done nothing wrong. Never for 'Discarded': it must be silent.
     *
     * 'Info Requested': the email only asks the applicant to open the tracking
     * page. The reviewer's question is NEVER put in the email (it stays behind
     * the tracking ID + email pair, and the reviewer stays anonymous), so
     * $note is ignored for this status.
     *
     * $paymentReference is the transaction reference (UTR / transaction ID)
     * finance recorded when marking the request paid. Only used for 'Paid',
     * so the applicant can match the payment against their own statement.
     */
    public static function statusUpdate($email, $applicantName, $trackingId, $eventTitle, $status, $note = '', $paymentReference = '') {
        $e = fn($s) => CoreMail::e($s);
        $trackUrl = CoreMail::appUrl() . '/track';
        $name  = $e($applicantName);
        $event = $e($eventTitle);
        $tid   = $e($trackingId);

        $button = '';
        $referenceHtml = '';
        $altReference = '';

        switch ($status) {
            case 'Paid':
                $subject = 'Your reimbursement has been paid';
                $line = "Your reimbursement for <strong>$event</strong> (tracking ID $tid) has been <strong>paid</strong>.";
                $alt  = "Your reimbursement for $eventTitle (Tracking ID: $trackingId) has been paid.";
                if ($paymentReference !== '') {
                    $referenceHtml = "<p><strong>Transaction reference:</strong> " . $e($paymentReference) . "<br><span style='font-size:14px; color:#64748b;'>You can use this to find the payment in your bank or UPI statement.</span></p>";
                    $altReference = "\n\nTransaction reference: $paymentReference\nYou can use this to find the payment in your bank or UPI statement.";
                }
                break;

            case 'Info Requested':
                $subject = 'We need a little more information on your reimbursement request';
                $line = "A reviewer has a question about your reimbursement request for <strong>$event</strong> (tracking ID $tid). Please open the tracking page, enter your tracking ID and this email address, read the question, correct your claim if needed and send your reply there. Your request can't move forward until you do.";
                $alt  = "A reviewer has a question about your reimbursement request for $eventTitle (Tracking ID: $trackingId). Please open the tracking page ($trackUrl), enter your tracking ID and this email address, read the question, correct your claim if needed and send your reply there. Your request can't move forward until you do.";
                $button = CoreMail::button($trackUrl, 'Open tracking page');
                $note = '';   // the question itself never goes in the email
                break;

            default:
                $subject = 'Update on your reimbursement request - ' . $status;
                $line = "Your reimbursement request for <strong>$event</strong>, tracking ID $tid, has been marked as <strong>" . $e($status) . "</strong>.";
                $alt  = "Your reimbursement request for $eventTitle (Tracking ID: $trackingId) has been marked as $status.";
        }

        $noteHtml = $note !== '' ? "<p><strong>Notes:</strong><br>" . nl2br($e($note)) . "</p>" : '';
        $altNote  = $note !== '' ? "\n\nNotes:\n$note" : '';

        $inner = "<p>Hello <strong>$name</strong>,</p><p>$line</p>$referenceHtml$noteHtml$button";

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => $subject,
            'html' => $inner,
            'alt' => "Hello $applicantName,\n\n$alt$altReference$altNote",
            'dev_result' => true,
            'dev_log' => "Reimbursement status update for $email: $trackingId ($eventTitle) is now '$status'" . ($note ? " | note: $note" : ''),
        ]);
    }
}
