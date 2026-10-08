<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/member_requests.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/MemberAuthModel.php';
require_once __DIR__ . '/../../models/InternetSupportModel.php';
require_once __DIR__ . '/../../models/ReimbursementSettingsModel.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';

/**
 * DCW Engage - unified support entry point (/member/support).
 *
 * MEMBERS ONLY. A volunteer signs in with their Member ID and password (/member/login), and the
 * email on their membership is used for every request, so there is no email verification step:
 * the login already proves who they are. Old ?verify= links still in inboxes are simply ignored.
 *
 * One page, then the member picks what they need. Type is chosen with ?type=internet|reimbursement.
 * All validation, privacy rules and state machines stay in InternetSupportModel / ReimbursementModel;
 * this file only does routing, sign-in checks and rendering.
 *
 * The old /support URL is kept as a redirect stub (support.php at the site root).
 */

/**
 * true  = only members whose membership is active (approved and not expired) can request support.
 * false = any signed-in member can.
 */
const SUPPORT_REQUIRES_ACTIVE_MEMBER = true;

$internetModel      = new InternetSupportModel();
$reimbursementModel = new ReimbursementModel();
$internetSettings   = $internetModel->getSettings();
$reimbursementSettings = (new ReimbursementSettingsModel())->get();

// Which kinds of support are currently switched on.
$typeOpen = [
    'internet'      => $internetModel->isOpen(),
    'reimbursement' => (bool) ($reimbursementSettings && $reimbursementSettings['is_active']),
];

// Nothing open at all: behave like the old pages did when switched off.
if (!in_array(true, $typeOpen, true)) {
    http_response_code(404);
    require __DIR__ . '/../forms/not_found.php';
    die();
}

// ------------------------------------------------------------------
// Members only: not signed in means off to /member/login (and back here afterwards).
// ------------------------------------------------------------------
MemberSession::requireLogin();
$member = MemberSession::current();
// Any active membership under this Member ID counts (same rule as the dashboard tiles).
$memberActive = !SUPPORT_REQUIRES_ACTIVE_MEMBER || MemberSession::isActive();

// The member's own email is used for every request (it replaces the old email verification).
$verifiedEmail = $memberActive ? strtolower((string) $member['email']) : '';
$memberName = (string) ($member['full_name'] ?? '');

$typeMeta = [
    'internet' => [
        'title' => 'Internet support',
        'blurb' => 'Help paying for a data pack so you can keep contributing.',
    ],
    'reimbursement' => [
        'title' => 'Reimbursement',
        'blurb' => 'Claim back expenses for a DCW-aligned event.',
    ],
];

// ------------------------------------------------------------------
// Which type is the person on? A submission decides for itself; otherwise ?type=.
// ------------------------------------------------------------------
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';
$requested = (string) ($_GET['type'] ?? '');
if ($action === 'submit_internet_request') {
    $requested = 'internet';
} elseif ($action === 'submit_reimbursement') {
    $requested = 'reimbursement';
}
$requestedClosed = isset($typeOpen[$requested]) && !$typeOpen[$requested];
$type = (isset($typeOpen[$requested]) && $typeOpen[$requested]) ? $requested : null;

$errors = [];
$success = null;
$successType = null;

// ------------------------------------------------------------------
// Submission: internet support
// ------------------------------------------------------------------
if ($action === 'submit_internet_request' && $type === 'internet') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    if ($verifiedEmail === '') {
        $errors['system'] = "Support requests are for members with an active membership.";
    } else {
        try {
            $applicantName = trim($_POST['applicant_name'] ?? '');

            // Eligibility and narrative answers are validated inside the model,
            // so the rules live in one place.
            $result = $internetModel->createRequest(
                $verifiedEmail,
                $applicantName,
                $_POST['phone'] ?? '',
                $_POST['operator'] ?? '',
                $_POST['package_name'] ?? '',
                $_POST['amount'] ?? '',
                $_POST['validity_days'] ?? '',
                $_POST['reason'] ?? '',
                [
                    'wikimedia_username' => $_POST['wikimedia_username'] ?? '',
                    'edits_80'           => $_POST['edits_80'] ?? '',
                    'attended_ch'        => $_POST['attended_ch'] ?? '',
                    'tech_contributor'   => $_POST['tech_contributor'] ?? '',
                    'contributions'      => $_POST['contributions'] ?? '',
                    'plans'              => $_POST['plans'] ?? '',
                    'pack_ends_on'       => $_POST['pack_ends_on'] ?? '',
                ]
            );

            require_once __DIR__ . '/../../includes/mailer.php';
            Mailer::sendInternetReceived($verifiedEmail, $applicantName, $result['tracking_id']);

            $success = $result;
            $successType = 'internet';
        } catch (\InvalidArgumentException $e) {
            $errors['system'] = $e->getMessage();
        } catch (Exception $e) {
            app_log("Internet support submission failed for <$verifiedEmail>: " . $e->getMessage());
            $errors['system'] = "An error occurred submitting your request. Please try again.";
        }
    }
}

