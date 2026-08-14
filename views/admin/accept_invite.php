<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../models/InviteModel.php';

/**
 * Public page — the visitor has no account yet, so this is the one route
 * under /admin that must not call Auth::requireLogin().
 *
 * Holding the token is the proof of identity. It came from an email we sent
 * to that address, which is why there is no separate verification step.
 */

$invites = new InviteModel();

// The token arrives on the query string on first click, then rides the POST
// so a failed password attempt does not lose it.
$token = $_GET['token'] ?? $_POST['token'] ?? '';

// Someone already signed in has no business creating a second account.
if (Auth::check()) {
    header('Location: /admin/dashboard');
    exit;
}

$invite = $invites->findUsableByToken($token);
$error = '';
$done = false;

if ($invite && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif (strlen($password) < InviteModel::MIN_PASSWORD_LENGTH) {
        $error = 'Password must be at least ' . InviteModel::MIN_PASSWORD_LENGTH . ' characters.';
    } elseif ($password !== $confirm) {
        $error = 'Those passwords do not match.';
    } else {
        $account = $invites->redeem($token, $password);

        if (!$account) {
            // Only reachable if the invite was revoked or consumed between
            // loading this page and submitting it.
            $error = 'This invitation is no longer valid. Ask an owner to send a new one.';
            $invite = null;
        } else {
            // The actor is the new account itself — a self-serve event, not an
            // owner action — so it is logged under their own identity.
            AuditLog::record('invite.accepted', $account['id'], $account['email'], $account['email'], 'Account created from invitation');

            // Sign them straight in. They have just proved control of the
            // inbox and chosen the password, so a login form here would only
            // ask for what they typed thirty seconds ago.
            session_regenerate_id(true);
            $_SESSION['admin_id']    = $account['id'];
            $_SESSION['admin_email'] = $account['email'];
            $_SESSION['admin_role']  = $account['role'];
            // A brand new account has never changed its password, so the
            // stamp starts null and matches what Auth::check() will read.
            $_SESSION['admin_pw_stamp'] = null;

            $done = true;
        }
    }
}

if ($done) {
    header('Location: /admin/dashboard');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Accept invitation - DCW Engage</title>
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
        .hint { font-size: 12px; color: #64748b; margin: 0 0 18px; }
        button {
            width: 100%; padding: 13px; background: var(--primary-color); color: #fff;
            border: none; border-radius: 6px; font-family: inherit; font-size: 15px; font-weight: 600; cursor: pointer;
        }
        button:hover { background: #0d587f; }
        .error {
            background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
            padding: 12px 14px; border-radius: 6px; font-size: 14px; margin-bottom: 20px; line-height: 1.55;
        }
        .foot { font-size: 13px; color: #64748b; margin: 22px 0 0; text-align: center; }
        .foot a { color: var(--primary-color); }
    </style>
</head>
<body>
    <div class="card">
        <?php if (!$invite): ?>
            <h1>Invitation not valid</h1>
            <p class="sub">
                This link has expired, has already been used, or was withdrawn.
                Invitations are single use and time limited.
            </p>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <p class="sub" style="margin-bottom:0;">
                Ask a workspace owner to send you a new invitation.
            </p>
        <?php else: ?>
            <h1>Set your password</h1>
            <p class="sub">
                You have been invited to the DCW Engage organizer workspace as
                <strong><?= htmlspecialchars($invite['email']) ?></strong>.
                Choose a password to activate the account.
            </p>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">

                <label for="password">Password</label>
                <input type="password" name="password" id="password" required autofocus
                       minlength="<?= InviteModel::MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
                <p class="hint">
                    At least <?= InviteModel::MIN_PASSWORD_LENGTH ?> characters. This workspace holds
                    applicant information, so use a password you do not use anywhere else.
                </p>

                <label for="password_confirm">Confirm password</label>
                <input type="password" name="password_confirm" id="password_confirm" required
                       minlength="<?= InviteModel::MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
                <div style="height:12px;"></div>

                <button type="submit">Activate account</button>
            </form>

            <p class="foot">
                Not expecting this? You can close this page &mdash; nothing is
                created until you set a password.
            </p>
        <?php endif; ?>
    </div>
</body>
</html>
