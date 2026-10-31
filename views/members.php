<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/engage_page.php';
require_once __DIR__ . '/../models/PublicDirectoryModel.php';

/**
 * DCW Engage - public members directory (/members). No sign-in.
 * Shows Wikimedia usernames and clubs only (see PublicDirectoryModel). Members can opt out from
 * /member/profile. ?club=amu opens the page filtered to one club.
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

$h     = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$link  = fn(string $n) => 'https://meta.wikimedia.org/wiki/Special:CentralAuth/' . rawurlencode(str_replace(' ', '_', $n));
$names = fn(array $keys) => implode(', ', array_map(fn($c) => MemberModel::CHAPTER_NAMES[$c] ?? $c, $keys));

$club = (string) ($_GET['club'] ?? '');
if (!isset(MemberModel::CHAPTER_NAMES[$club])) $club = '';

engage_header([
    'title'   => 'DCW members',
    'heading' => 'DCW members',
    'lead'    => 'Wikimedia usernames of the people in the DCW community, by club.',
    'compact' => true,
    'wide'    => true,
]);
?>
<style>
    .pd-bar { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 18px; }
    .pd-bar input, .pd-bar select { padding: 9px 12px; border: 1px solid var(--border); border-radius: 10px; font: inherit; background: var(--card); }
    .pd-bar input { flex: 1 1 240px; min-width: 0; }
    .pd-card { margin: 0 0 22px; padding: 20px 22px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 6px 18px rgba(15,23,42,.06); }
    .pd-card h2 { margin: 0 0 4px; font-size: 18px; font-weight: 800; letter-spacing: -.01em; }
    .pd-sub { margin: 0 0 12px; color: var(--muted); font-size: 13.5px; }
    .pd-wrap { overflow-x: auto; }
    .pd-t { width: 100%; border-collapse: collapse; font-size: 14.5px; }
    .pd-t th { text-align: left; color: var(--muted); font-size: 12.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 6px 8px; }
    .pd-t td { padding: 9px 8px; border-top: 1px solid var(--border); vertical-align: top; }
    .pd-t a { color: var(--primary); font-weight: 600; overflow-wrap: anywhere; }
    .pd-t small { color: var(--muted); }
    .pd-t tr[hidden] { display: none; }
    .pd-empty { margin: 10px 0 0; color: var(--muted); font-size: 14px; }
    .pd-note { color: var(--muted); font-size: 13.5px; line-height: 1.6; }
    .pd-note a { color: var(--primary); font-weight: 600; }
</style>

<div class="dash">
<?php if ($dir === null): ?>
    <div class="alert error">The members list is unavailable right now. Please try again in a few minutes.</div>
<?php else: ?>

    <div class="pd-bar">
        <input type="search" id="pd-q" placeholder="Search usernames" autocomplete="off" aria-label="Search usernames">
        <select id="pd-club" aria-label="Filter by club">
            <option value="">All clubs</option>
            <?php foreach (MemberModel::CHAPTER_NAMES as $k => $label): ?>
                <option value="<?= $h($k) ?>"<?= $k === $club ? ' selected' : '' ?>><?= $h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <section class="pd-card" aria-label="Current members">
        <h2>Current members (<span id="pd-n-current"><?= count($dir['current']) ?></span>)</h2>
        <p class="pd-sub">Members whose membership is active.</p>
        <div class="pd-wrap">
            <table class="pd-t">
                <thead><tr><th>Wikimedia username</th><th>Clubs</th></tr></thead>
                <tbody>
                <?php foreach ($dir['current'] as $p): ?>
                    <tr data-name="<?= $h(mb_strtolower($p['name'])) ?>" data-clubs="<?= $h(implode(' ', $p['clubs'])) ?>">
                        <td><a href="<?= $h($link($p['name'])) ?>" target="_blank" rel="noopener noreferrer"><?= $h($p['name']) ?></a></td>
                        <td><?= $h($names($p['clubs'])) ?><?= $p['former'] ? ' <small>(formerly ' . $h($names($p['former'])) . ')</small>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="pd-empty" id="pd-e-current" hidden>No one matches.</p>
    </section>

    <section class="pd-card" aria-label="Alumni">
        <h2>Alumni (<span id="pd-n-alumni"><?= count($dir['alumni']) ?></span>)</h2>
        <p class="pd-sub">Past members whose membership has ended. Thank you for what you contributed.</p>
        <div class="pd-wrap">
            <table class="pd-t">
                <thead><tr><th>Wikimedia username</th><th>Was part of</th></tr></thead>
                <tbody>
                <?php foreach ($dir['alumni'] as $p): ?>
                    <tr data-name="<?= $h(mb_strtolower($p['name'])) ?>" data-clubs="<?= $h(implode(' ', $p['former'])) ?>">
                        <td><a href="<?= $h($link($p['name'])) ?>" target="_blank" rel="noopener noreferrer"><?= $h($p['name']) ?></a></td>
                        <td><?= $h($names($p['former'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="pd-empty" id="pd-e-alumni" hidden>No one matches.</p>
    </section>

    <?php $legacy = $dir['legacy'] ?? []; if ($legacy): ?>
    <section class="pd-card" aria-label="Listed before Engage">
        <h2>Listed before Engage (<span id="pd-n-legacy"><?= count($legacy) ?></span>)</h2>
        <p class="pd-sub">People from DCW's earlier member list on Meta-Wiki who have not yet linked a verified username on Engage. They move up to Current members or Alumni automatically once they do. To be removed, contact the DCW organisers.</p>
        <div class="pd-wrap">
            <table class="pd-t">
                <thead><tr><th>Wikimedia username</th><th>Club</th></tr></thead>
                <tbody>
                <?php foreach ($legacy as $p): ?>
                    <tr data-name="<?= $h(mb_strtolower($p['name'])) ?>" data-clubs="<?= $h(implode(' ', $p['clubs'])) ?>">
                        <td><a href="<?= $h($link($p['name'])) ?>" target="_blank" rel="noopener noreferrer"><?= $h($p['name']) ?></a></td>
                        <td><?= $p['clubs'] ? $h($names($p['clubs'])) : '<small>DCW community</small>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="pd-empty" id="pd-e-legacy" hidden>No one matches.</p>
    </section>
    <?php endif; ?>

    <p class="pd-note">
        Only Wikimedia usernames are shown. Current members and alumni appear only once their username has been verified by DCW.
        If you are a member and want to be left off this list, sign in and change it on <a href="/member/profile#listing">your profile</a>.
        Want to join? See <a href="/membership">Membership</a>.
    </p>

    <script>
    (function () {
        var q = document.getElementById('pd-q'), club = document.getElementById('pd-club');
        function run() {
            var s = q.value.trim().toLowerCase().replace(/_/g, ' '), c = club.value;
            ['current', 'alumni', 'legacy'].forEach(function (kind) {
                var count = document.getElementById('pd-n-' + kind);
                if (!count) return;   // the legacy section is absent when there are no legacy entries
                var section = count.closest('section');
                var shown = 0;
                section.querySelectorAll('tbody tr').forEach(function (tr) {
                    var okName = !s || tr.dataset.name.indexOf(s) !== -1;
                    var okClub = !c || tr.dataset.clubs.split(' ').indexOf(c) !== -1;
                    tr.hidden = !(okName && okClub);
                    if (!tr.hidden) shown++;
                });
                count.textContent = shown;
                document.getElementById('pd-e-' + kind).hidden = shown !== 0;
            });
        }
        q.addEventListener('input', run);
        club.addEventListener('change', run);
        run();
    })();
    </script>
<?php endif; ?>
</div>
<?php engage_footer(); ?>
