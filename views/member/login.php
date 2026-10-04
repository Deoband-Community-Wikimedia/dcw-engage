<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../models/MemberAuthModel.php';
require_once __DIR__ . '/../../includes/member_session.php';

// Where to land after a successful sign in (only /support for now).
$next = $_GET['next'] ?? $_POST['next'] ?? '';
if (!MemberSession::isSafeNext($next)) {
    $next = '/support';
}

// Already signed in, no reason to show the form.
if (MemberSession::check()) {
    header('Location: ' . $next);
    exit;
}

$error = '';
$memberId = '';

// One-shot message handed over by the set-password page.
$notice = $_SESSION['member_notice'] ?? '';
unset($_SESSION['member_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $memberId = MemberAuthModel::normaliseId((string) ($_POST['member_id'] ?? ''));

    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired. Please try again.';
    } else {
        $result = (new MemberAuthModel())->authenticate($memberId, (string) ($_POST['password'] ?? ''));

        if ($result['status'] === 'ok') {
            MemberSession::login($result['member']);
            header('Location: ' . $next);
            exit;
        }
        // One message for an unknown ID, no password yet and a wrong password: nobody can use this page to find valid IDs.
        $error = $result['status'] === 'locked'
            ? 'Too many failed attempts. Please try again after ' . $result['until'] . ', or reset your password.'
            : 'Member ID or password is incorrect.';
    }
}

$pageTitle = 'Member sign in';
$heading = 'Member sign in';
$sub = 'Use the Member ID from your membership email and the password you chose.';
require __DIR__ . '/_head.php';
?>
        <form method="POST" autocomplete="off">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">

            <label for="member_id">Member ID</label>
            <input type="text" name="member_id" id="member_id" class="mono" required autofocus
                   maxlength="12" placeholder="e.g. A48213977" autocapitalize="characters" spellcheck="false"
                   value="<?= htmlspecialchars($memberId, ENT_QUOTES) ?>">

            <label for="password">Password</label>
            <input type="password" name="password" id="password" required autocomplete="current-password">

            <button type="submit">Sign in</button>
        </form>

        <p class="links">
            <a href="/member/forgot">First time here, or forgot your password?</a><br>
            <a href="/membership">Not a member yet? Join DCW</a>
        </p>
<?php require __DIR__ . '/_foot.php'; ?>
