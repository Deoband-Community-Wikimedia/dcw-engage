<?php
// includes/mail/membership_mailer.php
// Emails for the membership review flow. Uses CoreMail for SMTP and the shared look; this
// class only adds the club-specific wording, From display name, header line and reply-to.
//
// Chapters: emails name the club the applicant applied to (MemberModel::CHAPTER_NAMES).
// DCW itself (the 'generic' chapter, or an unknown chapter) is not a separate club:
// it reads "Deoband Community Wikimedia" and is signed by the Membership Coordinators.
// Clubs are signed by the Core Organising Team of that club. See MemberModel::isDcw().
//
// Renewals: pass MemberModel::isRenewal($app) as the last argument so the wording says
// "renewing" rather than "joining".
//
// Shared Member ID: someone who already has a DCW Generic Community ID keeps it when they are
// approved for a club ($sharedId). They are told so, and are not asked to set another password.
//
// One email on approval: when a new member has no password yet, the "Set my password"
// button is inside the confirmation email (see sendDecision's $passwordToken). A separate
// password email is only sent for the "forgot password" flow (sendPasswordLink).
// The approval email also tells the member what they can do in DCW Engage (dashboard, profile,
// membership card, support): see dashboardBlock().
//
// "Forgot your Member ID?": sendMemberIdReminder() emails the ID to the address on file.
//
// Join-form verification: sendExistingAccount() carries the one-time verification CODE (OTP),
// not a link, when the address already belongs to a member joining another chapter. It names the
// form's event only (CoreMail::eventTitle()), not the full form title. It greets the person by the
// name they typed on the verification gate ($applicantName), falling back to the name on file.
//
// Sign-off examples:
//   Best regards,                       Best regards,
//   Core Organising Team                Membership Coordinators
//   Wiki Club AMU                       Deoband Community Wikimedia

require_once __DIR__ . '/CoreMail.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../models/MemberAuthModel.php';

class MembershipMailer {
    /** Full name of DCW, used wherever the applicant is joining DCW itself. */
    private const DCW_NAME = 'Deoband Community Wikimedia';

    /** Signing team for DCW itself (generic chapter / unknown chapter). */
    private const DCW_TEAM = 'Membership Coordinators';

    /** Signing team for a club (shown above the club's name). */
    private const CLUB_TEAM = 'Core Organising Team';

    /**
     * Optional reply-to address per chapter key, for when each club has its own
     * mailbox. Empty for now, so replies are not routed anywhere yet. Example:
     *   'amu' => 'amu@example.org'
     */
    private const CONTACT_EMAILS = [];

    /**
     * Display name of a separate club ('amu' -> 'Wiki Club AMU').
     * Returns null for DCW itself (generic chapter) or an unknown chapter.
     */
    private static function club($chapter) {
        if (!$chapter || MemberModel::isDcw($chapter)) {
            return null;
        }
        return MemberModel::CHAPTER_NAMES[$chapter] ?? null;
    }

    /** What the applicant is joining: the club's name, or Deoband Community Wikimedia. */
    private static function org($chapter) {
        return self::club($chapter) ?? self::DCW_NAME;
    }

    /** [team, organisation] for the sign-off. */
    private static function signoff($chapter) {
        return self::club($chapter)
            ? [self::CLUB_TEAM, self::club($chapter)]
            : [self::DCW_TEAM, self::DCW_NAME];
    }

    /** HTML sign-off block. */
    private static function signHtml($chapter) {
        [$team, $org] = self::signoff($chapter);
        return "<p style='margin-bottom:0;'>Best regards,<br><strong>"
             . htmlspecialchars($team, ENT_QUOTES, 'UTF-8') . "</strong><br>"
             . htmlspecialchars($org, ENT_QUOTES, 'UTF-8') . "</p>";
    }

    /** Plain-text sign-off block. */
    private static function signText($chapter) {
        [$team, $org] = self::signoff($chapter);
        return "Best regards,\n$team\n$org";
    }

