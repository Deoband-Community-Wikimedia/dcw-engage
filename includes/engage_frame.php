<?php
/**
 * DCW Engage - one page frame and one menu (Phase 1 of the "one shared layout" issue).
 *
 * Loaded by includes/engage_page.php. Holds:
 *   engage_header()        dispatcher: new frame when ENGAGE_FRAME_V2 is true, old frame otherwise
 *   engage_header_v2()     the new frame: top bar only on the blue band, then <main> with back link + <h1>
 *   engage_nav()           THE menu (one markup, one CSS) for guests, members and staff
 *   engage_nav_items()     the item lists, one per audience, in one shared format
 *   engage_member_menu(), engage_guest_menu(), engage_staff_menu()   thin wrappers kept for existing callers
 *
 * Turn the new frame on with  define('ENGAGE_FRAME_V2', true);  near the top of includes/init.php.
 * Remove that line to go back to the old frame at once. Delete the flag in Phase 5.
 */
if (!defined('ENGAGE_FRAME_V2')) define('ENGAGE_FRAME_V2', false);

/** Every page calls this. Options are documented in engage_header_legacy() until Phase 5 rewrites the docblock. */
function engage_header(array $o) {
    if (ENGAGE_FRAME_V2) {
        engage_header_v2($o);
    } else {
        engage_header_legacy($o);
    }
}

/**
 * New frame. Option mapping from the old one:
 *   home, slim, compact        no effect
 *   bare, flat                 no page head: the page prints its own <h1>
 *   kicker                     dropped (shown as a small line only when it carries an ID, i.e. contains a digit)
 *   crumbs                     the nearest linked crumb becomes the back link; the trail itself is not printed
 *   back                       the back link (wins over crumbs)
 *   heading, lead, lead_html   printed in .page-head on the light page
 *   member                     chapter colours and logo
 *   tools                      printed beside the menu; if it already holds a menu (data-nav) no second one is added
 *   wide / narrow              content width
 */
function engage_header_v2(array $o) {
    $e        = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $title    = $o['title'];
    $heading  = $o['heading'] ?? $title;
    $lead     = (string) ($o['lead'] ?? '');
    $leadHtml = (string) ($o['lead_html'] ?? '');
    $kicker   = (string) ($o['kicker'] ?? '');
    $member   = $o['member'] ?? null;
    $tools    = trim((string) ($o['tools'] ?? ''));
    $noindex  = !empty($o['noindex']);
    $wide     = !empty($o['wide']);
    $narrow   = !empty($o['narrow']) && !$wide;
    $home     = !empty($o['home']);
    $compact  = !empty($o['compact']);
    $noHead   = !empty($o['bare']) || !empty($o['flat']);

    $isStaff   = engage_is_staff_path();
    $isPreview = engage_is_theme_preview();

    // Back link: explicit 'back' wins, else the nearest linked crumb.
    $crumbs = $noHead ? [] : ($o['crumbs'] ?? (($home || $compact) ? [] : [['Home', '/'], [$heading]]));
    $back = (isset($o['back']) && is_array($o['back']) && !empty($o['back'][0]) && !empty($o['back'][1])) ? $o['back'] : null;
    if (!$back) {
        foreach ($crumbs as $c) { if (!empty($c[1])) $back = [$c[0], $c[1]]; }
    }
    if ($noHead) $back = null;

    // Audience and menu.
    $hasNav = strpos($tools, 'data-nav=') !== false;
    $aud = $isStaff ? 'staff' : (($member || strpos($tools, 'data-nav="member"') !== false) ? 'member' : 'guest');
    $nav = '';
    if (!$hasNav) {
        if ($aud === 'guest')       $nav = engage_nav(['aud' => 'guest', 'join' => strpos($tools, '/membership') !== false]);
        elseif ($aud === 'member')  $nav = engage_nav(['aud' => 'member', 'member' => (array) $member]);
        else                        $nav = engage_nav(['aud' => 'staff']);
    }

    // Chapter look (members only), same rules as the old frame.
    $chapterKey = (!$isStaff && $member) ? engage_chapter_key($member) : '';
    $themeVars  = $chapterKey !== '' ? engage_chapter_theme_vars($member) : [];
    $themeCss   = engage_theme_vars_css($themeVars);
    $themeColor = $isStaff ? '#051A2B' : ($themeVars['--primary'] ?? '#2E6599');
    $logoUrl    = engage_logo_url($chapterKey);
    $brandHref  = $isStaff ? '/admin/dashboard' : '/';
    $bodyClass  = 'f2 aud-' . $aud . ($isStaff ? ' admin' : '') . ($wide ? ' f2-wide' : '') . ($narrow ? ' f2-narrow' : '');
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
    <link rel="stylesheet" href="<?= $e(engage_asset('/assets/css/engage.css')) ?>">
    <?php if ($themeCss !== ''): ?><style><?= $themeCss ?></style><?php endif; ?>
    <?php if ($isStaff): ?><link rel="stylesheet" href="<?= $e(engage_asset('/assets/css/staff.css')) ?>"><?php endif; ?>
</head>
<body class="<?= $e($bodyClass) ?>">
<header class="site-bar">
    <div class="topbar">
        <a class="brand" href="<?= $e($brandHref) ?>"><span class="brand-logo"><img src="<?= $e($logoUrl) ?>" alt=""></span><span class="bname">DCW Engage</span></a>
        <div class="tools">
            <?= $tools ?>
            <?= $nav ?>
        </div>
    </div>
</header>
<main class="wrap cards-wrap<?= $wide ? ' wide' : '' ?>">
<?php if ($back): ?>
    <a class="page-back" href="<?= $e($back[1]) ?>">&larr; <?= $e($back[0]) ?></a>
<?php endif; ?>
<?php if (!$noHead): ?>
    <header class="page-head">
        <?php /* Temporary: a kicker that carries an ID (tracking ID, Member ID) is still useful. Removed in Phase 2 with the page edits. */ ?>
        <?php if ($kicker !== '' && preg_match('/\d/', $kicker)): ?><p class="page-eyebrow"><?= $e($kicker) ?></p><?php endif; ?>
        <h1><?= $e($heading) ?></h1>
        <?php if ($lead !== ''): ?><p class="lead"><?= $e($lead) ?></p><?php endif; ?>
        <?php if ($leadHtml !== ''): ?><?= $leadHtml ?><?php endif; ?>
    </header>
<?php endif; ?>
<?php
}

