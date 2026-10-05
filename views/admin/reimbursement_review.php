<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';

// Support reviewers and owners review claim substance. Organizers only work
// with application forms, so they are not in this list. Finance is NOT in
// this list either: they get reimbursement_queue.php instead, which shows
// payment details, not claim substance.
requireRole(['support_reviewer', 'owner']);

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
        // Substance-based: the applicant plausibly belonged here, the claim
        // itself didn't hold up. They get an email — see ReimbursementModel::reject().
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
    } elseif (($_POST['decision'] ?? '') === 'discard') {
        // Never had a real claim to begin with — spam, unrelated email,
        // no connection to any event. Deliberately silent: no Mailer call,
        // by design, per ReimbursementModel::discard()'s docblock. $notes
        // here is internal-only context for the audit trail.
        if ($reimbursementModel->discard($requestId, Auth::email(), $notes ?: null)) {
            AuditLog::record('reimbursement.discarded', Auth::id(), Auth::email(), null, 'Request #' . $requestId . ($notes ? ' | ' . $notes : ''));
            $message = "Request #$requestId discarded. No email was sent.";
        }
    }
}

$pending = $reimbursementModel->listForAdminReview('Submitted');
$underReview = $reimbursementModel->listForAdminReview('Under Review');
// Payment Failed lands back here, not with the applicant — see the
// CALLER CONTRACT note on Mailer::sendReimbursementStatusUpdate(). A reviewer
// needs to either fix the payment details and re-approve, or reject outright.
$paymentFailed = $reimbursementModel->listForAdminReview('Payment Failed');
$requests = array_merge($pending, $underReview);

$h = fn($v) => htmlspecialchars((string) $v);

/** One claim card: header, line items, and the decision form. */
function reimbursement_card(array $req, bool $failed, callable $h): void
{
    ?>
    <section class="sect<?= $failed ? ' sect-failed' : '' ?>">
        <div class="qhead">
            <h3><?= $h($req['applicant_name']) ?></h3>
            <span class="pill <?= $failed ? 'st-bad' : 'st-new' ?>"><?= $failed ? 'Payment failed' : 'Awaiting review' ?></span>
            <?php if (!$failed && empty($req['previously_eligible'])): ?>
                <span class="pill st-wait">No prior acceptance or allowlist match</span>
            <?php endif; ?>
        </div>
        <p class="qmeta">
            <?= $h($req['email']) ?> &middot; #<?= $h($req['tracking_id']) ?> &middot;
            <?= $h($req['event_name']) ?>
            <?php if (!empty($req['event_date'])): ?>
                &middot; <?= $h(date('j M Y', strtotime($req['event_date']))) ?>
            <?php endif; ?>
        </p>

        <?php if ($failed && !empty($req['payment_notes'])): ?>
            <div class="action-banner"><span><strong>Finance's note:</strong> <?= $h($req['payment_notes']) ?></span></div>
        <?php endif; ?>

        <?php if (!$failed): ?>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr><th>Category</th><th>Description</th><th class="r">Amount</th><th>Receipt</th></tr>
                </thead>
                <tbody>
                <?php foreach ($req['line_items'] as $item): ?>
                    <tr>
                        <td><?= $h($item['category']) ?></td>
                        <td><?= $h($item['description']) ?></td>
                        <td class="r">₹<?= number_format($item['amount_paise'] / 100, 2) ?></td>
                        <td>
                            <?php if (!empty($item['receipt_path'])): ?>
                                <a class="dl" href="/<?= $h($item['receipt_path']) ?>" target="_blank" rel="noopener">View</a>
                            <?php else: ?>
                                <span style="color:#94a3b8;">None attached</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <p class="total">Total <strong>₹<?= number_format($req['total_amount_paise'] / 100, 2) ?></strong></p>

        <form method="POST" class="actform">
            <?= CSRF::getInputField() ?>
            <?= CSRF::getSubmitField() ?>
            <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
            <div class="field">
                <label>Notes (required if rejecting, optional if discarding)</label>
                <textarea name="notes" rows="2"></textarea>
            </div>
            <div class="actrow">
                <button type="submit" name="decision" value="approve" class="btn-ok"><?= $failed ? 'Re-approve for payment' : 'Approve for payment' ?></button>
                <button type="submit" name="decision" value="reject" class="btn-bad">Reject</button>
                <button type="submit" name="decision" value="discard" class="btn-ghost"
                        onclick="return confirm('Discard this request? No email will be sent to the applicant.');">Discard</button>
            </div>
        </form>
    </section>
    <?php
}

engage_header([
    'title'   => 'Reimbursement review',
    'heading' => 'Reimbursement review',
    'kicker'  => 'Organizer workspace',
    'lead'    => 'Approve or reject claims. Line items and receipts only, no payment details.',
    'tools'   => '',
    'wide'    => true,
    'crumbs'  => [['Workspace', '/admin/dashboard'], ['Reimbursement review']],
]);
?>
<style>
    /* Reimbursement review only. Everything else comes from /assets/css/engage.css */
    .sect { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 22px 24px; margin: 0 0 22px; box-shadow: 0 16px 34px rgba(15,23,42,.12); }
    .sect.sect-failed { border-color: #fecaca; background: #fffafa; }
    .sect textarea { width: 100%; box-sizing: border-box; }
    .qhead { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; }
    .qhead h3 { margin: 0; }

    .pill.st-new { --tone: var(--primary); }
    .pill.st-wait { --tone: #b45309; }
    .pill.st-bad { --tone: #b91c1c; }

    .tbl th.r, .tbl td.r { text-align: right; white-space: nowrap; }
    .total { margin: 14px 0 0; font-size: 15px; text-align: right; }
    .total strong { font-size: 18px; }

    .actform { margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--border); }
    .actform .field { margin-bottom: 12px; }
    .actrow { display: flex; flex-wrap: wrap; gap: 10px; }
    .actrow button { width: auto; }

    .failed-head { margin: 34px 0 6px; font-size: 20px; font-weight: 800; letter-spacing: -.02em; color: #b91c1c; }
</style>

<?php if ($message): ?><div class="alert ok"><?= $h($message) ?></div><?php endif; ?>

<?php if (empty($requests)): ?>
    <div class="empty-note">Nothing awaiting review.</div>
<?php endif; ?>

<?php foreach ($requests as $req) { reimbursement_card($req, false, $h); } ?>

<?php if (!empty($paymentFailed)): ?>
    <h2 class="failed-head">Payment failed: needs attention</h2>
    <p class="intro">
        Finance tried to pay these and it didn't go through. Check the note below,
        fix the payment details if needed (the applicant may need to resubmit
        correct bank/UPI details), then re-approve or reject.
    </p>
    <?php foreach ($paymentFailed as $req) { reimbursement_card($req, true, $h); } ?>
<?php endif; ?>
<?php engage_footer(); ?>
