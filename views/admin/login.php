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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Organizer Sign In - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary-color: #106b9a; }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: #ffffff;
            width: 100%;
            max-width: 400px;
            padding: 40px 32px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
        }
        h1 { font-size: 22px; margin: 0 0 6px; color: var(--primary-color); }
        .sub { font-size: 14px; color: #64748b; margin: 0 0 28px; }
        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
        input[type=email], input[type=password] {
            width: 100%;
            padding: 12px;
            margin-bottom: 18px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-family: inherit;
            font-size: 15px;
        }
        input:focus { outline: 2px solid var(--primary-color); outline-offset: -1px; border-color: transparent; }
        button {
            width: 100%;
            padding: 13px;
            background: var(--primary-color);
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { background: #0d587f; }
        .notice {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
            padding: 12px 14px;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 20px;
        }
        .forgot { text-align: center; font-size: 13px; margin: 20px 0 0; }
        .forgot a { color: #64748b; text-decoration: none; }
        .forgot a:hover { color: var(--primary-color); text-decoration: underline; }
        .error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 12px 14px;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>DCW Engage</h1>
        <p class="sub">Sign in to the organizer workspace.</p>

        <?php if ($notice): ?>
            <div class="notice"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES) ?>">

            <label for="email">Email</label>
            <input type="email" name="email" id="email" required autofocus
                   value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>">

            <label for="password">Password</label>
            <input type="password" name="password" id="password" required>

            <button type="submit">Sign In</button>
        </form>

        <p class="forgot"><a href="/admin/forgot-password">Forgot your password?</a></p>
    </div>
</body>
</html>