// ------------------------------------------------------------------
// Submission: reimbursement
// ------------------------------------------------------------------
function cleanupUploadedPaths(array $paths) {
    foreach ($paths as $p) {
        if (is_string($p) && strpos($p, 'uploads/') === 0 && strpos($p, '..') === false) {
            $full = __DIR__ . '/../../' . $p;
            if (is_file($full)) {
                @unlink($full);
            }
        }
    }
}

if ($action === 'submit_reimbursement' && $type === 'reimbursement') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $eventName = trim($_POST['event_name'] ?? '');
    $eventDate = trim($_POST['event_date'] ?? '');
    $dcwEvent  = $_POST['dcw_event'] ?? '';

    if ($verifiedEmail === '') {
        $errors['system'] = "Support requests are for members with an active membership.";
    } elseif ($dcwEvent === '') {
        $errors['system'] = "Please tell us whether this was a DCW-aligned, DCW-organised or DCW-associated event.";
    } elseif ($dcwEvent !== 'yes') {
        $errors['system'] = "Reimbursement is only available for events that are DCW-aligned, DCW-organised or DCW-associated.";
    } elseif ($eventName === '') {
        $errors['system'] = "Please enter the name of the event.";
    } elseif ($reimbursementModel->hasOpenOrPaidRequest($verifiedEmail, $eventName)) {
        $errors['system'] = "You already have a reimbursement request on file for \"" . $eventName . "\".";
    } else {
        require_once __DIR__ . '/../../models/FileUploader.php';
        $fileUploader = new FileUploader();

        $categories   = $_POST['line_item_category'] ?? [];
        $descriptions = $_POST['line_item_description'] ?? [];
        $amounts      = $_POST['line_item_amount'] ?? [];
        $uploadedPaths = [];
        $lineItems = [];

        try {
            $rowCount = count($categories);
            for ($i = 0; $i < $rowCount; $i++) {
                $category     = trim($categories[$i] ?? '');
                $description  = trim($descriptions[$i] ?? '');
                $amountRupees = trim($amounts[$i] ?? '');

                // Skip a fully-empty trailing row.
                if ($category === '' && $description === '' && $amountRupees === '') {
                    continue;
                }

                // Same parser as the resubmit flow, so an amount can't pass here and fail there.
                $paise = ReimbursementModel::rupeesToPaise($amountRupees);
                if ($category === '' || $description === '' || $paise === null || $paise <= 0) {
                    throw new \InvalidArgumentException("Row " . ($i + 1) . ": category, description, and a positive amount are all required.");
                }

                // Receipts are optional. '' means none was attached.
                // The Member ID goes in the filename, not the email: no personal data in the URL.
                $fileKey = "line_item_receipt_$i";
                $path = '';
                if (!empty($_FILES[$fileKey]['name'])) {
                    $path = $fileUploader->handleUpload($_FILES[$fileKey], $fileKey, (string) $member['member_id'], 'reimbursement') ?: '';
                    if ($path) {
                        $uploadedPaths[] = $path;
                    }
                }

                $lineItems[] = [
                    'category'     => $category,
                    'description'  => $description,
                    'amount_paise' => $paise,
                    'receipt_path' => $path,
                ];
            }

            $payment = [
                'method'                      => $_POST['payment_method'] ?? '',
                'upi_id'                      => $_POST['upi_id'] ?? '',
                'bank_account_name'           => $_POST['bank_account_name'] ?? '',
                'bank_account_number'         => $_POST['bank_account_number'] ?? '',
                'bank_account_number_confirm' => $_POST['bank_account_number_confirm'] ?? '',
                'bank_ifsc'                   => $_POST['bank_ifsc'] ?? '',
            ];

            $applicantName = trim($_POST['applicant_name'] ?? '');

            $result = $reimbursementModel->createRequest(
                $reimbursementSettings, $verifiedEmail, $applicantName, $eventName, $eventDate, $payment, $lineItems
            );

            require_once __DIR__ . '/../../includes/mailer.php';
            Mailer::sendReimbursementReceived($verifiedEmail, $applicantName, $result['tracking_id'], $eventName);

            $success = $result;
            $successType = 'reimbursement';
        } catch (\InvalidArgumentException $e) {
            cleanupUploadedPaths($uploadedPaths);
            $errors['system'] = $e->getMessage();
        } catch (Exception $e) {
            cleanupUploadedPaths($uploadedPaths);
            app_log("Reimbursement submission failed for <$verifiedEmail> (event: $eventName): " . $e->getMessage());
            $errors['system'] = "An error occurred submitting your request. Please try again.";
        }
    }
}

