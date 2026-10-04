<?php
/**
 * Shared top of the member pages (login, forgot, set password): page head, styles, card, and messages.
 * Set before including: $pageTitle, $heading, $sub (optional). Uses $notice / $error if set.
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
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= htmlspecialchars($pageTitle) ?> - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary-color: #106b9a; }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, sans-serif; background: #f8fafc; color: #1e293b;
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .card {
            background: #fff; width: 100%; max-width: 420px; padding: 40px 32px; border-radius: 10px;
            border: 1px solid #e2e8f0; box-shadow: 0 4px 16px rgba(0,0,0,.04);
        }
        h1 { font-size: 22px; margin: 0 0 6px; color: var(--primary-color); }
        .sub { font-size: 14px; color: #64748b; margin: 0 0 26px; line-height: 1.55; }
        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
        input[type=text], input[type=email], input[type=password] {
            width: 100%; padding: 12px; margin-bottom: 18px; border: 1px solid #e2e8f0; border-radius: 6px;
            font-family: inherit; font-size: 15px;
        }
        input.mono { font-family: Menlo, Consolas, monospace; letter-spacing: 1px; text-transform: uppercase; }
        input:focus { outline: 2px solid var(--primary-color); outline-offset: -1px; border-color: transparent; }
        button {
            width: 100%; padding: 13px; background: var(--primary-color); color: #fff; border: none; border-radius: 6px;
            font-family: inherit; font-size: 15px; font-weight: 600; cursor: pointer;
        }
        button:hover { background: #0d587f; }
        .hint { font-size: 13px; color: #64748b; margin: -10px 0 18px; line-height: 1.5; }
        .notice { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 12px 14px; border-radius: 6px; font-size: 14px; margin-bottom: 20px; line-height: 1.5; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 14px; border-radius: 6px; font-size: 14px; margin-bottom: 20px; line-height: 1.5; }
        .links { text-align: center; font-size: 13px; margin: 20px 0 0; line-height: 1.9; }
        .links a { color: #64748b; text-decoration: none; }
        .links a:hover { color: var(--primary-color); text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <h1><?= htmlspecialchars($heading ?? 'DCW Engage') ?></h1>
        <?php if (!empty($sub)): ?><p class="sub"><?= htmlspecialchars($sub) ?></p><?php endif; ?>
        <?php if (!empty($notice)): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
        <?php if (!empty($error)): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
