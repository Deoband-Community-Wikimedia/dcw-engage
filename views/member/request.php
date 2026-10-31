<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/member_requests.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';
require_once __DIR__ . '/../../models/InternetSupportModel.php';
require_once __DIR__ . '/../../models/CommunityRecordsModel.php';

/**
 * DCW Engage - one internet support or reimbursement request (/member/request?id=IS-... / RB-...).
 * Signed-in members only. The request is looked up with the SESSION email, never a typed one,
 * so a member can only open their own requests. Not-found looks the same whether the ID does
 * not exist or belongs to someone else.
 *
 * Open to every signed-in member, including expired ones: a request already in flight
 * must be finishable (reply to a reviewer, upload a receipt).
 *
 * Never shown here: payment details, the full phone number, uploaded receipts, internal staff notes.
 * The amounts shown are requested / approved / paid, with the reasons staff wrote for the member.
 *
 * Internet support, "Info Requested": the member gets an editable copy of what they submitted
 * (name, phone, operator, pack, price, validity, pack end date, reason, contributions, plans,
 * edits answer) and corrects it together with a note to the reviewer. The phone number is shown
 * masked and left blank to keep it. NOT editable: the Wikimedia username (it comes from the
 * verified profile when the request is made) and the Conversation Hours / technical-projects
 * answers (they always come from staff records; InternetSupportModel::resubmitDraft() keeps them
 * as stored whatever is posted).
 */
MemberSession::requireLogin();
$member = MemberSession::current();
$email  = strtolower((string) ($member['email'] ?? ''));

function req_discard_upload($path) {
    if (is_string($path) && strpos($path, 'uploads/') === 0 && strpos($path, '..') === false) {
        $full = __DIR__ . '/../../' . $path;
        if (is_file($full)) { @unlink($full); }
    }
}
function req_yes_no($v) {
    if ($v === null || $v === '') return '';
    return (int) $v === 1 ? 'yes' : 'no';
}

$trackingKey = strtoupper(trim((string) ($_POST['id'] ?? $_GET['id'] ?? '')));
$isRb = strpos($trackingKey, 'RB-') === 0;
$isIs = strpos($trackingKey, 'IS-') === 0;

$reimbursement = $reimbursementModel = $reimbDraft = $postedReimb = null;
$internet = $internetModel = $internetDraft = $postedDraft = null;
$reimbursementThread = $internetThread = [];
$error = $uploadMessage = '';
$chLocked = false;   // set true when a resubmit form is shown: attended_ch / tech_contributor are always read-only
$action = $_POST['action'] ?? '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost && !CSRF::validate($_POST['csrf_token'] ?? '')) {
    die("Invalid CSRF token.");
}