// ------------------------------------------------------------------
// View state
// ------------------------------------------------------------------
$old = function ($key) {
    return htmlspecialchars((string) ($_POST[$key] ?? ''));
};
// The name field starts with the member's name from their membership.
$oldName = function () use ($memberName) {
    return htmlspecialchars((string) ($_POST['applicant_name'] ?? $memberName));
};
$checked = function ($key, $value) {
    return (($_POST[$key] ?? '') === $value) ? 'checked' : '';
};
// Reimbursement: keep the non-sensitive answers after a validation error. Bank/UPI details and
// line items are deliberately not echoed back.
$postedDcw    = (string) ($_POST['dcw_event'] ?? '');
$postedMethod = (string) ($_POST['payment_method'] ?? 'upi');
if (!in_array($postedMethod, ['upi', 'bank'], true)) $postedMethod = 'upi';

// Why a signed-in member who is not active cannot continue.
$inactiveReason = '';
if (!$memberActive) {
    $inactiveReason = (($member['status'] ?? '') === 'active')
        ? 'Your membership expired on ' . MemberAuthModel::formatIst((string) $member['expires_at'], 'j M Y') . '.'
        : 'Your membership is not active.';
}

// Internet support
$maxAmountRupees = $internetSettings ? (int) ($internetSettings['max_amount_paise'] / 100) : 0;
$eligibilityQuestions = [
    'edits_80'         => 'Have you made 80+ edits to Wikimedia projects in the past month, without using automated tools like Depictor?',
    'attended_ch'      => 'Have you attended the past 3 DCW Conversation Hours?',
    'tech_contributor' => 'Are you actively contributing to DCW technical projects?',
];

// Internet support: the date picker for "when does your current pack end" uses the same window the
// model enforces (InternetSupportModel::cleanPackEndDate), in India time.
$_tz = new DateTimeZone('Asia/Kolkata');
$_today = new DateTimeImmutable('today', $_tz);
$packEndMin = $_today->modify('-' . InternetSupportModel::PACK_END_MAX_PAST_DAYS . ' days')->format('Y-m-d');
$packEndMax = $_today->modify('+' . InternetSupportModel::PACK_END_MAX_AHEAD_DAYS . ' days')->format('Y-m-d');
$packNoticeDays = (int) InternetSupportModel::PREFERRED_NOTICE_DAYS;

// Reimbursement: same India-time window the model enforces, used only to bound the date picker.
$maxEventDate = $_today->format('Y-m-d');
$minEventDate = $_today->modify('-' . ReimbursementModel::CLAIM_WINDOW_DAYS . ' days')->format('Y-m-d');
$upiMaxRupees = ReimbursementModel::UPI_MAX_PAISE / 100;

$pageHeading = $type ? $typeMeta[$type]['title'] . ' request' : 'Request support';

// Icons and accent colours for the type cards.
$icons = [
    'wifi' => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
    'card' => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
];
$typeStyle = [
    'internet'      => ['tone' => '#0f766e', 'icon' => 'wifi'],
    'reimbursement' => ['tone' => '#106b9a', 'icon' => 'card'],
];

engage_header([
    'title'   => $pageHeading,
    'heading' => $pageHeading,
    'kicker'  => 'Member support',
    'lead'    => $type ? $typeMeta[$type]['blurb'] : 'Tell us what kind of support you need.',
    'member'  => $member,
    'crumbs'  => $type
        ? [['Home', '/'], ['My dashboard', '/member/dashboard'], ['Support', '/member/support'], [$typeMeta[$type]['title']]]
        : [['Home', '/'], ['My dashboard', '/member/dashboard'], ['Support']],
]);
?>

