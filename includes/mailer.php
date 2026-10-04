<?php
// includes/mailer.php
//
// Facade. The emails now live in one class per subject under includes/mail/:
//
//   CoreMail.php        SMTP + the shared look (nothing else talks to PHPMailer)
//   ApplicationMail.php      event applications: verification, magic link, received, status, organizer alert
//   OrganizerAccountMail.php organizer invitations and password resets
//   ReimbursementMail.php    reimbursement: verification, received, status (incl. Info Requested)
//   InternetSupportMail.php  internet support: verification, received, status (incl. Info Requested)
//   MemberSupportMail.php    member conversations with DCW Support: "you have a reply" / "status changed"
//                            notifications (never contain the message text or any staff name)
//
// Every existing `Mailer::sendX(...)` call keeps working unchanged: this class
// only forwards. New code can call the subject class directly. To add an email,
// put it in the matching subject class (or a new file under includes/mail/) and,
// if old-style callers need it, add a one-line forwarder here.
//
// includes/membership_mailer.php is unchanged (club-specific wording and
// reply-to). It could adopt CoreMail later.

require_once __DIR__ . '/mail/CoreMail.php';
require_once __DIR__ . '/mail/ApplicationMail.php';
require_once __DIR__ . '/mail/OrganizerAccountMail.php';
require_once __DIR__ . '/mail/ReimbursementMail.php';
require_once __DIR__ . '/mail/InternetSupportMail.php';
require_once __DIR__ . '/mail/MemberSupportMail.php';

class Mailer {
    public static function formatExpiryIST($utcDatetime) { return CoreMail::formatExpiryIST($utcDatetime); }

    // --- Applications -------------------------------------------------------
    public static function sendEmailVerification(...$a)   { return ApplicationMail::emailVerification(...$a); }
    public static function sendMagicLink(...$a)           { return ApplicationMail::magicLink(...$a); }
    public static function sendApplicationReceived(...$a) { return ApplicationMail::received(...$a); }
    public static function sendStatusUpdate(...$a)        { return ApplicationMail::statusUpdate(...$a); }
    public static function sendOrganizerAlert(...$a)      { return ApplicationMail::organizerAlert(...$a); }

    // --- Organizer accounts -------------------------------------------------
    public static function sendOrganizerInvite(...$a)     { return OrganizerAccountMail::invite(...$a); }
    public static function sendPasswordReset(...$a)       { return OrganizerAccountMail::passwordReset(...$a); }

    // --- Reimbursements -----------------------------------------------------
    public static function sendReimbursementVerification(...$a) { return ReimbursementMail::verification(...$a); }
    public static function sendReimbursementReceived(...$a)     { return ReimbursementMail::received(...$a); }
    public static function sendReimbursementStatusUpdate(...$a) { return ReimbursementMail::statusUpdate(...$a); }

    // --- Internet support ---------------------------------------------------
    public static function sendInternetVerification(...$a)   { return InternetSupportMail::verification(...$a); }
    public static function sendInternetReceived(...$a)       { return InternetSupportMail::received(...$a); }
    public static function sendInternetStatusUpdate(...$a)   { return InternetSupportMail::statusUpdate(...$a); }

    // --- Member support (DCW Support conversations) -------------------------
    // ($email, $memberName, $trackingId, $kind) where $kind is 'reply' or 'status'.
    // Called by ticket_notify() in models/MemberTicketModel.php.
    public static function sendMemberTicketUpdate(...$a)     { return MemberSupportMail::update(...$a); }
}
