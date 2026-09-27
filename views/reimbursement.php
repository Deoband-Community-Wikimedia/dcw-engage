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
// Eligibility — checked globally now (accepted into ANY event, or on the
// global eligibility list), only once verified.
// ------------------------------------------------------------------
$isEligible = false;
if ($verifiedEmail !== '') {
    $isEligible = $reimbursementModel->isEligible($verifiedEmail);
}

// ------------------------------------------------------------------
// Submission
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_reimbursement') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $eventName = trim($_POST['event_name'] ?? '');

    if ($verifiedEmail === '' || !$isEligible) {
        $errors['system'] = "Please verify your email and confirm eligibility before submitting.";
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

                $fileKey = "line_item_receipt_$i";
                if (empty($_FILES[$fileKey]['name'])) {
                    throw new \InvalidArgumentException("Row " . ($i + 1) . ": a receipt is required.");
                }

                $path = $fileUploader->handleUpload($_FILES[$fileKey], $fileKey, $verifiedEmail, 'reimbursement');
                if ($path) {
                    $uploadedPaths[] = $path;
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
                $settings, $verifiedEmail, $applicantName, $eventName, $payment, $lineItems
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
$cashThresholdRupees = $settings['cash_threshold_paise'] / 100;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reimbursement Request - DCW Engage</title>
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

            <?php elseif (!$isEligible): ?>
                <div class="alert-error">
                    <strong>Not eligible:</strong> we don't have a record of <?= htmlspecialchars($verifiedEmail) ?>
                    being eligible for reimbursement. If you believe this is a mistake, contact the organizers.
                </div>

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
                        <label>Event name <span style="color:#ef4444">*</span></label>
                        <input type="text" name="event_name" required maxlength="255" placeholder="e.g. Wiki Loves Monuments 2026 Workshop">
                        <span style="font-size:13px; color:#64748b; margin-top:5px; display:block;">Type the name of the event you're claiming expenses for.</span>
                    </div>

                    <h3>Expenses</h3>
                    <div id="line-items"></div>
                    <button type="button" id="add-line-item" style="width:auto; background:#fff; color:#106b9a; border:1px solid #106b9a; margin-bottom:20px;">+ Add expense</button>

                    <div style="background:#f1f5f9; padding:12px 16px; border-radius:6px; margin-bottom:20px; font-size:14px;">
                        Running total: ₹<span id="running-total">0.00</span>
                    </div>

                    <h3>Payment details</h3>
                    <p style="font-size:13px; color:#64748b;">
                        Cash is only available for totals of ₹<?= number_format($cashThresholdRupees, 2) ?> or less.
                    </p>

                    <div class="form-group" style="margin-bottom:15px;">
                        <label><input type="radio" name="payment_method" value="cash" id="method-cash" checked> Cash</label><br>
                        <label><input type="radio" name="payment_method" value="upi" id="method-upi"> UPI</label><br>
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

                    <button type="submit" style="margin-top:10px;">Submit reimbursement request</button>
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
                <label>Receipt</label>
                <input type="file" name="__RECEIPT_NAME__" accept=".pdf,.jpg,.jpeg,.png">
            </div>
            <button type="button" class="remove-line-item" style="width:auto; background:#fff; color:#991b1b; border:1px solid #f87171;">Remove</button>
        </div>
    </template>

    <script>
        const CASH_THRESHOLD_RUPEES = <?= json_encode($cashThresholdRupees) ?>;
        let lineItemCount = 0;

        function addLineItem() {
            const template = document.getElementById('line-item-template');
            const clone = template.content.cloneNode(true);
            const receiptInput = clone.querySelector('input[type="file"]');
            receiptInput.name = 'line_item_receipt_' + lineItemCount;

            clone.querySelector('.remove-line-item').addEventListener('click', function (e) {
                e.target.closest('.line-item').remove();
                recomputeTotal();
            });
            clone.querySelector('.li-amount').addEventListener('input', recomputeTotal);

            document.getElementById('line-items').appendChild(clone);
            lineItemCount++;
        }

        function recomputeTotal() {
            const amounts = document.querySelectorAll('.li-amount');
            let total = 0;
            amounts.forEach(input => {
                const val = parseFloat(input.value);
                if (!isNaN(val)) total += val;
            });
            document.getElementById('running-total').textContent = total.toFixed(2);

            // Client-side hint only — the server re-validates the threshold
            // against the real computed total regardless of what's shown here.
            const cashRadio = document.getElementById('method-cash');
            if (total > CASH_THRESHOLD_RUPEES) {
                cashRadio.disabled = true;
                if (cashRadio.checked) {
                    document.getElementById('method-upi').checked = true;
                    togglePaymentFields();
                }
            } else {
                cashRadio.disabled = false;
            }
        }

        function togglePaymentFields() {
            const method = document.querySelector('input[name="payment_method"]:checked')?.value;
            document.getElementById('upi-fields').style.display = method === 'upi' ? 'block' : 'none';
            document.getElementById('bank-fields').style.display = method === 'bank' ? 'block' : 'none';
        }

        document.getElementById('add-line-item')?.addEventListener('click', addLineItem);
        document.querySelectorAll('input[name="payment_method"]').forEach(el => el.addEventListener('change', togglePaymentFields));

        // Start with one line item row.
        if (document.getElementById('line-items')) {
            addLineItem();
            togglePaymentFields();
        }
    </script>
</body>

</html>
