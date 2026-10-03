<?php
require_once __DIR__ . '/../includes/wikitext.php';
require_once __DIR__ . '/../models/FormModel.php';
require_once __DIR__ . '/../models/InternetSupportModel.php';
require_once __DIR__ . '/../models/ReimbursementSettingsModel.php';

$formModel = new FormModel();
$activeForms = $formModel->getActiveForms();

// Membership forms (membership-generic, membership-amu, ..., the renewal form) are not
// ordinary programs. They are reached through /membership, which sends new applicants
// and renewals to the right form, so they are taken out of the programs list and shown
// as a single Membership card instead. The card appears only while at least one
// membership form is switched on in the form manager.
$isMembershipForm = fn($f) => str_starts_with((string) $f['form_type'], 'membership-');
$membershipOpen = count(array_filter($activeForms, $isMembershipForm)) > 0;
$activeForms = array_values(array_filter($activeForms, fn($f) => !$isMembershipForm($f)));

// Show the internet support card only while the programme is switched on
// (internet_settings.is_active). Guarded so the public landing page can
// never break, for example before internet_support.sql has been run.
try {
    $internetOpen = (new InternetSupportModel())->isOpen();
} catch (Throwable $e) {
    $internetOpen = false;
}

// Same for reimbursements: listed only while the global form is switched on
// (reimbursement_settings.is_active), the same switch /reimbursement checks.
try {
    $reimbursementSettings = (new ReimbursementSettingsModel())->get();
    $reimbursementOpen = $reimbursementSettings && !empty($reimbursementSettings['is_active']);
} catch (Throwable $e) {
    $reimbursementOpen = false;
}

/**
 * Photo strip ("From our events"). Leave the list empty to hide it.
 *   src    image address (for Commons: Special:FilePath/<file name>?width=640 gives a small copy)
 *   alt    what the photo shows, for screen readers
 *   credit caption under the photo (photographer and licence)
 *   href   optional link for the caption, e.g. the photo's Commons page
 * Only add photos you are allowed to show, and keep the credit accurate. Before going live, open
 * each Commons page and put the photographer's name and the licence (e.g. CC BY-SA 4.0) in 'credit'.
 */
$galleryImages = [
    [
        'src'    => 'https://commons.wikimedia.org/wiki/Special:FilePath/Aafi_during_his_presentation,_Wikiconference_India_2026_DSC_5679.jpg?width=640',
        'alt'    => 'Aafi presenting at WikiConference India 2026',
        'credit' => 'JyotiPN, CC BY-SA 4.0, Wikimedia Commons',
        'href'   => 'https://commons.wikimedia.org/wiki/File:Aafi_during_his_presentation,_Wikiconference_India_2026_DSC_5679.jpg',
    ],
    [
        'src'    => 'https://commons.wikimedia.org/wiki/Special:FilePath/Group_photo_from_DCW_5th_Anniversary.jpg?width=640',
        'alt'    => 'Group photo from the DCW 5th Anniversary',
        'credit' => 'Muntaqibah, CC BY-SA 4.0, Wikimedia Commons',
        'href'   => 'https://commons.wikimedia.org/wiki/File:Group_photo_from_DCW_5th_Anniversary.jpg',
    ],
    [
        // Embassy of Ukraine in India site: its footer says all content is CC BY 4.0, which needs the
        // photographer's name and a link to the licence (the caption link below). This points at their
        // server; for reliability save a copy under /assets/img/ and change 'src' to that path.
        // TODO: replace the alt text with a description of what the photo shows.
        'src'    => 'https://india.mfa.gov.ua/storage/app/thumbnails/29f/219/429/69ef2b793300d191148887_820x360.jpg',
        'alt'    => 'Ukrainian Diplomacy Month offline Wikipedia workshop led by DCW members at Embassy of Ukraine in New Delhi',
        'credit' => 'Photo: Volodymyr Prytula, Embassy of Ukraine in India, CC BY 4.0',
        'href'   => 'https://creativecommons.org/licenses/by/4.0/',
    ],
];

// Inner SVG markup for the card icons (24x24 viewBox, stroke icons).
$icons = [
    'doc'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'people' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'card'   => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
    'wifi'   => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
];

