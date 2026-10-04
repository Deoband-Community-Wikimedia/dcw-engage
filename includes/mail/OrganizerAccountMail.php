<?php
// includes/mail/OrganizerAccountMail.php
// Emails for organizer workspace accounts: invitations and password resets.
require_once __DIR__ . '/CoreMail.php';

class OrganizerAccountMail {
    /**
     * Invites an organizer to join the workspace.
     *
     * Returns true only when the message actually went out. The caller shows
     * the invite link on screen when this returns false, so a mail
     * misconfiguration cannot strand an invitation that already exists in the
     * database with no way to deliver it.
     */
    public static function invite($email, $token, $invitedByEmail, $expiresAt) {
        $e = fn($s) => CoreMail::e($s);
        $inviteUrl = CoreMail::appUrl() . '/admin/accept-invite?token=' . urlencode($token);
        $url = $e($inviteUrl);
        $expiresTime = CoreMail::formatExpiryIST($expiresAt);
        $safeInvitedBy = $e($invitedByEmail);

        $inner = "
            <p>Hello,</p>
            <p><strong>$safeInvitedBy</strong> has invited you to join the DCW Engage organizer workspace.</p>
            <p>Use the button below to choose a password and activate your account. The workspace holds applicant information, so please pick a password you do not use anywhere else.</p>
            <div class='btn-wrapper'><a href='$url' class='btn'>Set My Password</a></div>
            <p>If the button doesn't work, copy and paste this link into your browser:<br><br><a href='$url' style='color: #106b9a; word-break: break-all;'>$url</a></p>
            <p style='margin-bottom:0;'>This invitation expires at <strong>$expiresTime</strong> and can only be used once. If you were not expecting it, you can ignore this email — no account is created until the link is opened.</p>";

        $alt = "Hello,\n\n$invitedByEmail has invited you to join the DCW Engage organizer workspace.\n\nSet your password here:\n$inviteUrl\n\nThis invitation expires at $expiresTime and can only be used once.\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'subject' => 'You have been invited to DCW Engage',
            'html' => $inner, 'alt' => $alt,
            'dev_result' => false, 'dev_log' => "Organizer invite for $email: $inviteUrl",
        ]);
    }

    /**
     * Sends a password reset link.
     *
     * Unlike an invitation, the caller must NOT surface whether this
     * succeeded. The forgot-password page answers identically for every
     * address, so a failure here is logged and swallowed rather than shown.
     */
    public static function passwordReset($email, $token, $expiresAt) {
        $e = fn($s) => CoreMail::e($s);
        $resetUrl = CoreMail::appUrl() . '/admin/reset-password?token=' . urlencode($token);
        $url = $e($resetUrl);
        $expiresTime = CoreMail::formatExpiryIST($expiresAt);

        $inner = "
            <p>Hello,</p>
            <p>Somebody asked to reset the password for the DCW Engage organizer account registered to this address.</p>
            <div class='btn-wrapper'><a href='$url' class='btn'>Choose a New Password</a></div>
            <p>If the button doesn't work, copy and paste this link into your browser:<br><br><a href='$url' style='color: #106b9a; word-break: break-all;'>$url</a></p>
            <p><strong>This link expires at $expiresTime</strong> and can only be used once.</p>
            <p style='margin-bottom:0;'>If this was not you, ignore this email and nothing changes. Your current password keeps working. If it keeps happening, tell a workspace owner.</p>";

        $alt = "Hello,\n\nSomebody asked to reset the password for the DCW Engage account registered to this address.\n\nChoose a new password here:\n$resetUrl\n\nThis link expires at $expiresTime and can only be used once.\n\nIf this was not you, ignore this email and nothing changes.\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'subject' => 'Reset your DCW Engage password',
            'html' => $inner, 'alt' => $alt,
            'dev_result' => false, 'dev_log' => "Password reset for $email: $resetUrl",
        ]);
    }
}
