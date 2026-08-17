<?php
/**
 * Shown when a requested form slug does not exist at all.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Form Not Found - DCW Engage</title>
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
        .code { font-size: 44px; font-weight: 700; color: var(--primary-color); margin: 0 0 8px; letter-spacing: -1px; }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { font-size: 15px; line-height: 1.6; color: #64748b; margin: 0; }
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
        <p class="code">404</p>
        <h1>Form Not Found</h1>
        <p>The form you are looking for does not exist. Please double-check the link you were given.</p>
        <a href="/" class="home-link">&larr; Back to DCW Engage</a>
    </div>
</body>
</html>