// One flat list of cards, so the page never has a lonely card sitting in a section of its own.
// tone = the card's accent colour, tag = the small label on top.
$cards = [];

if ($membershipOpen) {
    $cards[] = [
        'featured' => true, 'tone' => '#97161b', 'tag' => 'Membership', 'icon' => 'people',
        'title' => 'Become a DCW member',
        'desc'  => 'Join Deoband Community Wikimedia or one of our clubs, or renew your existing membership.',
        'cta'   => 'Join or renew', 'href' => '/membership',
    ];
}

foreach ($activeForms as $form) {
    $title = $form['title'] ?: ucwords(str_replace(['-', '_'], ' ', $form['form_type']));
    // Plain-text preview: strip the formatting syntax (see #44)
    // rather than render it, so truncating to 120 chars below
    // can never cut a tag in half or leave raw wikitext
    // punctuation ('' / == / : / []) in the card blurb.
    $desc = $form['description'] ? MiniWikiText::stripToPlainText($form['description']) : 'Open for applications now.';
    $cards[] = [
        'featured' => false, 'tone' => '#106b9a', 'tag' => 'Open program', 'icon' => 'doc',
        'title' => $title,
        'desc'  => mb_strimwidth($desc, 0, 120, '…'),
        'cta'   => 'Apply now', 'href' => '/' . $form['form_type'],
    ];
}

