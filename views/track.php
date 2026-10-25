<?php
/**
 * Public "check my status" lookup for applications (tracking IDs start with "DCW-").
 *
 * Requires BOTH the tracking ID and the email address it was submitted with: an email is often
 * not confidential, so the pair prevents a single leaked value from being sufficient. Status only.
 *
 * Signed-in members: a link like /track?id=DCW-XXXXXXXX (used by "My applications" on the member
 * dashboard) is looked up with the member's own session email, so they do not need to retype it. The email
 * still has to match the application, so a member can only ever open their own applications this way.
 * For anyone else, the link simply pre-fills the tracking ID.
 *
 * Internet support (IS-) and reimbursement (RB-) requests are member-only. Those IDs are sent to
 * /member/request, which asks for sign-in and checks ownership against the session.
 *
 * What the applicant sees is deliberately not the raw internal status:
 *   New / Submitted -> "Received"              Draft -> "More information needed" (the link was emailed to them)
 *   Discarded       -> "Received"              (a discard is never announced to the applicant, so it must not show here)
 * For an accepted application on a programme that uses award confirmation, the page also shows where their
 * confirmation stands (confirmed / declined / awaiting / overdue). It never shows or builds the private
 * confirmation link: that stays in the award email, so knowing a tracking ID and an email is not enough to answer for someone.
 *
 * Session lockout mirrors Auth::attempt()'s cooldown. A session can be reset by clearing cookies, so this only slows
 * casual guessing; a per-IP limit would need shared storage (not added here).
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/engage_page.php';
require_once __DIR__ . '/../models/ApplicationModel.php';
require_once __DIR__ . '/../models/FormModel.php';
require_once __DIR__ . '/../models/AwardConfirmationModel.php';

const TRACK_MAX_ATTEMPTS = 5;
const TRACK_LOCKOUT_SECONDS = 900;

// A result page shows one person's application status: never cached, never indexed.
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

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

/** Member-only request IDs go to the member page (which handles sign-in). */
function trackIsMemberRequest($id) {
    $id = strtoupper(trim((string) $id));
    return strpos($id, 'RB-') === 0 || strpos($id, 'IS-') === 0;
}

/** What the applicant is told for an internal status: [label, one-line explanation]. */
function trackStatusView(string $status): array {
    switch ($status) {
        case 'Under Review':
            return ['Under review', 'Your application is under review. We will email you once a decision has been made.'];
        case 'Draft':
            return ['More information needed', 'We have requested further information from you. Please check your email (including your spam folder) for our message and the link to update your application.'];
        case 'Accepted':
            return ['Accepted', 'Congratulations! Please check your email for your award letter.'];
        case 'Rejected':
            return ['Rejected', 'Thank you for applying. Please check your email for our message.'];
        case 'Discarded':   // never announced to the applicant
        case 'New':
        case 'Submitted':
        default:
            return ['Received', 'We have received your application. It is currently awaiting review.'];
    }
}

// Links like /track?id=IS-XXXX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && trackIsMemberRequest($_GET['id'] ?? '')) {
    header('Location: /member/request?id=' . rawurlencode(strtoupper(trim($_GET['id']))));
    exit;
}

// Tracking is public; a signed-in member just gets their name in the top bar
// (and can open their own applications from the dashboard without retyping the email).
$member = null;
try {
    require_once __DIR__ . '/../includes/member_session.php';
    $member = MemberSession::current();
} catch (Throwable $ex) {
    $member = null;
}
$memberEmail = $member ? strtolower(trim((string) ($member['email'] ?? ''))) : '';

$application = null;
$error = '';
$trackingId = strtoupper(trim((string) ($_POST['tracking_id'] ?? ($_GET['id'] ?? ''))));
$email = trim((string) ($_POST['email'] ?? ''));