    /**
     * "What you can do in DCW Engage" block for the approval email: dashboard, profile,
     * membership card and support. Returns [html, text].
     */
    private static function dashboardBlock() {
        $base  = CoreMail::appUrl();
        $login = $base . '/member/login';
        $safe  = htmlspecialchars($login, ENT_QUOTES, 'UTF-8');
        // [title, path, description]. Each title links to its page (signed-out members are sent to sign in first).
        $items = [
            ['Dashboard', '/member/dashboard', 'Check your memberships along with their validity dates, certificates, requests, conversations, and any action items. You can also process your renewals directly from here.'],
            ['My profile', '/member/profile', 'Update your Wikimedia username so the reviewers can verify it. This is required for internet support and will appear on your membership card once verified. You can also add a short bio and languages known.'],
            ['Membership card', '/member/card', 'Download your membership card as a PDF to print or store on your mobile device. Anyone can scan the QR code to verify your active membership status. If misplaced, you can generate a replacement card from this page, which will automatically invalidate the older code.'],
            ['Certificates', '/member/certificates', 'Access and download participation certificates issued for DCW events.'],
            ['Support', '/member/dashboard#support', 'Raise requests for internet support or reimbursements when active, reach out to DCW Support, or report technical glitches.'],
        ];
        $html = "<h2 style='font-size:18px; margin:28px 0 8px; color:#106b9a;'>What you can do in DCW Engage</h2>"
              . "<ul style='margin:0 0 12px; padding-left:20px; line-height:1.6;'>";
        $text = "\n\nWHAT YOU CAN DO IN DCW ENGAGE\n";
        foreach ($items as [$title, $path, $desc]) {
            $url   = $base . $path;
            $html .= "<li style='margin-bottom:8px;'><strong><a href='" . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . "' style='color:#106b9a;'>"
                   . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</a>:</strong> "
                   . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . "</li>";
            $text .= "- $title: $desc\n  $url\n";
        }
        $html .= "</ul><p>You can sign in at any time at <a href='$safe' style='color:#106b9a;'>$safe</a>.</p>";
        $text .= "\nYou can sign in at any time at $login";
        return [$html, $text];
    }

