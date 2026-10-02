<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../models/InternetSupportModel.php';
require_once __DIR__ . '/../models/EmailVerificationModel.php';

// Like the global reimbursement form, email verification isn't tied to any
// specific form row, so form_id is NULL (see EmailVerificationModel's note
// on the NULL-safe <=> comparisons).
const INTERNET_FORM_ID = null;

$model = new InternetSupportModel();
$settings = $model->getSettings();

// Switched off (internet_settings.is_active = 0) or not set up yet.
if (!$model->isOpen()) {
    http_response_code(404);
    require __DIR__ . '/forms/not_found.php';
    die();
}

$errors = [];
$success = null;

// ------------------------------------------------------------------
// Email verification: GET stages the token, POST confirms it
// (scanner-prefetch safe, same as reimbursement.php).
// ------------------------------------------------------------------
$verifiedEmail = '';
$pendingVerifyToken = null;
$verifySent = false;

if (isset($_GET['verify'])) {
    $pendingVerifyToken = (string) $_GET['verify'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_verification') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $verifiedFor = (new EmailVerificationModel())->consume(INTERNET_FORM_ID, (string) ($_POST['verify_token'] ?? ''));

    if ($verifiedFor) {
        $_SESSION['verified_email_internet'] = $verifiedFor;
        header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        exit;
    }

    $errors['verify'] = "That verification link has expired or was already used. Enter your email below to get a new one.";
}

$verifiedEmail = $_SESSION['verified_email_internet'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_verification') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Please enter a valid email address.";
    } else {
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $config = require __DIR__ . '/../includes/config.php';
            $email = strtolower($email);
            $issued = (new EmailVerificationModel())->request(INTERNET_FORM_ID, $email);

            if ($issued) {
                $verifyUrl = rtrim($config['app']['url'], '/') . '/internet-support?verify=' . urlencode($issued['token']);
                Mailer::sendInternetVerification($email, $verifyUrl, $issued['expires_at']);
            }
            // Same response whether or not a link was issued.
            $verifySent = true;
        } catch (Exception $e) {
            app_log("Internet support verification request failed for <$email>: " . $e->getMessage());
            $errors['system'] = "Something went wrong sending your verification email. Please try again.";
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_email') {
    unset($_SESSION['verified_email_internet']);
    $verifiedEmail = '';
}

// ------------------------------------------------------------------
// Submission
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_internet_request') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    if ($verifiedEmail === '') {
        $errors['system'] = "Please verify your email before submitting.";
    } else {
        try {
            $applicantName = trim($_POST['applicant_name'] ?? '');

            // Eligibility and narrative answers are validated inside the model,
            // so the rules live in one place.
            $result = $model->createRequest(
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
                    'edits_80'         => $_POST['edits_80'] ?? '',
                    'attended_ch'      => $_POST['attended_ch'] ?? '',
                    'tech_contributor' => $_POST['tech_contributor'] ?? '',
                    'contributions'    => $_POST['contributions'] ?? '',
                    'plans'            => $_POST['plans'] ?? '',
                ]
            );

            require_once __DIR__ . '/../includes/mailer.php';
            Mailer::sendInternetReceived($verifiedEmail, $applicantName, $result['tracking_id']);

            $success = $result;
        } catch (\InvalidArgumentException $e) {
            $errors['system'] = $e->getMessage();
        } catch (Exception $e) {
            app_log("Internet support submission failed for <$verifiedEmail>: " . $e->getMessage());
            $errors['system'] = "An error occurred submitting your request. Please try again.";
        }
    }
}

$showConfirm = $pendingVerifyToken !== null && $verifiedEmail === '';
$showGate = $verifiedEmail === '' && !$showConfirm;

$old = function ($key) {
    return htmlspecialchars((string) ($_POST[$key] ?? ''));
};

$checked = function ($key, $value) {
    return (($_POST[$key] ?? '') === $value) ? 'checked' : '';
};

$maxAmountRupees = (int) ($settings['max_amount_paise'] / 100);

