<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/MemberCertificateModel.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/member_requests.php';
require_once __DIR__ . '/../../includes/cert_rows.php';

/**
 * DCW Engage - all certificates (/member/certificates).
 * Signed-in members only. Matched by the member's own verified email, exactly like the dashboard.
 * Search and year filter run in the database, 20 per page, so 100+ certificates stay fast.
 */
MemberSession::requireLogin();
$member = MemberSession::current();
$email  = strtolower((string) ($member['email'] ?? ''));

const CERT_PER_PAGE = 20;
$certBase = 'https://certificates.dcwwiki.org';

// Inputs (GET). Everything is cast or trimmed before it reaches the model.
$q    = trim((string) ($_GET['q'] ?? ''));
$year = (int) ($_GET['year'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));

$rows = []; $total = 0; $years = []; $failed = false;
try {
    $model = new MemberCertificateModel();
    $years = $model->yearsForMember($email);
    if ($year && !in_array($year, $years, true)) $year = 0;

    $res   = $model->searchForMember($email, $q, $year, CERT_PER_PAGE, ($page - 1) * CERT_PER_PAGE);
    $total = $res['total'];

    // If the page is past the end (filter changed, or a stale link), fall back to the last page.
    $pages = max(1, (int) ceil($total / CERT_PER_PAGE));
    if ($page > $pages) {
        $page = $pages;
        $res  = $model->searchForMember($email, $q, $year, CERT_PER_PAGE, ($page - 1) * CERT_PER_PAGE);
    }
    $rows = $res['rows'];
} catch (Throwable $e) {
    error_log('Engage certificates page failed: ' . $e->getMessage());
    $failed = true;
}
$pages     = max(1, (int) ceil($total / CERT_PER_PAGE));
$filtering = $q !== '' || $year > 0;
$from      = $total ? ($page - 1) * CERT_PER_PAGE + 1 : 0;
$to        = $total ? min($total, $page * CERT_PER_PAGE) : 0;

/** URL for another page of the same search. */
$pageUrl = function (int $p) use ($q, $year): string {
    $args = array_filter(['q' => $q, 'year' => $year ?: null, 'page' => $p > 1 ? $p : null], fn($v) => $v !== null && $v !== '');
    return '/member/certificates' . ($args ? '?' . http_build_query($args) : '');
};

engage_header([
    'title'   => 'My certificates',
    'heading' => 'My certificates',
    'kicker'  => 'Member ID ' . $member['member_id'],
    'lead'    => 'Every certificate issued to ' . $email . '.',
    'member'  => $member,
    'tools'   => '<a class="chip-btn" href="/member/dashboard">Back to dashboard</a>',
    'wide'    => true,
    'crumbs'  => [['Home', '/'], ['My dashboard', '/member/dashboard'], ['My certificates']],
]);
cert_rows_css();
?>
<style>
    .c-filter { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 16px; }
    .c-filter input[type=search], .c-filter select { font: inherit; font-size: 14.5px; padding: 9px 14px; color: var(--ink); background: #fff; border: 1px solid var(--border); border-radius: 999px; }
    .c-filter input[type=search] { flex: 1 1 240px; min-width: 0; }
    .c-filter input:focus-visible, .c-filter select:focus-visible { outline: 2px solid var(--primary); outline-offset: 1px; }
    .c-sum { margin: 0 0 10px; font-size: 13.5px; color: var(--muted); }
    .c-pager { display: flex; align-items: center; justify-content: center; gap: 14px; margin: 18px 0 0; font-size: 14px; color: var(--muted); }
    .c-pager .off { opacity: .45; pointer-events: none; }
    .c-clear { margin-left: 4px; font-size: 14px; font-weight: 600; color: var(--primary); }
    .d-empty { padding: 24px 22px; text-align: center; color: var(--muted); background: var(--card); border: 1px dashed #c3d0dc; border-radius: 14px; font-size: 14.5px; }
    .d-empty strong { display: block; margin-bottom: 4px; color: var(--ink); font-size: 16px; }
    .d-empty p { margin: 0 auto; max-width: 52ch; }
    .d-empty .d-btn { margin-top: 14px; }
</style>

<div class="dash">
    <section class="panel">

        <?php if ($failed): ?>
            <div class="d-empty">
                <strong>Certificates are unavailable right now</strong>
                <p>We could not load your certificates. Please try again in a few minutes.</p>
                <a class="d-btn line" href="/member/certificates">Try again</a>
            </div>

        <?php elseif ($total === 0 && !$filtering): ?>
            <div class="d-empty">
                <strong>No certificates issued yet</strong>
                <p>Certificates appear here after an event team issues them against <?= htmlspecialchars($email) ?>. If you attended an event and cannot find your certificate, please tell DCW Support which email address you used.</p>
                <a class="d-btn line" href="/member/talk">Contact DCW Support</a>
            </div>

        <?php else: ?>
            <form class="c-filter" method="get" action="/member/certificates" role="search">
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by event name or certificate ID" aria-label="Search certificates" maxlength="100">
                <?php if ($years): ?>
                    <select name="year" aria-label="Year issued" onchange="this.form.submit()">
                        <option value="0">All years</option>
                        <?php foreach ($years as $y): ?>
                            <option value="<?= $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button type="submit" class="d-btn fill">Search</button>
                <?php if ($filtering): ?><a class="c-clear" href="/member/certificates" style="align-self:center;">Clear</a><?php endif; ?>
            </form>

            <?php if ($total === 0): ?>
                <div class="d-empty">
                    <strong>No certificates match</strong>
                    <p>Try a different event name, a shorter search, or all years.</p>
                    <a class="d-btn line" href="/member/certificates">Show all certificates</a>
                </div>
            <?php else: ?>
                <p class="c-sum" role="status">
                    <?= $filtering ? 'Found ' . $total . ' certificate' . ($total === 1 ? '' : 's') . '.' : '' ?>
                    Showing <?= $from ?>&ndash;<?= $to ?> of <?= $total ?>.
                </p>
                <div class="c-list">
                    <?php foreach ($rows as $c) cert_row($c, $certBase); ?>
                </div>

                <?php if ($pages > 1): ?>
                    <nav class="c-pager" aria-label="Pages">
                        <a class="d-btn line<?= $page <= 1 ? ' off' : '' ?>" href="<?= htmlspecialchars($pageUrl($page - 1)) ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Previous</a>
                        <span>Page <?= $page ?> of <?= $pages ?></span>
                        <a class="d-btn line<?= $page >= $pages ? ' off' : '' ?>" href="<?= htmlspecialchars($pageUrl($page + 1)) ?>" <?= $page >= $pages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next</a>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    </section>
</div>
<?php cert_rows_js(); ?>
<?php engage_footer(); ?>
