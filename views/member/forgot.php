<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../models/MemberAuthModel.php';
require_once __DIR__ . '/../../includes/mail/membership_mailer.php';

/**
 * First-time password setup and "forgot password": the member gives their Member ID AND the email
 * on file, and if they match, a one-time link is emailed. The page says the same thing whether or not
 * they matched, so it cannot be used to find out who is a member.
 */
$error = '';
$notice = '';
$memberId = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $memberId = MemberAuthModel::normaliseId((string) ($_POST['member_id'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));

    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif ($memberId === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter your Member ID and the email address you joined with.';
    } else {
        try {
            $link = (new MemberAuthModel())->requestLink($memberId, $email);
            if ($link) {
                $m = $link['member'];
                MembershipMailer::sendPasswordLink($m['email'], $m['full_name'], $m['member_id'], $link['token'], $link['purpose'], $m['chapter']);
            }
        } catch (Throwable $e) {
            app_log("Member password link failed for $memberId: " . $e->getMessage());
        }
        // Same answer, regardless of what happened.
        $notice = 'If those details match a member, we have emailed a link to choose a password. It can take a minute to arrive, so check your spam folder too.';
    }
}

$pageTitle = 'Set or reset password';
$heading = 'Set or reset your password';
$sub = 'Enter your Member ID and the email address you joined with. We will email you a link to choose a password.';
require __DIR__ . '/_head.php';
?>
        <form method="POST" autocomplete="off">
            <?= CSRF::getInputField() ?>

            <label for="member_id">Member ID</label>
            <input type="text" name="member_id" id="member_id" class="mono" required autofocus
                   maxlength="12" placeholder="e.g. A48213977" autocapitalize="characters" spellcheck="false"
                   value="<?= htmlspecialchars($memberId, ENT_QUOTES) ?>">

            <label for="email">Email</label>
            <input type="email" name="email" id="email" required value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">

            <button type="submit">Email me a link</button>
        </form>

        <p class="links"><a href="/member/login">&larr; Back to sign in</a></p>
<?php require __DIR__ . '/_foot.php'; ?>
