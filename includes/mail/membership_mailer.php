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
            ? 'Thanks for renewing your membership with %s'
            : 'Thanks for your interest in joining %s';

        if ($decision === 'approved') {
            $until = $hasDetail ? date('j M Y', strtotime($detail)) : '';
            $id = htmlspecialchars((string) $memberId, ENT_QUOTES, 'UTF-8');
            $subject = $renewal
                ? "Your $org membership is renewed"
                : "Welcome to $org: your membership is confirmed";
            $good = $renewal
                ? "Good news: your renewal is confirmed, and we're glad to keep you with us!"
                : "Good news: your membership is confirmed, and we're glad to have you with us!";
            $keep = $renewal
                ? "Your member ID stays the same. Keep it handy for next time."
                : ($sharedId
                    ? "This is the same Member ID you already use for the DCW Generic Community, so you keep a single ID for all your memberships. Both appear on your dashboard."
                    : "Keep your member ID handy: you'll need it when you renew.");

            // Password section. New members (token given) are told to set a password and get the
            // button; renewing members who already have one are told their login still works.
            $pwHtml = '';
            $pwText = '';
            if ($passwordToken) {
                $url = CoreMail::appUrl() . '/member/set-password/' . $passwordToken;
                $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                $pwHtml = "
                <h2 style='font-size:18px; margin:28px 0 8px; color:#106b9a;'>Next step: set your password</h2>
                <p>To log in to DCW Engage you need a password. Use the button below to choose one. After that, you can sign in with your Member ID <strong>$id</strong> and your new password to see your membership and request support.</p>
                <div class='btn-wrapper'><a href='$safeUrl' class='btn'>Set my password</a></div>
                <p style='font-size:14px;'>If the button doesn't work, copy and paste this link into your browser:<br>
                   <a href='$safeUrl' style='color:#106b9a; word-break:break-all;'>$safeUrl</a></p>
                <p style='font-size:14px; color:#64748b;'>This link is private to you, works once and will expire, so please set your password soon and don't share the link.</p>";
                $pwText = "\n\nNEXT STEP: SET YOUR PASSWORD\nTo log in to DCW Engage you need a password. Choose one using this link, then sign in with your Member ID $memberId and your new password to see your membership and request support:\n$url\n\nThis link is private to you, works once and will expire, so please set your password soon and don't share the link.";
            } else {
                // No fresh link (renewing member, a club joined under an existing ID, or a link
                // could not be created): still advise.
                $pwHtml = "<p><strong>Password:</strong> sign in to DCW Engage with your Member ID <strong>$id</strong> and your password. If you haven't set a password yet, or have forgotten it, choose \"Forgot password\" on the sign-in page and we'll email you a link.</p>";
                $pwText = "\n\nPASSWORD: sign in to DCW Engage with your Member ID $memberId and your password. If you haven't set a password yet, or have forgotten it, choose \"Forgot password\" on the sign-in page and we'll email you a link.";
            }

            $inner = "
                <p>Hello <strong>$n</strong>,</p>
                <p>" . sprintf($thanks, "<strong>$safeOrg</strong>") . ". $good</p>
                <div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:14px 18px; margin:0 0 20px; line-height:1.7;'>
                    <strong>Member ID:</strong> <span style='font-family:Menlo,Consolas,monospace; letter-spacing:1px;'>$id</span>" . ($until ? "<br><strong>Valid until:</strong> $until" : '') . "
                </div>
                <p>$keep</p>
                " . ($passwordToken ? "<p style='background:#fffbeb; border:1px solid #fcd34d; border-radius:6px; padding:12px 16px;'><strong>Important:</strong> please set your password below so you can log in to DCW Engage.</p>" : '') . "
                $pwHtml
                " . self::signHtml($chapter);
            $alt = "Hello $name,\n\n" . sprintf($thanks, $org) . ". $good\n\nMember ID: $memberId"
                 . ($until ? "\nValid until: $until" : '') . "\n\n$keep" . ($passwordToken ? "\n\nIMPORTANT: please set your password below so you can log in to DCW Engage." : '') . $pwText . "\n\n" . self::signText($chapter);
        } else {
            $subject = $renewal
                ? "Your $org membership renewal"
                : "Your $org membership application";
            $sorry = $renewal
                ? "and for the time you put into your renewal. After review, we're not able to approve it at this time."
                : "and for the time you put into your application. After review, we're not able to approve your membership at this time.";
            $close = $renewal
                ? "We appreciate your interest in staying part of the community."
                : "We appreciate your interest and hope our paths cross again.";
            $reason = $hasDetail
                ? "<p><strong>Reviewer notes:</strong><br>" . nl2br(htmlspecialchars((string) $detail, ENT_QUOTES, 'UTF-8')) . "</p>" : '';
            $inner = "
                <p>Hello <strong>$n</strong>,</p>
                <p>" . sprintf($thanks, "<strong>$safeOrg</strong>") . ", $sorry</p>
                $reason
                <p>$close</p>
                " . self::signHtml($chapter);
            $alt = "Hello $name,\n\n" . sprintf($thanks, $org) . ", $sorry"
                 . ($hasDetail ? "\n\nReviewer notes:\n$detail" : '')
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
            ? 'Thanks for renewing your membership with %s'
            : 'Thanks for your interest in joining %s';
        $looked = $renewal
            ? "We've looked at your renewal and need one more thing from you before we can decide:"
            : "We've looked at your application and need one more thing from you before we can decide:";
        $subject = $renewal
            ? "One more step for your $org membership renewal"
            : "One more step for your $org membership application";

        $inner = "
            <p>Hello <strong>$n</strong>,</p>
            <p>" . sprintf($thanks, "<strong>$safeOrg</strong>") . ". $looked</p>
            <p style='border-left:3px solid #106b9a; padding-left:14px;'>$m</p>
            <div class='btn-wrapper'><a href='$safeUrl' class='btn'>Update my application</a></div>
            <p>If the button doesn't work, copy and paste this link into your browser:<br><br>
               <a href='$safeUrl' style='color: #106b9a; word-break: break-all;'>$safeUrl</a></p>
            <p>This link is private to you and will expire, so please don't share it.</p>
            " . self::signHtml($chapter);

        $alt = "Hello $name,\n\n" . sprintf($thanks, $org) . ". $looked\n\n$message\n\nUpdate your application here:\n$url\n\nThis link is private to you and will expire, so please don't share it.\n\n" . self::signText($chapter);

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
            $intro = "We got a request to reset the password for Member ID <strong>$id</strong>. Choose a new one with the button below.";
            $introText = "We got a request to reset the password for Member ID $memberId. Choose a new one here:";
            $button = 'Choose a new password';
            $ignore = "Didn't ask for this? You can ignore this email. Your password stays as it is.";
        } else {
            $subject = 'Set your password for DCW Engage';
            $intro = "Welcome to <strong>$safeOrg</strong>! One last step: choose a password, and you can log in to DCW Engage with your Member ID <strong>$id</strong>.";
            $introText = "Welcome to $org! One last step: choose a password, and you can log in to DCW Engage with your Member ID $memberId. Set it here:";
            $button = 'Set my password';
            $ignore = "Didn't expect this email? You can ignore it.";
        }

        $inner = "
            <p>Hello <strong>$n</strong>,</p>
            <p>$intro</p>
            <div class='btn-wrapper'><a href='$safeUrl' class='btn'>$button</a></div>
            <p>If the button doesn't work, copy and paste this link into your browser:<br><br>
               <a href='$safeUrl' style='color: #106b9a; word-break: break-all;'>$safeUrl</a></p>
            <p>This link is private to you, works once and will expire, so please don't share it. $ignore</p>
            " . self::signHtml($chapter);

        $alt = "Hello $name,\n\n$introText\n$url\n\nThis link is private to you, works once and will expire, so please don't share it. $ignore\n\n" . self::signText($chapter);

        return self::send($email, $name, $subject, $inner, $alt, $chapter);
    }

    /**
     * Sent from the join-form verification step when the address already belongs to a member.
     * $held = MemberModel::membershipsForEmail($email) (must include full_name).
     * $verifyUrl is null when they already hold THIS chapter (no application is needed), and set
     * when they are joining another chapter (the normal verification link is included).
     */
    public static function sendExistingAccount($email, array $held, $chapter, $formTitle, $verifyUrl = null, $expiresAt = null) {
        $base  = CoreMail::appUrl();
        $login = $base . '/member/login';
        $renew = $base . '/membership?type=renewal';
        $e     = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $name = (string) ($held[0]['full_name'] ?? '');
        if ($name === '') $name = 'there';
        $ids  = array_values(array_unique(array_map(fn($r) => (string) $r['member_id'], $held)));

        $rowsHtml = $rowsText = '';
        foreach ($held as $r) {
            $club    = MemberModel::CHAPTER_NAMES[$r['chapter']] ?? $r['chapter'];
            $expired = $r['status'] !== 'active' || strtotime((string) $r['expires_at']) < time();
            $until   = date('j M Y', strtotime((string) $r['expires_at']));
            $note    = $expired ? "expired $until" : "valid until $until";
            $rowsHtml .= "<li><strong>" . $e($club) . "</strong>: " . $e($note) . "</li>";
            $rowsText .= "- $club: $note\n";
        }

        $holdsThis = $verifyUrl === null;
        $thisName  = self::org($chapter);
        $subject   = $holdsThis
            ? 'You already have a DCW account'
            : 'You already have a DCW account: continue your application';

        $idHtml = implode(', ', array_map($e, $ids));
        $idText = implode(', ', $ids);
        $expiryText = $expiresAt ? date('j M Y, g:i a', strtotime((string) $expiresAt)) : '';

        $intro = $holdsThis
            ? "Someone (hopefully you) tried to apply for <strong>" . $e($thisName) . "</strong> using this email address. You are already a member, so there is no need to apply again."
            : "Someone (hopefully you) started an application for <strong>" . $e($formTitle) . "</strong> using this email address. You already have a DCW account, and joining another chapter keeps your existing Member ID.";
        $introText = $holdsThis
            ? "Someone (hopefully you) tried to apply for $thisName using this email address. You are already a member, so there is no need to apply again."
            : "Someone (hopefully you) started an application for $formTitle using this email address. You already have a DCW account, and joining another chapter keeps your existing Member ID.";

        $inner = "
            <p>Hello <strong>" . $e($name) . "</strong>,</p>
            <p>$intro</p>
            <div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:14px 18px; margin:0 0 20px; line-height:1.7;'>
                <strong>Member ID:</strong> <span style='font-family:Menlo,Consolas,monospace; letter-spacing:1px;'>$idHtml</span>
                <ul style='margin:10px 0 0; padding-left:20px;'>$rowsHtml</ul>
            </div>
            <p><strong>To sign in</strong>, use your Member ID and password. If you have forgotten your password or never set one, choose <em>Forgot password</em> on the sign-in page and we will email you a link.</p>
            <div class='btn-wrapper'><a href='" . $e($login) . "' class='btn'>Go to sign in</a></div>"
            . ($holdsThis
                ? "<p>If your membership has expired, you can <a href='" . $e($renew) . "' style='color:#106b9a;'>renew it here</a>.</p>"
                : "<p>To continue with your application, open the link below in this browser. It works once and expires on " . $e($expiryText) . ".</p>
                   <div class='btn-wrapper'><a href='" . $e($verifyUrl) . "' class='btn'>Continue my application</a></div>
                   <p style='font-size:14px;'>If the button doesn't work, copy and paste this link into your browser:<br>
                   <a href='" . $e($verifyUrl) . "' style='color:#106b9a; word-break:break-all;'>" . $e($verifyUrl) . "</a></p>")
            . "<p style='font-size:14px; color:#64748b;'>Didn't do this? You can ignore this email. Nothing has changed on your account.</p>"
            . self::signHtml(null);

        $alt = "Hello $name,\n\n$introText\n\nMember ID: $idText\n$rowsText\n"
             . "To sign in, use your Member ID and password. If you have forgotten your password or never set one, choose \"Forgot password\" on the sign-in page:\n$login\n\n"
             . ($holdsThis
                ? "If your membership has expired, renew it here:\n$renew\n\n"
                : "To continue with your application, open this link in this browser (works once, expires $expiryText):\n$verifyUrl\n\n")
             . "Didn't do this? You can ignore this email. Nothing has changed on your account.\n\n" . self::signText(null);

        return self::send($email, $name, $subject, $inner, $alt, null);
    }

    /**
     * Hands the finished message to CoreMail. The only membership-specific parts are the From
     * display name ("Wiki Club AMU via DCW Engage"; DCW itself is plain "DCW Engage"), the club
     * line in the header, and the optional per-chapter Reply-To. Returns true only when a
     * message really went out (dev mode returns false, so callers show the manual link).
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
