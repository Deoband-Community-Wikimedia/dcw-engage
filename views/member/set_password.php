<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../models/MemberAuthModel.php';

/**
 * /member/set-password/{token}: choose a first password, or a new one after "forgot password".
 * Opening the link (GET) changes nothing, so an email scanner that prefetches it cannot use it up;
 * the token is spent only when the form is submitted.
 */
global $memberSetToken;
$token = (string) ($memberSetToken ?? '');
header('Referrer-Policy: no-referrer');

$model = new MemberAuthModel();
$link = $token !== '' ? $model->findValidToken($token) : null;
$error = '';

if ($link && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        try {
            $model->setPassword($token, $password);
            $_SESSION['member_notice'] = 'Your password is saved. You can now sign in with your Member ID.';
            header('Location: /member/login');
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
            $link = $model->findValidToken($token);   // may have been used or expired in the meantime
        }
    }
}

$pageTitle = 'Choose a password';
$heading = 'Choose a password';
$sub = $link ? 'For Member ID ' . $link['member_id'] . '. At least ' . MemberAuthModel::MIN_PASSWORD_LENGTH . ' characters.' : '';
require __DIR__ . '/_head.php';
?>
<?php if (!$link): ?>
        <div class="error">This link is no longer valid. It may have expired or already been used.</div>
        <p class="links"><a href="/member/forgot">Get a new link</a></p>
<?php else: ?>
        <form method="POST" autocomplete="off">
            <?= CSRF::getInputField() ?>

            <label for="password">New password</label>
            <input type="password" name="password" id="password" required autofocus autocomplete="new-password"
                   minlength="<?= (int) MemberAuthModel::MIN_PASSWORD_LENGTH ?>" maxlength="<?= (int) MemberAuthModel::MAX_PASSWORD_BYTES ?>">
            <p class="hint">A few random words make a strong, easy-to-remember password.</p>

            <label for="password_confirm">Type it again</label>
            <input type="password" name="password_confirm" id="password_confirm" required autocomplete="new-password"
                   minlength="<?= (int) MemberAuthModel::MIN_PASSWORD_LENGTH ?>" maxlength="<?= (int) MemberAuthModel::MAX_PASSWORD_BYTES ?>">

            <button type="submit">Save password</button>
        </form>
<?php endif; ?>
<?php require __DIR__ . '/_foot.php'; ?>
