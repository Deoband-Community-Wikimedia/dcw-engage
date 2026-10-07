<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/money.php';
require_once __DIR__ . '/../../includes/internal_notes_ui.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';
require_once __DIR__ . '/../../models/InternetSupportModel.php';
require_once __DIR__ . '/../../models/InternalNoteModel.php';

// Combined finance queue: reimbursement payments + internet support recharges
// and receipt checks. Same boundary as before: finance and owners. Organizers
// review claim substance elsewhere but never execute payment.
//
// Finance may pay (or recharge) a DIFFERENT amount than the reviewer approved,
// for example when the operator's real price differs or a figure was mistaken.
// A reason is then required and the applicant is told. Reimbursements can never
// be paid above what the applicant claimed, and recharges never above the
// programme limit; both are typo guards, not policy: change them below if needed.
requireRole(['finance', 'owner']);

const FINANCE_TABS = ['reimbursement', 'internet'];

$reimbursementModel = new ReimbursementModel();
$internetModel      = new InternetSupportModel();
$noteModel          = new InternalNoteModel();

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
    // Internal notes (staff only; never shown to the applicant)
    // ==================================================================
    if ($result === 'internal_note' && in_array($queueName, FINANCE_TABS, true)) {
        $requestedTab = $queueName;
        try {
            $noteModel->add($queueName, $requestId, Auth::email(), $_POST['internal_note'] ?? '');
            AuditLog::record($queueName . '.internal_note', Auth::id(), Auth::email(), null, "Request #$requestId");
            $message = 'Internal note added.';
        } catch (\InvalidArgumentException $ex) {
            $error = $ex->getMessage();
        }
        $queueName = ''; // nothing else to do for this POST
    }

    // ==================================================================
    // Reimbursements
    // ==================================================================
    if ($queueName === 'reimbursement') {
        $requestedTab = 'reimbursement';

        if ($result === 'paid') {
            $reference = trim($_POST['payment_reference'] ?? '');
            $receiptsDownloaded = !empty($_POST['receipts_downloaded']);

            // The amount actually paid. It defaults (in the form) to what the
            // reviewer approved; finance may change it, with a reason.
            $paidPaise     = rupees_to_paise($_POST['paid_amount'] ?? '');
            $approvedPaise = $reimbursementModel->approvedAmountPaise($requestId);
            $claimedPaise  = $reimbursementModel->claimedAmountPaise($requestId);
            $amountNote    = trim($_POST['amount_note'] ?? '');
            $differs       = $paidPaise !== null && $paidPaise !== $approvedPaise;
            $paidNote      = $differs ? $amountNote : '';

            // Every payment is a UPI or bank transfer, so there is always a
            // UTR / transaction ID. It is emailed to the applicant.
            if ($reference === '') {
                $error = "Enter the UTR / transaction reference before marking this paid. It is emailed to the applicant so they can find the payment on their statement.";
            } elseif (mb_strlen($reference) > 255) {
                $error = "That transaction reference is too long (255 characters max).";
            } elseif ($paidPaise === null) {
                $error = "Enter the amount you are paying, in ₹ (for example 250 or 250.50).";
            } elseif ($paidPaise > $claimedPaise) {
                $error = "You can't pay more than the applicant claimed (" . rupees_label($claimedPaise) . ").";
            } elseif ($differs && $amountNote === '') {
                $error = "This differs from the approved amount (" . rupees_label($approvedPaise) . "). Add a reason: the applicant will see it.";
            } elseif (mb_strlen($amountNote) > 500) {
                $error = "The reason is too long (500 characters max).";
            // Receipts attached: finance must confirm they've saved them, which
            // is what allows the purge cron to delete the files afterwards.
            } elseif ($reimbursementModel->hasReceipts($requestId) && !$receiptsDownloaded) {
                $error = "This request has receipts attached. Download them and tick the confirmation box before marking it paid — they are deleted from the server afterwards.";
            } elseif ($reimbursementModel->markPaid($requestId, Auth::email(), $reference, $receiptsDownloaded, $paidPaise, $paidNote)) {
                $info = $reimbursementModel->getForNotification($requestId);
                AuditLog::record(
                    'reimbursement.paid', Auth::id(), Auth::email(), $info['email'],
                    'Tracking: ' . $info['tracking_id'] . ' | Ref: ' . $reference
                    . ' | Paid ' . rupees_label($paidPaise)
                    . ($differs ? ' (approved ' . rupees_label($approvedPaise) . ' | Reason: ' . $paidNote . ')' : '')
                    . ($receiptsDownloaded ? ' | Receipts downloaded' : '')
                );
                Mailer::sendReimbursementStatusUpdate(
                    $info['email'], $info['applicant_name'], $info['tracking_id'],
                    $info['event_name'], 'Paid', '', $reference, $paidPaise, $paidNote
                );
                $message = "Request #$requestId marked paid (" . rupees_label($paidPaise) . ")."
                    . ($differs ? " This differs from the approved " . rupees_label($approvedPaise) . "; the applicant has been told why." : '')
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

            // The amount actually recharged. It defaults (in the form) to what
            // the reviewer approved; finance may change it, with a reason.
            $paidPaise     = rupees_to_paise($_POST['paid_amount'] ?? '');
            $approvedPaise = $internetModel->approvedAmountPaise($requestId);
            $settings      = $internetModel->getSettings();
            $maxPaise      = $settings ? (int) $settings['max_amount_paise'] : 0;
            $amountNote    = trim($_POST['amount_note'] ?? '');
            $differs       = $paidPaise !== null && $paidPaise !== $approvedPaise;
            $paidNote      = $differs ? $amountNote : '';

            if ($reference === '') {
                $error = "Enter the operator's recharge reference or transaction ID before marking this done. It is emailed to the applicant.";
            } elseif (mb_strlen($reference) > 255) {
                $error = "That reference is too long (255 characters max).";
            } elseif ($paidPaise === null) {
                $error = "Enter the amount you recharged, in ₹ (for example 299 or 299.50).";
            } elseif ($maxPaise > 0 && $paidPaise > $maxPaise) {
                $error = "That is above the programme limit of " . rupees_label($maxPaise) . ". Check the amount.";
            } elseif ($differs && $amountNote === '') {
                $error = "This differs from the approved amount (" . rupees_label($approvedPaise) . "). Add a reason: the applicant will see it.";
            } elseif (mb_strlen($amountNote) > 500) {
                $error = "The reason is too long (500 characters max).";
            } elseif ($internetModel->markRechargeDone($requestId, Auth::email(), $reference, $paidPaise, $paidNote)) {
                $info = $internetModel->getForNotification($requestId);
                AuditLog::record(
                    'internet.recharge_done', Auth::id(), Auth::email(), $info['email'],
                    'Tracking: ' . $info['tracking_id'] . ' | Ref: ' . $reference
                    . ' | Recharged ' . rupees_label($paidPaise)
                    . ($differs ? ' (approved ' . rupees_label($approvedPaise) . ' | Reason: ' . $paidNote . ')' : '')
                );
                Mailer::sendInternetStatusUpdate($info['email'], $info['applicant_name'], $info['tracking_id'], 'Awaiting Receipt', '', $reference, $paidPaise, $paidNote);
                $message = "Request {$info['tracking_id']} marked recharged (" . rupees_label($paidPaise) . "). The applicant has been asked for the receipt."
                    . ($differs ? " This differs from the approved " . rupees_label($approvedPaise) . "; the applicant has been told why." : '');
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

$e = fn($s) => htmlspecialchars((string) $s);

engage_header([
    'title'   => 'Finance queue',
    'heading' => 'Finance queue',
    'kicker'  => 'Finance',
    'lead'    => 'Pay approved claims, recharge approved packs and check receipts.',
    'wide'    => true,
    'crumbs'  => [['Home', '/'], ['Finance queue']],
    'tools'   => '<span class="who">' . $e(Auth::email()) . '</span>'
               . '<a class="chip-btn" href="/finance/closed?tab=' . $e($tab) . '">Closed requests &amp; receipts &rarr;</a>',
]);
?>
<div class="fcard wide">
    <nav class="tabs" aria-label="Queue">
        <a class="tab<?= $tab === 'reimbursement' ? ' on' : '' ?>" href="/finance?tab=reimbursement">Reimbursements <span class="count"><?= $reimbursementCount ?></span></a>
        <a class="tab<?= $tab === 'internet' ? ' on' : '' ?>" href="/finance?tab=internet">Internet support <span class="count"><?= $internetCount ?></span></a>
    </nav>

    <?php if ($message): ?>
        <div class="alert ok">
            <?= $e($message) ?>
            <?php if ($justPaidRequestId): ?>
                <br><a href="/finance/reimbursements/receipt/<?= (int) $justPaidRequestId ?>" target="_blank" style="font-weight:700;">⬇ Download payment confirmation (PDF)</a>
            <?php endif; ?>
            <?php if ($justClosedId): ?>
                <br><a href="/finance/internet-support/receipt/<?= (int) $justClosedId ?>" target="_blank" style="font-weight:700;">⬇ Download receipt (PDF)</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= $e($error) ?></div><?php endif; ?>

<?php if ($tab === 'reimbursement'): ?>
    <p class="note">
        Approved claims awaiting payment. Expense details aren't shown here; that review already happened.
        If receipts were attached, download them before marking a request paid, as they are deleted from the server afterwards.
        The amount shown is what the reviewer approved. If you pay a different amount, change it in the form and give a reason: the applicant is told.
    </p>

    <?php if (empty($reimbursementQueue)): ?><div class="empty-note">Nothing awaiting payment.</div><?php endif; ?>

    <?php foreach ($reimbursementQueue as $req): ?>
        <?php $approved = (int) $req['approved_paise']; $claimed = (int) $req['total_amount_paise']; ?>
        <div class="qcard pay">
            <div class="qhead">
                <h3><?= $e($req['applicant_name']) ?> <code>#<?= $e($req['tracking_id']) ?></code></h3>
                <span class="qamount" title="Approved amount"><?= $e(rupees_label($approved)) ?></span>
            </div>

            <div class="kv">
                <div><span>Event</span><strong><?= $e($req['event_name']) ?></strong></div>
                <?php if ($approved !== $claimed): ?>
                    <div><span>Applicant claimed</span><strong><?= $e(rupees_label($claimed)) ?></strong></div>
                <?php endif; ?>
                <?php if ($req['payment_method'] === 'upi'): ?>
                    <div><span>UPI ID</span><strong><?= $e($req['upi_id']) ?></strong></div>
                <?php elseif ($req['payment_method'] === 'bank'): ?>
                    <div><span>Account name</span><strong><?= $e($req['bank_account_name']) ?></strong></div>
                    <div><span>Account number</span><strong><?= $e($req['bank_account_number'] ?? '') ?></strong></div>
                    <div><span>IFSC</span><strong><?= $e($req['bank_ifsc']) ?></strong></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($req['receipts'])): ?>
                <div class="qlinks">
                    <strong>Receipts:</strong>
                    <?php foreach ($req['receipts'] as $n => $path): ?>
                        <a href="/<?= $e($path) ?>" target="_blank" download>⬇ Receipt <?= $n + 1 ?></a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="qmeta">No receipts attached.</p>
            <?php endif; ?>

            <p class="qmeta">Approved by <?= $e($req['decided_by']) ?> on <?= $e($req['decided_at']) ?></p>

            <?php internal_notes_block('reimbursement', (int) $req['id'], $noteModel->forRequest('reimbursement', (int) $req['id']), 'result', 'reimbursement'); ?>

            <form method="POST" class="qform">
                <?= CSRF::getInputField() ?>
                <?= CSRF::getSubmitField() ?>
                <input type="hidden" name="queue" value="reimbursement">
                <input type="hidden" name="tab" value="reimbursement">
                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                <!-- Required for "Mark paid". "Payment failed" has formnovalidate. -->
                <input type="text" name="payment_reference" required maxlength="255" placeholder="UTR / transaction reference (required to mark paid)">
                <input type="text" name="paid_amount" required inputmode="decimal" value="<?= $e(paise_to_rupees($approved)) ?>" placeholder="Amount you are paying (₹)" title="Amount you are paying (₹). Starts as the approved amount.">
                <input type="text" name="amount_note" maxlength="500" placeholder="Reason, only if this differs from the approved amount (the applicant sees it)">
                <textarea name="notes" placeholder="Notes (required if marking failed)"></textarea>
                <?php if (!empty($req['receipts'])): ?>
                    <label class="confirm">
                        <input type="checkbox" name="receipts_downloaded" value="1">
                        I have downloaded the receipts; OK to delete them from the server
                    </label>
                <?php endif; ?>
                <button type="submit" name="result" value="paid" class="btn-ok">Mark paid</button>
                <button type="submit" name="result" value="failed" formnovalidate class="btn-bad">Payment failed</button>
            </form>
        </div>
    <?php endforeach; ?>

<?php else: ?>
    <p class="note">
        Approved requests waiting for a recharge, then receipts waiting to be checked.
        The reason for a request isn't shown here; the review already happened.
        The amount shown is what the reviewer approved. If the operator's real price is different, enter what you actually recharged and give a reason: the applicant is told.
    </p>

    <h2 class="sec-title">1. To recharge <span class="pill"><?= count($rechargeQueue) ?></span></h2>
    <?php if (empty($rechargeQueue)): ?><div class="empty-note">Nothing awaiting recharge.</div><?php endif; ?>

    <?php foreach ($rechargeQueue as $req): ?>
        <?php $approved = (int) $req['approved_paise']; $requested = (int) $req['package_price_paise']; ?>
        <div class="qcard pay">
            <div class="qhead">
                <h3><?= $e($req['applicant_name']) ?> <code>#<?= $e($req['tracking_id']) ?></code></h3>
                <span class="qamount" title="Approved amount"><?= $e(rupees_label($approved)) ?></span>
            </div>

            <?php if ($req['phone_error']): ?>
                <div class="alert error">
                    <strong>The phone number on this request can't be read</strong> (it was stored damaged),
                    so it can't be recharged. Mark it as failed with a note. A reviewer can then reject it,
                    and the volunteer can submit a new request straight away.
                </div>
            <?php else: ?>
                <div class="kv">
                    <div><span>Mobile number</span><strong class="big"><?= $e($req['phone']) ?></strong></div>
                    <div><span>Operator</span><strong><?= $e($req['operator']) ?></strong></div>
                    <div><span>Pack</span><strong><?= $e($req['package_name']) ?><?= $req['package_validity_days'] ? ' · ' . (int) $req['package_validity_days'] . ' days' : '' ?></strong></div>
                    <?php if ($approved !== $requested): ?>
                        <div><span>Applicant stated</span><strong><?= $e(rupees_label($requested)) ?></strong></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p class="qmeta">
                The applicant stated this pack and price<?= $approved !== $requested ? '; the reviewer approved a different amount' : '' ?>.
                Confirm the operator's actual price before recharging. If you recharge a different amount than approved,
                enter it below and give a reason.<br>
                Approved by <?= $e($req['decided_by']) ?> on <?= $e($req['decided_at']) ?> UTC
            </p>

            <?php internal_notes_block('internet', (int) $req['id'], $noteModel->forRequest('internet', (int) $req['id']), 'result', 'internet'); ?>

            <form method="POST" class="qform">
                <?= CSRF::getInputField() ?>
                <?= CSRF::getSubmitField() ?>
                <input type="hidden" name="queue" value="internet">
                <input type="hidden" name="tab" value="internet">
                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                <?php if (!$req['phone_error']): ?>
                    <!-- Required for "Recharge done"; "Recharge failed" has formnovalidate. -->
                    <input type="text" name="recharge_reference" required maxlength="255" placeholder="Operator reference / transaction ID (required when done)">
                    <input type="text" name="paid_amount" required inputmode="decimal" value="<?= $e(paise_to_rupees($approved)) ?>" placeholder="Amount you recharged (₹)" title="Amount you recharged (₹). Starts as the approved amount.">
                    <input type="text" name="amount_note" maxlength="500" placeholder="Reason, only if this differs from the approved amount (the applicant sees it)">
                <?php endif; ?>
                <textarea name="notes" placeholder="Notes (required if recharge failed)"></textarea>
                <?php if (!$req['phone_error']): ?>
                    <button type="submit" name="result" value="done" class="btn-ok">Recharge done</button>
                <?php endif; ?>
                <button type="submit" name="result" value="failed" formnovalidate class="btn-bad">Recharge failed</button>
            </form>
        </div>
    <?php endforeach; ?>

    <h2 class="sec-title">2. Receipts to check <span class="pill" style="--tone:#c2410c;"><?= count($receiptQueue) ?></span></h2>
    <?php if (empty($receiptQueue)): ?><div class="empty-note">No receipts waiting.</div><?php endif; ?>

    <?php foreach ($receiptQueue as $req): ?>
        <?php $paid = (int) $req['paid_paise']; $approved = (int) $req['approved_paise']; ?>
        <div class="qcard check">
            <div class="qhead">
                <h3><?= $e($req['applicant_name']) ?> <code>#<?= $e($req['tracking_id']) ?></code></h3>
                <span class="qamount" title="Amount recharged"><?= $e(rupees_label($paid)) ?></span>
            </div>

            <div class="kv">
                <div><span>Mobile number</span><strong><?= $req['phone_error'] ? '(phone unreadable)' : $e($req['phone']) ?></strong></div>
                <div><span>Operator / pack</span><strong><?= $e($req['operator']) ?> — <?= $e($req['package_name']) ?></strong></div>
                <div><span>Recharge reference</span><strong><?= $e((string) $req['recharge_reference']) ?></strong></div>
                <?php if ($paid !== $approved): ?>
                    <div><span>Approved amount</span><strong><?= $e(rupees_label($approved)) ?></strong></div>
                <?php endif; ?>
            </div>

            <div class="qlinks">
                <?php if ($req['receipt_path'] !== ''): ?>
                    <a href="/<?= $e($req['receipt_path']) ?>" target="_blank" download>⬇ Download receipt</a>
                <?php else: ?>
                    <span style="color:var(--muted);">Receipt file is missing.</span>
                <?php endif; ?>
                <span style="color:var(--muted);">Uploaded <?= $e((string) $req['receipt_submitted_at']) ?> UTC</span>
            </div>

            <?php internal_notes_block('internet', (int) $req['id'], $noteModel->forRequest('internet', (int) $req['id']), 'result', 'internet'); ?>

            <form method="POST" class="qform">
                <?= CSRF::getInputField() ?>
                <?= CSRF::getSubmitField() ?>
                <input type="hidden" name="queue" value="internet">
                <input type="hidden" name="tab" value="internet">
                <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                <textarea name="notes" placeholder="Notes (required to send the receipt back; the applicant sees them)"></textarea>
                <label class="confirm">
                    <input type="checkbox" name="receipt_downloaded" value="1">
                    I have downloaded the receipt; OK to delete it from the server
                </label>
                <button type="submit" name="result" value="close" class="btn-ok">Verify &amp; close</button>
                <button type="submit" name="result" value="bounce" class="btn-bad">Send receipt back</button>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>
<?php engage_footer(); ?>
