<?php
require_once __DIR__ . '/../includes/wikitext.php';
require_once __DIR__ . '/../includes/engage_page.php';
require_once __DIR__ . '/../models/FormModel.php';
require_once __DIR__ . '/../models/FeedbackModel.php';
require_once __DIR__ . '/../includes/member_session.php';
require_once __DIR__ . '/../includes/member_stats.php';
require_once __DIR__ . '/../includes/dcw_events.php';
require_once __DIR__ . '/../models/PublicDirectoryModel.php';

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

// Link-only forms: live and reachable by their URL, but never listed here. A form is link-only
// when its schema has "unlisted": true (the "Link only" checkbox in the form builder).
// getActiveForms() returns this as the `unlisted` column.
$activeForms = array_values(array_filter($activeForms, fn($f) => empty($f['unlisted'])));

// Feedback forms (schema "kind": "feedback") are not applications, so they do not belong under
// "Open programs" with an "Apply now" button. They are pulled out here and shown in their own
// "Share your feedback" section further down, with "Give feedback" wording. A feedback form is
// recognised by its id (FeedbackModel::feedbackForms()) or, when the row carries its schema, by the
// schema itself. If the feedback table has not been created yet, nothing is pulled out.

$feedbackForms = array_values(array_filter($activeForms, fn($f) => FeedbackModel::isFeedbackRow($f)));
$activeForms   = array_values(array_filter($activeForms, fn($f) => !FeedbackModel::isFeedbackRow($f)));

// Is a member signed in? Guarded so the public landing page can never break, for example
// before the members tables exist.
try {
    $signedInMember = MemberSession::current();
} catch (Throwable $e) {
    $signedInMember = null;
}

// Support is a members-only service. It is listed in the member menu for every signed-in member (the
// support page itself explains any lock) and is not shown to guests anywhere on this page.

// Member numbers for the member statistics line. PEOPLE ARE COUNTED BY MEMBER ID: one ID is one
// person, even when it covers several clubs (see includes/member_stats.php). Never throws; if the
// numbers can't be read, the line is simply left out. Shown to everyone, public visitors included.
$stats = member_stats();

// Upcoming events from dcwwiki.org, read from the wiki's public Cargo API (api.php?action=cargoquery;
// see includes/dcw_events.php). No database login is used and nothing on the wiki is changed.
// Cached, never throws: if the wiki can't be reached, this is just empty and the "What's on"
// section is left out.
try {
    $events = dcw_upcoming_events(3);
} catch (Throwable $e) {
    $events = [];
}

// "Meet our members" strip: a few current members picked at random on each visit, from the same public
// directory as /members (verified usernames only; members who opted out are already left out).
// Never throws: if the list can't be read, or has fewer than 3 people, the strip is left out.
$stripMembers = [];
$stripTotal   = 0;
try {
    $directory  = (new PublicDirectoryModel())->cached();
    $allCurrent = is_array($directory['current'] ?? null) ? $directory['current'] : [];
    $stripTotal = count($allCurrent);
    shuffle($allCurrent);
    $stripMembers = array_slice($allCurrent, 0, 10);
} catch (Throwable $e) {
    $stripMembers = [];
}
$stripMore = max(0, $stripTotal - count($stripMembers));

// Selected community reflections (DCW@5). The list is hand-approved, so the text may carry
// trusted <a> links. If the file is missing or malformed, the card is simply left out.
$selected_reflections = [];
$reflectionsFile = __DIR__ . '/../includes/reflections.php';
if (is_file($reflectionsFile)) {
    require $reflectionsFile;   // defines $selected_reflections
}
$reflections = array_values(array_filter(
    is_array($selected_reflections) ? $selected_reflections : [],
    fn($r) => is_array($r) && !empty($r['name']) && !empty($r['text'])
));
shuffle($reflections);              // a different reflection leads on each visit
$reflectionsMoreUrl = '';           // optional "Read more" link, e.g. a wiki page that collects them all
$reflectionsRotate = true;          // true: one reflection at a time, fading to the next; false: one fixed reflection per visit
$reflectionsSeconds = 7;            // how long each reflection stays before the next fades in

