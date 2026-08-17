<?php
require_once __DIR__ . '/../models/FormModel.php';

$formModel = new FormModel();
$activeForms = $formModel->getActiveForms();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DCW Engage — Applications &amp; Forms</title>
    <meta name="description" content="The engagement hub for Deoband Community Wikimedia — scholarships, fellowships, volunteering, and more, all in one place.">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #106b9a;
            --primary-hover: #0c567a;
            --accent: #97161b;
            --page: #f4f6f8;
            --card: #ffffff;
            --ink: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; }
        html { color-scheme: light; }
        body {
            margin: 0; background: var(--page); color: var(--ink);
            font-family: 'Inter', -apple-system, sans-serif; line-height: 1.6;
        }
        .wrap { max-width: 1040px; margin: 0 auto; padding: 0 22px; }

        /* Hero */
        .hero { text-align: center; padding: 60px 22px 40px; }
        .hero img.logo { width: 92px; height: auto; margin-bottom: 20px; }
        .hero .kicker {
            font-size: 13px; letter-spacing: .14em; text-transform: uppercase;
            color: var(--primary); font-weight: 700; margin: 0 0 12px;
        }
        .hero h1 {
            font-size: clamp(30px, 5vw, 46px); font-weight: 700; margin: 0 0 14px;
            letter-spacing: -0.5px;
        }
        .hero p {
            font-size: clamp(16px, 2.2vw, 19px); color: var(--muted);
            max-width: 620px; margin: 0 auto;
        }

        /* Program grid */
        .section-label {
            text-align: center; font-size: 13px; letter-spacing: .1em; text-transform: uppercase;
            color: var(--muted); font-weight: 600; margin: 34px 0 18px;
            position: relative;
        }
        .grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 18px; padding-bottom: 30px;
        }
        .prog {
            display: flex; flex-direction: column; background: var(--card);
            border: 1px solid var(--border); border-radius: 12px; padding: 22px 22px 20px;
            text-decoration: none; color: inherit; transition: transform .15s, box-shadow .15s, border-color .15s;
        }
        .prog:hover { transform: translateY(-3px); box-shadow: 0 10px 24px rgba(16,107,154,.12); border-color: var(--primary); }
        .prog .tick { width: 34px; height: 34px; border-radius: 9px; background: color-mix(in srgb, var(--primary) 12%, #fff); display: grid; place-items: center; margin-bottom: 14px; }
        .prog .tick svg { width: 18px; height: 18px; stroke: var(--primary); }
        .prog h3 { margin: 0 0 6px; font-size: 18px; font-weight: 700; }
        .prog p { margin: 0 0 16px; color: var(--muted); font-size: 14.5px; flex: 1; }
        .prog .go { color: var(--primary); font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 6px; }

        /* Empty state */
        .empty {
            text-align: center; background: var(--card); border: 1px solid var(--border);
            border-radius: 12px; padding: 46px 30px; color: var(--muted); max-width: 560px;
            margin: 10px auto 40px;
        }
        .empty h3 { color: var(--ink); margin: 0 0 8px; }

        /* Footer */
        footer {
            border-top: 1px solid var(--border); margin-top: 20px; padding: 26px 0 40px;
            text-align: center; color: var(--muted); font-size: 14px;
        }
        footer a { color: var(--primary); text-decoration: none; }
        footer .org { display: inline-flex; align-items: center; gap: 9px; margin-bottom: 6px; }
        footer .org img { width: 26px; height: auto; }
    </style>
</head>
<body>
    <div class="hero">
        <img class="logo" src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="Deoband Community Wikimedia">
        <p class="kicker">Deoband Community Wikimedia</p>
        <h1>DCW Engage</h1>
        <p>One home for our applications and forms — scholarships, fellowships, volunteering, course registration and more. Pick a program below to get started.</p>
    </div>

    <div class="wrap">
        <?php if (empty($activeForms)): ?>
            <div class="empty">
                <h3>No open programs right now</h3>
                <p>There are no forms accepting submissions at the moment. Please check back soon — new opportunities are added here as they open.</p>
            </div>
        <?php else: ?>
            <p class="section-label">Open programs</p>
            <div class="grid">
                <?php foreach ($activeForms as $form): ?>
                    <?php
                        $title = $form['title'] ?: ucwords(str_replace(['-', '_'], ' ', $form['form_type']));
                        $desc  = $form['description'] ?: 'Open for applications now.';
                    ?>
                    <a class="prog" href="/<?= htmlspecialchars($form['form_type']) ?>">
                        <span class="tick">
                            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                            </svg>
                        </span>
                        <h3><?= htmlspecialchars($title) ?></h3>
                        <p><?= htmlspecialchars(mb_strimwidth($desc, 0, 120, '…')) ?></p>
                        <span class="go">Apply now →</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <footer>
        <div class="org">
            <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
            <span>Deoband Community Wikimedia</span>
        </div>
        <div>&copy; <?= date('Y') ?> · <a href="https://dcwwiki.org">dcwwiki.org</a></div>
    </footer>
</body>
</html>
