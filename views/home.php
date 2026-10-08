<?php
require_once __DIR__ . '/../includes/wikitext.php';
require_once __DIR__ . '/../includes/engage_page.php';
require_once __DIR__ . '/../models/FormModel.php';
require_once __DIR__ . '/../models/InternetSupportModel.php';
require_once __DIR__ . '/../models/ReimbursementSettingsModel.php';
require_once __DIR__ . '/../includes/member_session.php';
require_once __DIR__ . '/../includes/member_stats.php';

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

// Member numbers for the "Member Statistics" panel. PEOPLE ARE COUNTED BY MEMBER ID: one ID is one
// person, even when it covers several clubs (see includes/member_stats.php). Never throws; if the
// numbers can't be read, the panel is simply left out.
$stats = member_stats();

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
    'pulse'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
    'layers' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
];

$cards = [];
$programs = [];

if ($membershipOpen) {
    $cards[] = [
        'tone' => 'var(--accent)', 'tag' => 'Membership', 'icon' => 'people',
        'title' => 'Become a DCW member',
        'desc'  => 'Join Deoband Community Wikimedia or one of our clubs, or renew your existing membership.',
        'cta'   => 'Join or renew', 'href' => '/membership',
    ];
}

foreach ($activeForms as $form) {
    $title = $form['title'] ?: ucwords(str_replace(['-', '_'], ' ', $form['form_type']));

    $desc = $form['description'] ? MiniWikiText::stripToPlainText($form['description']) : 'Open for applications now.';
    $programs[] = [
        'tone'  => 'var(--primary)', 'icon' => 'doc',
        'title' => $title,
        'desc'  => mb_strimwidth($desc, 0, 120, '…'),
        'cta'   => 'Apply now', 'href' => '/' . $form['form_type'],
    ];
}
engage_header([
    'title'       => 'Deoband Community Wikimedia',
    'heading'     => 'DCW Engage',
    'lead'        => 'One home for our applications and forms — scholarships, fellowships, volunteering, membership and more. Pick one below to get started.',
    'home'        => true,
    'member'      => $signedInMember,
    // Signed in: the header's own "name / My dashboard / Sign out" links. Signed out: one sign-in button.
    'tools'       => $signedInMember ? null : '<a class="chip-btn" href="/member/login?next=%2Fmember%2Fdashboard">Member sign in</a>',
    'description' => 'Scholarships, fellowships, volunteering, membership and more from Deoband Community Wikimedia.',
    'image'       => 'Group photo from DCW 5th Anniversary.jpg',   // Commons file name; change to any other photo
    'image_alt'   => 'Group photo from the DCW 5th Anniversary',
]);
?>
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
            </section>
        <?php endif; ?>

        <?php if ($stats['ok'] && $stats['active'] > 0): ?>
            <section class="stats" aria-label="Member Statistics">
                <h2 class="section-title">Member Statistics</h2>
                <div class="stat-row">
                    <div class="stat" style="--tone: var(--leaf-dark);">
                        <span class="stat-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons['pulse'] ?></svg></span>
                        <strong data-count="<?= (int) $stats['active'] ?>"><?= number_format($stats['active']) ?></strong>
                        <span class="lbl">Active members</span>
                    </div>
                    <div class="stat" style="--tone: var(--primary);">
                        <span class="stat-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons['layers'] ?></svg></span>
                        <strong data-count="<?= (int) $stats['memberships'] ?>"><?= number_format($stats['memberships']) ?></strong>
                        <span class="lbl">Memberships across DCW and our clubs</span>
                    </div>
                </div>
            </section>
            <script>
                // Gentle count-up when the stats scroll into view (skipped for reduced motion).
                (function () {
                    var els = document.querySelectorAll('.stat strong[data-count]');
                    if (!els.length || !('IntersectionObserver' in window)
                        || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                    var io = new IntersectionObserver(function (entries) {
                        entries.forEach(function (e) {
                            if (!e.isIntersecting) return;
                            io.unobserve(e.target);
                            var el = e.target, end = +el.dataset.count, t0 = null;
                            function step(t) {
                                if (t0 === null) t0 = t;
                                var p = Math.min((t - t0) / 1100, 1);
                                el.textContent = Math.round(end * (1 - Math.pow(1 - p, 3))).toLocaleString();
                                if (p < 1) requestAnimationFrame(step);
                            }
                            requestAnimationFrame(step);
                        });
                    }, { threshold: .4 });
                    els.forEach(function (el) { io.observe(el); });
                })();
            </script>
        <?php endif; ?>

        <section class="helpbar" aria-label="Tracking and support">
            <a class="help-item" href="/track" style="--tone: var(--primary);">
                <span class="help-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons['search'] ?></svg></span>
                <span class="help-text">
                    <strong>Already applied?</strong>
                    <span class="help-link">Track your application <span aria-hidden="true">→</span></span>
                </span>
            </a>
            <?php if ($reimbursementOpen || $internetOpen): ?>
                <div class="help-item" style="--tone: var(--leaf-dark);">
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

<?php engage_footer(); ?>
