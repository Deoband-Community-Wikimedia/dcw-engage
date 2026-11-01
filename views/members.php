<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/engage_page.php';
require_once __DIR__ . '/../models/PublicDirectoryModel.php';

/**
 * DCW Engage - public members directory (/members). No sign-in.
 * Shows Wikimedia usernames and clubs only (see PublicDirectoryModel). Members can opt out from
 * /member/profile. ?club=amu opens the page filtered to one club.
 * Styles live in assets/css/engage.css (the .pm-* block).
 */
$dir = null;
try {
    $dir = (new PublicDirectoryModel())->cached();
} catch (Throwable $e) {
    app_log('Public members list failed: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 300');
}
// This list must NOT be indexed by search engines. Do not remove this header, and do not
// Disallow /members in robots.txt (a blocked crawler never sees the noindex).
header('X-Robots-Tag: noindex, nofollow');
if ($dir !== null) {
    header('Cache-Control: public, max-age=120');
}

$h    = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
// Each username links to its user page on Meta-Wiki, e.g. https://meta.wikimedia.org/wiki/User:Aafi
$link = fn(string $n) => 'https://meta.wikimedia.org/wiki/User:' . rawurlencode(str_replace(' ', '_', $n));

$club = (string) ($_GET['club'] ?? '');
if (!isset(MemberModel::CHAPTER_NAMES[$club])) $club = '';

// One accent colour per club, so the pills are easy to tell apart.
$tones   = ['var(--primary)', 'var(--leaf-dark)', 'var(--accent)', '#7A5AA6', '#B7791F'];
$clubIdx = array_flip(array_keys(MemberModel::CHAPTER_NAMES));
$pills = function (array $keys) use ($h, $tones, $clubIdx): string {
    if (!$keys) return '<span class="pm-pill" style="--tone:var(--muted)">DCW community</span>';
    $out = '';
    foreach ($keys as $k) {
        $tone = $tones[($clubIdx[$k] ?? 0) % count($tones)];
        $out .= '<span class="pm-pill" style="--tone:' . $tone . '">' . $h(MemberModel::CHAPTER_NAMES[$k] ?? $k) . '</span>';
    }
    return $out;
};
$chip = function (array $p, array $clubKeys, string $extra = '') use ($h, $link, $pills): string {
    $name    = (string) $p['name'];
    $initial = mb_strtoupper(mb_substr($name, 0, 1));
    return '<li data-name="' . $h(mb_strtolower($name)) . '" data-clubs="' . $h(implode(' ', $clubKeys)) . '">'
         . '<a class="pm-link" href="' . $h($link($name)) . '" target="_blank" rel="noopener noreferrer">'
         . '<span class="pm-av" aria-hidden="true">' . $h($initial) . '</span>'
         . '<span class="pm-body"><span class="pm-name">' . $h($name) . '<span class="pm-ext" aria-hidden="true">&#8599;</span></span>'
         . '<span class="pm-clubs">' . $pills($clubKeys) . $extra . '</span></span>'
         . '</a></li>';
};

$current = $dir['current'] ?? [];
$alumni  = $dir['alumni']  ?? [];
$legacy  = $dir['legacy']  ?? [];

// Number of current members per club, for the filter tabs.
$perClub = [];
foreach ($current as $p) foreach ($p['clubs'] as $k) $perClub[$k] = ($perClub[$k] ?? 0) + 1;

engage_header([
    'title'   => 'DCW members',
    'heading' => 'DCW members',
    'lead'    => 'Wikimedia usernames of the people in the DCW community, by club.',
    'compact' => true,
    'wide'    => true,
]);
?>
<?php if ($dir === null): ?>
    <div class="pm-card"><div class="alert error" style="margin:0">The members list is unavailable right now. Please try again in a few minutes.</div></div>
