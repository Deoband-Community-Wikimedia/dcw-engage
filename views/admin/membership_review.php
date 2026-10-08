<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../includes/mail/membership_mailer.php';

// Organizers only work with application forms, so they are not in this list.
requireRole(['membership_coordinator', 'membership_reviewer', 'owner']);

$model = new MemberModel();
// Pass the FULL role list: an account can hold several roles, and Auth::role() is only the primary one.
$scope = $model->scopeFor(Auth::roles(), (string) Auth::email());   // null = every chapter
// Changing which Member ID a club membership uses is a reviewer decision, not a coordinator one.
$canLinkIds = Auth::hasAnyRole(['membership_reviewer', 'owner']);
$notice = ''; $error = '';
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$app = $id ? $model->getApplication($id, $scope) : false;
if ($id && !$app) { http_response_code(404); die('Application not found.'); }

// ---------------------------------------------------------------------------
// Bulk action from the queue: one action applied to every ticked application.
// Each application is re-loaded through getApplication($id, $scope), so a
// coordinator can never touch a chapter outside their scope by editing the
// request, and anything no longer open is skipped rather than failing the
// batch. Every item is handled on its own: one failure never stops the rest,
// and an email failure never undoes a decision that is already saved.
// ---------------------------------------------------------------------------
const BULK_MAX = 100;

