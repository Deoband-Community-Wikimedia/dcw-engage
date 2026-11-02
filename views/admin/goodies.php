<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../models/MemberStrikeModel.php';
require_once __DIR__ . '/../../models/PointsModel.php';

/**
 * DCW Engage - goodies, reviewer side (/admin/goodies).
 * support_reviewer and owner only (the same roles as internet_review.php).
 *
 * Four tabs, kept in the address as ?tab=:
 *   award        award points for an activity, approve large awards, take points back
 *   activities   what can earn points and how many; changing points revises awards already made (#208)
 *   catalogue    add and edit goodies and their point costs
 *   redemptions  the order queue: Requested > Ordered > Shipped > Delivered (or Cancelled, which refunds)
 *
 * An award under PointsModel::SECOND_APPROVAL_AT is approved when it is created. A larger one waits for
 * a different reviewer. Every state-changing action is a CSRF-checked POST and is written to audit_log.
 *
 * Conversation Hour (award tab): attendance logged in Community records is awarded per session with
 * "Award now" (PointsModel::awardForSession), and hosts are set here. Re-running only adds what is new.
 */
Auth::requireLogin();
// Signed in but not a support reviewer or owner: 403, the same way Auth::requireOwner() treats a non-owner.
if (!Auth::hasAnyRole(['support_reviewer', 'owner'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<meta name="robots" content="noindex, nofollow"><title>Not allowed - DCW Engage</title></head><body '
       . 'style="font-family: Inter, -apple-system, sans-serif; background:#f8fafc; color:#1e293b; display:flex; align-items:center; '
       . 'justify-content:center; min-height:100vh; margin:0; padding:20px;"><div style="max-width:420px; text-align:center;">'
       . '<h1 style="color:#106b9a; font-size:20px; margin:0 0 8px;">Not allowed</h1>'
       . '<p style="color:#64748b; font-size:14px; line-height:1.6; margin:0 0 20px;">Goodies are managed by support reviewers. Ask an owner if you need access.</p>'
       . '<a href="/admin/dashboard" style="color:#106b9a; font-size:14px;">Back to workspace</a></div></body></html>';
    exit;
}

$actor = strtolower((string) Auth::email());
$model = new PointsModel();

// The reviewer's own Member IDs: Conversation Hour awards for these are left for another reviewer.
$ownIds = [];
try { $ownIds = array_column((new MemberModel())->membershipsForEmail($actor), 'member_id'); } catch (Throwable $e) { app_log_ignored('goodies.php:own', $e); }

const GOODIES_TABS = ['award' => 'Award points', 'activities' => 'Activities and points', 'catalogue' => 'Catalogue', 'redemptions' => 'Orders'];
$tab = isset(GOODIES_TABS[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'award';

function goodies_flash(string $kind, string $text): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['goodies_flash'] = [$kind, $text];
}

/**
 * The members rows for a Member ID, or [] when it is malformed or unknown. A Member ID is one
 * person across every chapter (letter + 8 digits), so the first row is enough to identify them.
 */
function goodies_find_member(string $raw): array
{
    $id = strtoupper(trim($raw));
    if (MemberModel::chapterFromMemberId($id) === null) return [];
    try { return (new MemberModel())->membershipsFor($id); } catch (Throwable $e) { app_log_ignored('goodies.php:member', $e); return []; }
}

/**
 * What a reviewer typed (a name, a verified Wikimedia username or a Member ID) as [memberships, error].
 * The error says why nobody was picked, listing the candidates when the name is shared or only partly matches.
 */
function goodies_lookup(PointsModel $model, string $typed): array
{
    $hit = $model->findMember($typed);
    if (!isset($hit['member_id'])) return [[], $hit['error']];
    $rows = goodies_find_member($hit['member_id']);
    return $rows ? [$rows, ''] : [[], 'No member was found for "' . trim($typed) . '".'];
}

/** "Full Name (A48213977)" for messages, so the reviewer can see who was picked. */
function goodies_who(array $memberships): string
{
    $m = $memberships[0];
    return trim((string) $m['full_name']) . ' (' . $m['member_id'] . ')';
}

/** True when the signed-in reviewer holds one of these memberships (any email on the rows is theirs). */
function goodies_is_own(array $memberships): bool
{
    foreach ($memberships as $m) if (Auth::isOwnEmail($m['email'] ?? '')) return true;
    return false;
}

// ---- Actions -------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $do   = (string) ($_POST['do'] ?? '');
    $back = $tab;
    try {
        if ($do === 'award' || $do === 'reverse') {
            $back = 'award';
            [$found, $notFound] = goodies_lookup($model, (string) ($_POST['member_id'] ?? ''));
            $mid   = $found ? (string) $found[0]['member_id'] : '';
            if (!$found) {
                goodies_flash('error', $notFound);
            } elseif ($do === 'award') {
                if (goodies_is_own($found)) {
                    goodies_flash('error', 'You cannot award points to yourself. Ask another reviewer.');
                } elseif ((new MemberStrikeModel())->isStruck($mid)) {
                    goodies_flash('error', 'That member is struck off, so points cannot be awarded.');
                } else {
                    $r = $model->createAward($mid, (int) ($_POST['activity_type_id'] ?? 0),
                        trim((string) ($_POST['evidence_url'] ?? '')), trim((string) ($_POST['note'] ?? '')), $actor);
                    goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok']
                        ? ($r['status'] === 'Approved' ? $r['points'] . ' points awarded to ' . goodies_who($found) . '.' : 'Saved for ' . goodies_who($found) . '. This award is ' . $r['points'] . ' points, so a different reviewer must approve it before it counts.')
                        : $r['error']);
                }
            } else {
                if (goodies_is_own($found)) {
                    goodies_flash('error', 'You cannot change your own points. Ask another reviewer.');
                } else {
                    $r = $model->reverse($mid, (int) ($_POST['points'] ?? 0), (string) ($_POST['reason'] ?? ''), $actor);
                    goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Points taken back from ' . goodies_who($found) . '.' : $r['error']);
                }
            }
        } elseif ($do === 'approve' || $do === 'reject') {
            $back = 'award';
            $awardId = (int) ($_POST['award_id'] ?? 0);
            $award   = $model->award($awardId);
            $owner   = $award ? goodies_find_member((string) $award['member_id']) : [];
            if ($do === 'approve' && $owner && goodies_is_own($owner)) {
                goodies_flash('error', 'You cannot approve points awarded to yourself.');
            } elseif ($do === 'approve') {
                $r = $model->approveAward($awardId, $actor);
                goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Approved. The points are now in the member’s balance.' : $r['error']);
            } else {
                goodies_flash($model->rejectAward($awardId, $actor) ? 'ok' : 'error', 'Award rejected.');
            }
        } elseif ($do === 'ch_award') {
            $back = 'award';
            $r = $model->awardForSession((int) ($_POST['session_id'] ?? 0), $actor, $ownIds);
            if (!$r['ok']) {
                goodies_flash('error', $r['error']);
            } else {
                $p = $r['plan'];
                $msg = $r['awarded'] === 1 ? '1 award made.' : $r['awarded'] . ' awards made.';
                if ($p['own'])        $msg .= ' Your own award is left for another reviewer.';
                if ($p['struck'])     $msg .= ' Skipped ' . count($p['struck']) . ' struck-off member(s).';
                if ($p['unresolved']) $msg .= ' ' . count($p['unresolved']) . ' attendee(s) could not be matched to a member.';
                goodies_flash('ok', $msg);
            }
        } elseif ($do === 'ch_host_add') {
            $back = 'award';
            [$found, $notFound] = goodies_lookup($model, (string) ($_POST['member_id'] ?? ''));
            if (!$found) {
                goodies_flash('error', $notFound);
            } else {
                $r = $model->addChHost((int) ($_POST['session_id'] ?? 0), (string) $found[0]['member_id'], $actor);
                goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? goodies_who($found) . ' saved as host. Use Award now to award them.' : $r['error']);
            }
        } elseif ($do === 'ch_host_remove') {
            $back = 'award';
            $model->removeChHost((int) ($_POST['session_id'] ?? 0), (string) ($_POST['member_id'] ?? ''), $actor);
            goodies_flash('ok', 'Host removed. Points already awarded stay; take them back below if needed.');
        } elseif ($do === 'activity_save') {
            $back = 'activities';
            $r = $model->saveActivity(((int) ($_POST['id'] ?? 0)) ?: null, $_POST, $actor);
            if (!$r['ok']) {
                goodies_flash('error', $r['error']);
            } elseif ($r['revised'] > 0) {
                goodies_flash('ok', 'Saved. ' . $r['revised'] . ' award(s) revised to the new points'
                    . ($r['members'] ? ', changing ' . $r['members'] . ' member(s)\' balance by ' . sprintf('%+d', $r['change']) . ' points in total' : '') . '.');
            } else {
                goodies_flash('ok', 'Saved.');
            }
        } elseif ($do === 'activity_delete') {
            $back = 'activities';
            $r = $model->deleteActivity((int) ($_POST['id'] ?? 0), $actor);
            goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Activity deleted.' : $r['error']);
        } elseif ($do === 'save_goody') {
            $back = 'catalogue';
            $r = $model->saveGoody(((int) ($_POST['id'] ?? 0)) ?: null, (string) ($_POST['name'] ?? ''), (string) ($_POST['description'] ?? ''),
                (int) ($_POST['points_cost'] ?? 0), (string) ($_POST['variants'] ?? ''), !empty($_POST['is_active']), $actor);
            goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Catalogue updated.' : $r['error']);
        } elseif ($do === 'order_status') {
            $back = 'redemptions';
            $r = $model->setRedemptionStatus((int) ($_POST['id'] ?? 0), (string) ($_POST['to'] ?? ''), $actor);
            goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Order updated.' : $r['error']);
        }
    } catch (Throwable $e) {
        app_log('Goodies admin action failed: ' . $e->getMessage());
        goodies_flash('error', 'Something went wrong. Please try again.');
    }
    header('Location: /admin/goodies?tab=' . $back);
    exit;
}

