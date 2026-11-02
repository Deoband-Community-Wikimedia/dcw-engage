<?php
// includes/mailer.php
//
// Facade. The emails now live in one class per subject under includes/mail/:
//
//   CoreMail.php        SMTP + the shared look (nothing else talks to PHPMailer)
//   ApplicationMail.php      event applications: verification, magic link, received, status, sent back, organizer alert
//   OrganizerAccountMail.php organizer invitations and password resets
//   ReimbursementMail.php    reimbursement (members only): received, status (incl. Info Requested)
//   InternetSupportMail.php  internet support (members only): received, status (incl. Info Requested)
//   MemberSupportMail.php    member conversations with DCW Support: "you have a reply" / "status changed"
//                            notifications (never contain the message text or any staff name)
//   membership_mailer.php    membership emails (class MembershipMailer): club-specific wording, From name and reply-to
//   feedback_mailer.php      feedback forms (class FeedbackMailer): receipt to the respondent, alert to organizers.
//                            No tracking ID and no link in the receipt.
//
// Every existing `Mailer::sendX(...)` call keeps working unchanged: this class
// only forwards. New code can call the subject class directly. To add an email,
// put it in the matching subject class (or a new file under includes/mail/) and,
// if old-style callers need it, add a one-line forwarder here.
//
// Reimbursement and internet support no longer send an email-verification message:
// they are members-only, so signing in already proves the email.
//
// MembershipMailer has no forwarders here: call it directly, e.g. MembershipMailer::sendDecisionFor(...).

require_once __DIR__ . '/mail/CoreMail.php';
require_once __DIR__ . '/mail/ApplicationMail.php';
require_once __DIR__ . '/mail/OrganizerAccountMail.php';
require_once __DIR__ . '/mail/ReimbursementMail.php';
require_once __DIR__ . '/mail/InternetSupportMail.php';
require_once __DIR__ . '/mail/MemberSupportMail.php';
require_once __DIR__ . '/mail/membership_mailer.php';
require_once __DIR__ . '/mail/feedback_mailer.php';

class Mailer {
    public static function formatExpiryIST($utcDatetime) { return CoreMail::formatExpiryIST($utcDatetime); }

    // --- Applications -------------------------------------------------------
    public static function sendEmailVerification(...$a)   { return ApplicationMail::emailVerification(...$a); }
    public static function sendMagicLink(...$a)           { return ApplicationMail::magicLink(...$a); }
    // ($email, $applicantName, $trackingId, $formTitle, $revised = false)
    // $revised = true when staff sent the application back ("Needs information") and the applicant has
    // resubmitted it: the email then says a revised application was received.
    public static function sendApplicationReceived(...$a) { return ApplicationMail::received(...$a); }
    public static function sendStatusUpdate(...$a)        { return ApplicationMail::statusUpdate(...$a); }
    // ($email, $applicantName, $trackingId, $formTitle, $message, $token)
    // "Needs information": what the organizer needs + the one-time /resume/<token> link.
    // Returns false when the email was not really sent, so the caller can show the link instead.
    public static function sendSentBack(...$a)            { return ApplicationMail::sentBack(...$a); }
    // ($form, $applicantEmail, $applicantName, $trackingId, $revised = false)
    // $revised = true tells organizers it is a resubmission of an application they sent back.
    public static function sendOrganizerAlert(...$a)      { return ApplicationMail::organizerAlert(...$a); }
    // Scholarship award confirmation (see models/AwardConfirmationModel.php). All three go out with the committee Reply-To.
    // ($email, $name, $formTitle, $deadlineLabel, $url, $replyTo, $replyToName = '')   first link or reminder; false = not sent
    public static function sendConfirmReminder(...$a)     { return ApplicationMail::confirmReminder(...$a); }
    // ($email, $name, $formTitle, $response ['confirmed'|'declined'], $deadlineLabel, $url, $replyTo, $replyToName = '')
    public static function sendAwardReceipt(...$a)        { return ApplicationMail::awardReceipt(...$a); }
    // ($notifyEmails, $name, $trackingId, $formTitle, $reason, $reviewUrl)   tells organizers a place is free
    public static function sendAwardDeclinedAlert(...$a)  { return ApplicationMail::awardDeclinedAlert(...$a); }

    // --- Feedback forms -----------------------------------------------------
    // ($email, $name, $formTitle)
    // Receipt only: no tracking ID, no link. Returns true when sent (or in dev mode).
    public static function sendFeedbackReceipt(...$a)     { return FeedbackMailer::sendReceipt(...$a); }
    // ($notifyEmails, $formTitle, $formId)   $notifyEmails is the form's comma-separated notify_emails.
    // Says feedback arrived and links to /admin/feedback-review; never repeats the feedback itself.
    public static function sendFeedbackAlert(...$a)       { return FeedbackMailer::sendOrganizerAlert(...$a); }

    // --- Organizer accounts -------------------------------------------------
    public static function sendOrganizerInvite(...$a)     { return OrganizerAccountMail::invite(...$a); }
    public static function sendPasswordReset(...$a)       { return OrganizerAccountMail::passwordReset(...$a); }

    // --- Reimbursements (members only) --------------------------------------
    // ($email, $applicantName, $trackingId, $eventTitle)
    public static function sendReimbursementReceived(...$a)     { return ReimbursementMail::received(...$a); }
    // ($email, $applicantName, $trackingId, $eventTitle, $status, $note = '', $paymentReference = '',
    //  $amountPaise = null, $amountNote = '')
    // $amountPaise / $amountNote are used for 'Approved for Payment' (approved amount) and 'Paid'
    // (amount paid); $amountNote is the reason the amount differs, shown to the member.
    // Returns false (sends nothing) for 'Payment Failed' and 'Discarded'.
    public static function sendReimbursementStatusUpdate(...$a) { return ReimbursementMail::statusUpdate(...$a); }

    // --- Internet support (members only) ------------------------------------
    // ($email, $applicantName, $trackingId)
    public static function sendInternetReceived(...$a)       { return InternetSupportMail::received(...$a); }
    // ($email, $applicantName, $trackingId, $status, $note = '', $reference = '',
    //  $amountPaise = null, $amountNote = '')
    // $amountPaise / $amountNote are used for 'Approved for Support' (approved amount) and
    // 'Awaiting Receipt' (amount recharged); $amountNote is the reason the amount differs.
    // Returns false (sends nothing) for 'Recharge Failed' and 'Discarded'.
    public static function sendInternetStatusUpdate(...$a)   { return InternetSupportMail::statusUpdate(...$a); }

    // --- Member support (DCW Support conversations) -------------------------
    // ($email, $memberName, $trackingId, $kind) where $kind is 'reply' or 'status'.
    // Called by ticket_notify() in models/MemberTicketModel.php.
    public static function sendMemberTicketUpdate(...$a)     { return MemberSupportMail::update(...$a); }
}
