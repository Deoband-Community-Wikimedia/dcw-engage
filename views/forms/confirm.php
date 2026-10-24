<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/AwardConfirmationModel.php';
require_once __DIR__ . '/../../includes/mailer.php';

/**
 * /confirm/<token>: an awardee confirms (or declines) their scholarship and shares their travel and stay details.
 * The link is private to one awarded application (see AwardConfirmationModel) and is sent in the award letter.
 *
 * - Confirming can be changed until the deadline. Declining is final on this page (a reviewer may already have
 *   offered the place to someone else); the awardee is told to write to the committee if it was a mistake.
 * - After the deadline, or when confirmation is switched off, the page is read-only.
 * - The answers are stored encrypted. A decline emails the program's organizers so the place can be reused.
 * - Every save emails the awardee a receipt. The wording depends on what they had before this save ($previous):
 *   a first confirmation, an update of details after confirming, or a decline (which replaces an earlier confirmation).
 *
 * The router must set $confirmToken from the URL, the same way it sets $resumeToken for /resume/<token>.
 */
global $confirmToken;

// A private page: a secret link and the awardee's personal answers. Never cached, never indexed, and the link is not
// handed on to other sites in a Referer header.
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$cm = new AwardConfirmationModel();
$row = $cm->byToken((string) ($confirmToken ?? ''));

if (!$row || ($row['status'] ?? '') !== 'Accepted') {
    http_response_code(404);
    engage_header([
        'title'   => 'Link not valid',
        'heading' => 'This link is not valid',
        'crumbs'  => [['Home', '/'], ['Confirm your scholarship']],
        'noindex' => true,
    ]);
    ?>
    <div class="fcard" style="max-width:520px; text-align:center;">
        <p class="intro" style="margin:0 0 18px;">This confirmation link is not valid. Please use the link provided in your award email or write to the scholarship committee.</p>
        <a class="back-link" href="/" style="margin:0;">&larr; Back to DCW Engage</a>
    </div>
    <?php
    engage_footer();
    die();
}

$config = require __DIR__ . '/../../includes/config.php';
$appUrl = rtrim((string) ($config['app']['url'] ?? ''), '/');
$srcReplyTo = trim((string) ($config['mail']['src_reply_to'] ?? ''));
$srcName = trim((string) ($config['mail']['src_name'] ?? '')) ?: 'DCW Scholarship Review Committee';
$srcReady = $srcReplyTo !== '' && filter_var($srcReplyTo, FILTER_VALIDATE_EMAIL) !== false;

$title = CoreMail::eventTitle($row['form_title'] ?: $row['form_type']);
$name = CoreMail::cleanName($row['applicant_name']);
$myUrl = $appUrl . '/confirm/' . $confirmToken;   // the link they opened (only its hash is stored)

try {
    $fields = AwardConfirmationModel::parseFields((string) ($row['fields_text'] ?? ''));
} catch (Throwable $ex) {
    $fields = [];
}

$deadlineTs = FormModel::utcToTs($row['deadline_at'] ?? null);
$deadlineLabel = FormModel::formatIst($row['deadline_at'] ?? null);
$switchedOn = !empty($row['enabled']);
$pastDeadline = $deadlineTs !== null && $deadlineTs <= time();
$closed = !$switchedOn || $pastDeadline;
$declined = $row['response'] === 'declined';
$locked = $closed || $declined;

$errors = [];
$success = '';
// A POST value as a plain string ('' when missing or an array), so a crafted request cannot raise warnings.
$postStr = fn(string $k): string => (isset($_POST[$k]) && is_scalar($_POST[$k])) ? (string) $_POST[$k] : '';
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && !$locked;