$flash = null;
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!empty($_SESSION['goodies_flash'])) { $flash = $_SESSION['goodies_flash']; unset($_SESSION['goodies_flash']); }

// ---- Data for the page ---------------------------------------------------------------------------
$totals = ['outstanding' => 0, 'awarded' => 0, 'spent' => 0, 'awaiting_second' => 0, 'requested' => 0];
$types = $awaiting = $goodies = $orders = [];
$statusFilter = (string) ($_GET['status'] ?? '');
try {
    $totals   = $model->totals();
    $types    = $model->activityTypes();
    $awaiting = $model->awardsAwaitingSecond();
    $goodies  = $model->goodies(false);
    $orders   = $tab === 'redemptions' ? $model->redemptionQueue($statusFilter) : [];
} catch (Throwable $e) {
    app_log('Goodies admin: load failed: ' . $e->getMessage());
    $flash = ['error', 'The goodies tables are not set up yet. Run database_goodies.sql first.'];
}

// Every activity, switched on or off, with how often each has been awarded (activities tab).
$allTypes = $usage = [];
if ($tab === 'activities') {
    try {
        $allTypes = $model->activityTypes(false);
        $usage    = $model->activityUsage();
    } catch (Throwable $e) { app_log_ignored('goodies.php:activities', $e); }
}