    /**
     * Decision for the applicant. $decision: 'approved' | 'rejected'.
     * Approved: $memberId and $detail (expiry, 'Y-m-d H:i:s') are shown; only the day is displayed.
     * Rejected: $detail is the reason the coordinator wrote.
     * $chapter is the chapter key (MemberModel::chapterOf($app)), or null.
     * $renewal is true for a renewal (MemberModel::isRenewal($app)): the wording then says
     * "renewing" instead of "joining", and notes that the member ID stays the same.
     * $passwordToken (approved only): raw one-time token from MemberAuthModel. When given, a
     * "Set my password" button is added to this same email, so the member gets ONE email.
     * $sharedId (approved only): true when this is a NEW club membership under an ID the person
     * already had, so the email explains that one ID covers all their memberships.
     */
    public static function sendDecision($email, $name, $decision, $memberId = null, $detail = '', $chapter = null, $renewal = false, $passwordToken = null, $sharedId = false) {
        $n = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
        $org = self::org($chapter);
        $safeOrg = htmlspecialchars($org, ENT_QUOTES, 'UTF-8');
        $hasDetail = ($detail !== '' && $detail !== null);

        // Opening line, as plain text with the club name left to be escaped by each version.
        $thanks = $renewal
            ? 'Thank you for renewing your membership with %s'
            : 'Thank you for your interest in joining %s';

        if ($decision === 'approved') {
            $until = $hasDetail ? date('j M Y', strtotime($detail)) : '';
            $id = htmlspecialchars((string) $memberId, ENT_QUOTES, 'UTF-8');
            $subject = $renewal
                ? "Your $org membership renewal is confirmed"
                : "Welcome to $org: your membership is confirmed";
            $good = $renewal
                ? "We are pleased to confirm that your renewal has been approved. Thank you for continuing your association with us."
                : "We are pleased to confirm that your application has been approved. Welcome aboard our open knowledge community!";
            $keep = $renewal
                ? "Your Member ID remains the same. Please keep it handy to access your dashboard and for future renewals."
                : ($sharedId
                    ? "This membership has been linked to your existing Member ID. You can now conveniently access and manage all your memberships using this single ID."
                    : "Please note down your Member ID securely. You will need it to sign in to DCW Engage and access your membership benefits.");

            // Password section. New members (token given) are told to set a password and get the
            // button; renewing members who already have one are told their login still works.
            $pwHtml = '';
            $pwText = '';
            if ($passwordToken) {
                $url = CoreMail::appUrl() . '/member/set-password/' . $passwordToken;
                $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                $pwHtml = "
                <h2 style='font-size:18px; margin:28px 0 8px; color:#106b9a;'>Next step: set your password</h2>
                <p>To access DCW Engage, please set your account password using the button below. Once done, you can sign in with your Member ID <strong>$id</strong> to check your membership details, download your digital cards, and access community resources.</p>
                <div class='btn-wrapper'><a href='$safeUrl' class='btn'>Set my password</a></div>
                <p style='font-size:14px;'>If the button does not work, copy and paste this link into your browser:<br>
                   <a href='$safeUrl' style='color:#106b9a; word-break:break-all;'>$safeUrl</a></p>
                <p style='font-size:14px; color:#64748b;'>This link is meant exclusively for you, can be used only once, and will expire shortly. Please set your password promptly and do not share this email with anyone.</p>";
                $pwText = "\n\nNEXT STEP: SET YOUR PASSWORD\nTo access DCW Engage, please set your account password using the link below. Once done, you can sign in with your Member ID $memberId to view your membership details and access community resources:\n$url\n\nThis link is meant exclusively for you, can be used only once, and will expire shortly. Please set your password promptly and do not share this email with anyone.";
            } else {
                // No fresh link (renewing member, a club joined under an existing ID, or a link
                // could not be created): still advise.
                $pwHtml = "<p><strong>Sign in:</strong> You can log in to DCW Engage using your Member ID <strong>$id</strong> and your existing password. If you haven't set a password yet or need to reset it, simply click on \"Forgot password\" on the sign-in page.</p>";
                $pwText = "\n\nSIGN IN: You can log in to DCW Engage using your Member ID $memberId and your existing password. If you haven't set a password yet or need to reset it, simply click on \"Forgot password\" on the sign-in page.";
            }

            // What the member can do once signed in (dashboard, profile, membership card, support).
            [$dashHtml, $dashText] = self::dashboardBlock();

            $inner = "
                <p>Hello <strong>$n</strong>,</p>
                <p>" . sprintf($thanks, "<strong>$safeOrg</strong>") . ". $good</p>
                <div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:14px 18px; margin:0 0 20px; line-height:1.7;'>
                    <strong>Member ID:</strong> <span style='font-family:Menlo,Consolas,monospace; letter-spacing:1px;'>$id</span>" . ($until ? "<br><strong>Valid until:</strong> $until" : '') . "
                </div>
                <p>$keep</p>
                " . ($passwordToken ? "<p style='background:#fffbeb; border:1px solid #fcd34d; border-radius:6px; padding:12px 16px;'><strong>Important:</strong> Please set your password using the section below to finish activating your account.</p>" : '') . "
                $pwHtml
                $dashHtml
                " . self::signHtml($chapter);
            $alt = "Hello $name,\n\n" . sprintf($thanks, $org) . ". $good\n\nMember ID: $memberId"
                 . ($until ? "\nValid until: $until" : '') . "\n\n$keep" . ($passwordToken ? "\n\nIMPORTANT: Please set your password using the section below to finish activating your account." : '') . $pwText . $dashText . "\n\n" . self::signText($chapter);
        } else {
            $subject = $renewal
                ? "Update regarding your $org membership renewal"
                : "Update regarding your $org membership application";
            $sorry = $renewal
                ? "and for taking the time to submit your renewal. After careful review, we regret to inform you that we are unable to approve your renewal at this juncture."
                : "and for taking the time to submit your application. After careful review, we regret to inform you that we are unable to approve your membership at this juncture.";
            $close = $renewal
                ? "We sincerely appreciate your ongoing support and look forward to your continued participation in future community initiatives."
                : "We sincerely appreciate your interest in our initiatives and hope our paths cross again in future open knowledge endeavours.";
            $reason = $hasDetail
                ? "<p><strong>Reviewer feedback:</strong><br>" . nl2br(htmlspecialchars((string) $detail, ENT_QUOTES, 'UTF-8')) . "</p>" : '';
            $inner = "
                <p>Hello <strong>$n</strong>,</p>
                <p>" . sprintf($thanks, "<strong>$safeOrg</strong>") . ", $sorry</p>
                $reason
                <p>$close</p>
                " . self::signHtml($chapter);
            $alt = "Hello $name,\n\n" . sprintf($thanks, $org) . ", $sorry"
                 . ($hasDetail ? "\n\nReviewer feedback:\n$detail" : '')
                 . "\n\n$close\n\n" . self::signText($chapter);
        }

        return self::send($email, $name, $subject, $inner, $alt, $chapter);
    }

