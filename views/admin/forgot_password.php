<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mailer.php';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Reset password - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary-color: #106b9a; }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: #f8fafc; color: #1e293b; margin: 0;
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .card {
            background: #fff; width: 100%; max-width: 400px; padding: 40px 32px;
            border-radius: 10px; border: 1px solid #e2e8f0; box-shadow: 0 4px 16px rgba(0,0,0,0.04);
        }
        h1 { font-size: 22px; margin: 0 0 6px; color: var(--primary-color); }
        .sub { font-size: 14px; color: #64748b; margin: 0 0 28px; line-height: 1.6; }
        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
        input[type=email] {
            width: 100%; padding: 12px; margin-bottom: 18px; border: 1px solid #e2e8f0;
            border-radius: 6px; font-family: inherit; font-size: 15px;
        }
        input:focus { outline: 2px solid var(--primary-color); outline-offset: -1px; border-color: transparent; }
        button {
            width: 100%; padding: 13px; background: var(--primary-color); color: #fff;
            border: none; border-radius: 6px; font-family: inherit; font-size: 15px; font-weight: 600; cursor: pointer;
        }
        button:hover { background: #0d587f; }
        button:disabled { opacity: 0.6; cursor: default; }
        .error {
            background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
            padding: 12px 14px; border-radius: 6px; font-size: 14px; margin-bottom: 20px;
        }
        .notice {
            background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534;
            padding: 14px 16px; border-radius: 6px; font-size: 14px; line-height: 1.6;
        }
        .foot { font-size: 13px; margin: 22px 0 0; text-align: center; }
        .foot a { color: var(--primary-color); }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($sent): ?>
            <h1>Check your email</h1>
            <p class="sub">
                If that address belongs to an organizer account, a reset link is
                on its way. It expires in an hour and can be used once.
            </p>
            <div class="notice">
                Nothing arrived? Check spam, and make sure you used the address
                your account was created with. Asking again sends a fresh link
                and cancels the previous one.
            </div>
            <p class="foot"><a href="/admin/login">Back to sign in</a></p>
        <?php else: ?>
            <h1>Reset your password</h1>
            <p class="sub">
                Enter the email address on your organizer account and we will
                send you a link to choose a new password.
            </p>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <?= CSRF::getInputField() ?>
                <?= CSRF::getSubmitField() ?>

                <label for="email">Email</label>
                <input type="email" name="email" id="email" required autofocus
                       autocomplete="email" placeholder="you@dcwwiki.org">

                <button type="submit">Send reset link</button>
            </form>

            <p class="foot"><a href="/admin/login">Back to sign in</a></p>
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
</body>
</html>