// Type-ahead for the member fields (award tab): every member's name, verified username and Member ID.
// The fields also accept any of the three typed by hand; see goodies_lookup().
$directory = [];
if ($tab === 'award') {
    try { $directory = $model->memberDirectory(); } catch (Throwable $e) { app_log_ignored('goodies.php:directory', $e); }
}

// Conversation Hour sessions with what "Award now" would do for each (award tab only).
$chRows = [];
$chError = '';
if ($tab === 'award') {
    try {
        foreach ($model->chSessions(12) as $sess) {
            $chRows[] = ['session' => $sess, 'plan' => $model->chPlan((int) $sess['id'], $ownIds)];
        }
    } catch (Throwable $e) {
        app_log_ignored('goodies.php:ch', $e);
        $chError = 'Conversation Hour awards need the migration 2026_10_27_goodies_ch_awards.sql.';
    }
}

$ist = new DateTimeZone('Asia/Kolkata');
$when = fn($ts) => $ts ? (new DateTimeImmutable((string) $ts, new DateTimeZone('UTC')))->setTimezone($ist)->format('j M Y, g:i a') : '';

engage_header([
    'title'   => 'Goodies',
    'heading' => 'Goodies',
    'lead'    => 'Award points, manage the catalogue and fulfil orders.',
    'slim'    => true,
    'wide'    => true,
    'crumbs'  => [],
    'tools'   => '',
]);
?>
<style>
    .gd-nav { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 20px; }
    .gd-nav a { padding: 7px 16px; border-radius: 999px; border: 1px solid var(--border); background: var(--card); color: var(--ink); font-weight: 600; font-size: 14px; text-decoration: none; }
    .gd-nav a.on { background: var(--primary); border-color: var(--primary); color: #fff; }
    .gd-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin: 0 0 22px; }
    .gd-stat { padding: 14px 16px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; }
    .gd-stat b { display: block; font-size: 22px; font-variant-numeric: tabular-nums; }
    .gd-stat span { color: var(--muted); font-size: 13px; }
    .gd-card { margin: 0 0 22px; padding: 18px; background: var(--card); border: 1px solid var(--border); border-radius: 14px; }
    .gd-card h3 { margin: 0 0 12px; font-size: 16px; }
    .gd-form { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); align-items: end; }
    .gd-form label { display: block; font-size: 13px; font-weight: 600; }
    .gd-form input[type=text], .gd-form input[type=number], .gd-form input[type=url], .gd-form select { width: 100%; box-sizing: border-box; padding: 8px 10px; font: inherit; border: 1px solid var(--border); border-radius: 8px; }
    .gd-form .wide { grid-column: 1 / -1; }
    .gd-btn { padding: 8px 16px; font: inherit; font-weight: 600; font-size: 14px; border: 1px solid var(--primary); border-radius: 999px; background: var(--primary); color: #fff; cursor: pointer; }
    .gd-btn.line { background: #fff; color: var(--primary); }
    .gd-btn.red { background: #fff; color: #97161b; border-color: #97161b; }
    .gd-btn[disabled] { opacity: .5; cursor: not-allowed; }
    .gd-row { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; justify-content: space-between; padding: 12px 0; border-top: 1px solid var(--border); }
    .gd-row:first-of-type { border-top: 0; }
    .gd-row p { margin: 2px 0 0; color: var(--muted); font-size: 13.5px; overflow-wrap: anywhere; }
    .gd-row form { display: inline; margin: 0; }
    .gd-empty { padding: 18px; text-align: center; color: var(--muted); }
    .gd-note { margin: 0 0 10px; color: var(--muted); font-size: 13.5px; }
    .gd-warn { color: #b45309; }
    .gd-hosts { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 6px; }
    .gd-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 4px 2px 10px; border: 1px solid var(--border); border-radius: 999px; font-size: 13px; }
    .gd-chip button { border: 0; background: none; color: #97161b; font: inherit; font-weight: 700; cursor: pointer; padding: 0 6px; }
    .gd-inline { display: inline-flex !important; gap: 6px; align-items: center; }
    .gd-inline input[type=text] { width: 9.5em; padding: 5px 8px; font: inherit; font-size: 13px; border: 1px solid var(--border); border-radius: 8px; }
    .gd-inline .gd-btn { padding: 5px 12px; font-size: 13px; }
</style>

<?php if ($flash): ?>
    <div class="alert <?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars((string) $flash[1]) ?></div>
<?php endif; ?>

<div class="gd-stats">
    <div class="gd-stat"><b><?= number_format($totals['outstanding']) ?></b><span>Points outstanding (≈ ₹ liability)</span></div>
    <div class="gd-stat"><b><?= number_format($totals['awarded']) ?></b><span>Points awarded, ever</span></div>
    <div class="gd-stat"><b><?= number_format($totals['spent']) ?></b><span>Points spent, net of refunds</span></div>
    <div class="gd-stat"><b><?= (int) $totals['awaiting_second'] ?></b><span>Awards awaiting a second reviewer</span></div>
    <div class="gd-stat"><b><?= (int) $totals['requested'] ?></b><span>New orders</span></div>
</div>

<nav class="gd-nav" aria-label="Goodies sections">
    <?php foreach (GOODIES_TABS as $key => $label): ?>
        <a href="/admin/goodies?tab=<?= $key ?>"<?= $tab === $key ? ' class="on" aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'award'): ?>

    <datalist id="gd-members">
        <?php foreach ($directory as $mid => $m): ?>
            <option value="<?= htmlspecialchars($mid) ?>"><?= htmlspecialchars($m['name'] . ($m['username'] !== '' ? ' · ' . $m['username'] : '')) ?></option>
        <?php endforeach; ?>
    </datalist>

    <section class="gd-card">
        <h3>Award points</h3>
        <form method="POST" action="/admin/goodies?tab=award" class="gd-form">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="do" value="award">
            <label>Member (name, Wikimedia username or Member ID)<input type="text" name="member_id" list="gd-members" required maxlength="190" autocomplete="off"></label>
            <label>Activity
                <select name="activity_type_id" required>
                    <option value="">Choose…</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= (int) $t['id'] ?>"><?= htmlspecialchars($t['name']) ?> (<?= (int) $t['points'] ?><?= (int) $t['points'] >= PointsModel::SECOND_APPROVAL_AT ? ', needs 2nd approval' : '' ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Evidence link (diff or page; required for some activities)<input type="url" name="evidence_url" maxlength="500" placeholder="https://"></label>
            <label>Note (optional)<input type="text" name="note" maxlength="500"></label>
            <div><button type="submit" class="gd-btn">Award</button></div>
        </form>
    </section>

    <section class="gd-card">
        <h3>Conversation Hour</h3>
        <p class="gd-note">Points for attendance logged in <a href="/admin/community-records">Community records</a>. Members logged present earn the attend points; each host earns the host points instead (fewer if under the minimum attendance). Award now can be run again later: it only adds people logged since.</p>
        <?php if ($chError !== ''): ?>
            <div class="gd-empty"><?= htmlspecialchars($chError) ?></div>
        <?php elseif (!$chRows): ?>
            <div class="gd-empty">No sessions logged yet.</div>
        <?php else: foreach ($chRows as $row): $sess = $row['session']; $p = $row['plan']; $sid = (int) $sess['id']; $todo = count($p['award']); ?>
            <div class="gd-row">
                <div>
                    <strong><?= htmlspecialchars((string) $sess['title']) ?> · <?= htmlspecialchars(date('j M Y', strtotime((string) $sess['held_on']))) ?></strong>
                    <p><?= (int) $p['present'] ?> present · <?= (int) $p['done'] ?> awarded · <strong><?= $todo ?> to award</strong>
                        <?php if ($p['hosts'] && $p['host_points']): ?> · host <?= (int) $p['host_points'] ?> pts<?php endif; ?></p>
                    <?php if ($p['missing']): ?><p class="gd-warn">Switched off or missing activity: <?= htmlspecialchars(implode(', ', $p['missing'])) ?>. Nothing is awarded for it.</p><?php endif; ?>
                    <?php if ($p['own']): ?><p class="gd-warn">Your own award (<?= htmlspecialchars(implode(', ', $p['own'])) ?>) is left for another reviewer.</p><?php endif; ?>
                    <?php if ($p['struck']): ?><p class="gd-warn">Struck off, not awarded: <?= htmlspecialchars(implode(', ', $p['struck'])) ?></p><?php endif; ?>
                    <?php if ($p['unresolved']): ?><p class="gd-warn">Not matched to a member (no Member ID or verified username): <?= htmlspecialchars(implode(', ', $p['unresolved'])) ?></p><?php endif; ?>
                    <?php if ($p['gone']): ?><p class="gd-warn">Awarded, but no longer logged present: <?= htmlspecialchars(implode(', ', $p['gone'])) ?>. If that was a mistake, take the points back below.</p><?php endif; ?>
                    <div class="gd-hosts">
                        <span style="font-size:13px;color:var(--muted)">Hosts:</span>
                        <?php foreach ($p['hosts'] as $h): ?>
                            <form method="POST" action="/admin/goodies?tab=award" class="gd-chip"><?= CSRF::getInputField() ?>
                                <input type="hidden" name="do" value="ch_host_remove"><input type="hidden" name="session_id" value="<?= $sid ?>"><input type="hidden" name="member_id" value="<?= htmlspecialchars($h) ?>">
                                <?= htmlspecialchars($h) ?><button type="submit" aria-label="Remove host <?= htmlspecialchars($h) ?>" title="Remove host">×</button>
                            </form>
                        <?php endforeach; ?>
                        <form method="POST" action="/admin/goodies?tab=award" class="gd-inline"><?= CSRF::getInputField() ?>
                            <input type="hidden" name="do" value="ch_host_add"><input type="hidden" name="session_id" value="<?= $sid ?>">
                            <input type="text" name="member_id" list="gd-members" required maxlength="190" placeholder="Host: name or ID" aria-label="Host for <?= htmlspecialchars((string) $sess['title']) ?>: name, Wikimedia username or Member ID" autocomplete="off">
                            <button type="submit" class="gd-btn line">Add host</button>
                        </form>
                    </div>
                </div>
                <div>
                    <form method="POST" action="/admin/goodies?tab=award"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="do" value="ch_award"><input type="hidden" name="session_id" value="<?= $sid ?>">
                        <button type="submit" class="gd-btn"<?= $todo ? '' : ' disabled' ?>>Award now<?= $todo ? ' (' . $todo . ')' : '' ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </section>

    <section class="gd-card">
        <h3>Waiting for a second reviewer (<?= count($awaiting) ?>)</h3>
        <?php if (!$awaiting): ?>
            <div class="gd-empty">Nothing is waiting.</div>
        <?php else: foreach ($awaiting as $a): $mine = strcasecmp((string) $a['first_reviewer_email'], $actor) === 0; ?>
            <div class="gd-row">
                <div>
                    <strong><?= htmlspecialchars($a['activity_name']) ?> · <?= (int) $a['points'] ?> pts · <?= htmlspecialchars((string) $a['member_id']) ?></strong>
                    <p>Created by <?= htmlspecialchars((string) $a['first_reviewer_email']) ?> on <?= htmlspecialchars($when($a['created_at'])) ?>
                        <?php if (!empty($a['evidence_url'])): ?> · <a href="<?= htmlspecialchars((string) $a['evidence_url']) ?>" target="_blank" rel="noopener noreferrer">evidence</a><?php endif; ?>
                        <?php if (!empty($a['note'])): ?> · <?= htmlspecialchars((string) $a['note']) ?><?php endif; ?></p>
                </div>
                <div>
                    <form method="POST" action="/admin/goodies?tab=award"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="do" value="approve"><input type="hidden" name="award_id" value="<?= (int) $a['id'] ?>">
                        <button type="submit" class="gd-btn"<?= $mine ? ' disabled title="A different reviewer must approve this"' : '' ?>>Approve</button>
                    </form>
                    <form method="POST" action="/admin/goodies?tab=award"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="do" value="reject"><input type="hidden" name="award_id" value="<?= (int) $a['id'] ?>">
                        <button type="submit" class="gd-btn red">Reject</button>
                    </form>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </section>

    <section class="gd-card">
        <h3>Take points back</h3>
        <p style="margin:0 0 10px;color:var(--muted);font-size:13.5px">For a bad award or a code-of-conduct breach. This adds a negative entry to the member’s history; the original award stays on record. A balance can go below zero if the points were already spent.</p>
        <form method="POST" action="/admin/goodies?tab=award" class="gd-form">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="do" value="reverse">
            <label>Member (name, Wikimedia username or Member ID)<input type="text" name="member_id" list="gd-members" required maxlength="190" autocomplete="off"></label>
            <label>Points to take back<input type="number" name="points" min="1" max="100000" required></label>
            <label class="wide">Reason (shown to the member)<input type="text" name="reason" required maxlength="230"></label>
            <div><button type="submit" class="gd-btn red">Take back</button></div>
        </form>
    </section>

<?php elseif ($tab === 'activities'): ?>

    <section class="gd-card">
        <h3>Add an activity</h3>
        <form method="POST" action="/admin/goodies?tab=activities" class="gd-form">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="do" value="activity_save">
            <label>Name<input type="text" name="name" required maxlength="160" placeholder="Attend a Wiki Loves Monuments walk"></label>
            <label>Category
                <select name="category" required>
                    <?php foreach (PointsModel::CATEGORIES as $key => $label): ?><option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Points<input type="number" name="points" min="1" max="10000" required></label>
            <label><input type="checkbox" name="needs_evidence" value="1"> Needs an evidence link</label>
            <label><input type="checkbox" name="one_time" value="1"> Only once per member</label>
            <label><input type="checkbox" name="is_active" value="1" checked> Can be awarded</label>
            <div><button type="submit" class="gd-btn">Add</button></div>
        </form>
    </section>

    <section class="gd-card">
        <h3>Activities (<?= count($allTypes) ?>)</h3>
        <p class="gd-note">Changing an activity's points <strong>revises every award already made for it</strong>: each member's balance moves by the difference, and the change shows in their points history. Awards of <?= PointsModel::SECOND_APPROVAL_AT ?> points or more need a second reviewer. Minimum attendance is for hosting: under it, the host earns the lower points. An activity that has been awarded can be switched off but not deleted.</p>
        <?php foreach ($allTypes as $t): $used = (int) ($usage[(int) $t['id']] ?? 0); $builtIn = in_array((string) $t['code'], PointsModel::BUILT_IN_CODES, true); ?>
            <form method="POST" action="/admin/goodies?tab=activities" class="gd-form gd-row gd-activity" style="display:grid"
                  data-used="<?= $used ?>" data-points="<?= (int) $t['points'] ?>" data-below="<?= $t['below_min_points'] === null ? '' : (int) $t['below_min_points'] ?>">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="do" value="activity_save">
                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                <label class="wide">Name<input type="text" name="name" value="<?= htmlspecialchars((string) $t['name']) ?>" required maxlength="160"></label>
                <label>Category
                    <select name="category" required>
                        <?php foreach (PointsModel::CATEGORIES as $key => $label): ?><option value="<?= $key ?>"<?= $t['category'] === $key ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Points<input type="number" name="points" value="<?= (int) $t['points'] ?>" min="1" max="10000" required></label>
                <label>Minimum attendance (optional)<input type="number" name="min_attendees" value="<?= $t['min_attendees'] === null ? '' : (int) $t['min_attendees'] ?>" min="1" max="1000"></label>
                <label>Points under the minimum<input type="number" name="below_min_points" value="<?= $t['below_min_points'] === null ? '' : (int) $t['below_min_points'] ?>" min="0" max="10000"></label>
                <label><input type="checkbox" name="needs_evidence" value="1"<?= (int) $t['needs_evidence'] ? ' checked' : '' ?>> Needs an evidence link</label>
                <label><input type="checkbox" name="one_time" value="1"<?= (int) ($t['one_time'] ?? 0) ? ' checked' : '' ?>> Only once per member</label>
                <label><input type="checkbox" name="is_active" value="1"<?= (int) $t['is_active'] ? ' checked' : '' ?>> Can be awarded</label>
                <div>
                    <button type="submit" class="gd-btn line">Save</button>
                    <span class="gd-note" style="margin-left:8px"><?= $used ? 'Awarded ' . $used . ' time' . ($used === 1 ? '' : 's') : 'Not awarded yet' ?><?= $builtIn ? ' · used by the app' : '' ?></span>
                </div>
            </form>
            <?php if (!$used && !$builtIn): ?>
                <form method="POST" action="/admin/goodies?tab=activities" style="margin:-6px 0 8px"
                      onsubmit="return confirm('Delete this activity? It has never been awarded.')">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="do" value="activity_delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="gd-btn red" style="padding:4px 12px;font-size:13px">Delete <?= htmlspecialchars((string) $t['name']) ?></button>
                </form>
            <?php endif; ?>
        <?php endforeach; ?>
    </section>
    <script>
    // Changing the points of an activity that has been awarded revises those awards: say so before saving.
    document.querySelectorAll('form.gd-activity').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            var used = +f.dataset.used, p = f.elements.points.value, b = f.elements.below_min_points.value;
            if (used > 0 && (p !== f.dataset.points || b !== f.dataset.below)
                && !confirm('This activity has been awarded ' + used + ' time(s). Saving new points revises all of those awards, and members\' balances move by the difference. Continue?')) {
                ev.preventDefault();
            }
        });
    });
    </script>

