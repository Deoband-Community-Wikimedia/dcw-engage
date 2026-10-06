<?php
require_once __DIR__ . '/social_meta.php';

/**
 * Compact DCW Engage page shell.
 * Usage: engage_header([...]); ...content...; engage_footer();
 *
 * Options:
 *   title    <title> text (" - DCW Engage" is added)
 *   heading  big heading in the hero (defaults to title)
 *   kicker   small pill above the heading
 *   lead     optional sentence under the heading
 *   member   MemberSession::current() row, or null
 *   tools    raw, already-escaped HTML for the top-bar buttons (overrides the member links)
 *   wide     true for wide layouts (finance queue and tables)
 *   crumbs   breadcrumb trail, e.g. [['Home','/'],['Support','/support'],['Reimbursement']].
 *            The last item is the current page (no link). A "Back" button pointing to the
 *            nearest linked crumb is added automatically. Defaults to Home > heading.
 *   description  social preview text (optional; a default is used)
 *   image        social preview image: a Commons file name or link, any URL, or a path (optional;
 *                the default Commons photo in includes/social_meta.php is used)
 *   image_alt    alt text for the preview image (defaults to the heading)
 */
function engage_header(array $o) {
    $title   = $o['title'];
    $heading = $o['heading'] ?? $title;
    $kicker  = $o['kicker'] ?? 'Deoband Community Wikimedia';
    $lead    = $o['lead'] ?? '';
    $member  = $o['member'] ?? null;
    $tools   = $o['tools'] ?? null;
    $wide    = !empty($o['wide']);
    $crumbs  = $o['crumbs'] ?? [['Home', '/'], [$heading]];
    $backHref = null; $backLabel = null;
    foreach ($crumbs as $c) { if (!empty($c[1])) { $backHref = $c[1]; $backLabel = $c[0]; } }
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    // Public pages get an "About DCW" menu; staff pages (/admin, /finance) do not need it.
    $about = engage_is_staff_path() ? [] : engage_about_links(true);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c567a">
    <title><?= $e($title) ?> - DCW Engage</title>
    <?php ob_start(); require __DIR__ . '/favicon.php'; echo engage_strip_social_images(ob_get_clean()); ?>
    <?php if (!engage_is_staff_path()) engage_social_meta([
        'title'       => $title . ' - DCW Engage',
        'description' => $o['description'] ?? null,
        'image'       => $o['image'] ?? null,
        'image_alt'   => $o['image_alt'] ?? $heading,
    ]); ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/engage.css">
</head>
<body>
<header class="hero">
    <div class="topbar">
        <a class="brand" href="/"><img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">DCW Engage</a>
        <?php engage_about_menu($about); ?>
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
        </div>
    </div>
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
    <p class="kicker"><?= $e($kicker) ?></p>
    <h1><?= $e($heading) ?></h1>
    <?php if ($lead): ?><p class="lead"><?= $e($lead) ?></p><?php endif; ?>
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

/** Staff pages live under /admin and /finance. */
function engage_is_staff_path(): bool {
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
    if ($site !== '') $out[] = ['Main website (' . $c['site_label'] . ')', $site];
    return $out;
}

/**
 * Renders the "About DCW" dropdown inside the hero top bar.
 * Used by engage_header() and by the public home page. Prints nothing when $about is empty.
 * Needs the CSS variables --border, --ink and --primary (engage.css and home.php both define them).
 */
function engage_about_menu(array $about): void {
    if (!$about) return;
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    ?>
    <details class="about" id="about-menu">
        <summary class="chip-btn">About DCW <span aria-hidden="true">&#9662;</span></summary>
        <div class="about-menu">
            <?php foreach ($about as [$name, $href]): ?>
                <a href="<?= $e($href) ?>" rel="noopener"><?= $e($name) ?></a>
            <?php endforeach; ?>
        </div>
    </details>
    <style>
        .topbar .about { position: relative; margin: 0 auto 0 14px; }
        .topbar .about summary { list-style: none; cursor: pointer; }
        .topbar .about summary::-webkit-details-marker { display: none; }
        .topbar .about .about-menu { position: absolute; z-index: 30; top: calc(100% + 8px); left: 0; min-width: 220px; padding: 8px; background: #fff; border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 16px 34px rgba(15,23,42,.18); }
        .topbar .about .about-menu a { display: block; padding: 9px 12px; border-radius: 9px; color: var(--ink); font-size: 14.5px; font-weight: 600; text-decoration: none; }
        .topbar .about .about-menu a:hover { background: #f1f7fb; color: var(--primary); }
    </style>
    <?php
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
    ?>
</main>
<style>
    /* Site footer. Self-contained so it works on every page; it can move into engage.css later. */
    footer.site-footer { display: block; text-align: left; margin: 48px 0 0; padding: 0; background: #f8fafc; border-top: 1px solid var(--border); color: var(--ink); }
    .site-footer .sf-inner { max-width: 1100px; margin: 0 auto; padding: 40px 24px 20px; }
    .site-footer .sf-grid { display: grid; grid-template-columns: 1.4fr repeat(3, 1fr); gap: 32px 28px; }
    .site-footer .sf-org { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 16px; letter-spacing: -.01em; }
    .site-footer .sf-org img { width: 34px; height: 34px; }
    .site-footer .sf-about { margin: 12px 0 0; font-size: 14px; line-height: 1.65; color: var(--muted); max-width: 40ch; }
    .site-footer .sf-about strong { color: var(--ink); }
    .site-footer h2 { margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
    .site-footer ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 9px; }
    .site-footer a { color: var(--ink); text-decoration: none; font-size: 14.5px; }
    .site-footer a:hover { color: var(--primary); text-decoration: underline; }
    .site-footer .sf-cta { display: inline-block; margin-top: 4px; padding: 8px 16px; border-radius: 999px; background: var(--primary); color: #fff; font-weight: 600; font-size: 14px; }
    .site-footer .sf-cta:hover { color: #fff; text-decoration: none; opacity: .92; }
    .site-footer .sf-social { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
    .site-footer .sf-social a { padding: 5px 12px; border: 1px solid var(--border); border-radius: 999px; background: #fff; font-size: 13px; font-weight: 600; }
    .site-footer .sf-bottom { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px 20px; margin-top: 32px; padding-top: 18px; border-top: 1px solid var(--border); font-size: 13px; color: var(--muted); }
    .site-footer .sf-bottom a { font-size: 13px; color: var(--muted); }
    .site-footer .sf-bottom a:hover { color: var(--primary); }
    .site-footer .sf-bottom nav { display: flex; flex-wrap: wrap; gap: 6px 18px; }
    @media (max-width: 860px) { .site-footer .sf-grid { grid-template-columns: 1fr 1fr; } .site-footer .sf-brand { grid-column: 1 / -1; } }
    @media (max-width: 520px) { .site-footer .sf-grid { grid-template-columns: 1fr; } }
</style>
<?php if ($isStaff): ?>
<footer class="site-footer">
    <div class="sf-inner" style="padding-top:22px;">
        <div class="sf-bottom" style="margin-top:0; padding-top:0; border-top:0;">
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
                    <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
                    <span>Deoband Community Wikimedia</span>
                </div>
                <p class="sf-about"><strong>DCW Engage</strong> is the home for everything you do with Deoband Community Wikimedia: join or renew your membership, apply to programs, ask for support, and follow every request in one place.</p>
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
                    <li><a href="/support">Request support</a></li>
                    <li><a href="/track">Track an application</a></li>
                    <li><a href="/member/login">Member sign in</a></li>
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
                    <li><a href="/member/talk">Talk to DCW Support</a></li>
                    <li><a href="/member/report-problem">Report a problem</a></li>
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
    // Close the "About DCW" menu when the visitor clicks anywhere else.
    document.addEventListener('click', function (e) {
        var m = document.getElementById('about-menu');
        if (m && m.open && !m.contains(e.target)) m.open = false;
    });
</script>
</body>
</html>
<?php }
