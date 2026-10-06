<?php
require_once __DIR__ . '/../includes/wikitext.php';
require_once __DIR__ . '/../includes/engage_page.php';
require_once __DIR__ . '/../models/FormModel.php';
require_once __DIR__ . '/../models/InternetSupportModel.php';
require_once __DIR__ . '/../models/ReimbursementSettingsModel.php';
require_once __DIR__ . '/../includes/member_session.php';

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

// Support is for signed-in members. Is one signed in? Guarded so the public landing page can
// never break, for example before sql/member_login.sql has been run.
try {
    $signedInMember = MemberSession::current();
} catch (Throwable $e) {
    $signedInMember = null;
}

/**
 * Moving photo strip ("From our events"). Leave the list empty to hide it.
 * It scrolls by itself, pauses on hover or touch, has a Pause button, and stands still
 * (swipeable) for visitors who ask their device for reduced motion. Add as many photos as you like.
 *
 * Easiest way, for a Wikimedia Commons photo: give the file name (or the Commons page link).
 * The picture is fetched at the right size and the credit (author, licence, link) is filled in
 * automatically:
 *   ['commons' => 'Some photo.jpg', 'alt' => 'What the photo shows'],
 *
 * Any other photo: give the address and write the credit yourself:
 *   src    image address (a full URL, or a path such as /assets/img/photo.jpg)
 *   alt    what the photo shows, for screen readers
 *   credit caption under the photo (photographer and licence)
 *   href   optional link for the caption, e.g. the photo's page or its licence
 * Optional on any entry: 'credit' / 'href' written by hand win over the automatic Commons credit.
 * Only add photos you are allowed to show, and keep the credit accurate.
 * Landscape photos, at least 640 px wide, look best (they are shown 280 x 190).
 */
$galleryImages = [
    ['commons' => 'Wikimedians at WTS2024 Hyderabad (11).jpg',       'alt' => 'Wikimedians at WTS2024 in Hyderabad'],
    ['commons' => 'Wikimedia-Futures-Lab-26-Friday-118.jpg',          'alt' => 'Participants at Wikimedia Futures Lab 2026'],
    ['commons' => 'Wikimania 2025 — Day 15.jpg',                      'alt' => 'Wikimania 2025'],
    ['commons' => 'WikiConference India 2026 Snaps 02.jpg',           'alt' => 'WikiConference India 2026'],
    ['commons' => 'Aafi during his presentation, Wikiconference India 2026 DSC 5679.jpg', 'alt' => 'Aafi presenting at WikiConference India 2026'],
    ['commons' => 'Group photo from DCW 5th Anniversary.jpg',         'alt' => 'Group photo from the DCW 5th Anniversary'],
    [
        // Embassy of Ukraine in India site: its footer says all content is CC BY 4.0, which needs the
        // photographer's name and a link to the licence (the caption link below). This points at their
        // server; for reliability save a copy under /assets/img/ and change 'src' to that path.
        'src'    => 'https://india.mfa.gov.ua/storage/app/thumbnails/29f/219/429/69ef2b793300d191148887_820x360.jpg',
        'alt'    => 'Ukrainian Diplomacy Month offline Wikipedia workshop led by DCW members at Embassy of Ukraine in New Delhi',
        'credit' => 'Photo: Volodymyr Prytula, Embassy of Ukraine in India, CC BY 4.0',
        'href'   => 'https://creativecommons.org/licenses/by/4.0/',
    ],
    // More photos go here, for example:
    // ['commons' => 'Some photo from a DCW event.jpg', 'alt' => 'Participants at the workshop'],
];

// Work out the final picture address and caption for each photo.
$gallery = [];
foreach ($galleryImages as $g) {
    if (!empty($g['commons'])) {
        $g['src'] = engage_resolve_image($g['commons'], 960);
        if (empty($g['credit'])) {
            $c = engage_commons_credit($g['commons']);
            if ($c) {
                $g['credit'] = implode(', ', array_filter([$c['author'], $c['license'], 'Wikimedia Commons']));
                if (empty($g['href'])) $g['href'] = $c['page'];
            }
        }
    }
    if (!empty($g['src'])) $gallery[] = $g;
}
// Slower scroll for longer strips, so every photo stays on screen about as long.
$galleryDuration = max(24, count($gallery) * 7);