<?php elseif ($tab === 'catalogue'): ?>

    <section class="gd-card">
        <h3>Add a goody</h3>
        <form method="POST" action="/admin/goodies?tab=catalogue" class="gd-form">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="do" value="save_goody">
            <label>Name<input type="text" name="name" required maxlength="120"></label>
            <label>Cost (points)<input type="number" name="points_cost" min="1" max="100000" required></label>
            <label>Sizes or options (comma separated, optional)<input type="text" name="variants" placeholder="S, M, L, XL"></label>
            <label class="wide">Description<input type="text" name="description" maxlength="500"></label>
            <label><input type="checkbox" name="is_active" value="1" checked> Show to members</label>
            <div><button type="submit" class="gd-btn">Add</button></div>
        </form>
    </section>

    <section class="gd-card">
        <h3>Catalogue (<?= count($goodies) ?>)</h3>
        <p style="margin:0 0 6px;color:var(--muted);font-size:13.5px">Members only see goodies that are switched on. Changing a cost never affects orders already placed.</p>
        <?php foreach ($goodies as $g): ?>
            <form method="POST" action="/admin/goodies?tab=catalogue" class="gd-form gd-row" style="display:grid">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="do" value="save_goody">
                <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                <label>Name<input type="text" name="name" value="<?= htmlspecialchars($g['name']) ?>" required maxlength="120"></label>
                <label>Cost<input type="number" name="points_cost" value="<?= (int) $g['points_cost'] ?>" min="1" max="100000" required></label>
                <label>Options<input type="text" name="variants" value="<?= htmlspecialchars(implode(', ', $g['variants'])) ?>"></label>
                <label class="wide">Description<input type="text" name="description" value="<?= htmlspecialchars((string) $g['description']) ?>" maxlength="500"></label>
                <label><input type="checkbox" name="is_active" value="1"<?= (int) $g['is_active'] ? ' checked' : '' ?>> Show to members</label>
                <div><button type="submit" class="gd-btn line">Save</button></div>
            </form>
        <?php endforeach; ?>
    </section>