// ======================================================================================================
// The menu
// ======================================================================================================

/**
 * Menu items for one audience, in one shared format. Each entry is one of:
 *   ['label' => 'Home', 'href' => '/', 'class' => '', 'ext' => false]   a link ('class' may be 'out' or 'xs-only')
 *   ['sep' => true]                                                      a divider
 *   ['heading' => 'About DCW']                                           a small heading
 *   ['post' => '/admin/logout', 'label' => 'Sign out']                   a POST button (staff sign out)
 * $c: 'home','programs','events' (member menu on the home page), 'join' (guest on small phones).
 * THIS is the one place to edit a menu's contents.
 */
function engage_nav_items(string $aud, array $c = []): array {
    $link = fn(string $label, string $href, string $class = '') => ['label' => $label, 'href' => $href, 'class' => $class];
    $items = [];

    if ($aud === 'member') {
        if (!empty($c['home'])) {
            if (!empty($c['programs'])) $items[] = $link('Programs', '#programs');
            if (!empty($c['events']))   $items[] = $link('Events', '#events');
        } else {
            $items[] = $link('Home', '/');
        }
        $items[] = $link('Track Application', '/track');
        $items[] = $link('Members', '/members');
        $items[] = ['sep' => true];
        $items[] = $link('My Dashboard', '/member/dashboard');
        $items[] = $link('My Applications', '/member/applications');
        $items[] = $link('My Profile', '/member/profile');
        $items[] = $link('My Certificates', '/member/certificates');
        $items[] = $link('Support', '/member/support');
        $items[] = $link('Talk to DCW Support', '/member/talk');
        $items[] = $link('Report a problem', '/member/report-problem');
    } elseif ($aud === 'staff') {
        // [label, href, roles that see it (null = everyone signed in)]. Each mirrors the gate on the page it opens.
        $all = [
            ['Workspace',        '/admin/dashboard',      null],
            ['Finance',          '/finance',              ['finance', 'owner']],
            ['Team',             '/admin/team',           ['owner']],
            ['Audit log',        '/admin/audit',          ['owner']],
            ['Chapter themes',   '/admin/chapter-themes', ['owner']],
            ['Report a problem', '/admin/report-problem', null],
        ];
        foreach ($all as [$label, $href, $roles]) {
            if ($roles !== null) {
                try { if (!Auth::hasAnyRole($roles)) continue; } catch (Throwable $ex) { continue; }
            }
            $items[] = $link($label, $href);
        }
    } else { // guest
        if (!empty($c['join'])) $items[] = $link('Join DCW', '/membership', 'xs-only');
        $items[] = $link('Track application', '/track');
        $items[] = $link('Members', '/members');
    }

    // About DCW links: everyone except staff.
    if ($aud !== 'staff') {
        $about = engage_about_links(true);
        if ($about) {
            $items[] = ['sep' => true];
            $items[] = ['heading' => 'About DCW'];
            foreach ($about as [$name, $href]) $items[] = ['label' => $name, 'href' => $href, 'class' => '', 'ext' => true];
        }
    }

    // Sign out.
    if ($aud === 'member') {
        $items[] = ['sep' => true];
        $items[] = $link('Sign out', '/member/logout', 'out');
    } elseif ($aud === 'staff') {
        $items[] = ['sep' => true];
        $items[] = ['post' => '/admin/logout', 'label' => 'Sign out'];
    }
    return $items;
}