    /**
     * Decision email built from the application row, so callers cannot forget the chapter or the
     * renewal flag. On approval, a new member with no password yet gets the "set your password"
     * link INSIDE this one email (members who already have a password get no button).
     * $detail is the expiry for 'approved' and the reviewer's reason for 'rejected', as in sendDecision().
     * $sharedId is the third value MemberModel::approve() returns: true when a new club membership
     * was created under an existing Member ID.
     */
    public static function sendDecisionFor(array $app, $decision, $memberId = null, $detail = '', $sharedId = false) {
        $chapter = MemberModel::chapterOf($app);

        $token = null;
        if ($decision === 'approved' && $memberId) {
            // The Generic Community row holds the password, so a club joined under an existing ID
            // returns null here and no second "set your password" link is sent.
            $link = (new MemberAuthModel())->setLinkIfNeeded((string) $memberId);
            $token = $link['token'] ?? null;
        }

        // Someone joining a club under an ID they already hold is "joining", not "renewing",
        // even though they used the renewal form to enter that ID.
        $renewal = MemberModel::isRenewal($app) && !$sharedId;

        return self::sendDecision($app['email'], $app['applicant_name'], $decision, $memberId, $detail,
                                  $chapter, $renewal, $token, (bool) $sharedId);
    }

    /** Info-request email built from the application row (chapter and renewal wording handled for you). */
    public static function sendInfoRequestFor(array $app, $message, $token) {
        return self::sendInfoRequest($app['email'], $app['applicant_name'], $message, $token,
                                     MemberModel::chapterOf($app), MemberModel::isRenewal($app));
    }

