<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../models/InternetSupportModel.php';

// Finance group, which includes owners (same boundary as the reimbursement queue).
requireRole(['finance', 'owner']);

$model = new InternetSupportModel();
$message = '';
$error = '';
$justClosedId = null;

/** Delete an uploaded receipt that is no longer wanted. */
function finance_discard_file($path) {
    if (is_string($path) && strpos($path, 'uploads/') === 0 && strpos($path, '..') === false) {
        $full = __DIR__ . '/../../' . $path;
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    // A double click on "Recharge done" must never fire twice.
    if (!CSRF::consumeSubmitToken($_POST['submit_token'] ?? '')) {
        header('Location: /finance/internet-support');
        exit;
    }

    $requestId = (int) ($_POST['request_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $result = $_POST['result'] ?? '';

    if ($result === 'done') {
        $reference = trim($_POST['recharge_reference'] ?? '');

        if ($reference === '') {
            $error = "Enter the operator's recharge reference or transaction ID before marking this done. It is emailed to the applicant.";
        } elseif (mb_strlen($reference) > 255) {
            $error = "That reference is too long (255 characters max).";
        } elseif ($model->markRechargeDone($requestId, Auth::email(), $reference)) {
            $info = $model->getForNotification($requestId);
            AuditLog::record('internet.recharge_done', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ' | Ref: ' . $reference);
            Mailer::sendInternetStatusUpdate($info['email'], $info['applicant_name'], $info['tracking_id'], 'Awaiting Receipt', '', $reference);
            $message = "Request {$info['tracking_id']} marked recharged. The applicant has been asked for the receipt.";
        } else {
            $error = "That request was already handled by someone else.";
        }

    } elseif ($result === 'failed') {
        if ($notes === '') {
            $error = "Add a note saying why the recharge failed. The reviewers will see it.";
        } elseif ($model->markRechargeFailed($requestId, Auth::email(), $notes)) {
            // No applicant email: this goes back to the reviewers (see Mailer::sendInternetStatusUpdate's contract).
            $info = $model->getForNotification($requestId);
            AuditLog::record('internet.recharge_failed', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ' | ' . $notes);
            app_log("Internet recharge failed: {$info['tracking_id']} by " . Auth::email() . " — $notes");
            $message = "Request {$info['tracking_id']} sent back to the support reviewers.";
        } else {
            $error = "That request was already handled by someone else.";
        }

    } elseif ($result === 'close') {
        if (empty($_POST['receipt_downloaded'])) {
            $error = "Download the receipt and tick the confirmation box before closing. It is deleted from the server afterwards.";
        } elseif ($model->close($requestId, Auth::email(), true)) {
            $info = $model->getForNotification($requestId);
            AuditLog::record('internet.closed', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ' | Receipt downloaded');
            Mailer::sendInternetStatusUpdate($info['email'], $info['applicant_name'], $info['tracking_id'], 'Closed');
            $message = "Request {$info['tracking_id']} closed. Its receipt will be removed from the server by the scheduled cleanup.";
            $justClosedId = $requestId;
        } else {
            $error = "That request was already handled by someone else.";
        }

    } elseif ($result === 'bounce') {
        if ($notes === '') {
            $error = "Add a note saying what is wrong with the receipt. The applicant will see it.";
        } else {
            $oldPath = $model->sendBackForReceipt($requestId, Auth::email(), $notes);
            if ($oldPath === false) {
                $error = "That request was already handled by someone else.";
            } else {
                finance_discard_file($oldPath);
                $info = $model->getForNotification($requestId);
                AuditLog::record('internet.receipt_bounced', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ' | ' . $notes);
                Mailer::sendInternetStatusUpdate($info['email'], $info['applicant_name'], $info['tracking_id'], 'Receipt Rejected', $notes);
                $message = "Receipt for {$info['tracking_id']} sent back to the applicant.";
            }
        }
    }
}

$rechargeQueue = $model->listForRechargeQueue();
$receiptQueue  = $model->listForReceiptVerification();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <title>Internet Support — Finance</title>
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>
<body>
    <div class="container">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h1 style="margin:0;">Internet Support</h1>
            <span style="display:flex; gap:18px; flex-wrap:wrap;">
                <a href="/finance/internet-support/closed" style="color:#106b9a; font-size:14px; font-weight:600; text-decoration:none;">Closed requests &amp; receipts &rarr;</a>
                <a href="/finance/reimbursements" style="color:#106b9a; font-size:14px; font-weight:600; text-decoration:none;">Reimbursements &rarr;</a>
            </span>
        </div>
        <p style="color:#64748b; font-size:14px;">
            Approved requests waiting for a recharge, then receipts waiting to be checked.
            The reason for a request isn't shown here; the review already happened.
        </p>

        <?php if ($message): ?>
            <div class="alert-success">
                <?= htmlspecialchars($message) ?>
                <?php if ($justClosedId): ?>
                    <br>
                    <a href="/finance/internet-support/receipt/<?= (int) $justClosedId ?>" target="_blank"
                       style="display:inline-block; margin-top:8px; color:#106b9a; font-weight:600; text-decoration:none;">
                        ⬇ Download receipt (PDF)
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <!-- ============ 1. Recharge queue ============ -->
        <h2 style="font-size:17px;">1. To recharge (<?= count($rechargeQueue) ?>)</h2>
        <?php if (empty($rechargeQueue)): ?><p>Nothing awaiting recharge.</p><?php endif; ?>

        <?php foreach ($rechargeQueue as $req): ?>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:20px;">
                <h3 style="margin-top:0;">
                    <?= htmlspecialchars($req['applicant_name']) ?> — #<?= htmlspecialchars($req['tracking_id']) ?>
                </h3>
                <?php if ($req['phone_error']): ?>
                    <div class="alert-error" style="margin:8px 0;">
                        <strong>The phone number on this request can't be read</strong> (it was stored damaged),
                        so it can't be recharged. Mark it as failed with a note. A reviewer can then reject it,
                        and the volunteer can submit a new request straight away.
                    </div>
                <?php else: ?>
                    <p style="font-size:20px; margin:6px 0;"><strong><?= htmlspecialchars($req['phone']) ?></strong></p>
                <?php endif; ?>
                <p style="margin-bottom:4px;">
                    <?= htmlspecialchars($req['operator']) ?> — <strong><?= htmlspecialchars($req['package_name']) ?></strong><br>
                    Approved amount: <strong>₹<?= number_format($req['package_price_paise'] / 100, 2) ?></strong><?= $req['package_validity_days'] ? ' · ' . (int) $req['package_validity_days'] . ' days' : '' ?>
                </p>
                <p style="font-size:12px; color:#64748b; margin-top:0;">The applicant stated this pack and price. Confirm the operator's actual price before recharging, and don't pay more than the approved amount.</p>
                <p style="font-size:13px; color:#64748b;">
                    Approved by <?= htmlspecialchars($req['decided_by']) ?> on <?= htmlspecialchars($req['decided_at']) ?> UTC
                </p>

                <form method="POST" style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">
                    <?= CSRF::getInputField() ?>
                    <?= CSRF::getSubmitField() ?>
                    <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                    <?php if (!$req['phone_error']): ?>
                        <!-- Required for "Recharge done"; "Recharge failed" has formnovalidate. -->
                        <input type="text" name="recharge_reference" required maxlength="255"
                               placeholder="Operator reference / transaction ID (required when done)"
                               style="flex:1; min-width:180px;">
                    <?php endif; ?>
                    <textarea name="notes" placeholder="Notes (required if recharge failed)" style="flex:1; min-width:180px; min-height:40px;"></textarea>
                    <?php if (!$req['phone_error']): ?>
                        <button type="submit" name="result" value="done" style="width:auto; background:#059669;">Recharge done</button>
                    <?php endif; ?>
                    <button type="submit" name="result" value="failed" formnovalidate style="width:auto; background:#dc2626;">Recharge failed</button>
                </form>
            </div>
        <?php endforeach; ?>

        <!-- ============ 2. Receipt verification ============ -->
        <h2 style="font-size:17px; margin-top:40px;">2. Receipts to check (<?= count($receiptQueue) ?>)</h2>
        <?php if (empty($receiptQueue)): ?><p>No receipts waiting.</p><?php endif; ?>

        <?php foreach ($receiptQueue as $req): ?>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:20px;">
                <h3 style="margin-top:0;">
                    <?= htmlspecialchars($req['applicant_name']) ?> — #<?= htmlspecialchars($req['tracking_id']) ?>
                </h3>
                <p>
                    <?= $req['phone_error'] ? '(phone unreadable)' : htmlspecialchars($req['phone']) ?> ·
                    <?= htmlspecialchars($req['operator']) ?> — <?= htmlspecialchars($req['package_name']) ?>
                    (approved ₹<?= number_format($req['package_price_paise'] / 100, 2) ?>)<br>
                    Recharge reference: <strong><?= htmlspecialchars((string) $req['recharge_reference']) ?></strong>
                </p>
                <p style="font-size:14px;">
                    <?php if ($req['receipt_path'] !== ''): ?>
                        <a href="/<?= htmlspecialchars($req['receipt_path']) ?>" target="_blank" download>⬇ Download receipt</a>
                    <?php else: ?>
                        <span style="color:#94a3b8;">Receipt file is missing.</span>
                    <?php endif; ?>
                    <span style="color:#64748b;"> — uploaded <?= htmlspecialchars((string) $req['receipt_submitted_at']) ?> UTC</span>
                </p>

                <form method="POST" style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">
                    <?= CSRF::getInputField() ?>
                    <?= CSRF::getSubmitField() ?>
                    <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                    <textarea name="notes" placeholder="Notes (required to send the receipt back — the applicant sees them)" style="flex:1; min-width:220px; min-height:40px;"></textarea>
                    <label style="flex-basis:100%; font-size:13px; font-weight:500;">
                        <input type="checkbox" name="receipt_downloaded" value="1">
                        I have downloaded the receipt — OK to delete it from the server
                    </label>
                    <button type="submit" name="result" value="close" style="width:auto; background:#059669;">Verify &amp; close</button>
                    <button type="submit" name="result" value="bounce" style="width:auto; background:#dc2626;">Send receipt back</button>
                </form>
            </div>
        <?php endforeach; ?>

    </div>
</body>
</html>
