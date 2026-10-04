<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
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
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require __DIR__ . '/../../includes/favicon.php'; ?>
<title>Membership review - DCW Engage</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
body{font-family:Inter,sans-serif;background:#f8fafc;color:#1e293b;margin:0;padding:32px}
.wrap{max-width:960px;margin:auto} h1{color:#106b9a;margin:0 0 6px} a{color:#106b9a}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e2e8f0}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid #e2e8f0;font-size:14px}
th.pick,td.pick{width:36px;padding-right:0}
td.pick input,th.pick input{width:16px;height:16px;cursor:pointer;accent-color:#106b9a}
td.pick input:disabled{cursor:not-allowed}
.box{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin:16px 0}
dt{font-weight:600;margin-top:12px;font-size:13px;color:#475569} dd{margin:2px 0 0;white-space:pre-wrap}
.ok{background:#ecfdf5;border:1px solid #6ee7b7;padding:10px 14px;border-radius:6px;margin:12px 0}
.bad{background:#fef2f2;border:1px solid #f87171;padding:10px 14px;border-radius:6px;margin:12px 0}
.warn{background:#fffbeb;border:1px solid #fcd34d;padding:10px 14px;border-radius:6px;margin:12px 0}
.warn ul{margin:6px 0 0;padding-left:18px}
button{padding:9px 16px;border-radius:6px;border:0;font:600 14px Inter,sans-serif;cursor:pointer;color:#fff;background:#106b9a}
button.no{background:#b91c1c} button.gray{background:#fff;color:#475569;border:1px solid #cbd5e1}
button:disabled{opacity:.5;cursor:not-allowed}
select,textarea,input[type=text]{font:inherit;padding:7px;border:1px solid #cbd5e1;border-radius:4px}
textarea{width:100%;box-sizing:border-box}
.bulkbar{position:sticky;top:0;z-index:5;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin:0 0 10px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;box-shadow:0 2px 6px rgba(0,0,0,.04)}
.bulkbar .count{font-size:14px;font-weight:600;color:#475569;min-width:90px}
.bulkbar input[type=text]{flex:1 1 240px;min-width:200px}
.rowform{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap}
.rowform input[type=text]{width:170px;padding:5px 8px;font-size:13px}
.rowform select{padding:5px;font-size:13px}
.rowform button{padding:6px 12px;font-size:13px}
</style></head><body><div class="wrap">
<p><a href="/admin/dashboard">&larr; Workspace</a><?= $app ? ' &middot; <a href="/admin/membership-review">Queue</a>' : '' ?></p>
<h1>Membership review</h1>
<?php if ($scope !== null): ?><p style="color:#64748b;margin:0"><?= $scope ? 'Your chapters: ' . $h(implode(', ', $scope)) : 'No chapters are assigned to you yet. Ask an owner.' ?></p><?php endif; ?>
<?php if ($notice): ?><div class="ok"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($manualLink): ?><div class="ok">The email was not sent automatically. Give the applicant this one-time link:<br><code><?= $h($manualLink) ?></code></div><?php endif; ?>
<?php if ($bulk): ?>
    <div class="<?= $bulk['done'] ? 'ok' : 'bad' ?>">
        <?= (int) $bulk['done'] ?> application<?= $bulk['done'] === 1 ? '' : 's' ?> <?= $h($bulkVerb[$bulk['act']] ?? 'updated') ?>.
        <?= $bulk['skipped'] ? count($bulk['skipped']) . ' skipped.' : '' ?>
    </div>
    <?php if ($bulk['skipped']): ?>
        <div class="warn"><strong>Skipped</strong><ul><?php foreach ($bulk['skipped'] as $s): ?><li><?= $h($s) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($bulk['links']): ?>
        <div class="warn"><strong>These emails were not sent automatically.</strong> Give each applicant their one-time link:
            <ul><?php foreach ($bulk['links'] as $l): ?><li><?= $h($l['who']) ?>: <code><?= $h($l['url']) ?></code></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php if ($error): ?><div class="bad"><?= $h($error) ?></div><?php endif; ?>

<?php if ($app):
    $schema = json_decode($app['schema_json'], true) ?: [];
    $data = json_decode($app['form_data'] ?? '', true) ?: [];
    $open = in_array($app['status'], MemberModel::OPEN, true); ?>
    <div class="box">
        <strong><?= $h($app['applicant_name']) ?></strong> &middot; <?= $h($app['email']) ?><br>
        <?= MemberModel::isRenewal($app) ? 'Renewal' : 'New applicant' ?> &middot;
        <?= $h($schema['title'] ?? $app['form_type']) ?> &middot;
        <?= $h($app['tracking_id']) ?> &middot; Status: <strong><?= $h($label($app['status'])) ?></strong>
        <dl>
        <?php foreach ($schema['fields'] ?? [] as $f): $v = $data[$f['name']] ?? ''; if (is_array($v)) $v = implode(', ', $v); ?>
            <dt><?= MiniWikiText::inline($h($f['label'] ?? $f['name'])) ?></dt>
            <dd><?= $v === '' ? '&mdash;' : (($f['type'] ?? '') === 'file' ? 'File uploaded: ' . $h($v) : $h($v)) ?></dd>
        <?php endforeach; ?>
        </dl>
    </div>
    <?php if ($hist = $model->history((int) $app['id'])): ?>
    <div class="box"><strong>History</strong>
        <?php foreach ($hist as $e): ?>
            <p style="margin:8px 0 0;font-size:14px"><?= $h(str_replace('_', ' ', $e['decision'])) ?> &middot;
               <?= $h($e['decided_by']) ?> &middot; <?= $h($e['decided_at']) ?>
               <?= $e['reason'] ? '<br><em>' . nl2br($h($e['reason'])) . '</em>' : '' ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($open): ?>
    <div class="box">
        <form method="POST" style="display:inline"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <button name="action" value="approve">Approve</button>
            <button name="action" value="review" class="gray">Mark under review</button>
        </form>
        <form method="POST" style="margin-top:14px"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <label>Ask for more information (sent to the applicant with a link to edit and resubmit)</label>
            <textarea name="message" rows="3" placeholder="e.g. Please upload a clearer photo of your student ID."></textarea><br><br>
            <button name="action" value="info" class="gray">Send back for more information</button>
        </form>
        <form method="POST" style="margin-top:14px"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <label>Reason for rejecting (sent to the applicant)</label>
            <textarea name="reason" rows="3"></textarea><br><br>
            <button name="action" value="reject" class="no">Reject</button>
        </form>
    </div>
    <?php elseif ($model->awaitingApplicant($app)): ?>
    <div class="box">Waiting for the applicant to update and resubmit. It returns to the queue when they do.
        <form method="POST" style="margin-top:10px"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <button name="action" value="resend" class="gray">Send the link again</button>
        </form></div>
    <?php else: ?><p>This application has been decided and is locked.</p><?php endif; ?>

<?php else: $rows = $model->listApplications($fSlug, $fStatus, $scope); ?>
    <form method="GET" style="margin:14px 0">
        <select name="form"><option value="">All memberships</option>
            <?php foreach ($model->formSlugs() as $s): ?>
                <option value="<?= $h($s) ?>" <?= $s === $fSlug ? 'selected' : '' ?>><?= $h($s) ?></option>
            <?php endforeach; ?></select>
        <select name="status"><option value="">All statuses</option>
            <?php foreach (['New','Submitted','Under Review','Draft','Accepted','Rejected'] as $s): ?>
                <option value="<?= $h($s) ?>" <?= $s === $fStatus ? 'selected' : '' ?>><?= $h($label($s)) ?></option>
            <?php endforeach; ?></select>
        <button class="gray">Filter</button>
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
        <button type="submit" id="bulkApply">Apply to selected</button>
    </form>

    <table><tr>
        <th class="pick"><input type="checkbox" id="pickAll" aria-label="Select all"></th>
        <th>Applicant</th><th>Membership</th><th>Status</th><th>Submitted</th><th>Update</th></tr>
    <?php foreach ($rows as $r):
        $rowOpen = in_array($r['status'], MemberModel::OPEN, true); ?>
        <tr>
            <td class="pick"><input type="checkbox" class="pick-row" value="<?= (int) $r['id'] ?>"
                <?= $rowOpen ? '' : 'disabled title="Already decided or waiting on the applicant"' ?>></td>
            <td><a href="?id=<?= (int) $r['id'] ?>"><?= $h($r['applicant_name'] ?: $r['email']) ?></a><br>
            <small><?= $h($r['email']) ?></small></td>
            <td><?= $h($r['form_type']) ?></td><td><?= $h($label($r['status'])) ?></td><td><?= $h($r['created_at']) ?></td>
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
                    <button type="submit" class="gray">Apply</button>
                </form>
            <?php else: ?><span style="color:#94a3b8">&mdash;</span><?php endif; ?>
            </td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6">No applications yet.</td></tr><?php endif; ?>
    </table>

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
</div></body></html>