if ($posted) {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');

    $response = $postStr('response');
    $answers = [];
    $reason = '';

    if (!in_array($response, ['confirmed', 'declined'], true)) {
        $errors['response'] = 'Please select whether you wish to accept the scholarship.';
    } elseif ($response === 'confirmed') {
        // Questions hidden by an earlier answer (for example accommodation for someone local) are not asked, checked or saved.
        $vis = AwardConfirmationModel::visibleMap($fields, $_POST);
        foreach ($fields as $f) {
            if (empty($vis[$f['name']])) continue;
            $v = trim($postStr($f['name']));
            $label = $f['label'];
            if ($v === '') {
                if ($f['required']) $errors[$f['name']] = 'This field is mandatory.';
                continue;
            }
            if (mb_strlen($v) > AwardConfirmationModel::VALUE_MAX) {
                $errors[$f['name']] = 'Please limit this entry to ' . AwardConfirmationModel::VALUE_MAX . ' characters.';
            } elseif ($f['type'] === 'select' && !in_array($v, $f['options'], true)) {
                $errors[$f['name']] = 'Please select a valid option from the list.';
            } elseif ($f['type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $errors[$f['name']] = 'Please enter a valid date in YYYY-MM-DD format.';
            } elseif ($f['type'] === 'email' && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$f['name']] = 'Please enter a valid email address.';
            } elseif ($f['type'] === 'number' && !is_numeric($v)) {
                $errors[$f['name']] = 'Please enter a numeric value.';
            }
            $answers[] = ['label' => $label, 'value' => $v];
        }
    } else {
        $reason = trim($postStr('reason'));
        if (mb_strlen($reason) > AwardConfirmationModel::REASON_MAX) {
            $errors['reason'] = 'Please limit this entry to ' . AwardConfirmationModel::REASON_MAX . ' characters.';
        }
    }

    if (!$errors) {
        // What they had before this save: 'pending', 'confirmed' or 'declined'. Used for the wording of the
        // on-screen message and of the receipt email, and to alert organizers only once.
        $previous = (string) $row['response'];
        try {
            $cm->respond((int) $row['conf_id'], $response, $answers, $reason);
            if ($response === 'confirmed') {
                $success = $previous === 'confirmed'
                    ? 'Thank you! Your details have been successfully updated. You may modify your responses as many times as required until the deadline.'
                    : 'Thank you! Your confirmation and details have been saved successfully. You can update your information anytime before the deadline.';
            } else {
                $success = 'We have recorded that you are unable to accept the scholarship. Thank you for informing us.';
            }

            if ($srcReady) {
                try {
                    Mailer::sendAwardReceipt($row['email'], $row['applicant_name'], $row['form_title'] ?: $row['form_type'],
                        $response, $deadlineLabel, $myUrl, $srcReplyTo, $srcName, $previous);
                } catch (Throwable $ex) {
                    app_log("Award receipt failed for application #{$row['application_id']}: " . $ex->getMessage());
                }
            }
            // Tell the organizers once, when a place becomes free.
            if ($response === 'declined' && $previous !== 'declined') {
                try {
                    Mailer::sendAwardDeclinedAlert($row['notify_emails'] ?? '', $row['applicant_name'], $row['tracking_id'],
                        $row['form_title'] ?: $row['form_type'], $reason, $appUrl . '/admin/application-review?id=' . (int) $row['application_id']);
                } catch (Throwable $ex) {
                    app_log("Award declined alert failed for application #{$row['application_id']}: " . $ex->getMessage());
                }
            }
            // The awardee acting through their private link: no staff account, so the actor is "Awardee".
            AuditLog::record('award.' . $response, null, 'Awardee', 'Application #' . $row['application_id'],
                ($previous === 'confirmed' && $response === 'confirmed') ? 'details updated' : null);

            $row = $cm->byToken((string) $confirmToken);
            $declined = $row['response'] === 'declined';
            $locked = $closed || $declined;
        } catch (Throwable $ex) {
            app_log("Award response failed for application #{$row['application_id']}: " . $ex->getMessage());
            $errors['system'] = 'An error occurred while saving your response. Please try again or reach out to the committee.';
        }
    }
}

// What the form shows: this POST if it was refused, otherwise what was saved.
$saved = AwardConfirmationModel::decrypt($row['answers_enc'] ?? '');
$savedByLabel = [];
foreach ($saved['answers'] as $a) $savedByLabel[(string) ($a['label'] ?? '')] = (string) ($a['value'] ?? '');
$currentResponse = $posted && $errors ? $postStr('response') : (string) $row['response'];
if ($currentResponse === 'pending') $currentResponse = '';
$value = function (array $f) use ($posted, $errors, $savedByLabel, $postStr): string {
    return $posted && $errors ? $postStr($f['name']) : ($savedByLabel[$f['label']] ?? '');
};
$respondedAt = FormModel::formatIst($row['responded_at'] ?? null);
$shownNow = AwardConfirmationModel::visibleMap($fields, array_combine(array_column($fields, 'name'), array_map($value, $fields)) ?: []);

