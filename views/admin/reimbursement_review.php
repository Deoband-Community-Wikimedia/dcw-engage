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
// A reviewer may approve MORE or LESS than the applicant claimed (for example
// when the claim was mistyped against the receipt). Any difference needs a
// reason, and the applicant is told. Finance later sees the approved amount
// and may still adjust it when paying.
//
// A reviewer may also SEND A CLAIM BACK to the applicant with a question. The
// applicant fixes it on their member page and resubmits. If they do not reply
// within ReimbursementModel::INFO_EXPIRY_DAYS days, bin/expire_info_requests.php
// closes it as unsuccessful.
requireRole(['support_reviewer', 'owner']);

$reimbursementModel = new ReimbursementModel();
$noteModel = new InternalNoteModel();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    // Double-submit guard: the first click consumes the token, a second is dropped.
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
        try {
            $noteModel->add('reimbursement', $requestId, Auth::email(), $_POST['internal_note'] ?? '');
            AuditLog::record('reimbursement.internal_note', Auth::id(), Auth::email(), null, "Request #$requestId");
            $message = 'Internal note added.';
        } catch (\InvalidArgumentException $ex) {
            $error = $ex->getMessage();
        }

    } elseif ($decision === 'rename_event') {
        try {
            $result = $reimbursementModel->renameEvent($requestId, $_POST['event_name'] ?? '');
            if ($result === null) {
                $error = "That request can't be renamed any more (it was already decided, paid or closed).";
            } elseif (!$result['changed']) {
                $message = 'The event name is already that.';
            } else {
                $info = $reimbursementModel->getForNotification($requestId);
                AuditLog::record(
                    'reimbursement.event_renamed', Auth::id(), Auth::email(), $info['email'],
                    'Tracking: ' . $info['tracking_id'] . ' | "' . $result['old'] . '" -> "' . $result['new'] . '"'
                );
                $message = "Event name for request #$requestId changed to \"{$result['new']}\".";
            }
        } catch (\InvalidArgumentException $ex) {
            $error = $ex->getMessage();
        }

    } elseif ($decision === 'request_info') {
        // Send the claim back to the applicant to fix and resubmit.
        try {
            if ($reimbursementModel->requestInfo($requestId, Auth::email(), $_POST['question'] ?? '')) {
                $info = $reimbursementModel->getForNotification($requestId);
                AuditLog::record(
                    'reimbursement.sent_back_to_applicant', Auth::id(), Auth::email(), $info['email'],
                    'Tracking: ' . $info['tracking_id']
                );
                require_once __DIR__ . '/../../includes/mailer.php';
                // The question itself is never emailed; ReimbursementMail only nudges them to sign in.
                Mailer::sendReimbursementStatusUpdate(
                    $info['email'], $info['applicant_name'], $info['tracking_id'],
                    $info['event_name'], 'Info Requested'
                );
                $message = "Request #$requestId sent back to the applicant. It closes automatically if they don't reply within "
                    . ReimbursementModel::INFO_EXPIRY_DAYS . " days.";
            } else {
                $error = "That request was already handled by someone else, or is already waiting on the applicant.";
            }
        } catch (\InvalidArgumentException $ex) {
            $error = $ex->getMessage();
        }

    } elseif ($decision === 'approve') {
        $approvedPaise = rupees_to_paise($_POST['approved_amount'] ?? '');
        $claimedPaise  = $reimbursementModel->claimedAmountPaise($requestId);
        $amountNote    = trim($_POST['amount_note'] ?? '');
        $differs       = $approvedPaise !== null && $approvedPaise !== $claimedPaise;
        $higher        = $differs && $approvedPaise > $claimedPaise;
        $approvedNote  = $differs ? $amountNote : '';

        if ($approvedPaise === null) {
            $error = "Enter the amount to approve, in ₹ (for example 250 or 250.50).";
        } elseif ($differs && $amountNote === '') {
            $error = "You are approving a different amount than claimed (" . rupees_label($claimedPaise) . "). Add a reason: the applicant will see it.";
        } elseif (mb_strlen($amountNote) > 500) {
            $error = "The reason is too long (500 characters max).";
        } elseif ($reimbursementModel->exceedsUpiLimit($requestId, $approvedPaise)) {
            $error = "That amount is above the " . rupees_label(ReimbursementModel::UPI_MAX_PAISE)
                . " limit for this claim's payout method, so it can't be approved. Approve up to "
                . rupees_label(ReimbursementModel::UPI_MAX_PAISE) . ", or send it back to the applicant.";
        } elseif ($reimbursementModel->approveForPayment($requestId, Auth::email(), $approvedPaise, $approvedNote)) {
            $info = $reimbursementModel->getForNotification($requestId);
            AuditLog::record(
                'reimbursement.approved', Auth::id(), Auth::email(), $info['email'],
                'Tracking: ' . $info['tracking_id'] . ' | Approved ' . rupees_label($approvedPaise)
                . ($differs ? ' (claimed ' . rupees_label($claimedPaise) . ($higher ? ', raised' : ', reduced') . ' | Reason: ' . $approvedNote . ')' : '')
            );
            require_once __DIR__ . '/../../includes/mailer.php';
            Mailer::sendReimbursementStatusUpdate(
                $info['email'], $info['applicant_name'], $info['tracking_id'],
                $info['event_name'], 'Approved for Payment', '', '', $approvedPaise, $approvedNote
            );
            $message = "Request #$requestId approved for " . rupees_label($approvedPaise) . ". It now moves to the finance queue."
                . ($differs
                    ? " This is " . ($higher ? "more" : "less") . " than the claimed " . rupees_label($claimedPaise) . "; the applicant has been told why."
                    : '');
        } else {
            $error = "That request was already handled by someone else, or is waiting on the applicant's reply.";
        }

    } elseif ($decision === 'reject') {
        // Substance-based: the applicant gets an email. Also allowed while waiting on the applicant.
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
        // Silent by design: no Mailer call (see ReimbursementModel::discard()).
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
$requests = array_merge($pending, $underReview);
// Sent back to the applicant; waiting for their reply (auto-closes after INFO_EXPIRY_DAYS).
$waiting = $reimbursementModel->listForAdminReview('Info Requested');
// Payment Failed lands back here, not with the applicant — see the
// CALLER CONTRACT note on Mailer::sendReimbursementStatusUpdate().
$paymentFailed = $reimbursementModel->listForAdminReview('Payment Failed');

$h = fn($v) => htmlspecialchars((string) $v);

/**
 * One claim card: header, line items, conversation, and the action forms.
 *
 * $mode:
 *   'review'  - normal card: approve / reject / discard, plus send back and rename
 *   'waiting' - sent back to the applicant: only reject / discard (and rename)
 *   'failed'  - payment failed: re-approve / reject / discard
 *
 * Uses the shared queue-card look (.qcard, .qhead, .qmeta, .tbl, .field, .btn-ok/.btn-bad/.btn-neutral)
 * from assets/css/engage.css.
 */
function reimbursement_card(array $req, string $mode, callable $h, InternalNoteModel $noteModel): void
{
    $failed  = $mode === 'failed';
    $waiting = $mode === 'waiting';

    // The amount box starts at the earlier approval (a re-approval after a failed payment), else the full claim.
    $claimedPaise = (int) $req['total_amount_paise'];
    $prefillPaise = $req['approved_amount_paise'] !== null ? (int) $req['approved_amount_paise'] : $claimedPaise;
    $internalNotes = $noteModel->forRequest('reimbursement', (int) $req['id']);
    $messages = $req['messages'] ?? [];

    // When the applicant was last asked something, and when that lapses.
    $askedAt = null;
    foreach ($messages as $m) {
        if ($m['sender'] === 'reviewer') {
            $askedAt = $m['created_at'];
        }
    }
    $closesOn = $askedAt
        ? date('j M Y', strtotime($askedAt . ' +' . ReimbursementModel::INFO_EXPIRY_DAYS . ' days'))
        : null;

    $tone = $failed ? 'var(--accent)' : ($waiting ? 'var(--amber, #b45309)' : 'var(--primary)');
    $pillText = $failed ? 'Payment failed' : ($waiting ? 'Waiting on applicant' : 'Awaiting review');
    ?>
    <section class="qcard"<?= $failed ? ' style="--tone: var(--accent);"' : '' ?>>
        <div class="qhead">
            <h3><?= $h($req['applicant_name']) ?></h3>
            <span class="qamount">Claimed ₹<?= number_format($claimedPaise / 100, 2) ?></span>
        </div>
        <div class="qlinks">
            <span class="pill" style="--tone: <?= $tone ?>;"><?= $pillText ?></span>
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

        <?php if ($waiting && $closesOn): ?>
            <div class="action-banner"><span><strong>Sent back to the applicant.</strong> If they don't reply, this closes automatically as unsuccessful on <?= $h($closesOn) ?>.</span></div>
        <?php endif; ?>

        <?php if ($mode === 'review' && !empty($req['sent_back_note'])): ?>
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

        <?php if (!empty($messages)): ?>
            <div class="field">
                <label>Conversation with the applicant (the applicant sees reviewers as "DCW reviewer")</label>
                <?php foreach ($messages as $m): ?>
                    <p class="note" style="margin:.25rem 0;">
                        <strong><?= $m['sender'] === 'reviewer' ? 'Reviewer' . (!empty($m['author']) ? ' (' . $h($m['author']) . ')' : '') : 'Applicant' ?></strong>
                        &middot; <?= $h(date('j M Y, g:i a', strtotime($m['created_at']))) ?><br>
                        <?= nl2br($h($m['body'])) ?>
                    </p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php internal_notes_block('reimbursement', (int) $req['id'], $internalNotes, 'decision'); ?>

        <?php if (!$failed): ?>
        <form method="POST" class="actform">
            <?= CSRF::getInputField() ?>
            <?= CSRF::getSubmitField() ?>
            <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
            <div class="field">
                <label>Event name (fix it if it was mistyped; the applicant will see the new name)</label>
                <input type="text" name="event_name" maxlength="255" value="<?= $h($req['event_name']) ?>">
            </div>
            <div class="actrow">
                <button type="submit" name="decision" value="rename_event" class="btn-neutral">Save event name</button>
            </div>
        </form>
        <?php endif; ?>

        <?php if ($mode === 'review'): ?>
        <form method="POST" class="actform">
            <?= CSRF::getInputField() ?>
            <?= CSRF::getSubmitField() ?>
            <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
            <div class="field">
                <label>Send back to the applicant to fix and resubmit. Your question (the applicant sees it after signing in; it is not emailed). They have <?= (int) ReimbursementModel::INFO_EXPIRY_DAYS ?> days to reply.</label>
                <textarea name="question" rows="2" minlength="5" maxlength="1000"></textarea>
            </div>
            <div class="actrow">
                <button type="submit" name="decision" value="request_info" class="btn-neutral">Send back for fixes</button>
            </div>
        </form>
        <?php endif; ?>

        <form method="POST" class="actform">
            <?= CSRF::getInputField() ?>
            <?= CSRF::getSubmitField() ?>
            <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
            <?php if (!$waiting): ?>
            <div class="field">
                <label>Amount to approve (₹): starts as <?= $req['approved_amount_paise'] !== null ? 'the earlier approval' : 'the full claim' ?>; you can approve more or less if the claim doesn't match the receipts</label>
                <input type="text" name="approved_amount" inputmode="decimal" value="<?= $h(paise_to_rupees($prefillPaise)) ?>">
            </div>
            <div class="field">
                <label>Reason, required if the amount differs from the claimed ₹<?= number_format($claimedPaise / 100, 2) ?> (the applicant sees it)</label>
                <input type="text" name="amount_note" maxlength="500">
            </div>
            <?php endif; ?>
            <div class="field">
                <label>Notes (required if rejecting: the applicant sees them. Optional if discarding: audit trail only)</label>
                <textarea name="notes" rows="2"></textarea>
            </div>
            <div class="actrow">
                <?php if (!$waiting): ?>
                    <button type="submit" name="decision" value="approve" class="btn-ok"><?= $failed ? 'Re-approve for payment' : 'Approve for payment' ?></button>
                <?php endif; ?>
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
    'lead'    => 'Approve or reject claims, and for how much, or send a claim back to the applicant to fix. Line items and receipts only, no payment details.',
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

<?php foreach ($requests as $req) { reimbursement_card($req, 'review', $h, $noteModel); } ?>

<?php if (!empty($waiting)): ?>
    <h2 class="sec-title">Sent back: waiting on the applicant</h2>
    <p class="note">
        These claims are with the applicant to fix and resubmit. They come back to the list above when
        the applicant replies. If there is no reply within <?= (int) ReimbursementModel::INFO_EXPIRY_DAYS ?> days,
        the claim is closed automatically as unsuccessful. You can still reject or discard one now.
    </p>
    <?php foreach ($waiting as $req) { reimbursement_card($req, 'waiting', $h, $noteModel); } ?>
<?php endif; ?>

<?php if (!empty($paymentFailed)): ?>
    <h2 class="sec-title" style="color: var(--accent);">Payment failed: needs attention</h2>
    <p class="note">
        Finance tried to pay these and it didn't go through. Check the note below,
        fix the payment details if needed (the applicant may need to resubmit
        correct bank/UPI details), then re-approve or reject.
    </p>
    <?php foreach ($paymentFailed as $req) { reimbursement_card($req, 'failed', $h, $noteModel); } ?>
<?php endif; ?>
<?php engage_footer(); ?>
