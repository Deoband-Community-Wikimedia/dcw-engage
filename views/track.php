<?php
/**
 * Public "check my status" lookup (see #32) — covers applications
 * (tracking IDs start "DCW-"), reimbursement requests (start "RB-") and
 * internet support requests (start "IS-").
 *
 * Deliberately requires BOTH the tracking ID and the email an application
 * was submitted with — a tracking ID alone is unguessable (see
 * ApplicationModel::generateTrackingId()), but an applicant's email address
 * is often not a secret, so requiring the pair keeps a single leaked or
 * guessed value from being enough on its own to pull up someone's
 * application.
 *
 * Because an email is not a secret, results are limited to status
 * information. Reimbursements never show UPI / bank details; internet
 * support never shows the phone number.
 *
 * Writes allowed from this page (each re-checks the pair and the status):
 *   - internet support, 'Awaiting Receipt': attach a receipt
 *   - internet support / reimbursement, 'Info Requested': the request comes
 *     back as a DRAFT. The applicant sees the reviewer's question plus
 *     their own editable answers (the one place the reason, contributions
 *     and plans are shown, and only while the request is with them), edits
 *     them, adds a note, and resubmits to the review queue.
 *     Payment details, the phone number and uploaded receipts are never
 *     shown or changed from here.
 *
 * Session-based lockout mirrors Auth::attempt()'s login cooldown, so a
 * script trying to brute-force the tracking ID space (or spam this page)
 * gets slowed to a crawl the same way a login-guessing attempt would.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/engage_page.php';
require_once __DIR__ . '/../models/ApplicationModel.php';
require_once __DIR__ . '/../models/ReimbursementModel.php';
require_once __DIR__ . '/../models/InternetSupportModel.php';

const TRACK_MAX_ATTEMPTS = 5;
const TRACK_LOCKOUT_SECONDS = 900;

function trackLockoutRemaining() {
    $until = $_SESSION['track_locked_until'] ?? 0;
    if ($until <= time()) {
        if ($until) {
            unset($_SESSION['track_failures'], $_SESSION['track_locked_until']);
        }
        return 0;
    }
    return $until - time();
}

/** Remove a just-uploaded file whose database write didn't go through. */
function trackDiscardUpload($path) {
    if (is_string($path) && strpos($path, 'uploads/') === 0 && strpos($path, '..') === false) {
        $full = __DIR__ . '/../' . $path;
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

/** 'yes' / 'no' / '' for a stored 1 / 0 / NULL. */
function trackYesNo($v) {
    if ($v === null || $v === '') { return ''; }
    return (int) $v === 1 ? 'yes' : 'no';
}

$application = null;
$reimbursement = null;
$reimbursementModel = null;
$reimbursementThread = [];
$reimbDraft = null;
$postedReimb = null;
$internet = null;
$internetModel = null;
$internetThread = [];
$internetDraft = null;
$postedDraft = null;
$uploadMessage = '';
$error = '';
$trackingId = trim($_POST['tracking_id'] ?? '');
$email = trim($_POST['email'] ?? '');
$action = $_POST['action'] ?? 'lookup';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $wait = trackLockoutRemaining();
    if ($wait > 0) {
        $error = "Too many attempts. Try again in " . ceil($wait / 60) . " minute(s).";
    } elseif (empty($trackingId) || empty($email)) {
        $error = "Please enter both your tracking ID and the email you used.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Tracking IDs are always issued upper-case; normalize what was
        // typed so a lower-cased paste still matches.
        $trackingKey = strtoupper($trackingId);

        if (strpos($trackingKey, 'RB-') === 0) {
            $reimbursementModel = new ReimbursementModel();
            // The pair must match before anything else happens, including a resubmit.
            $reimbursement = $reimbursementModel->getStatusForApplicant($trackingKey, $email);

            if ($reimbursement && $action === 'resubmit_reimbursement') {
                $reply = trim($_POST['reply'] ?? '');
                $postedItems = [];
                $modelItems = [];
                $badAmount = false;
                foreach ((array) ($_POST['items'] ?? []) as $itemId => $row) {
                    if (!is_array($row)) { continue; }
                    $desc = trim((string) ($row['description'] ?? ''));
                    $amt  = trim((string) ($row['amount'] ?? ''));
                    $paise = ReimbursementModel::rupeesToPaise($amt);
                    if ($paise === null) { $badAmount = true; }
                    $postedItems[(int) $itemId] = ['description' => $desc, 'amount' => $amt];
                    $modelItems[(int) $itemId]  = ['description' => $desc, 'amount_paise' => $paise];
                }
                $postedReimb = [
                    'event_name' => trim((string) ($_POST['event_name'] ?? '')),
                    'event_date' => trim((string) ($_POST['event_date'] ?? '')),
                    'items'      => $postedItems,
                ];

                if ($reimbursement['status'] !== 'Info Requested') {
                    $error = "This request isn't waiting for your changes right now.";
                } elseif ($badAmount) {
                    $error = "Please enter every amount as a positive number, e.g. 250 or 250.50.";
                } else {
                    try {
                        if ($reimbursementModel->resubmitDraft($trackingKey, $email, $postedReimb['event_name'], $postedReimb['event_date'], $modelItems, $reply)) {
                            $uploadMessage = "Thank you. Your changes and note have been sent and the reviewer will look at your request again.";
                            $reimbursement = $reimbursementModel->getStatusForApplicant($trackingKey, $email);
                            $postedReimb = null;
                        } else {
                            $error = "This request isn't waiting for your changes right now.";
                        }
                    } catch (\InvalidArgumentException $ex) {
                        $error = $ex->getMessage();
                    } catch (\Exception $ex) {
                        app_log("Reimbursement resubmit failed for $trackingKey: " . $ex->getMessage());
                        $error = "Something went wrong saving your changes. Please try again.";
                    }
                }
            }

            // Load the draft and conversation only while the request is with the applicant.
            if ($reimbursement && $reimbursement['status'] === 'Info Requested') {
                $reimbursementThread = $reimbursementModel->getMessagesForApplicant($trackingKey, $email);
                $reimbDraft = $reimbursementModel->getDraftForApplicant($trackingKey, $email);
                if ($reimbDraft) {
                    foreach ($reimbDraft['items'] as &$it) {
                        $it['amount'] = number_format($it['amount_paise'] / 100, 2, '.', '');
                    }
                    unset($it);
                    if ($postedReimb) {
                        $reimbDraft['event_name'] = $postedReimb['event_name'];
                        $reimbDraft['event_date'] = $postedReimb['event_date'];
                        foreach ($reimbDraft['items'] as &$it) {
                            if (isset($postedReimb['items'][$it['id']])) {
                                $it['description'] = $postedReimb['items'][$it['id']]['description'];
                                $it['amount']      = $postedReimb['items'][$it['id']]['amount'];
                            }
                        }
                        unset($it);
                    }
                }
            }
        } elseif (strpos($trackingKey, 'IS-') === 0) {
            $internetModel = new InternetSupportModel();
            // The pair must match before anything else happens, including an upload or a resubmit.
            $internet = $internetModel->getStatusForApplicant($trackingKey, $email);

            if ($internet && $action === 'upload_receipt') {
                if ($internet['status'] !== 'Awaiting Receipt') {
                    $error = "This request isn't waiting for a receipt right now.";
                } else {
                    $path = null;
                    try {
                        require_once __DIR__ . '/../models/FileUploader.php';
                        // The tracking ID goes in the filename instead of a name: unique, and no personal data in the URL.
                        $path = (new FileUploader())->handleUpload($_FILES['receipt'] ?? [], 'receipt', $trackingKey, 'internet');
                        if (!$path) {
                            $error = "Please choose a file to upload.";
                        }
                    } catch (Exception $e) {
                        // FileUploader messages are written for the user (size, type, etc).
                        $error = $e->getMessage();
                    }

                    if ($path) {
                        try {
                            if ($internetModel->submitReceipt($trackingKey, $email, $path)) {
                                $uploadMessage = "Thank you. Your receipt has been uploaded and our finance team will check it.";
                                $internet = $internetModel->getStatusForApplicant($trackingKey, $email);
                            } else {
                                trackDiscardUpload($path);
                                $error = "This request isn't waiting for a receipt right now.";
                            }
                        } catch (Exception $e) {
                            trackDiscardUpload($path);
                            app_log("Internet support receipt save failed for $trackingKey: " . $e->getMessage());
                            $error = "Something went wrong saving your receipt. Please try again.";
                        }
                    }
                }
            } elseif ($internet && $action === 'resubmit_draft') {
                $reply = trim((string) ($_POST['reply'] ?? ''));
                $postedDraft = [
                    'wikimedia_username' => trim((string) ($_POST['wikimedia_username'] ?? '')),
                    'reason'             => trim((string) ($_POST['reason'] ?? '')),
                    'contributions'      => trim((string) ($_POST['contributions'] ?? '')),
                    'plans'              => trim((string) ($_POST['plans'] ?? '')),
                    'edits_80'           => (string) ($_POST['edits_80'] ?? ''),
                    'attended_ch'        => (string) ($_POST['attended_ch'] ?? ''),
                    'tech_contributor'   => (string) ($_POST['tech_contributor'] ?? ''),
                ];

                if ($internet['status'] !== 'Info Requested') {
                    $error = "This request isn't waiting for your changes right now.";
                } else {
                    try {
                        if ($internetModel->resubmitDraft($trackingKey, $email, $postedDraft, $reply)) {
                            $uploadMessage = "Thank you. Your changes and note have been sent and the reviewer will look at your request again.";
                            $internet = $internetModel->getStatusForApplicant($trackingKey, $email);
                            $postedDraft = null;
                        } else {
                            $error = "This request isn't waiting for your changes right now.";
                        }
                    } catch (\InvalidArgumentException $ex) {
                        $error = $ex->getMessage();
                    } catch (\Exception $ex) {
                        app_log("Internet support resubmit failed for $trackingKey: " . $ex->getMessage());
                        $error = "Something went wrong saving your changes. Please try again.";
                    }
                }
            }

            // Load the draft and conversation only while the request is with the applicant.
            if ($internet && $internet['status'] === 'Info Requested') {
                $internetThread = $internetModel->getMessagesForApplicant($trackingKey, $email);
                $internetDraft = $internetModel->getDraftForApplicant($trackingKey, $email);
                if ($internetDraft) {
                    foreach (['edits_80', 'attended_ch', 'tech_contributor'] as $k) {
                        $internetDraft[$k] = trackYesNo($internetDraft[$k]);
                    }
                    foreach (['wikimedia_username', 'contributions', 'plans'] as $k) {
                        $internetDraft[$k] = (string) ($internetDraft[$k] ?? '');
                    }
                    if ($postedDraft) {
                        $internetDraft = array_merge($internetDraft, $postedDraft);
                    }
                }
            }
        } else {
            $application = (new ApplicationModel())->getApplicationByTrackingIdAndEmail($trackingKey, $email);
        }

        if ($application || $reimbursement || $internet) {
            unset($_SESSION['track_failures'], $_SESSION['track_locked_until']);
        } else {
            // Same message either way — never reveal whether the tracking
            // ID or the email was the part that didn't match.
            $error = "No record found for that tracking ID and email address.";
            $_SESSION['track_failures'] = ($_SESSION['track_failures'] ?? 0) + 1;
            if ($_SESSION['track_failures'] >= TRACK_MAX_ATTEMPTS) {
                $_SESSION['track_locked_until'] = time() + TRACK_LOCKOUT_SECONDS;
            }
        }
    }
}

// Tracking is public; a signed-in member just gets their name in the top bar.
$member = null;
try {
    require_once __DIR__ . '/../includes/member_session.php';
    $member = MemberSession::current();
} catch (Throwable $ex) {
    $member = null;
}

engage_header([
    'title'   => 'Track your request',
    'heading' => 'Track your request',
    'kicker'  => 'Status check',
    'lead'    => 'Application IDs start with DCW-, reimbursement IDs with RB- and internet support IDs with IS-.',
    'member'  => $member,
    'crumbs'  => [['Home', '/'], ['Track your request']],
]);
$e = fn($s) => htmlspecialchars((string) $s);

/** Reviewer <-> applicant conversation. The reviewer is always just "DCW reviewer". */
$renderThread = function (array $thread) use ($e) {
    foreach ($thread as $m) {
        echo '<div style="margin-bottom:10px; padding:10px 12px; border:1px solid var(--border); border-radius:10px; font-size:14px;">'
           . '<div style="font-size:12px; color:var(--muted);">'
           . ($m['sender'] === 'reviewer' ? 'DCW reviewer' : 'You')
           . ' · ' . $e(date('F j, Y H:i', strtotime($m['created_at']))) . ' UTC</div>'
           . nl2br($e($m['body']))
           . '</div>';
    }
};
$yesNoSelect = function ($name, $val) use ($e) {
    $h = '<select name="' . $e($name) . '" required>';
    foreach (['' => '— Choose —', 'yes' => 'Yes', 'no' => 'No'] as $k => $label) {
        $h .= '<option value="' . $k . '"' . ((string) $val === (string) $k ? ' selected' : '') . '>' . $e($label) . '</option>';
    }
    return $h . '</select>';
};
$todayIst = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
?>
<div class="fcard">
    <?php if ($error): ?><div class="alert error"><strong>Notice:</strong> <?= $e($error) ?></div><?php endif; ?>
    <?php if ($uploadMessage): ?><div class="alert ok"><?= $e($uploadMessage) ?></div><?php endif; ?>

    <?php if ($application): ?>
        <div class="result">
            <h3><?= $e($application['form_title'] ?: 'Application') ?></h3>
            Tracking ID: <code><?= $e($application['tracking_id']) ?></code><br>
            Status: <strong><?= $e($application['status']) ?></strong><br>
            Submitted: <?= $e(date('F j, Y', strtotime($application['created_at']))) ?>
        </div>
    <?php endif; ?>

    <?php if ($reimbursement): ?>
        <div class="result">
            <h3>Reimbursement — <?= $e($reimbursement['event_name']) ?></h3>
            Tracking ID: <code><?= $e($reimbursement['tracking_id']) ?></code><br>
            Amount: ₹<?= number_format($reimbursement['total_amount_paise'] / 100, 2) ?><br>
            Status: <strong><?= $e($reimbursement['status']) ?></strong><br>
            <?php if ($reimbursement['status'] === 'Paid'): ?>
                <?php if (!empty($reimbursement['payment_reference'])): ?>Transaction reference: <strong><?= $e($reimbursement['payment_reference']) ?></strong><br><?php endif; ?>
                <?php if (!empty($reimbursement['paid_at'])): ?>Paid on: <?= $e(date('F j, Y', strtotime($reimbursement['paid_at']))) ?><br><?php endif; ?>
            <?php endif; ?>
            <?php if ($reimbursement['status'] === 'Rejected' && !empty($reimbursement['admin_notes'])): ?>
                Reviewer notes: <?= nl2br($e($reimbursement['admin_notes'])) ?><br>
            <?php endif; ?>
            Submitted: <?= $e(date('F j, Y', strtotime($reimbursement['created_at']))) ?>
        </div>

        <?php if ($reimbursement['status'] === 'Info Requested' && $reimbDraft): ?>
            <fieldset class="group" style="padding-bottom:18px;">
                <legend>Your request was sent back to you</legend>
                <p style="font-size:14px; color:var(--muted); margin-top:0;">
                    The reviewer needs more information. Read their message, correct your details below if needed,
                    add a short note, and resubmit. Your payment details and uploaded receipts are not shown here
                    and stay exactly as you submitted them.
                </p>
                <?php $renderThread($reimbursementThread); ?>
                <form method="POST">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="resubmit_reimbursement">
                    <input type="hidden" name="tracking_id" value="<?= $e($reimbursement['tracking_id']) ?>">
                    <input type="hidden" name="email" value="<?= $e($email) ?>">

                    <div class="field">
                        <label>Event name <span class="req-star">*</span></label>
                        <input type="text" name="event_name" maxlength="255" value="<?= $e($reimbDraft['event_name']) ?>" required>
                    </div>
                    <div class="field">
                        <label>Event date <span class="req-star">*</span></label>
                        <input type="date" name="event_date" max="<?= $e($todayIst) ?>" value="<?= $e($reimbDraft['event_date']) ?>" required>
                    </div>

                    <?php foreach ($reimbDraft['items'] as $n => $it): ?>
                        <div class="field">
                            <label>Expense <?= $n + 1 ?> — <?= $e($it['category']) ?></label>
                            <input type="text" name="items[<?= (int) $it['id'] ?>][description]" maxlength="<?= ReimbursementModel::MAX_ITEM_DESCRIPTION_LENGTH ?>" value="<?= $e($it['description']) ?>" placeholder="What was this for?" required>
                            <input type="text" inputmode="decimal" name="items[<?= (int) $it['id'] ?>][amount]" value="<?= $e($it['amount']) ?>" placeholder="Amount in ₹" required style="margin-top:6px;">
                        </div>
                    <?php endforeach; ?>

                    <div class="field">
                        <label>Note to the reviewer <span class="req-star">*</span></label>
                        <textarea name="reply" rows="4" maxlength="<?= ReimbursementModel::MAX_MESSAGE_LENGTH ?>" required placeholder="Answer the question above and/or say what you changed."><?= $e($_POST['reply'] ?? '') ?></textarea>
                    </div>
                    <button type="submit">Resubmit for review</button>
                </form>
            </fieldset>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($internet): ?>
        <div class="result">
            <h3>Internet support — <?= $e($internet['operator']) ?>, <?= $e($internet['package_name']) ?></h3>
            Tracking ID: <code><?= $e($internet['tracking_id']) ?></code><br>
            Amount requested: ₹<?= number_format($internet['package_price_paise'] / 100, 2) ?><br>
            Status: <strong><?= $e($internet['status']) ?></strong><br>
            <?php if ($internet['status'] === 'Rejected' && !empty($internet['admin_notes'])): ?>
                Reviewer notes: <?= nl2br($e($internet['admin_notes'])) ?><br>
            <?php endif; ?>
            <?php if (!empty($internet['recharge_reference']) && in_array($internet['status'], ['Awaiting Receipt', 'Receipt Submitted', 'Closed'], true)): ?>
                Recharge reference: <strong><?= $e($internet['recharge_reference']) ?></strong><br>
            <?php endif; ?>
            Submitted: <?= $e(date('F j, Y', strtotime($internet['created_at']))) ?>
        </div>

        <?php if ($internet['status'] === 'Awaiting Receipt'): ?>
            <fieldset class="group" style="padding-bottom:18px;">
                <legend>Upload your recharge receipt</legend>
                <?php if (!empty($internet['finance_notes'])): ?>
                    <div class="alert error">
                        <strong>Your last receipt wasn't accepted:</strong><br><?= nl2br($e($internet['finance_notes'])) ?>
                    </div>
                <?php endif; ?>
                <p style="font-size:14px; color:var(--muted); margin-top:0;">
                    Your number has been recharged. Please upload the operator's receipt or confirmation
                    (PDF, JPG or PNG, up to 10 MB) so we can close the request.
                </p>
                <form method="POST" enctype="multipart/form-data">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="upload_receipt">
                    <input type="hidden" name="tracking_id" value="<?= $e($internet['tracking_id']) ?>">
                    <input type="hidden" name="email" value="<?= $e($email) ?>">
                    <div class="field"><input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png" required></div>
                    <button type="submit">Upload receipt</button>
                </form>
            </fieldset>
        <?php elseif ($internet['status'] === 'Receipt Submitted'): ?>
            <p style="font-size:14px; color:var(--muted);">Your receipt is with our finance team. Nothing more is needed from you.</p>
        <?php elseif ($internet['status'] === 'Info Requested' && $internetDraft): ?>
            <?php $d = $internetDraft; ?>
            <fieldset class="group" style="padding-bottom:18px;">
                <legend>Your request was sent back to you</legend>
                <p style="font-size:14px; color:var(--muted); margin-top:0;">
                    The reviewer needs more information. Read their message, correct your answers below if needed,
                    add a short note, and resubmit. Your phone number and pack details are not shown here
                    and stay as you gave them.
                </p>
                <?php $renderThread($internetThread); ?>
                <form method="POST">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="resubmit_draft">
                    <input type="hidden" name="tracking_id" value="<?= $e($internet['tracking_id']) ?>">
                    <input type="hidden" name="email" value="<?= $e($email) ?>">

                    <div class="field">
                        <label>Wikimedia username <span class="req-star">*</span></label>
                        <input type="text" name="wikimedia_username" maxlength="255" value="<?= $e($d['wikimedia_username']) ?>" required>
                    </div>
                    <div class="field">
                        <label>80+ manual edits last month <span class="req-star">*</span></label>
                        <?= $yesNoSelect('edits_80', $d['edits_80']) ?>
                    </div>
                    <div class="field">
                        <label>Attended last 3 Conversation Hours <span class="req-star">*</span></label>
                        <?= $yesNoSelect('attended_ch', $d['attended_ch']) ?>
                    </div>
                    <div class="field">
                        <label>Active on DCW technical projects <span class="req-star">*</span></label>
                        <?= $yesNoSelect('tech_contributor', $d['tech_contributor']) ?>
                    </div>
                    <div class="field">
                        <label>Why you need support <span class="req-star">*</span></label>
                        <textarea name="reason" rows="4" maxlength="<?= InternetSupportModel::MAX_REASON_LENGTH ?>" required><?= $e($d['reason']) ?></textarea>
                    </div>
                    <div class="field">
                        <label>Contributions in the last three months <span class="req-star">*</span></label>
                        <textarea name="contributions" rows="4" maxlength="<?= InternetSupportModel::MAX_NARRATIVE_LENGTH ?>" required><?= $e($d['contributions']) ?></textarea>
                    </div>
                    <div class="field">
                        <label>Plans for the support period <span class="req-star">*</span></label>
                        <textarea name="plans" rows="4" maxlength="<?= InternetSupportModel::MAX_NARRATIVE_LENGTH ?>" required><?= $e($d['plans']) ?></textarea>
                    </div>
                    <div class="field">
                        <label>Note to the reviewer <span class="req-star">*</span></label>
                        <textarea name="reply" rows="4" maxlength="<?= InternetSupportModel::MAX_MESSAGE_LENGTH ?>" required placeholder="Answer the question above and/or say what you changed."><?= $e($_POST['reply'] ?? '') ?></textarea>
                    </div>
                    <button type="submit">Resubmit for review</button>
                </form>
            </fieldset>
        <?php endif; ?>
    <?php endif; ?>

    <form method="POST">
        <?= CSRF::getInputField() ?>
        <div class="field">
            <label>Tracking ID <span class="req-star">*</span></label>
            <input type="text" name="tracking_id" placeholder="DCW-XXXXXXXX, RB-XXXXXXXX or IS-XXXXXXXX" value="<?= $e($trackingId) ?>" required>
        </div>
        <div class="field">
            <label>Email address <span class="req-star">*</span></label>
            <input type="email" name="email" value="<?= $e($email) ?>" required>
        </div>
        <button type="submit">Check status</button>
    </form>
</div>
<?php engage_footer(); ?>
