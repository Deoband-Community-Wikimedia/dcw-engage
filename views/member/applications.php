<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/ApplicationModel.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/member_requests.php';
require_once __DIR__ . '/../../includes/cert_rows.php';

/**
 * DCW Engage - all applications (/member/applications).
 * Signed-in members only. Matched by the member's own email, exactly like the dashboard.
 * Search, status and year filters run in the database, 20 per page.
 * Drafts come first. "Email me the link" posts to /member/dashboard, which already
 * checks the draft belongs to this member and sends the resume link.
 * A Discarded application is shown as "Under review": applicants are never told it was discarded.
 */
MemberSession::requireLogin();
$member = MemberSession::current();
$email  = strtolower((string) ($member['email'] ?? ''));

const APP_PER_PAGE = 20;

// Inputs (GET). Everything is cast, trimmed or checked against a whitelist before it reaches the model.
$q      = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
if (!isset(ApplicationModel::STATUS_GROUPS[$status])) $status = '';
$year   = (int) ($_GET['year'] ?? 0);
$page   = max(1, (int) ($_GET['page'] ?? 1));

// A draft can be resumed only while its form is still open (same rule as the dashboard).
$resumable = [];
try { $resumable = array_map('intval', array_column((new FormModel())->draftsForEmail($email), 'id')); } catch (Throwable $e) { app_log_ignored('applications.php:33', $e); /* none resumable */ }

$rows = []; $total = 0; $years = []; $failed = false;
try {
    $model = new ApplicationModel();
    $years = $model->yearsForMember($email);
    if ($year && !in_array($year, $years, true)) $year = 0;

    $res   = $model->searchForMember($email, $q, $status, $year, APP_PER_PAGE, ($page - 1) * APP_PER_PAGE);
    $total = $res['total'];

    // Past the end (filter changed, or a stale link): fall back to the last page.
    $pages = max(1, (int) ceil($total / APP_PER_PAGE));
    if ($page > $pages) {
        $page = $pages;
        $res  = $model->searchForMember($email, $q, $status, $year, APP_PER_PAGE, ($page - 1) * APP_PER_PAGE);
    }
    $rows = $res['rows'];
} catch (Throwable $e) {
    app_log('Member applications page failed: ' . $e->getMessage());
    $failed = true;
}
$pages     = max(1, (int) ceil($total / APP_PER_PAGE));
$filtering = $q !== '' || $status !== '' || $year > 0;
$from      = $total ? ($page - 1) * APP_PER_PAGE + 1 : 0;
$to        = $total ? min($total, $page * APP_PER_PAGE) : 0;

/** URL for another page of the same search. */
$pageUrl = function (int $p) use ($q, $status, $year): string {
    $args = array_filter(
        ['q' => $q, 'status' => $status, 'year' => $year ?: null, 'page' => $p > 1 ? $p : null],
        fn($v) => $v !== null && $v !== ''
    );
    return '/member/applications' . ($args ? '?' . http_build_query($args) : '');
};

// Same frame as the dashboard: slim top bar, shared member menu (hamburger), one quiet back link.
engage_header([
    'title'   => 'My applications',
    'heading' => 'My applications',
    'lead'    => 'Every application submitted with ' . $email . '.',
    'compact' => true,
    'back'    => ['Dashboard', '/member/dashboard'],
    'member'  => $member,
    'tools'   => engage_member_menu($member, ['current' => '/member/applications']),
    'wide'    => true,
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
    .req .side form { margin: 0; }
    .req .side button.d-btn { font: inherit; font-size: 13.5px; cursor: pointer; }
    .d-empty { padding: 24px 22px; text-align: center; color: var(--muted); background: var(--card); border: 1px dashed #c3d0dc; border-radius: 14px; font-size: 14.5px; }
    .d-empty strong { display: block; margin-bottom: 4px; color: var(--ink); font-size: 16px; }
    .d-empty p { margin: 0 auto; max-width: 52ch; }
    .d-empty .d-btn { margin-top: 14px; }
</style>

<div class="dash">
    <section class="panel">

        <?php if ($failed): ?>
            <div class="d-empty">
                <strong>Applications are unavailable right now</strong>
                <p>We could not load your applications. Please try again in a few minutes.</p>
                <a class="d-btn line" href="/member/applications">Try again</a>
            </div>

        <?php elseif ($total === 0 && !$filtering): ?>
            <div class="d-empty">
                <strong>No applications yet</strong>
                <p>Programs you apply to will be listed here, including drafts you have not finished.</p>
                <a class="d-btn line" href="/">Browse programs</a>
            </div>

        <?php else: ?>
            <form class="c-filter" method="get" action="/member/applications" role="search">
                <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by program name or tracking ID" aria-label="Search applications" maxlength="100">
                <select name="status" aria-label="Status" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <?php foreach (ApplicationModel::STATUS_GROUPS as $key => [$label]): ?>
                        <option value="<?= htmlspecialchars($key) ?>"<?= $key === $status ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($years): ?>
                    <select name="year" aria-label="Year applied" onchange="this.form.submit()">
                        <option value="0">All years</option>
                        <?php foreach ($years as $y): ?>
                            <option value="<?= $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <button type="submit" class="d-btn fill">Search</button>
                <?php if ($filtering): ?><a class="c-clear" href="/member/applications" style="align-self:center;">Clear</a><?php endif; ?>
            </form>

            <?php if ($total === 0): ?>
                <div class="d-empty">
                    <strong>No applications match</strong>
                    <p>Try a different program name, a shorter search, or all statuses and years.</p>
                    <a class="d-btn line" href="/member/applications">Show all applications</a>
                </div>
            <?php else: ?>
                <p class="c-sum" role="status">
                    <?= $filtering ? 'Found ' . $total . ' application' . ($total === 1 ? '' : 's') . '.' : '' ?>
                    Showing <?= $from ?>&ndash;<?= $to ?> of <?= $total ?>.
                </p>

                <div class="reqs">
                    <?php foreach ($rows as $a):
                        $id = (int) $a['id'];
                        [$label, $tone, $act] = dash_app_status((string) $a['status'], in_array($id, $resumable, true));
                        $title = trim((string) ($a['form_title'] ?? ''));
                        if ($title === '') $title = ucwords(str_replace(['-', '_'], ' ', (string) $a['form_type']));
                        $tracking = (string) $a['tracking_id'];
                    ?>
                        <div class="req<?= $act ? ' act' : '' ?>" style="--tone: <?= htmlspecialchars($tone) ?>;">
                            <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span>
                            <div>
                                <h3><?= htmlspecialchars($title) ?></h3>
                                <p class="meta">
                                    Application &middot; <?= htmlspecialchars(dash_date($a['created_at'])) ?>
                                    &middot; <code><?= htmlspecialchars($tracking) ?></code><button type="button" class="copy" data-copy="<?= htmlspecialchars($tracking) ?>">Copy</button>
                                </p>
                            </div>
                            <div class="side">
                                <span class="pill"><?= htmlspecialchars($label) ?></span>
                                <?php if ($act): ?>
                                    <form method="POST" action="/member/dashboard">
                                        <?= CSRF::getInputField() ?>
                                        <input type="hidden" name="action" value="send_draft_link">
                                        <input type="hidden" name="application_id" value="<?= $id ?>">
                                        <button type="submit" class="d-btn amber">Email me the link</button>
                                    </form>
                                <?php else: ?>
                                    <a href="/track?id=<?= htmlspecialchars(rawurlencode($tracking)) ?>">View &rarr;</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
<?php cert_rows_js(); /* copy buttons for tracking IDs */ ?>
<?php engage_footer(); ?>
