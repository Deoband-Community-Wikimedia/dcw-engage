<?php
require_once __DIR__ . '/social_meta.php';
require_once __DIR__ . '/chapter_theme.php';
require_once __DIR__ . '/engage_frame.php';   // engage_header(), engage_forbidden(), engage_nav() and the menu wrappers

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

/*
 * The page shell is engage_header() (and engage_forbidden() for the 403 page) in includes/engage_frame.php, with its
 * options documented there. Usage: engage_header([...]); ...content...; engage_footer();
 *
 * Owner preview: the owner-only page admin/chapter-themes.php sets $GLOBALS['ENGAGE_THEME_PREVIEW'] = true
 * before calling engage_header(). While that flag is set, a page under /admin is drawn with the MEMBER look
 * (no staff scheme, no staff footer), is marked noindex and has no social preview tags, so the owner sees
 * exactly what a member of the chosen chapter sees. Pass a made-up member (engage_preview_member()), never a real one.
 */

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
 * The page footer, and the end of the page. Closes <main>. Styles: assets/css/engage.css, section "Site footer".
 */
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
                    <li><a href="/members">Our members</a></li>
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
