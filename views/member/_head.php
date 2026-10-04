<?php
/**
 * Shared top of the member pages (login, forgot, set password): page head, styles, logo, card and messages.
 * Set before including: $pageTitle, $heading, $sub (optional). Uses $notice / $error if set.
 * Pair with _foot.php, which closes the card and the page.
 */
header('Cache-Control: no-store');
$pageTitle = $pageTitle ?? 'Member sign in';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c567a">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= htmlspecialchars($pageTitle) ?> - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #106b9a; --primary-dark: #0c567a; --accent: #97161b; }
        * { box-sizing: border-box; }
        html { color-scheme: light; }
        body {
            font-family: 'Inter', -apple-system, sans-serif; color: #1e293b; margin: 0; min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 24px 20px;
            background:
                radial-gradient(circle at 10% 15%, rgba(255,255,255,.14) 0, rgba(255,255,255,0) 36%),
                radial-gradient(circle at 90% 85%, rgba(151,22,27,.55) 0, rgba(151,22,27,0) 45%),
                linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 60%, #1b8cc0 100%);
        }
        a:focus-visible, button:focus-visible, input:focus-visible { outline: 3px solid #f59e0b; outline-offset: 2px; }
        .wrap { width: 100%; max-width: 440px; text-align: center; }
        .logo {
            display: block; margin: 0 auto -38px; position: relative; z-index: 1;
            width: 80px; height: 80px; object-fit: contain; padding: 10px; background: #fff; border-radius: 50%;
            box-shadow: 0 8px 22px rgba(0,0,0,.22);
        }
        .card {
            position: relative; text-align: left; background: #fff; padding: 56px 32px 30px; border-radius: 18px;
            box-shadow: 0 24px 50px rgba(0,0,0,.25); overflow: hidden;
        }
        .card::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 5px; background: linear-gradient(90deg, var(--primary), var(--accent)); }
        .chip {
            display: block; width: fit-content; margin: 0 auto 12px; padding: 3px 12px; border-radius: 999px;
            font-size: 11.5px; letter-spacing: .1em; text-transform: uppercase; font-weight: 700;
            color: var(--primary); background: #e8f3f9;
        }
        h1 { font-size: 24px; font-weight: 800; letter-spacing: -.3px; margin: 0 0 8px; text-align: center; color: #0f172a; }
        .sub { font-size: 14.5px; color: #64748b; margin: 0 0 24px; line-height: 1.55; text-align: center; }
        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
        input[type=text], input[type=email], input[type=password] {
            width: 100%; padding: 13px 14px; margin-bottom: 18px; border: 1.5px solid #e2e8f0; border-radius: 10px;
            font-family: inherit; font-size: 15px; background: #f8fafc; transition: border-color .15s, background .15s, box-shadow .15s;
        }
        input:focus { outline: none; background: #fff; border-color: var(--primary); box-shadow: 0 0 0 4px rgba(16,107,154,.15); }
        input.mono { font-family: Menlo, Consolas, monospace; letter-spacing: 2px; text-transform: uppercase; font-size: 16px; }
        input.mono::placeholder { letter-spacing: 1px; text-transform: none; font-family: 'Inter', sans-serif; }
        button {
            width: 100%; padding: 14px; color: #fff; border: none; border-radius: 10px; cursor: pointer;
            font-family: inherit; font-size: 15.5px; font-weight: 700;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            box-shadow: 0 8px 18px rgba(16,107,154,.35); transition: transform .15s, box-shadow .15s;
        }
        button:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(16,107,154,.4); }
        .hint { font-size: 13px; color: #64748b; margin: -10px 0 18px; line-height: 1.5; }
        .notice { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 12px 14px; border-radius: 10px; font-size: 14px; margin-bottom: 20px; line-height: 1.5; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 14px; border-radius: 10px; font-size: 14px; margin-bottom: 20px; line-height: 1.5; }
        .links { text-align: center; font-size: 13.5px; margin: 22px 0 0; line-height: 2; }
        .links a { color: var(--primary); font-weight: 600; text-decoration: none; }
        .links a:hover { text-decoration: underline; }
        .back { margin: 20px 0 0; font-size: 14px; }
        .back a { color: rgba(255,255,255,.9); text-decoration: none; font-weight: 600; }
        .back a:hover { color: #fff; text-decoration: underline; }
        @media (max-width: 480px) { .card { padding: 54px 22px 26px; } }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>
    <main class="wrap">
        <img class="logo" src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="Deoband Community Wikimedia">
        <div class="card">
            <span class="chip">Member area</span>
            <h1><?= htmlspecialchars($heading ?? 'DCW Engage') ?></h1>
            <?php if (!empty($sub)): ?><p class="sub"><?= htmlspecialchars($sub) ?></p><?php endif; ?>
            <?php if (!empty($notice)): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
            <?php if (!empty($error)): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