// Inner SVG markup for the card icons (24x24 viewBox, stroke icons).
$icons = [
    'doc'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'people' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'card'   => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
    'wifi'   => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    'help'   => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><line x1="4.93" y1="4.93" x2="9.17" y2="9.17"/><line x1="14.83" y1="14.83" x2="19.07" y2="19.07"/><line x1="14.83" y1="9.17" x2="19.07" y2="4.93"/><line x1="4.93" y1="19.07" x2="9.17" y2="14.83"/>',
];

// $cards holds only the featured Membership banner.
// $programs holds one card per open program; they sit under a single "Open programs"
// heading, so the cards themselves carry no tag.
// Reimbursement and internet support are not cards: they live in the "Looking for support?" tile
// of the help strip further down (see the markup), shown only while each is switched on.
$cards = [];
$programs = [];

if ($membershipOpen) {
    $cards[] = [
        'tone' => '#97161b', 'tag' => 'Membership', 'icon' => 'people',
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
    $programs[] = [
        'tone'  => '#106b9a', 'icon' => 'doc',
        'title' => $title,
        'desc'  => mb_strimwidth($desc, 0, 120, '…'),
        'cta'   => 'Apply now', 'href' => '/' . $form['form_type'],
    ];
}

// Same "About DCW" menu as every other Engage page.
$about = engage_about_links(true);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
     <?php ob_start(); require __DIR__ . '/../includes/favicon.php'; echo engage_strip_social_images(ob_get_clean()); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c567a">
    <title>DCW Engage — Applications &amp; Forms</title>
    <?php engage_social_meta([
        'title'       => 'DCW Engage — Applications & Forms',
        'description' => 'Scholarships, fellowships, volunteering, membership and more from Deoband Community Wikimedia.',
        'image'       => 'Group photo from DCW 5th Anniversary.jpg',   // Commons file name; change to any other photo
        'image_alt'   => 'Group photo from the DCW 5th Anniversary',
    ]); ?>
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
        a:focus-visible, summary:focus-visible, button:focus-visible { outline: 3px solid #f59e0b; outline-offset: 3px; }
        .wrap { max-width: 1060px; margin: 0 auto; padding: 0 22px; }

        /* Hero: brand gradient with soft shapes, cards overlap its lower edge */
        .hero {
            position: relative; overflow: hidden; text-align: center; color: #fff;
            padding: 84px 22px 100px;
            background:
                radial-gradient(circle at 12% 18%, rgba(255,255,255,.14) 0, rgba(255,255,255,0) 38%),
                radial-gradient(circle at 88% 80%, rgba(151,22,27,.55) 0, rgba(151,22,27,0) 46%),
                linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 60%, #1b8cc0 100%);
        }
        .hero img.logo {
            display: block; margin: 0 auto 18px;
            width: 84px; height: 84px; object-fit: contain; padding: 10px;
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

        /* Top bar inside the hero: the same "About DCW" menu and member links as the other Engage pages */
        .hero .topbar {
            position: absolute; top: 18px; left: 22px; right: 22px; z-index: 5;
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px; text-align: left;
        }
        .hero .topbar .about { margin: 0 auto 0 0; }
        .hero .topbar .tools { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-left: auto; font-size: 14px; }
        .hero .topbar .who { color: rgba(255,255,255,.9); font-weight: 600; }
        .chip-btn {
            display: inline-flex; align-items: center; gap: 6px; padding: 7px 15px; border-radius: 999px;
            background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.32);
            color: #fff; font: inherit; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer;
        }
        .chip-btn:hover { background: rgba(255,255,255,.28); }

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

        /* One heading above the group of program cards (instead of a tag on every card) */
        .section-title {
            margin: 30px 0 16px; text-align: center; font-size: 13px; letter-spacing: .12em;
            text-transform: uppercase; color: var(--muted); font-weight: 700;
        }
        /* With no Membership banner above it, the heading sits on the blue hero edge */
        .section-title.first { margin-top: 0; color: #fff; }

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
        .prog.featured .tag   { grid-area: tag; justify-self: start; margin: 0 0 6px; color: #fff; background: rgba(255,255,255,.2); }
        .prog.featured .tick  { grid-area: tick; align-self: center; margin: 0; width: 56px; height: 56px; background: rgba(255,255,255,.2); }
        .prog.featured .tick svg { width: 28px; height: 28px; stroke: #fff; }
        .prog.featured h3     { grid-area: title; margin: 0 0 4px; font-size: 24px; }
        .prog.featured p      { grid-area: desc; margin: 0; color: rgba(255,255,255,.9); font-size: 16px; }
        .prog.featured .go {
            grid-area: go; align-self: center; padding: 11px 22px; border-radius: 999px;
            background: #fff; color: var(--accent); box-shadow: 0 6px 16px rgba(0,0,0,.18);
        }
        .prog.featured:hover { border-color: transparent; }

        /* Help strip: tracking + support, under the cards */
        .helpbar { display: flex; flex-wrap: wrap; justify-content: center; gap: 16px; margin-top: 26px; }
        .help-item {
            flex: 1 1 320px; max-width: 520px; display: flex; align-items: center; gap: 16px;
            background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 18px 20px;
            color: inherit; text-decoration: none; box-shadow: 0 4px 12px rgba(15,23,42,.05);
        }
        a.help-item { transition: transform .15s, box-shadow .15s, border-color .15s; }
        a.help-item:hover { transform: translateY(-3px); box-shadow: 0 12px 26px rgba(15,23,42,.12); border-color: var(--primary); }
        .help-ico { flex: none; width: 46px; height: 46px; border-radius: 50%; display: grid; place-items: center; background: color-mix(in srgb, var(--tone, var(--primary)) 14%, #fff); }
        .help-ico svg { width: 22px; height: 22px; stroke: var(--tone, var(--primary)); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .help-text { display: flex; flex-direction: column; gap: 4px; font-size: 14.5px; color: var(--muted); }
        .help-text strong { color: var(--ink); font-size: 16px; }
        .help-link { color: var(--primary); font-weight: 700; }
        .help-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 2px; }
        .help-chips a {
            padding: 4px 13px; border-radius: 999px; font-size: 13.5px; font-weight: 600; text-decoration: none;
            color: var(--tone); background: color-mix(in srgb, var(--tone) 12%, #fff); border: 1px solid color-mix(in srgb, var(--tone) 30%, #fff);
        }
        .help-chips a:hover { background: var(--tone); color: #fff; }
        .help-sub { font-size: 13px; color: var(--muted); }
        .help-sub a { color: var(--primary); font-weight: 600; text-decoration: none; }
        .help-sub a:hover { text-decoration: underline; }

        /* Moving photo strip: two identical rows slide left in a loop (the second row is the
           seamless continuation, hidden from screen readers). Pauses on hover, focus, or the button. */
        .gallery { margin: 44px 0 6px; text-align: center; }
        .gallery h2 { margin: 0 0 16px; font-size: 13px; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); font-weight: 700; }
        .marquee {
            overflow: hidden; padding: 4px 0 2px;
            -webkit-mask-image: linear-gradient(90deg, transparent 0, #000 5%, #000 95%, transparent 100%);
            mask-image: linear-gradient(90deg, transparent 0, #000 5%, #000 95%, transparent 100%);
        }
        .marquee .track {
            display: flex; gap: 14px; width: max-content;
            animation: gal-scroll var(--gal-dur, 40s) linear infinite;
        }
        .marquee:hover .track, .marquee:focus-within .track, .marquee.paused .track { animation-play-state: paused; }
        /* Two rows with a 14px gap between them: the loop point is half the track plus half a gap. */
        @keyframes gal-scroll { to { transform: translateX(calc(-50% - 7px)); } }
        .gallery figure { flex: none; width: 280px; margin: 0; text-align: left; }
        .gallery img { width: 100%; height: 190px; object-fit: cover; border-radius: 14px; display: block; box-shadow: 0 6px 16px rgba(15,23,42,.1); background: #e2e8f0; }
        .gallery figcaption { margin-top: 6px; font-size: 12px; line-height: 1.4; color: var(--muted); }
        .gallery figcaption a { color: var(--muted); text-decoration: underline; text-underline-offset: 2px; }
        .gallery figcaption a:hover { color: var(--primary); }
        .gal-toggle {
            margin-top: 12px; padding: 5px 14px; border-radius: 999px; cursor: pointer;
            font: inherit; font-size: 12.5px; font-weight: 600; color: var(--muted);
            background: #fff; border: 1px solid var(--border);
        }
        .gal-toggle:hover { color: var(--primary); border-color: var(--primary); }

        /* Reduced motion: no animation. The strip becomes a normal swipeable row, without the repeat. */
        @media (prefers-reduced-motion: reduce) {
            .marquee { overflow-x: auto; -webkit-mask-image: none; mask-image: none; scroll-snap-type: x proximity; }
            .marquee .track { animation: none; }
            .marquee .dup, .gal-toggle { display: none; }
            .gallery figure { scroll-snap-align: start; }
        }

        /* Empty state */
        .empty {
            text-align: center; background: var(--card); border: 1px solid var(--border);
            border-radius: 16px; padding: 46px 30px; color: var(--muted); max-width: 560px;
            margin: 0 auto 30px; box-shadow: 0 6px 18px rgba(15,23,42,.06);
        }
        .empty h3 { color: var(--ink); margin: 0 0 8px; }

        @media (max-width: 640px) {
            .hero { padding: 76px 18px 100px; }
            .hero .topbar { left: 14px; right: 14px; }
            .prog, .prog.featured { max-width: none; flex-basis: 100%; }
            .prog.featured {
                grid-template-columns: 1fr; row-gap: 4px; padding: 24px;
                grid-template-areas: "tick" "tag" "title" "desc" "go";
            }
            .prog.featured .tick { margin-bottom: 12px; }
            .prog.featured .go { justify-self: start; margin-top: 14px; }
            .gallery figure { width: 240px; }
            .gallery img { height: 165px; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; }
        }
    </style>
</head>
<body>
    <header class="hero">
        <div class="topbar">
            <?php engage_about_menu($about); ?>
            <div class="tools">
                <?php if ($signedInMember): ?>
                    <span class="who"><?= htmlspecialchars($signedInMember['full_name'] ?: $signedInMember['member_id']) ?></span>
                    <a class="chip-btn" href="/member/dashboard">My dashboard</a>
                    <a class="chip-btn" href="/member/logout">Sign out</a>
                <?php else: ?>
                    <a class="chip-btn" href="/member/login?next=%2Fmember%2Fdashboard">Member sign in</a>
                <?php endif; ?>
            </div>
        </div>
        <img class="logo" src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="Deoband Community Wikimedia">
        <p class="kicker">Deoband Community Wikimedia</p>
        <h1>DCW Engage</h1>
        <p class="lead">One home for our applications and forms — scholarships, fellowships, volunteering, membership and more. Pick one below to get started.</p>
    </header>

    <main class="wrap cards-wrap">
        <?php if (empty($cards) && empty($programs)): ?>
            <div class="empty">
                <h3>No open programs right now</h3>
                <p>There are no forms accepting submissions at the moment. Please check back soon — new opportunities are added here as they open.</p>
            </div>
        <?php endif; ?>

        <?php if (!empty($cards)): ?>
            <div class="grid">
                <?php foreach ($cards as $c): ?>
                    <a class="prog featured" href="<?= htmlspecialchars($c['href']) ?>" style="--tone: <?= htmlspecialchars($c['tone']) ?>;">
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

        <?php if (!empty($programs)): ?>
            <h2 class="section-title<?= empty($cards) ? ' first' : '' ?>">Open programs</h2>
            <div class="grid">
                <?php foreach ($programs as $p): ?>
                    <a class="prog" href="<?= htmlspecialchars($p['href']) ?>" style="--tone: <?= htmlspecialchars($p['tone']) ?>;">
                        <span class="tick">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$p['icon']] ?></svg>
                        </span>
                        <h3><?= htmlspecialchars($p['title']) ?></h3>
                        <p><?= htmlspecialchars($p['desc']) ?></p>
                        <span class="go"><?= htmlspecialchars($p['cta']) ?> <span aria-hidden="true">→</span></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="helpbar" aria-label="Tracking and support">
            <a class="help-item" href="/track" style="--tone: #106b9a;">
                <span class="help-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons['search'] ?></svg></span>
                <span class="help-text">
                    <strong>Already applied?</strong>
                    <span class="help-link">Track your application <span aria-hidden="true">→</span></span>
                </span>
            </a>
            <?php if ($reimbursementOpen || $internetOpen): ?>
                <div class="help-item" style="--tone: #0f766e;">
                    <span class="help-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons['help'] ?></svg></span>
                    <span class="help-text">
                        <strong>Looking for support?</strong>
                        <span class="help-chips">
                            <?php if ($reimbursementOpen): ?><a href="/support?type=reimbursement">Reimbursement</a><?php endif; ?>
                            <?php if ($internetOpen): ?><a href="/support?type=internet">Internet support</a><?php endif; ?>
                        </span>
                        <?php if ($signedInMember): ?>
                            <span class="help-sub">Signed in as <strong><?= htmlspecialchars($signedInMember['full_name'] ?: $signedInMember['member_id']) ?></strong>
                                &middot; <a href="/member/dashboard">My dashboard</a>
                                &middot; <a href="/member/logout">Sign out</a></span>
                        <?php else: ?>
                            <span class="help-sub">For DCW members. <a href="/member/login?next=%2Fmember%2Fdashboard">Sign in with your Member ID</a></span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!empty($gallery)): ?>
            <section class="gallery" aria-label="From our events">
                <h2>From our events</h2>
                <div class="marquee" id="galMarquee" style="--gal-dur: <?= (int) $galleryDuration ?>s;">
                    <div class="track">
                        <?php foreach ([false, true] as $isDup): ?>
                            <?php foreach ($gallery as $img): ?>
                                <figure<?= $isDup ? ' class="dup" aria-hidden="true"' : '' ?>>
                                    <img src="<?= htmlspecialchars($img['src']) ?>" alt="<?= $isDup ? '' : htmlspecialchars($img['alt'] ?? '') ?>" decoding="async">
                                    <?php if (!empty($img['credit'])): ?>
                                        <figcaption>
                                            <?php if (!empty($img['href'])): ?>
                                                <a href="<?= htmlspecialchars($img['href']) ?>" target="_blank" rel="noopener"<?= $isDup ? ' tabindex="-1"' : '' ?>><?= htmlspecialchars($img['credit']) ?></a>
                                            <?php else: ?>
                                                <?= htmlspecialchars($img['credit']) ?>
                                            <?php endif; ?>
                                        </figcaption>
                                    <?php endif; ?>
                                </figure>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="button" class="gal-toggle" id="galToggle" aria-pressed="false">Pause photos</button>
            </section>
            <script>
                // Pause / play button for the moving photos.
                (function () {
                    var m = document.getElementById('galMarquee');
                    var b = document.getElementById('galToggle');
                    if (!m || !b) return;
                    b.addEventListener('click', function () {
                        var paused = m.classList.toggle('paused');
                        b.setAttribute('aria-pressed', paused ? 'true' : 'false');
                        b.textContent = paused ? 'Play photos' : 'Pause photos';
                    });
                })();
            </script>
        <?php endif; ?>
<?php engage_footer(); ?>