engage_header([
    'title'   => 'Confirm your scholarship - ' . $title,
    'heading' => $title,
    'kicker'  => 'Confirm your scholarship',
    'lead'    => (!$locked && $deadlineLabel !== '') ? 'Kindly ensure you submit your response by ' . $deadlineLabel . '.' : '',
    'crumbs'  => [['Home', '/'], ['Confirm your scholarship']],
    'noindex' => true,
]);
?>
<style>
    /* Page-specific bits not in engage.css. Colors come from the design tokens, so chapter themes and staff.css still apply. */
    .fcard .field .error-text { display: block; margin-top: 5px; font-size: 13px; color: var(--accent, #b91c1c); }
    .choices.stack input { margin: 0; }
    .confirm-intro { margin: 0 0 22px; line-height: 1.6; }
    .confirm-hint { margin: 6px 0 0; font-size: 13px; color: var(--muted); }
    .when-confirming[hidden], .when-declining[hidden], .fcard .field[hidden] { display: none !important; }
</style>

<div class="fcard">
    <?php if ($success): ?>
        <div class="alert ok"><strong>Success:</strong> <?= $e($success) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors['system'])): ?>
        <div class="alert error"><?= $e($errors['system']) ?></div>
    <?php endif; ?>

    <p class="confirm-intro">Dear <strong><?= $e($name !== '' ? $name : 'Awardee') ?></strong>,</p>

    <?php if (trim((string) ($row['intro_text'] ?? '')) !== ''): ?>
        <div class="confirm-intro"><?= MiniWikiText::render((string) $row['intro_text']) ?></div>
    <?php endif; ?>

    <?php if ($declined): ?>
        <div class="action-banner">
            <strong>You have declined this scholarship<?= $respondedAt !== '' ? ' on ' . $e($respondedAt) : '' ?>.</strong>
            If this was done in error, please write to the scholarship committee<?= $srcReady ? ' at ' . $e($srcReplyTo) : '' ?>.
        </div>
    <?php elseif ($closed): ?>
        <div class="action-banner">
            <strong><?= $switchedOn ? 'Confirmation window closed' . ($deadlineLabel !== '' ? ' on ' . $e($deadlineLabel) : '') : 'Confirmation is currently inactive' ?>.</strong>
            <?= $row['response'] === 'confirmed' ? 'Your confirmation has been recorded. Your submitted details are displayed below.' : 'Please write to the scholarship committee' . ($srcReady ? ' at ' . $e($srcReplyTo) : '') . ' if you still wish to claim your slot.' ?>
        </div>
    <?php else: ?>
        <p class="confirm-intro">
            <?php if ($row['response'] === 'confirmed'): ?>
                You confirmed your acceptance on <?= $e($respondedAt) ?>. You may modify your responses anytime until <strong><?= $e($deadlineLabel) ?></strong>.
            <?php else: ?>
                Kindly submit your response on or before <strong><?= $e($deadlineLabel) ?></strong>.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <form method="POST" id="confirmForm">
        <?= CSRF::getInputField() ?>

        <fieldset class="group">
            <legend>Your scholarship</legend>
            <div class="field">
                <div class="choices stack">
                    <label>
                        <input type="radio" name="response" value="confirmed" <?= $currentResponse === 'confirmed' ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
                        <span>Yes, I confirm that I will accept the scholarship for <?= $e($title) ?></span>
                    </label>
                    <?php if ($row['response'] !== 'declined' || !$locked): ?>
                    <label>
                        <input type="radio" name="response" value="declined" <?= $currentResponse === 'declined' ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>>
                        <span>No, I am unable to accept the scholarship</span>
                    </label>
                    <?php endif; ?>
                </div>
                <?php if (!empty($errors['response'])): ?><span class="error-text"><?= $e($errors['response']) ?></span><?php endif; ?>
            </div>
        </fieldset>

        <div class="when-confirming" id="whenConfirming" <?= $currentResponse === 'confirmed' || $locked && $row['response'] === 'confirmed' ? '' : 'hidden' ?>>
            <?php if ($fields): ?>
            <fieldset class="group">
                <legend>Your details</legend>
                <?php foreach ($fields as $f):
                    $n = $f['name']; $v = $value($f); $err = $errors[$n] ?? null;
                    $star = ($f['required'] && !$locked) ? ' <span class="req-star">*</span>' : '';
                    $dis = $locked ? 'disabled' : ''; ?>
                    <?php $sf = $f['showif'] ?? null; ?>
                    <div class="field"<?= $sf ? ' data-showif-name="' . $e($sf['name']) . '" data-showif-value="' . $e($sf['value']) . '"' : '' ?><?= empty($shownNow[$n]) ? ' hidden' : '' ?>>
                        <label for="<?= $e($n) ?>"><?= $e($f['label']) ?><?= $star ?></label>
                        <?php if ($f['type'] === 'select'): ?>
                            <select name="<?= $e($n) ?>" id="<?= $e($n) ?>" data-required="<?= $f['required'] ? '1' : '0' ?>" <?= $dis ?>>
                                <option value="">-- Please Select --</option>
                                <?php foreach ($f['options'] as $opt): ?>
                                    <option value="<?= $e($opt) ?>" <?= $v === $opt ? 'selected' : '' ?>><?= $e($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($f['type'] === 'textarea'): ?>
                            <textarea name="<?= $e($n) ?>" id="<?= $e($n) ?>" rows="3" maxlength="<?= (int) AwardConfirmationModel::VALUE_MAX ?>" data-required="<?= $f['required'] ? '1' : '0' ?>" <?= $dis ?>><?= $e($v) ?></textarea>
                        <?php else: ?>
                            <input type="<?= $e($f['type']) ?>" name="<?= $e($n) ?>" id="<?= $e($n) ?>" value="<?= $e($v) ?>"
                                   maxlength="<?= (int) AwardConfirmationModel::VALUE_MAX ?>" data-required="<?= $f['required'] ? '1' : '0' ?>" <?= $dis ?>>
                        <?php endif; ?>
                        <?php if ($err): ?><span class="error-text"><?= $e($err) ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </fieldset>
            <?php endif; ?>
        </div>

        <?php if (!$declined): ?>
        <div class="when-declining" id="whenDeclining" <?= $currentResponse === 'declined' ? '' : 'hidden' ?>>
            <div class="field">
                <label for="reason">Reason (optional)</label>
                <textarea name="reason" id="reason" rows="3" maxlength="<?= (int) AwardConfirmationModel::REASON_MAX ?>" <?= $locked ? 'disabled' : '' ?>><?= $e($posted && $errors ? $postStr('reason') : '') ?></textarea>
                <?php if (!empty($errors['reason'])): ?><span class="error-text"><?= $e($errors['reason']) ?></span><?php endif; ?>
                <p class="confirm-hint">Declining your scholarship is final on this page. If you change your mind later, please write to the committee.</p>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$locked): ?>
            <button type="submit">Save my response</button>
        <?php endif; ?>
    </form>
