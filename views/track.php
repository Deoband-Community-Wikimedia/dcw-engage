<?php
/**
 * Public "check my status" lookup (see #32) — covers both applications
 * (tracking IDs start "DCW-") and reimbursement requests (start "RB-").
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
 * reference once paid. It never shows UPI / bank details.
 *
 * Session-based lockout mirrors Auth::attempt()'s login cooldown, so a
 * script trying to brute-force the tracking ID space (or spam this page)
 * gets slowed to a crawl the same way a login-guessing attempt would.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../models/ApplicationModel.php';
require_once __DIR__ . '/../models/ReimbursementModel.php';

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

$application = null;
$reimbursement = null;
$error = '';
$trackingId = trim($_POST['tracking_id'] ?? '');
$email = trim($_POST['email'] ?? '');

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
        } else {
            $application = (new ApplicationModel())->getApplicationByTrackingIdAndEmail($trackingKey, $email);
        }

        if ($application || $reimbursement) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Your Application - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>
<body>
    <div class="container">
        <h1>Track Your Application or Reimbursement</h1>
        <p style="margin-top:-20px; color:#64748b; font-size:14.5px;">
            Enter the tracking ID you were given, along with the email address you used, to check the current status.
            Application IDs start with <strong>DCW-</strong> and reimbursement IDs start with <strong>RB-</strong>.
        </p>

        <?php if ($error): ?>
            <div class="alert-error"><strong>Notice:</strong> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($application): ?>
            <div class="alert-success">
                <strong><?= htmlspecialchars($application['form_title'] ?: 'Application') ?></strong><br>
                Tracking ID: <?= htmlspecialchars($application['tracking_id']) ?><br>
                Status: <strong><?= htmlspecialchars($application['status']) ?></strong><br>
                Submitted: <?= htmlspecialchars(date('F j, Y', strtotime($application['created_at']))) ?>
            </div>
        <?php endif; ?>

        <?php if ($reimbursement): ?>
            <div class="alert-success">
                <strong>Reimbursement — <?= htmlspecialchars($reimbursement['event_name']) ?></strong><br>
                Tracking ID: <?= htmlspecialchars($reimbursement['tracking_id']) ?><br>
                Amount: ₹<?= number_format($reimbursement['total_amount_paise'] / 100, 2) ?><br>
                Status: <strong><?= htmlspecialchars($reimbursement['status']) ?></strong><br>
                <?php if ($reimbursement['status'] === 'Paid'): ?>
                    <?php if (!empty($reimbursement['payment_reference'])): ?>
                        Transaction reference: <strong><?= htmlspecialchars($reimbursement['payment_reference']) ?></strong><br>
                    <?php endif; ?>
                    <?php if (!empty($reimbursement['paid_at'])): ?>
                        Paid on: <?= htmlspecialchars(date('F j, Y', strtotime($reimbursement['paid_at']))) ?><br>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($reimbursement['status'] === 'Rejected' && !empty($reimbursement['admin_notes'])): ?>
                    Reviewer notes: <?= nl2br(htmlspecialchars($reimbursement['admin_notes'])) ?><br>
                <?php endif; ?>
                Submitted: <?= htmlspecialchars(date('F j, Y', strtotime($reimbursement['created_at']))) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?= CSRF::getInputField() ?>
            <div class="form-group">
                <label>Tracking ID</label>
                <input type="text" name="tracking_id" placeholder="DCW-XXXXXXXX or RB-XXXXXXXX" value="<?= htmlspecialchars($trackingId) ?>" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
            </div>
            <button type="submit">Check Status</button>
        </form>

        <p style="text-align:center; margin-top:20px;">
            <a href="/" style="color:var(--primary-color); text-decoration:none; font-size:14px;">&larr; Back to programs</a>
        </p>
    </div>
</body>
</html>
