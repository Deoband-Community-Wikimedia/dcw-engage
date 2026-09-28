<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';

// Finance group, which includes owners per your instruction — this is the
// payment-execution boundary, and owners are trusted to be part of it here.
// Organizers are still excluded: they review claim substance
// (reimbursement_review.php) but don't execute payment.
requireRole(['finance', 'owner']);

$reimbursementModel = new ReimbursementModel();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    // Same double-submit guard as team.php — matters more here than almost
    // anywhere else in this app: a double click on "Mark paid" firing twice
    // is exactly the kind of bug that turns into a real duplicate transfer
    // if the underlying markPaid() call weren't also itself idempotent
    // (it is — see the WHERE status = 'Approved for Payment' guard — but
    // this stops the second request from even reaching that far).
    if (!CSRF::consumeSubmitToken($_POST['submit_token'] ?? '')) {
        header('Location: /finance/reimbursements');
        exit;
    }

    $requestId = (int) ($_POST['request_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if (($_POST['result'] ?? '') === 'paid') {
        $reference = trim($_POST['payment_reference'] ?? '');
        $receiptsDownloaded = !empty($_POST['receipts_downloaded']);

        // Every payment is a UPI or bank transfer (cash isn't an option), so
        // there is always a UTR / transaction ID to record. It is also emailed
        // to the applicant so they can find the payment on their statement.
        if ($reference === '') {
            $error = "Enter the UTR / transaction reference before marking this paid. It is emailed to the applicant so they can find the payment on their statement.";
        } elseif (mb_strlen($reference) > 255) {
            $error = "That transaction reference is too long (255 characters max).";
        // If receipts were attached, finance must confirm they've saved them
        // before this can be marked paid — that confirmation is what allows
        // the purge cron to delete the files afterwards.
        } elseif ($reimbursementModel->hasReceipts($requestId) && !$receiptsDownloaded) {
            $error = "This request has receipts attached. Download them and tick the confirmation box before marking it paid — they are deleted from the server afterwards.";
        } elseif ($reimbursementModel->markPaid($requestId, Auth::email(), $reference, $receiptsDownloaded)) {
            $info = $reimbursementModel->getForNotification($requestId);
            AuditLog::record('reimbursement.paid', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ' | Ref: ' . $reference . ($receiptsDownloaded ? ' | Receipts downloaded' : ''));
            require_once __DIR__ . '/../../includes/mailer.php';
            Mailer::sendReimbursementStatusUpdate(
                $info['email'], $info['applicant_name'], $info['tracking_id'],
                $info['event_name'], 'Paid', '', $reference
            );
            $message = "Request #$requestId marked paid."
                . ($receiptsDownloaded ? " Its receipts will be removed from the server by the scheduled cleanup." : '');
        }
    } elseif (($_POST['result'] ?? '') === 'failed') {
        if ($reimbursementModel->markPaymentFailed($requestId, Auth::email(), $notes)) {
            // Deliberately no applicant email here — see the CALLER
            // CONTRACT note on Mailer::sendReimbursementStatusUpdate().
            // This is a finance-to-admin signal, so it needs to be loud
            // somewhere an admin will actually see it: logged here, in the
            // audit trail, and it now surfaces in reimbursement_review.php's
            // "Payment Failed — needs attention" section for the admin to
            // act on.
            $info = $reimbursementModel->getForNotification($requestId);
            AuditLog::record('reimbursement.payment_failed', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ($notes ? ' | ' . $notes : ''));
            app_log("Reimbursement payment failed: #{$info['tracking_id']} ({$info['event_name']}) by " . Auth::email() . " — $notes");
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
            Approved claims awaiting payment. Expense details aren't shown here — that review
            already happened. If receipts were attached, download them before marking a request
            paid: they are deleted from the server afterwards.
        </p>

        <?php if ($message): ?>
            <div class="alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($queue)): ?>
            <p>Nothing awaiting payment.</p>
        <?php endif; ?>

        <?php foreach ($queue as $req): ?>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:20px;">
                <h3 style="margin-top:0;">
                    <?= htmlspecialchars($req['applicant_name']) ?> — #<?= htmlspecialchars($req['tracking_id']) ?>
                </h3>
                <p style="color:#475569; font-size:14px; margin-top:-8px;">
                    Event: <strong><?= htmlspecialchars($req['event_name']) ?></strong>
                </p>
                <p><strong>Amount: ₹<?= number_format($req['total_amount_paise'] / 100, 2) ?></strong></p>

                <?php if ($req['payment_method'] === 'upi'): ?>
                    <p>UPI ID: <strong><?= htmlspecialchars($req['upi_id']) ?></strong></p>
                <?php elseif ($req['payment_method'] === 'bank'): ?>
                    <p>
                        Account name: <strong><?= htmlspecialchars($req['bank_account_name']) ?></strong><br>
                        Account number: <strong><?= htmlspecialchars($req['bank_account_number'] ?? '') ?></strong><br>
                        IFSC: <strong><?= htmlspecialchars($req['bank_ifsc']) ?></strong>
                    </p>
                <?php endif; ?>

                <?php if (!empty($req['receipts'])): ?>
                    <p style="font-size:14px; margin-bottom:6px;"><strong>Receipts</strong></p>
                    <ul style="margin:0 0 12px; padding-left:20px; font-size:14px;">
                        <?php foreach ($req['receipts'] as $n => $path): ?>
                            <li><a href="/<?= htmlspecialchars($path) ?>" target="_blank" download>Receipt <?= $n + 1 ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="font-size:13px; color:#94a3b8;">No receipts attached.</p>
                <?php endif; ?>

                <p style="font-size:13px; color:#64748b;">
                    Approved by <?= htmlspecialchars($req['decided_by']) ?> on <?= htmlspecialchars($req['decided_at']) ?>
                </p>

                <form method="POST" style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">
                    <?= CSRF::getInputField() ?>
                    <?= CSRF::getSubmitField() ?>
                    <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                    <!-- Required for "Mark paid". The "Payment failed" button has
                         formnovalidate so it can still be submitted without one. -->
                    <input type="text" name="payment_reference" required maxlength="255"
                           placeholder="UTR / transaction reference (required to mark paid)"
                           style="flex:1; min-width:180px;">
                    <textarea name="notes" placeholder="Notes (required if marking failed)" style="flex:1; min-width:180px; min-height:40px;"></textarea>
                    <?php if (!empty($req['receipts'])): ?>
                        <label style="flex-basis:100%; font-size:13px; font-weight:500;">
                            <input type="checkbox" name="receipts_downloaded" value="1">
                            I have downloaded the receipts — OK to delete them from the server
                        </label>
                    <?php endif; ?>
                    <button type="submit" name="result" value="paid" style="width:auto; background:#059669;">Mark paid</button>
                    <button type="submit" name="result" value="failed" formnovalidate style="width:auto; background:#dc2626;">Payment failed</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
