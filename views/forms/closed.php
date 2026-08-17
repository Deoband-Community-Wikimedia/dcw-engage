<?php
/**
 * Shown when a form exists but has been closed by an organizer.
 * Expects $closedTitle to be set by the caller (renderer.php).
 */
$closedTitle = $closedTitle ?? 'This form';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Applications Closed - DCW Engage</title>
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
            max-width: 480px;
            padding: 48px 40px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            text-align: center;
        }
        .icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 24px;
            color: var(--primary-color);
        }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { font-size: 15px; line-height: 1.6; color: #64748b; margin: 0 0 8px; }
        .form-name { color: #1e293b; font-weight: 600; }
        .home-link {
            display: inline-block;
            margin-top: 24px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="card">
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="11" width="18" height="11" rx="2"></rect>
            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
        </svg>
        <h1>Applications Are Closed</h1>
        <p><span class="form-name"><?= htmlspecialchars($closedTitle) ?></span> is no longer accepting submissions.</p>
        <p>If you have already applied, you can still edit your application using the magic link sent to your email.</p>
        <a href="/" class="home-link">&larr; Back to DCW Engage</a>
    </div>
</body>
</html>
