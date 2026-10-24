<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/FeedbackModel.php';
require_once __DIR__ . '/../../models/ApplicationModel.php';
require_once __DIR__ . '/../../models/ScholarshipLetterModel.php';
require_once __DIR__ . '/../../models/AwardConfirmationModel.php';   // award confirmation: link, response, settings
require_once __DIR__ . '/../../includes/mailer.php';   // Mailer::sendStatusUpdate() and Mailer::sendSentBack()
require_once __DIR__ . '/../../includes/mail/ApplicationMail.php';   // ApplicationMail::decisionLetter()

/**
 * /admin/application-review: one queue for the responses to every ordinary form.
 * Membership forms are deliberately left out: they are handled in Membership review.
 * Feedback forms (schema "kind": "feedback") are left out too: their responses are not applications and
 * are read in the Feedback tab (/admin/feedback-review).
 *
 * Status rules (same ones ApplicationModel::saveApplication() enforces):
 *   open       New, Submitted, Under Review   -> can be decided here
 *   waiting    Draft                          -> the applicant is editing (or was sent back); nothing to decide
 *   decided    Accepted, Rejected             -> locked
 *   discarded  Discarded                      -> spam, hidden from the queue; can be restored
 * Accept / Reject / Under Review email the applicant exactly as the form manager does (Mailer::sendStatusUpdate),
 * with the optional note added to that email.
 *
 * SCHOLARSHIP LETTERS. A program can have committee-written letters switched on (see ScholarshipLetterModel;
 * the letters are written in the "Scholarship letters" box under the program, stored in the database).
 * On such a program Accept emails the award letter and Reject emails the decline letter INSTEAD of the standard
 * status email: exactly one email per decision, never the "status updated to Accepted" one. The letter is the
 * committee's text between the automatic greeting and signature, and is sent with the committee Reply-To
 * (config mail.src_reply_to). Mark under review still sends the standard status email.
 * If a letter cannot be sent the decision stays saved, the application is flagged, and "Resend letter" appears.
 *
 * AWARD CONFIRMATION (see AwardConfirmationModel; the public page is /confirm/<token>). A program can ask its
 * awardees to confirm or decline and share travel and stay details. The award letter carries the awardee's private
 * link wherever the committee writes [{confirm_link} Confirm your place]. Each awarded row shows Confirmed /
 * Declined / Awaiting / Overdue (no answer after the deadline). Nothing changes automatically: a decline or an
 * overdue row is only FLAGGED, and a reviewer accepts whoever they choose from the applications still open.
 * "Send confirmation link / reminder" emails the private link again (it is also how people accepted before
 * confirmation was switched on get their first link). Responses download as a CSV from the program panel.
 * The introduction supports the same wiki formatting as the letters (the public page renders it with
 * MiniWikiText), and "Preview confirmation page" shows the page as an awardee sees it without saving or sending.
 *
 * "Needs information" sets the application back to Draft, makes a one-time link (/resume/<token>) and EMAILS
 * the applicant your message plus that link (Mailer::sendSentBack). If the email cannot be sent, the link and
 * message are shown on screen instead so you can pass them on yourself. The status change is kept either way.
 * "Resend link" (Draft applications) does not email: it only shows a new one-time link.
 * "Discard" sets Discarded and sends NOTHING (that is what $notifyApplicant already does for any status
 * other than Under Review / Accepted / Rejected). "Restore" puts a discarded application back to New.
 */
requireRole(['organizer', 'owner']);

const APP_OPEN = ['New', 'Submitted', 'Under Review'];
// action name -> status it sets ('info' sets Draft and issues a link; 'restore' only works on Discarded)
const APP_ACTIONS = ['review' => 'Under Review', 'approve' => 'Accepted', 'reject' => 'Rejected', 'info' => 'Draft',
                     'discard' => 'Discarded', 'restore' => 'New'];
const BULK_MAX = 100;

$model = new ApplicationModel();
$formModel = new FormModel();
$letterModel = new ScholarshipLetterModel();
$letters = $letterModel->allByForm();   // form id => letters row; [] until the migration has been run
$letterOn = fn($formId) => ScholarshipLetterModel::isActive($letters[(int) $formId] ?? null);

$confModel = new AwardConfirmationModel();
$confSettings = $confModel->allSettings();        // form id => settings row; [] until the migration has been run
$confByApp = $confModel->allByApplication();      // application id => response row
$confOn = fn($formId) => AwardConfirmationModel::isOn($confSettings[(int) $formId] ?? null);
// confirmed | declined | awaiting | overdue | none
$confState = fn(array $r) => AwardConfirmationModel::stateOf($confByApp[(int) $r['id']] ?? null, $confSettings[(int) $r['form_id']] ?? null, (string) $r['status']);

// Forms this person may review: ordinary forms only (no membership, no feedback), and only ones FormModel
// says they can open. getAllForms() returns schema_json, which FeedbackModel::isFeedbackRow() reads.
$allForms = array_values(array_filter(
    $formModel->getAllForms(),
    fn($f) => !FormModel::isMembershipType($f['form_type'])
           && !FeedbackModel::isFeedbackRow($f)
           && FormModel::userCanOpen($f)
));
$allowed = [];   // form id => true
foreach ($allForms as $f) $allowed[(int) $f['id']] = true;


// Programs that are not shown in the review queue: closed forms, and test forms.
// A form counts as a test form when "test"/"testing"/"dummy" is a whole word in its title or URL slug
// (so "Test form" and "scholarship-test" match, "Contest 2026" does not). Add exact slugs to $extra to hide others.
// KEEP IN SYNC with the same function in views/admin/dashboard.php and application_review.php.
if (!function_exists('review_hidden_form')) {
    function review_hidden_form(array $form, array $extra = []): bool {
        if (empty($form['is_active'])) return true;
        if (in_array((string) $form['form_type'], $extra, true)) return true;
        return (bool) preg_match('/(^|[^a-z])(test|testing|dummy)([^a-z]|$)/i',
            (string) ($form['title'] ?? '') . ' ' . (string) $form['form_type']);
    }
}

$notice = ''; $error = '';
$keepLetters = null;   // posted letter text, kept in the editor when a save is refused
$keepConfirm = null;   // posted confirmation settings, kept in the panel when a save is refused
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$app = false;
if ($id) {
    $app = $model->getApplicationById($id);
    if ($app && FormModel::isMembershipType($app['form_type'])) {
        http_response_code(404);
        die('Membership applications are reviewed in Membership review.');
    }
    // A response to a feedback form is not in $allowed, so it lands here as "not found".
    if (!$app || !isset($allowed[(int) $app['form_id']])) { http_response_code(404); die('Application not found.'); }
}

$config = require __DIR__ . '/../../includes/config.php';
$resumeUrl = fn($token) => rtrim($config['app']['url'], '/') . '/resume/' . $token;

// The committee address scholarship letters are sent from / replied to (config.php: mail.src_reply_to).
$srcReplyTo = trim((string) ($config['mail']['src_reply_to'] ?? ''));
$srcName    = trim((string) ($config['mail']['src_name'] ?? '')) ?: 'DCW Scholarship Review Committee';
$srcReady   = $srcReplyTo !== '' && filter_var($srcReplyTo, FILTER_VALIDATE_EMAIL) !== false;

// Same outcome email the form manager sends. A mail failure never undoes a decision that is already saved.
// Only Under Review / Accepted / Rejected are emailed, so Discard and Restore are always silent.
$notifyApplicant = function (array $row, string $status, string $note) {
    if (!in_array($status, ['Under Review', 'Accepted', 'Rejected'], true)) return;
    try {
        Mailer::sendStatusUpdate($row['email'], $row['applicant_name'], $status, $row['tracking_id'],
                                 $row['form_title'] ?: $row['form_type'], $note);
    } catch (Throwable $e) {
        app_log("Status email failed for application #{$row['id']}: " . $e->getMessage());
    }
};

// Scholarship letter for a decision: Accepted -> award letter, Rejected -> decline letter.
// Returns true only when the email really went out, and records that on the application either way
// (sent -> letter_sent_at, failed -> letter_failed so "Resend letter" appears).
// A failure never undoes the decision that is already saved.
$sendLetter = function (array $row, string $status) use ($letters, $letterModel, $srcReplyTo, $srcName, $confModel, $confOn, $config): bool {
    $l = $letters[(int) $row['form_id']] ?? null;
    $kind = $status === 'Accepted' ? 'award' : 'decline';
    $ok = false;
    try {
        // The award letter carries the awardee's private confirmation link when the program uses confirmation.
        // If the link cannot be made the letter is NOT sent without it: it is flagged for "Resend letter" instead.
        $confirmUrl = ($kind === 'award' && $confOn($row['form_id']))
            ? $confModel->urlFor((int) $row['id'], (string) $config['app']['url'])
            : '';
        $ok = $l && (bool) ApplicationMail::decisionLetter(
            $row['email'], $row['applicant_name'], $row['form_title'] ?: $row['form_type'],
            (string) $l[$kind . '_subject'], (string) $l[$kind . '_body'], $srcReplyTo, $srcName, $confirmUrl
        );
        if ($ok) $letterModel->markSent((int) $row['id']); else $letterModel->markFailed((int) $row['id']);
    } catch (Throwable $e) {
        app_log("Scholarship $kind letter failed for application #{$row['id']}: " . $e->getMessage());
        try { $letterModel->markFailed((int) $row['id']); } catch (Throwable $e2) {}
        $ok = false;
    }
    if ($ok) app_log("Scholarship $kind letter sent: #{$row['id']} (letters v" . (int) ($l['version'] ?? 0) . ')');
    return $ok;
};

