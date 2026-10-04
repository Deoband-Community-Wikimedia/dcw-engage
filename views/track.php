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
 * Because an email is not a secret, the reimbursement result is limited to
 * status information: event, amount, status, dates, and the transaction
 * reference once paid. It never shows UPI / bank details. The internet
 * support result follows the same rule: it never shows the phone number or
 * the reason, and the only write it allows is attaching a receipt while a
 * request is in 'Awaiting Receipt'.
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

$application = null;
$reimbursement = null;
$internet = null;
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
            $reimbursement = (new ReimbursementModel())->getStatusForApplicant($trackingKey, $email);
        } elseif (strpos($trackingKey, 'IS-') === 0) {
            $internetModel = new InternetSupportModel();
            // The pair must match before anything else happens, including an upload.
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
