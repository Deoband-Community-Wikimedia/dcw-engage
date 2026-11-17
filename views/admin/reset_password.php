<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/PasswordResetModel.php';

/**
 * Public page. Consumes a reset token and sets a new password.
 *
 * Completing this logs out every session the account already had open, which
 * is handled by Auth::check() comparing each session against the account's
 * password_changed_at stamp. That is the whole point of a reset when the
 * reason for it is "somebody else may be in my account".
 */

$resets = new PasswordResetModel();

// On the query string when the link is first opened, then carried through the
// POST so a rejected password does not lose it.
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$reset = $resets->findUsableByToken($token);

$error = '';
$minLength = Auth::MIN_PASSWORD_LENGTH;

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (strlen($password) < $minLength) {
        $error = 'Password must be at least ' . $minLength . ' characters.';
    } elseif ($password !== $confirm) {
        $error = 'Those passwords do not match.';
    } else {
        $account = $resets->complete($token, $password);

        if (!$account) {
            $error = 'This link is no longer valid. Please request a new one.';
            $reset = null;
        } else {
            // Self-serve event: the actor is the account whose password changed.
            AuditLog::record('password.reset', $account['id'], $account['email'], $account['email'], 'Password reset via emailed link');

            // Deliberately not signed in here. Sending them to the login form
            // confirms the new password actually works, and keeps this page
            // from being a way to obtain a session.
            $_SESSION['login_notice'] = 'Password updated. Sign in with your new password.';
            header('Location: /admin/login');
            exit;
        }
    }
}

engage_header([
    'title'    => 'Choose a new password',
    'heading'  => $reset ? 'Choose a new password' : 'Link not valid',
    'lead'     => $reset
        ? 'Setting a new password for ' . $reset['email'] . '. Anyone signed in to this account elsewhere will be signed out.'
        : 'This reset link has expired, has already been used, or was replaced by a newer one. Reset links last an hour and work once.',
    'auth'     => true,
    'noindex'  => true,
    'referrer' => 'no-referrer',   // the URL carries the one-time token
]);
?>
<div class="auth-card">
<?php if ($error): ?>
    <div class="alert error" role="alert"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (!$reset): ?>
    <p class="alt" style="margin-top:0;"><a href="/admin/forgot-password">Request a new link</a></p>
<?php else: ?>
    <form method="POST" autocomplete="off">
        <?= CSRF::getInputField() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">

        <label for="password">New password</label>
        <input type="password" name="password" id="password" required autofocus
               minlength="<?= $minLength ?>" autocomplete="new-password">
        <p class="hint">
            At least <?= $minLength ?> characters. This workspace holds applicant
            information, so use a password you do not use anywhere else.
        </p>

        <label for="password_confirm">Confirm new password</label>
        <input type="password" name="password_confirm" id="password_confirm" required
               minlength="<?= $minLength ?>" autocomplete="new-password">

        <button type="submit" class="btn-primary">Update password</button>
    </form>
<?php endif; ?>
</div>
<?php engage_footer(); ?>
