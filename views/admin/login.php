<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c567a">
    <meta name="robots" content="noindex, nofollow">
    <title>Organizer sign in - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/engage.css?v=1">
</head>
<body>
    <header class="hero">
        <img class="logo" src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="Deoband Community Wikimedia">
        <p class="kicker">Organizer workspace</p>
        <h1>DCW Engage</h1>
        <p class="lead">Sign in to review applications, memberships and support requests.</p>
    </header>

    <main class="wrap cards-wrap">
        <div class="auth-card">
            <h2>Sign in</h2>
            <p class="sub">Use the email and password for your organizer account.</p>

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
    </main>

    <footer>
        <div class="org">
            <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
            <span>Deoband Community Wikimedia</span>
        </div>
        <div><a href="/">Back to DCW Engage</a></div>
    </footer>
</body>
</html>
