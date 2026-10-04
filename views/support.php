<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/wikitext.php';
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/member_session.php';
require_once __DIR__ . '/../models/InternetSupportModel.php';
require_once __DIR__ . '/../models/ReimbursementSettingsModel.php';
require_once __DIR__ . '/../models/ReimbursementModel.php';

/**
 * DCW Engage - unified support entry point (/support).
 *
 * MEMBERS ONLY. A volunteer signs in with their Member ID and password (/member/login), and the
 * email on their membership is used for every request, so there is no email verification step here
 * any more: the login already proves who they are. Old ?verify= links still in inboxes are simply
 * ignored (the person is asked to sign in instead).
 *
 * One page, then the member picks what they need. Type is chosen with ?type=internet|reimbursement.
 * All validation, privacy rules and state machines stay in InternetSupportModel / ReimbursementModel;
 * this file only does routing, sign-in checks and rendering.
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
    require __DIR__ . '/forms/not_found.php';
    die();
}

// ------------------------------------------------------------------
// Members only: not signed in means off to /member/login (and back here afterwards).
// ------------------------------------------------------------------
MemberSession::requireLogin();
$member = MemberSession::current();
$memberActive = !SUPPORT_REQUIRES_ACTIVE_MEMBER || MemberAuthModel::isActive($member);

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
                ]
            );

            require_once __DIR__ . '/../includes/mailer.php';
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
        if (is_string($p) && strpos($p, 'uploads/') === 0) {
            $full = __DIR__ . '/../' . $p;
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
        require_once __DIR__ . '/../models/FileUploader.php';
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

                if ($category === '' || $description === '' || !is_numeric($amountRupees) || (float) $amountRupees <= 0) {
                    throw new \InvalidArgumentException("Row " . ($i + 1) . ": category, description, and a positive amount are all required.");
                }

                // Receipts are optional. '' means none was attached.
                $fileKey = "line_item_receipt_$i";
                $path = '';
                if (!empty($_FILES[$fileKey]['name'])) {
                    $path = $fileUploader->handleUpload($_FILES[$fileKey], $fileKey, $verifiedEmail, 'reimbursement') ?: '';
                    if ($path) {
                        $uploadedPaths[] = $path;
                    }
                }

                $lineItems[] = [
                    'category'     => $category,
                    'description'  => $description,
                    'amount_paise' => (int) round(((float) $amountRupees) * 100),
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

            require_once __DIR__ . '/../includes/mailer.php';
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
$showInternetForm      = !$success && $memberActive && $type === 'internet';
$showReimbursementForm = !$success && $memberActive && $type === 'reimbursement';

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

// Reimbursement: same India-time window the model enforces, used only to bound the date picker.
$_tz = new DateTimeZone('Asia/Kolkata');
$_today = new DateTimeImmutable('today', $_tz);
$maxEventDate = $_today->format('Y-m-d');
$minEventDate = $_today->modify('-' . ReimbursementModel::CLAIM_WINDOW_DAYS . ' days')->format('Y-m-d');
$upiMaxRupees = ReimbursementModel::UPI_MAX_PAISE / 100;

$pageHeading = $type ? $typeMeta[$type]['title'] . ' request' : 'Request support';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageHeading) ?> - DCW Engage</title>
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>

<body>
    <div class="container">
        <h1 style="margin-top:0;"><?= htmlspecialchars($pageHeading) ?></h1>

        <p style="margin:-8px 0 20px; font-size:14px; color:#475569;">
            Signed in as <strong><?= htmlspecialchars($memberName !== '' ? $memberName : (string) $member['email']) ?></strong>
            (<?= htmlspecialchars((string) $member['member_id']) ?>) ✓
            &middot; <a href="/member/logout" style="color:#106b9a;">Sign out</a>
        </p>

        <?php if ($type !== null && !$success && $memberActive): ?>
            <p style="margin:-8px 0 20px; font-size:14px;">
                <a href="/support" style="color:#106b9a;">&larr; Choose a different kind of support</a>
            </p>
        <?php endif; ?>

        <?php if (!$memberActive): ?>
            <div class="alert-error">
                <strong>Notice:</strong> <?= htmlspecialchars($inactiveReason) ?>
                Support requests are for members with an active membership.
                <a href="/membership" style="color:#991b1b; font-weight:600;">Renew your membership</a> to continue.
            </div>

        <?php else: ?>

            <?php if ($type === 'internet'): ?>
                <p style="color:#475569; font-size:15px; line-height:1.6; margin-bottom:30px;">
                    DCW volunteers can request help with a data pack. A reviewer checks the request, our finance team
                    does the recharge, and you then upload the operator's receipt so we can close it.
                    You can submit one request every <?= (int) InternetSupportModel::MIN_DAYS_BETWEEN_REQUESTS ?> days.
                </p>
            <?php elseif ($type === 'reimbursement' && !empty($reimbursementSettings['instructions'])): ?>
                <div style="color:#475569; font-size:15px; margin-bottom:30px; line-height:1.6;">
                    <?= MiniWikiText::render($reimbursementSettings['instructions']) ?>
                </div>
            <?php elseif ($type === null && !$success): ?>
                <p style="color:#475569; font-size:15px; line-height:1.6; margin-bottom:30px;">
                    Tell us what kind of support you need. You can follow your request on the
                    <a href="/track" style="color:#106b9a;">tracking page</a> with your tracking ID and the email on your membership.
                </p>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-success">
                    <h3 style="margin-top:0">Request submitted</h3>
                    Your tracking ID is <strong><?= htmlspecialchars($success['tracking_id']) ?></strong>.
                    We've emailed you a confirmation. Use the
                    <a href="/track" style="color:#106b9a;">tracking page</a> with this ID and your email to follow it<?= $successType === 'reimbursement' ? " (we'll also email you once it's reviewed)." : '.' ?>
                </div>

            <?php else: ?>

                <?php if ($requestedClosed): ?>
                    <div class="alert-error">
                        <strong>Notice:</strong> <?= htmlspecialchars($typeMeta[$requested]['title']) ?> requests are closed right now.
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors['system'])): ?>
                    <div class="alert-error">
                        <strong>Notice:</strong> <?= htmlspecialchars($errors['system']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($type === null): ?>
                    <div style="display:flex; gap:14px; flex-wrap:wrap; margin-bottom:30px;">
                        <?php foreach ($typeMeta as $key => $meta): ?>
                            <?php if ($typeOpen[$key]): ?>
                                <a href="/support?type=<?= urlencode($key) ?>"
                                   style="flex:1 1 240px; display:block; padding:18px 20px; border:1px solid #cbd5e1; border-radius:8px; text-decoration:none; color:inherit; background:#fff;">
                                    <strong style="display:block; font-size:16px; color:#106b9a; margin-bottom:6px;"><?= htmlspecialchars($meta['title']) ?></strong>
                                    <span style="font-size:14px; color:#475569; line-height:1.5;"><?= htmlspecialchars($meta['blurb']) ?></span>
                                </a>
                            <?php else: ?>
                                <div style="flex:1 1 240px; padding:18px 20px; border:1px dashed #e2e8f0; border-radius:8px; background:#f8fafc; opacity:.7;">
                                    <strong style="display:block; font-size:16px; color:#64748b; margin-bottom:6px;"><?= htmlspecialchars($meta['title']) ?></strong>
                                    <span style="font-size:14px; color:#64748b;">Currently closed.</span>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($type === 'internet'): ?>
                    <form method="POST" action="/support?type=internet">
                        <?= CSRF::getInputField() ?>
                        <input type="hidden" name="action" value="submit_internet_request">

                        <fieldset style="border:1px solid #e2e8f0; border-radius:8px; padding:16px 20px; margin:0 0 24px;">
                            <legend style="font-weight:600; padding:0 6px;">Eligibility</legend>

                            <?php foreach ($eligibilityQuestions as $name => $label): ?>
                                <div class="form-group" style="margin-bottom:16px;">
                                    <label><?= htmlspecialchars($label) ?> <span style="color:#ef4444">*</span></label>
                                    <label style="font-weight:400; display:inline-block; margin-right:18px;">
                                        <input type="radio" name="<?= $name ?>" value="yes" required <?= $checked($name, 'yes') ?>> Yes
                                    </label>
                                    <label style="font-weight:400; display:inline-block;">
                                        <input type="radio" name="<?= $name ?>" value="no" <?= $checked($name, 'no') ?>> No
                                    </label>
                                </div>
                            <?php endforeach; ?>

                            <div id="ineligible-note" class="alert-error" style="display:none; margin:0;">
                                Based on your answers, you're not eligible for support right now. You need 80+ manual edits
                                in the past month and attendance at the last 3 Conversation Hours, or active contribution
                                to DCW technical projects.
                            </div>
                        </fieldset>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>Your name <span style="color:#ef4444">*</span></label>
                            <input type="text" name="applicant_name" required maxlength="255" value="<?= $oldName() ?>">
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>Your Wikimedia username <span style="color:#ef4444">*</span></label>
                            <input type="text" name="wikimedia_username" required maxlength="255" value="<?= $old('wikimedia_username') ?>">
                            <span style="font-size:13px; color:#64748b; margin-top:5px; display:block;">
                                The username only, without "User:" or a link.
                            </span>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>Mobile number to be recharged <span style="color:#ef4444">*</span></label>
                            <input type="tel" name="phone" required maxlength="20" placeholder="10-digit mobile number" value="<?= $old('phone') ?>">
                            <span style="font-size:13px; color:#64748b; margin-top:5px; display:block;">
                                Only the finance team can see this number.
                            </span>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>Mobile operator <span style="color:#ef4444">*</span></label>
                            <input type="text" name="operator" required maxlength="50" list="operator-list" placeholder="e.g. Jio" value="<?= $old('operator') ?>">
                            <datalist id="operator-list">
                                <option value="Jio">
                                <option value="Airtel">
                                <option value="Vi">
                                <option value="BSNL">
                            </datalist>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>The pack you need <span style="color:#ef4444">*</span></label>
                            <input type="text" name="package_name" required minlength="3" maxlength="120" placeholder="e.g. 1.5 GB/day recharge" value="<?= $old('package_name') ?>">
                            <span style="font-size:13px; color:#64748b; margin-top:5px; display:block;">
                                Describe the plan as your operator lists it.
                            </span>
                        </div>

                        <div style="display:flex; gap:14px; flex-wrap:wrap;">
                            <div class="form-group" style="margin-bottom:20px; flex:1 1 160px;">
                                <label>Price of the pack (₹) <span style="color:#ef4444">*</span></label>
                                <input type="number" name="amount" required min="1" max="<?= (int) $maxAmountRupees ?>" step="0.01" value="<?= $old('amount') ?>">
                                <span style="font-size:13px; color:#64748b; margin-top:5px; display:block;">
                                    Up to ₹<?= number_format($maxAmountRupees) ?>.
                                </span>
                            </div>
                            <div class="form-group" style="margin-bottom:20px; flex:1 1 160px;">
                                <label>Validity (days)</label>
                                <input type="number" name="validity_days" min="1" max="365" step="1" placeholder="optional" value="<?= $old('validity_days') ?>">
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>Why do you need support with internet access? <span style="color:#ef4444">*</span></label>
                            <textarea name="reason" required minlength="<?= (int) InternetSupportModel::MIN_REASON_LENGTH ?>" maxlength="<?= (int) InternetSupportModel::MAX_REASON_LENGTH ?>" rows="5"><?= $old('reason') ?></textarea>
                            <span style="font-size:13px; color:#64748b; margin-top:5px; display:block;">
                                We are particularly interested in understanding the motivation behind the request. For example, the support will help you contribute in an xyz way.
                            </span>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>Tell us about your contributions in the last three months which are relevant to the growth of DCW <span style="color:#ef4444">*</span></label>
                            <textarea name="contributions" required minlength="<?= (int) InternetSupportModel::MIN_NARRATIVE_LENGTH ?>" maxlength="<?= (int) InternetSupportModel::MAX_NARRATIVE_LENGTH ?>" rows="5"><?= $old('contributions') ?></textarea>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>Tell us about your prospective plans for the period you are seeking internet support for <span style="color:#ef4444">*</span></label>
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

                            function val(name) {
                                var el = form.querySelector('input[name="' + name + '"]:checked');
                                return el ? el.value : '';
                            }

                            function update() {
                                var e = val('edits_80'), a = val('attended_ch'), t = val('tech_contributor');
                                var answered = e && a && t;
                                var eligible = t === 'yes' || (e === 'yes' && a === 'yes');
                                var blocked = answered && !eligible;
                                note.style.display = blocked ? 'block' : 'none';
                                submit.disabled = blocked;
                            }

                            form.addEventListener('change', update);
                            update();
                        })();
                    </script>

                <?php elseif ($type === 'reimbursement'): ?>
                    <form method="POST" action="/support?type=reimbursement" enctype="multipart/form-data" id="reimbursement-form">
                        <?= CSRF::getInputField() ?>
                        <input type="hidden" name="action" value="submit_reimbursement">

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>Your name <span style="color:#ef4444">*</span></label>
                            <input type="text" name="applicant_name" required value="<?= $oldName() ?>">
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>Was this a DCW-aligned, DCW-organised or DCW-associated event? <span style="color:#ef4444">*</span></label>
                            <select name="dcw_event" id="dcw-event" required>
                                <option value="">Select…</option>
                                <option value="yes">Yes</option>
                                <option value="no">No</option>
                            </select>
                            <div id="dcw-no-notice" class="alert-error" style="display:none; margin-top:10px;">
                                Reimbursement is only available for DCW-aligned, DCW-organised or DCW-associated events.
                            </div>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>Event name <span style="color:#ef4444">*</span></label>
                            <input type="text" name="event_name" required maxlength="255" placeholder="e.g. Wiki Loves Monuments 2026 Workshop">
                            <span style="font-size:13px; color:#64748b; margin-top:5px; display:block;">Type the name of the event you're claiming expenses for.</span>
                        </div>

                        <div class="form-group" style="margin-bottom:20px;">
                            <label>Event date <span style="color:#ef4444">*</span></label>
                            <input type="date" name="event_date" required
                                   min="<?= htmlspecialchars($minEventDate) ?>" max="<?= htmlspecialchars($maxEventDate) ?>">
                            <span style="font-size:13px; color:#64748b; margin-top:5px; display:block;">
                                Requests must be submitted within <?= (int) ReimbursementModel::CLAIM_WINDOW_DAYS ?> days of the event.
                            </span>
                        </div>

                        <h3>Expenses</h3>
                        <div id="line-items"></div>
                        <button type="button" id="add-line-item" style="width:auto; background:#fff; color:#106b9a; border:1px solid #106b9a; margin-bottom:20px;">+ Add expense</button>

                        <div style="background:#f1f5f9; padding:12px 16px; border-radius:6px; margin-bottom:20px; font-size:14px;">
                            Running total: ₹<span id="running-total">0.00</span>
                        </div>

                        <h3>Payment details</h3>
                        <p style="font-size:13px; color:#64748b;">
                            UPI is available for claims up to ₹<?= number_format($upiMaxRupees) ?>.
                            Larger claims are paid by bank transfer.
                        </p>

                        <div class="form-group" style="margin-bottom:15px;">
                            <label><input type="radio" name="payment_method" value="upi" id="method-upi" checked> UPI</label><br>
                            <label><input type="radio" name="payment_method" value="bank" id="method-bank"> Bank transfer</label>
                        </div>

                        <div id="upi-fields" style="display:none;">
                            <div class="form-group">
                                <label>UPI ID</label>
                                <input type="text" name="upi_id" placeholder="name@bank">
                            </div>
                        </div>

                        <div id="bank-fields" style="display:none;">
                            <div class="form-group">
                                <label>Account holder name</label>
                                <input type="text" name="bank_account_name">
                            </div>
                            <div class="form-group">
                                <label>Account number</label>
                                <input type="text" name="bank_account_number" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label>Confirm account number</label>
                                <input type="text" name="bank_account_number_confirm" autocomplete="off">
                            </div>
                            <div class="form-group">
                                <label>IFSC code</label>
                                <input type="text" name="bank_ifsc" style="text-transform:uppercase;">
                            </div>
                        </div>

                        <button type="submit" id="submit-btn" style="margin-top:10px;">Submit reimbursement request</button>
                    </form>

                    <template id="line-item-template">
                        <div class="line-item" style="background:#f8fafc; padding:15px; border-radius:8px; margin-bottom:12px; border:1px solid #e2e8f0;">
                            <div class="form-group">
                                <label>Category</label>
                                <select name="line_item_category[]" class="li-category">
                                    <?php foreach ($reimbursementSettings['expense_categories'] as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <input type="text" name="line_item_description[]">
                            </div>
                            <div class="form-group">
                                <label>Amount (₹)</label>
                                <input type="number" step="0.01" min="0.01" name="line_item_amount[]" class="li-amount">
                            </div>
                            <div class="form-group">
                                <label>Receipt <span style="font-weight:400; color:#64748b;">(optional, but recommended)</span></label>
                                <input type="file" name="__RECEIPT_NAME__" accept=".pdf,.jpg,.jpeg,.png">
                            </div>
                            <button type="button" class="remove-line-item" style="width:auto; background:#fff; color:#991b1b; border:1px solid #f87171;">Remove</button>
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
                    </script>
                <?php endif; ?>

            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>

</html>