<?php if (!$memberActive): ?>
    <div class="fcard">
        <div class="alert error" style="margin:0;">
            <strong>Notice:</strong> <?= htmlspecialchars($inactiveReason) ?>
            Support requests are for members with an active membership.
            <a href="/membership" style="color:#991b1b;">Renew your membership</a> to continue.
        </div>
    </div>

<?php elseif ($success): ?>
    <div class="fcard">
        <div class="result">
            <h3>Request submitted</h3>
            Your tracking ID is <code><?= htmlspecialchars($success['tracking_id']) ?></code>.<br>
            We've emailed you a confirmation. You can follow this request any time from
            <a href="<?= htmlspecialchars(member_request_url($success['tracking_id'])) ?>">your dashboard</a><?= $successType === 'reimbursement' ? " (we'll also email you once it's reviewed)." : '.' ?>
        </div>
        <a class="back-link" href="/member/dashboard">&larr; Back to my dashboard</a>
    </div>

<?php elseif ($type === null): ?>
    <?php if ($requestedClosed): ?>
        <div class="fcard" style="max-width:none; padding:18px 22px;">
            <div class="alert error" style="margin:0;">
                <strong>Notice:</strong> <?= htmlspecialchars($typeMeta[$requested]['title']) ?> requests are closed right now.
            </div>
        </div>
    <?php endif; ?>
    <div class="grid">
        <?php foreach ($typeMeta as $key => $meta): $open = $typeOpen[$key]; $st = $typeStyle[$key]; ?>
            <<?= $open ? 'a href="/member/support?type=' . urlencode($key) . '"' : 'div' ?>
                class="prog<?= $open ? '' : ' off' ?>" style="--tone: <?= $st['tone'] ?>;">
                <span class="tag"><?= $open ? 'Open' : 'Closed' ?></span>
                <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$st['icon']] ?></svg></span>
                <h3><?= htmlspecialchars($meta['title']) ?></h3>
                <p><?= htmlspecialchars($meta['blurb']) ?></p>
                <?php if ($open): ?><span class="go">Start a request <span aria-hidden="true">→</span></span><?php endif; ?>
            </<?= $open ? 'a' : 'div' ?>>
        <?php endforeach; ?>
    </div>
    <p style="text-align:center; margin-top:22px; font-size:14px; color:var(--muted);">
        You can follow your requests from your <a href="/member/dashboard" style="color:var(--primary); font-weight:600;">dashboard</a>.
    </p>