$eligibilityQuestions = [
    'edits_80'         => 'Have you made 80+ edits to Wikimedia projects in the past month, without using automated tools like Depictor?',
    'attended_ch'      => 'Have you attended the past 3 DCW Conversation Hours?',
    'tech_contributor' => 'Are you actively contributing to DCW technical projects?',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internet Support Request - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>

<body>
    <div class="container">
        <h1 style="margin-top:0;">Internet Support Request</h1>
        <p style="color:#475569; font-size:15px; line-height:1.6; margin-bottom:30px;">
            DCW volunteers can request help with a data pack. A reviewer checks the request, our finance team
            does the recharge, and you then upload the operator's receipt here so we can close it.
            One request can be made every <?= (int) InternetSupportModel::MIN_DAYS_BETWEEN_REQUESTS ?> days.
        </p>

        <?php if ($success): ?>
            <div class="alert-success">
                <h3 style="margin-top:0">Request submitted</h3>
                Your tracking ID is <strong><?= htmlspecialchars($success['tracking_id']) ?></strong>.
                We've emailed you a confirmation. Use the
                <a href="/track" style="color:#106b9a;">tracking page</a> with this ID and your email to follow it.
            </div>

        <?php else: ?>

            <?php if (!empty($errors['system']) || !empty($errors['email']) || !empty($errors['verify'])): ?>
                <div class="alert-error">
                    <strong>Notice:</strong> <?= htmlspecialchars($errors['system'] ?? $errors['email'] ?? $errors['verify']) ?>
                </div>
            <?php endif; ?>

            <?php if ($showConfirm): ?>
                <div class="alert-success" style="margin-bottom:20px;">
                    <h3 style="margin-top:0">Confirm your email</h3>
                    Click below to finish verifying and continue to your request.
                </div>
                <form method="POST" action="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>" style="margin-bottom:30px;">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="confirm_verification">
                    <input type="hidden" name="verify_token" value="<?= htmlspecialchars($pendingVerifyToken) ?>">
                    <button type="submit">Continue to internet support request</button>
                </form>

            <?php elseif ($showGate): ?>
                <?php if ($verifySent): ?>
                    <div class="alert-success">
                        <h3 style="margin-top:0">Check your inbox</h3>
                        If <strong><?= htmlspecialchars($email ?? '') ?></strong> can receive email, a verification link is on its way.
                    </div>
                <?php endif; ?>
                <form method="POST" style="background:#f8fafc; padding:20px; border-radius:8px; margin-bottom:30px; border:1px solid #e2e8f0;">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="request_verification">
                    <div class="form-group" style="margin-bottom:15px;">
                        <label>Verify your email to begin <span style="color:#ef4444">*</span></label>
                        <input type="email" name="email" value="<?= htmlspecialchars($email ?? '') ?>" required>
                    </div>
                    <button type="submit"><?= $verifySent ? 'Send a new link' : 'Send verification link' ?></button>
                </form>

            <?php else: ?>

                <form method="POST" style="margin:0 0 20px; font-size:14px; color:#475569;">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="change_email">
                    Verified as <strong><?= htmlspecialchars($verifiedEmail) ?></strong> ✓
                    <button type="submit" formnovalidate
                        style="background:none; border:none; color:#106b9a; padding:0 0 0 6px; width:auto; font-size:14px; font-weight:500; text-decoration:underline; cursor:pointer;">Use a different email</button>
                </form>

                <form method="POST">
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
                            Based on your answers you're not eligible for support right now. You need 80+ manual edits
                            in the past month and attendance at the last 3 Conversation Hours, or active contribution
                            to DCW technical projects.
                        </div>
                    </fieldset>

                    <div class="form-group" style="margin-bottom:20px;">
                        <label>Your name <span style="color:#ef4444">*</span></label>
                        <input type="text" name="applicant_name" required maxlength="255" value="<?= $old('applicant_name') ?>">
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
                        <label>Why do you need this? <span style="color:#ef4444">*</span></label>
                        <textarea name="reason" required minlength="<?= (int) InternetSupportModel::MIN_REASON_LENGTH ?>" maxlength="<?= (int) InternetSupportModel::MAX_REASON_LENGTH ?>" rows="5"><?= $old('reason') ?></textarea>
                        <span style="font-size:13px; color:#64748b; margin-top:5px; display:block;">
                            For example, the DCW work you'll do online with it.
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
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>

</html>
