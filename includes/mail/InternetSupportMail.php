<?php
// includes/mail/InternetSupportMail.php
// Emails for internet support requests (tracking IDs start "IS-").
// Internet support is MEMBERS ONLY: requests are made and followed while signed in
// (/member/support, /member/request), so there is no email-verification step any more.
// Callers must not let the result change what the member sees; every method
// returns false in dev mode, so they never pretend something was sent.
require_once __DIR__ . '/CoreMail.php';

class InternetSupportMail {

    public static function received($email, $applicantName, $trackingId) {
        $tid = CoreMail::e($trackingId);

        $inner = "
            <p>" . CoreMail::greeting($applicantName) . "</p>
            <p>We have successfully received your internet support request.</p>
            <p><strong>Tracking ID:</strong> $tid</p>
            <p style='margin-bottom:0;'>We will keep you updated on the progress via email. No further action is required from your side at this stage. You can follow your request any time from your member dashboard.</p>"
            . CoreMail::button(CoreMail::memberRequestUrl($trackingId), 'View My Request');

        $alt = CoreMail::greeting($applicantName, false) . "\n\nWe have successfully received your internet support request.\nTracking ID: $trackingId\n\n"
             . "We will keep you updated on the progress via email. No further action is required from your side at this stage. You can follow your request any time from your member dashboard:\n"
             . CoreMail::memberRequestUrl($trackingId);

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => 'Internet support request received successfully',
            'html' => $inner, 'alt' => $alt,
            'dev_result' => false, 'dev_log' => "Internet support email to $email: received $trackingId",
        ]);
    }

    /**
     * Applicant-facing status changes only. $status is one of:
     *   'Approved for Support' - reviewers approved; finance is next
     *   'Rejected'             - not approved ($note shown)
     *   'Info Requested'       - a reviewer has a question; asks the member to
     *                            reply from their signed-in request page. The question itself is
     *                            NEVER put in the email ($note is ignored): it stays
     *                            behind the member sign-in, and the reviewer stays anonymous.
     *   'Awaiting Receipt'     - recharge done; asks for the receipt ($reference shown)
     *   'Receipt Rejected'     - notification-only pseudo-status: finance bounced
     *                            the uploaded receipt ($note says why)
     *   'Closed'               - all done
     *
     * 'Recharge Failed' and 'Discarded' are refused here (return false): the first is
     * finance-to-reviewer plumbing, the second must be silent.
     *
     * $amountPaise / $amountNote: for 'Approved for Support' this is the approved amount,
     * for 'Awaiting Receipt' the amount actually recharged. $amountNote is the reason the
     * amount differs from the previous figure (pass '' when it doesn't); the member sees it.
     * They are ignored for every other status, and never carry internal notes.
     */
    public static function statusUpdate($email, $applicantName, $trackingId, $status, $note = '', $reference = '', $amountPaise = null, $amountNote = '') {
        // Never tell the member about internal-only states, even if a caller forgets.
        if (in_array($status, ['Recharge Failed', 'Discarded'], true)) {
            return false;
        }

        $e = fn($s) => CoreMail::e($s);
        $trackUrl = CoreMail::memberRequestUrl($trackingId);
        $tid  = $e($trackingId);
        $button = CoreMail::button($trackUrl, 'View My Request');

        switch ($status) {
            case 'Approved for Support':
                $subject = 'Your internet support request has been approved';
                $line = "We are pleased to inform you that your internet support request (Tracking ID: $tid) has been approved and forwarded to our finance team for processing the recharge. We will notify you once the recharge is completed.";
                $alt  = "We are pleased to inform you that your internet support request (Tracking ID: $trackingId) has been approved and forwarded to our finance team for processing the recharge. We will notify you once the recharge is completed.\n\nView your request: $trackUrl";
                $extra = $button;
                break;

            case 'Rejected':
                $subject = 'Update regarding your internet support request';
                $line = "Regrettably, your internet support request (Tracking ID: $tid) could not be approved.";
                $alt  = "Regrettably, your internet support request (Tracking ID: $trackingId) could not be approved.";
                $extra = '';
                break;

            case 'Info Requested':
                $subject = 'Further information required for your internet support request';
                $line = "The reviewer has requested additional information regarding your internet support request (Tracking ID: $tid). Kindly sign in to your member account, open the request, review the query, and submit your response. Please note that your request cannot be processed further until this detail is provided.";
                $alt  = "The reviewer has requested additional information regarding your internet support request (Tracking ID: $trackingId). Kindly sign in to your member account, open the request ($trackUrl), review the query, and submit your response. Please note that your request cannot be processed further until this detail is provided.";
                $extra = $button;
                $note = '';   // the question itself never goes in the email
                break;

            case 'Awaiting Receipt':
                $subject = 'Recharge completed – Please upload the receipt';
                $refHtml = $reference !== ''
                    ? "<p><strong>Recharge Reference No.:</strong> " . $e($reference) . "</p>" : '';
                $line = "The recharge for your request <strong>$tid</strong> has been successfully completed. Kindly sign in to your member account and upload the operator's receipt or payment confirmation to help us complete the request.";
                $alt  = "The recharge for your request $trackingId has been successfully completed. Kindly sign in to your member account and upload the operator's receipt or payment confirmation ($trackUrl)."
                      . ($reference !== '' ? "\nRecharge Reference No.: $reference" : '');
                $extra = $refHtml . $button;
                break;

            case 'Receipt Rejected':
                $subject = 'Action required: Please re-upload your recharge receipt';
                $line = "We were unable to accept the receipt uploaded for request <strong>$tid</strong>. Kindly sign in to your member account and upload a clear and valid receipt at your earliest convenience.";
                $alt  = "We were unable to accept the receipt uploaded for request $trackingId. Kindly sign in to your member account and upload a clear and valid receipt ($trackUrl) at your earliest convenience.";
                $extra = $button;
                break;

            case 'Closed':
                $subject = 'Your internet support request is now closed';
                $line = "Your internet support request (Tracking ID: $tid) has been successfully closed. Thank you for your patience and cooperation!";
                $alt  = "Your internet support request (Tracking ID: $trackingId) has been successfully closed. Thank you for your patience and cooperation!";
                $extra = '';
                break;

            default:
                $subject = 'Update regarding your internet support request';
                $line = "The status of your internet support request (Tracking ID: $tid) has been updated to: <strong>" . $e($status) . "</strong>.";
                $alt  = "The status of your internet support request (Tracking ID: $trackingId) has been updated to: $status.\n\nView your request: $trackUrl";
                $extra = $button;
        }

        // Amount line: only for the two statuses that carry one.
        $amountNote = trim((string) $amountNote);
        $amountHtml = '';
        $amountAlt  = '';
        if ($amountPaise !== null && in_array($status, ['Approved for Support', 'Awaiting Receipt'], true)) {
            $label = $status === 'Awaiting Receipt' ? 'Amount recharged' : 'Approved amount';
            $amt   = '₹' . number_format(((int) $amountPaise) / 100, 2);
            $amountHtml = "<p><strong>$label:</strong> $amt"
                        . ($amountNote !== ''
                            ? "<br><span style='font-size:14px; color:#64748b;'>Reason for the difference: " . nl2br($e($amountNote)) . "</span>"
                            : '')
                        . "</p>";
            $amountAlt  = "\n\n$label: $amt" . ($amountNote !== '' ? "\nReason for the difference: $amountNote" : '');
        }

        $safeNote = $note !== '' ? "<p><strong>Remarks / Notes:</strong><br>" . nl2br($e($note)) . "</p>" : '';
        $altNote  = $note !== '' ? "\n\nRemarks / Notes:\n$note" : '';

        return CoreMail::send([
            'to' => $email, 'to_name' => $applicantName,
            'subject' => $subject,
            'html' => "<p>" . CoreMail::greeting($applicantName) . "</p><p>$line</p>$amountHtml$safeNote$extra",
            'alt' => CoreMail::greeting($applicantName, false) . "\n\n$alt$amountAlt$altNote",
            'dev_result' => false, 'dev_log' => "Internet support email to $email: $subject",
        ]);
    }
}