if ($reimbursementOpen) {
    // Remove this card to keep reimbursements unlisted and share /reimbursement directly instead.
    $cards[] = [
        'featured' => false, 'tone' => '#b45309', 'tag' => 'Volunteer support', 'icon' => 'card',
        'title' => 'Reimbursement',
        'desc'  => 'Claim back expenses for a DCW-aligned, DCW-organised or DCW-associated event.',
        'cta'   => 'Request reimbursement', 'href' => '/reimbursement',
    ];
}
if ($internetOpen) {
    // Remove this card to keep internet support unlisted and share /internet-support directly instead.
    $cards[] = [
        'featured' => false, 'tone' => '#0f766e', 'tag' => 'Volunteer support', 'icon' => 'wifi',
        'title' => 'Internet Support',
        'desc'  => 'DCW volunteers can request help with a data pack to keep contributing online.',
        'cta'   => 'Request support', 'href' => '/internet-support',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
     <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c567a">
    <title>DCW Engage — Applications &amp; Forms</title>
    <meta name="description" content="The engagement hub for Deoband Community Wikimedia — scholarships, fellowships, volunteering, and more, all in one place.">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #106b9a;
            --primary-dark: #0c567a;
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
        a:focus-visible { outline: 3px solid #f59e0b; outline-offset: 3px; }
        .wrap { max-width: 1060px; margin: 0 auto; padding: 0 22px; }

        /* Hero: brand gradient with soft shapes, cards overlap its lower edge */
        .hero {
            position: relative; overflow: hidden; text-align: center; color: #fff;
            padding: 54px 22px 110px;
            background:
                radial-gradient(circle at 12% 18%, rgba(255,255,255,.14) 0, rgba(255,255,255,0) 38%),
                radial-gradient(circle at 88% 80%, rgba(151,22,27,.55) 0, rgba(151,22,27,0) 46%),
                linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 60%, #1b8cc0 100%);
        }
        .hero img.logo {
            width: 84px; height: 84px; object-fit: contain; padding: 10px; margin-bottom: 18px;
            background: #fff; border-radius: 50%; box-shadow: 0 8px 22px rgba(0,0,0,.2);
        }
        .hero .kicker {
            display: inline-block; margin: 0 0 14px; padding: 5px 14px; border-radius: 999px;
            background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.3);
            font-size: 12.5px; letter-spacing: .14em; text-transform: uppercase; font-weight: 700;
        }
        .hero h1 {
            margin: 0 0 14px; font-size: clamp(34px, 6vw, 56px); font-weight: 800; letter-spacing: -1px; line-height: 1.1;
        }
        .hero .lead {
            max-width: 640px; margin: 0 auto; color: rgba(255,255,255,.88);
            font-size: clamp(16px, 2.2vw, 19px);
        }
        .hero .actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; margin-top: 26px; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 12px 22px; border-radius: 999px;
            font-weight: 700; font-size: 15px; text-decoration: none; transition: transform .15s, box-shadow .15s, background .15s;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn-solid { background: #fff; color: var(--primary-dark); box-shadow: 0 8px 20px rgba(0,0,0,.18); }
        .btn-ghost { color: #fff; border: 1.5px solid rgba(255,255,255,.65); }
        .btn-ghost:hover { background: rgba(255,255,255,.14); }

        /* Cards: one centred flow, so any number of cards looks tidy */
        .cards-wrap { margin-top: -62px; position: relative; }
        .grid { display: flex; flex-wrap: wrap; justify-content: center; gap: 20px; padding-bottom: 12px; }
        .prog {
            --tone: var(--primary);
            position: relative; display: flex; flex-direction: column; overflow: hidden;
            flex: 1 1 300px; max-width: 340px;
            background: var(--card); border: 1px solid var(--border); border-radius: 16px;
            padding: 26px 24px 22px; text-decoration: none; color: inherit;
            box-shadow: 0 6px 18px rgba(15,23,42,.06);
            transition: transform .18s, box-shadow .18s, border-color .18s;
        }
        .prog::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 5px; background: var(--tone); }
        .prog:hover { transform: translateY(-4px); box-shadow: 0 16px 34px rgba(15,23,42,.14); border-color: var(--tone); }
        .prog .tag {
            align-self: flex-start; margin-bottom: 14px; padding: 3px 11px; border-radius: 999px;
            font-size: 11.5px; letter-spacing: .08em; text-transform: uppercase; font-weight: 700;
            color: var(--tone); background: color-mix(in srgb, var(--tone) 12%, #fff);
        }
        .prog .tick {
            width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; margin-bottom: 14px;
            background: color-mix(in srgb, var(--tone) 14%, #fff);
        }
        .prog .tick svg { width: 22px; height: 22px; stroke: var(--tone); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .prog h3 { margin: 0 0 8px; font-size: 19px; font-weight: 700; line-height: 1.3; }
        .prog p { margin: 0 0 18px; color: var(--muted); font-size: 14.5px; flex: 1; }
        .prog .go { color: var(--tone); font-weight: 700; font-size: 14.5px; display: inline-flex; align-items: center; gap: 6px; }
        .prog .go span { transition: transform .15s; }
        .prog:hover .go span { transform: translateX(4px); }

        /* The membership card is the featured one: a full-width banner, as tall as its content */
        .prog.featured {
            flex: 1 1 100%; max-width: 100%; color: #fff; border: none;
            padding: 26px 30px;
            display: grid; align-items: center; column-gap: 22px;
            grid-template-columns: auto 1fr auto;
            grid-template-areas:
                "tick tag   go"
                "tick title go"
                "tick desc  go";
            background: linear-gradient(135deg, var(--accent) 0%, #b3262c 55%, #c2410c 140%);
        }
        .prog.featured::before { display: none; }
        .prog.featured .tag   { grid-area: tag; margin: 0 0 6px; color: #fff; background: rgba(255,255,255,.2); }
        .prog.featured .tick  { grid-area: tick; align-self: center; margin: 0; width: 56px; height: 56px; background: rgba(255,255,255,.2); }
        .prog.featured .tick svg { width: 28px; height: 28px; stroke: #fff; }
        .prog.featured h3     { grid-area: title; margin: 0 0 4px; font-size: 24px; }
        .prog.featured p      { grid-area: desc; margin: 0; color: rgba(255,255,255,.9); font-size: 16px; }
        .prog.featured .go {
            grid-area: go; align-self: center; padding: 11px 22px; border-radius: 999px;
            background: #fff; color: var(--accent); box-shadow: 0 6px 16px rgba(0,0,0,.18);
        }
        .prog.featured:hover { border-color: transparent; }

        /* Optional photo strip */
        .gallery { margin: 44px 0 6px; text-align: center; }
        .gallery h2 { margin: 0 0 16px; font-size: 13px; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); font-weight: 700; }
        .gallery .strip { display: flex; flex-wrap: wrap; justify-content: center; gap: 14px; }
        .gallery figure { margin: 0; flex: 1 1 220px; max-width: 320px; }
        .gallery img { width: 100%; height: 190px; object-fit: cover; border-radius: 14px; display: block; box-shadow: 0 6px 16px rgba(15,23,42,.1); }
        .gallery figcaption { margin-top: 6px; font-size: 12px; color: var(--muted); }
        .gallery figcaption a { color: var(--muted); text-decoration: underline; text-underline-offset: 2px; }
        .gallery figcaption a:hover { color: var(--primary); }

        /* Empty state */
        .empty {
            text-align: center; background: var(--card); border: 1px solid var(--border);
            border-radius: 16px; padding: 46px 30px; color: var(--muted); max-width: 560px;
            margin: 0 auto 30px; box-shadow: 0 6px 18px rgba(15,23,42,.06);
        }
        .empty h3 { color: var(--ink); margin: 0 0 8px; }

        /* Footer */
        footer {
            border-top: 1px solid var(--border); margin-top: 40px; padding: 26px 0 40px;
            text-align: center; color: var(--muted); font-size: 14px;
        }
        footer a { color: var(--primary); text-decoration: none; }
        footer .org { display: inline-flex; align-items: center; gap: 9px; margin-bottom: 6px; }
        footer .org img { width: 26px; height: auto; }

        @media (max-width: 640px) {
            .hero { padding: 40px 18px 100px; }
            .prog, .prog.featured { max-width: none; flex-basis: 100%; }
            .prog.featured {
                grid-template-columns: 1fr; row-gap: 4px; padding: 24px;
                grid-template-areas: "tick" "tag" "title" "desc" "go";
            }
            .prog.featured .tick { margin-bottom: 12px; }
            .prog.featured .go { justify-self: start; margin-top: 14px; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; }
        }
    </style>
</head>
<body>
    <header class="hero">
        <img class="logo" src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="Deoband Community Wikimedia">
        <p class="kicker">Deoband Community Wikimedia</p>
        <h1>DCW Engage</h1>
        <p class="lead">One home for our applications and forms — scholarships, fellowships, volunteering, membership and more. Pick one below to get started.</p>
        <div class="actions">
            <?php if ($membershipOpen): ?>
                <a class="btn btn-solid" href="/membership">Join or renew <span aria-hidden="true">→</span></a>
            <?php endif; ?>
            <a class="btn btn-ghost" href="/track">Track your application</a>
        </div>
    </header>

    <main class="wrap cards-wrap">
        <?php if (empty($cards)): ?>
            <div class="empty">
                <h3>No open programs right now</h3>
                <p>There are no forms accepting submissions at the moment. Please check back soon — new opportunities are added here as they open.</p>
            </div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($cards as $c): ?>
                    <a class="prog<?= !empty($c['featured']) ? ' featured' : '' ?>" href="<?= htmlspecialchars($c['href']) ?>" style="--tone: <?= htmlspecialchars($c['tone']) ?>;">
                        <span class="tag"><?= htmlspecialchars($c['tag']) ?></span>
                        <span class="tick">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$c['icon']] ?></svg>
                        </span>
                        <h3><?= htmlspecialchars($c['title']) ?></h3>
                        <p><?= htmlspecialchars($c['desc']) ?></p>
                        <span class="go"><?= htmlspecialchars($c['cta']) ?> <span aria-hidden="true">→</span></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($galleryImages)): ?>
            <section class="gallery" aria-label="From our events">
                <h2>From our events</h2>
                <div class="strip">
                    <?php foreach ($galleryImages as $img): ?>
                        <figure>
                            <img src="<?= htmlspecialchars($img['src']) ?>" alt="<?= htmlspecialchars($img['alt'] ?? '') ?>" loading="lazy">
                            <?php if (!empty($img['credit'])): ?>
                                <figcaption>
                                    <?php if (!empty($img['href'])): ?>
                                        <a href="<?= htmlspecialchars($img['href']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($img['credit']) ?></a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($img['credit']) ?>
                                    <?php endif; ?>
                                </figcaption>
                            <?php endif; ?>
                        </figure>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <footer>
        <div class="org">
            <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
            <span>Deoband Community Wikimedia</span>
        </div>
        <div>&copy; <?= date('Y') ?> · <a href="https://dcwwiki.org">dcwwiki.org</a></div>
    </footer>
</body>
</html>
