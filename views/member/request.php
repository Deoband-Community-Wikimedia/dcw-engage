<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/member_requests.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';
require_once __DIR__ . '/../../models/ReimbursementSettingsModel.php';
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
 *
 * Reimbursement, "Info Requested": the member sees the reviewer's question, the date by
 * which they need to reply (ReimbursementModel::INFO_EXPIRY_DAYS days after the question; after that
 * bin/expire_info_requests.php closes the request as unsuccessful), and an editable copy of the claim.
 * They can fix the event name/date and, for every expense, the category, description, amount and
 * receipt (attach, replace or remove), and they can add or remove expenses. NOT editable: the payment
 * details (never shown). Receipt paths are never sent to the browser; the form only knows whether a
 * receipt is on file.
 *
 * Receipt uploads on resubmit: new files are saved first, then ReimbursementModel::resubmitDraft()
 * stores the claim. If anything fails, the new files are deleted again and the member is asked to pick
 * them again (a browser cannot refill a file input). If it works, files that were replaced, removed or
 * belonged to a deleted expense are deleted.
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
$reimbRows = [];   // expense rows for the reimbursement edit form (see below)
$reimbCategories = [];
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

    // The expense categories offered on the form (a new or changed category must be one of them).
    try {
        $rbSettings = (new ReimbursementSettingsModel())->get();
        $reimbCategories = $rbSettings ? array_values((array) $rbSettings['expense_categories']) : [];
    } catch (Throwable $ex) {
        app_log_ignored('request.php:categories', $ex);
    }

    if ($reimbursement && $isPost && $action === 'resubmit_reimbursement') {
        $reply = trim($_POST['reply'] ?? '');

        // ---- Pass 1: read the posted rows and check what can be checked before any file is saved ----
        $postedItems = [];   // for putting the form back after an error
        $modelItems  = [];   // for the model; receipt_path is filled in below
        $fileKeys    = [];   // same order as $modelItems: the $_FILES key for that row
        $badAmount   = false;
        $tooMany     = false;
        foreach ((array) ($_POST['rows'] ?? []) as $rowKey => $row) {
            if (!is_array($row)) continue;
            $rowKey = (int) $rowKey;
            $id     = (int) ($row['id'] ?? 0);
            $cat    = trim((string) ($row['category'] ?? ''));
            $desc   = trim((string) ($row['description'] ?? ''));
            $amt    = trim((string) ($row['amount'] ?? ''));
            $remove = !empty($row['remove_receipt']);
            $fileKey = 'receipt_' . $rowKey;
            $hasFile = !empty($_FILES[$fileKey]['name']);

            // A brand-new row left completely empty is simply ignored.
            if ($id === 0 && $cat === '' && $desc === '' && $amt === '' && !$hasFile) continue;

            $paise = ReimbursementModel::rupeesToPaise($amt);
            if ($paise === null) $badAmount = true;
            $postedItems[] = ['id' => $id, 'category' => $cat, 'description' => $desc, 'amount' => $amt, 'remove_receipt' => $remove];
            $modelItems[]  = ['id' => $id, 'category' => $cat, 'description' => $desc, 'amount_paise' => $paise,
                              'receipt_path' => '', 'remove_receipt' => $remove];
            $fileKeys[]    = $hasFile ? $fileKey : null;
            if (count($postedItems) > ReimbursementModel::MAX_ITEMS) { $tooMany = true; break; }
        }
        $postedReimb = [
            'event_name' => trim((string) ($_POST['event_name'] ?? '')),
            'event_date' => trim((string) ($_POST['event_date'] ?? '')),
            'items'      => $postedItems,
        ];

        if ($reimbursement['status'] !== 'Info Requested') {
            $error = "This request isn't waiting for your changes right now.";
        } elseif ($tooMany) {
            $error = "A claim can have at most " . ReimbursementModel::MAX_ITEMS . " expenses.";
        } elseif (!$postedItems) {
            $error = "Please keep at least one expense on the claim.";
        } elseif ($badAmount) {
            $error = "Please enter every amount as a positive number, e.g. 250 or 250.50.";
        } else {
            // ---- Pass 2: save the newly chosen receipt files ----
            require_once __DIR__ . '/../../models/FileUploader.php';
            $newUploads = [];
            $uploadFailed = false;
            try {
                $uploader = new FileUploader();
                foreach ($fileKeys as $i => $fileKey) {
                    if ($fileKey === null) continue;
                    // The tracking ID goes in the filename instead of a name: unique, and no personal data in the URL.
                    $path = $uploader->handleUpload($_FILES[$fileKey], $fileKey, $trackingKey, 'reimbursement');
                    if ($path) {
                        $modelItems[$i]['receipt_path'] = $path;
                        $newUploads[] = $path;
                    }
                }
            } catch (Exception $ex) {
                // FileUploader messages are written for the user (size, type, etc).
                $uploadFailed = true;
                $error = engage_user_message($ex, 'Receipt upload');
            }

            if (!$uploadFailed) {
                try {
                    $saved = $reimbursementModel->resubmitDraft(
                        $trackingKey, $email, $postedReimb['event_name'], $postedReimb['event_date'],
                        $modelItems, $reply, $reimbCategories
                    );
                    if ($saved) {
                        // Receipts that were replaced, removed or belonged to a deleted expense are no longer used.
                        foreach ((array) ($saved['obsolete_receipts'] ?? []) as $oldPath) { req_discard_upload($oldPath); }
                        $uploadMessage = "Thank you. Your changes and note have been sent and the reviewer will look at your request again.";
                        $reimbursement = $reimbursementModel->getStatusForApplicant($trackingKey, $email);
                        $postedReimb = null;
                    } else {
                        foreach ($newUploads as $p) { req_discard_upload($p); }
                        $error = "This request isn't waiting for your changes right now.";
                    }
                } catch (\InvalidArgumentException $ex) {
                    foreach ($newUploads as $p) { req_discard_upload($p); }
                    $error = $ex->getMessage();
                } catch (\Exception $ex) {
                    foreach ($newUploads as $p) { req_discard_upload($p); }
                    app_log("Reimbursement resubmit failed for $trackingKey: " . $ex->getMessage());
                    $error = "Something went wrong saving your changes. Please try again.";
                }
            } else {
                foreach ($newUploads as $p) { req_discard_upload($p); }
            }

            // Files cannot be put back into the form, so say so.
            if ($error !== '' && array_filter($fileKeys, fn($k) => $k !== null)) {
                $error .= " Nothing was saved, so please choose your receipt file(s) again.";
            }
        }
    }

    if ($reimbursement && $reimbursement['status'] === 'Info Requested') {
        $reimbursementThread = $reimbursementModel->getMessagesForApplicant($trackingKey, $email);
        $reimbDraft = $reimbursementModel->getDraftForApplicant($trackingKey, $email);
        if ($reimbDraft) {
            $stored = [];
            foreach ($reimbDraft['items'] as $it) { $stored[(int) $it['id']] = $it; }

            if ($postedReimb) {
                // The form came back with an error: show what the member typed.
                $reimbDraft['event_name'] = $postedReimb['event_name'];
                $reimbDraft['event_date'] = $postedReimb['event_date'];
                foreach ($postedReimb['items'] as $p) {
                    $sid = (int) $p['id'];
                    $reimbRows[] = [
                        'id'             => $sid,
                        'category'       => $p['category'],
                        'description'    => $p['description'],
                        'amount'         => $p['amount'],
                        'has_receipt'    => $sid > 0 && isset($stored[$sid]) ? (bool) $stored[$sid]['has_receipt'] : false,
                        'remove_receipt' => (bool) $p['remove_receipt'],
                    ];
                }
            }
            if (!$reimbRows) {
                foreach ($reimbDraft['items'] as $it) {
                    $reimbRows[] = [
                        'id'             => (int) $it['id'],
                        'category'       => (string) $it['category'],
                        'description'    => (string) $it['description'],
                        'amount'         => number_format($it['amount_paise'] / 100, 2, '.', ''),
                        'has_receipt'    => (bool) $it['has_receipt'],
                        'remove_receipt' => false,
                    ];
                }
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
                    The reviewer needs more information. Read their message, then correct anything that is wrong below:
                    change an expense, attach or replace a receipt, or add or remove an expense. Add a short note and resubmit.
                    Your payment details are not shown here and stay exactly as you submitted them.
                </p>
                <?php
                // The reply deadline: INFO_EXPIRY_DAYS days after the reviewer's latest message.
                $askedAt = null;
                foreach ($reimbursementThread as $m) {
                    if ($m['sender'] === 'reviewer') { $askedAt = $m['created_at']; }
                }
                if ($askedAt): ?>
                    <div class="alert error">
                        <strong>Please reply by
                        <?= $e(date('j M Y', strtotime($askedAt . ' +' . ReimbursementModel::INFO_EXPIRY_DAYS . ' days'))) ?>.</strong>
                        If we don't hear from you by then, this request is closed automatically as unsuccessful.
                    </div>
                <?php endif; ?>
                <?php $renderThread($reimbursementThread); ?>
                <form method="POST" enctype="multipart/form-data" id="resubmit-reimbursement-form">
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

                    <h3>Expenses</h3>
                    <div id="rb-items"></div>
                    <button type="button" id="rb-add" class="btn-ghost" style="margin-bottom:16px;"
                            data-max="<?= (int) ReimbursementModel::MAX_ITEMS ?>">+ Add expense item</button>
                    <div class="total-bar"><span>Running total</span><strong>₹<span id="rb-total">0.00</span></strong></div>

                    <div class="field">
                        <label>Note to the reviewer <span class="req-star">*</span></label>
                        <textarea name="reply" rows="4" maxlength="<?= ReimbursementModel::MAX_MESSAGE_LENGTH ?>" required placeholder="Answer the question above and/or say what you changed."><?= $e($_POST['reply'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" id="rb-submit">Resubmit for review</button>
                </form>

                <template id="rb-item-template">
                    <div class="li line-item rb-item">
                        <input type="hidden" class="rb-id" value="0">
                        <div class="row">
                            <div class="field">
                                <label>Category <span class="req-star">*</span></label>
                                <select class="rb-category" required></select>
                            </div>
                            <div class="field">
                                <label>Amount (₹) <span class="req-star">*</span></label>
                                <input type="text" inputmode="decimal" class="rb-amount" placeholder="Amount in ₹" required>
                            </div>
                        </div>
                        <div class="field">
                            <label>Description <span class="req-star">*</span></label>
                            <input type="text" class="rb-description" maxlength="<?= (int) ReimbursementModel::MAX_ITEM_DESCRIPTION_LENGTH ?>" placeholder="What was this for?" required>
                        </div>
                        <div class="field">
                            <label>Receipt <span style="font-weight:400; color:var(--muted);">(optional, but highly recommended)</span></label>
                            <div class="rb-has" style="display:none; margin:0 0 8px; font-size:13.5px;">
                                &#10003; A receipt is on file for this expense.
                                <label style="display:block; font-weight:400;"><input type="checkbox" class="rb-remove" value="1"> Remove this receipt</label>
                                <span style="display:block; color:var(--muted);">Choose a file below only if you want to replace it.</span>
                            </div>
                            <input type="file" class="rb-file" accept=".pdf,.jpg,.jpeg,.png">
                            <span class="hint">PDF, JPG or PNG.</span>
                        </div>
                        <button type="button" class="rb-remove-item btn-danger-ghost">Remove this expense</button>
                    </div>
                </template>

                <script>
                    (function () {
                        var CATEGORIES = <?= json_encode(array_values($reimbCategories), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                        var INITIAL = <?= json_encode($reimbRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                        var MAX = parseInt(document.getElementById('rb-add').getAttribute('data-max'), 10) || 20;
                        var list = document.getElementById('rb-items');
                        var counter = 0;   // every row gets its own number, so names never clash when rows are removed

                        function rows() { return list.querySelectorAll('.rb-item'); }

                        function recompute() {
                            var total = 0;
                            list.querySelectorAll('.rb-amount').forEach(function (el) {
                                var v = parseFloat(el.value);
                                if (!isNaN(v)) total += v;
                            });
                            document.getElementById('rb-total').textContent = total.toFixed(2);
                            var n = rows().length;
                            document.getElementById('rb-add').disabled = n >= MAX;
                            // A claim must keep at least one expense.
                            list.querySelectorAll('.rb-remove-item').forEach(function (b) { b.style.display = n <= 1 ? 'none' : ''; });
                        }

                        function addRow(data) {
                            var k = counter++;
                            var clone = document.getElementById('rb-item-template').content.cloneNode(true);
                            var row = clone.querySelector('.rb-item');

                            var id = row.querySelector('.rb-id');            id.name = 'rows[' + k + '][id]';
                            var cat = row.querySelector('.rb-category');     cat.name = 'rows[' + k + '][category]';
                            var amt = row.querySelector('.rb-amount');       amt.name = 'rows[' + k + '][amount]';
                            var desc = row.querySelector('.rb-description'); desc.name = 'rows[' + k + '][description]';
                            var rem = row.querySelector('.rb-remove');       rem.name = 'rows[' + k + '][remove_receipt]';
                            var file = row.querySelector('.rb-file');        file.name = 'receipt_' + k;

                            var want = data && data.category ? data.category : '';
                            var seen = false;
                            CATEGORIES.forEach(function (c) {
                                var o = document.createElement('option'); o.value = c; o.textContent = c;
                                if (c === want) { o.selected = true; seen = true; }
                                cat.appendChild(o);
                            });
                            // An old claim may use a category that is no longer offered: keep it as it was.
                            if (want !== '' && !seen) {
                                var o = document.createElement('option'); o.value = want; o.textContent = want; o.selected = true;
                                cat.appendChild(o);
                            }

                            if (data) {
                                id.value = data.id || 0;
                                amt.value = data.amount || '';
                                desc.value = data.description || '';
                                if (data.has_receipt) {
                                    row.querySelector('.rb-has').style.display = 'block';
                                    rem.checked = !!data.remove_receipt;
                                }
                            }

                            amt.addEventListener('input', recompute);
                            row.querySelector('.rb-remove-item').addEventListener('click', function () {
                                row.remove();
                                recompute();
                            });
                            list.appendChild(clone);
                            recompute();
                        }

                        document.getElementById('rb-add').addEventListener('click', function () { addRow(null); });

                        // One click, one request: a second click while the files upload would file the answer twice.
                        var form = document.getElementById('resubmit-reimbursement-form');
                        var sending = false;
                        form.addEventListener('submit', function (ev) {
                            if (sending) { ev.preventDefault(); return; }
                            sending = true;
                            var btn = document.getElementById('rb-submit');
                            btn.textContent = 'Submitting\u2026';
                            setTimeout(function () { btn.disabled = true; }, 0);
                        });
                        window.addEventListener('pageshow', function (ev) {
                            if (ev.persisted) {
                                sending = false;
                                var btn = document.getElementById('rb-submit');
                                btn.textContent = 'Resubmit for review';
                                btn.disabled = false;
                            }
                        });

                        if (INITIAL.length) { INITIAL.forEach(addRow); } else { addRow(null); }
                        recompute();
                    })();
                </script>
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
