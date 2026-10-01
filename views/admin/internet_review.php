<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../models/InternetSupportModel.php';

// Support reviewers decide whether a request is reasonable. Owners are
// trusted to do the same. This page never selects the phone number (see
// InternetSupportModel::listForReview()); finance is the only role that sees it.
requireRole(['support_reviewer', 'owner']);

$model = new InternetSupportModel();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    // Same double-submit guard as the finance queue.
    if (!CSRF::consumeSubmitToken($_POST['submit_token'] ?? '')) {
        header('Location: /admin/internet-review');
        exit;
    }

    $requestId = (int) ($_POST['request_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $decision = $_POST['decision'] ?? '';

    if ($decision === 'approve') {
        if ($model->approve($requestId, Auth::email())) {
            $info = $model->getForNotification($requestId);
            AuditLog::record('internet.approved', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id']);
            Mailer::sendInternetStatusUpdate($info['email'], $info['applicant_name'], $info['tracking_id'], 'Approved for Support');
            $message = "Request {$info['tracking_id']} approved and passed to finance.";
        } else {
            $error = "That request was already handled by someone else.";
        }
    } elseif ($decision === 'reject') {
        if ($notes === '') {
            // The applicant sees this note, so a bare rejection isn't allowed.
            $error = "Add a note explaining the rejection. The applicant will see it.";
        } elseif ($model->reject($requestId, Auth::email(), $notes)) {
            $info = $model->getForNotification($requestId);
            AuditLog::record('internet.rejected', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ' | ' . $notes);
            Mailer::sendInternetStatusUpdate($info['email'], $info['applicant_name'], $info['tracking_id'], 'Rejected', $notes);
            $message = "Request {$info['tracking_id']} rejected. The applicant has been emailed.";
        } else {
            $error = "That request was already handled by someone else.";
        }
    } elseif ($decision === 'discard') {
        if ($model->discard($requestId, Auth::email(), $notes !== '' ? $notes : null)) {
            // Silent on purpose: no email, and /track treats it as "no record".
            $info = $model->getForNotification($requestId);
            AuditLog::record('internet.discarded', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ($notes ? ' | ' . $notes : ''));
            $message = "Request {$info['tracking_id']} discarded silently. The applicant was not told.";
        } else {
            $error = "That request was already handled by someone else.";
        }
    }
}

$requests = $model->listForReview();
$failed = array_filter($requests, function ($r) { return $r['status'] === 'Recharge Failed'; });
$fresh  = array_filter($requests, function ($r) { return $r['status'] === 'Submitted'; });

function internet_review_card(array $req) {
    ?>
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:20px;">
        <h3 style="margin-top:0;">
            <?= htmlspecialchars($req['applicant_name']) ?> — #<?= htmlspecialchars($req['tracking_id']) ?>
        </h3>
        <p style="color:#475569; font-size:14px; margin-top:-8px;">
            <?= htmlspecialchars($req['email']) ?> · submitted <?= htmlspecialchars($req['created_at']) ?> UTC
        </p>
        <p style="margin-bottom:4px;">
            <strong><?= htmlspecialchars($req['operator']) ?> — <?= htmlspecialchars($req['package_name']) ?></strong>
            · asking for <strong>₹<?= number_format($req['package_price_paise'] / 100, 2) ?></strong><?= $req['package_validity_days'] ? ' · ' . (int) $req['package_validity_days'] . ' days' : '' ?>
        </p>
        <p style="font-size:12px; color:#64748b; margin-top:0;">The pack and price are what the applicant typed, not checked against the operator.</p>
        <p style="font-size:14px; margin-bottom:4px;"><strong>Reason</strong></p>
        <p style="font-size:14px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:10px; margin-top:0;">
            <?= nl2br(htmlspecialchars($req['reason'])) ?>
        </p>
        <p style="font-size:13px; color:<?= $req['prior_recharges'] > 0 ? '#b45309' : '#64748b' ?>;">
            Earlier requests from this email that were recharged: <strong><?= (int) $req['prior_recharges'] ?></strong>
        </p>

        <?php if ($req['status'] === 'Recharge Failed'): ?>
            <div class="alert-error" style="margin-bottom:12px;">
                <strong>Finance couldn't complete the recharge:</strong><br>
                <?= nl2br(htmlspecialchars((string) $req['recharge_notes'])) ?>
            </div>
        <?php endif; ?>

        <form method="POST" style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">
            <?= CSRF::getInputField() ?>
            <?= CSRF::getSubmitField() ?>
            <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
            <textarea name="notes" placeholder="Notes (required to reject — the applicant sees them. Optional for discard — internal only)"
                      style="flex:1; min-width:220px; min-height:40px;"></textarea>
            <button type="submit" name="decision" value="approve" style="width:auto; background:#059669;">Approve</button>
            <button type="submit" name="decision" value="reject" style="width:auto; background:#dc2626;">Reject</button>
            <button type="submit" name="decision" value="discard" style="width:auto; background:#64748b;"
                    onclick="return confirm('Discard silently? The applicant will NOT be told and the request will vanish from their tracking page.');">Discard</button>
        </form>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Internet Support Review</title>
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>
<body>
    <div class="container">
        <h1 style="margin-bottom:6px;">Internet Support Review</h1>
        <p style="color:#64748b; font-size:14px; margin-top:0;">
            Decide whether each request is reasonable. Phone numbers aren't shown here; finance sees them when doing the recharge.
            <a href="/admin/dashboard" style="color:#106b9a;">Back to workspace</a>
        </p>

        <?php if ($message): ?><div class="alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if (!empty($failed)): ?>
            <h2 style="font-size:17px; color:#b91c1c;">Recharge failed — needs attention</h2>
            <?php foreach ($failed as $req) { internet_review_card($req); } ?>
        <?php endif; ?>

        <h2 style="font-size:17px;">New requests</h2>
        <?php if (empty($fresh)): ?>
            <p>Nothing waiting for review.</p>
        <?php endif; ?>
        <?php foreach ($fresh as $req) { internet_review_card($req); } ?>
    </div>
</body>
</html>