<?php else: ?>
    <div class="fcard">
        <a class="back-link" href="/member/support">&larr; Choose a different kind of support</a>

        <?php if (!empty($errors['system'])): ?>
            <div class="alert error"><strong>Notice:</strong> <?= htmlspecialchars($errors['system']) ?></div>
        <?php endif; ?>

        <?php if ($type === 'internet'): ?>
            <p class="intro">
                A reviewer checks the request, our finance team does the recharge, and you then upload the
                operator's receipt so we can close it. You can submit one request every
                <?= (int) InternetSupportModel::MIN_DAYS_BETWEEN_REQUESTS ?> days.
                Please apply at least <?= $packNoticeDays ?> days before your current pack ends.
            </p>

            <form method="POST" action="/member/support?type=internet">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="submit_internet_request">

                <fieldset class="group">
                    <legend>Eligibility</legend>
                    <?php foreach ($eligibilityQuestions as $name => $label): ?>
                        <div class="field">
                            <label><?= htmlspecialchars($label) ?> <span class="req-star">*</span></label>
                            <div class="choices">
                                <label><input type="radio" name="<?= $name ?>" value="yes" required <?= $checked($name, 'yes') ?>> Yes</label>
                                <label><input type="radio" name="<?= $name ?>" value="no" <?= $checked($name, 'no') ?>> No</label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div id="ineligible-note" class="alert error" style="display:none;">
                        Based on your answers, you're not eligible for support right now. You need 80+ manual edits
                        in the past month and attendance at the last 3 Conversation Hours, or active contribution
                        to DCW technical projects.
                    </div>
                </fieldset>

                <div class="row">
                    <div class="field">
                        <label>Your name <span class="req-star">*</span></label>
                        <input type="text" name="applicant_name" required maxlength="255" value="<?= $oldName() ?>">
                    </div>
                    <div class="field">
                        <label>Wikimedia username <span class="req-star">*</span></label>
                        <input type="text" name="wikimedia_username" required maxlength="255" value="<?= $old('wikimedia_username') ?>">
                        <span class="hint">The username only, without "User:" or a link.</span>
                    </div>
                </div>

                <div class="row">
                    <div class="field">
                        <label>Mobile number to be recharged <span class="req-star">*</span></label>
                        <input type="tel" name="phone" required maxlength="20" placeholder="10-digit mobile number" value="<?= $old('phone') ?>">
                        <span class="hint">Only the finance team can see this number.</span>
                    </div>
                    <div class="field">
                        <label>Mobile operator <span class="req-star">*</span></label>
                        <input type="text" name="operator" required maxlength="50" list="operator-list" placeholder="e.g. Jio" value="<?= $old('operator') ?>">
                        <datalist id="operator-list">
                            <option value="Jio">
                            <option value="Airtel">
                            <option value="Vi">
                            <option value="BSNL">
                        </datalist>
                    </div>
                </div>

                <div class="field">
                    <label>The pack you need <span class="req-star">*</span></label>
                    <input type="text" name="package_name" required minlength="3" maxlength="120" placeholder="e.g. 1.5 GB/day recharge" value="<?= $old('package_name') ?>">
                    <span class="hint">Describe the plan as your operator lists it.</span>
                </div>

                <div class="row">
                    <div class="field">
                        <label>Price of the pack (₹) <span class="req-star">*</span></label>
                        <input type="number" name="amount" required min="1" max="<?= (int) $maxAmountRupees ?>" step="0.01" value="<?= $old('amount') ?>">
                        <span class="hint">Up to ₹<?= number_format($maxAmountRupees) ?>.</span>
                    </div>
                    <div class="field">
                        <label>Validity (days)</label>
                        <input type="number" name="validity_days" min="1" max="365" step="1" placeholder="optional" value="<?= $old('validity_days') ?>">
                    </div>
                </div>

                <div class="field">
                    <label>When does your current pack end? <span class="req-star">*</span></label>
                    <input type="date" name="pack_ends_on" id="pack-ends-on" required
                           min="<?= htmlspecialchars($packEndMin) ?>" max="<?= htmlspecialchars($packEndMax) ?>" value="<?= $old('pack_ends_on') ?>">
                    <span class="hint">We try to have the recharge ready by then. Please apply at least <?= $packNoticeDays ?> days before this date.</span>
                    <div id="short-notice-note" class="alert error" style="display:none; margin:10px 0 0;">
                        That is less than <?= $packNoticeDays ?> days away. You can still submit, but we usually need about
                        <?= $packNoticeDays ?> days to review and recharge, so it may not be ready in time.
                    </div>
                </div>

                <h3>Your request</h3>
                <div class="field">
                    <label>Why do you need support with internet access? <span class="req-star">*</span></label>
                    <textarea name="reason" required minlength="<?= (int) InternetSupportModel::MIN_REASON_LENGTH ?>" maxlength="<?= (int) InternetSupportModel::MAX_REASON_LENGTH ?>" rows="5"><?= $old('reason') ?></textarea>
                    <span class="hint">We are particularly interested in the motivation behind the request. For example, the support will help you contribute in an xyz way.</span>
                </div>
                <div class="field">
                    <label>Your contributions in the last three months relevant to the growth of DCW <span class="req-star">*</span></label>
                    <textarea name="contributions" required minlength="<?= (int) InternetSupportModel::MIN_NARRATIVE_LENGTH ?>" maxlength="<?= (int) InternetSupportModel::MAX_NARRATIVE_LENGTH ?>" rows="5"><?= $old('contributions') ?></textarea>
                </div>
                <div class="field">
                    <label>Your plans for the period you are seeking internet support for <span class="req-star">*</span></label>
                    <textarea name="plans" required minlength="<?= (int) InternetSupportModel::MIN_NARRATIVE_LENGTH ?>" maxlength="<?= (int) InternetSupportModel::MAX_NARRATIVE_LENGTH ?>" rows="5"><?= $old('plans') ?></textarea>
                </div>

                <button type="submit">Submit request</button>
            </form>

            <script>
                // Convenience only: the server (InternetSupportModel::createRequest) is the real gate.
                (function () {
                    var first = document.querySelector('input[name="edits_80"]');
                    if (!first) return;
                    var form = first.form;
                    var note = document.getElementById('ineligible-note');
                    var submit = form.querySelector('button[type="submit"]');
                    function val(n) { var el = form.querySelector('input[name="' + n + '"]:checked'); return el ? el.value : ''; }
                    function update() {
                        var e = val('edits_80'), a = val('attended_ch'), t = val('tech_contributor');
                        var blocked = e && a && t && !(t === 'yes' || (e === 'yes' && a === 'yes'));
                        note.style.display = blocked ? 'block' : 'none';
                        submit.disabled = !!blocked;
                    }
                    form.addEventListener('change', update);
                    update();
                })();

                // Short-notice warning for the pack end date. It never blocks the form.
                (function () {
                    var input = document.getElementById('pack-ends-on');
                    var note = document.getElementById('short-notice-note');
                    if (!input || !note) return;
                    var PREFERRED_DAYS = <?= json_encode($packNoticeDays) ?>;
                    var todayIst = <?= json_encode($_today->format('Y-m-d')) ?>;
                    function daysUntil(iso) {
                        var a = Date.parse(todayIst + 'T00:00:00Z'), b = Date.parse(iso + 'T00:00:00Z');
                        return Math.round((b - a) / 86400000);
                    }
                    function update() {
                        note.style.display = (input.value && daysUntil(input.value) < PREFERRED_DAYS) ? 'block' : 'none';
                    }
                    input.addEventListener('input', update);
                    input.addEventListener('change', update);
                    update();
                })();
            </script>

        <?php else: /* reimbursement */ ?>
            <?php if (!empty($reimbursementSettings['instructions'])): ?>
                <div class="intro"><?= MiniWikiText::render($reimbursementSettings['instructions']) ?></div>
            <?php endif; ?>

            <form method="POST" action="/member/support?type=reimbursement" enctype="multipart/form-data" id="reimbursement-form">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="submit_reimbursement">

                <div class="field">
                    <label>Your name <span class="req-star">*</span></label>
                    <input type="text" name="applicant_name" required value="<?= $oldName() ?>">
                </div>

                <div class="field">
                    <label>Was this a DCW-aligned, DCW-organised or DCW-associated event? <span class="req-star">*</span></label>
                    <select name="dcw_event" id="dcw-event" required>
                        <option value="">Select…</option>
                        <option value="yes"<?= $postedDcw === 'yes' ? ' selected' : '' ?>>Yes</option>
                        <option value="no"<?= $postedDcw === 'no' ? ' selected' : '' ?>>No</option>
                    </select>
                    <div id="dcw-no-notice" class="alert error" style="display:none; margin:10px 0 0;">
                        Reimbursement is only available for DCW-aligned, DCW-organised or DCW-associated events.
                    </div>
                </div>

                <div class="row">
                    <div class="field">
                        <label>Event name <span class="req-star">*</span></label>
                        <input type="text" name="event_name" required maxlength="255" placeholder="e.g. Wiki Loves Monuments 2026 Workshop" value="<?= $old('event_name') ?>">
                    </div>
                    <div class="field">
                        <label>Event date <span class="req-star">*</span></label>
                        <input type="date" name="event_date" required
                               min="<?= htmlspecialchars($minEventDate) ?>" max="<?= htmlspecialchars($maxEventDate) ?>" value="<?= $old('event_date') ?>">
                        <span class="hint">Submit within <?= (int) ReimbursementModel::CLAIM_WINDOW_DAYS ?> days of the event.</span>
                    </div>
                </div>

                <h3>Expenses</h3>
                <div id="line-items"></div>
                <button type="button" id="add-line-item" class="btn-ghost" style="margin-bottom:16px;">+ Add expense</button>
                <div class="total-bar"><span>Running total</span><strong>₹<span id="running-total">0.00</span></strong></div>

                <h3>Payment details</h3>
                <p style="font-size:13.5px; color:var(--muted); margin-top:0;">
                    UPI is available for claims up to ₹<?= number_format($upiMaxRupees) ?>. Larger claims are paid by bank transfer.
                </p>
                <div class="field choices stack">
                    <label><input type="radio" name="payment_method" value="upi" id="method-upi"<?= $postedMethod === 'upi' ? ' checked' : '' ?>> UPI</label>
                    <label><input type="radio" name="payment_method" value="bank" id="method-bank"<?= $postedMethod === 'bank' ? ' checked' : '' ?>> Bank transfer</label>
                </div>

                <div id="upi-fields" style="display:none;">
                    <div class="field"><label>UPI ID</label><input type="text" name="upi_id" placeholder="name@bank" value="<?= $old('upi_id') ?>"></div>
                </div>
                <div id="bank-fields" style="display:none;">
                    <div class="field"><label>Account holder name</label><input type="text" name="bank_account_name" value="<?= $old('bank_account_name') ?>"></div>
                    <div class="row">
                        <div class="field"><label>Account number</label><input type="text" name="bank_account_number" autocomplete="off"></div>
                        <div class="field"><label>Confirm account number</label><input type="text" name="bank_account_number_confirm" autocomplete="off"></div>
                    </div>
                    <div class="field"><label>IFSC code</label><input type="text" name="bank_ifsc" style="text-transform:uppercase;" value="<?= $old('bank_ifsc') ?>"></div>
                </div>

                <button type="submit" id="submit-btn" style="margin-top:6px;">Submit reimbursement request</button>
            </form>

            <template id="line-item-template">
                <div class="li line-item">
                    <div class="row">
                        <div class="field">
                            <label>Category</label>
                            <select name="line_item_category[]" class="li-category">
                                <?php foreach ($reimbursementSettings['expense_categories'] as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Amount (₹)</label>
                            <input type="number" step="0.01" min="0.01" name="line_item_amount[]" class="li-amount">
                        </div>
                    </div>
                    <div class="field">
                        <label>Description</label>
                        <input type="text" name="line_item_description[]">
                    </div>
                    <div class="field">
                        <label>Receipt <span style="font-weight:400; color:var(--muted);">(optional, but recommended)</span></label>
                        <input type="file" name="__RECEIPT_NAME__" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                    <button type="button" class="remove-line-item btn-danger-ghost">Remove</button>
                </div>
            </template>

            <script>
                const UPI_MAX_RUPEES = <?= json_encode($upiMaxRupees) ?>;

                function addLineItem() {
                    const template = document.getElementById('line-item-template');
                    const clone = template.content.cloneNode(true);

                    clone.querySelector('.remove-line-item').addEventListener('click', function (e) {
                        e.target.closest('.line-item').remove();
                        renumberReceipts();
                        recomputeTotal();
                    });
                    clone.querySelector('.li-amount').addEventListener('input', recomputeTotal);

                    document.getElementById('line-items').appendChild(clone);
                    renumberReceipts();
                }

                // The server pairs row N with the file field line_item_receipt_N, so the
                // file inputs must always be numbered by their current position.
                function renumberReceipts() {
                    document.querySelectorAll('#line-items .line-item input[type="file"]').forEach(function (el, i) {
                        el.name = 'line_item_receipt_' + i;
                    });
                }

                function recomputeTotal() {
                    let total = 0;
                    document.querySelectorAll('.li-amount').forEach(input => {
                        const val = parseFloat(input.value);
                        if (!isNaN(val)) total += val;
                    });
                    document.getElementById('running-total').textContent = total.toFixed(2);

                    // Client-side convenience only; the server re-checks the real total.
                    const upiRadio = document.getElementById('method-upi');
                    const bankRadio = document.getElementById('method-bank');
                    if (total > UPI_MAX_RUPEES) {
                        upiRadio.disabled = true;
                        if (upiRadio.checked) {
                            bankRadio.checked = true;
                            togglePaymentFields();
                        }
                    } else {
                        upiRadio.disabled = false;
                    }
                }

                function togglePaymentFields() {
                    const method = document.querySelector('input[name="payment_method"]:checked')?.value;
                    document.getElementById('upi-fields').style.display = method === 'upi' ? 'block' : 'none';
                    document.getElementById('bank-fields').style.display = method === 'bank' ? 'block' : 'none';
                }

                function toggleDcwNotice() {
                    const answer = document.getElementById('dcw-event').value;
                    document.getElementById('dcw-no-notice').style.display = answer === 'no' ? 'block' : 'none';
                    document.getElementById('submit-btn').disabled = (answer === 'no');
                }

                document.getElementById('add-line-item').addEventListener('click', addLineItem);
                document.querySelectorAll('input[name="payment_method"]').forEach(el => el.addEventListener('change', togglePaymentFields));
                document.getElementById('dcw-event').addEventListener('change', toggleDcwNotice);
                document.getElementById('reimbursement-form').addEventListener('submit', renumberReceipts);

                // Start with one line item row.
                addLineItem();
                togglePaymentFields();
                toggleDcwNotice();
            </script>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php engage_footer(); ?>