<?php else: ?>

    <nav class="gd-nav" aria-label="Filter orders">
        <?php foreach (array_merge([''], array_keys(PointsModel::REDEMPTION_FLOW)) as $s): ?>
            <a href="/admin/goodies?tab=redemptions<?= $s !== '' ? '&amp;status=' . rawurlencode($s) : '' ?>"<?= $statusFilter === $s ? ' class="on"' : '' ?>><?= $s === '' ? 'All' : htmlspecialchars($s) ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="gd-card">
        <?php if (!$orders): ?>
            <div class="gd-empty">No orders here.</div>
        <?php else: foreach ($orders as $o): $ship = $o['shipping']; ?>
            <div class="gd-row">
                <div>
                    <strong><?= htmlspecialchars($o['goody_name']) ?><?= $o['variant'] ? ' (' . htmlspecialchars((string) $o['variant']) . ')' : '' ?> · <?= (int) $o['points_spent'] ?> pts</strong>
                    <p><code><?= htmlspecialchars((string) $o['tracking_id']) ?></code> · Member <?= htmlspecialchars((string) $o['member_id']) ?> · <?= htmlspecialchars($when($o['created_at'])) ?> · <strong><?= htmlspecialchars((string) $o['status']) ?></strong></p>
                    <?php if ($ship): ?>
                        <p>Ship to <?= htmlspecialchars((string) ($ship['name'] ?? '')) ?>, <?= htmlspecialchars((string) ($ship['phone'] ?? '')) ?> · <?= nl2br(htmlspecialchars((string) ($ship['address'] ?? ''))) ?></p>
                    <?php elseif (in_array($o['status'], ['Delivered', 'Cancelled'], true)): ?>
                        <p>Shipping details cleared.</p>
                    <?php endif; ?>
                </div>
                <div>
                    <?php foreach (PointsModel::REDEMPTION_FLOW[$o['status']] as $next): ?>
                        <form method="POST" action="/admin/goodies?tab=redemptions"><?= CSRF::getInputField() ?>
                            <input type="hidden" name="do" value="order_status"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="to" value="<?= htmlspecialchars($next) ?>">
                            <button type="submit" class="gd-btn <?= $next === 'Cancelled' ? 'red' : 'line' ?>"<?= $next === 'Cancelled' ? ' onclick="return confirm(\'Cancel this order and refund the points?\')"' : '' ?>><?= $next === 'Cancelled' ? 'Cancel and refund' : 'Mark ' . htmlspecialchars(strtolower($next)) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </section>

<?php endif; ?>

<?php engage_footer(); ?>
