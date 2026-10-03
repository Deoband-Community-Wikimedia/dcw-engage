<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../includes/membership_mailer.php';

requireRole(['membership_coordinator', 'membership_reviewer', 'organizer', 'owner']);

$model = new MemberModel();
$scope = $model->scopeFor((string) Auth::role(), (string) Auth::email());   // null = every chapter
$notice = ''; $error = '';
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$app = $id ? $model->getApplication($id, $scope) : false;
if ($id && !$app) { http_response_code(404); die('Application not found.'); }

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
                $sent = (bool) MembershipMailer::sendInfoRequest($app['email'], $app['applicant_name'], $msg, $token);
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
                MembershipMailer::sendDecision($app['email'], $app['applicant_name'], $mail[0], $mail[1], $mail[2]);
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
.box{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin:16px 0}
dt{font-weight:600;margin-top:12px;font-size:13px;color:#475569} dd{margin:2px 0 0;white-space:pre-wrap}
.ok{background:#ecfdf5;border:1px solid #6ee7b7;padding:10px 14px;border-radius:6px;margin:12px 0}
.bad{background:#fef2f2;border:1px solid #f87171;padding:10px 14px;border-radius:6px;margin:12px 0}
button{padding:9px 16px;border-radius:6px;border:0;font:600 14px Inter,sans-serif;cursor:pointer;color:#fff;background:#106b9a}
button.no{background:#b91c1c} button.gray{background:#fff;color:#475569;border:1px solid #cbd5e1}
select,textarea{font:inherit;padding:7px;border:1px solid #cbd5e1;border-radius:4px}
textarea{width:100%;box-sizing:border-box}
</style></head><body><div class="wrap">
<p><a href="/admin/dashboard">&larr; Workspace</a><?= $app ? ' &middot; <a href="/admin/membership-review">Queue</a>' : '' ?></p>
<h1>Membership review</h1>
<?php if ($scope !== null): ?><p style="color:#64748b;margin:0"><?= $scope ? 'Your chapters: ' . $h(implode(', ', $scope)) : 'No chapters are assigned to you yet. Ask an owner.' ?></p><?php endif; ?>
<?php if ($notice): ?><div class="ok"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($manualLink): ?><div class="ok">The email was not sent automatically. Give the applicant this one-time link:<br><code><?= $h($manualLink) ?></code></div><?php endif; ?>
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
    <table><tr><th>Applicant</th><th>Membership</th><th>Status</th><th>Submitted</th></tr>
    <?php foreach ($rows as $r): ?>
        <tr><td><a href="?id=<?= (int) $r['id'] ?>"><?= $h($r['applicant_name'] ?: $r['email']) ?></a><br>
            <small><?= $h($r['email']) ?></small></td>
            <td><?= $h($r['form_type']) ?></td><td><?= $h($label($r['status'])) ?></td><td><?= $h($r['created_at']) ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="4">No applications yet.</td></tr><?php endif; ?>
    </table>
<?php endif; ?>
</div></body></html>