if ($isRb) {
    $reimbursementModel = new ReimbursementModel();
    $reimbursement = $reimbursementModel->getStatusForApplicant($trackingKey, $email);

    if ($reimbursement && $isPost && $action === 'resubmit_reimbursement') {
        $reply = trim($_POST['reply'] ?? '');
        $postedItems = []; $modelItems = []; $badAmount = false;
        foreach ((array) ($_POST['items'] ?? []) as $itemId => $row) {
            if (!is_array($row)) continue;
            $desc  = trim((string) ($row['description'] ?? ''));
            $amt   = trim((string) ($row['amount'] ?? ''));
            $paise = ReimbursementModel::rupeesToPaise($amt);
            if ($paise === null) $badAmount = true;
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
} elseif ($isIs) {
    $internetModel = new InternetSupportModel();
    $internet = $internetModel->getStatusForApplicant($trackingKey, $email);

    if ($internet && $isPost && $action === 'upload_receipt') {
        if ($internet['status'] !== 'Awaiting Receipt') {
            $error = "This request isn't waiting for a receipt right now.";
        } else {
            $path = null;
            try {
                require_once __DIR__ . '/../../models/FileUploader.php';
                // The tracking ID goes in the filename instead of a name: unique, and no personal data in the URL.
                $path = (new FileUploader())->handleUpload($_FILES['receipt'] ?? [], 'receipt', $trackingKey, 'internet');
                if (!$path) $error = "Please choose a file to upload.";
            } catch (Exception $ex) {
                // FileUploader messages are written for the user (size, type, etc).
                $error = engage_user_message($ex, 'Receipt upload');
            }
            if ($path) {
                try {
                    if ($internetModel->submitReceipt($trackingKey, $email, $path)) {
                        $uploadMessage = "Thank you. Your receipt has been uploaded and our finance team will check it.";
                        $internet = $internetModel->getStatusForApplicant($trackingKey, $email);
                    } else {
                        req_discard_upload($path);
                        $error = "This request isn't waiting for a receipt right now.";
                    }
                } catch (Exception $ex) {
                    req_discard_upload($path);
                    app_log("Internet support receipt save failed for $trackingKey: " . $ex->getMessage());
                    $error = "Something went wrong saving your receipt. Please try again.";
                }
            }
        }
    } elseif ($internet && $isPost && $action === 'resubmit_draft') {
        $reply = trim((string) ($_POST['reply'] ?? ''));
        // No wikimedia_username here on purpose: it is not editable after the request is made.
        // The model also re-applies the locked attended_ch / tech_contributor values from the stored request.
        $postedDraft = [
            'applicant_name'   => trim((string) ($_POST['applicant_name'] ?? '')),
            'phone'            => trim((string) ($_POST['phone'] ?? '')),
            'operator'         => trim((string) ($_POST['operator'] ?? '')),
            'package_name'     => trim((string) ($_POST['package_name'] ?? '')),
            'amount'           => trim((string) ($_POST['amount'] ?? '')),
            'validity_days'    => trim((string) ($_POST['validity_days'] ?? '')),
            'pack_ends_on'     => trim((string) ($_POST['pack_ends_on'] ?? '')),
            'reason'           => trim((string) ($_POST['reason'] ?? '')),
            'contributions'    => trim((string) ($_POST['contributions'] ?? '')),
            'plans'            => trim((string) ($_POST['plans'] ?? '')),
            'edits_80'         => (string) ($_POST['edits_80'] ?? ''),
            'attended_ch'      => (string) ($_POST['attended_ch'] ?? ''),
            'tech_contributor' => (string) ($_POST['tech_contributor'] ?? ''),
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

    if ($internet && $internet['status'] === 'Info Requested') {
        $internetThread = $internetModel->getMessagesForApplicant($trackingKey, $email);
        $internetDraft  = $internetModel->getDraftForApplicant($trackingKey, $email);
        if ($internetDraft) {
            foreach (['edits_80', 'attended_ch', 'tech_contributor'] as $k) {
                $internetDraft[$k] = req_yes_no($internetDraft[$k]);
            }
            // wikimedia_username is shown read-only, straight from the saved request.
            foreach (['wikimedia_username', 'contributions', 'plans', 'applicant_name', 'operator',
                      'package_name', 'amount', 'validity_days', 'pack_ends_on', 'phone_masked'] as $k) {
                $internetDraft[$k] = (string) ($internetDraft[$k] ?? '');
            }
            // These two answers are always fixed (the model enforces it too).
            // The form shows them as read-only text, so keep them on screen if the form comes back with an error.
            $chLocked = true;
            $keep = [];
            if ($chLocked) {
                foreach (['attended_ch', 'tech_contributor'] as $k) {
                    if ($internetDraft[$k] !== '') $keep[$k] = $internetDraft[$k];
                }
            }
            if ($postedDraft) {
                $internetDraft = array_merge($internetDraft, $postedDraft, $keep);
            }
            // The phone number is never echoed back: a blank field always means "keep the current number".
            $internetDraft['phone'] = '';
        }
    }
}

$found = $reimbursement ?: $internet;
$notFound = !$found;
// What the member sees: internal states are masked ("Recharge Failed" -> "In review").
$statusLabel = '';
if ($found) {
    [$statusLabel] = dash_status($isIs ? 'internet' : 'reimbursement', (string) $found['status']);
}

$e = fn($s) => htmlspecialchars((string) $s);
$renderThread = function (array $thread) use ($e) {
    foreach ($thread as $m) {
        echo '<div style="margin-bottom:10px; padding:10px 12px; border:1px solid var(--border); border-radius:10px; font-size:14px;">'
           . '<div style="font-size:12px; color:var(--muted);">'
           . ($m['sender'] === 'reviewer' ? 'DCW reviewer' : 'You')
           . ' · ' . $e(dash_datetime($m['created_at'])) . '</div>'
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
// Same as $yesNoSelect, but shows the stored answer as read-only text when it is locked.
// A request from before the eligibility questions existed has no stored answer, so it stays a select.
$yesNoOrLocked = function ($name, $val, $locked) use ($e, $yesNoSelect) {
    if ($locked && $val !== '') {
        return '<input type="text" value="' . ($val === 'yes' ? 'Yes' : 'No') . '" readonly>'
             . '<span class="hint">Fixed when you made the request. It can&rsquo;t be changed here.</span>';
    }
    return $yesNoSelect($name, $val);
};
$todayIst = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

// Bounds for the editable internet support form (same window the model enforces, in India time).
$isSettings  = $internetModel ? $internetModel->getSettings() : null;
$isMaxRupees = $isSettings ? (int) ($isSettings['max_amount_paise'] / 100) : 0;
$_todayIst   = new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'));
$packEndMin  = $_todayIst->modify('-' . InternetSupportModel::PACK_END_MAX_PAST_DAYS . ' days')->format('Y-m-d');
$packEndMax  = $_todayIst->modify('+' . InternetSupportModel::PACK_END_MAX_AHEAD_DAYS . ' days')->format('Y-m-d');

engage_header([
    'title'   => 'My request',
    'heading' => 'My request',
    'kicker'  => $found ? $found['tracking_id'] : 'Request details',
    'lead'    => 'Status and next steps for your support request.',
    'member'  => $member,
    'tools'   => engage_member_menu($member, ['current' => '/member/support']),
    'crumbs'  => [['Home', '/'], ['My dashboard', '/member/dashboard'], ['My request']],
]);
?>
<div class="fcard">
    <?php if ($error): ?><div class="alert error"><strong>Notice:</strong> <?= $e($error) ?></div><?php endif; ?>
    <?php if ($uploadMessage): ?><div class="alert ok"><?= $e($uploadMessage) ?></div><?php endif; ?>

    <?php if ($notFound): ?>
        <div class="alert error">We couldn't find that request in your account. Check the tracking ID, or open it from your dashboard.</div>
        <p><a class="btn-pill" href="/member/dashboard">Back to my dashboard</a></p>
    <?php endif; ?>

    <?php if ($reimbursement): ?>
        <div class="result">
            <h3>Reimbursement — <?= $e($reimbursement['event_name']) ?></h3>
            Tracking ID: <code><?= $e($reimbursement['tracking_id']) ?></code><br>
            <?php
            $st = (string) $reimbursement['status'];
            foreach (member_amount_rows(
                'Amount claimed', (int) $reimbursement['total_amount_paise'],
                (int) $reimbursement['approved_paise'], (int) $reimbursement['paid_paise'],
                $reimbursement['approved_amount_note'], $reimbursement['paid_amount_note'],
                in_array($st, ['Approved for Payment', 'Paid'], true), $st === 'Paid', 'Amount paid'
            ) as [$amtLabel, $amtValue, $amtWhy]): ?>
                <?= $e($amtLabel) ?>: <strong><?= $e($amtValue) ?></strong><br>
                <?php if ($amtWhy !== ''): ?>
                    <span style="font-size:14px; color:var(--muted);">Reason for the difference: <?= nl2br($e($amtWhy)) ?></span><br>
                <?php endif; ?>
            <?php endforeach; ?>
            Status: <strong><?= $e($statusLabel) ?></strong><br>
            <?php if ($reimbursement['status'] === 'Paid'): ?>
                <?php if (!empty($reimbursement['payment_reference'])): ?>Transaction reference: <strong><?= $e($reimbursement['payment_reference']) ?></strong><br><?php endif; ?>
                <?php if (!empty($reimbursement['paid_at'])): ?>Paid on: <?= $e(dash_date($reimbursement['paid_at'])) ?><br><?php endif; ?>
            <?php endif; ?>
            <?php if ($reimbursement['status'] === 'Rejected' && !empty($reimbursement['admin_notes'])): ?>
                Reviewer notes: <?= nl2br($e($reimbursement['admin_notes'])) ?><br>
            <?php endif; ?>
            Submitted: <?= $e(dash_date($reimbursement['created_at'])) ?>
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
                    <input type="hidden" name="id" value="<?= $e($reimbursement['tracking_id']) ?>">

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
            <?php
            $st = (string) $internet['status'];
            $internetMoneyMoved = in_array($st, ['Awaiting Receipt', 'Receipt Submitted', 'Closed'], true);
            foreach (member_amount_rows(
                'Amount requested', (int) $internet['package_price_paise'],
                (int) $internet['approved_paise'], (int) $internet['paid_paise'],
                $internet['approved_amount_note'], $internet['paid_amount_note'],
                $internetMoneyMoved || $st === 'Approved for Support', $internetMoneyMoved, 'Amount recharged'
            ) as [$amtLabel, $amtValue, $amtWhy]): ?>
                <?= $e($amtLabel) ?>: <strong><?= $e($amtValue) ?></strong><br>
                <?php if ($amtWhy !== ''): ?>
                    <span style="font-size:14px; color:var(--muted);">Reason for the difference: <?= nl2br($e($amtWhy)) ?></span><br>
                <?php endif; ?>
            <?php endforeach; ?>
            Status: <strong><?= $e($statusLabel) ?></strong><br>
            <?php if ($internet['status'] === 'Rejected' && !empty($internet['admin_notes'])): ?>
                Reviewer notes: <?= nl2br($e($internet['admin_notes'])) ?><br>
            <?php endif; ?>
            <?php if (!empty($internet['recharge_reference']) && in_array($internet['status'], ['Awaiting Receipt', 'Receipt Submitted', 'Closed'], true)): ?>
                Recharge reference: <strong><?= $e($internet['recharge_reference']) ?></strong><br>
            <?php endif; ?>
            Submitted: <?= $e(dash_date($internet['created_at'])) ?>
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
                    Your number has been recharged with ₹<?= $e(number_format(((int) $internet['paid_paise']) / 100, 2)) ?>.
                    Please upload the operator's receipt or confirmation
                    (PDF, JPG or PNG, up to 10 MB) so we can close the request.
                </p>
                <form method="POST" enctype="multipart/form-data">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="upload_receipt">
                    <input type="hidden" name="id" value="<?= $e($internet['tracking_id']) ?>">
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
                    The reviewer needs more information. Read their message, correct anything that is wrong below,
                    add a short note, and resubmit. Only your Wikimedia username and the attendance / technical
                    status checked by our staff cannot be changed.
                </p>
                <?php $renderThread($internetThread); ?>
                <form method="POST">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="resubmit_draft">
                    <input type="hidden" name="id" value="<?= $e($internet['tracking_id']) ?>">

                    <div class="row">
                        <div class="field">
                            <label>Your full name <span class="req-star">*</span></label>
                            <input type="text" name="applicant_name" required maxlength="255" value="<?= $e($d['applicant_name']) ?>">
                        </div>
                        <div class="field">
                            <label>Wikimedia username</label>
                            <input type="text" value="<?= $e($d['wikimedia_username']) ?>" readonly>
                            <span class="hint">Taken from your verified profile. It can't be changed here.</span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="field">
                            <label>Mobile number to be recharged</label>
                            <input type="tel" name="phone" maxlength="20" placeholder="Leave blank to keep <?= $e($d['phone_masked']) ?>" value="">
                            <span class="hint">Enter a number only if you need to correct it. Visible only to the finance team.</span>
                        </div>
                        <div class="field">
                            <label>Mobile operator <span class="req-star">*</span></label>
                            <input type="text" name="operator" required maxlength="50" value="<?= $e($d['operator']) ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label>Data pack required <span class="req-star">*</span></label>
                        <input type="text" name="package_name" required minlength="3" maxlength="120" value="<?= $e($d['package_name']) ?>">
                    </div>

                    <div class="row">
                        <div class="field">
                            <label>Pack price (₹) <span class="req-star">*</span></label>
                            <input type="number" name="amount" required min="1" <?= $isMaxRupees > 0 ? 'max="' . $isMaxRupees . '"' : '' ?> step="0.01" value="<?= $e($d['amount']) ?>">
                            <?php if ($isMaxRupees > 0): ?><span class="hint">Maximum limit is ₹<?= number_format($isMaxRupees) ?>.</span><?php endif; ?>
                        </div>
                        <div class="field">
                            <label>Validity (days)</label>
                            <input type="number" name="validity_days" min="1" max="365" step="1" placeholder="optional" value="<?= $e($d['validity_days']) ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label>When does your current pack expire?<?= $d['pack_ends_on'] !== '' ? ' <span class="req-star">*</span>' : '' ?></label>
                        <?php $minDate = ($d['pack_ends_on'] !== '' && $d['pack_ends_on'] < $packEndMin) ? $d['pack_ends_on'] : $packEndMin; ?>
                        <input type="date" name="pack_ends_on" <?= $d['pack_ends_on'] !== '' ? 'required' : '' ?>
                               min="<?= $e($minDate) ?>" max="<?= $e($packEndMax) ?>" value="<?= $e($d['pack_ends_on']) ?>">
                    </div>

                    <div class="field">
                        <label>80+ manual edits last month <span class="req-star">*</span></label>
                        <?= $yesNoSelect('edits_80', $d['edits_80']) ?>
                    </div>
                    <div class="field">
                        <label>Attended last 3 Conversation Hours <span class="req-star">*</span></label>
                        <?= $yesNoOrLocked('attended_ch', $d['attended_ch'], $chLocked) ?>
                    </div>
                    <div class="field">
                        <label>Active on DCW technical projects <span class="req-star">*</span></label>
                        <?= $yesNoOrLocked('tech_contributor', $d['tech_contributor'], $chLocked) ?>
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

    <?php if ($found): ?>
        <p style="margin-top:18px;"><a href="/member/dashboard">&larr; Back to my dashboard</a></p>
    <?php endif; ?>
</div>
<?php engage_footer(); ?>
