<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../includes/membership_mailer.php';

requireRole(['membership_coordinator', 'membership_reviewer', 'organizer', 'owner']);

$model = new MemberModel();
// Pass the FULL role list: an account can hold several roles, and Auth::role() is only the primary one.
$scope = $model->scopeFor(Auth::roles(), (string) Auth::email());   // null = every chapter
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
                    [$memberId, $exp] = $model->approve($row, $by);
                    $mail = ['approved', $memberId, $exp];
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
                        MembershipMailer::sendDecisionFor($row, $mail[0], $mail[1], $mail[2]);
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
            [$memberId, $exp] = $model->approve($app, $by);
            $mail = ['approved', $memberId, $exp];
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
        } else { throw new Exception('Unknown action.'); }

        app_log("Membership $act: application #{$app['id']} ({$app['tracking_id']}) by $by");
        // Email failure must never undo a decision that is already saved.
        if (!empty($mail)) {
            try {
                // Also emails the new member a "set your password" link on approval.
                MembershipMailer::sendDecisionFor($app, $mail[0], $mail[1], $mail[2]);
            } catch (Throwable $e) { app_log("Membership email failed for application #{$app['id']}: " . $e->getMessage()); }
        }
        header('Location: /admin/membership-review?id=' . $app['id'] . '&done=' . urlencode($act));
        exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
    $app = $model->getApplication($id, $scope);   // show fresh state after an error
}
$doneText = ['approve' => 'Approved.', 'reject' => 'Rejected.', 'review' => 'Marked under review.',
             'info' => 'Sent back to the applicant.', 'resend' => 'Link sent again.'];
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

$scopeLine = $scope !== null
    ? ($scope ? 'Your chapters: ' . implode(', ', $scope) : 'No chapters are assigned to you yet. Ask an owner.')
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
    $open = in_array($app['status'], MemberModel::OPEN, true); ?>
    <section class="sect">
        <div class="qhead">
            <h3><?= $h($app['applicant_name']) ?></h3>
            <span class="pill <?= $tone($app['status']) ?>"><?= $h($label($app['status'])) ?></span>
        </div>
        <p class="qmeta">
            <?= $h($app['email']) ?> &middot;
            <?= MemberModel::isRenewal($app) ? 'Renewal' : 'New applicant' ?> &middot;
            <?= $h($schema['title'] ?? $app['form_type']) ?> &middot;
            <?= $h($app['tracking_id']) ?>
        </p>
        <dl class="answers">
        <?php foreach ($schema['fields'] ?? [] as $f): $v = $data[$f['name']] ?? ''; if (is_array($v)) $v = implode(', ', $v); ?>
            <dt><?= MiniWikiText::inline($h($f['label'] ?? $f['name'])) ?></dt>
            <dd><?= $v === '' ? '&mdash;' : (($f['type'] ?? '') === 'file' ? 'File uploaded: ' . $h($v) : $h($v)) ?></dd>
        <?php endforeach; ?>
        </dl>
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

<?php else: $rows = $model->listApplications($fSlug, $fStatus, $scope); ?>
    <section class="sect">
        <form method="GET" class="filters">
            <select name="form"><option value="">All memberships</option>
                <?php foreach ($model->formSlugs() as $s): ?>
                    <option value="<?= $h($s) ?>" <?= $s === $fSlug ? 'selected' : '' ?>><?= $h($s) ?></option>
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

        <div class="tbl-wrap">
        <table class="tbl"><thead><tr>
            <th class="pick"><input type="checkbox" id="pickAll" aria-label="Select all"></th>
            <th>Applicant</th><th>Membership</th><th>Status</th><th>Submitted</th><th>Update</th></tr></thead><tbody>
        <?php foreach ($rows as $r):
            $rowOpen = in_array($r['status'], MemberModel::OPEN, true); ?>
            <tr>
                <td class="pick"><input type="checkbox" class="pick-row" value="<?= (int) $r['id'] ?>"
                    <?= $rowOpen ? '' : 'disabled title="Already decided or waiting on the applicant"' ?>></td>
                <td><a class="dl" href="?id=<?= (int) $r['id'] ?>"><?= $h($r['applicant_name'] ?: $r['email']) ?></a>
                    <span class="sub"><?= $h($r['email']) ?></span></td>
                <td><?= $h($r['form_type']) ?></td>
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
        <?php endforeach; if (!$rows): ?><tr><td colspan="6">No applications yet.</td></tr><?php endif; ?>
        </tbody></table>
        </div>
    </section>

    <script>
    (function () {
        var bulkForm = document.getElementById('bulkForm');
        var all = document.getElementById('pickAll');
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
