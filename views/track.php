<?php
/**
 * Public "check my status" lookup for applications (tracking IDs start "DCW-").
 *
 * Requires BOTH the tracking ID and the email it was submitted with: an email is often
 * not a secret, so the pair keeps one leaked value from being enough. Status only.
 *
 * Internet support (IS-) and reimbursement (RB-) are member-only. Those IDs are sent to
 * /member/request, which asks for sign-in and checks ownership against the session.
 *
 * Session lockout mirrors Auth::attempt()'s cooldown.
 */
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/engage_page.php';
require_once __DIR__ . '/../models/ApplicationModel.php';

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

/** Member-only request IDs go to the member page (which handles sign-in). */
function trackIsMemberRequest($id) {
    $id = strtoupper(trim((string) $id));
    return strpos($id, 'RB-') === 0 || strpos($id, 'IS-') === 0;
}

// Links like /track?id=IS-XXXX
if ($_SERVER['REQUEST_METHOD'] === 'GET' && trackIsMemberRequest($_GET['id'] ?? '')) {
    header('Location: /member/request?id=' . rawurlencode(strtoupper(trim($_GET['id']))));
    exit;
}

$application = null;
$error = '';
$trackingId = trim($_POST['tracking_id'] ?? '');
$email = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    // An IS-/RB- ID typed here: send them to the member page (it asks for sign-in).
    if (trackIsMemberRequest($trackingId)) {
        header('Location: /member/request?id=' . rawurlencode(strtoupper($trackingId)));
        exit;
    }

    $wait = trackLockoutRemaining();
    if ($wait > 0) {
        $error = "Too many attempts. Try again in " . ceil($wait / 60) . " minute(s).";
    } elseif ($trackingId === '' || $email === '') {
        $error = "Please enter both your tracking ID and the email you used.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $application = (new ApplicationModel())->getApplicationByTrackingIdAndEmail(strtoupper($trackingId), $email);
        if ($application) {
            unset($_SESSION['track_failures'], $_SESSION['track_locked_until']);
        } else {
            // Same message either way: never reveal whether the ID or the email didn't match.
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
    'title'   => 'Track your application',
    'heading' => 'Track your application',
    'kicker'  => 'Status check',
    'lead'    => 'For applications (IDs start with DCW-). Internet support and reimbursement requests are followed from your member dashboard.',
    'member'  => $member,
    'crumbs'  => [['Home', '/'], ['Track your application']],
]);
$e = fn($s) => htmlspecialchars((string) $s);
?>
<div class="fcard">
    <?php if ($error): ?><div class="alert error"><strong>Notice:</strong> <?= $e($error) ?></div><?php endif; ?>

    <?php if ($application): ?>
        <div class="result">
            <h3><?= $e($application['form_title'] ?: 'Application') ?></h3>
            Tracking ID: <code><?= $e($application['tracking_id']) ?></code><br>
            Status: <strong><?= $e($application['status']) ?></strong><br>
            Submitted: <?= $e(date('F j, Y', strtotime($application['created_at']))) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <?= CSRF::getInputField() ?>
        <div class="field">
            <label>Tracking ID <span class="req-star">*</span></label>
            <input type="text" name="tracking_id" placeholder="DCW-XXXXXXXX" value="<?= $e($trackingId) ?>" required>
        </div>
        <div class="field">
            <label>Email address <span class="req-star">*</span></label>
            <!-- Not "required" in HTML so an IS-/RB- ID can be passed on to the member page; the server still requires it for DCW- lookups. -->
            <input type="email" name="email" value="<?= $e($email) ?>">
        </div>
        <button type="submit">Check status</button>
        <p style="font-size:13px; color:var(--muted); margin-top:14px;">
            Looking for an internet support or reimbursement request? <a href="/member/dashboard">Sign in to your dashboard</a>.
        </p>
    </form>
</div>
<?php engage_footer(); ?>
