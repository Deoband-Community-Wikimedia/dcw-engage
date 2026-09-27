<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';

// Finance-only, strictly — this is the payment-execution boundary you asked
// for. Owners are deliberately NOT included here: you said a separate staff
// handles payments, so this stays finance-only rather than owners being able
// to bypass the two-person-integrity control. If you want an owner override
// for emergencies later, that's a one-line change (requireRole(['finance', 'owner'])).
requireRole('finance');

$reimbursementModel = new ReimbursementModel();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    $requestId = (int) ($_POST['request_id'] ?? 0);
    $financeIdentifier = currentAdminIdentifier(); // ASSUMPTION: see require_role.php
    $notes = trim($_POST['notes'] ?? '');

    if (($_POST['result'] ?? '') === 'paid') {
        $reference = trim($_POST['payment_reference'] ?? '');
        if ($reimbursementModel->markPaid($requestId, $financeIdentifier, $reference)) {
            $info = $reimbursementModel->getForNotification($requestId);
            require_once __DIR__ . '/../../includes/mailer.php';
            Mailer::sendReimbursementStatusUpdate(
                $info['email'], $info['applicant_name'], $info['tracking_id'],
                $info['event_title'], 'Paid'
            );
            $message = "Request #$requestId marked paid.";
        }
    } elseif (($_POST['result'] ?? '') === 'failed') {
        if ($reimbursementModel->markPaymentFailed($requestId, $financeIdentifier, $notes)) {
            // Deliberately no applicant email here — see the CALLER
            // CONTRACT note on Mailer::sendReimbursementStatusUpdate().
            // This is a finance-to-admin signal, so it needs to be loud
            // somewhere an admin will actually see it: logged here, and
            // it now surfaces in reimbursement_review.php's "Payment
            // Failed — needs attention" section for the admin to act on.
            $info = $reimbursementModel->getForNotification($requestId);
            app_log("Reimbursement payment failed: #{$info['tracking_id']} ({$info['event_title']}) by $financeIdentifier — $notes");
            $message = "Request #$requestId marked as payment failed — it now needs admin attention.";
        }
    }
}

$queue = $reimbursementModel->listForFinanceQueue();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reimbursement Payment Queue</title>
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>
<body>
    <div class="container">
        <h1>Reimbursement Payment Queue</h1>
        <p style="color:#64748b; font-size:14px;">
            Approved claims awaiting payment. Line items and receipts aren't shown here —
            that review already happened; this queue is for executing the transfer only.
        </p>

        <?php if ($message): ?>
            <div class="alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (empty($queue)): ?>
            <p>Nothing awaiting payment.</p>
        <?php endif; ?>

        <?php foreach ($queue as $req): ?>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:20px;">
                <h3 style="margin-top:0;">
                    <?= htmlspecialchars($req['applicant_name']) ?> — #<?= htmlspecialchars($req['tracking_id']) ?>
                </h3>
                <p><strong>Amount: ₹<?= number_format($req['total_amount_paise'] / 100, 2) ?></strong></p>

                <?php if ($req['payment_method'] === 'upi'): ?>
                    <p>UPI ID: <strong><?= htmlspecialchars($req['upi_id']) ?></strong></p>
                <?php elseif ($req['payment_method'] === 'bank'): ?>
                    <p>
                        Account name: <strong><?= htmlspecialchars($req['bank_account_name']) ?></strong><br>
                        Account number: <strong><?= htmlspecialchars($req['bank_account_number'] ?? '') ?></strong><br>
                        IFSC: <strong><?= htmlspecialchars($req['bank_ifsc']) ?></strong>
                    </p>
                <?php else: ?>
                    <p>Payment method: Cash</p>
                <?php endif; ?>

                <p style="font-size:13px; color:#64748b;">
                    Approved by <?= htmlspecialchars($req['decided_by']) ?> on <?= htmlspecialchars($req['decided_at']) ?>
                </p>

                <form method="POST" style="display:flex; gap:10px; align-items:flex-start;">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                    <input type="text" name="payment_reference" placeholder="UTR / transaction reference" style="flex:1;">
                    <textarea name="notes" placeholder="Notes (required if marking failed)" style="flex:1; min-height:40px;"></textarea>
                    <button type="submit" name="result" value="paid" style="width:auto; background:#059669;">Mark paid</button>
                    <button type="submit" name="result" value="failed" style="width:auto; background:#dc2626;">Payment failed</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
