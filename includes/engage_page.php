<?php
require_once __DIR__ . '/social_meta.php';
require_once __DIR__ . '/chapter_theme.php';

/**
 * Absolute path of a file under the web root (e.g. '/assets/css/engage.css'), or null if it can't be found.
 */
function engage_asset_file(string $path): ?string {
    $candidates = array_filter([
        !empty($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') . $path : null,
        __DIR__ . '/../public' . $path,
        __DIR__ . '/..' . $path,
    ]);
    foreach ($candidates as $file) {
        if (is_file($file)) return $file;
    }
    return null;
}

/**
 * Versioned URL for a file under the web root, e.g. engage_asset('/assets/css/engage.css').
 * Appends ?v=<last-modified time>, so a changed file gets a new URL and is never served
 * from a stale browser or CDN cache. If the file can't be found, the plain URL is returned.
 */
function engage_asset(string $path): string {
    $file = engage_asset_file($path);
    return $file !== null ? $path . '?v=' . (int) filemtime($file) : $path;
}

/**
 * URL of the logo shown in the top bar (versioned like the CSS).
 *
 * With no argument it is the DCW seal, assets/chapters/generic.svg. Pass a member's chapter key
 * (see engage_chapter_key()) and it is that chapter's logo, assets/chapters/<key>.svg, falling back to the
 * DCW seal when the chapter has no logo file. The key is checked before it is used in a path.
 */
function engage_logo_url(string $chapter = ''): string {
    if ($chapter !== '' && $chapter !== 'generic' && preg_match('/^[a-z0-9_-]{1,40}\z/', $chapter)) {
        $path = '/assets/chapters/' . $chapter . '.svg';
        if (engage_asset_file($path) !== null) return engage_asset($path);
    }
    return engage_asset('/assets/chapters/generic.svg');
}

/**
 * Compact DCW Engage page shell.
 * Usage: engage_header([...]); ...content...; engage_footer();
 *
 * Staff pages (/admin, /finance) automatically get the staff scheme ("Logo Blue, Green & Crimson"): the <body> gets
 * class="admin", assets/css/staff.css is loaded after engage.css, the page is marked noindex, and the brand
 * link goes to the workspace. A staff page that passes no 'tools' also gets the shared staff menu
 * (engage_staff_menu()): chips on wide screens, one hamburger on phones.
 *
 * Member pages that pass 'member' get that member's chapter colors and chapter logo (see
 * includes/chapter_theme.php). Staff pages and pages with no member keep the default DCW look.
 *
 * Owner preview: the owner-only page admin/chapter-themes.php sets $GLOBALS['ENGAGE_THEME_PREVIEW'] = true
 * before calling engage_header(). While that flag is set, a page under /admin is drawn with the MEMBER look
 * (no staff scheme, no staff footer), is marked noindex and has no social preview tags, so the owner sees
 * exactly what a member of the chosen chapter sees. Pass a made-up member (engage_preview_member()), never a real one.
 *
 * Options:
 *   title    <title> text (" - DCW Engage" is added)
 *   heading  big heading in the hero (defaults to title)
 *   kicker   small pill above the heading (not shown on slim pages)
 *   lead     optional sentence under the heading (plain text)
 *   lead_html optional trusted, already-escaped HTML shown under the lead (for example a link)
 *   member   MemberSession::current() row, or null
 *   tools    raw, already-escaped HTML for the top-bar buttons (overrides the member links and the staff menu)
 *   wide     true for wide layouts (finance queue and tables)
 *   home     true for the public home page: a slim hero with a left-aligned heading and lead, no big
 *            logo, no kicker pill, and no breadcrumbs unless 'crumbs' is given
 *   slim     true for any other page that should use the same slim top bar as the home page
 *            with a left-aligned heading and lead, no kicker pill, and the breadcrumb trail kept.
 *            'home' implies it.
 *   compact  true for a shorter band (member pages, and the home page for signed-in members). Implies
 *            'slim', and the breadcrumb trail is off unless 'crumbs' is given.
 *   bare     true for a band with only the top bar: no heading, no lead, no trail (the dashboard).
 *            Implies 'compact'.
 *   flat     true for a band with only the top bar (like 'bare'), but the page starts below the band
 *            with no overlap (the signed-in member's home page). Implies 'slim'.
 *   back     [label, href] a single quiet "← label" link above the heading (replaces the breadcrumb
 *            pills on member pages), e.g. ['Dashboard', '/member/dashboard'].
 *   crumbs   breadcrumb trail, e.g. [['Home','/'],['Support','/support'],['Reimbursement']].
 *            The last item is the current page (no link). A "Back" button pointing to the
 *            nearest linked crumb is added automatically. Defaults to Home > heading
 *            (or no trail at all when 'home', 'compact' or 'bare' is true). Pass [] to hide the trail.
 *   description  social preview text (optional; a default is used)
 *   image        social preview image: a Commons file name or link, any URL, or a path (optional;
 *                the default Commons photo in includes/social_meta.php is used)
 *   image_alt    alt text for the preview image (defaults to the heading)
 *   noindex      true for a private page opened from a secret link (for example /confirm/<token>): the page is marked
 *                noindex and gets no social preview tags, so the event name and link are never shared or indexed.
 *
 * Top bar: the brand sits alone on the left. On the right, a signed-in member gets the avatar menu
 * (engage_member_menu(), which also holds the About DCW links). Everyone else gets whatever 'tools'
 * holds plus a small menu button (engage_guest_menu()) with Track application and About DCW.
 */
function engage_header(array $o) {
    $title    = $o['title'];
    $heading  = $o['heading'] ?? $title;
    $kicker   = $o['kicker'] ?? 'Deoband Community Wikimedia';
    $lead     = $o['lead'] ?? '';
    $leadHtml = (string) ($o['lead_html'] ?? '');
    $member   = $o['member'] ?? null;
    $tools    = $o['tools'] ?? null;
    $noindex  = !empty($o['noindex']);   // private page: not indexed, no social preview

    // Staff pages with no top-bar tools of their own get the shared staff menu.
    if (engage_is_staff_path() && ($tools === null || trim((string) $tools) === '')) {
        $staffMenu = engage_staff_menu();
        if ($staffMenu !== '') $tools = $staffMenu;
    }

    $wide     = !empty($o['wide']);
    $home     = !empty($o['home']);
    $bare     = !empty($o['bare']);
    $flat     = !empty($o['flat']);   // top bar only: no heading, no lead, no trail; the page starts below the band
    $compact  = $bare || !empty($o['compact']);
    $slim     = $home || $compact || $flat || !empty($o['slim']);
    $back     = (isset($o['back']) && is_array($o['back']) && !empty($o['back'][0]) && !empty($o['back'][1])) ? $o['back'] : null;
    $crumbs   = ($bare || $flat) ? [] : ($o['crumbs'] ?? (($home || $compact) ? [] : [['Home', '/'], [$heading]]));
    $backHref = null; $backLabel = null;
    foreach ($crumbs as $c) { if (!empty($c[1])) { $backHref = $c[1]; $backLabel = $c[0]; } }
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $isPreview = engage_is_theme_preview();

    // Public pages get the About DCW links (inside the menu); staff pages (/admin, /finance) do not need them.
    $about = engage_is_staff_path() ? [] : engage_about_links(true);
    // A page that already passes the member menu has the About links inside it. Everyone else gets the small menu.
    $hasMemberMenu = $tools !== null && strpos((string) $tools, 'mmenu') !== false;
    $showGuestMenu = !$hasMemberMenu && !empty($about);
    $joinInMenu    = $tools !== null && strpos((string) $tools, '/membership') !== false;

    $heroClass = 'hero' . ($slim ? ' slim' : '') . ($home ? ' home' : '') . ($compact ? ' compact' : '') . ($bare ? ' bare' : '') . ($flat ? ' flat' : '');
    $isStaff   = engage_is_staff_path();
    // The brand goes to the workspace on staff pages, to the public home page everywhere else.
    $brandHref = $isStaff ? '/admin/dashboard' : '/';

    // Chapter look: a signed-in member sees their chapter's colors and logo. Staff pages and pages with no
    // member keep the default DCW look ($themeCss is '' and the logo is the DCW seal).
    // The theme is worked out once, here, and printed from the same array.
    $chapterKey = (!$isStaff && $member) ? engage_chapter_key($member) : '';
    $themeVars  = $chapterKey !== '' ? engage_chapter_theme_vars($member) : [];
    $themeCss   = engage_theme_vars_css($themeVars);
    $themeColor = $isStaff ? '#051A2B' : ($themeVars['--primary'] ?? '#2E6599');   // fallback = --primary in engage.css
    $logoUrl    = engage_logo_url($chapterKey);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= $e($themeColor) ?>">
    <?php if ($isStaff || $isPreview || $noindex): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <title><?= $e($title) ?> - DCW Engage</title>
    <?php ob_start(); require __DIR__ . '/favicon.php'; echo engage_strip_social_images(ob_get_clean()); ?>
    <?php if (!$isStaff && !$isPreview && !$noindex) engage_social_meta([
        'title'       => $title . ' - DCW Engage',
        'description' => $o['description'] ?? null,
        'image'       => $o['image'] ?? null,
        'image_alt'   => $o['image_alt'] ?? $heading,
    ]); ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php /* Versioned by file modification time: edits to the CSS show up at once, never stuck in a cache. */ ?>
    <link rel="stylesheet" href="<?= $e(engage_asset('/assets/css/engage.css')) ?>">
    <?php /* The member's chapter colors. Must come AFTER engage.css so these variables override the defaults. */ ?>
    <?php if ($themeCss !== ''): ?><style><?= $themeCss ?></style><?php endif; ?>
    <?php /* Staff pages (/admin, /finance) add the staff scheme on top of the shared design system. */ ?>
    <?php if ($isStaff): ?><link rel="stylesheet" href="<?= $e(engage_asset('/assets/css/staff.css')) ?>"><?php endif; ?>
</head>
<body<?= $isStaff ? ' class="admin"' : '' ?>>
<header class="<?= $e($heroClass) ?>">
    <div class="topbar">
        <?php /* The logo sits inside .brand-logo: a small circle that crops the image (see engage.css). It is the member's chapter logo (assets/chapters/<chapter>.svg) or the DCW seal (generic.svg). */ ?>
        <a class="brand" href="<?= $e($brandHref) ?>"><span class="brand-logo"><img src="<?= $e($logoUrl) ?>" alt=""></span><span class="bname">DCW Engage</span></a>
        <div class="tools">
            <?php if ($tools !== null): ?>
                <?= $tools ?>
            <?php elseif ($member): ?>
                <span class="who"><?= $e($member['full_name'] ?: $member['member_id']) ?> (<?= $e($member['member_id']) ?>)</span>
                <a class="chip-btn" href="/member/dashboard">My dashboard</a>
                <a class="chip-btn" href="/member/logout">Sign out</a>
            <?php else: ?>
                <a class="chip-btn" href="/">All programs</a>
            <?php endif; ?>
            <?php if ($showGuestMenu) echo engage_guest_menu($about, $joinInMenu); ?>
        </div>
    </div>
    <?php if ($crumbs): ?>
    <nav class="crumbs" aria-label="Breadcrumb">
        <?php if ($backHref): ?><a class="back" href="<?= $e($backHref) ?>">&larr; Back</a><?php endif; ?>
        <?php foreach ($crumbs as $i => $c): ?>
            <?php if ($i > 0): ?><span class="sep" aria-hidden="true">/</span><?php endif; ?>
            <?php if (!empty($c[1])): ?>
                <a href="<?= $e($c[1]) ?>"><?= $e($c[0]) ?></a>
            <?php else: ?>
                <span class="here" aria-current="page"><?= $e($c[0]) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <?php if (!$bare && !$flat): ?>
        <?php if ($slim): ?><div class="hero-copy"><?php endif; ?>
        <?php if ($back): ?><a class="hero-back" href="<?= $e($back[1]) ?>">&larr; <?= $e($back[0]) ?></a><?php endif; ?>
        <?php if (!$slim): ?><p class="kicker"><?= $e($kicker) ?></p><?php endif; ?>
        <h1><?= $e($heading) ?></h1>
        <?php if ($lead): ?><p class="lead"><?= $e($lead) ?></p><?php endif; ?>
        <?php if ($leadHtml !== ''): ?><?= $leadHtml ?><?php endif; ?>
        <?php if ($slim): ?></div><?php endif; ?>
    <?php endif; ?>
</header>
<main class="wrap cards-wrap<?= $wide ? ' wide' : '' ?>">
<?php
}

/**
 * Footer and "About DCW" settings: the ONE place to edit. Anything left empty is simply not shown.
 *
 *   about          pages about DCW, shown in the header menu and the footer: [['Label', 'https://...'], ...]
 *   contact_url    the Contact page
 *   contact_email  a public mailbox, e.g. 'hello@dcwwiki.org' (optional, shown as well)
 *   subscribe_url  the mailing-list sign-up page
 *   community      chat or community groups:  [['Telegram', 'https://...'], ...]
 *   social         social profiles:           [['Facebook', 'https://...'], ...]
 *   help_url       FAQ / help page
 *   privacy_url    privacy notice (worth having: Engage holds applicants' personal data)
 *   conduct_url    friendly space policy / code of conduct (conduct_label is the text of the link)
 *   staff_login    show a small "Staff sign in" link
 */
function engage_footer_config(): array {
    return [
        'site'          => 'https://dcwwiki.org',
        'site_label'    => 'dcwwiki.org',
        'about'         => [
            ['Our history',         'https://dcwwiki.org/History'],
            ['Vision & objectives', 'https://dcwwiki.org/Vision_%26_Objectives'],
        ],
        'contact_url'   => 'https://dcwwiki.org/Contact',
        'contact_email' => '',
        'subscribe_url' => 'https://lists.wikimedia.org/postorius/lists/wikimedia-dcw.lists.wikimedia.org/',
        'community'     => [],
        'social'        => [
            ['X',         'https://x.com/dcwwiki'],
            ['Facebook',  'https://www.facebook.com/dcwwiki'],
            ['Instagram', 'https://www.instagram.com/dcwwiki'],
            ['Threads',   'https://www.threads.com/@dcwwiki'],
            ['YouTube',   'https://www.youtube.com/@dcwwiki'],
            ['LinkedIn',  'https://www.linkedin.com/company/deoband-community-wikimedia'],
        ],
        'help_url'      => '',
        'privacy_url'   => '',
        'conduct_url'   => 'https://dcwwiki.org/Friendly_space_policy',
        'conduct_label' => 'Friendly space policy',
        'staff_login'   => true,
    ];
}

/** Only web addresses are ever printed as links, so a typo in the settings cannot become a script link. */
function engage_safe_url($u): string {
    return preg_match('#^https?://#i', (string) $u) ? (string) $u : '';
}

/**
 * Link to an uploaded file (a stored 'uploads/...' value). Files are never linked directly: /file checks who
 * is asking (views/file.php). Pass the resume token when the reader is the applicant, not staff.
 */
function engage_upload_url(string $path, string $resumeToken = ''): string {
    return '/file?' . http_build_query(['p' => $path] + ($resumeToken !== '' ? ['t' => $resumeToken] : []));
}

/**
 * True on the owner-only chapter theme preview page. That page sets $GLOBALS['ENGAGE_THEME_PREVIEW'] = true
 * (after its own owner check) so the header and footer draw the member look instead of the staff look.
 */
function engage_is_theme_preview(): bool {
    return !empty($GLOBALS['ENGAGE_THEME_PREVIEW']);
}

/** Staff pages live under /admin and /finance (except the owner theme preview, which shows the member look). */
function engage_is_staff_path(): bool {
    if (engage_is_theme_preview()) return false;
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return (bool) preg_match('#^/(admin|finance)(/|$)#', $path);
}

/** "About DCW" links, in order: the pages from settings, then the main website. */
function engage_about_links(bool $withContact = false): array {
    $c = engage_footer_config();
    $out = [];
    foreach ($c['about'] as $p) {
        $u = engage_safe_url($p[1] ?? '');
        if ($u !== '' && !empty($p[0])) $out[] = [(string) $p[0], $u];
    }
    $contact = engage_safe_url($c['contact_url'] ?? '');
    if ($withContact && $contact !== '') $out[] = ['Contact', $contact];
    $site = engage_safe_url($c['site']);
    if ($site !== '') $out[] = ['Main website', $site];
    return $out;
}

/**
 * The small menu for visitors who are not signed in: three lines on the right of the top bar, opening a
 * panel with Track application and the About DCW links. Sign in and Join stay visible beside it.
 * On very small phones the "Join" button is hidden from the bar (see engage.css), so when the page
 * shows one ($withJoin) the same link appears at the top of this menu on those screens only.
 * Returns HTML (already escaped). The open/close behaviour comes from the script in engage_footer().
 */
function engage_guest_menu(array $about, bool $withJoin = false): string {
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    ob_start(); ?>
    <nav class="mnav" aria-label="Site menu">
        <details class="mmenu burger plain">
            <summary aria-label="Open menu"><span class="bars" aria-hidden="true"><i></i><i></i><i></i></span></summary>
            <div class="mpop">
                <?php if ($withJoin): ?><a class="xs-only" href="/membership">Join DCW</a><?php endif; ?>
                <a href="/track">Track application</a>
                <div class="msep"></div>
                <div class="mlabel">About DCW</div>
                <?php foreach ($about as [$name, $href]): ?>
                    <a href="<?= $e($href) ?>" rel="noopener"><?= $e($name) ?></a>
                <?php endforeach; ?>
            </div>
        </details>
    </nav>
    <?php
    return ob_get_clean();
}

/**
 * Kept for older pages that may still call it. The header no longer uses it: the About DCW links now
 * live inside the top-bar menus (engage_guest_menu() and engage_member_menu()).
 */
function engage_about_menu(array $about): void {
    if (!$about) return;
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $site = rtrim(engage_safe_url(engage_footer_config()['site'] ?? ''), '/');
    ?>
    <details class="about" id="about-menu">
        <summary class="chip-btn">About DCW <span class="caret" aria-hidden="true">&#9662;</span></summary>
        <div class="about-menu">
            <?php foreach ($about as [$name, $href]): ?>
                <?php $isSite = $site !== '' && rtrim($href, '/') === $site; ?>
                <?php if ($isSite): ?><div class="about-sep"></div><?php endif; ?>
                <a href="<?= $e($href) ?>" rel="noopener"<?= $isSite ? ' class="ext"' : '' ?>><?= $e($name) ?><?php if ($isSite): ?> <span aria-hidden="true">&#8599;</span><?php endif; ?></a>
            <?php endforeach; ?>
        </div>
    </details>
    <?php
}

/**
 * The signed-in member's menu for the top bar: avatar plus three lines, opening a panel.
 * Pass the result as the 'tools' option of engage_header() on any member page, so every member page
 * has the same navigation. Returns HTML (already escaped). It also holds the About DCW links, so
 * members do not need a separate About button in the bar.
 *
 *   $member   MemberSession::current() row
 *   $o['home']      true on the home page: "Programs" and "Events" jump links replace the "Home" link
 *   $o['programs']  home only: show "Programs" (there are open programs)
 *   $o['events']    home only: show "Events" (there are upcoming events)
 *   $o['current']   the path of the page being shown (e.g. '/member/dashboard'); that item is highlighted
 *
 * Support, Talk to DCW Support and Report a problem are always listed for a signed-in member: those pages
 * explain any lock themselves (for example an expired membership), which is clearer than a missing link.
 * The menu's open/close behaviour comes from the script in engage_footer().
 */
function engage_member_menu(array $member, array $o = []): string {
    $e       = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $id      = (string) ($member['member_id'] ?? '');
    $display = trim((string) ($member['full_name'] ?? '')) ?: $id;
    $initial = mb_strtoupper(mb_substr($display, 0, 1));
    $current = (string) ($o['current'] ?? '');
    $home    = !empty($o['home']);

    $link = function (string $label, string $href, string $class = '') use ($e, $current): string {
        $isCur = $current !== '' && $href === $current;
        $cls   = trim($class . ($isCur ? ' cur' : ''));
        return '<a href="' . $e($href) . '"' . ($cls !== '' ? ' class="' . $e($cls) . '"' : '')
             . ($isCur ? ' aria-current="page"' : '') . '>' . $e($label) . '</a>';
    };

    ob_start(); ?>
    <nav class="mnav" aria-label="Member menu">
        <details class="mmenu burger">
            <summary aria-label="Open menu">
                <span class="mav" aria-hidden="true"><?= $e($initial) ?></span>
                <span class="bars" aria-hidden="true"><i></i><i></i><i></i></span>
            </summary>
            <div class="mpop">
                <div class="mwho">
                    <span class="mav lg" aria-hidden="true"><?= $e($initial) ?></span>
                    <span>
                        <strong><?= $e($display) ?></strong>
                        <?php if ($id !== ''): ?><em><?= $e($id) ?></em><?php endif; ?>
                    </span>
                </div>

                <?php if ($home): ?>
                    <?php if (!empty($o['programs'])): ?><a href="#programs">Programs</a><?php endif; ?>
                    <?php if (!empty($o['events'])):   ?><a href="#events">Events</a><?php endif; ?>
                <?php else: ?>
                    <?= $link('Home', '/') ?>
                <?php endif; ?>
                <?= $link('Track Application', '/track') ?>

                <div class="msep"></div>
                <?= $link('My Dashboard', '/member/dashboard') ?>
                <?= $link('My Applications', '/member/applications') ?>
                <?= $link('My Profile', '/member/profile') ?>
                <?= $link('My Certificates', '/member/certificates') ?>
                <?= $link('Support', '/member/support') ?>
                <?= $link('Talk to DCW Support', '/member/talk') ?>
                <?= $link('Report a problem', '/member/report-problem') ?>

                <?php $aboutLinks = engage_about_links(true); ?>
                <?php if ($aboutLinks): ?>
                    <div class="msep"></div>
                    <div class="mlabel">About DCW</div>
                    <?php foreach ($aboutLinks as [$name, $href]): ?>
                        <a href="<?= $e($href) ?>" rel="noopener"><?= $e($name) ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>

                <div class="msep"></div>
                <?= $link('Sign out', '/member/logout', 'out') ?>
            </div>
        </details>
    </nav>
    <?php
    return ob_get_clean();
}

/**
 * The staff top-bar menu, used automatically by engage_header() on every /admin and /finance page
 * that does not pass its own 'tools'. Wide screens get chips; phones get one hamburger with the
 * same links (the switch is in engage.css: .tools-wide / .tools-narrow).
 * Returns '' when nobody is signed in, so sign-in pages are unaffected.
 * Sign out posts to /admin/logout (POST only, CSRF checked there).
 */
function engage_staff_menu(): string {
    if (!class_exists('Auth')) return '';
    try {
        $email = trim((string) Auth::email());
    } catch (Throwable $ex) {
        return '';
    }
    if ($email === '') return '';

    $e       = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $path    = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $initial = mb_strtoupper(mb_substr($email, 0, 1)) ?: 'A';
    $csrf    = class_exists('CSRF') ? CSRF::getInputField() : '';

    // [label, href, roles that see it (null = everyone signed in)]. Each mirrors the gate on the page it opens.
    $links = [
        ['Workspace',        '/admin/dashboard',      null],
        ['Finance',          '/finance',              ['finance', 'owner']],
        ['Team',             '/admin/team',           ['owner']],
        ['Audit log',        '/admin/audit',          ['owner']],
        ['Chapter themes',   '/admin/chapter-themes', ['owner']],
        ['Report a problem', '/admin/report-problem', null],
    ];
    $links = array_values(array_filter($links, function ($l) {
        if ($l[2] === null) return true;
        try { return Auth::hasAnyRole($l[2]); } catch (Throwable $ex) { return false; }
    }));

    ob_start(); ?>
    <div class="tools-wide">
        <span class="who"><?= $e($email) ?></span>
        <?php foreach ($links as [$label, $href]): ?>
            <?php if ($label === 'Report a problem' || $href === $path) continue; /* in the footer / already here */ ?>
            <a class="chip-btn" href="<?= $e($href) ?>"><?= $e($label) ?></a>
        <?php endforeach; ?>
        <form method="POST" action="/admin/logout"><?= $csrf ?><button type="submit" class="chip-btn">Sign out</button></form>
    </div>

    <nav class="mnav tools-narrow" aria-label="Account menu">
        <details class="mmenu burger">
            <summary aria-label="Open menu">
                <span class="mav" aria-hidden="true"><?= $e($initial) ?></span>
                <span class="bars" aria-hidden="true"><i></i><i></i><i></i></span>
            </summary>
            <div class="mpop">
                <div class="mwho">
                    <span class="mav lg" aria-hidden="true"><?= $e($initial) ?></span>
                    <span><strong>Signed in</strong><em><?= $e($email) ?></em></span>
                </div>
                <?php foreach ($links as [$label, $href]): $cur = $href === $path; ?>
                    <a href="<?= $e($href) ?>"<?= $cur ? ' class="cur" aria-current="page"' : '' ?>><?= $e($label) ?></a>
                <?php endforeach; ?>
                <div class="msep"></div>
                <form method="POST" action="/admin/logout"><?= $csrf ?><button type="submit" class="out">Sign out</button></form>
            </div>
        </details>
    </nav>
    <?php
    return ob_get_clean();
}

function engage_footer() {
    $c   = engage_footer_config();
    $e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $url = fn($u) => engage_safe_url($u);
    $pairs = function (array $list) use ($url) {
        $out = [];
        foreach ($list as $p) {
            $u = $url($p[1] ?? '');
            if ($u !== '' && !empty($p[0])) $out[] = [(string) $p[0], $u];
        }
        return $out;
    };

    $site      = $url($c['site']) ?: 'https://dcwwiki.org';
    $email     = filter_var($c['contact_email'], FILTER_VALIDATE_EMAIL) ? (string) $c['contact_email'] : '';
    $subscribe = $url($c['subscribe_url']);
    $contactUrl = $url($c['contact_url'] ?? '');
    $community = $pairs($c['community']);
    $social    = $pairs($c['social']);
    $legal     = array_values(array_filter([
        $url($c['help_url'])    ? ['Help', $url($c['help_url'])] : null,
        $url($c['privacy_url']) ? ['Privacy', $url($c['privacy_url'])] : null,
        $url($c['conduct_url']) ? [(string) ($c['conduct_label'] ?? 'Code of conduct'), $url($c['conduct_url'])] : null,
    ]));
    // Staff pages (/admin, /finance) get a short footer: the member-facing links are not for them.
    $isStaff    = engage_is_staff_path();
    $aboutLinks = engage_about_links();

    // Support is a members-only service, so its links are shown only to a signed-in member.
    // This reads the member session only if the page has already loaded and started it (the footer
    // runs after output, so it must never start a session itself). A page that has not done so
    // simply shows the signed-out links.
    $isMember = false;
    if (!$isStaff && class_exists('MemberSession') && session_status() === PHP_SESSION_ACTIVE) {
        try {
            $isMember = (bool) MemberSession::current();
        } catch (Throwable $ex) {
            $isMember = false;
        }
    }
    ?>
</main>
<style>
    /* Site footer. Self-contained so it works on every page; colors come from the tokens in engage.css
       (and staff.css on staff pages).
       The top edge is a three-color stripe taken from the DCW logo: blue, crimson, green. */
    footer.site-footer {
        display: block; text-align: left; margin: 48px 0 0; padding: 0;
        background: var(--bg); color: var(--ink);
        border-top: 4px solid transparent;
        border-image: linear-gradient(90deg, var(--primary) 0 40%, var(--accent) 40% 70%, var(--leaf) 70% 100%) 1;
    }
    /* Staff pages: the same three logo colours (blue, green, crimson), to match the header band. */
    body.admin footer.site-footer {
        border-image: linear-gradient(90deg, var(--primary-light) 0 40%, var(--leaf) 40% 70%, var(--accent) 70% 100%) 1;
    }
    .site-footer .sf-inner { max-width: 1100px; margin: 0 auto; padding: 40px 24px 20px; }
    .site-footer .sf-inner.sf-staff { padding-top: 22px; }
    .site-footer .sf-grid { display: grid; grid-template-columns: 1.4fr repeat(3, 1fr); gap: 32px 28px; }
    .site-footer .sf-org { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 16px; letter-spacing: -.01em; }
    .site-footer .sf-org img { width: 34px; height: 34px; object-fit: contain; }
    .site-footer .sf-about { margin: 12px 0 0; font-size: 14px; line-height: 1.65; color: var(--muted); max-width: 40ch; }
    .site-footer .sf-about strong { color: var(--ink); }
    .site-footer h2 { margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--primary); }
    .site-footer ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 9px; }
    .site-footer a { color: var(--ink); text-decoration: none; font-size: 14.5px; }
    .site-footer a:hover { color: var(--accent); text-decoration: underline; }
    .site-footer .sf-cta { display: inline-block; margin-top: 4px; padding: 8px 16px; border-radius: 999px; background: var(--accent); color: #fff; font-weight: 600; font-size: 14px; box-shadow: 0 3px 0 var(--accent-dark); }
    .site-footer .sf-cta:hover { background: var(--accent-dark); color: #fff; text-decoration: none; }
    .site-footer .sf-social { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
    .site-footer .sf-social a { padding: 5px 12px; border: 1.5px solid var(--border); border-radius: 999px; background: #fff; font-size: 13px; font-weight: 600; }
    .site-footer .sf-social a:hover { border-color: var(--primary); background: var(--primary-tint); color: var(--primary); text-decoration: none; }
    .site-footer .sf-bottom { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px 20px; margin-top: 32px; padding-top: 18px; border-top: 1px solid var(--border); font-size: 13px; color: var(--muted); }
    .site-footer .sf-staff .sf-bottom { margin-top: 0; padding-top: 0; border-top: 0; }
    .site-footer .sf-bottom a { font-size: 13px; color: var(--muted); }
    .site-footer .sf-bottom a:hover { color: var(--accent); }
    .site-footer .sf-bottom nav { display: flex; flex-wrap: wrap; gap: 6px 18px; }
    @media (max-width: 860px) { .site-footer .sf-grid { grid-template-columns: 1fr 1fr; } .site-footer .sf-brand { grid-column: 1 / -1; } }
    @media (max-width: 520px) { .site-footer .sf-grid { grid-template-columns: 1fr; } }
</style>
<?php if ($isStaff): ?>
<footer class="site-footer">
    <div class="sf-inner sf-staff">
        <div class="sf-bottom">
            <span>&copy; <?= date('Y') ?> Deoband Community Wikimedia &middot; <a href="<?= $e($site) ?>" rel="noopener"><?= $e($c['site_label']) ?></a></span>
            <nav aria-label="Staff">
                <a href="/admin/report-problem">Report a problem</a>
                <a href="/admin/dashboard">Workspace</a>
            </nav>
        </div>
    </div>
</footer>
<?php else: ?>
<footer class="site-footer">
    <div class="sf-inner">
        <div class="sf-grid">
            <div class="sf-brand">
                <div class="sf-org">
                    <img src="<?= $e(engage_logo_url()) ?>" alt="">
                    <span>Deoband Community Wikimedia</span>
                </div>
                <p class="sf-about"><strong>DCW Engage</strong> serves as the central administrative hub for Deoband Community Wikimedia, connecting members to community governance, program applications, and support infrastructure.</p>
                <?php if ($social): ?>
                    <div class="sf-social">
                        <?php foreach ($social as [$name, $href]): ?>
                            <a href="<?= $e($href) ?>" rel="noopener"><?= $e($name) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <nav aria-label="Engage">
                <h2>Engage</h2>
                <ul>
                    <li><a href="/">All programs</a></li>
                    <li><a href="/membership">Join or renew</a></li>
                    <li><a href="/track">Track an application</a></li>
                    <?php if ($isMember): ?>
                        <li><a href="/member/dashboard">My dashboard</a></li>
                        <li><a href="/member/profile">My profile</a></li>
                        <li><a href="/member/support">Request support</a></li>
                    <?php else: ?>
                        <li><a href="/member/login">Member sign in</a></li>
                    <?php endif; ?>
                </ul>
            </nav>

            <nav aria-label="DCW">
                <h2>DCW</h2>
                <ul>
                    <?php foreach ($aboutLinks as [$name, $href]): ?>
                        <li><a href="<?= $e($href) ?>" rel="noopener"><?= $e($name) ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach ($community as [$name, $href]): ?>
                        <li><a href="<?= $e($href) ?>" rel="noopener"><?= $e($name) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>

            <div>
                <h2>Contact &amp; updates</h2>
                <ul>
                    <?php if ($contactUrl): ?><li><a href="<?= $e($contactUrl) ?>" rel="noopener">Contact us</a></li><?php endif; ?>
                    <?php if ($email): ?><li><a href="mailto:<?= $e($email) ?>"><?= $e($email) ?></a></li><?php endif; ?>
                    <?php if ($isMember): ?>
                        <li><a href="/member/talk">Talk to DCW Support</a></li>
                        <li><a href="/member/report-problem">Report a problem</a></li>
                    <?php endif; ?>
                </ul>
                <?php if ($subscribe): ?>
                    <p style="margin:14px 0 0;"><a class="sf-cta" href="<?= $e($subscribe) ?>" rel="noopener">Join our mailing list</a></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="sf-bottom">
            <span>&copy; <?= date('Y') ?> Deoband Community Wikimedia</span>
            <nav aria-label="Legal">
                <?php foreach ($legal as [$name, $href]): ?>
                    <a href="<?= $e($href) ?>" rel="noopener"><?= $e($name) ?></a>
                <?php endforeach; ?>
                <?php if (!empty($c['staff_login'])): ?><a href="/admin/login">Staff sign in</a><?php endif; ?>
            </nav>
        </div>
    </div>
</footer>
<?php endif; ?>
<script>
    // Top-bar menus (the site menu and the member menu): close on a click outside, on Esc, or after
    // choosing a link; opening one closes the other.
    (function () {
        var menus = document.querySelectorAll('.topbar details');
        if (!menus.length) return;
        document.addEventListener('click', function (e) {
            menus.forEach(function (d) { if (d.open && !d.contains(e.target)) d.open = false; });
            var a = e.target.closest ? e.target.closest('.topbar details a') : null;
            if (a) { var d = a.closest('details'); if (d) d.open = false; }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') menus.forEach(function (d) { d.open = false; });
        });
        menus.forEach(function (d) {
            d.addEventListener('toggle', function () {
                if (d.open) menus.forEach(function (o) { if (o !== d) o.open = false; });
            });
        });
    })();
</script>
</body>
</html>
<?php }