    /**
     * Sends an application back with the coordinator's message and a fresh link to edit and
     * resubmit it (the existing /resume/{token} page).
     * $chapter is the chapter key (MemberModel::chapterOf($app)), or null.
     * $renewal is true for a renewal (MemberModel::isRenewal($app)).
     */
    public static function sendInfoRequest($email, $name, $message, $token, $chapter = null, $renewal = false) {
        $url = CoreMail::appUrl() . '/resume/' . $token;
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $n = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
        $m = nl2br(htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8'));
        $org = self::org($chapter);
        $safeOrg = htmlspecialchars($org, ENT_QUOTES, 'UTF-8');

        $thanks = $renewal
            ? 'Thank you for renewing your membership with %s'
            : 'Thank you for your interest in joining %s';
        $looked = $renewal
            ? "We have gone through your renewal details and need a few additional clarifications before we can proceed further:"
            : "We have gone through your application details and need a few additional clarifications before we can proceed further:";
        $subject = $renewal
            ? "Action required: additional details for your $org renewal"
            : "Action required: additional details for your $org application";

        $inner = "
            <p>Hello <strong>$n</strong>,</p>
            <p>" . sprintf($thanks, "<strong>$safeOrg</strong>") . ". $looked</p>
            <p style='border-left:3px solid #106b9a; padding-left:14px;'>$m</p>
            <div class='btn-wrapper'><a href='$safeUrl' class='btn'>Update my application</a></div>
            <p>If the button does not work, copy and paste this link into your browser:<br><br>
               <a href='$safeUrl' style='color: #106b9a; word-break: break-all;'>$safeUrl</a></p>
            <p>This private link will expire shortly, so please update your details promptly and do not share this link.</p>
            " . self::signHtml($chapter);

        $alt = "Hello $name,\n\n" . sprintf($thanks, $org) . ". $looked\n\n$message\n\nPlease update your application here:\n$url\n\nThis private link will expire shortly, so please update your details promptly and do not share this link.\n\n" . self::signText($chapter);

        return self::send($email, $name, $subject, $inner, $alt, $chapter);
    }

    /**
     * One-time link to choose a password for the Member ID login.
     * Used for 'reset' (forgotten password). New members get the link inside the approval email
     * instead, but 'set' still works here if you ever need to resend it on its own.
     * $purpose: 'set' or 'reset'.
     * $token is the raw token from MemberAuthModel::createToken(); it is only ever stored hashed.
     * $chapter is the chapter key (MemberModel::chapterOf($app) or the member's chapter), or null.
     */
    public static function sendPasswordLink($email, $name, $memberId, $token, $purpose = 'set', $chapter = null) {
        $url = CoreMail::appUrl() . '/member/set-password/' . $token;
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $n = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
        $id = htmlspecialchars((string) $memberId, ENT_QUOTES, 'UTF-8');
        $org = self::org($chapter);
        $safeOrg = htmlspecialchars($org, ENT_QUOTES, 'UTF-8');

        if ($purpose === 'reset') {
            $subject = 'Reset your DCW Engage password';
            $intro = "We received a request to reset the password linked to Member ID <strong>$id</strong>. You can set a new password by clicking the button below.";
            $introText = "We received a request to reset the password linked to Member ID $memberId. You can set a new password here:";
            $button = 'Choose a new password';
            $ignore = "If you didn't request a password reset, you may safely ignore this email. Your existing account credentials will remain unaffected.";
        } else {
            $subject = 'Set your password for DCW Engage';
            $intro = "Welcome to <strong>$safeOrg</strong>! To complete your account setup, please set a password so you can sign in to DCW Engage using your Member ID <strong>$id</strong>.";
            $introText = "Welcome to $org! To complete your account setup, please set a password so you can sign in to DCW Engage using your Member ID $memberId. Set it here:";
            $button = 'Set my password';
            $ignore = "If you were not expecting this email, you may safely ignore it.";
        }

        $inner = "
            <p>Hello <strong>$n</strong>,</p>
            <p>$intro</p>
            <div class='btn-wrapper'><a href='$safeUrl' class='btn'>$button</a></div>
            <p>If the button does not work, copy and paste this link into your browser:<br><br>
               <a href='$safeUrl' style='color: #106b9a; word-break: break-all;'>$safeUrl</a></p>
            <p>This single-use link is meant exclusively for you and will expire shortly. $ignore</p>
            " . self::signHtml($chapter);

        $alt = "Hello $name,\n\n$introText\n$url\n\nThis single-use link is meant exclusively for you and will expire shortly. $ignore\n\n" . self::signText($chapter);

        return self::send($email, $name, $subject, $inner, $alt, $chapter);
    }

    /**
     * "Forgot your Member ID?": emails the Member ID to the address on file.
     * Called from views/member/forgot-id.php only after the username and email both matched
     * one member, so the ID is never shown on a web page. DCW itself is the sender and signer
     * because one ID covers every chapter the person holds.
     * Returns true only when a message really went out (false in dev mode).
     */
    public static function sendMemberIdReminder($email, $memberId, $name = '') {
        $e      = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $login  = CoreMail::appUrl() . '/member/login';
        $forgot = CoreMail::appUrl() . '/member/forgot';
        $hello  = trim((string) $name) !== '' ? (string) $name : 'there';

        $subject = 'Your DCW Engage Member ID';
        $inner = "
            <p>Hello <strong>" . $e($hello) . "</strong>,</p>
            <p>We received a request to remind you of your Member ID. Here it is:</p>
            <div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:14px 18px; margin:0 0 20px; line-height:1.7;'>
                <strong>Member ID:</strong> <span style='font-family:Menlo,Consolas,monospace; letter-spacing:1px;'>" . $e($memberId) . "</span>
            </div>
            <p>Use it together with your password to sign in. If you have forgotten your password too, you can <a href='" . $e($forgot) . "' style='color:#106b9a;'>reset it here</a>.</p>
            <div class='btn-wrapper'><a href='" . $e($login) . "' class='btn'>Go to sign in</a></div>
            <p style='font-size:14px; color:#64748b;'>If you did not request this, you can safely ignore this email. Your account has not been changed.</p>"
            . self::signHtml(null);

        $alt = "Hello $hello,\n\nWe received a request to remind you of your Member ID:\n\nMember ID: $memberId\n\n"
             . "Sign in here: $login\nForgot your password too? $forgot\n\n"
             . "If you did not request this, you can safely ignore this email. Your account has not been changed.\n\n"
             . self::signText(null);

        return self::send($email, $hello, $subject, $inner, $alt, null);
    }

    /**
     * Sent from the join-form verification step when the address already belongs to a member.
     * $held = MemberModel::membershipsForEmail($email).
     * $code is null when they already hold THIS chapter (no application is needed), and set
     * when they are joining another chapter: then the one-time verification code (OTP, the raw
     * 8-character value from EmailVerificationModel::request()) is included, to be typed into the
     * page they have open. There is no link.
     * $expiresAt is the code's expiry as stored (UTC); it is shown to the member in IST.
     * $formTitle is shown as the event name only (CoreMail::eventTitle()).
     * $applicantName is the name typed on the verification gate. The greeting uses it (with any age
     * typed after it removed); when empty it falls back to the name on file, then to "there".
     */
    public static function sendExistingAccount($email, array $held, $chapter, $formTitle, $code = null, $expiresAt = null, $applicantName = '') {
        $base  = CoreMail::appUrl();
        $login = $base . '/member/login';
        $renew = $base . '/membership?type=renewal';
        $e     = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $name = CoreMail::cleanName($applicantName);
        if ($name === '') $name = (string) ($held[0]['full_name'] ?? '');
        if ($name === '') $name = 'there';
        $formTitle = CoreMail::eventTitle($formTitle);   // event name only, no form-type prefix or edition/date
        $ids  = array_values(array_unique(array_map(fn($r) => (string) $r['member_id'], $held)));

        $rowsHtml = $rowsText = '';
        foreach ($held as $r) {
            $club    = MemberModel::CHAPTER_NAMES[$r['chapter']] ?? $r['chapter'];
            $expired = $r['status'] !== 'active' || strtotime((string) $r['expires_at']) < time();
            $until   = date('j M Y', strtotime((string) $r['expires_at']));
            $note    = $expired ? "expired on $until" : "valid until $until";
            $rowsHtml .= "<li><strong>" . $e($club) . "</strong>: " . $e($note) . "</li>";
            $rowsText .= "- $club: $note\n";
        }

        $holdsThis = $code === null;
        $thisName  = self::org($chapter);
        $subject   = $holdsThis
            ? 'DCW Engage account details'
            : 'DCW Engage: your verification code';

        $idHtml = implode(', ', array_map($e, $ids));
        $idText = implode(', ', $ids);
        // The code's expiry is stored in UTC. formatExpiryIST() converts it and already adds
        // the " IST" label, so do not append "IST" again where this is printed.
        $expiryText = $expiresAt ? CoreMail::formatExpiryIST((string) $expiresAt) : '';
        $shownCode  = $code !== null ? substr((string) $code, 0, 4) . '-' . substr((string) $code, 4) : '';

        $intro = $holdsThis
            ? "An application for <strong>" . $e($thisName) . "</strong> was initiated using this email address. Since you are already an active member, there is no requirement to submit another application."
            : "An application for <strong>" . $e($formTitle) . "</strong> was initiated using this email address. As you already hold a DCW account, joining an additional chapter will seamlessly connect to your existing Member ID.";
        $introText = $holdsThis
            ? "An application for $thisName was initiated using this email address. Since you are already an active member, there is no requirement to submit another application."
            : "An application for $formTitle was initiated using this email address. As you already hold a DCW account, joining an additional chapter will seamlessly connect to your existing Member ID.";

        $inner = "
            <p>Hello <strong>" . $e($name) . "</strong>,</p>
            <p>$intro</p>
            <div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:14px 18px; margin:0 0 20px; line-height:1.7;'>
                <strong>Member ID:</strong> <span style='font-family:Menlo,Consolas,monospace; letter-spacing:1px;'>$idHtml</span>
                <ul style='margin:10px 0 0; padding-left:20px;'>$rowsHtml</ul>
            </div>
            <p><strong>To sign in:</strong> Enter your Member ID and password. If you haven't set a password yet or need to reset it, simply click on <em>Forgot password</em> on the sign-in page.</p>
            <div class='btn-wrapper'><a href='" . $e($login) . "' class='btn'>Go to sign in</a></div>"
            . ($holdsThis
                ? "<p>If your membership has expired, you can <a href='" . $e($renew) . "' style='color:#106b9a;'>renew it here</a>.</p>"
                : "<p>To proceed with your application, enter the verification code below on the page where you provided your email address. This single-use code will expire on " . $e($expiryText) . ". Please do not share it with anyone.</p>
                   <div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:16px 18px; margin:0 0 20px; text-align:center;'>
                       <span style='font-family:Menlo,Consolas,monospace; font-size:28px; font-weight:bold; letter-spacing:4px; color:#0f172a;'>" . $e($shownCode) . "</span>
                   </div>")
            . "<p style='font-size:14px; color:#64748b;'>If you did not initiate this request, you may safely ignore this email. Your account remains secure and unchanged.</p>"
            . self::signHtml(null);

        $alt = "Hello $name,\n\n$introText\n\nMember ID: $idText\n$rowsText\n"
             . "To sign in, enter your Member ID and password. If you haven't set a password yet or need to reset it, click on \"Forgot password\" on the sign-in page:\n$login\n\n"
             . ($holdsThis
                ? "If your membership has expired, you can renew it here:\n$renew\n\n"
                : "To proceed with your application, enter this verification code on the page where you provided your email address (single-use, expires $expiryText). Please do not share it with anyone.\n\nVerification code: $shownCode\n\n")
             . "If you did not initiate this request, you may safely ignore this email. Your account remains secure and unchanged.\n\n" . self::signText(null);

        return self::send($email, $name, $subject, $inner, $alt, null);
    }

    /**
     * Hands the finished message to CoreMail. The only membership-specific parts are the From
     * display name ("Wiki Club AMU via DCW Engage"; DCW itself is plain "DCW Engage"), the club
     * line in the header, and the optional per-chapter Reply-To. Returns true only when a
     * message really went out (dev mode returns false, so callers show the manual link).
     *
     * Note: dev_log writes the whole plain-text body, so in dev mode a verification code from
     * sendExistingAccount() appears there. That is the only place it is logged.
     */
    private static function send($email, $name, $subject, $innerHtml, $altBody, $chapter = null) {
        $club = self::club($chapter);
        $opts = [
            'to'         => $email,
            'to_name'    => (string) $name,
            'subject'    => $subject,
            'html'       => $innerHtml,
            'alt'        => $altBody,
            'from_name'  => $club ? $club . ' via DCW Engage' : 'DCW Engage',
            'header_sub' => $club,
            'dev_result' => false,
            'dev_log'    => "Membership email to $email: $subject\n$altBody",
        ];
        if ($chapter && !empty(self::CONTACT_EMAILS[$chapter])) {
            $opts['reply_to'] = [self::CONTACT_EMAILS[$chapter], self::signoff($chapter)[0]];
        }
        return CoreMail::send($opts);
    }
}
