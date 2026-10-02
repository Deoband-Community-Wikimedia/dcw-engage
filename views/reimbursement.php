<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/wikitext.php';
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../models/ReimbursementSettingsModel.php';
require_once __DIR__ . '/../models/ReimbursementModel.php';
require_once __DIR__ . '/../models/EmailVerificationModel.php';

// One global reimbursement form, not one per event — the applicant types
// the event name themselves in the form below rather than this page being
// scoped to a specific row in `forms`. Email verification therefore isn't
// tied to any particular form either: $formId is passed as null throughout
// (see the NULL-safe <=> comparisons added to EmailVerificationModel for
// exactly this case), meaning "verified once, usable regardless of which
// event they're claiming for."
const REIMBURSEMENT_FORM_ID = null;

$settingsModel = new ReimbursementSettingsModel();
$settings = $settingsModel->get();

if (!$settings || !$settings['is_active']) {
    http_response_code(404);
    require __DIR__ . '/forms/not_found.php';
    die();
}

$reimbursementModel = new ReimbursementModel();
$errors = [];
$success = null;

// ------------------------------------------------------------------
// Email verification — identical GET-stages/POST-confirms pattern as
// views/forms/renderer.php (scanner-prefetch safe).
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

    $verifiedFor = (new EmailVerificationModel())->consume(REIMBURSEMENT_FORM_ID, (string) ($_POST['verify_token'] ?? ''));

    if ($verifiedFor) {
        $_SESSION['verified_email_reimbursement'] = $verifiedFor;
        header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        exit;
    }

    $errors['verify'] = "That verification link has expired or was already used. Enter your email below to get a new one.";
}

$verifiedEmail = $_SESSION['verified_email_reimbursement'] ?? '';

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
            $issued = (new EmailVerificationModel())->request(REIMBURSEMENT_FORM_ID, $email);

            if ($issued) {
                $verifyUrl = rtrim($config['app']['url'], '/') . '/reimbursement?verify=' . urlencode($issued['token']);
                Mailer::sendReimbursementVerification($email, 'your reimbursement request', $verifyUrl, $issued['expires_at']);
            }
            $verifySent = true;
        } catch (Exception $e) {
            app_log("Reimbursement verification request failed for <$email>: " . $e->getMessage());
            $errors['system'] = "Something went wrong sending your verification email. Please try again.";
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_email') {
    unset($_SESSION['verified_email_reimbursement']);
    $verifiedEmail = '';
}

// ------------------------------------------------------------------
// Eligibility is no longer checked here. Any verified email may submit —
// see ReimbursementModel's class docblock. isEligible() still exists and
// is used by reimbursement_review.php as an informational flag for
// reviewers ("no prior acceptance or allowlist match"), so organizers can
// discard requests from people with no real connection to any event
// without an email being sent — see ReimbursementModel::discard().
// ------------------------------------------------------------------