</div>

<?php if (!$locked): ?>
<script>
    (function () {
        var form = document.getElementById('confirmForm');
        var yes = document.getElementById('whenConfirming');
        var no = document.getElementById('whenDeclining');

        // Shows each question whose "only if" answer is given (in page order, so a chain hides everything below a
        // "no"), and switches off the questions that are hidden so they are neither required nor sent.
        function applyConditions(confirming) {
            yes.querySelectorAll('.field').forEach(function (w) {
                var n = w.getAttribute('data-showif-name');
                if (n) {
                    var c = form.elements[n];
                    var cw = c && c.closest ? c.closest('.field') : null;
                    w.hidden = !(c && cw && !cw.hidden && c.value === w.getAttribute('data-showif-value'));
                }
                w.querySelectorAll('input, select, textarea').forEach(function (el) {
                    el.disabled = !confirming || w.hidden;
                    el.required = confirming && !w.hidden && el.getAttribute('data-required') === '1';
                });
            });
        }

        function sync() {
            var r = form.querySelector('input[name=response]:checked');
            var v = r ? r.value : '';
            yes.hidden = v !== 'confirmed';
            no.hidden = v !== 'declined';
            applyConditions(v === 'confirmed');
        }
        form.addEventListener('change', sync);
        form.addEventListener('submit', function (ev) {
            var r = form.querySelector('input[name=response]:checked');
            if (r && r.value === 'declined' && !confirm('Are you sure you want to decline this scholarship? This action cannot be reversed on this page.')) ev.preventDefault();
        });
        sync();
    })();
</script>
<?php endif; ?>
<?php engage_footer(); ?>