// Emails an awardee their private confirmation link (the first link, or a reminder). Returns true only when the
// email really went out, and records the reminder. Callers have already checked the application is Accepted and
// that confirmation is on for its program.
$sendConfirmLink = function (array $row) use ($confModel, $confSettings, $srcReplyTo, $srcName, $config): bool {
    try {
        $s = $confSettings[(int) $row['form_id']] ?? [];
        $url = $confModel->urlFor((int) $row['id'], (string) $config['app']['url']);
        $ok = (bool) Mailer::sendConfirmReminder($row['email'], $row['applicant_name'], $row['form_title'] ?: $row['form_type'],
                                                 FormModel::formatIst($s['deadline_at'] ?? null), $url, $srcReplyTo, $srcName);
        if ($ok) $confModel->markReminded((int) $row['id']);
        return $ok;
    } catch (Throwable $e) {
        app_log("Confirmation link email failed for application #{$row['id']}: " . $e->getMessage());
        return false;
    }
};

// "Needs information": emails the applicant what is needed plus their one-time link.
// Returns true only when the email really went out; on false the caller shows the link on screen instead.
// A mail failure never undoes the status change that is already saved.
$emailSentBack = function (array $row, string $message, string $token): bool {
    try {
        return (bool) Mailer::sendSentBack($row['email'], $row['applicant_name'], $row['tracking_id'],
                                           $row['form_title'] ?: $row['form_type'], $message, $token);
    } catch (Throwable $e) {
        app_log("Send-back email failed for application #{$row['id']}: " . $e->getMessage());
        return false;
    }
};

// ---------------------------------------------------------------------------
// Scholarship letters and award confirmation: save, or preview (nothing is sent or saved by a preview).
// The program must be one this person may review, so editing the request cannot reach any other form.
// ---------------------------------------------------------------------------
if (!$id && $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['save_letters', 'preview_award', 'preview_decline', 'save_confirm', 'preview_confirm'], true)) {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    $lf = (int) ($_POST['form_id'] ?? 0);
    if (!isset($allowed[$lf])) { http_response_code(404); die('Program not found.'); }

    if ($_POST['action'] === 'save_letters') {
        try {
            if (!empty($_POST['enabled']) && !$srcReady) {
                throw new Exception('Add mail.src_reply_to (the committee email address) to config.php before turning scholarship letters on.');
            }
            // A program that uses confirmation must keep the link in its award letter, or awardees get no link.
            if (!empty($_POST['enabled']) && $confOn($lf)
                && strpos((string) ($_POST['award_body'] ?? ''), '{confirm_link}') === false) {
                throw new Exception('This program asks awardees to confirm, so the award letter must contain the link: write [{confirm_link} Confirm your place] where you want it.');
            }
            $letterModel->save($lf, !empty($_POST['enabled']),
                (string) ($_POST['award_subject'] ?? ''), (string) ($_POST['award_body'] ?? ''),
                (string) ($_POST['decline_subject'] ?? ''), (string) ($_POST['decline_body'] ?? ''),
                currentAdminIdentifier());
            app_log("Scholarship letters saved: form #$lf by " . currentAdminIdentifier());
            $keep = array_filter(['form' => $_GET['form'] ?? '', 'status' => $_GET['status'] ?? ''], 'strlen');
            header('Location: /admin/application-review?' . http_build_query($keep + ['letters' => 'saved']));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $keepLetters = $_POST;
        }
    } elseif ($_POST['action'] === 'save_confirm') {
        try {
            $on = !empty($_POST['confirm_enabled']);
            if ($on && !$srcReady) {
                throw new Exception('Add mail.src_reply_to (the committee email address) to config.php before turning confirmation on.');
            }
            // The link has to be in the award letter, and the letter has to be on, or nobody would receive it.
            if ($on && !$letterOn($lf)) {
                throw new Exception('Turn the scholarship letters on first: the award letter is what carries the confirmation link.');
            }
            if ($on && strpos((string) (($letters[$lf]['award_body'] ?? '')), '{confirm_link}') === false) {
                throw new Exception('Add [{confirm_link} Confirm your place] to the award letter first, so awardees receive their link. Then turn confirmation on.');
            }
            $confModel->saveSettings($lf, $on, (string) ($_POST['confirm_deadline'] ?? ''),
                (string) ($_POST['confirm_fields'] ?? ''), (string) ($_POST['confirm_intro'] ?? ''), currentAdminIdentifier());
            app_log("Award confirmation settings saved: form #$lf by " . currentAdminIdentifier());
            $keep = array_filter(['form' => $_GET['form'] ?? '', 'status' => $_GET['status'] ?? ''], 'strlen');
            header('Location: /admin/application-review?' . http_build_query($keep + ['confirm' => 'saved']));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $keepConfirm = $_POST;
        }
    } elseif ($_POST['action'] === 'preview_confirm') {
        // The confirmation page as an awardee sees it, built from what is typed in the panel (saved or not).
        // Uses the real engage_header / engage_footer and the same MiniWikiText rendering as /confirm/<token>.
        // Nothing is saved or sent, and the form on the preview page does not submit.
        $ph = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');   // local: $h is defined further down
        $pform = $formModel->getFormById($lf);
        $ptitle = CoreMail::eventTitle((string) (($pform['schema']['title'] ?? '') ?: ($pform['form_type'] ?? '')));
        $pintro = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['confirm_intro'] ?? '')));

        // The deadline is typed in IST; show it the way the live page does (formatIst expects a stored UTC value).
        $pdl = trim((string) ($_POST['confirm_deadline'] ?? ''));
        $pts = $pdl !== '' ? FormModel::istInputToTs($pdl) : null;
        $pdlLabel = $pts !== null ? FormModel::formatIst(gmdate('Y-m-d H:i:s', $pts)) : '';

        try {
            $pfields = AwardConfirmationModel::parseFields((string) ($_POST['confirm_fields'] ?? ''));
            $pfieldsError = '';
        } catch (Throwable $ex) {
            $pfields = [];
            $pfieldsError = $ex->getMessage();
        }

        engage_header([
            'title'   => 'Preview: Confirm your scholarship - ' . $ptitle,
            'heading' => $ptitle,
            'kicker'  => 'Confirm your scholarship',
            'lead'    => $pdlLabel !== '' ? 'Please submit your response by ' . $pdlLabel . '.' : '',
            'crumbs'  => [['Home', '/'], ['Confirm your scholarship']],
            'noindex' => true,
        ]);
        ?>