// "User:Khaatir" reads better as "Khaatir" on a public card.
$reflectionName = fn($n) => preg_replace('/^User:/i', '', (string) $n);

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
    'quote'  => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
];

$cards = [];
$programs = [];

// The Membership card is for people who are not signed in yet. Signed-in members already have
// a membership, so the card is hidden from them.
if ($membershipOpen && !$signedInMember) {
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

// Feedback forms get their own cards and wording. They are shown in a separate "Share your feedback"
// section, not under "Open programs", and are not counted as programs.
$feedbackCards = [];
foreach ($feedbackForms as $form) {
    $title = $form['title'] ?: ucwords(str_replace(['-', '_'], ' ', $form['form_type']));

    $desc = $form['description'] ? MiniWikiText::stripToPlainText($form['description']) : 'Tell us what you think.';
    $feedbackCards[] = [
        'tone'  => 'var(--accent)', 'icon' => 'quote',
        'title' => $title,
        'desc'  => mb_strimwidth($desc, 0, 120, '…'),
        'cta'   => 'Give feedback', 'href' => '/' . $form['form_type'],
    ];
}

// Markup for the "Selected reflections" card. Built here so it can sit inside the programs grid.
// Only ONE reflection is visible at a time; the rest wait quietly and fade in one by one.
// Underneath: previous / next buttons and a "2 / 14" counter (easier to tap than a row of dots).
$reflectionCard = '';
if (!empty($reflections)) {
    if (!$reflectionsRotate) $reflections = array_slice($reflections, 0, 1);
    ob_start();
    ?>
    <section class="reflect" id="reflect" data-seconds="<?= (int) $reflectionsSeconds ?>"
             aria-label="A reflection from the DCW community">
        <span class="rq-mark" aria-hidden="true">&ldquo;</span>
        <span class="rq-tag">Reflections</span>

        <div class="rq-stage">
            <?php foreach ($reflections as $k => $r): ?>
                <?php
                    $rawName = trim((string) $r['name']);
                    $isUser  = stripos($rawName, 'User:') === 0;
                    $label   = $reflectionName($rawName);
                    $initial = mb_strtoupper(mb_substr($label, 0, 1));
                    $userUrl = 'https://meta.wikimedia.org/wiki/User:' . rawurlencode(str_replace(' ', '_', trim(substr($rawName, 5))));
                ?>
                <figure class="rq<?= $k === 0 ? ' on' : '' ?>" aria-hidden="<?= $k === 0 ? 'false' : 'true' ?>">
                    <blockquote><?= $r['text'] /* trusted, hand-approved HTML */ ?></blockquote>
                    <figcaption>
                        <span class="rq-av" aria-hidden="true"><?= htmlspecialchars($initial) ?></span>
                        <span class="rq-by"><?php if ($isUser): ?><a href="<?= htmlspecialchars($userUrl) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($label) ?></a><?php else: ?><?= htmlspecialchars($label) ?><?php endif; ?></span>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>

        <?php if (count($reflections) > 1 || $reflectionsMoreUrl !== ''): ?>
            <div class="rq-bar">
                <?php if (count($reflections) > 1): ?>
                    <span class="rq-nav" role="group" aria-label="Browse reflections">
                        <button type="button" class="rq-btn" data-dir="-1" aria-label="Previous reflection">&lsaquo;</button>
                        <span class="rq-count"><span class="rq-cur">1</span> / <?= count($reflections) ?></span>
                        <button type="button" class="rq-btn" data-dir="1" aria-label="Next reflection">&rsaquo;</button>
                    </span>
                <?php else: ?><span></span><?php endif; ?>
                <?php if ($reflectionsMoreUrl !== ''): ?>
                    <a class="more" href="<?= htmlspecialchars($reflectionsMoreUrl) ?>" target="_blank" rel="noopener">Read more <span aria-hidden="true">&rarr;</span></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
    $reflectionCard = ob_get_clean();
}

// With only one or two programs the row has spare room, so the reflection card sits beside them
// instead of dropping to the next row. With three or more programs it keeps its own full-width row.
// With upcoming events AND a reflection, the reflection gets its own row under the events, with the
// heading "From our community". The events keep the full width (cards side by side), so a tall events
// column never leaves an empty area beside a short reflection. In that case the reflection is not
// repeated inside the programs grid.
$reflectionRow = $reflectionCard !== '' && !empty($events);
$beside = !$reflectionRow && $reflectionCard !== '' && !empty($programs) && count($programs) <= 2;

// A row with only one or two cards and nothing beside them: the cards share the full width instead of
// sitting small in the middle of an empty row. A single card becomes one wide horizontal card.
$fewCardsClass = fn(int $n): string => $n === 1 ? ' grid--fill grid--solo' : ($n === 2 ? ' grid--fill' : '');

// One "Upcoming Events" card, used on its own and beside the reflection card.
$eventCard = function (array $ev): string {
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    ob_start();
    ?>
    <a class="evt" href="<?= $h($ev['url']) ?>" target="_blank" rel="noopener"
       aria-label="<?= $h($ev['label']) ?>" style="--tone: var(--leaf-dark);">
        <span class="evt-date" aria-hidden="true">
            <span class="d"><?= $h($ev['day']) ?></span>
            <span class="wm"><span class="w"><?= $h($ev['wday']) ?></span><span class="m"><?= $h($ev['month']) ?></span></span>
        </span>
        <span class="evt-body">
            <h3><?= $h($ev['title']) ?></h3>
            <?php if ($ev['desc'] !== ''): ?><p><?= $h($ev['desc']) ?></p><?php endif; ?>
        </span>
        <?php if ($ev['time'] !== '' || $ev['range'] !== ''): ?>
            <span class="evt-meta">
                <?php if ($ev['time'] !== ''): ?><span class="evt-time"><?= $h($ev['time']) ?></span><?php endif; ?>
                <?php if ($ev['range'] !== ''): ?><span class="evt-range"><?= $h($ev['range']) ?></span><?php endif; ?>
            </span>
        <?php endif; ?>
    </a>
    <?php
    return ob_get_clean();
};

// ---- Header content, built from the data above --------------------------------------------
// Everyone gets the same slim top bar, with the logo alone on the left. The rest differs:
//   guests:  a blue band with a headline about what they can do, and "Sign in" + "Join" on the right
//            (About DCW and Track application are in the menu)
//   members: the band holds only the top bar (avatar + three-line menu with every member link). The
//            greeting is plain text at the top of the page, below the band, so nothing is pitched to
//            people who already know DCW and no heading ever sits on the blue.
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$heroHeading  = 'Deoband Community Wikimedia';   // only the <title> for members; the band shows no heading
$heroLead     = '';
$heroLeadHtml = '';
$greeting     = '';
$greetLine    = '';

if ($signedInMember) {
    $display  = trim((string) ($signedInMember['full_name'] ?? '')) ?: (string) ($signedInMember['member_id'] ?? '');
    $first    = explode(' ', $display)[0];

    // One greeting and one live line. The next event is not repeated here: its card is on this page.
    $greeting  = 'Welcome back, ' . $first;
    $greetLine = count($programs) > 0
        ? count($programs) . ' open ' . (count($programs) === 1 ? 'program' : 'programs')
        : '';

    // The shared member menu (includes/engage_page.php), the same one every member page uses.
    $headerTools = engage_member_menu($signedInMember, [
        'home'     => true,
        'programs' => !empty($programs),
        'events'   => !empty($events),
    ]);
} else {
    $heroHeading = 'Welcome to DCW Engage';
    $heroLead    = 'The DCW home for scholarships, fellowships, membership and more.';
    // "Track your application" lives in the menu, so there is no link under the headline.

    // After signing out, member/logout lands here and leaves a one-time notice in the session.
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['member_notice'])) {
        $heroLeadHtml = '<p class="lead-notice" role="status">' . $h($_SESSION['member_notice']) . '</p>';
        unset($_SESSION['member_notice']);
    }

    $headerTools = '<a class="chip-btn" href="/member/login?next=%2Fmember%2Fdashboard">Sign in</a>';
    if ($membershipOpen) {
        $headerTools .= '<a class="chip-btn solid" href="/membership">Join</a>';
    }
}

