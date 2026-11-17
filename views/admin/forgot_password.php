<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/PasswordResetModel.php';

/**
 * Public page. Asks for an address and, if it belongs to an organizer, emails
 * a reset link.
 *
 * The response is identical whether or not the address has an account, and
 * whether or not the mail actually went out. Anything that varies with the
 * outcome — different wording, a different status code, a visibly different
 * delay — turns this form into a way to discover which addresses are
 * organizers, which is exactly the list an attacker wants.
 */

if (Auth::check()) {
    header('Location: /admin/dashboard');
    exit;
}

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (!CSRF::consumeSubmitToken($_POST['submit_token'] ?? '')) {
        // Repeat submission from a double click. Show the same confirmation
        // the first one produced rather than sending a second email.
        $sent = true;
    } else {
        $email = trim($_POST['email'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $resets = new PasswordResetModel();
            $reset = $resets->request($email);

            // Null means unknown address or too many recent requests. Both are
            // silent on purpose.
            if ($reset) {
                Mailer::sendPasswordReset(
                    $reset['email'],
                    $reset['token'],
                    $reset['expires_at']
                );
            }
        }

        // Always the same answer, including for a malformed address.
        $sent = true;
    }
}

engage_header([
    'title'   => 'Reset password',
    'heading' => $sent ? 'Check your email' : 'Reset your password',
    'lead'    => $sent
        ? 'If that address belongs to an organizer account, a reset link is on its way. It expires in an hour and can be used once.'
        : 'Enter the email address on your organizer account and we will send you a link to choose a new password.',
    'back'    => ['Back to sign in', '/admin/login'],
    'auth'    => true,
    'noindex' => true,
]);
?>
<div class="auth-card">
<?php if ($sent): ?>
    <div class="notice">
        Nothing arrived? Check spam, and make sure you used the address
        your account was created with. Asking again sends a fresh link
        and cancels the previous one.
    </div>
    <p class="alt"><a href="/admin/login">Back to sign in</a></p>
<?php else: ?>
    <?php if ($error): ?>
        <div class="alert error" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <?= CSRF::getInputField() ?>
        <?= CSRF::getSubmitField() ?>

        <label for="email">Email</label>
        <input type="email" name="email" id="email" required autofocus
               autocomplete="email" placeholder="you@dcwwiki.org">

        <button type="submit" class="btn-primary">Send reset link</button>
    </form>

    <p class="alt"><a href="/admin/login">Back to sign in</a></p>
<?php endif; ?>
</div>
<script>
    // Stops the obvious double click. The single-use submit token on the
    // server is what actually guarantees one email.
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var button = form.querySelector('button[type=submit]');
            if (!button || button.disabled) return;
            button.style.minWidth = button.offsetWidth + 'px';
            button.disabled = true;
            button.textContent = 'Sending...';
        });
    });
</script>
<?php engage_footer(); ?>
