<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Choose a new password - DCW Engage</title>
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
            background: #fff; width: 100%; max-width: 420px; padding: 40px 32px;
            border-radius: 10px; border: 1px solid #e2e8f0; box-shadow: 0 4px 16px rgba(0,0,0,0.04);
        }
        h1 { font-size: 22px; margin: 0 0 6px; color: var(--primary-color); }
        .sub { font-size: 14px; color: #64748b; margin: 0 0 28px; line-height: 1.6; }
        .sub strong { color: #1e293b; }
        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
        input[type=password] {
            width: 100%; padding: 12px; margin-bottom: 6px; border: 1px solid #e2e8f0;
            border-radius: 6px; font-family: inherit; font-size: 15px;
        }
        input:focus { outline: 2px solid var(--primary-color); outline-offset: -1px; border-color: transparent; }
        .hint { font-size: 12px; color: #64748b; margin: 0 0 18px; line-height: 1.5; }
        button {
            width: 100%; padding: 13px; background: var(--primary-color); color: #fff;
            border: none; border-radius: 6px; font-family: inherit; font-size: 15px; font-weight: 600; cursor: pointer;
        }
        button:hover { background: #0d587f; }
        .error {
            background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
            padding: 12px 14px; border-radius: 6px; font-size: 14px; margin-bottom: 20px; line-height: 1.55;
        }
        .foot { font-size: 13px; margin: 22px 0 0; text-align: center; }
        .foot a { color: var(--primary-color); }
    </style>
</head>
<body>
    <div class="card">
        <?php if (!$reset): ?>
            <h1>Link not valid</h1>
            <p class="sub">
                This reset link has expired, has already been used, or was
                replaced by a newer one. Reset links last an hour and work once.
            </p>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <p class="foot"><a href="/admin/forgot-password">Request a new link</a></p>
        <?php else: ?>
            <h1>Choose a new password</h1>
            <p class="sub">
                Setting a new password for
                <strong><?= htmlspecialchars($reset['email']) ?></strong>.
                Anyone signed in to this account elsewhere will be signed out.
            </p>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

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
                <div style="height:12px;"></div>

                <button type="submit">Update password</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
