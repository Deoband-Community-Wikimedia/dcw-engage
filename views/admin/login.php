<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/engage_page.php';

// Where to land after a successful sign in.
$next = $_GET['next'] ?? $_POST['next'] ?? '';
if (!Auth::isSafeNext($next)) {
    $next = '/admin/dashboard';
}

// Already signed in, no reason to show the form.
if (Auth::check()) {
    header('Location: ' . $next);
    exit;
}

$error = '';

// One-shot confirmation handed over by the reset page.
$notice = $_SESSION['login_notice'] ?? '';
unset($_SESSION['login_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        $error = "Your session expired. Please try again.";
    } else {
        $error = Auth::attempt(trim($_POST['email'] ?? ''), $_POST['password'] ?? '');

        if ($error === null) {
            header('Location: ' . $next);
            exit;
        }
    }
}

engage_header([
    'title'   => 'Organizer sign in',
    'heading' => 'Organizer sign in',
    'lead'    => 'Use the email and password for your organizer account.',
    'auth'    => true,
    'noindex' => true,
]);
?>
<div class="auth-card">
    <?php if ($notice): ?>
        <div class="alert ok" role="status"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <?= CSRF::getInputField() ?>
        <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">

        <label for="email">Email</label>
        <input type="email" name="email" id="email" required autofocus
               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>">

        <label for="password">Password</label>
        <input type="password" name="password" id="password" required>

        <button type="submit" class="btn-primary">Sign in</button>
    </form>

    <p class="alt"><a href="/admin/forgot-password">Forgot your password?</a></p>
</div>
<?php engage_footer(); ?>