if (!$id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');

    $bulkAct = (string) ($_POST['bulk_action'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    // One note field serves both: it is the rejection reason or the "what we
    // need from you" message, depending on the action chosen.
    $note = trim((string) ($_POST['note'] ?? ''));
    $reason = $note;
    $message = $note;

    if (!in_array($bulkAct, ['review', 'approve', 'info', 'reject'], true)) {
        $error = 'Choose an action to apply.';
    } elseif (!$ids) {
        $error = 'Tick at least one application first.';
    } elseif (count($ids) > BULK_MAX) {
        $error = 'Please act on at most ' . BULK_MAX . ' applications at a time.';
    } elseif ($bulkAct === 'reject' && $reason === '') {
        $error = 'Give a reason so the applicants know what to do next.';
    } elseif ($bulkAct === 'info' && $message === '') {
        $error = 'Say what you need from the applicants.';
    } else {
        @set_time_limit(300);   // each approve/reject sends an email
        $by = currentAdminIdentifier();
        $result = ['act' => $bulkAct, 'done' => 0, 'skipped' => [], 'links' => []];

        foreach ($ids as $appId) {
            $row = $model->getApplication($appId, $scope);
            if (!$row) {
                $result['skipped'][] = "#$appId: not found, or outside your chapters";
                continue;
            }
            $who = $row['applicant_name'] ?: $row['email'];

            if (!in_array($row['status'], MemberModel::OPEN, true)) {
                $result['skipped'][] = "$who: already " . ($row['status'] === 'Draft' ? 'awaiting the applicant' : strtolower($row['status']));
                continue;
            }
            if ($bulkAct === 'review' && $row['status'] === 'Under Review') {
                $result['skipped'][] = "$who: already under review";
                continue;
            }

            try {
                $mail = null;
                if ($bulkAct === 'approve') {
                    [$memberId, $exp, $shared] = $model->approve($row, $by);
                    $mail = ['approved', $memberId, $exp, $shared];
                } elseif ($bulkAct === 'reject') {
                    $model->reject($row, $reason, $by);
                    $mail = ['rejected', null, $reason];
                } elseif ($bulkAct === 'review') {
                    $model->markUnderReview($row, $by);
                } else {   // info
                    $token = $model->requestInfo($row, $message, $by);
                    $sent = false;
                    try {
                        $sent = (bool) MembershipMailer::sendInfoRequestFor($row, $message, $token);
                    } catch (Throwable $e) {
                        app_log("Info-request email failed for application #{$row['id']}: " . $e->getMessage());
                    }
                    if (!$sent) {   // never strand the link
                        $config = require __DIR__ . '/../../includes/config.php';
                        $result['links'][] = [
                            'who' => $who,
                            'url' => rtrim($config['app']['url'], '/') . '/resume/' . $token,
                        ];
                    }
                }

                app_log("Membership bulk $bulkAct: application #{$row['id']} ({$row['tracking_id']}) by $by");
                $result['done']++;

                if ($mail) {
                    try {
                        // Also emails the new member a "set your password" link on approval.
                        MembershipMailer::sendDecisionFor($row, $mail[0], $mail[1], $mail[2], $mail[3] ?? false);
                    } catch (Throwable $e) {
                        app_log("Membership email failed for application #{$row['id']}: " . $e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                $result['skipped'][] = "$who: " . $e->getMessage();
            }
        }

        $_SESSION['membership_bulk'] = $result;
        $keep = array_filter(['form' => $_GET['form'] ?? '', 'status' => $_GET['status'] ?? ''], 'strlen');
        header('Location: /admin/membership-review' . ($keep ? '?' . http_build_query($keep) : ''));
        exit;
    }
}

if ($app && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    $act = $_POST['action'] ?? '';
    $by = currentAdminIdentifier();
    try {
        if ($act === 'approve') {
            [$memberId, $exp, $shared] = $model->approve($app, $by);
            $mail = ['approved', $memberId, $exp, $shared];
        } elseif ($act === 'reject') {
            $reason = trim($_POST['reason'] ?? '');
            if ($reason === '') throw new Exception('Give a reason so the applicant knows what to do next.');
            $model->reject($app, $reason, $by);
            $mail = ['rejected', null, $reason];
        } elseif ($act === 'info' || $act === 'resend') {
            if ($act === 'info') {
                $msg = trim($_POST['message'] ?? '');
                if ($msg === '') throw new Exception('Say what you need from the applicant.');
                $token = $model->requestInfo($app, $msg, $by);
            } else {
                $token = $model->resendLink($app);
                $msg = (string) $model->lastInfoMessage((int) $app['id']);
            }
            $sent = false;   // Mailer returns false when it only logged (dev mode) or failed
            try {
                $sent = (bool) MembershipMailer::sendInfoRequestFor($app, $msg, $token);
            } catch (Throwable $e) { app_log("Info-request email failed for application #{$app['id']}: " . $e->getMessage()); }
            if (!$sent) {   // same idea as the invite flow: never strand the link
                $config = require __DIR__ . '/../../includes/config.php';
                $_SESSION['membership_link'] = rtrim($config['app']['url'], '/') . '/resume/' . $token;
            }
            $mail = null;
        } elseif ($act === 'review') {
            $model->markUnderReview($app, $by);
            $mail = null;
        } elseif (in_array($act, ['link_id', 'unlink_id', 'relink_id'], true)) {
            // Share one Member ID between the Generic Community and a club. No email is sent.
            if (!$canLinkIds) throw new Exception('Only reviewers can change Member IDs.');
            $given = (string) ($_POST['link_member_id'] ?? '');
            $confirm = !empty($_POST['link_confirm']);
            if ($act === 'link_id') {
                $model->linkMemberId($app, $given, $by, $confirm);
            } elseif ($act === 'unlink_id') {
                $model->unlinkMemberId((int) $app['id']);
            } else {
                $old = $model->relinkApproved($app, $given, $by, $confirm);
                app_log("Membership ID changed: application #{$app['id']} ({$app['tracking_id']}) $old -> "
                    . strtoupper(trim($given)) . " by $by");
            }
            $mail = null;
        } else { throw new Exception('Unknown action.'); }

        app_log("Membership $act: application #{$app['id']} ({$app['tracking_id']}) by $by");
        // Email failure must never undo a decision that is already saved.
        if (!empty($mail)) {
            try {
                // Also emails the new member a "set your password" link on approval.
                MembershipMailer::sendDecisionFor($app, $mail[0], $mail[1], $mail[2], $mail[3] ?? false);
            } catch (Throwable $e) { app_log("Membership email failed for application #{$app['id']}: " . $e->getMessage()); }
        }
        header('Location: /admin/membership-review?id=' . $app['id'] . '&done=' . urlencode($act));
        exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
    $app = $model->getApplication($id, $scope);   // show fresh state after an error
}
$doneText = ['approve' => 'Approved.', 'reject' => 'Rejected.', 'review' => 'Marked under review.',
             'info' => 'Sent back to the applicant.', 'resend' => 'Link sent again.',
             'link_id' => 'Linked. On approval this member keeps that ID and no new one is made.',
             'unlink_id' => 'Link removed.',
             'relink_id' => 'Member ID changed. The confirmation email they received shows the old ID, so let them know.'];
if (!empty($_GET['done'])) $notice = $doneText[$_GET['done']] ?? 'Saved.';
$manualLink = $_SESSION['membership_link'] ?? '';
unset($_SESSION['membership_link']);

// Result of the last bulk action, shown once on the queue.
$bulk = $_SESSION['membership_bulk'] ?? null;
unset($_SESSION['membership_bulk']);
$bulkVerb = ['approve' => 'approved', 'reject' => 'rejected', 'review' => 'marked under review', 'info' => 'sent back for more information'];

$fSlug = (string) ($_GET['form'] ?? ''); $fStatus = (string) ($_GET['status'] ?? '');
$h = fn($v) => htmlspecialchars((string) $v);
$label = fn($s) => $s === 'Draft' ? 'Awaiting applicant' : $s;
// Colour class for a status pill.
$tone = fn($s) => ['New' => 'st-new', 'Submitted' => 'st-new', 'Under Review' => 'st-review',
                   'Draft' => 'st-wait', 'Accepted' => 'st-ok', 'Rejected' => 'st-bad'][$s] ?? 'st-new';

// Chapter name for a queue row: from the slug, or from the answer on renewals
// (MemberModel::chapterOf handles both). Falls back to the raw slug if unknown.
$chapterLabel = function (array $r): string {
    $key = MemberModel::chapterOf($r);
    return ($key !== null ? (MemberModel::CHAPTER_NAMES[$key] ?? null) : null) ?? (string) $r['form_type'];
};
// Display name for a form slug in the filter dropdown. The submitted value stays the slug.
$slugLabel = function (string $slug): string {
    if (str_starts_with($slug, 'membership-renewal')) return 'Renewals';
    $key = substr($slug, strlen('membership-'));
    return MemberModel::CHAPTER_NAMES[$key] ?? $slug;
};
// Existing memberships, so "joining another chapter" can be told apart from "renewal" and "new".
$held = $model->heldPairs();

// Member IDs a person already holds: member rows grouped by ID, e.g. ['D48213977' => ['DCW Generic Community', 'Wiki Club AMU']].
$idGroups = function (array $mems): array {
    $g = [];
    foreach ($mems as $m) $g[$m['member_id']][] = MemberModel::CHAPTER_NAMES[$m['chapter']] ?? $m['chapter'];
    return $g;
};

$scopeLine = $scope !== null
    ? ($scope ? 'Your chapters: ' . implode(', ', array_map(fn($c) => MemberModel::CHAPTER_NAMES[$c] ?? $c, $scope)) : 'No chapters are assigned to you yet. Ask an owner.')
    : '';
$crumbs = $app
    ? [['Workspace', '/admin/dashboard'], ['Membership review', '/admin/membership-review'], [$app['applicant_name'] ?: $app['email']]]
    : [['Workspace', '/admin/dashboard'], ['Membership review']];

engage_header([
    'title'   => 'Membership review',
    'heading' => 'Membership review',
    'kicker'  => 'Organizer workspace',
    'lead'    => $scopeLine,
    'tools'   => '',
    'wide'    => true,
    'crumbs'  => $crumbs,
]);
?>
<style>
    /* Membership review only. Everything else comes from /assets/css/engage.css */
    .sect { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 22px 24px; margin: 0 0 22px; box-shadow: 0 16px 34px rgba(15,23,42,.12); }
    .sect select, .sect input[type=text] {
        padding: 8px 12px; background: #fff; color: var(--ink);
        border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 14px;
    }
    .sect select:focus, .sect input[type=text]:focus { outline: 2px solid var(--primary); outline-offset: -1px; border-color: transparent; }
    .btn-ghost.sm, .btn-solid.sm { width: auto; padding: 7px 16px; font-size: 13.5px; }

    .pill.st-new { --tone: var(--primary); }
    .pill.st-review { --tone: #6d28d9; }
    .pill.st-wait { --tone: #b45309; }
    .pill.st-ok { --tone: #047857; }
    .pill.st-bad { --tone: #b91c1c; }

    .action-banner ul { flex-basis: 100%; margin: 6px 0 0; padding-left: 18px; }
    .flash-link { display: block; flex-basis: 100%; margin-top: 8px; padding: 10px; background: rgba(0,0,0,.06); border-radius: 6px; font-size: 12px; word-break: break-all; }

    dl.answers { margin: 14px 0 0; }
    dl.answers dt { margin-top: 14px; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
    dl.answers dd { margin: 3px 0 0; white-space: pre-wrap; }
    .hist { margin: 10px 0 0; font-size: 14px; }

    .actrow { display: flex; flex-wrap: wrap; gap: 10px; }
    .actform { margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
    .actform .field { margin-bottom: 12px; }

    .idline { margin: 6px 0 0; font-size: 14.5px; }
    .idform { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 12px; }
    .idform input[type=text] { width: 190px; }
    .idform label.chk { display: flex; align-items: center; gap: 6px; font-size: 13.5px; margin: 0; }
    .idform .btn-ghost, .idform .btn-solid { width: auto; padding: 7px 16px; font-size: 13.5px; }
    .idlist { margin: 10px 0 0; padding-left: 18px; font-size: 14.5px; }
    .idlist li { margin: 3px 0; }
    .idwarn { margin: 12px 0 0; padding: 10px 12px; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px; font-size: 14px; color: #92400e; }
    .idbad { margin: 12px 0 0; padding: 10px 12px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; font-size: 14px; color: #991b1b; }

    .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 16px; }
    .bulkbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 14px; padding: 12px 14px; background: #fff; border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 4px 12px rgba(15,23,42,.08); }
    .bulkbar .count { min-width: 90px; font-size: 14px; font-weight: 700; color: var(--muted); }
    .bulkbar input[type=text] { flex: 1 1 240px; min-width: 200px; }
    .rowform { display: inline-flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0; }
    .rowform input[type=text] { width: 170px; padding: 6px 10px; font-size: 13px; }
    .rowform select { padding: 6px 8px; font-size: 13px; }
    .tbl th.pick, .tbl td.pick { width: 36px; padding-right: 0; }
    .tbl td.pick input, .tbl th.pick input { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }
    .tbl td.pick input:disabled { cursor: not-allowed; }

    /* Accepted members: a collapsible section. The summary line is always visible. */
    details.acc > summary {
        display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 6px 16px;
        cursor: pointer; list-style: none;
    }
    details.acc > summary::-webkit-details-marker { display: none; }
    details.acc > summary::before { content: '\25B8'; margin-right: 8px; color: var(--muted); }
    details.acc[open] > summary::before { content: '\25BE'; }
    details.acc > summary:focus-visible { outline: 3px solid #f59e0b; outline-offset: 4px; border-radius: 6px; }
    details.acc .acc-title { flex: 1 1 auto; font-size: 17px; font-weight: 800; }
    details.acc .acc-count { font-size: 14px; font-weight: 600; color: var(--muted); }
    details.acc[open] > summary { margin-bottom: 4px; }
</style>

<?php if ($notice): ?><div class="alert ok"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($manualLink): ?>
    <div class="action-banner">
        <span>The email was not sent automatically. Give the applicant this one-time link:</span>
        <code class="flash-link"><?= $h($manualLink) ?></code>
    </div>
<?php endif; ?>
<?php if ($bulk): ?>
    <div class="alert <?= $bulk['done'] ? 'ok' : 'error' ?>">
        <?= (int) $bulk['done'] ?> application<?= $bulk['done'] === 1 ? '' : 's' ?> <?= $h($bulkVerb[$bulk['act']] ?? 'updated') ?>.
        <?= $bulk['skipped'] ? count($bulk['skipped']) . ' skipped.' : '' ?>
    </div>
    <?php if ($bulk['skipped']): ?>
        <div class="action-banner"><strong>Skipped</strong><ul><?php foreach ($bulk['skipped'] as $s): ?><li><?= $h($s) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($bulk['links']): ?>
        <div class="action-banner"><strong>These emails were not sent automatically.</strong> Give each applicant their one-time link:
            <ul><?php foreach ($bulk['links'] as $l): ?><li><?= $h($l['who']) ?>: <code><?= $h($l['url']) ?></code></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= $h($error) ?></div><?php endif; ?>

<?php if ($app):
    $schema = json_decode($app['schema_json'], true) ?: [];
    $data = json_decode($app['form_data'] ?? '', true) ?: [];
    $open = in_array($app['status'], MemberModel::OPEN, true);
    $chapterKey = MemberModel::chapterOf($app);

    // Member IDs this person already has, for every reviewer and coordinator (not only those who can change IDs).
    $mine = $model->membershipsForEmail((string) $app['email']);
    $mineIds = $idGroups($mine);
    $statedId = MemberModel::statedMemberId($app);
    $outcome = $open ? $model->idOutcome($app) : null; ?>
    <section class="sect">
        <div class="qhead">
            <h3><?= $h($app['applicant_name']) ?></h3>
            <span class="pill <?= $tone($app['status']) ?>"><?= $h($label($app['status'])) ?></span>
        </div>
        <p class="qmeta">
            <?= $h($app['email']) ?> &middot;
            <?= $h(MemberModel::kindLabel($app, $held)) ?> &middot;
            <?= $h($chapterLabel($app)) ?> &middot;
            <?= $h($app['tracking_id']) ?>
        </p>
        <dl class="answers">
        <?php foreach ($schema['fields'] ?? [] as $f): $v = $data[$f['name']] ?? ''; if (is_array($v)) $v = implode(', ', $v); ?>
            <dt><?= MiniWikiText::inline($h($f['label'] ?? $f['name'])) ?></dt>
            <dd><?= $v === '' ? '&mdash;' : (($f['type'] ?? '') === 'file' ? 'File uploaded: ' . $h($v) : $h($v)) ?></dd>
        <?php endforeach; ?>
        </dl>
    </section>

    <section class="sect">
        <h3 style="margin:0 0 4px; font-size:17px; font-weight:800;">Member ID</h3>
        <?php if ($mineIds): ?>
            <p class="idline">Already holds, under <?= $h($app['email']) ?>:</p>
            <ul class="idlist">
            <?php foreach ($mine as $m): ?>
                <li><code><?= $h($m['member_id']) ?></code> &middot; <?= $h(MemberModel::CHAPTER_NAMES[$m['chapter']] ?? $m['chapter']) ?>
                    &middot; <?= $h($m['status']) ?>, valid until <?= $h(MemberAuthModel::formatIst($m['expires_at'], 'j M Y')) ?></li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="idline">No membership is held under this email yet.</p>
        <?php endif; ?>

        <?php if ($statedId !== null): ?>
            <p class="idline">Member ID given on this application: <code><?= $h($statedId) ?></code>
                <?= isset($mineIds[$statedId]) ? '(matches their email)' : '(does not match any membership under this email)' ?></p>
        <?php endif; ?>

        <?php if (count($mineIds) > 1): ?>
            <div class="idwarn"><strong>More than one Member ID.</strong> This person holds <?= count($mineIds) ?> different IDs
                (<?= $h(implode(', ', array_keys($mineIds))) ?>). They are meant to have just one.</div>
        <?php endif; ?>

        <?php if ($outcome !== null): ?>
            <?php if ($outcome['error']): ?>
                <div class="idbad"><strong>Approval would fail:</strong> <?= $h($outcome['error']) ?></div>
            <?php elseif ($outcome['id'] !== null): ?>
                <p class="idline"><strong>On approval:</strong> keeps <code><?= $h($outcome['id']) ?></code>. No new ID is made.</p>
            <?php else: ?>
                <p class="idline"><strong>On approval:</strong> a new Member ID is generated.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($hist = $model->history((int) $app['id'])): ?>
    <section class="sect">
        <h3 style="margin:0 0 4px; font-size:17px; font-weight:800;">History</h3>
        <?php foreach ($hist as $e): ?>
            <p class="hist"><?= $h(str_replace('_', ' ', $e['decision'])) ?> &middot;
               <?= $h($e['decided_by']) ?> &middot; <?= $h($e['decided_at']) ?>
               <?= $e['reason'] ? '<br><em>' . nl2br($h($e['reason'])) . '</em>' : '' ?></p>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php
    // Member ID sharing: reviewers only, and only for club memberships (the Generic Community is the anchor).
    if ($canLinkIds && $chapterKey !== null && $chapterKey !== 'generic'):
        $link = $model->linkFor((int) $app['id']);
        $memberRow = $app['status'] === 'Accepted' ? $model->memberRowFor($app) : null;
        if ($open || $memberRow): ?>
    <section class="sect">
        <h3 style="margin:0 0 4px; font-size:17px; font-weight:800;">Change Member ID</h3>
        <?php if ($memberRow): ?>
            <p class="idline">Current Member ID for this club: <code><?= $h($memberRow['member_id']) ?></code>.
                To put this member on their DCW Generic Community ID instead, enter it below.</p>
        <?php elseif ($link): ?>
            <p class="idline">Linked to <code><?= $h($link['member_id']) ?></code> by <?= $h($link['linked_by']) ?>.
                On approval this member keeps that ID and no new one is made.</p>
        <?php else: ?>
            <p class="idline">If this person already holds a membership under this email, that ID is reused automatically.
                If they used a different email, enter their DCW Generic Community Member ID here.</p>
        <?php endif; ?>

        <form method="POST" class="idform"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <input type="text" name="link_member_id" maxlength="<?= MemberModel::MEMBER_ID_DIGITS + 1 ?>" autocomplete="off"
                   placeholder="e.g. D48213977" value="<?= $h($link['member_id'] ?? '') ?>" required>
            <label class="chk"><input type="checkbox" name="link_confirm" value="1"> Link even if the email differs</label>
            <?php if ($memberRow): ?>
                <button name="action" value="relink_id" class="btn-ghost"
                        onclick="return confirm('Change this member\'s ID? The email they already received shows the old one.');">Change Member ID</button>
            <?php else: ?>
                <button name="action" value="link_id" class="btn-ghost"><?= $link ? 'Update link' : 'Link Member ID' ?></button>
                <?php if ($link): ?><button name="action" value="unlink_id" class="btn-ghost" formnovalidate>Remove link</button><?php endif; ?>
            <?php endif; ?>
        </form>
    </section>
    <?php endif; endif; ?>

    <?php if ($open): ?>
    <section class="sect">
        <form method="POST" class="actrow"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <button name="action" value="approve" class="btn-ok">Approve</button>
            <button name="action" value="review" class="btn-ghost">Mark under review</button>
        </form>
        <form method="POST" class="actform"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <div class="field">
                <label>Ask for more information (sent to the applicant with a link to edit and resubmit)</label>
                <textarea name="message" rows="3" placeholder="e.g. Please upload a clearer photo of your student ID."></textarea>
            </div>
            <button name="action" value="info" class="btn-ghost">Send back for more information</button>
        </form>
        <form method="POST" class="actform"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <div class="field">
                <label>Reason for rejecting (sent to the applicant)</label>
                <textarea name="reason" rows="3"></textarea>
            </div>
            <button name="action" value="reject" class="btn-bad">Reject</button>
        </form>
    </section>
    <?php elseif ($model->awaitingApplicant($app)): ?>
    <section class="sect">
        <p style="margin:0 0 12px;">Waiting for the applicant to update and resubmit. It returns to the queue when they do.</p>
        <form method="POST"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <button name="action" value="resend" class="btn-ghost">Send the link again</button>
        </form>
    </section>
    <?php else: ?>
        <div class="empty-note">This application has been decided and is locked.</div>
    <?php endif; ?>

<?php else:
    $rows = $model->listApplications($fSlug, $fStatus, $scope);
    // Accepted applications live in their own section below, with the Member ID each person was assigned.
    $queue = array_values(array_filter($rows, fn($r) => $r['status'] !== 'Accepted'));
    $accepted = array_values(array_filter($rows, fn($r) => $r['status'] === 'Accepted'));
    usort($accepted, fn($a, $b) => strcmp((string) ($b['approved_at'] ?? $b['created_at']), (string) ($a['approved_at'] ?? $a['created_at'])));
    $infoMap = $model->memberInfoMap();
    $byEmail = $model->membershipsByEmail();   // one query for the whole queue
    $showQueue = $fStatus !== 'Accepted';
    $showAccepted = $fStatus === '' || $fStatus === 'Accepted';

    // ONE ROW PER PERSON. People are counted by Member ID: one ID is one person, even if it covers
    // several clubs, and their memberships are listed together on that row. A row with no ID on file
    // yet falls back to its email, so nobody is dropped. $accepted is newest first, so the first
    // row seen for a person is their latest. A club that was accepted more than once (renewals)
    // is listed once, linking to its latest application.
    $acceptedGroups = [];
    foreach ($accepted as $r) {
        $chap = MemberModel::chapterOf($r) ?? '';
        $inf  = $infoMap[strtolower((string) $r['email']) . '|' . $chap] ?? null;
        $mid  = trim((string) ($inf['member_id'] ?? ''));
        $key  = $mid !== '' ? $mid : 'email:' . strtolower((string) $r['email']);
        $dedupe = $chap !== '' ? $chap : (string) $r['form_type'];

        if (!isset($acceptedGroups[$key])) {
            $acceptedGroups[$key] = ['mid' => $mid, 'latest' => $r, 'memberships' => [], 'expires' => null];
        }
        $g =& $acceptedGroups[$key];
        if ($g['mid'] === '' && $mid !== '') $g['mid'] = $mid;
        if (!isset($g['memberships'][$dedupe])) {
            $exp = !empty($inf['expires_at']) ? (string) $inf['expires_at'] : null;
            $g['memberships'][$dedupe] = ['id' => (int) $r['id'], 'label' => $chapterLabel($r), 'expires' => $exp];
            if ($exp !== null && ($g['expires'] === null || $exp > $g['expires'])) $g['expires'] = $exp;
        }
        unset($g);
    }
    $acceptedPeopleCount = count($acceptedGroups);
    $acceptedMembershipCount = 0;
    foreach ($acceptedGroups as $g) $acceptedMembershipCount += count($g['memberships']);
    ?>
    <section class="sect">
        <form method="GET" class="filters">
            <select name="form"><option value="">All memberships</option>
                <?php foreach ($model->formSlugs() as $s): ?>
                    <option value="<?= $h($s) ?>" <?= $s === $fSlug ? 'selected' : '' ?>><?= $h($slugLabel($s)) ?></option>
                <?php endforeach; ?></select>
            <select name="status"><option value="">All statuses</option>
                <?php foreach (['New','Submitted','Under Review','Draft','Accepted','Rejected'] as $s): ?>
                    <option value="<?= $h($s) ?>" <?= $s === $fStatus ? 'selected' : '' ?>><?= $h($label($s)) ?></option>
                <?php endforeach; ?></select>
            <button class="btn-ghost sm">Filter</button>
        </form>

        <?php /* Both forms post back to this same URL, so the active filters are kept. */ ?>
        <form method="POST" id="bulkForm" class="bulkbar" style="display:none"><?= CSRF::getInputField() ?>
            <input type="hidden" name="action" value="bulk">
            <div id="bulkIdsContainer"></div>
            <span class="count" id="bulkCount"></span>
            <select name="bulk_action" id="bulkAction" required>
                <option value="">Set status to&hellip;</option>
                <option value="review">Under Review</option>
                <option value="approve">Accepted</option>
                <option value="info">Needs information</option>
                <option value="reject">Rejected</option>
            </select>
            <input type="text" name="note" id="bulkNote"
                placeholder="Note to applicants (required for Needs information / Rejected)">
            <button type="submit" id="bulkApply" class="btn-solid sm">Apply to selected</button>
        </form>

        <?php if ($showQueue): ?>
        <div class="tbl-wrap">
        <table class="tbl"><thead><tr>
            <th class="pick"><input type="checkbox" id="pickAll" aria-label="Select all"></th>
            <th>Applicant</th><th>Membership</th><th>Member ID</th><th>Status</th><th>Submitted</th><th>Update</th></tr></thead><tbody>
        <?php foreach ($queue as $r):
            $rowOpen = in_array($r['status'], MemberModel::OPEN, true);
            $kind = MemberModel::kindLabel($r, $held);
            $rowIds = $idGroups($byEmail[strtolower((string) $r['email'])] ?? []);
            $rowStated = MemberModel::statedMemberId($r); ?>
            <tr>
                <td class="pick"><input type="checkbox" class="pick-row" value="<?= (int) $r['id'] ?>"
                    <?= $rowOpen ? '' : 'disabled title="Already decided or waiting on the applicant"' ?>></td>
                <td><a class="dl" href="?id=<?= (int) $r['id'] ?>"><?= $h($r['applicant_name'] ?: $r['email']) ?></a>
                    <span class="sub"><?= $h($r['email']) ?></span></td>
                <td><?= $h($chapterLabel($r)) ?>
                    <?php if ($kind !== 'New applicant'): ?><span class="sub"><?= $h($kind) ?></span><?php endif; ?></td>
                <td>
                <?php if ($rowIds): foreach ($rowIds as $mid => $chs): ?>
                    <code><?= $h($mid) ?></code> <span class="sub"><?= $h(implode(', ', $chs)) ?></span>
                <?php endforeach; else: ?><span style="color:#94a3b8">&mdash;</span><?php endif; ?>
                <?php if ($rowStated !== null && !isset($rowIds[$rowStated])): ?>
                    <span class="sub">Entered: <code><?= $h($rowStated) ?></code> (no match)</span>
                <?php endif; ?>
                <?php if (count($rowIds) > 1): ?><span class="sub" style="color:#b45309">More than one ID</span><?php endif; ?>
                </td>
                <td><span class="pill <?= $tone($r['status']) ?>"><?= $h($label($r['status'])) ?></span></td>
                <td><?= $h($r['created_at']) ?></td>
                <td>
                <?php if ($rowOpen): ?>
                    <form method="POST" class="rowform"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="action" value="bulk">
                        <input type="hidden" name="ids[]" value="<?= (int) $r['id'] ?>">
                        <select name="bulk_action" aria-label="Set status">
                            <option value="review">Under Review</option>
                            <option value="approve">Accepted</option>
                            <option value="info">Needs information</option>
                            <option value="reject">Rejected</option>
                        </select>
                        <input type="text" name="note" placeholder="Note to applicant">
                        <button type="submit" class="btn-ghost sm">Apply</button>
                    </form>
                <?php else: ?><span style="color:#94a3b8">&mdash;</span><?php endif; ?>
                </td></tr>
        <?php endforeach; if (!$queue): ?><tr><td colspan="7">Nothing to review here.</td></tr><?php endif; ?>
        </tbody></table>
        </div>
        <?php endif; ?>
    </section>

    <?php if ($showAccepted): ?>
    <details class="sect acc" id="acceptedSect"<?= $fStatus === 'Accepted' ? ' open' : '' ?>>
        <summary>
            <span class="acc-title">Accepted members</span>
            <span class="acc-count"><?= $acceptedPeopleCount ?> <?= $acceptedPeopleCount === 1 ? 'person' : 'people' ?>
                &middot; <?= $acceptedMembershipCount ?> membership<?= $acceptedMembershipCount === 1 ? '' : 's' ?></span>
        </summary>
        <p class="qmeta" style="margin:6px 0 14px;">One row per person, counted by Member ID: one ID is one person even when it covers several clubs,
            and all their memberships are listed together (click one to open that application). Approved by and Accepted show the latest approval;
            Valid until shows the latest expiry (hover for each membership).</p>
        <?php if (!$acceptedGroups): ?>
            <div class="empty-note">No accepted memberships yet.</div>
        <?php else: ?>
        <div class="tbl-wrap">
        <table class="tbl"><thead><tr>
            <th>Member</th><th>Membership</th><th>Member ID</th><th>Approved by</th><th>Accepted</th><th>Valid until</th></tr></thead><tbody>
        <?php foreach ($acceptedGroups as $g):
            $latest = $g['latest'];
            $mships = array_values($g['memberships']);
            $expTitle = [];
            foreach ($mships as $m) {
                $expTitle[] = $m['label'] . ': ' . ($m['expires'] ? MemberAuthModel::formatIst($m['expires'], 'j M Y') : 'no date'); } ?>
            <tr>
                <td><a class="dl" href="?id=<?= (int) $latest['id'] ?>"><?= $h($latest['applicant_name'] ?: $latest['email']) ?></a>
                    <span class="sub"><?= $h($latest['email']) ?></span></td>
                <td><?php foreach ($mships as $i => $m): ?><?= $i ? ', ' : '' ?><a class="dl" href="?id=<?= (int) $m['id'] ?>"><?= $h($m['label']) ?></a><?php endforeach; ?></td>
                <td><?= $g['mid'] !== '' ? '<code>' . $h($g['mid']) . '</code>' : '<span style="color:#94a3b8">&mdash;</span>' ?></td>
                <td><?= !empty($latest['approved_by']) ? $h($latest['approved_by']) : '<span style="color:#94a3b8">&mdash;</span>' ?></td>
                <td><?= !empty($latest['approved_at']) ? $h(MemberAuthModel::formatIst($latest['approved_at'])) : '<span style="color:#94a3b8">&mdash;</span>' ?></td>
                <td title="<?= $h(implode('; ', $expTitle)) ?>"><?= !empty($g['expires']) ? $h(MemberAuthModel::formatIst($g['expires'], 'j M Y')) : '<span style="color:#94a3b8">&mdash;</span>' ?>
                    <?php if (count($mships) > 1): ?><span class="sub">latest of <?= count($mships) ?></span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
        <?php endif; ?>
    </details>
    <?php endif; ?>

    <script>
    (function () {
        var bulkForm = document.getElementById('bulkForm');
        var all = document.getElementById('pickAll') || { addEventListener: function () {}, style: {} };
        var rows = Array.prototype.slice.call(document.querySelectorAll('.pick-row:not(:disabled)'));
        var count = document.getElementById('bulkCount');
        var action = document.getElementById('bulkAction');
        var note = document.getElementById('bulkNote');
        var ids = document.getElementById('bulkIdsContainer');

        function picked() { return rows.filter(function (r) { return r.checked; }); }

        // The bar only appears once something is ticked.
        function sync() {
            var n = picked().length;
            bulkForm.style.display = n > 0 ? 'flex' : 'none';
            count.textContent = n + ' selected';
            all.checked = rows.length > 0 && n === rows.length;
            all.indeterminate = n > 0 && n < rows.length;
            all.disabled = rows.length === 0;
        }

        // Select all only ticks rows that can actually be acted on.
        all.addEventListener('change', function () {
            rows.forEach(function (r) { r.checked = all.checked; });
            sync();
        });
        rows.forEach(function (r) { r.addEventListener('change', sync); });

        // Returns false (and says why) if the action needs a note and has none.
        function ok(act, text) {
            if ((act === 'info' || act === 'reject') && text.trim() === '') {
                alert(act === 'reject'
                    ? 'Give a reason so the applicants know what to do next.'
                    : 'Say what you need from the applicants.');
                return false;
            }
            return true;
        }

        function confirmText(act, n) {
            var plural = n + ' application' + (n === 1 ? '' : 's');
            return {
                approve: 'Accept ' + plural + '? Each applicant is emailed and this cannot be undone.',
                reject: 'Reject ' + plural + '? Each applicant is emailed the reason and this cannot be undone.',
                info: 'Send ' + plural + ' back for more information? Each applicant is emailed.'
            }[act];
        }

        bulkForm.addEventListener('submit', function (e) {
            var chosen = picked();
            if (chosen.length === 0) { e.preventDefault(); return; }
            if (!action.value) { e.preventDefault(); alert('Choose a status first.'); return; }
            if (!ok(action.value, note.value)) { e.preventDefault(); return; }
            var warn = confirmText(action.value, chosen.length);
            if (warn && !confirm(warn)) { e.preventDefault(); return; }

            ids.innerHTML = '';
            chosen.forEach(function (cb) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'ids[]'; input.value = cb.value;
                ids.appendChild(input);
            });
            var apply = document.getElementById('bulkApply');
            apply.disabled = true; apply.textContent = 'Working…';
        });

        // Per-row Apply uses the same checks.
        document.querySelectorAll('.rowform').forEach(function (f) {
            f.addEventListener('submit', function (e) {
                var act = f.querySelector('select').value;
                var text = f.querySelector('input[type=text]').value;
                if (!ok(act, text)) { e.preventDefault(); return; }
                var warn = confirmText(act, 1);
                if (warn && !confirm(warn)) { e.preventDefault(); }
            });
        });

        sync();
    })();
    </script>
<?php endif; ?>
<?php engage_footer(); ?>
