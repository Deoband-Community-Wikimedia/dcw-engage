<?php
// includes/mail/InternetSupportMail.php
// Emails for internet support requests (tracking IDs start "IS-").
// Callers must not let the result change what the visitor sees; every method
// returns false in dev mode, so they never pretend something was sent.
require_once __DIR__ . '/CoreMail.php';

class InternetSupportMail {
    /** "Verify your email" for the internet support form (form_id NULL verification). */
    public static function verification($email, $verifyUrl, $expiresAt) {
        $expiresTime = CoreMail::formatExpiryIST($expiresAt);
        $url = CoreMail::e($verifyUrl);

        $inner = "
            <p>Hello,</p>
            <p>Please confirm this email address to start your internet support request.</p>
            <div class='btn-wrapper'><a href='$url' class='btn'>Verify My Email</a></div>
            <p>If the button doesn't work, copy and paste this link into your browser:<br><br>
               <a href='$url' style='color: #106b9a; word-break: break-all;'>$url</a></p>
            <p><strong>This link expires at $expiresTime</strong> and can only be used once.</p>
            <p style='margin-bottom:0;'>If you did not ask for this, ignore this email. Nothing has been submitted.</p>";

        $alt = "Hello,\n\nPlease confirm this email address to start your internet support request.\n\n"
             . "Verify your email here:\n$verifyUrl\n\nThis link expires at $expiresTime and can only be used once.\n\n"
             . "If you did not ask for this, ignore this email. Nothing has been submitted.\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'subject' => 'Verify your email to start your internet support request',
            'html' => $inner, 'alt' => $alt,
            'dev_result' => false, 'dev_log' => "Internet support email to $email: verification $verifyUrl",
        ]);
    }

    public static function received($email, $applicantName, $trackingId) {
        $name = CoreMail::e($applicantName);
        $tid  = CoreMail::e($trackingId);

        $inner = "
            <p>Hello <strong>$name</strong>,</p>
            <p>We have received your internet support request.</p>
            <p><strong>Tracking ID:</strong> $tid</p>
            <p style='margin-bottom:0;'>We'll email you as it moves forward. No action is needed right now.</p>";

        $alt = "Hello $applicantName,\n\nWe've received your internet support request.\nTracking ID: $trackingId\n\nWe'll email you as it moves forward.";

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => 'Internet support request received',
            'html' => $inner, 'alt' => $alt,
            'dev_result' => false, 'dev_log' => "Internet support email to $email: received $trackingId",
        ]);
    }

    /**
     * Applicant-facing status changes only. $status is one of:
     *   'Approved for Support' - reviewers approved; finance is next
     *   'Rejected'             - not approved ($note shown)
     *   'Info Requested'       - a reviewer has a question; asks the applicant to
     *                            reply on the tracking page. The question itself is
     *                            NEVER put in the email ($note is ignored): it stays
     *                            behind the tracking ID + email pair, and the
     *                            reviewer stays anonymous.
     *   'Awaiting Receipt'     - recharge done; asks for the receipt ($reference shown)
     *   'Receipt Rejected'     - notification-only pseudo-status: finance bounced
     *                            the uploaded receipt ($note says why)
     *   'Closed'               - all done
     *
     * CALLER CONTRACT: never call this for 'Recharge Failed' or 'Discarded'.
     * The first is finance-to-reviewer plumbing; the second must be silent.
     */
    public static function statusUpdate($email, $applicantName, $trackingId, $status, $note = '', $reference = '') {
        $e = fn($s) => CoreMail::e($s);
        $trackUrl = CoreMail::appUrl() . '/track';
        $name = $e($applicantName);
        $tid  = $e($trackingId);
        $button = CoreMail::button($trackUrl, 'Open tracking page');

        switch ($status) {
            case 'Approved for Support':
                $subject = 'Your internet support request was approved';
                $line = "Good news: your internet support request (tracking ID $tid) has been approved and passed to our finance team for the recharge. We'll email you once it's done.";
                $alt  = "Your internet support request ($trackingId) has been approved and passed to our finance team for the recharge. We'll email you once it's done.";
                $extra = '';
                break;

            case 'Rejected':
                $subject = 'Update on your internet support request';
                $line = "Your internet support request (tracking ID $tid) was not approved.";
                $alt  = "Your internet support request ($trackingId) was not approved.";
                $extra = '';
                break;

            case 'Info Requested':
                $subject = 'We need a little more information on your internet support request';
                $line = "A reviewer has a question about your internet support request (tracking ID $tid). Please open the tracking page, enter your tracking ID and this email address, read the question, correct your answers if needed and send your reply there. Your request can't move forward until you do.";
                $alt  = "A reviewer has a question about your internet support request ($trackingId). Please open the tracking page ($trackUrl), enter your tracking ID and this email address, read the question, correct your answers if needed and send your reply there. Your request can't move forward until you do.";
                $extra = $button;
                $note = '';   // the question itself never goes in the email
                break;

            case 'Awaiting Receipt':
                $subject = 'Your recharge is done - please upload the receipt';
                $refHtml = $reference !== ''
                    ? "<p><strong>Recharge reference:</strong> " . $e($reference) . "</p>" : '';
                $line = "Your recharge for request <strong>$tid</strong> has been done. Please upload the operator's receipt or confirmation so we can close the request. Open the tracking page and enter your tracking ID and this email address.";
                $alt  = "Your recharge for request $trackingId has been done. Please upload the operator's receipt on the tracking page ($trackUrl) using your tracking ID and this email address."
                      . ($reference !== '' ? "\nRecharge reference: $reference" : '');
                $extra = $refHtml . $button;
                break;

            case 'Receipt Rejected':
                $subject = 'Please re-upload your internet recharge receipt';
                $line = "We couldn't accept the receipt you uploaded for request <strong>$tid</strong>. Please upload a new one on the tracking page.";
                $alt  = "We couldn't accept the receipt you uploaded for request $trackingId. Please upload a new one on the tracking page: $trackUrl";
                $extra = $button;
                break;

            case 'Closed':
                $subject = 'Your internet support request is complete';
                $line = "Your internet support request (tracking ID $tid) is now complete. Thank you!";
                $alt  = "Your internet support request ($trackingId) is now complete. Thank you!";
                $extra = '';
                break;

            default:
                $subject = 'Update on your internet support request';
                $line = "Your internet support request (tracking ID $tid) is now: <strong>" . $e($status) . "</strong>.";
                $alt  = "Your internet support request ($trackingId) is now: $status.";
                $extra = '';
        }

        $safeNote = $note !== '' ? "<p><strong>Notes:</strong><br>" . nl2br($e($note)) . "</p>" : '';
        $altNote  = $note !== '' ? "\n\nNotes:\n$note" : '';

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => $subject,
            'html' => "<p>Hello <strong>$name</strong>,</p><p>$line</p>$safeNote$extra",
            'alt' => "Hello $applicantName,\n\n$alt$altNote",
            'dev_result' => false, 'dev_log' => "Internet support email to $email: $subject",
        ]);
    }
}