// ------------------------------------------------------------------
// Submission
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_reimbursement') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $eventName = trim($_POST['event_name'] ?? '');
    $eventDate = trim($_POST['event_date'] ?? '');
    $dcwEvent  = $_POST['dcw_event'] ?? '';

    if ($verifiedEmail === '') {
        $errors['system'] = "Please verify your email before submitting.";
    } elseif ($dcwEvent === '') {
        $errors['system'] = "Please tell us whether this was a DCW-aligned, DCW-organised or DCW-associated event.";
    } elseif ($dcwEvent !== 'yes') {
        $errors['system'] = "Reimbursement is only available for events that are DCW-aligned, DCW-organised or DCW-associated.";
    } elseif ($eventName === '') {
        $errors['system'] = "Please enter the name of the event.";
    } elseif ($reimbursementModel->hasOpenOrPaidRequest($verifiedEmail, $eventName)) {
        $errors['system'] = "You already have a reimbursement request on file for \"" . htmlspecialchars($eventName) . "\".";
    } else {
        require_once __DIR__ . '/../models/FileUploader.php';
        $fileUploader = new FileUploader();

        $categories = $_POST['line_item_category'] ?? [];
        $descriptions = $_POST['line_item_description'] ?? [];
        $amounts = $_POST['line_item_amount'] ?? [];
        $uploadedPaths = [];
        $lineItems = [];

        try {
            $rowCount = count($categories);
            for ($i = 0; $i < $rowCount; $i++) {
                $category = trim($categories[$i] ?? '');
                $description = trim($descriptions[$i] ?? '');
                $amountRupees = trim($amounts[$i] ?? '');

                // Skip a fully-empty trailing row (e.g. user clicked "Add
                // expense" once too many and left it blank).
                if ($category === '' && $description === '' && $amountRupees === '') {
                    continue;
                }

                if ($category === '' || $description === '' || !is_numeric($amountRupees) || (float) $amountRupees <= 0) {
                    throw new \InvalidArgumentException("Row " . ($i + 1) . ": category, description, and a positive amount are all required.");
                }

                // Receipts are optional. '' means none was attached; if one
                // was, it is kept until finance has downloaded it and the
                // purge cron (bin/purge_receipts.php) removes it.
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
                $settings, $verifiedEmail, $applicantName, $eventName, $eventDate, $payment, $lineItems
            );

            require_once __DIR__ . '/../includes/mailer.php';
            Mailer::sendReimbursementReceived($verifiedEmail, $applicantName, $result['tracking_id'], $eventName);

            $success = $result;
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

$showConfirm = $pendingVerifyToken !== null && $verifiedEmail === '';
$showGate = $verifiedEmail === '' && !$showConfirm;

// Same India-time window the model enforces, used only to bound the date picker.
$_tz = new DateTimeZone('Asia/Kolkata');
$_today = new DateTimeImmutable('today', $_tz);
$maxEventDate = $_today->format('Y-m-d');
$minEventDate = $_today->modify('-' . ReimbursementModel::CLAIM_WINDOW_DAYS . ' days')->format('Y-m-d');
$upiMaxRupees = ReimbursementModel::UPI_MAX_PAISE / 100;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reimbursement Request - DCW Engage</title>
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>

<body>
    <div class="container">
        <h1 style="margin-top:0;">Reimbursement Request</h1>

        <?php if (!empty($settings['instructions'])): ?>
            <div style="color:#475569; font-size:15px; margin-bottom:30px; line-height:1.6;">
                <?= MiniWikiText::render($settings['instructions']) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-success">
                <h3 style="margin-top:0">Request submitted</h3>
                Your tracking ID is <strong><?= htmlspecialchars($success['tracking_id']) ?></strong>.
                We've emailed you a confirmation. You'll be notified by email once it's reviewed.
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
                    Click below to finish verifying and continue to your reimbursement request.
                </div>
                <form method="POST" action="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>" style="margin-bottom:30px;">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="confirm_verification">
                    <input type="hidden" name="verify_token" value="<?= htmlspecialchars($pendingVerifyToken) ?>">
                    <button type="submit">Continue to reimbursement request</button>
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

                <form method="POST" enctype="multipart/form-data" id="reimbursement-form">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="submit_reimbursement">

                    <div class="form-group" style="margin-bottom:20px;">
                        <label>Your name <span style="color:#ef4444">*</span></label>
                        <input type="text" name="applicant_name" required>
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
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <template id="line-item-template">
        <div class="line-item" style="background:#f8fafc; padding:15px; border-radius:8px; margin-bottom:12px; border:1px solid #e2e8f0;">
            <div class="form-group">
                <label>Category</label>
                <select name="line_item_category[]" class="li-category">
                    <?php foreach ($settings['expense_categories'] as $cat): ?>
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
        // file inputs must always be numbered by their current position. Without
        // this, removing an earlier row would silently detach a later row's receipt.
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

            // Client-side convenience only — the server re-checks against the
            // real computed total. Above the UPI cap, bank transfer is the only option.
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

        document.getElementById('add-line-item')?.addEventListener('click', addLineItem);
        document.querySelectorAll('input[name="payment_method"]').forEach(el => el.addEventListener('change', togglePaymentFields));
        document.getElementById('dcw-event')?.addEventListener('change', toggleDcwNotice);
        document.getElementById('reimbursement-form')?.addEventListener('submit', renumberReceipts);

        // Start with one line item row.
        if (document.getElementById('line-items')) {
            addLineItem();
            togglePaymentFields();
        }
    </script>
</body>

</html>