<style>
    .fcard .field .error-text { display: block; margin-top: 5px; font-size: 13px; color: var(--accent, #b91c1c); }
    .choices.stack input { margin: 0; }
    .confirm-intro { margin: 0 0 22px; line-height: 1.6; }
    .confirm-hint { margin: 6px 0 0; font-size: 13px; color: var(--muted); }
    .when-confirming[hidden], .when-declining[hidden], .fcard .field[hidden] { display: none !important; }
</style>
<div class="fcard">
    <div class="action-banner"><strong>Preview only.</strong> Nothing was saved or sent, and this form does not submit. Your own settings are used, whether or not they are saved yet.</div>
    <?php if ($pfieldsError !== ''): ?><div class="alert error">The questions could not be read: <?= $ph($pfieldsError) ?></div><?php endif; ?>
    <?php if ($pdlLabel === ''): ?><div class="alert error">No deadline is set yet. Confirmation cannot be turned on without one.</div><?php endif; ?>

    <p class="confirm-intro">Dear <strong>Sample Applicant</strong>,</p>
    <?php if ($pintro !== ''): ?>
        <div class="confirm-intro"><?= MiniWikiText::render($pintro) ?></div>
    <?php endif; ?>
    <?php if ($pdlLabel !== ''): ?>
        <p class="confirm-intro">Please submit your response by <strong><?= $ph($pdlLabel) ?></strong>.</p>
    <?php endif; ?>

    <form id="previewForm" onsubmit="return false;">
        <fieldset class="group">
            <legend>Your scholarship</legend>
            <div class="field">
                <div class="choices stack">
                    <label>
                        <input type="radio" name="response" value="confirmed">
                        <span>Yes, I confirm that I will accept the scholarship for <?= $ph($ptitle) ?></span>
                    </label>
                    <label>
                        <input type="radio" name="response" value="declined">
                        <span>No, I am unable to accept the scholarship</span>
                    </label>
                </div>
            </div>
        </fieldset>

        <div class="when-confirming" id="whenConfirming" hidden>
            <?php if ($pfields): ?>
            <fieldset class="group">
                <legend>Your details</legend>
                <?php foreach ($pfields as $pf): $psf = $pf['showif'] ?? null; $pn = $pf['name']; ?>
                    <div class="field"<?= $psf ? ' data-showif-name="' . $ph($psf['name']) . '" data-showif-value="' . $ph($psf['value']) . '"' : '' ?><?= $psf ? ' hidden' : '' ?>>
                        <label for="<?= $ph($pn) ?>"><?= $ph($pf['label']) ?><?= $pf['required'] ? ' <span class="req-star">*</span>' : '' ?></label>
                        <?php if ($pf['type'] === 'select'): ?>
                            <select name="<?= $ph($pn) ?>" id="<?= $ph($pn) ?>">
                                <option value="">-- Please Select --</option>
                                <?php foreach ($pf['options'] as $opt): ?>
                                    <option value="<?= $ph($opt) ?>"><?= $ph($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($pf['type'] === 'textarea'): ?>
                            <textarea name="<?= $ph($pn) ?>" id="<?= $ph($pn) ?>" rows="3"></textarea>
                        <?php else: ?>
                            <input type="<?= $ph($pf['type']) ?>" name="<?= $ph($pn) ?>" id="<?= $ph($pn) ?>">
                        <?php endif; ?>
                        <?php if ($psf): ?><p class="confirm-hint">Only shown when &ldquo;<?= $ph($psf['label']) ?>&rdquo; is &ldquo;<?= $ph($psf['value']) ?>&rdquo;.</p><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </fieldset>
            <?php endif; ?>
        </div>

        <div class="when-declining" id="whenDeclining" hidden>
            <div class="field">
                <label for="reason">Reason (optional)</label>
                <textarea name="reason" id="reason" rows="3"></textarea>
                <p class="confirm-hint">Declining your scholarship is final on this page. If you change your mind later, please write to the committee.</p>
            </div>
        </div>

        <button type="button" disabled>Save my response</button>
    </form>
</div>
<script>
    (function () {
        var form = document.getElementById('previewForm');
        var yes = document.getElementById('whenConfirming');
        var no = document.getElementById('whenDeclining');

        // Same rule as the live page: a question shows when the select it depends on is shown and has that answer.
        function applyConditions() {
            yes.querySelectorAll('.field').forEach(function (w) {
                var n = w.getAttribute('data-showif-name');
                if (!n) return;
                var c = form.elements[n];
                var cw = c && c.closest ? c.closest('.field') : null;
                w.hidden = !(c && cw && !cw.hidden && c.value === w.getAttribute('data-showif-value'));
            });
        }
        function sync() {
            var r = form.querySelector('input[name=response]:checked');
            var v = r ? r.value : '';
            yes.hidden = v !== 'confirmed';
            no.hidden = v !== 'declined';
            applyConditions();
        }
        form.addEventListener('change', sync);
        sync();
    })();
</script>
        <?php
        engage_footer();
        exit;
    } else {
        $kind = $_POST['action'] === 'preview_award' ? 'award' : 'decline';
        $pf = $formModel->getFormById($lf);
        $title = (string) ($pf['schema']['title'] ?? $pf['form_type'] ?? '');
        header('Content-Type: text/html; charset=utf-8');
        echo ApplicationMail::decisionLetterPreview('Sample Applicant', $title,
            (string) ($_POST[$kind . '_subject'] ?? ''), (string) ($_POST[$kind . '_body'] ?? ''), $srcReplyTo, $srcName);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Bulk action from the queue. Every application is re-loaded and re-checked,
// so editing the request can never reach a form outside the allowed list, and
// anything not in the right state is skipped instead of failing the whole batch.
// ---------------------------------------------------------------------------
if (!$id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');

    $bulkAct = (string) ($_POST['bulk_action'] ?? '');
    $isResend = $bulkAct === 'resend_letter';
    $isRemind = $bulkAct === 'remind_confirm';   // email the awardee's confirmation link again (or for the first time)
    $ids =array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    $note = trim((string) ($_POST['note'] ?? ''));

    if (!isset(APP_ACTIONS[$bulkAct]) && !$isResend && !$isRemind) {
        $error = 'Choose an action to apply.';
    } elseif (!$ids) {
        $error = 'Tick at least one application first.';
    } elseif (count($ids) > BULK_MAX) {
        $error = 'Please act on at most ' . BULK_MAX . ' applications at a time.';
    } elseif ($bulkAct === 'info' && $note === '') {
        $error = 'Say what you need from the applicants.';
    } else {
        $by = currentAdminIdentifier();
        // 'links' now holds only the applicants whose send-back email could NOT be sent.
        // 'letters' counts scholarship letters emailed; 'letter_failed' lists the ones that could not be sent.
        // 'reminded' counts confirmation links emailed; 'remind_failed' lists the ones that could not be sent.
        $result = ['act' => $bulkAct, 'done' => 0, 'skipped' => [], 'links' => [], 'letters' => 0, 'letter_failed' => [],
                   'reminded' => 0, 'remind_failed' => [],
                   'message' => $bulkAct === 'info' ? $note : ''];

        // Enough time for a few dozen emails (each one opens its own SMTP connection).
        if (function_exists('set_time_limit')) @set_time_limit(180);

        foreach ($ids as $appId) {
            $row = $model->getApplicationById($appId);
            if (!$row || !isset($allowed[(int) $row['form_id']])) {
                $result['skipped'][] = "#$appId: not found, or not one you can review";
                continue;
            }
            $who = $row['applicant_name'] ?: $row['email'];

            // Send an unsent scholarship letter again (decision already saved, letter failed earlier).
            if ($isResend) {
                if (!$letterOn($row['form_id']) || !in_array($row['status'], ['Accepted', 'Rejected'], true) || empty($row['letter_failed'])) {
                    $result['skipped'][] = "$who: has no unsent letter";
                    continue;
                }
                if (!$srcReady) {
                    $result['skipped'][] = "$who: the committee address (mail.src_reply_to) is not set in config.php";
                    continue;
                }
                if (!$letterModel->claimResend((int) $row['id'])) {
                    $result['skipped'][] = "$who: letter is already being sent";
                    continue;
                }
                if ($sendLetter($row, $row['status'])) {
                    $result['letters']++;
                } else {
                    $result['letter_failed'][] = ['who' => $who, 'email' => $row['email']];
                }
                app_log("Application bulk resend_letter: #{$row['id']} ({$row['tracking_id']}, {$row['form_type']}) by $by");
                $result['done']++;
                continue;
            }

            // Email the confirmation link: only for an awarded application on a program that uses confirmation,
            // and not to someone who has already answered.
            if ($isRemind) {
                $cs = $confState($row);
                if ($row['status'] !== 'Accepted' || !$confOn($row['form_id'])) {
                    $result['skipped'][] = "$who: not an accepted application on a program with confirmation turned on";
                } elseif (in_array($cs, ['confirmed', 'declined'], true)) {
                    $result['skipped'][] = "$who: has already " . $cs;
                } elseif (!$srcReady) {
                    $result['skipped'][] = "$who: the committee address (mail.src_reply_to) is not set in config.php";
                } else {
                    if ($sendConfirmLink($row)) {
                        $result['reminded']++;
                    } else {
                        $result['remind_failed'][] = ['who' => $who, 'email' => $row['email']];
                    }
                    app_log("Application bulk remind_confirm: #{$row['id']} ({$row['tracking_id']}, {$row['form_type']}) by $by");
                    $result['done']++;
                }
                continue;
            }

            // Restore only works on discarded applications; everything else only on open ones.
            $need = $bulkAct === 'restore' ? ['Discarded'] : APP_OPEN;
            if (!in_array($row['status'], $need, true)) {
                $result['skipped'][] = $bulkAct === 'restore'
                    ? "$who: is not discarded"
                    : "$who: already " . ($row['status'] === 'Draft' ? 'awaiting the applicant' : strtolower($row['status']));
                continue;
            }
            if ($bulkAct === 'review' && $row['status'] === 'Under Review') {
                $result['skipped'][] = "$who: already under review";
                continue;
            }
            // Accept / Reject on a scholarship program send the committee letter instead of the status email.
            $letterKind = in_array(APP_ACTIONS[$bulkAct], ['Accepted', 'Rejected'], true) && $letterOn($row['form_id']);
            if ($letterKind && !$srcReady) {
                $result['skipped'][] = "$who: the committee address (mail.src_reply_to) is not set in config.php, nothing was changed";
                continue;
            }
            try {
                if ($bulkAct === 'info') {
                    $token = $model->generateMagicLink((int) $row['id'], true);
                    $model->updateStatus((int) $row['id'], 'Draft');
                    if (!$emailSentBack($row, $note, $token)) {
                        // Email failed: keep the link so the organizer can pass it on by hand.
                        $result['links'][] = ['who' => $who, 'email' => $row['email'], 'url' => $resumeUrl($token)];
                    }
                } else {
                    $model->updateStatus((int) $row['id'], APP_ACTIONS[$bulkAct]);
                    if ($letterKind) {
                        if ($sendLetter($row, APP_ACTIONS[$bulkAct])) {
                            $result['letters']++;
                        } else {
                            $result['letter_failed'][] = ['who' => $who, 'email' => $row['email']];
                        }
                    } else {
                        $notifyApplicant($row, APP_ACTIONS[$bulkAct], $note);
                    }
                }
                app_log("Application bulk $bulkAct: #{$row['id']} ({$row['tracking_id']}, {$row['form_type']}) by $by");
                $result['done']++;
            } catch (Throwable $e) {
                $result['skipped'][] = "$who: " . $e->getMessage();
            }
        }

        $_SESSION['application_bulk'] = $result;
        $keep = array_filter(['form' => $_GET['form'] ?? '', 'status' => $_GET['status'] ?? ''], 'strlen');
        header('Location: /admin/application-review' . ($keep ? '?' . http_build_query($keep) : ''));
        exit;
    }
}

// ---------------------------------------------------------------------------
// Single-application action
// ---------------------------------------------------------------------------
if ($app && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    $act = (string) ($_POST['action'] ?? '');
    $by = currentAdminIdentifier();
    $doneKey = $act;   // which confirmation message to show after the redirect
    try {
        if ($act === 'resend') {
            if ($app['status'] !== 'Draft') throw new Exception('Only an application waiting on the applicant can be sent a new link.');
            $token = $model->generateMagicLink((int) $app['id'], true);
            // Not emailed: the organizer passes this link on by hand.
            $_SESSION['application_link'] = ['who' => $app['applicant_name'] ?: $app['email'], 'email' => $app['email'],
                                             'message' => '', 'url' => $resumeUrl($token), 'emailFailed' => false];
        } elseif ($act === 'resend_letter') {
            if (!$letterOn($app['form_id']) || !in_array($app['status'], ['Accepted', 'Rejected'], true) || empty($app['letter_failed'])) {
                throw new Exception('There is no unsent letter for this application.');
            }
            if (!$srcReady) throw new Exception('The committee address (mail.src_reply_to) is not set in config.php, so the letter cannot be sent.');
            if (!$letterModel->claimResend((int) $app['id'])) throw new Exception('This letter is already being sent.');
            if (!$sendLetter($app, $app['status'])) throw new Exception('The letter could not be sent. Please try again in a moment.');
        } elseif ($act === 'remind_confirm') {
            if ($app['status'] !== 'Accepted' || !$confOn($app['form_id'])) {
                throw new Exception('Confirmation is not turned on for this application\'s program, or it is not an accepted application.');
            }
            if (in_array($confState($app), ['confirmed', 'declined'], true)) throw new Exception('This awardee has already answered.');
            if (!$srcReady) throw new Exception('The committee address (mail.src_reply_to) is not set in config.php, so the email cannot be sent.');
            if (!$sendConfirmLink($app)) throw new Exception('The email could not be sent. Please try again in a moment.');
        } elseif (isset(APP_ACTIONS[$act])) {
            // Restore only works on discarded applications; everything else only on open ones.
            $need = $act === 'restore' ? ['Discarded'] : APP_OPEN;
            if (!in_array($app['status'], $need, true)) {
                throw new Exception($act === 'restore'
                    ? 'This application is not discarded.'
                    : 'This application is no longer open for a decision.');
            }
            if ($act === 'info') {
                $msg = trim((string) ($_POST['message'] ?? ''));
                if ($msg === '') throw new Exception('Say what you need from the applicant.');
                $token = $model->generateMagicLink((int) $app['id'], true);
                $model->updateStatus((int) $app['id'], 'Draft');
                if (!$emailSentBack($app, $msg, $token)) {
                    // Email failed: show the link and message so the decision is not wasted.
                    $_SESSION['application_link'] = ['who' => $app['applicant_name'] ?: $app['email'], 'email' => $app['email'],
                                                     'message' => $msg, 'url' => $resumeUrl($token), 'emailFailed' => true];
                    $doneKey = 'info_manual';
                }
            } else {
                $newStatus = APP_ACTIONS[$act];
                // Accept / Reject on a scholarship program send the committee letter instead of the status email.
                $letterKind = in_array($newStatus, ['Accepted', 'Rejected'], true) && $letterOn($app['form_id']);
                if ($letterKind && !$srcReady) {
                    throw new Exception('The committee address (mail.src_reply_to) is not set in config.php, so the letter cannot be sent. Nothing was changed.');
                }
                $model->updateStatus((int) $app['id'], $newStatus);
                if ($letterKind) {
                    $doneKey = $sendLetter($app, $newStatus) ? $act . '_letter' : $act . '_unsent';
                } else {
                    $notifyApplicant($app, $newStatus, trim((string) ($_POST['note'] ?? '')));
                }
            }
        } else {
            throw new Exception('Unknown action.');
        }
        app_log("Application $act: #{$app['id']} ({$app['tracking_id']}, {$app['form_type']}) by $by");
        header('Location: /admin/application-review?id=' . $app['id'] . '&done=' . urlencode($doneKey));
        exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
    $app = $model->getApplicationById($id);   // fresh state after an error
}

$doneText = ['approve' => 'Accepted.', 'reject' => 'Rejected.', 'review' => 'Marked under review.',
             'approve_letter' => 'Accepted. The award letter was emailed to the applicant.',
             'reject_letter' => 'Rejected. The decline letter was emailed to the applicant.',
             'approve_unsent' => 'Accepted, but the award letter could not be sent. Use "Send letter again" below.',
             'reject_unsent' => 'Rejected, but the decline letter could not be sent. Use "Send letter again" below.',
             'resend_letter' => 'Letter sent.',
             'remind_confirm' => 'Confirmation link emailed to the awardee.',
             'info' => 'Sent back to the applicant. They were emailed your message and a link to update their application.',
             'info_manual' => 'Sent back to the applicant, but the email could not be sent. See below.',
             'resend' => 'New link created.',
             'discard' => 'Discarded. The applicant was not notified.', 'restore' => 'Restored to the queue.'];
if (!empty($_GET['done'])) $notice = $doneText[$_GET['done']] ?? 'Saved.';
if (($_GET['letters'] ?? '') === 'saved') $notice = 'Scholarship letters saved.';
if (($_GET['confirm'] ?? '') === 'saved') $notice = 'Award confirmation settings saved.';

// ---------------------------------------------------------------------------
// CSV of one program's awardees and their confirmation answers (organizers and owners only, like this page).
// Must run before any output. Only accepted applications are listed. Names and answers are typed by people, so
// a cell that starts with = + - @ is prefixed with an apostrophe to stop a spreadsheet running it as a formula.
// ---------------------------------------------------------------------------
if (!$id && isset($_GET['export_confirm'])) {
    $ef = (int) $_GET['export_confirm'];
    if (!isset($allowed[$ef])) { http_response_code(404); die('Program not found.'); }

    $safe = function ($v) {
        $v = (string) $v;
        return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
    };
    $awardees = array_values(array_filter($model->getAllApplications(),
        fn($r) => (int) $r['form_id'] === $ef && $r['status'] === 'Accepted'));
    usort($awardees, fn($a, $b) => strcasecmp((string) $a['applicant_name'], (string) $b['applicant_name']));

    // Columns: the fixed ones, then every question asked (current questions first, then any older ones answered).
    $labels = [];
    try {
        foreach (AwardConfirmationModel::parseFields((string) ($confSettings[$ef]['fields_text'] ?? '')) as $f) $labels[$f['label']] = true;
    } catch (Throwable $e) {}
    $perApp = [];
    foreach ($awardees as $r) {
        $blob = AwardConfirmationModel::decrypt($confByApp[(int) $r['id']]['answers_enc'] ?? '');
        $map = [];
        foreach ($blob['answers'] as $a) {
            $map[(string) ($a['label'] ?? '')] = (string) ($a['value'] ?? '');
            $labels[(string) ($a['label'] ?? '')] = true;
        }
        $perApp[(int) $r['id']] = ['map' => $map, 'reason' => $blob['reason']];
    }
    $labels = array_keys($labels);

    $slug = preg_replace('/[^a-zA-Z0-9]+/', '_', (string) ($allForms[array_search($ef, array_column($allForms, 'id'))]['form_type'] ?? 'program'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $slug . '_award_confirmations.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // so Excel reads the UTF-8 names correctly
    fputcsv($out, array_merge(['Name', 'Email', 'Tracking ID', 'Response', 'Responded (IST)', 'Reminders sent', 'Reason for declining'], $labels));
    foreach ($awardees as $r) {
        $c = $confByApp[(int) $r['id']] ?? null;
        $state = $confState($r);
        $row = [$safe($r['applicant_name']), $safe($r['email']), $safe($r['tracking_id']),
                ['confirmed' => 'Confirmed', 'declined' => 'Declined', 'awaiting' => 'Awaiting', 'overdue' => 'Overdue', 'none' => 'Not asked yet'][$state],
                $c && !empty($c['responded_at']) ? FormModel::formatIst($c['responded_at']) : '',
                (int) ($c['reminder_count'] ?? 0), $safe($perApp[(int) $r['id']]['reason'])];
        foreach ($labels as $label) $row[] = $safe($perApp[(int) $r['id']]['map'][$label] ?? '');
        fputcsv($out, $row);
    }
    fclose($out);
    app_log("Award confirmations exported: form #$ef by " . currentAdminIdentifier());
    exit;
}

// One-time link(s) to hand to the applicant: a new link from "Create a new link" (never emailed),
// or the link from a send-back whose email could not be sent.
$manual = $_SESSION['application_link'] ?? null;
unset($_SESSION['application_link']);
$bulk = $_SESSION['application_bulk'] ?? null;
unset($_SESSION['application_bulk']);
$bulkVerb = ['approve' => 'accepted', 'reject' => 'rejected', 'review' => 'marked under review',
             'info' => 'sent back for more information', 'discard' => 'discarded (no one was notified)',
             'restore' => 'restored to the queue', 'resend_letter' => 'had their letter sent again',
             'remind_confirm' => 'sent their confirmation link'];
// Pill for an awarded row: Confirmed / Declined / Awaiting / Overdue. Overdue and Declined are the two to act on.
$confLabel = ['confirmed' => 'Confirmed', 'declined' => 'Declined', 'awaiting' => 'Awaiting confirmation', 'overdue' => 'Overdue'];
$confTone  = ['confirmed' => 'st-ok', 'declined' => 'st-off', 'awaiting' => 'st-wait', 'overdue' => 'st-bad'];

$fForm = (string) ($_GET['form'] ?? ''); $fStatus = (string) ($_GET['status'] ?? '');
$h = fn($v) => htmlspecialchars((string) $v);
$label = fn($s) => $s === 'Draft' ? 'Awaiting applicant' : $s;
$tone = fn($s) => ['New' => 'st-new', 'Submitted' => 'st-new', 'Under Review' => 'st-review',
                   'Draft' => 'st-wait', 'Accepted' => 'st-ok', 'Rejected' => 'st-bad',
                   'Discarded' => 'st-off'][$s] ?? 'st-new';

$crumbs = $app
    ? [['Workspace', '/admin/dashboard'], ['Application review', '/admin/application-review'], [$app['applicant_name'] ?: $app['email']]]
    : [['Workspace', '/admin/dashboard'], ['Application review']];

engage_header([
    'title'   => 'Application review',
    'heading' => 'Application review',
    'kicker'  => 'Organizer workspace',
    'lead'    => 'Responses to program forms. Membership forms are handled in Membership review, feedback in the Feedback tab.',
    'tools'   => '',
    'wide'    => true,
    'crumbs'  => $crumbs,
]);
?>
<style>
    /* Application review only. Shared pieces (.sect, .btn-*.sm, tokens) come from engage.css and staff.css */
    .pill.st-new { --tone: var(--primary); }
    .pill.st-review { --tone: var(--violet); }
    .pill.st-wait { --tone: var(--amber); }
    .pill.st-ok { --tone: var(--leaf-dark); }
    .pill.st-bad { --tone: var(--accent); }
    .pill.st-off { --tone: var(--slate); }

    .action-banner ul { flex-basis: 100%; margin: 6px 0 0; padding-left: 18px; }
    .flash-link { display: block; flex-basis: 100%; margin-top: 8px; padding: 10px; background: rgba(6,26,30,.07); border-radius: 8px; font-size: 12px; word-break: break-all; }

    dl.answers { margin: 14px 0 0; }
    dl.answers dt { margin-top: 14px; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
    dl.answers dd { margin: 3px 0 0; white-space: pre-wrap; }

    .actrow { display: flex; flex-wrap: wrap; gap: 10px; }
    .actform { margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
    .actform .field { margin-bottom: 12px; }
    .actform textarea { width: 100%; padding: 12px 14px; border: 1.5px solid #BFD3D1; border-radius: 10px; font: inherit; }
    .actform textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px color-mix(in srgb, var(--primary) 20%, transparent); }

    .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 16px; }
    .filters .total { margin-left: auto; font-size: 13.5px; font-weight: 600; color: var(--muted); }
    .bulkbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 14px; padding: 12px 14px; background: #fff; border: 1.5px solid var(--primary); border-radius: 14px; box-shadow: 0 3px 0 var(--primary-dark), 0 10px 22px rgba(6,26,30,.14); }
    .bulkbar .count { min-width: 90px; font-size: 14px; font-weight: 800; color: var(--primary-dark); }
    .bulkbar input[type=text] { flex: 1 1 240px; min-width: 200px; }
    .rowform { display: inline-flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0; }
    .rowform input[type=text] { width: 170px; padding: 6px 10px; font-size: 13px; }
    .rowform select { padding: 6px 8px; font-size: 13px; }
    .tbl th.pick, .tbl td.pick { width: 36px; padding-right: 0; }
    .tbl td.pick input, .tbl th.pick input { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }
    .tbl td.pick input:disabled { cursor: not-allowed; }
    .dash { color: var(--muted); }
    .unsent { font-size: 12.5px; font-weight: 700; color: var(--accent); }

    /* One collapsible block per program */
    details.prog-group { padding: 0; overflow: hidden; border-left: 6px solid var(--primary); }
    details.prog-group > summary {
        display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; padding: 16px 22px;
        cursor: pointer; list-style: none; background: linear-gradient(90deg, var(--primary-tint), #fff 70%);
    }
    details.prog-group > summary::-webkit-details-marker { display: none; }
    details.prog-group > summary::before { content: '\25B8'; color: var(--primary); font-size: 15px; }
    details.prog-group[open] > summary::before { content: '\25BE'; }
    details.prog-group > summary:focus-visible { outline: 3px solid var(--focus); outline-offset: -3px; }
    details.prog-group .pg-title { font-size: 17px; font-weight: 800; letter-spacing: -.01em; }
    details.prog-group .pg-slug { font-size: 12.5px; color: var(--muted); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    details.prog-group .pg-stats { margin-left: auto; display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; font-size: 13px; font-weight: 600; color: var(--muted); }
    details.prog-group .pg-body { padding: 4px 18px 18px; border-top: 1px solid var(--border); }
    details.prog-group .tbl-wrap { margin-top: 14px; }
    .toolbar-right { margin-left: auto; display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
    .toolbar-right .total { font-size: 13.5px; font-weight: 600; color: var(--muted); }

    /* Scholarship letters editor (inside each program) */
    details.letters { margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border); }
    details.letters > summary { cursor: pointer; font-size: 14.5px; font-weight: 800; color: var(--primary-dark); list-style: none; }
    details.letters > summary::-webkit-details-marker { display: none; }
    details.letters > summary::before { content: '\25B8'; margin-right: 8px; color: var(--muted); }
    details.letters[open] > summary::before { content: '\25BE'; }
    .letterform { margin: 14px 0 0; }
    .letterform .field { margin-bottom: 14px; }
    .letterform label { display: block; margin-bottom: 5px; font-size: 13.5px; font-weight: 700; }
    .letterform input[type=text], .letterform textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #BFD3D1; border-radius: 10px; font: inherit; font-size: 14px; }
    .letterform input[type=text]:focus, .letterform textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px color-mix(in srgb, var(--primary) 20%, transparent); }
    .letterform .chk { display: flex; gap: 8px; align-items: flex-start; margin: 0 0 16px; font-weight: 600; }
    .letterform .chk input { margin-top: 4px; }
    .letterform .hint { margin: 0 0 14px; font-size: 12.5px; color: var(--muted); }
</style>

<?php if ($notice): ?><div class="alert ok"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($manual): ?>
    <div class="action-banner">
        <?php if (!empty($manual['emailFailed'])): ?>
            <span><strong>The email could not be sent.</strong> Send <?= $h($manual['who']) ?> (<?= $h($manual['email']) ?>) this one-time link<?= $manual['message'] !== '' ? ' with your message' : '' ?>:</span>
        <?php else: ?>
            <span><strong>Nothing was emailed.</strong> Send <?= $h($manual['who']) ?> (<?= $h($manual['email']) ?>) this one-time link<?= $manual['message'] !== '' ? ' with your message' : '' ?>:</span>
        <?php endif; ?>
        <?php if ($manual['message'] !== ''): ?><code class="flash-link"><?= nl2br($h($manual['message'])) ?></code><?php endif; ?>
        <code class="flash-link"><?= $h($manual['url']) ?></code>
    </div>
<?php endif; ?>
<?php if ($bulk): ?>
    <div class="alert <?= $bulk['done'] ? 'ok' : 'error' ?>">
        <?= (int) $bulk['done'] ?> application<?= $bulk['done'] === 1 ? '' : 's' ?> <?= $h($bulkVerb[$bulk['act']] ?? 'updated') ?>.
        <?= $bulk['skipped'] ? count($bulk['skipped']) . ' skipped.' : '' ?>
        <?php if ($bulk['act'] === 'info' && $bulk['done'] > count($bulk['links'])): ?>
            <?= (int) ($bulk['done'] - count($bulk['links'])) ?> emailed.
        <?php endif; ?>
        <?php if (!empty($bulk['letters'])): ?>
            <?= (int) $bulk['letters'] ?> letter<?= $bulk['letters'] === 1 ? '' : 's' ?> emailed.
        <?php endif; ?>
        <?php if (!empty($bulk['reminded'])): ?>
            <?= (int) $bulk['reminded'] ?> confirmation link<?= $bulk['reminded'] === 1 ? '' : 's' ?> emailed.
        <?php endif; ?>
    </div>
    <?php if (!empty($bulk['remind_failed'])): ?>
        <div class="action-banner"><strong>These confirmation emails could not be sent.</strong> Tick them and try again, or write to them yourself:
            <ul><?php foreach ($bulk['remind_failed'] as $l): ?><li><?= $h($l['who']) ?> (<?= $h($l['email']) ?>)</li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if ($bulk['skipped']): ?>
        <div class="action-banner"><strong>Skipped</strong><ul><?php foreach ($bulk['skipped'] as $s): ?><li><?= $h($s) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if (!empty($bulk['letter_failed'])): ?>
        <div class="action-banner"><strong>These letters could not be sent.</strong> The decisions are saved. Tick them and choose &ldquo;Resend unsent letter&rdquo; to try again, or write to them yourself:
            <ul><?php foreach ($bulk['letter_failed'] as $l): ?><li><?= $h($l['who']) ?> (<?= $h($l['email']) ?>)</li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if ($bulk['links']): ?>
        <div class="action-banner"><strong>These emails could not be sent.</strong> Send each of these applicants your message and their one-time link:
            <?php if ($bulk['message'] !== ''): ?><code class="flash-link"><?= nl2br($h($bulk['message'])) ?></code><?php endif; ?>
            <ul><?php foreach ($bulk['links'] as $l): ?><li><?= $h($l['who']) ?> (<?= $h($l['email']) ?>): <code><?= $h($l['url']) ?></code></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= $h($error) ?></div><?php endif; ?>

<?php if ($app):
    $form = $formModel->getFormById((int) $app['form_id']);
    $schema = $form['schema'] ?? [];
    $data = json_decode($app['form_data'] ?? '', true) ?: [];
    $open = in_array($app['status'], APP_OPEN, true);
    $lettersOn = $letterOn($app['form_id']); ?>
    <section class="sect">
        <div class="qhead">
            <h3><?= $h($app['applicant_name'] ?: $app['email']) ?></h3>
            <span class="pill <?= $tone($app['status']) ?>"><?= $h($label($app['status'])) ?></span>
        </div>
        <p class="qmeta">
            <?= $h($app['email']) ?> &middot;
            <?= $h($app['form_title'] ?: $app['form_type']) ?> &middot;
            <?= $h($app['tracking_id']) ?> &middot;
            Submitted <?= $h($app['created_at']) ?>
            <?php if (!empty($app['letter_sent_at'])): ?> &middot; Letter emailed <?= $h($app['letter_sent_at']) ?><?php endif; ?>
        </p>
        <dl class="answers">
        <?php foreach ($schema['fields'] ?? [] as $f):
            if (empty($f['name'])) continue;
            $v = $data[$f['name']] ?? ''; if (is_array($v)) $v = implode(', ', $v); ?>
            <dt><?= MiniWikiText::inline($h($f['label'] ?? $f['name'])) ?></dt>
            <dd><?= $v === '' ? '&mdash;' : (($f['type'] ?? '') === 'file' ? 'File uploaded: ' . $h($v) : $h($v)) ?></dd>
        <?php endforeach; ?>
        </dl>
        <p class="qmeta" style="margin-top:16px;"><a href="/admin/form_manager?id=<?= (int) $app['form_id'] ?>">Open in form manager</a>
            for internal notes and CSV export.</p>
    </section>

    <?php if ($open): ?>
    <section class="sect">
        <form method="POST"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <?php if ($lettersOn): ?>
                <p class="qmeta" style="margin:0 0 14px;">Scholarship letters are on for this program. <strong>Accept</strong> emails the committee&rsquo;s award letter and <strong>Reject</strong> emails its decline letter, instead of the standard status email.</p>
            <?php else: ?>
            <div class="field">
                <label>Note to include in the email to the applicant (optional)</label>
                <input type="text" name="note" style="width:100%">
            </div>
            <?php endif; ?>
            <div class="actrow">
            <button name="action" value="approve" class="btn-ok"
                    onclick="return confirm('Accept this application? <?= $lettersOn ? 'The award letter is emailed right now. ' : '' ?>It will be locked.');">Accept</button>
            <?php if ($app['status'] !== 'Under Review'): ?>
                <button name="action" value="review" class="btn-ghost">Mark under review</button>
            <?php endif; ?>
            <button name="action" value="reject" class="btn-bad"
                    onclick="return confirm('Reject this application? <?= $lettersOn ? 'The decline letter is emailed right now. ' : '' ?>It will be locked.');">Reject</button>
            <button name="action" value="discard" class="btn-ghost"
                    onclick="return confirm('Discard this application? The applicant will NOT be notified. You can restore it later.');">Discard</button>
            </div>
        </form>
        <form method="POST" class="actform"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <div class="field">
                <label>Ask for more information (emails the applicant your message and a one-time link to edit and resubmit)</label>
                <textarea name="message" rows="3" placeholder="e.g. Please upload a clearer copy of your ID."></textarea>
            </div>
            <button name="action" value="info" class="btn-ghost">Send back for more information</button>
        </form>
    </section>
    <?php elseif ($app['status'] === 'Draft'): ?>
    <section class="sect">
        <p style="margin:0 0 12px;">Waiting for the applicant to finish or update. It returns to the queue when they submit.</p>
        <form method="POST"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <button name="action" value="resend" class="btn-ghost">Create a new link</button>
        </form>
    </section>
    <?php elseif ($app['status'] === 'Discarded'): ?>
    <section class="sect">
        <p style="margin:0 0 12px;">Discarded. The applicant was not notified, and this application is hidden from the queue.</p>
        <form method="POST"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <button name="action" value="restore" class="btn-ghost">Restore to queue</button>
        </form>
    </section>
    <?php else: ?>
        <div class="empty-note">This application has been decided and is locked.</div>
        <?php if ($lettersOn && !empty($app['letter_failed']) && in_array($app['status'], ['Accepted', 'Rejected'], true)): ?>
        <section class="sect">
            <p style="margin:0 0 12px;"><strong>The <?= $app['status'] === 'Accepted' ? 'award' : 'decline' ?> letter has not been sent.</strong> The decision is saved.</p>
            <form method="POST"><?= CSRF::getInputField() ?>
                <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                <button name="action" value="resend_letter" class="btn-ghost">Send letter again</button>
            </form>
        </section>
        <?php endif; ?>

        <?php
        // Award confirmation: what the awardee answered, and a button to email their link again.
        $cState = $confState($app);
        $cRow = $confByApp[(int) $app['id']] ?? null;
        if ($app['status'] === 'Accepted' && ($cState !== 'none' || $confOn($app['form_id']))):
            $cBlob = AwardConfirmationModel::decrypt($cRow['answers_enc'] ?? ''); ?>
        <section class="sect">
            <div class="qhead">
                <h3>Award confirmation</h3>
                <?php if ($cState !== 'none'): ?><span class="pill <?= $confTone[$cState] ?>"><?= $h($confLabel[$cState]) ?></span><?php endif; ?>
            </div>
            <p class="qmeta">
                <?php if ($cState === 'confirmed' || $cState === 'declined'): ?>
                    <?= $cState === 'confirmed' ? 'Confirmed' : 'Declined' ?> on <?= $h(FormModel::formatIst($cRow['responded_at'] ?? null)) ?>
                <?php elseif ($cState === 'overdue'): ?>
                    No answer, and the deadline (<?= $h(FormModel::formatIst($confSettings[(int) $app['form_id']]['deadline_at'] ?? null)) ?>) has passed. Nothing has been changed: the place is yours to keep or offer to someone else.
                <?php elseif ($cState === 'awaiting'): ?>
                    Waiting for an answer. Deadline <?= $h(FormModel::formatIst($confSettings[(int) $app['form_id']]['deadline_at'] ?? null)) ?>.
                <?php else: ?>
                    This awardee has not been sent a confirmation link yet.
                <?php endif; ?>
                <?php if (!empty($cRow['reminder_count'])): ?> &middot; Link emailed <?= (int) $cRow['reminder_count'] ?> time<?= (int) $cRow['reminder_count'] === 1 ? '' : 's' ?> as a reminder<?php endif; ?>
            </p>
            <?php if ($cBlob['answers']): ?>
                <dl class="answers">
                <?php foreach ($cBlob['answers'] as $a): ?>
                    <dt><?= $h($a['label'] ?? '') ?></dt>
                    <dd><?= (string) ($a['value'] ?? '') === '' ? '&mdash;' : $h($a['value']) ?></dd>
                <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <?php if ($cBlob['reason'] !== ''): ?>
                <dl class="answers"><dt>Reason given for declining</dt><dd><?= $h($cBlob['reason']) ?></dd></dl>
            <?php endif; ?>
            <?php if ($confOn($app['form_id']) && !in_array($cState, ['confirmed', 'declined'], true)): ?>
                <form method="POST" style="margin-top:16px;"><?= CSRF::getInputField() ?>
                    <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                    <button name="action" value="remind_confirm" class="btn-ghost"
                            onclick="return confirm('Email this awardee their confirmation link?');"><?= $cState === 'none' ? 'Send confirmation link' : 'Send reminder' ?></button>
                </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    <?php endif; ?>

<?php else:
    // The queue lists open, non-test programs only. A program the person asked for by name (?form=...,
    // e.g. from the form manager) is shown even if it is closed or a test form.
    $listedForms = array_values(array_filter($allForms, fn($f) => $f['form_type'] === $fForm || !review_hidden_form($f)));
    $listed = [];
    foreach ($listedForms as $f) $listed[(int) $f['id']] = true;

    // Discarded applications are hidden unless the Discarded status filter is chosen.
    $rows = array_values(array_filter($model->getAllApplications(), function ($r) use ($listed, $fForm, $fStatus) {
        return isset($listed[(int) $r['form_id']])
            && ($fForm === '' || $r['form_type'] === $fForm)
            && ($fStatus === '' ? $r['status'] !== 'Discarded' : $r['status'] === $fStatus);
    }));

    // One group per program (form): programs with applications waiting come first, then the busiest.
    $groups = [];
    foreach ($listedForms as $f) {
        $groups[(int) $f['id']] = ['form' => $f, 'rows' => [], 'waiting' => 0, 'counts' => []];
    }
    foreach ($rows as $r) {
        $fid = (int) $r['form_id'];
        if (!isset($groups[$fid])) continue;
        $groups[$fid]['rows'][] = $r;
        $groups[$fid]['counts'][$r['status']] = ($groups[$fid]['counts'][$r['status']] ?? 0) + 1;
        if (in_array($r['status'], ['New', 'Submitted'], true)) $groups[$fid]['waiting']++;
        $cs = $confState($r);
        if ($cs !== 'none') $groups[$fid]['conf'][$cs] = ($groups[$fid]['conf'][$cs] ?? 0) + 1;
    }
    $groups = array_filter($groups, fn($g) => $g['rows']);
    uasort($groups, fn($a, $b) => ($b['waiting'] <=> $a['waiting']) ?: (count($b['rows']) <=> count($a['rows'])));
    $filtering = $fForm !== '' || $fStatus !== '';
    $totalWaiting = array_sum(array_column($groups, 'waiting')); ?>
    <section class="sect">
        <form method="GET" class="filters">
            <select name="form"><option value="">All programs</option>
                <?php foreach ($listedForms as $f): ?>
                    <option value="<?= $h($f['form_type']) ?>" <?= $f['form_type'] === $fForm ? 'selected' : '' ?>><?= $h($f['title'] ?: $f['form_type']) ?></option>
                <?php endforeach; ?></select>
            <select name="status"><option value="">All statuses</option>
                <?php foreach (['New','Submitted','Under Review','Draft','Accepted','Rejected','Discarded'] as $s): ?>
                    <option value="<?= $h($s) ?>" <?= $s === $fStatus ? 'selected' : '' ?>><?= $h($label($s)) ?></option>
                <?php endforeach; ?></select>
            <button class="btn-ghost sm">Filter</button>
            <?php if ($filtering): ?><a href="/admin/application-review" class="btn-ghost sm" style="text-decoration:none">Clear</a><?php endif; ?>
            <span class="toolbar-right">
                <span class="total"><?= count($rows) ?> application<?= count($rows) === 1 ? '' : 's' ?>
                    in <?= count($groups) ?> program<?= count($groups) === 1 ? '' : 's' ?><?= $totalWaiting ? ' &middot; ' . $totalWaiting . ' waiting' : '' ?></span>
                <?php if (count($groups) > 1): ?>
                    <button type="button" class="btn-ghost sm" id="expandAll">Expand all</button>
                    <button type="button" class="btn-ghost sm" id="collapseAll">Collapse all</button>
                <?php endif; ?>
            </span>
        </form>

        <?php /* Posts back to this same URL, so the active filters are kept. Ticks from every program count together. */ ?>
        <form method="POST" id="bulkForm" class="bulkbar" style="display:none"><?= CSRF::getInputField() ?>
            <input type="hidden" name="action" value="bulk">
            <div id="bulkIdsContainer"></div>
            <span class="count" id="bulkCount"></span>
            <select name="bulk_action" id="bulkAction" required>
                <option value="">Set status to&hellip;</option>
                <option value="review">Under Review</option>
                <option value="approve">Accepted</option>
                <option value="info">Needs information</option>
                <option value="reject">Rejected</option>
                <option value="discard">Discard (no email)</option>
                <option value="restore">Restore discarded</option>
                <option value="resend_letter">Resend unsent letter</option>
                <option value="remind_confirm">Email confirmation link / reminder</option>
            </select>
            <input type="text" name="note" id="bulkNote"
                placeholder="Note emailed with the decision (optional) / what you need (required for Needs information)">
            <button type="submit" id="bulkApply" class="btn-solid sm">Apply to selected</button>
        </form>

        <?php if (!$groups): ?>
            <div class="empty-note">Nothing to review here.</div>
        <?php endif; ?>
    </section>

    <?php foreach ($groups as $g):
        $f = $g['form']; $c = $g['counts']; $n = count($g['rows']);
        $isOpen = $filtering || $g['waiting'] > 0 || $keepLetters !== null || $keepConfirm !== null;
        $cc = $g['conf'] ?? [];
        $CS = $confSettings[(int) $f['id']] ?? null;
        $keepC = ($keepConfirm !== null && (int) ($keepConfirm['form_id'] ?? 0) === (int) $f['id']) ? $keepConfirm : null;
        // Confirmation panel values: what was just posted (if a save was refused), else what is stored, else a default.
        $cv = function (string $postKey, string $col, string $default = '') use ($keepC, $CS): string {
            if ($keepC !== null) return (string) ($keepC[$postKey] ?? '');
            $v = (string) ($CS[$col] ?? '');
            return $v !== '' ? $v : $default;
        };
        $cChecked = $keepC !== null ? !empty($keepC['confirm_enabled']) : !empty($CS['enabled']);
        $cDeadline = $keepC !== null ? (string) ($keepC['confirm_deadline'] ?? '') : FormModel::utcToIstInput($CS['deadline_at'] ?? null);
        $cMin = (new DateTimeImmutable('now', new DateTimeZone(FormModel::DISPLAY_TZ)))->format('Y-m-d\TH:i');
        $L = $letters[(int) $f['id']] ?? null;
        $keepL = ($keepLetters !== null && (int) ($keepLetters['form_id'] ?? 0) === (int) $f['id']) ? $keepLetters : null;
        // Value for a letter field: what was just posted (if a save was refused), else what is stored, else a default.
        $lv = function (string $key, string $default = '') use ($keepL, $L): string {
            if ($keepL !== null) return (string) ($keepL[$key] ?? '');
            $v = (string) ($L[$key] ?? '');
            return $v !== '' ? $v : $default;
        };
        $lChecked = $keepL !== null ? !empty($keepL['enabled']) : !empty($L['enabled']);
        $lActive = ScholarshipLetterModel::isActive($L); ?>
    <details class="sect prog-group"<?= $isOpen ? ' open' : '' ?>>
        <summary>
            <span class="pg-title"><?= $h($f['title'] ?: $f['form_type']) ?></span>
            <span class="pg-slug">/<?= $h($f['form_type']) ?></span>
            <span class="pg-stats">
                <?php if ($lActive): ?><span class="pill st-ok">letters on</span><?php endif; ?>
                <?php if (!empty($cc['overdue'])): ?><span class="pill badge-attn"><?= (int) $cc['overdue'] ?> overdue</span><?php endif; ?>
                <?php if (!empty($cc['declined'])): ?><span class="pill st-off"><?= (int) $cc['declined'] ?> declined</span><?php endif; ?>
                <?php if (!empty($cc['confirmed']) || !empty($cc['awaiting'])): ?><span class="pill st-wait"><?= (int) ($cc['confirmed'] ?? 0) ?> confirmed &middot; <?= (int) ($cc['awaiting'] ?? 0) ?> awaiting</span><?php endif; ?>
                <?php if ($g['waiting']): ?><span class="pill badge-attn"><?= (int) $g['waiting'] ?> waiting</span><?php endif; ?>
                <?php if (!empty($c['Under Review'])): ?><span class="pill st-review"><?= (int) $c['Under Review'] ?> under review</span><?php endif; ?>
                <?php if (!empty($c['Draft'])): ?><span class="pill st-wait"><?= (int) $c['Draft'] ?> awaiting applicant</span><?php endif; ?>
                <?php if (!empty($c['Discarded'])): ?><span class="pill st-off"><?= (int) $c['Discarded'] ?> discarded</span><?php endif; ?>
                <span><?= (int) ($c['Accepted'] ?? 0) ?> accepted &middot; <?= (int) ($c['Rejected'] ?? 0) ?> rejected &middot; <?= $n ?> total</span>
            </span>
        </summary>
        <div class="pg-body">
        <div class="tbl-wrap">
        <table class="tbl"><thead><tr>
            <th class="pick"><input type="checkbox" class="pick-all" aria-label="Select all in <?= $h($f['title'] ?: $f['form_type']) ?>"></th>
            <th>Applicant</th><th>Tracking ID</th><th>Status</th><th>Submitted</th><th>Update</th></tr></thead><tbody>
        <?php foreach ($g['rows'] as $r):
            $rowOpen = in_array($r['status'], APP_OPEN, true);
            $rowDiscarded = $r['status'] === 'Discarded';
            $rowLetter = $letterOn($r['form_id']);   // Accept / Reject send the committee letter on this program
            $rowResend = $rowLetter && !empty($r['letter_failed']) && in_array($r['status'], ['Accepted', 'Rejected'], true);
            $rowConf = $confState($r);   // confirmed | declined | awaiting | overdue | none
            $rowRemind = $r['status'] === 'Accepted' && $confOn($r['form_id']) && in_array($rowConf, ['awaiting', 'overdue'], true);
            $rowPick = $rowOpen || $rowDiscarded || $rowResend || $rowRemind; ?>
            <tr>
                <td class="pick"><input type="checkbox" class="pick-row" value="<?= (int) $r['id'] ?>" data-letters="<?= $rowLetter ? '1' : '0' ?>"
                    <?= $rowPick ? '' : 'disabled title="Already decided or waiting on the applicant"' ?>></td>
                <td><a class="dl" href="?id=<?= (int) $r['id'] ?>"><?= $h($r['applicant_name'] ?: $r['email']) ?></a>
                    <span class="sub"><?= $h($r['email']) ?></span></td>
                <td><code><?= $h($r['tracking_id']) ?></code></td>
                <td><span class="pill <?= $tone($r['status']) ?>"><?= $h($label($r['status'])) ?></span>
                    <?php if ($rowConf !== 'none'): ?><span class="pill <?= $confTone[$rowConf] ?>"><?= $h($confLabel[$rowConf]) ?></span><?php endif; ?></td>
                <td><?= $h($r['created_at']) ?></td>
                <td>
                <?php if ($rowOpen): ?>
                    <form method="POST" class="rowform" data-letters="<?= $rowLetter ? '1' : '0' ?>"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="action" value="bulk">
                        <input type="hidden" name="ids[]" value="<?= (int) $r['id'] ?>">
                        <select name="bulk_action" aria-label="Set status">
                            <option value="review">Under Review</option>
                            <option value="approve">Accepted</option>
                            <option value="info">Needs information</option>
                            <option value="reject">Rejected</option>
                            <option value="discard">Discard (no email)</option>
                        </select>
                        <input type="text" name="note" placeholder="<?= $rowLetter ? 'Only for Needs information' : 'Note (optional)' ?>">
                        <button type="submit" class="btn-ghost sm">Apply</button>
                    </form>
                <?php elseif ($rowDiscarded): ?>
                    <form method="POST" class="rowform"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="action" value="bulk">
                        <input type="hidden" name="bulk_action" value="restore">
                        <input type="hidden" name="ids[]" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn-ghost sm">Restore</button>
                    </form>
                <?php elseif ($rowResend): ?>
                    <form method="POST" class="rowform"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="action" value="bulk">
                        <input type="hidden" name="bulk_action" value="resend_letter">
                        <input type="hidden" name="ids[]" value="<?= (int) $r['id'] ?>">
                        <span class="unsent">Letter not sent</span>
                        <button type="submit" class="btn-ghost sm">Resend letter</button>
                    </form>
                <?php elseif ($rowRemind): ?>
                    <form method="POST" class="rowform"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="action" value="bulk">
                        <input type="hidden" name="bulk_action" value="remind_confirm">
                        <input type="hidden" name="ids[]" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="btn-ghost sm">Send reminder</button>
                    </form>
                <?php else: ?><span class="dash">&mdash;</span><?php endif; ?>
                </td></tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>

        <details class="letters"<?= $keepC !== null ? ' open' : '' ?>>
            <summary>Award confirmation <?= AwardConfirmationModel::isOn($CS) ? '<span class="pill st-ok">on</span>' : '<span class="pill st-off">off</span>' ?></summary>
            <form method="POST" class="letterform"><?= CSRF::getInputField() ?>
                <input type="hidden" name="form_id" value="<?= (int) $f['id'] ?>">
                <label class="chk"><input type="checkbox" name="confirm_enabled" value="1" <?= $cChecked ? 'checked' : '' ?>>
                    <span>Turn on for this program: the award letter carries each awardee&rsquo;s private link, where they accept or decline the place and share their travel and stay details.</span></label>

                <div class="field">
                    <label for="cd-<?= (int) $f['id'] ?>">Respond by (IST)</label>
                    <input type="datetime-local" id="cd-<?= (int) $f['id'] ?>" name="confirm_deadline" value="<?= $h($cDeadline) ?>" min="<?= $h($cMin) ?>">
                </div>
                <div class="field">
                    <label for="ci-<?= (int) $f['id'] ?>">Introduction shown on the confirmation page (optional)</label>
                    <textarea id="ci-<?= (int) $f['id'] ?>" name="confirm_intro" rows="3" maxlength="<?= (int) AwardConfirmationModel::INTRO_MAX ?>"><?= $h($cv('confirm_intro', 'intro_text')) ?></textarea>
                </div>
                <div class="field">
                    <label for="cf-<?= (int) $f['id'] ?>">Questions, one per line</label>
                    <textarea id="cf-<?= (int) $f['id'] ?>" name="confirm_fields" rows="9" maxlength="<?= (int) AwardConfirmationModel::FIELDS_MAX_CHARS ?>"><?= $h($cv('confirm_fields', 'fields_text', AwardConfirmationModel::DEFAULT_FIELDS)) ?></textarea>
                </div>

                <p class="hint">Introduction formatting: <code>'''bold'''</code> <code>''italic''</code> <code>[https://example.org link text]</code> <code>* bullet</code> <code># numbered</code> <code>== heading ==</code>. Use &ldquo;Preview confirmation page&rdquo; to see it as an awardee will.<br>
                    Question format: <code>Question | type | required or optional | choices</code>. Types: text, textarea, date, tel, number, email, select (a select needs its choices, separated by commas). To ask a question only for some answers, add a fifth part: <code>Do you need accommodation? | select | required | Yes, No | if Are you local? = No</code> (the earlier question must be a select; leave the choices part empty for other types).
                    The award letter must contain <code>[{confirm_link} Confirm your place]</code> where the link should appear; confirmation can only be turned on once it does, and letters are on.
                    Answers are stored encrypted. Declining only flags the place as free: nothing is released automatically, and you accept whoever you choose.
                    <?php if (!empty($CS['saved_at'])): ?>Last saved <?= $h($CS['saved_at']) ?> by <?= $h($CS['saved_by']) ?>.<?php endif; ?></p>
                <?php if (!$srcReady): ?><p class="hint" style="color:var(--accent);">Add <code>mail.src_reply_to</code> to config.php before turning confirmation on.</p><?php endif; ?>

                <div class="actrow">
                    <button type="submit" name="action" value="save_confirm" class="btn-solid sm">Save confirmation settings</button>
                    <button type="submit" name="action" value="preview_confirm" formtarget="_blank" class="btn-ghost sm">Preview confirmation page</button>
                    <a class="btn-ghost sm" style="text-decoration:none" href="?export_confirm=<?= (int) $f['id'] ?>">Download responses (CSV)</a>
                </div>
            </form>
        </details>

        <details class="letters"<?= $keepL !== null ? ' open' : '' ?>>
            <summary>Scholarship letters <?= $lActive ? '<span class="pill st-ok">on</span>' : '<span class="pill st-off">off</span>' ?></summary>
            <form method="POST" class="letterform"><?= CSRF::getInputField() ?>
                <input type="hidden" name="form_id" value="<?= (int) $f['id'] ?>">
                <label class="chk"><input type="checkbox" name="enabled" value="1" <?= $lChecked ? 'checked' : '' ?>>
                    <span>Turn on for this program: Accept emails the award letter and Reject emails the decline letter, instead of the standard status email. One email per decision.</span></label>

                <div class="field">
                    <label for="as-<?= (int) $f['id'] ?>">Award letter: subject</label>
                    <input type="text" id="as-<?= (int) $f['id'] ?>" name="award_subject" maxlength="<?= (int) ScholarshipLetterModel::SUBJECT_MAX ?>"
                           value="<?= $h($lv('award_subject', 'Scholarship award: {event}')) ?>">
                </div>
                <div class="field">
                    <label for="ab-<?= (int) $f['id'] ?>">Award letter: message (what the scholarship covers, next steps)</label>
                    <textarea id="ab-<?= (int) $f['id'] ?>" name="award_body" rows="10" maxlength="<?= (int) ScholarshipLetterModel::BODY_MAX ?>"><?= $h($lv('award_body')) ?></textarea>
                </div>
                <div class="field">
                    <label for="ds-<?= (int) $f['id'] ?>">Decline letter: subject</label>
                    <input type="text" id="ds-<?= (int) $f['id'] ?>" name="decline_subject" maxlength="<?= (int) ScholarshipLetterModel::SUBJECT_MAX ?>"
                           value="<?= $h($lv('decline_subject', 'Your application for {event}')) ?>">
                </div>
                <div class="field">
                    <label for="db-<?= (int) $f['id'] ?>">Decline letter: message (say what happens next, and whether they may apply again)</label>
                    <textarea id="db-<?= (int) $f['id'] ?>" name="decline_body" rows="8" maxlength="<?= (int) ScholarshipLetterModel::BODY_MAX ?>"><?= $h($lv('decline_body')) ?></textarea>
                </div>

                <p class="hint">Each email starts with &ldquo;Dear &lt;applicant name&gt;,&rdquo; and ends with &ldquo;Scholarship Review Committee, Deoband Community Wikimedia&rdquo;; write only what goes between.
                    Replies go to the committee address. Use <code>{event}</code> for the event name.
                    Formatting: <code>'''bold'''</code> <code>''italic''</code> <code>[https://example.org link text]</code> <code>* bullet</code> <code># numbered</code> <code>== heading ==</code>.
                    <?php if (!empty($L['saved_at'])): ?>Last saved <?= $h($L['saved_at']) ?> by <?= $h($L['saved_by']) ?> (version <?= (int) $L['version'] ?>).<?php endif; ?></p>
                <?php if (!$srcReady): ?><p class="hint" style="color:var(--accent);">Add <code>mail.src_reply_to</code> to config.php before turning letters on.</p><?php endif; ?>

                <div class="actrow">
                    <button type="submit" name="action" value="save_letters" class="btn-solid sm">Save letters</button>
                    <button type="submit" name="action" value="preview_award" formtarget="_blank" class="btn-ghost sm">Preview award letter</button>
                    <button type="submit" name="action" value="preview_decline" formtarget="_blank" class="btn-ghost sm">Preview decline letter</button>
                </div>
            </form>
        </details>
        </div>
    </details>
    <?php endforeach; ?>

    <script>
    (function () {
        var bulkForm = document.getElementById('bulkForm');
        var rows = Array.prototype.slice.call(document.querySelectorAll('.pick-row:not(:disabled)'));
        var alls = Array.prototype.slice.call(document.querySelectorAll('.pick-all'));
        var count = document.getElementById('bulkCount');
        var action = document.getElementById('bulkAction');
        var note = document.getElementById('bulkNote');
        var ids = document.getElementById('bulkIdsContainer');

        function picked() { return rows.filter(function (r) { return r.checked; }); }
        function actionable(table) { return Array.prototype.slice.call(table.querySelectorAll('.pick-row:not(:disabled)')); }

        // The bar only appears once something is ticked, in any program.
        function sync() {
            var n = picked().length;
            bulkForm.style.display = n > 0 ? 'flex' : 'none';
            count.textContent = n + ' selected';
            alls.forEach(function (a) {
                var rs = actionable(a.closest('table'));
                var c = rs.filter(function (r) { return r.checked; }).length;
                a.checked = rs.length > 0 && c === rs.length;
                a.indeterminate = c > 0 && c < rs.length;
                a.disabled = rs.length === 0;
            });
        }

        // "Select all" ticks only the rows of its own program that can actually be acted on.
        alls.forEach(function (a) {
            a.addEventListener('change', function () {
                actionable(a.closest('table')).forEach(function (r) { r.checked = a.checked; });
                sync();
            });
        });
        rows.forEach(function (r) { r.addEventListener('change', sync); });

        var ex = document.getElementById('expandAll'), co = document.getElementById('collapseAll');
        if (ex) ex.addEventListener('click', function () {
            document.querySelectorAll('details.prog-group').forEach(function (d) { d.open = true; });
        });
        if (co) co.addEventListener('click', function () {
            document.querySelectorAll('details.prog-group').forEach(function (d) { d.open = false; });
        });

        // Returns false (and says why) if the action needs a message and has none.
        function ok(act, text) {
            if (act === 'info' && text.trim() === '') {
                alert('Say what you need from the applicants.');
                return false;
            }
            return true;
        }

        // n = how many applications, nl = how many of them are on a program with scholarship letters
        // (those get the committee letter right now instead of the standard status email).
        function confirmText(act, n, nl) {
            var plural = n + ' application' + (n === 1 ? '' : 's');
            var mail = nl === 0 ? 'Each applicant is emailed'
                : (nl === n ? 'Each applicant is emailed the committee letter right now (not the status email)'
                            : nl + ' of them are emailed the committee letter right now (the rest get the status email)');
            return {
                approve: 'Accept ' + plural + '? ' + mail + ', they are locked and this cannot be undone.',
                reject: 'Reject ' + plural + '? ' + mail + ', they are locked and this cannot be undone.',
                info: 'Send ' + plural + ' back for more information? Each applicant is emailed your message and a link to update their application.',
                discard: 'Discard ' + plural + '? The applicants are NOT notified. You can restore them later from the Discarded status filter.',
                resend_letter: 'Send the committee letter again to ' + plural + '?',
                remind_confirm: 'Email the confirmation link to ' + plural + '? Anyone who has already answered is skipped.'
            }[act];
        }

        bulkForm.addEventListener('submit', function (e) {
            var chosen = picked();
            if (chosen.length === 0) { e.preventDefault(); return; }
            if (!action.value) { e.preventDefault(); alert('Choose a status first.'); return; }
            if (!ok(action.value, note.value)) { e.preventDefault(); return; }
            var nl = chosen.filter(function (c) { return c.getAttribute('data-letters') === '1'; }).length;
            var warn = confirmText(action.value, chosen.length, nl);
            if (warn && !confirm(warn)) { e.preventDefault(); return; }

            ids.innerHTML = '';
            chosen.forEach(function (cb) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'ids[]'; input.value = cb.value;
                ids.appendChild(input);
            });
            var apply = document.getElementById('bulkApply');
            apply.disabled = true; apply.textContent = 'Working…';
        });

        // Per-row Apply uses the same checks. (Restore and Resend rows have no dropdown, so they submit straight away.)
        document.querySelectorAll('.rowform').forEach(function (f) {
            f.addEventListener('submit', function (e) {
                var sel = f.querySelector('select');
                if (!sel) return;
                var act = sel.value;
                var t = f.querySelector('input[type=text]');
                if (!ok(act, t ? t.value : '')) { e.preventDefault(); return; }
                var warn = confirmText(act, 1, f.getAttribute('data-letters') === '1' ? 1 : 0);
                if (warn && !confirm(warn)) { e.preventDefault(); }
            });
        });

        sync();
    })();
    </script>
<?php endif; ?>
<?php engage_footer(); ?>
