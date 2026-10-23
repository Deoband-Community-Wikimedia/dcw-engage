<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/amount_helpers.php';
require_once __DIR__ . '/../../includes/internal_notes_ui.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';
require_once __DIR__ . '/../../models/InternalNoteModel.php';

// Support reviewers and owners review claim substance. Organizers only work
// with application forms, so they are not in this list. Finance is NOT in
// this list either: they get reimbursement_queue.php instead, which shows
// payment details, not claim substance.
//
// A reviewer may approve LESS than the applicant claimed (never more). A reason
// is then required and the applicant is told. Finance later sees the approved
// amount and may still adjust it when paying.
requireRole(['support_reviewer', 'owner']);

// Global now — one reimbursement form covers every event, so there's no
// per-event slug to look up (no more FormModel/ReimbursementFormModel here
// at all). Every open request across every event lands on this one page.
$reimbursementModel = new ReimbursementModel();
$noteModel = new InternalNoteModel();
$message = '';
$error = '';

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
    $decision = $_POST['decision'] ?? '';

    // Nobody decides on their own claim; another reviewer has to.
    $owner = $reimbursementModel->getForNotification($requestId);
    if ($owner && Auth::isOwnEmail($owner['email']) && $decision !== 'internal_note') {
        $error = "This is your own request, so another reviewer needs to decide on it.";
        $decision = '';
    }

    if ($decision === 'internal_note') {
        // Staff-only; never shown to the applicant.
        try {
            $noteModel->add('reimbursement', $requestId, Auth::email(), $_POST['internal_note'] ?? '');
            AuditLog::record('reimbursement.internal_note', Auth::id(), Auth::email(), null, "Request #$requestId");
            $message = 'Internal note added.';
        } catch (\InvalidArgumentException $ex) {
            $error = $ex->getMessage();
        }
    } elseif ($decision === 'approve') {
        $approvedPaise = rupees_to_paise($_POST['approved_amount'] ?? '');
        $claimedPaise  = $reimbursementModel->claimedAmountPaise($requestId);
        $amountNote    = trim($_POST['amount_note'] ?? '');
        $differs       = $approvedPaise !== null && $approvedPaise !== $claimedPaise;
        $approvedNote  = $differs ? $amountNote : '';

        if ($approvedPaise === null) {
            $error = "Enter the amount to approve, in ₹ (for example 250 or 250.50).";
        } elseif ($approvedPaise > $claimedPaise) {
            $error = "You can't approve more than the applicant claimed (" . rupees_label($claimedPaise) . ").";
        } elseif ($differs && $amountNote === '') {
            $error = "You are approving a different amount than claimed (" . rupees_label($claimedPaise) . "). Add a reason: the applicant will see it.";
        } elseif (mb_strlen($amountNote) > 500) {
            $error = "The reason is too long (500 characters max).";
        } elseif ($reimbursementModel->approveForPayment($requestId, Auth::email(), $approvedPaise, $approvedNote)) {
            $info = $reimbursementModel->getForNotification($requestId);
            AuditLog::record(
                'reimbursement.approved', Auth::id(), Auth::email(), $info['email'],
                'Tracking: ' . $info['tracking_id'] . ' | Approved ' . rupees_label($approvedPaise)
                . ($differs ? ' (claimed ' . rupees_label($claimedPaise) . ' | Reason: ' . $approvedNote . ')' : '')
            );
            require_once __DIR__ . '/../../includes/mailer.php';
            Mailer::sendReimbursementStatusUpdate(
                $info['email'], $info['applicant_name'], $info['tracking_id'],
                $info['event_name'], 'Approved for Payment', '', '', $approvedPaise, $approvedNote
            );
            $message = "Request #$requestId approved for " . rupees_label($approvedPaise) . ". It now moves to the finance queue."
                . ($differs ? " This is less than the claimed " . rupees_label($claimedPaise) . "; the applicant has been told why." : '');
        } else {
            $error = "That request was already handled by someone else, or is waiting on the applicant's reply.";
        }
    } elseif ($decision === 'reject') {
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
        } else {
            $error = "That request was already handled by someone else.";
        }
    } elseif ($decision === 'discard') {
        // Never had a real claim to begin with — spam, unrelated email,
        // no connection to any event. Deliberately silent: no Mailer call,
        // by design, per ReimbursementModel::discard()'s docblock. $notes
        // here is internal-only context for the audit trail.
        if ($reimbursementModel->discard($requestId, Auth::email(), $notes ?: null)) {
            AuditLog::record('reimbursement.discarded', Auth::id(), Auth::email(), null, 'Request #' . $requestId . ($notes ? ' | ' . $notes : ''));
            $message = "Request #$requestId discarded. No email was sent.";
        } else {
            $error = "That request was already handled by someone else.";
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

/**
 * One claim card: header, line items, and the decision form.
 * Uses the shared queue-card look (.qcard, .qhead, .qmeta, .tbl, .field, .btn-ok/.btn-bad/.btn-neutral) from
 * assets/css/engage.css. A failed payment turns the card's edge crimson via --tone.
 */
function reimbursement_card(array $req, bool $failed, callable $h, InternalNoteModel $noteModel): void
{
    // The amount box starts at the earlier approval (a re-approval after a failed payment), else the full claim.
    $claimedPaise = (int) $req['total_amount_paise'];
    $prefillPaise = $req['approved_amount_paise'] !== null ? (int) $req['approved_amount_paise'] : $claimedPaise;
    $internalNotes = $noteModel->forRequest('reimbursement', (int) $req['id']);
    ?>
    <section class="qcard"<?= $failed ? ' style="--tone: var(--accent);"' : '' ?>>
        <div class="qhead">
            <h3><?= $h($req['applicant_name']) ?></h3>
            <span class="qamount">Claimed ₹<?= number_format($claimedPaise / 100, 2) ?></span>
        </div>
        <div class="qlinks">
            <span class="pill" style="--tone: <?= $failed ? 'var(--accent)' : 'var(--primary)' ?>;"><?= $failed ? 'Payment failed' : 'Awaiting review' ?></span>
            <?php if (!$failed && empty($req['previously_eligible'])): ?>
                <span class="pill" style="--tone: var(--amber, #b45309);">No prior acceptance or allowlist match</span>
            <?php endif; ?>
        </div>
        <p class="qmeta">
            <?= $h($req['email']) ?> &middot; #<?= $h($req['tracking_id']) ?> &middot;
            <?= $h($req['event_name']) ?>
            <?php if (!empty($req['event_date'])): ?>
                &middot; <?= $h(date('j M Y', strtotime($req['event_date']))) ?>
            <?php endif; ?>
        </p>

        <?php if (!$failed && !empty($req['sent_back_note'])): ?>
            <div class="action-banner"><span><strong>Finance sent this back for another look:</strong> <?= $h($req['sent_back_note']) ?></span></div>
        <?php endif; ?>

        <?php if ($failed && !empty($req['payment_notes'])): ?>
            <div class="action-banner"><span><strong>Finance's note:</strong> <?= $h($req['payment_notes']) ?></span></div>
        <?php endif; ?>

        <?php if (!$failed): ?>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr><th>Category</th><th>Description</th><th class="num">Amount</th><th>Receipt</th></tr>
                </thead>
                <tbody>
                <?php foreach ($req['line_items'] as $item): ?>
                    <tr>
                        <td><?= $h($item['category']) ?></td>
                        <td><?= $h($item['description']) ?></td>
                        <td class="num">₹<?= number_format($item['amount_paise'] / 100, 2) ?></td>
                        <td>
                            <?php if (!empty($item['receipt_path'])): ?>
                                <a class="dl" href="<?= $h(engage_upload_url($item['receipt_path'])) ?>" target="_blank" rel="noopener">View</a>
                            <?php else: ?>
                                <span class="sub">None attached</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php internal_notes_block('reimbursement', (int) $req['id'], $internalNotes, 'decision'); ?>

        <form method="POST" class="actform">
            <?= CSRF::getInputField() ?>
            <?= CSRF::getSubmitField() ?>
            <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
            <div class="field">
                <label>Amount to approve (₹): starts as <?= $req['approved_amount_paise'] !== null ? 'the earlier approval' : 'the full claim' ?>; you can approve less, never more</label>
                <input type="text" name="approved_amount" inputmode="decimal" value="<?= $h(paise_to_rupees($prefillPaise)) ?>">
            </div>
            <div class="field">
                <label>Reason, only if this is less than the claimed ₹<?= number_format($claimedPaise / 100, 2) ?> (the applicant sees it)</label>
                <input type="text" name="amount_note" maxlength="500">
            </div>
            <div class="field">
                <label>Notes (required if rejecting: the applicant sees them. Optional if discarding: audit trail only)</label>
                <textarea name="notes" rows="2"></textarea>
            </div>
            <div class="actrow">
                <button type="submit" name="decision" value="approve" class="btn-ok"><?= $failed ? 'Re-approve for payment' : 'Approve for payment' ?></button>
                <button type="submit" name="decision" value="reject" class="btn-bad">Reject</button>
                <button type="submit" name="decision" value="discard" class="btn-neutral"
                        onclick="return confirm('Discard this request? No email will be sent to the applicant.');">Discard</button>
            </div>
        </form>
    </section>
    <?php
}

engage_header([
    'title'   => 'Reimbursement review',
    'heading' => 'Reimbursement review',
    'lead'    => 'Approve or reject claims, and for how much. Line items and receipts only, no payment details.',
    'slim'    => true,
    'wide'    => true,
    'crumbs'  => [['Workspace', '/admin/dashboard'], ['Reimbursement review']],
]);
?>

<?php if ($message): ?><div class="alert ok"><?= $h($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= $h($error) ?></div><?php endif; ?>

<?php if (empty($requests)): ?>
    <div class="empty-note">Nothing awaiting review.</div>
<?php endif; ?>

<?php foreach ($requests as $req) { reimbursement_card($req, false, $h, $noteModel); } ?>

<?php if (!empty($paymentFailed)): ?>
    <h2 class="sec-title" style="color: var(--accent);">Payment failed: needs attention</h2>
    <p class="note">
        Finance tried to pay these and it didn't go through. Check the note below,
        fix the payment details if needed (the applicant may need to resubmit
        correct bank/UPI details), then re-approve or reject.
    </p>
    <?php foreach ($paymentFailed as $req) { reimbursement_card($req, true, $h, $noteModel); } ?>
<?php endif; ?>
<?php engage_footer(); ?>
