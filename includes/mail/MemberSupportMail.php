<?php
// includes/mail/MemberSupportMail.php
// Member support conversations (complaints, suggestions, questions; tracking IDs CM-, SG-, HQ-).
// Privacy rule: these emails NEVER contain the message text, the subject line the member wrote,
// or any staff name. They say that something is waiting and point at the signed-in dashboard.
// Callers must not let the result change what staff see; a failed email never undoes a saved reply.
require_once __DIR__ . '/CoreMail.php';

class MemberSupportMail {
    /**
     * $kind: 'reply'  = DCW Support answered
     *        'status' = the status changed (suggestion progress, or a conversation was resolved)
     */
    public static function update($email, $memberName, $trackingId, $kind = 'reply') {
        $name = CoreMail::e($memberName !== '' ? $memberName : 'member');
        $tid  = CoreMail::e($trackingId);
        $url  = CoreMail::appUrl() . '/member/dashboard';

        if ($kind === 'status') {
            $subject = 'An update on your conversation with DCW Support';
            $line = "There is an update on your conversation with DCW Support (reference <strong>$tid</strong>).";
            $alt  = "There is an update on your conversation with DCW Support (reference $trackingId).";
        } else {
            $subject = 'You have a new reply from DCW Support';
            $line = "DCW Support has replied to your conversation (reference <strong>$tid</strong>).";
            $alt  = "DCW Support has replied to your conversation (reference $trackingId).";
        }

        $inner = "
            <p>Hello <strong>$name</strong>,</p>
            <p>$line</p>
            <p>For your privacy we do not put the message in this email. Sign in with your Member ID to read it.</p>"
            . CoreMail::button($url, 'Open my dashboard') . "
            <p style='margin-bottom:0;'>If you were not expecting this, you can ignore this email.</p>";

        return CoreMail::send([
            'to' => $email, 'to_name' => $memberName,
            'subject' => $subject,
            'html' => $inner,
            'alt' => "Hello $memberName,\n\n$alt\n\nFor your privacy we do not put the message in this email. "
                   . "Sign in with your Member ID to read it:\n$url",
            'dev_result' => false, 'dev_log' => "Member support email to $email: $subject ($trackingId)",
        ]);
    }
}