<?php else: ?>

    <?php /* First card on the overlap: search, club tabs and totals. Nothing sits bare on the blue band. */ ?>
    <section class="pm-card pm-tools" aria-label="Find a member">
        <div class="pm-top">
            <div class="pm-search">
                <input type="search" id="pd-q" placeholder="Search usernames" autocomplete="off" aria-label="Search usernames">
            </div>
            <p class="pm-total">
                <strong><?= count($current) ?></strong> current
                <?php if ($alumni): ?><span>&middot;</span> <strong><?= count($alumni) ?></strong> alumni<?php endif; ?>
            </p>
        </div>
        <div class="tabs" role="group" aria-label="Filter by club">
            <button type="button" class="tab<?= $club === '' ? ' on' : '' ?>" data-club="">All <span class="count"><?= count($current) ?></span></button>
            <?php foreach (MemberModel::CHAPTER_NAMES as $k => $label): ?>
                <button type="button" class="tab<?= $k === $club ? ' on' : '' ?>" data-club="<?= $h($k) ?>"><?= $h($label) ?> <span class="count"><?= (int) ($perClub[$k] ?? 0) ?></span></button>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="pm-card" aria-label="Current members" data-kind="current">
        <div class="panel-head">
            <h2>Current members (<span class="pm-n"><?= count($current) ?></span>)</h2>
            <p>Members whose membership is active.</p>
        </div>
        <ul class="pm-grid">
            <?php foreach ($current as $p):
                $extra = $p['former'] ? '<small class="pm-former">formerly ' . $h(implode(', ', array_map(fn($c) => MemberModel::CHAPTER_NAMES[$c] ?? $c, $p['former']))) . '</small>' : '';
                echo $chip($p, $p['clubs'], $extra);
            endforeach; ?>
        </ul>
        <p class="pm-empty" hidden>No one matches.</p>
    </section>

    <?php if ($alumni): ?>
    <details class="pm-card pm-fold" data-kind="alumni">
        <summary>
            <span class="pm-sum-h">Alumni (<span class="pm-n"><?= count($alumni) ?></span>)</span>
            <span class="pm-sum-p">Past members. Thank you for what you contributed.</span>
        </summary>
        <ul class="pm-grid alumni">
            <?php foreach ($alumni as $p) echo $chip($p, $p['former']); ?>
        </ul>
        <p class="pm-empty" hidden>No one matches.</p>
    </details>
    <?php endif; ?>

    <?php if ($legacy): ?>
    <details class="pm-card pm-fold" data-kind="legacy">
        <summary>
            <span class="pm-sum-h">Listed before Engage (<span class="pm-n"><?= count($legacy) ?></span>)</span>
            <span class="pm-sum-p">From DCW's earlier member list on Meta-Wiki.</span>
        </summary>
        <p class="pm-note" style="margin-top:12px">These people have not yet linked a verified username on Engage. They move up to Current members or Alumni automatically once they do. To be removed, contact the DCW organisers.</p>
        <ul class="pm-grid alumni">
            <?php foreach ($legacy as $p) echo $chip($p, $p['clubs']); ?>
        </ul>
        <p class="pm-empty" hidden>No one matches.</p>
    </details>
    <?php endif; ?>

    <p class="pm-note">
        Only Wikimedia usernames are shown, and each links to the person's user page on Meta-Wiki. Current members and alumni appear only once their username has been verified by DCW.
        If you are a member and want to be left off this list, sign in and change it on <a href="/member/profile#listing">your profile</a>.
        Want to join? See <a href="/membership">Membership</a>.
    </p>

    <script>
    (function () {
        var q = document.getElementById('pd-q');
        var tabs = document.querySelectorAll('.pm-tools .tab');
        var club = <?= json_encode($club) ?>;

        function run() {
            var s = q.value.trim().toLowerCase().replace(/_/g, ' ');
            document.querySelectorAll('[data-kind]').forEach(function (sec) {
                var shown = 0;
                sec.querySelectorAll('.pm-grid > li').forEach(function (li) {
                    var okName = !s || li.dataset.name.indexOf(s) !== -1;
                    var okClub = !club || li.dataset.clubs.split(' ').indexOf(club) !== -1;
                    li.hidden = !(okName && okClub);
                    if (!li.hidden) shown++;
                });
                sec.querySelector('.pm-n').textContent = shown;
                sec.querySelector('.pm-empty').hidden = shown !== 0;
            });
        }
        tabs.forEach(function (t) {
            t.addEventListener('click', function () {
                club = t.dataset.club;
                tabs.forEach(function (o) { o.classList.toggle('on', o === t); });
                if (window.history && history.replaceState) {
                    history.replaceState(null, '', club ? '?club=' + encodeURIComponent(club) : location.pathname);
                }
                run();
            });
        });
        q.addEventListener('input', run);
        run();
    })();
    </script>
<?php endif; ?>
<?php engage_footer(); ?>
