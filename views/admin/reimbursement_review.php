<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';

// Both owner and organizer can review claim substance — same access level
// as the rest of /admin (form_manager, builder, etc). Finance is NOT in this
// list: they get reimbursement_queue.php instead, which shows payment
// details, not claim substance.
requireRole(['owner', 'organizer']);

// Global now — one reimbursement form covers every event, so there's no
// per-event slug to look up (no more FormModel/ReimbursementFormModel here
// at all). Every open request across every event lands on this one page.
$reimbursementModel = new ReimbursementModel();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    // Same double-submit guard as team.php: a double click on Approve/Reject
    // is exactly the kind of thing that shouldn't be able to fire twice on a
    // financial action. The first click consumes the token; a second finds
    // it gone and is silently dropped here, before anything changes.
    if (!CSRF::consumeSubmitToken($_POST['submit_token'] ?? '')) {
        header('Location: /admin/reimbursements/review');
        exit;
    }

    $requestId = (int) ($_POST['request_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if (($_POST['decision'] ?? '') === 'approve') {
        if ($reimbursementModel->approveForPayment($requestId, Auth::email())) {
            $info = $reimbursementModel->getForNotification($requestId);
            AuditLog::record('reimbursement.approved', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id']);
            require_once __DIR__ . '/../../includes/mailer.php';
            Mailer::sendReimbursementStatusUpdate(
                $info['email'], $info['applicant_name'], $info['tracking_id'],
                $info['event_name'], 'Approved for Payment'
            );
            $message = "Request #$requestId approved for payment. It now moves to the finance queue.";
        }
    } elseif (($_POST['decision'] ?? '') === 'reject') {
        if ($reimbursementModel->reject($requestId, Auth::email(), $notes)) {
            $info = $reimbursementModel->getForNotification($requestId);
            AuditLog::record('reimbursement.rejected', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ($notes ? ' | ' . $notes : ''));
            require_once __DIR__ . '/../../includes/mailer.php';
            Mailer::sendReimbursementStatusUpdate(
                $info['email'], $info['applicant_name'], $info['tracking_id'],
                $info['event_name'], 'Rejected', $notes
            );
            $message = "Request #$requestId rejected.";
        }
    }
}

$pending = $reimbursementModel->listForAdminReview('Submitted');
$underReview = $reimbursementModel->listForAdminReview('Under Review');
// Payment Failed lands back here, not with the applicant — see the
// CALLER CONTRACT note on Mailer::sendReimbursementStatusUpdate(). An admin
// needs to either fix the payment details and re-approve, or reject outright.
$paymentFailed = $reimbursementModel->listForAdminReview('Payment Failed');
$requests = array_merge($pending, $underReview);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reimbursement Review — DCW Engage</title>
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>
<body>
    <div class="container">
        <h1>Reimbursement Review</h1>

        <?php if ($message): ?>
            <div class="alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (empty($requests)): ?>
            <p>Nothing awaiting review.</p>
        <?php endif; ?>

        <?php foreach ($requests as $req): ?>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:20px;">
                <h3 style="margin-top:0;">
                    <?= htmlspecialchars($req['applicant_name']) ?>
                    (<?= htmlspecialchars($req['email']) ?>) — #<?= htmlspecialchars($req['tracking_id']) ?>
                </h3>
                <p style="color:#475569; font-size:14px; margin-top:-8px;">
                    Event: <strong><?= htmlspecialchars($req['event_name']) ?></strong>
                </p>
                <p><strong>Total: ₹<?= number_format($req['total_amount_paise'] / 100, 2) ?></strong></p>

                <table style="width:100%; border-collapse:collapse; margin-bottom:15px;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid #cbd5e1;">
                            <th style="padding:6px 0;">Category</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($req['line_items'] as $item): ?>
                            <tr style="border-bottom:1px solid #e2e8f0;">
                                <td style="padding:6px 0;"><?= htmlspecialchars($item['category']) ?></td>
                                <td><?= htmlspecialchars($item['description']) ?></td>
                                <td>₹<?= number_format($item['amount_paise'] / 100, 2) ?></td>
                                <td><a href="/<?= htmlspecialchars($item['receipt_path']) ?>" target="_blank">View</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="POST" style="display:flex; gap:10px; align-items:flex-start;">
                    <?= CSRF::getInputField() ?>
                    <?= CSRF::getSubmitField() ?>
                    <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                    <textarea name="notes" placeholder="Notes (required if rejecting)" style="flex:1; min-height:40px;"></textarea>
                    <button type="submit" name="decision" value="approve" style="width:auto; background:#059669;">Approve for payment</button>
                    <button type="submit" name="decision" value="reject" style="width:auto; background:#dc2626;">Reject</button>
                </form>
            </div>
        <?php endforeach; ?>

        <?php if (!empty($paymentFailed)): ?>
            <h2 style="color:#dc2626;">Payment Failed — needs attention</h2>
            <p style="color:#64748b; font-size:14px;">
                Finance tried to pay these and it didn't go through. Check the note below,
                fix the payment details if needed (the applicant may need to resubmit
                correct bank/UPI details), then re-approve or reject.
            </p>
            <?php foreach ($paymentFailed as $req): ?>
                <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:20px; margin-bottom:20px;">
                    <h3 style="margin-top:0;">
                        <?= htmlspecialchars($req['applicant_name']) ?>
                        (<?= htmlspecialchars($req['email']) ?>) — #<?= htmlspecialchars($req['tracking_id']) ?>
                    </h3>
                    <p style="color:#475569; font-size:14px; margin-top:-8px;">
                        Event: <strong><?= htmlspecialchars($req['event_name']) ?></strong>
                    </p>
                    <p><strong>Total: ₹<?= number_format($req['total_amount_paise'] / 100, 2) ?></strong></p>
                    <?php if (!empty($req['payment_notes'])): ?>
                        <p><strong>Finance's note:</strong> <?= htmlspecialchars($req['payment_notes']) ?></p>
                    <?php endif; ?>

                    <form method="POST" style="display:flex; gap:10px; align-items:flex-start;">
                        <?= CSRF::getInputField() ?>
                        <?= CSRF::getSubmitField() ?>
                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                        <textarea name="notes" placeholder="Notes (required if rejecting)" style="flex:1; min-height:40px;"></textarea>
                        <button type="submit" name="decision" value="approve" style="width:auto; background:#059669;">Re-approve for payment</button>
                        <button type="submit" name="decision" value="reject" style="width:auto; background:#dc2626;">Reject</button>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