engage_header([
    'title'       => 'Deoband Community Wikimedia',
    'heading'     => $heroHeading,
    'lead'        => $heroLead,
    'lead_html'   => $heroLeadHtml,
    'home'        => true,
    'flat'        => (bool) $signedInMember,   // members: top bar only, content starts below the band
    'member'      => null,           // the header's own name / dashboard / sign-out row is replaced by 'tools'
    'tools'       => $headerTools,
    'description' => 'Scholarships, fellowships, volunteering, membership and more from Deoband Community Wikimedia.',
    'image'       => 'Group photo from DCW 5th Anniversary.jpg',   // Commons file name; change to any other photo
    'image_alt'   => 'Group photo from the DCW 5th Anniversary',
]);

// Section headings sit on the blue band only for guests with nothing above them (white text).
// For members the greeting comes first, so every heading is normal grey on the light page.
$onBand = !$signedInMember;
?>
        <?php if ($signedInMember): ?>
            <section class="greet" aria-label="Welcome">
                <h1><?= $h($greeting) ?></h1>
                <?php if ($greetLine !== ''): ?><p><?= $h($greetLine) ?></p><?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (empty($cards) && empty($programs) && empty($feedbackCards) && $reflectionCard === '' && empty($events)): ?>
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
            <?php $titleClass = 'section-title' . (empty($cards) && $onBand ? ' first' : ''); ?>
            <?php if (!$beside): ?>
                <h2 id="programs" class="<?= $titleClass ?>">Open programs</h2>
            <?php endif; ?>
            <div class="grid<?= $beside ? ' grid--beside n' . count($programs) : $fewCardsClass(count($programs)) ?>">
                <?php if ($beside): ?>
                    <?php /* Inside the grid, so it centres over the program cards only, not over the reflection. */ ?>
                    <h2 id="programs" class="<?= $titleClass ?> beside-title">Open programs</h2>
                <?php endif; ?>
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
                <?= $reflectionRow ? '' : $reflectionCard ?>
            </div>
        <?php elseif ($reflectionCard !== '' && !$reflectionRow): ?>
            <div class="grid"><?= $reflectionCard ?></div>
        <?php endif; ?>

        <?php /* Feedback forms: their own section, "Give feedback" wording, never under "Open programs". */ ?>
        <?php if (!empty($feedbackCards)): ?>
            <?php $fbTitleClass = 'section-title' . (empty($cards) && empty($programs) && ($reflectionCard === '' || $reflectionRow) && $onBand ? ' first' : ''); ?>
            <h2 id="feedback" class="<?= $fbTitleClass ?>">Share your feedback</h2>
            <div class="grid<?= $fewCardsClass(count($feedbackCards)) ?>">
                <?php foreach ($feedbackCards as $p): ?>
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


        <?php if (!empty($events)): ?>
            <?php $evTitleClass = 'section-title' . (empty($cards) && empty($programs) && empty($feedbackCards) && $onBand ? ' first' : ''); ?>
            <section class="events" id="events" aria-label="Upcoming Events">
                <h2 class="<?= $evTitleClass ?>">Upcoming Events</h2>
                <div class="grid<?= count($events) === 1 ? ' one' : '' ?>">
                    <?php foreach ($events as $ev) echo $eventCard($ev); ?>
                </div>
            </section>
            <?php if ($reflectionRow): ?>
                <section class="community" id="community" aria-label="From our community">
                    <h2 class="section-title">From our community</h2>
                    <div class="grid"><?= $reflectionCard ?></div>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php /* Meet our members: a few random current members, linking to their Meta-Wiki user pages and to the full list. */ ?>
        <?php if (count($stripMembers) >= 3): ?>
            <?php $cmTitleClass = 'section-title' . (empty($cards) && empty($programs) && empty($feedbackCards) && empty($events) && $reflectionCard === '' && $onBand ? ' first' : ''); ?>
            <section class="cm" id="members" aria-label="Meet our members">
                <h2 class="<?= $cmTitleClass ?>">Meet our members</h2>
                <div class="cm-card">
                    <ul class="cm-list">
                        <?php foreach ($stripMembers as $m): ?>
                            <?php $mn = (string) $m['name']; ?>
                            <li><a class="cm-chip" href="https://meta.wikimedia.org/wiki/User:<?= $h(rawurlencode(str_replace(' ', '_', $mn))) ?>" target="_blank" rel="noopener noreferrer">
                                <span class="cm-av" aria-hidden="true"><?= $h(mb_strtoupper(mb_substr($mn, 0, 1))) ?></span>
                                <span class="cm-name"><?= $h($mn) ?></span>
                            </a></li>
                        <?php endforeach; ?>
                        <?php if ($stripMore > 0): ?>
                            <li><a class="cm-chip cm-more" href="/members">+<?= number_format($stripMore) ?> more</a></li>
                        <?php endif; ?>
                    </ul>
                    <p class="cm-foot">
                        <span>The Wikimedians of the DCW community.</span>
                        <a class="btn-pill" href="/members">See all members</a>
                    </p>
                </div>
            </section>
        <?php endif; ?>

        <?php /* Member numbers: one quiet line, shown to everyone, so the page ends on the photos. */ ?>
        <?php if ($stats['ok'] && $stats['active'] > 0): ?>
            <p class="stats-line" aria-label="Member Statistics">
                <strong data-count="<?= (int) $stats['active'] ?>"><?= number_format($stats['active']) ?></strong> active members
                <span aria-hidden="true">·</span>
                <strong data-count="<?= (int) $stats['memberships'] ?>"><?= number_format($stats['memberships']) ?></strong> memberships across DCW and our clubs
            </p>
            <script>
                // Gentle count-up when the numbers scroll into view (skipped for reduced motion).
                (function () {
                    var els = document.querySelectorAll('.stats-line strong[data-count]');
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

        <?php if (!empty($gallery)): ?>
            <section class="gallery" aria-label="Our community in photos">
                <h2>Our community in photos</h2>
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

<?php if ($reflectionCard !== ''): ?>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;1,400;1,500&display=swap" rel="stylesheet">
        <?php /* Card styles live in assets/css/engage.css (section: Selected reflections). */ ?>
        <script>
            // One reflection at a time: the old one fades out, then the next fades in. Pauses while the
            // visitor hovers or focuses the card, and never auto-advances for visitors who prefer reduced motion.
            (function () {
                var root = document.getElementById('reflect');
                if (!root) return;
                var items = root.querySelectorAll('.rq'), btns = root.querySelectorAll('.rq-btn');
                var cur = root.querySelector('.rq-cur');
                var ms = Math.max(5, +root.dataset.seconds || 12) * 1000;
                var i = 0, timer = null;
                var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                function show(n) {
                    i = (n + items.length) % items.length;
                    for (var k = 0; k < items.length; k++) {
                        items[k].classList.toggle('on', k === i);
                        items[k].setAttribute('aria-hidden', k === i ? 'false' : 'true');
                    }
                    if (cur) cur.textContent = i + 1;
                }
                function stop() { clearInterval(timer); timer = null; }
                function play() {
                    if (reduce || items.length < 2) return;
                    stop();
                    timer = setInterval(function () { show(i + 1); }, ms);
                }
                for (var b = 0; b < btns.length; b++) {
                    (function (btn) {
                        btn.addEventListener('click', function () { show(i + (+btn.dataset.dir || 1)); play(); });
                    })(btns[b]);
                }
                root.addEventListener('mouseenter', stop);
                root.addEventListener('mouseleave', play);
                root.addEventListener('focusin', stop);
                root.addEventListener('focusout', play);
                play();
            })();
        </script>
<?php endif; ?>

<?php engage_footer(); ?>