// Link from the member dashboard: /track?id=DCW-XXXXXXXX. Looked up with the session email, so only
// the member's own application can match. No match (or not signed in): the form below is simply
// pre-filled, with no error and no failed attempt counted.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $trackingId !== '' && $memberEmail !== '') {
    try {
        $application = (new ApplicationModel())->getApplicationByTrackingIdAndEmail($trackingId, $memberEmail);
    } catch (Throwable $ex) {
        $application = null;
    }
    if (!$application) {
        $email = $memberEmail;   // pre-fill, so they can still press "Check status"
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    // An IS-/RB- ID typed here: send them to the member page (it asks for sign-in).
    if (trackIsMemberRequest($trackingId)) {
        header('Location: /member/request?id=' . rawurlencode($trackingId));
        exit;
    }

    $wait = trackLockoutRemaining();
    if ($wait > 0) {
        $error = "Too many attempts. Please try again in " . ceil($wait / 60) . " minute(s).";
    } elseif ($trackingId === '' || $email === '') {
        $error = "Please enter both your tracking ID and email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            $application = (new ApplicationModel())->getApplicationByTrackingIdAndEmail($trackingId, $email);
        } catch (Throwable $ex) {
            // A database problem is not the applicant's fault: state so, and do not count it as a failed attempt.
            $application = null;
            $error = "We could not check your application at the moment. Please try again in a few minutes.";
        }
        if ($application) {
            unset($_SESSION['track_failures'], $_SESSION['track_locked_until']);
        } elseif ($error === '') {
            // Same message either way: never reveal whether the ID or the email didn't match.
            $error = "No record found for that tracking ID and email address.";
            $_SESSION['track_failures'] = ($_SESSION['track_failures'] ?? 0) + 1;
            if ($_SESSION['track_failures'] >= TRACK_MAX_ATTEMPTS) {
                $_SESSION['track_locked_until'] = time() + TRACK_LOCKOUT_SECONDS;
            }
        }
    }
}

// Where award confirmation stands for an accepted application (status only: never the private link).
$award = null;
if ($application && ($application['status'] ?? '') === 'Accepted' && !empty($application['id']) && !empty($application['form_id'])) {
    try {
        $cm = new AwardConfirmationModel();
        $settings = $cm->allSettings()[(int) $application['form_id']] ?? null;
        $conf = $cm->allByApplication()[(int) $application['id']] ?? null;
        $state = AwardConfirmationModel::stateOf($conf, $settings, 'Accepted');
        if ($state !== 'none') {
            $deadline = FormModel::formatIst($settings['deadline_at'] ?? null);
            $award = ['state' => $state, 'deadline' => $deadline];
        }
    } catch (Throwable $ex) {
        $award = null;   // the status above is still shown
    }
}

engage_header([
    'title'   => 'Track your application',
    'heading' => 'Track your application',
    'kicker'  => 'Status check',
    'lead'    => 'For applications (IDs start with DCW-). Internet support and reimbursement requests are tracked via your member dashboard.',
    'member'  => $member,
    'crumbs'  => [['Home', '/'], ['Track your application']],
    'noindex' => true,
]);
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<div class="fcard">
    <?php if ($error): ?><div class="alert error" role="alert"><strong>Notice:</strong> <?= $e($error) ?></div><?php endif; ?>

    <?php if ($application):
        [$statusLabel, $statusNote] = trackStatusView((string) $application['status']); ?>
        <div class="result" role="status">
            <h3><?= $e($application['form_title'] ?: 'Application') ?></h3>
            Tracking ID: <code><?= $e($application['tracking_id']) ?></code><br>
            Status: <strong><?= $e($statusLabel) ?></strong><br>
            Submitted: <?= $e(date('F j, Y', strtotime($application['created_at']))) ?>
            <p style="margin:12px 0 0;"><?= $e($statusNote) ?></p>
            <?php if ($award): ?>
                <p style="margin:12px 0 0;"><strong>Scholarship confirmation:</strong>
                <?php if ($award['state'] === 'confirmed'): ?>
                    You have confirmed your place. Thank you.
                <?php elseif ($award['state'] === 'declined'): ?>
                    Your scholarship has been recorded as declined. If this was done in error, please write to the scholarship committee.
                <?php elseif ($award['state'] === 'overdue'): ?>
                    We have not received your confirmation and the deadline<?= $award['deadline'] !== '' ? ' (' . $e($award['deadline']) . ')' : '' ?> has passed. Please contact the scholarship committee if you still wish to claim your place.
                <?php else: ?>
                    Waiting for your response<?= $award['deadline'] !== '' ? ' by ' . $e($award['deadline']) : '' ?>. Please use the confirmation link in your award email.
                <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <?= CSRF::getInputField() ?>
        <div class="field">
            <label for="tracking_id">Tracking ID <span class="req-star">*</span></label>
            <input type="text" id="tracking_id" name="tracking_id" placeholder="DCW-XXXXXXXX" value="<?= $e($trackingId) ?>"
                   autocomplete="off" autocapitalize="characters" spellcheck="false" required>
        </div>
        <div class="field">
            <label for="email">Email address <span class="req-star">*</span></label>
            <!-- Not "required" in HTML so an IS-/RB- ID can be passed on to the member page; the server still requires it for DCW- lookups. -->
            <input type="email" id="email" name="email" value="<?= $e($email) ?>" autocomplete="email" inputmode="email">
        </div>
        <button type="submit">Check status</button>
        <p style="font-size:13px; color:var(--muted); margin-top:14px;">
            Looking for an internet support or reimbursement request? <a href="/member/dashboard">Sign in to your dashboard</a>.
        </p>
    </form>
</div>
<?php engage_footer(); ?>
