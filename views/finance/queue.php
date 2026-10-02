<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';
require_once __DIR__ . '/../../models/InternetSupportModel.php';

// Combined finance queue: reimbursement payments + internet support recharges
// and receipt checks. Same boundary as before: finance and owners. Organizers
// review claim substance elsewhere but never execute payment.
requireRole(['finance', 'owner']);

const FINANCE_TABS = ['reimbursement', 'internet'];

$reimbursementModel = new ReimbursementModel();
$internetModel      = new InternetSupportModel();

$message = '';
$error = '';
$justPaidRequestId = null;   // reimbursement payment confirmation PDF
$justClosedId = null;        // internet support receipt PDF

/** Delete an uploaded internet-support receipt that is no longer wanted. */
function finance_discard_file($path) {
    if (is_string($path) && strpos($path, 'uploads/') === 0 && strpos($path, '..') === false) {
        $full = __DIR__ . '/../../' . $path;
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

// Which tab to show. Legacy URLs can preset $financeDefaultTab before including this file.
$requestedTab = $_POST['tab'] ?? $_GET['tab'] ?? ($financeDefaultTab ?? null);
if ($requestedTab !== null && !in_array($requestedTab, FINANCE_TABS, true)) {
    $requestedTab = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    // Double-submit guard (a double click on "Mark paid" / "Recharge done"
    // must never fire twice). The model methods are idempotent as well.
    if (!CSRF::consumeSubmitToken($_POST['submit_token'] ?? '')) {
        header('Location: /finance' . ($requestedTab ? '?tab=' . urlencode($requestedTab) : ''));
        exit;
    }

    $queueName = $_POST['queue'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $notes     = trim($_POST['notes'] ?? '');
    $result    = $_POST['result'] ?? '';

    // ==================================================================
    // Reimbursements
    // ==================================================================
    if ($queueName === 'reimbursement') {
        $requestedTab = 'reimbursement';

        if ($result === 'paid') {
            $reference = trim($_POST['payment_reference'] ?? '');
            $receiptsDownloaded = !empty($_POST['receipts_downloaded']);

            // Every payment is a UPI or bank transfer, so there is always a
            // UTR / transaction ID. It is emailed to the applicant.
            if ($reference === '') {
                $error = "Enter the UTR / transaction reference before marking this paid. It is emailed to the applicant so they can find the payment on their statement.";
            } elseif (mb_strlen($reference) > 255) {
                $error = "That transaction reference is too long (255 characters max).";
            // Receipts attached: finance must confirm they've saved them, which
            // is what allows the purge cron to delete the files afterwards.
            } elseif ($reimbursementModel->hasReceipts($requestId) && !$receiptsDownloaded) {
                $error = "This request has receipts attached. Download them and tick the confirmation box before marking it paid — they are deleted from the server afterwards.";
            } elseif ($reimbursementModel->markPaid($requestId, Auth::email(), $reference, $receiptsDownloaded)) {
                $info = $reimbursementModel->getForNotification($requestId);
                AuditLog::record('reimbursement.paid', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ' | Ref: ' . $reference . ($receiptsDownloaded ? ' | Receipts downloaded' : ''));
                Mailer::sendReimbursementStatusUpdate(
                    $info['email'], $info['applicant_name'], $info['tracking_id'],
                    $info['event_name'], 'Paid', '', $reference
                );
                $message = "Request #$requestId marked paid."
                    . ($receiptsDownloaded ? " Its receipts will be removed from the server by the scheduled cleanup." : '');
                $justPaidRequestId = $requestId;
            } else {
                $error = "That request was already handled by someone else.";
            }
        } elseif ($result === 'failed') {
            if ($reimbursementModel->markPaymentFailed($requestId, Auth::email(), $notes)) {
                // Deliberately no applicant email: this is a finance-to-admin
                // signal. It surfaces in reimbursement_review.php's
                // "Payment Failed" section for an admin to act on.
                $info = $reimbursementModel->getForNotification($requestId);
                AuditLog::record('reimbursement.payment_failed', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ($notes ? ' | ' . $notes : ''));
                app_log("Reimbursement payment failed: #{$info['tracking_id']} ({$info['event_name']}) by " . Auth::email() . " — $notes");
                $message = "Request #$requestId marked as payment failed — it now needs admin attention.";
            } else {
                $error = "That request was already handled by someone else.";
            }
        }

    // ==================================================================
    // Internet support
    // ==================================================================
    } elseif ($queueName === 'internet') {
        $requestedTab = 'internet';

        if ($result === 'done') {
            $reference = trim($_POST['recharge_reference'] ?? '');

            if ($reference === '') {
                $error = "Enter the operator's recharge reference or transaction ID before marking this done. It is emailed to the applicant.";
            } elseif (mb_strlen($reference) > 255) {
                $error = "That reference is too long (255 characters max).";
            } elseif ($internetModel->markRechargeDone($requestId, Auth::email(), $reference)) {
                $info = $internetModel->getForNotification($requestId);
                AuditLog::record('internet.recharge_done', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ' | Ref: ' . $reference);
                Mailer::sendInternetStatusUpdate($info['email'], $info['applicant_name'], $info['tracking_id'], 'Awaiting Receipt', '', $reference);
                $message = "Request {$info['tracking_id']} marked recharged. The applicant has been asked for the receipt.";
            } else {
                $error = "That request was already handled by someone else.";
            }

        } elseif ($result === 'failed') {
            if ($notes === '') {
                $error = "Add a note saying why the recharge failed. The reviewers will see it.";
            } elseif ($internetModel->markRechargeFailed($requestId, Auth::email(), $notes)) {
                // No applicant email: this goes back to the reviewers.
                $info = $internetModel->getForNotification($requestId);
                AuditLog::record('internet.recharge_failed', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ' | ' . $notes);
                app_log("Internet recharge failed: {$info['tracking_id']} by " . Auth::email() . " — $notes");
                $message = "Request {$info['tracking_id']} sent back to the support reviewers.";
            } else {
                $error = "That request was already handled by someone else.";
            }

        } elseif ($result === 'close') {
            if (empty($_POST['receipt_downloaded'])) {
                $error = "Download the receipt and tick the confirmation box before closing. It is deleted from the server afterwards.";
            } elseif ($internetModel->close($requestId, Auth::email(), true)) {
                $info = $internetModel->getForNotification($requestId);
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
                $oldPath = $internetModel->sendBackForReceipt($requestId, Auth::email(), $notes);
                if ($oldPath === false) {
                    $error = "That request was already handled by someone else.";
                } else {
                    finance_discard_file($oldPath);
                    $info = $internetModel->getForNotification($requestId);
                    AuditLog::record('internet.receipt_bounced', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ' | ' . $notes);
                    Mailer::sendInternetStatusUpdate($info['email'], $info['applicant_name'], $info['tracking_id'], 'Receipt Rejected', $notes);
                    $message = "Receipt for {$info['tracking_id']} sent back to the applicant.";
                }
            }
        }
    }
}

$reimbursementQueue = $reimbursementModel->listForFinanceQueue();
$rechargeQueue      = $internetModel->listForRechargeQueue();
$receiptQueue       = $internetModel->listForReceiptVerification();

$reimbursementCount = count($reimbursementQueue);
$internetCount      = count($rechargeQueue) + count($receiptQueue);

// No explicit tab: land on the first one that has work waiting.
if ($requestedTab === null) {
    $requestedTab = ($reimbursementCount === 0 && $internetCount > 0) ? 'internet' : 'reimbursement';
}
$tab = $requestedTab;

$tabStyle = function ($active) {
    return 'padding:8px 16px; border-radius:6px 6px 0 0; text-decoration:none; font-weight:600; font-size:14px; '
        . ($active ? 'background:#106b9a; color:#fff;' : 'background:#e2e8f0; color:#334155;');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Queue</title>
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>
<body>
    <div class="container">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h1 style="margin:0;">Finance Queue</h1>
            <a href="/finance/closed?tab=<?= htmlspecialchars($tab) ?>" style="color:#106b9a; font-size:14px; font-weight:600; text-decoration:none;">Closed requests &amp; receipts &rarr;</a>
        </div>

        <div style="display:flex; gap:6px; margin:20px 0 0; border-bottom:2px solid #106b9a; flex-wrap:wrap;">
            <a href="/finance?tab=reimbursement" style="<?= $tabStyle($tab === 'reimbursement') ?>">Reimbursements (<?= $reimbursementCount ?>)</a>
            <a href="/finance?tab=internet" style="<?= $tabStyle($tab === 'internet') ?>">Internet support (<?= $internetCount ?>)</a>
        </div>

        <?php if ($message): ?>
            <div class="alert-success" style="margin-top:16px;">
                <?= htmlspecialchars($message) ?>
                <?php if ($justPaidRequestId): ?>
                    <br>
                    <a href="/finance/reimbursements/receipt/<?= (int) $justPaidRequestId ?>" target="_blank"
                       style="display:inline-block; margin-top:8px; color:#106b9a; font-weight:600; text-decoration:none;">
                        ⬇ Download payment confirmation (PDF)
                    </a>
                <?php endif; ?>
                <?php if ($justClosedId): ?>
                    <br>
                    <a href="/finance/internet-support/receipt/<?= (int) $justClosedId ?>" target="_blank"
                       style="display:inline-block; margin-top:8px; color:#106b9a; font-weight:600; text-decoration:none;">
                        ⬇ Download receipt (PDF)
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-error" style="margin-top:16px;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div style="margin-top:20px;">

<?php if ($tab === 'reimbursement'): ?>
        <!-- ================= REIMBURSEMENTS ================= -->
        <p style="color:#64748b; font-size:14px;">
            Approved claims awaiting payment. Expense details aren't shown here — that review
            already happened. If receipts were attached, download them before marking a request
            paid: they are deleted from the server afterwards.
        </p>

        <?php if (empty($reimbursementQueue)): ?>
            <p>Nothing awaiting payment.</p>
        <?php endif; ?>

        <?php foreach ($reimbursementQueue as $req): ?>
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
                    <input type="hidden" name="queue" value="reimbursement">
                    <input type="hidden" name="tab" value="reimbursement">
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

<?php else: ?>
        <!-- ================= INTERNET SUPPORT ================= -->
        <p style="color:#64748b; font-size:14px;">
            Approved requests waiting for a recharge, then receipts waiting to be checked.
            The reason for a request isn't shown here; the review already happened.
        </p>

        <!-- ---- 1. Recharge queue ---- -->
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
                    <input type="hidden" name="queue" value="internet">
                    <input type="hidden" name="tab" value="internet">
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

        <!-- ---- 2. Receipt verification ---- -->
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
                    <input type="hidden" name="queue" value="internet">
                    <input type="hidden" name="tab" value="internet">
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
<?php endif; ?>

        </div>
    </div>
</body>
</html>
