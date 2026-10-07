<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/amount_helpers.php';
require_once __DIR__ . '/../../includes/internal_notes_ui.php';
require_once __DIR__ . '/../../models/InternetSupportModel.php';
require_once __DIR__ . '/../../models/InternalNoteModel.php';

// Support reviewers decide whether a request is reasonable. Owners are
// trusted to do the same. This page never selects the phone number (see
// InternetSupportModel::listForReview()); finance is the only role that sees it.
//
// A reviewer may approve a DIFFERENT amount than the applicant asked for. A
// reason is then required and the applicant is told. Finance later sees the
// approved amount and may still adjust it when recharging.
requireRole(['support_reviewer', 'owner']);

$model = new InternetSupportModel();
$noteModel = new InternalNoteModel();
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

    if ($decision === 'internal_note') {
        // Staff-only; never shown to the applicant.
        try {
            $noteModel->add('internet', $requestId, Auth::email(), $_POST['internal_note'] ?? '');
            AuditLog::record('internet.internal_note', Auth::id(), Auth::email(), null, "Request #$requestId");
            $message = 'Internal note added.';
        } catch (\InvalidArgumentException $ex) {
            $error = $ex->getMessage();
        }
    } elseif ($decision === 'approve') {
        $approvedPaise  = rupees_to_paise($_POST['approved_amount'] ?? '');
        $requestedPaise = $model->requestedAmountPaise($requestId);
        $settings       = $model->getSettings();
        $maxPaise       = $settings ? (int) $settings['max_amount_paise'] : 0;
        $amountNote     = trim($_POST['amount_note'] ?? '');
        $differs        = $approvedPaise !== null && $approvedPaise !== $requestedPaise;
        $approvedNote   = $differs ? $amountNote : '';

        if ($approvedPaise === null) {
            $error = "Enter the amount to approve, in ₹ (for example 299 or 299.50).";
        } elseif ($maxPaise > 0 && $approvedPaise > $maxPaise) {
            $error = "That is above the programme limit of " . rupees_label($maxPaise) . ".";
        } elseif ($differs && $amountNote === '') {
            // The applicant reads this as a message from "DCW reviewer".
            $error = "You are approving a different amount than requested (" . rupees_label($requestedPaise) . "). Add a reason: the applicant will see it (without your name).";
        } elseif (mb_strlen($amountNote) > 500) {
            $error = "The reason is too long (500 characters max).";
        } elseif ($model->approve($requestId, Auth::email(), $approvedPaise, $approvedNote)) {
            $info = $model->getForNotification($requestId);
            AuditLog::record(
                'internet.approved', Auth::id(), Auth::email(), $info['email'],
                'Tracking: ' . $info['tracking_id'] . ' | Approved ' . rupees_label($approvedPaise)
                . ($differs ? ' (requested ' . rupees_label($requestedPaise) . ' | Reason: ' . $approvedNote . ')' : '')
            );
            Mailer::sendInternetStatusUpdate(
                $info['email'], $info['applicant_name'], $info['tracking_id'],
                'Approved for Support', '', '', $approvedPaise, $approvedNote
            );
            $message = "Request {$info['tracking_id']} approved for " . rupees_label($approvedPaise) . " and passed to finance."
                . ($differs ? " This differs from the requested " . rupees_label($requestedPaise) . "; the applicant has been told why." : '');
        } else {
            $error = "That request was already handled by someone else, or is waiting on the applicant's reply.";
        }
    } elseif ($decision === 'request_info') {
        if ($notes === '') {
            // The applicant reads this as a message from "DCW reviewer".
            $error = "Write your question in the notes box. The applicant will see it (without your name).";
        } else {
            try {
                if ($model->requestInfo($requestId, Auth::email(), $notes)) {
                    $info = $model->getForNotification($requestId);
                    AuditLog::record('internet.info_requested', Auth::id(), Auth::email(), $info['email'], 'Tracking: ' . $info['tracking_id'] . ' | ' . $notes);
                    // The email only nudges them to the tracking page; the question stays behind tracking ID + email.
                    Mailer::sendInternetStatusUpdate($info['email'], $info['applicant_name'], $info['tracking_id'], 'Info Requested');
                    $message = "Request {$info['tracking_id']} sent back to the applicant for more information. It returns to the queue when they reply.";
                } else {
                    $error = "That request was already handled by someone else.";
                }
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
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
$failed  = array_filter($requests, function ($r) { return $r['status'] === 'Recharge Failed'; });
$fresh   = array_filter($requests, function ($r) { return $r['status'] === 'Submitted'; });
$waiting = array_filter($requests, function ($r) { return $r['status'] === 'Info Requested'; });

function internet_review_card(array $req, InternetSupportModel $model, InternalNoteModel $noteModel) {
    $yn = function ($v) {
        return $v === null ? '—' : ((int) $v === 1 ? 'Yes' : 'No');
    };
    // Qualified only through the technical-contributor route: worth a closer look.
    $techOnly = $req['tech_contributor'] !== null && (int) $req['tech_contributor'] === 1
        && ((int) $req['edits_80'] !== 1 || (int) $req['attended_ch'] !== 1);
    $wikiUser = (string) ($req['wikimedia_username'] ?? '');
    $thread = $model->getMessagesForReview((int) $req['id']);
    $internalNotes = $noteModel->forRequest('internet', (int) $req['id']);
    // The amount box starts at the earlier approval (a re-approval after a failed recharge), else the request.
    $requestedPaise = (int) $req['package_price_paise'];
    $prefillPaise = $req['approved_amount_paise'] !== null ? (int) $req['approved_amount_paise'] : $requestedPaise;
    // Left-edge colour of the card: red = recharge failed, blue = waiting on the applicant.
    $tone = $req['status'] === 'Recharge Failed' ? '#b91c1c'
        : ($req['status'] === 'Info Requested' ? '#1e40af' : 'var(--primary)');
    ?>
    <article class="qcard" style="--tone:<?= $tone ?>;">
        <div class="qhead">
            <h3><?= htmlspecialchars($req['applicant_name']) ?> <code>#<?= htmlspecialchars($req['tracking_id']) ?></code></h3>
            <span class="qamount" title="Amount requested">₹<?= number_format($requestedPaise / 100, 2) ?></span>
        </div>
        <p class="qmeta">
            <?= htmlspecialchars($req['email']) ?> · submitted <?= htmlspecialchars($req['created_at']) ?> UTC
        </p>

        <div class="kv">
            <div>
                <span>Wikimedia username</span>
                <strong>
                    <?php if ($wikiUser !== ''): ?>
                        <a href="https://meta.wikimedia.org/wiki/Special:CentralAuth/<?= rawurlencode($wikiUser) ?>" target="_blank" rel="noopener" style="color:var(--primary);"><?= htmlspecialchars($wikiUser) ?></a>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </strong>
            </div>
            <div>
                <span>Package</span>
                <strong><?= htmlspecialchars($req['operator']) ?> — <?= htmlspecialchars($req['package_name']) ?><?= $req['package_validity_days'] ? ' · ' . (int) $req['package_validity_days'] . ' days' : '' ?></strong>
            </div>
            <div>
                <span>80+ manual edits last month</span>
                <strong><?= $yn($req['edits_80']) ?></strong>
            </div>
            <div>
                <span>Attended last 3 Conversation Hours</span>
                <strong><?= $yn($req['attended_ch']) ?></strong>
            </div>
            <div>
                <span>Active on DCW technical projects</span>
                <strong><?= $yn($req['tech_contributor']) ?></strong>
            </div>
            <div>
                <span>Earlier requests recharged</span>
                <strong<?= $req['prior_recharges'] > 0 ? ' style="color:#b45309;"' : '' ?>><?= (int) $req['prior_recharges'] ?></strong>
            </div>
        </div>
        <p class="qmeta">
            Eligibility answers are self-declared: spot-check against XTools or attendance sheets.
            The pack and price are what the applicant typed, not checked against the operator.
        </p>

        <?php if ($techOnly): ?>
            <div class="action-banner">
                Qualified via technical contribution only. Worth confirming they are active on DCW technical projects.
            </div>
        <?php endif; ?>

        <p class="qlabel">Why they need support</p>
        <p class="qtext"><?= nl2br(htmlspecialchars($req['reason'])) ?></p>

        <?php if ($req['contributions'] !== null): ?>
            <p class="qlabel">Contributions in the last three months</p>
            <p class="qtext"><?= nl2br(htmlspecialchars($req['contributions'])) ?></p>
        <?php endif; ?>

        <?php if ($req['plans'] !== null): ?>
            <p class="qlabel">Plans for the support period</p>
            <p class="qtext"><?= nl2br(htmlspecialchars($req['plans'])) ?></p>
        <?php endif; ?>

        <?php if ($req['status'] === 'Recharge Failed'): ?>
            <div class="alert error">
                <strong>Finance couldn't complete the recharge:</strong><br>
                <?= nl2br(htmlspecialchars((string) $req['recharge_notes'])) ?>
            </div>
        <?php endif; ?>

        <?php if ($req['status'] === 'Submitted' && !empty($req['sent_back_note'])): ?>
            <div class="action-banner">
                <strong>Finance sent this back for another look:</strong>
                <?= nl2br(htmlspecialchars((string) $req['sent_back_note'])) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($thread)): ?>
            <p class="qlabel">Conversation with applicant</p>
            <div class="thread">
                <?php foreach ($thread as $m): ?>
                    <div class="msg">
                        <div class="msg-meta">
                            <?php if ($m['sender'] === 'reviewer'): ?>
                                Reviewer (<?= htmlspecialchars((string) $m['author']) ?>) — the applicant sees "DCW reviewer"
                            <?php else: ?>
                                Applicant
                            <?php endif; ?>
                            · <?= htmlspecialchars($m['created_at']) ?> UTC
                        </div>
                        <?= nl2br(htmlspecialchars($m['body'])) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($req['status'] === 'Info Requested'): ?>
            <div class="info-banner">
                Waiting for the applicant's reply. This request returns to "New requests" when they answer. You can still reject or discard it if they never respond.
            </div>
        <?php endif; ?>

        <?php internal_notes_block('internet', (int) $req['id'], $internalNotes, 'decision'); ?>

        <form method="POST" class="qform">
            <?= CSRF::getInputField() ?>
            <?= CSRF::getSubmitField() ?>
            <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
            <?php if ($req['status'] !== 'Info Requested'): ?>
                <p class="qlabel">Amount to approve (₹): starts as <?= $req['approved_amount_paise'] !== null ? 'the earlier approval' : 'what the applicant asked for' ?></p>
                <input type="text" name="approved_amount" inputmode="decimal" value="<?= htmlspecialchars(paise_to_rupees($prefillPaise)) ?>" placeholder="Amount to approve (₹)">
                <input type="text" name="amount_note" maxlength="500" placeholder="Reason, only if this differs from the requested ₹<?= number_format($requestedPaise / 100, 2) ?> (the applicant sees it)">
            <?php endif; ?>
            <textarea name="notes" placeholder="Notes. Required to reject or to request info: the applicant sees them (reviewer name hidden). Optional for discard: internal only."></textarea>
            <?php if ($req['status'] !== 'Info Requested'): ?>
                <button type="submit" name="decision" value="approve" class="btn-ok">Approve</button>
            <?php endif; ?>
            <?php if ($req['status'] === 'Submitted'): ?>
                <button type="submit" name="decision" value="request_info" class="btn-info">Request info</button>
            <?php endif; ?>
            <button type="submit" name="decision" value="reject" class="btn-bad">Reject</button>
            <button type="submit" name="decision" value="discard" class="btn-mute"
                    onclick="return confirm('Discard silently? The applicant will NOT be told and the request will vanish from their tracking page.');">Discard</button>
        </form>
    </article>
    <?php
}

engage_header([
    'title'   => 'Internet support review',
    'heading' => 'Internet support review',
    'kicker'  => 'Organizer workspace',
    'lead'    => "Decide whether each request is reasonable, and for how much. Phone numbers aren't shown here; finance sees them when doing the recharge.",
    'tools'   => '',
    'wide'    => true,
    'crumbs'  => [['Workspace', '/admin/dashboard'], ['Internet support review']],
]);
?>
<style>
    /* Internet review only. Everything else comes from /assets/css/engage.css */
    .sect { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 22px 24px 8px; margin: 0 0 26px; box-shadow: 0 16px 34px rgba(15,23,42,.12); }
    .qlabel { margin: 0 0 4px; font-size: 14px; font-weight: 700; }
    .qtext { margin: 0 0 14px; padding: 10px 12px; background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; font-size: 14px; }
    .thread { margin: 0 0 14px; padding: 10px 12px; background: #fff; border: 1px solid var(--border); border-radius: 10px; font-size: 14px; }
    .thread .msg { margin-bottom: 10px; }
    .thread .msg:last-child { margin-bottom: 0; }
    .msg-meta { font-size: 12px; color: var(--muted); }
    .info-banner { margin: 0 0 14px; padding: 10px 14px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; color: #1e40af; font-size: 14px; }
    .qcard .alert { margin: 0 0 14px; }
    .qcard .action-banner { margin: 0 0 14px; }
    .btn-info, .btn-mute {
        padding: 11px 22px; border: none; border-radius: 999px; cursor: pointer; font: inherit; font-size: 14.5px; font-weight: 700; color: #fff;
        transition: transform .15s, box-shadow .15s;
    }
    .btn-info { background: linear-gradient(135deg, var(--primary-dark), var(--primary)); box-shadow: 0 5px 14px rgba(16,107,154,.3); }
    .btn-mute { background: linear-gradient(135deg, #475569, #64748b); box-shadow: 0 5px 14px rgba(71,85,105,.25); }
    .btn-info:hover, .btn-mute:hover { transform: translateY(-2px); }
</style>

<?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!empty($failed)): ?>
    <section class="sect">
        <h2 class="sec-title" style="color:#b91c1c;">Recharge failed — needs attention</h2>
        <?php foreach ($failed as $req) { internet_review_card($req, $model, $noteModel); } ?>
    </section>
<?php endif; ?>

<section class="sect">
    <h2 class="sec-title">New requests <span class="pill"><?= count($fresh) ?></span></h2>
    <?php if (empty($fresh)): ?>
        <div class="empty-note" style="margin-bottom:16px;">Nothing waiting for review.</div>
    <?php endif; ?>
    <?php foreach ($fresh as $req) { internet_review_card($req, $model, $noteModel); } ?>
</section>

<?php if (!empty($waiting)): ?>
    <section class="sect">
        <h2 class="sec-title" style="color:#1e40af;">Waiting for applicant's reply</h2>
        <?php foreach ($waiting as $req) { internet_review_card($req, $model, $noteModel); } ?>
    </section>
<?php endif; ?>
<?php engage_footer(); ?>
