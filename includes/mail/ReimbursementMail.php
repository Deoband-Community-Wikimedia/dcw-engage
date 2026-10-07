<?php
// includes/mail/ReimbursementMail.php
// Emails for reimbursement requests (tracking IDs start "RB-").
// Reimbursement is MEMBERS ONLY: requests are made and followed while signed in
// (/member/support, /member/request), so there is no email-verification step any more.
require_once __DIR__ . '/CoreMail.php';

class ReimbursementMail {

    /** Plain confirmation once a request is submitted: an acknowledgment with the tracking ID. */
    public static function received($email, $applicantName, $trackingId, $eventTitle) {
        $e = fn($s) => CoreMail::e($s);
        $trackUrl = CoreMail::memberRequestUrl($trackingId);
        $inner = "
            <p>" . CoreMail::greeting($applicantName) . "</p>
            <p>We have successfully received your reimbursement request for <strong>" . $e($eventTitle) . "</strong>.</p>
            <p><strong>Tracking ID:</strong> " . $e($trackingId) . "</p>
            <p style='margin-bottom:0;'>We will notify you via email once your request has been reviewed. No further action is required from your side at this stage. You can track the status anytime through your member dashboard.</p>"
            . CoreMail::button($trackUrl, 'View My Request');

        $alt = CoreMail::greeting($applicantName, false) . "\n\nWe have successfully received your reimbursement request for $eventTitle.\nTracking ID: $trackingId\n\nWe will notify you via email once your request has been reviewed. You can track the status anytime through your member dashboard:\n$trackUrl";

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => "Reimbursement Request Received - $eventTitle",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true, 'dev_log' => "Reimbursement confirmation email for $email ($trackingId, $eventTitle)",
        ]);
    }

    /**
     * Notifies a member that their request's status changed.
     *
     * Only applicant-facing statuses are sent:
     *   'Approved for Payment', 'Rejected', 'Paid', 'Info Requested'.
     * 'Payment Failed' and 'Discarded' are refused here (return false): the first is a signal
     * from finance back to an admin that the transfer needs fixing, not a message for the
     * member, who has done nothing wrong; the second must be silent.
     *
     * 'Info Requested': the email only asks the member to sign in and open the request.
     * The reviewer's question is NEVER put in the email (it stays behind the member
     * sign-in, and the reviewer stays anonymous), so $note is ignored for this status.
     *
     * $paymentReference is the transaction reference (UTR / transaction ID)
     * finance recorded when marking the request paid. Only used for 'Paid',
     * so the member can match the payment against their own statement.
     */
    public static function statusUpdate($email, $applicantName, $trackingId, $eventTitle, $status, $note = '', $paymentReference = '') {
        // Never tell the member about internal-only states, even if a caller forgets.
        if (in_array($status, ['Payment Failed', 'Discarded'], true)) {
            return false;
        }

        $e = fn($s) => CoreMail::e($s);
        $trackUrl = CoreMail::memberRequestUrl($trackingId);
        $event = $e($eventTitle);
        $tid   = $e($trackingId);

        $button = CoreMail::button($trackUrl, 'View My Request');
        $referenceHtml = '';
        $altReference = '';
        $altLink = "\n\nView your request: $trackUrl";

        switch ($status) {
            case 'Paid':
                $subject = 'Your reimbursement payment has been processed';
                $line = "Your reimbursement for <strong>$event</strong> (Tracking ID: $tid) has been <strong>successfully paid</strong>.";
                $alt  = "Your reimbursement for $eventTitle (Tracking ID: $trackingId) has been successfully paid.";
                if ($paymentReference !== '') {
                    $referenceHtml = "<p><strong>Transaction / UTR reference:</strong> " . $e($paymentReference) . "<br><span style='font-size:14px; color:#64748b;'>You can use this reference number to locate the payment in your bank or UPI account statement.</span></p>";
                    $altReference = "\n\nTransaction / UTR reference: $paymentReference\nYou can use this reference number to locate the payment in your bank or UPI account statement.";
                }
                break;

            case 'Info Requested':
                $subject = 'Further information required for your reimbursement request';
                $line = "The reviewer has requested additional information regarding your reimbursement request for <strong>$event</strong> (Tracking ID: $tid). Kindly log in to your member account, open the request, review the query, and submit your response. Please note that your request cannot be processed further until this detail is provided.";
                $alt  = "The reviewer has requested additional information regarding your reimbursement request for $eventTitle (Tracking ID: $trackingId). Kindly log in to your member account, open the request ($trackUrl), review the query, and submit your response. Please note that your request cannot be processed further until this detail is provided.";
                $altLink = '';   // the link is already in the sentence above
                $note = '';      // the question itself never goes in the email
                break;

            default:
                $subject = 'Update regarding your reimbursement request - ' . $status;
                $line = "The status of your reimbursement request for <strong>$event</strong> (Tracking ID: $tid) has been updated to: <strong>" . $e($status) . "</strong>.";
                $alt  = "The status of your reimbursement request for $eventTitle (Tracking ID: $trackingId) has been updated to: $status.";
        }

        $noteHtml = $note !== '' ? "<p><strong>Remarks / Notes:</strong><br>" . nl2br($e($note)) . "</p>" : '';
        $altNote  = $note !== '' ? "\n\nRemarks / Notes:\n$note" : '';

        $inner = "<p>" . CoreMail::greeting($applicantName) . "</p><p>$line</p>$referenceHtml$noteHtml$button";

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => $subject,
            'html' => $inner,
            'alt' => CoreMail::greeting($applicantName, false) . "\n\n$alt$altReference$altNote$altLink",
            'dev_result' => true,
            'dev_log' => "Reimbursement status update for $email: $trackingId ($eventTitle) is now '$status'" . ($note ? " | note: $note" : ''),
        ]);
    }
}