/**
 * The menu: three lines (plus an avatar for signed-in people) opening one panel.
 * $o: 'aud' = guest | member | staff, 'member' (member row), 'current' (path to highlight),
 *     plus the item options of engage_nav_items(). Returns '' for staff when nobody is signed in.
 * Open/close behaviour comes from the script in engage_footer().
 */
function engage_nav(array $o = []): string {
    $e   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $aud = (string) ($o['aud'] ?? 'guest');
    $who = null; $initial = ''; $label = 'Site menu';
    $current = (string) ($o['current'] ?? '');

    if ($aud === 'member') {
        $m       = (array) ($o['member'] ?? []);
        $id      = (string) ($m['member_id'] ?? '');
        $display = trim((string) ($m['full_name'] ?? '')) ?: $id;
        $initial = mb_strtoupper(mb_substr($display, 0, 1)) ?: 'M';
        $who     = [$display, $id];
        $label   = 'Member menu';
    } elseif ($aud === 'staff') {
        if (!class_exists('Auth')) return '';
        try { $email = trim((string) Auth::email()); } catch (Throwable $ex) { return ''; }
        if ($email === '') return '';
        $initial = mb_strtoupper(mb_substr($email, 0, 1)) ?: 'A';
        $who     = ['Signed in', $email];
        $label   = 'Account menu';
        if ($current === '') $current = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    }

    $items = engage_nav_items($aud, $o);
    $csrf  = ($aud === 'staff' && class_exists('CSRF')) ? CSRF::getInputField() : '';

    ob_start(); ?>
    <nav class="mnav" data-nav="<?= $e($aud) ?>" aria-label="<?= $e($label) ?>">
        <details class="mmenu burger<?= $who === null ? ' plain' : '' ?>">
            <summary aria-label="Open menu">
                <?php if ($who !== null): ?><span class="mav" aria-hidden="true"><?= $e($initial) ?></span><?php endif; ?>
                <span class="bars" aria-hidden="true"><i></i><i></i><i></i></span>
            </summary>
            <div class="mpop">
                <?php if ($who !== null): ?>
                <div class="mwho">
                    <span class="mav lg" aria-hidden="true"><?= $e($initial) ?></span>
                    <span><strong><?= $e($who[0]) ?></strong><?php if ($who[1] !== ''): ?><em><?= $e($who[1]) ?></em><?php endif; ?></span>
                </div>
                <?php endif; ?>
                <?php foreach ($items as $it): ?>
                    <?php if (!empty($it['sep'])): ?><div class="msep"></div>
                    <?php elseif (!empty($it['heading'])): ?><div class="mlabel"><?= $e($it['heading']) ?></div>
                    <?php elseif (!empty($it['post'])): ?>
                        <form method="POST" action="<?= $e($it['post']) ?>"><?= $csrf ?><button type="submit" class="out"><?= $e($it['label']) ?></button></form>
                    <?php else:
                        $isCur = $current !== '' && $it['href'] === $current;
                        $cls = trim(($it['class'] ?? '') . ($isCur ? ' cur' : '')); ?>
                        <a href="<?= $e($it['href']) ?>"<?= $cls !== '' ? ' class="' . $e($cls) . '"' : '' ?><?= $isCur ? ' aria-current="page"' : '' ?><?= !empty($it['ext']) ? ' rel="noopener"' : '' ?>><?= $e($it['label']) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </details>
    </nav>
    <?php
    return ob_get_clean();
}

// ---- Wrappers: existing pages keep calling these ----------------------------------------------------

/** Member menu. $o: 'current', 'home', 'programs', 'events'. */
function engage_member_menu(array $member, array $o = []): string {
    return engage_nav(['aud' => 'member', 'member' => $member] + $o);
}

/** Guest menu. $about is ignored (the list comes from engage_nav_items()); kept so the old call still works. */
function engage_guest_menu(array $about = [], bool $withJoin = false): string {
    return engage_nav(['aud' => 'guest', 'join' => $withJoin]);
}

/** Staff menu. Old frame: the old chips + burger. New frame: the same burger menu everyone else has. */
function engage_staff_menu(): string {
    return ENGAGE_FRAME_V2 ? engage_nav(['aud' => 'staff']) : engage_staff_menu_legacy();
}
