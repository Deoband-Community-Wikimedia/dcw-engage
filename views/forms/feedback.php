<?php
/**
 * DCW Engage - public feedback form.
 *
 * Included by views/forms/renderer.php when the form's schema has "kind": "feedback".
 * Runs in the renderer's scope, so it uses $form, $schema, $formType and $previewSchema from there.
 * The renderer has already dealt with closed / missing forms.
 *
 * SIGNED-IN MEMBERS skip verification entirely. Their email was already verified when they signed in,
 * so they go straight to the feedback fields and the response is stored under their member name and
 * email from the session. Everyone else (guests) goes through the flow below.
 *
 * Guest flow (email verification happens BEFORE the form opens, with a one-time code):
 *   1. gate     name + email  -> POST fb_action=request -> 8-character code emailed
 *   2. code     "enter your code" screen -> POST fb_action=verify. The email being verified is kept in the
 *               SESSION (not in a hidden field), so it cannot be swapped between the two steps.
 *               Resend (fb_action=resend) is rate-limited by EmailVerificationModel (2-minute cooldown, hourly
 *               cap); "use a different email" (fb_action=change) goes back to the gate.
 *   3. form     the feedback fields; POST fb_action=submit
 *
 * There is no link to click any more, so mail-security link scanners have nothing to burn, and the
 * old ?verify=TOKEN URLs that may still sit in inboxes are simply ignored (the visitor lands on the gate).
 * Wrong guesses are capped per code inside EmailVerificationModel::consume().
 *
 * The name and email stored with the response are the VERIFIED ones (from the member session, or from
 * the verified guest session), never values posted with the final form, so an address cannot be
 * swapped out at submit time.
 *
 * What is still different from an application:
 *   - no draft, no magic link, no tracking ID, no duplicate check, no member-ID logic
 *   - the response goes to feedback_responses, not applications
 *   - the person gets a receipt email and nothing else
 *
 * The abuse limits (FeedbackModel::MAX_PER_EMAIL_PER_HOUR / MAX_PER_FORM_PER_10_MIN) apply to members too.
 *
 * Page frame: the slim hero (left-aligned heading, quiet "Home" back link, no breadcrumb pills), the same
 * as the member dashboard. Signed-in members also get the shared member menu in the top bar.
 */
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/FeedbackModel.php';
require_once __DIR__ . '/../../models/EmailVerificationModel.php';
require_once __DIR__ . '/../../includes/mail/feedback_mailer.php';

/** How long a confirmed address may be used to submit before it has to be verified again. */
const FB_VERIFIED_TTL = 3600;

/** How long the "enter your code" screen stays available before the visitor has to start again. */
const FB_OTP_SCREEN_TTL = 1800;

$isPreview = !empty($previewSchema);
$fbFormId  = $form['id'] ?? null;
$formIdInt = (int) $fbFormId;
$fbPath    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$fbSections = isset($schemaSections) && is_array($schemaSections) ? $schemaSections : FormModel::schemaSections($schema);
$fbFields  = FormModel::schemaFields($schema);
$fbTitle   = (string) ($schema['title'] ?? 'Feedback');
$fbErrors  = [];
$fbName    = '';   // gate form values (kept when re-showing the gate with errors)
$fbEmail   = '';

// ---------------------------------------------------------------------------------------------
// Signed-in member? Their login already verified the email, so no verification step is needed.
// Guarded so a problem with the member session can never break the public form: on any failure the
// visitor is simply treated as a guest. Not used in preview mode.
// $sessionMember is kept (not just $fbMember) because the page header needs the full session row
// for the member menu and chapter theme.
// ---------------------------------------------------------------------------------------------
$sessionMember = null;
$fbMember = null;
if (!$isPreview) {
    try {
        $sessionMember = MemberSession::current();
    } catch (Throwable $e) {
        $sessionMember = null;
    }
    if (is_array($sessionMember)) {
        $mEmail = strtolower(trim((string) ($sessionMember['email'] ?? '')));
        if ($mEmail !== '' && filter_var($mEmail, FILTER_VALIDATE_EMAIL)) {
            $mName    = trim((string) ($sessionMember['full_name'] ?? ''));
            $fbMember = ['name' => $mName !== '' ? $mName : 'Member', 'email' => $mEmail];
        }
    }
}

// Code-entry state for guests: which address a code was sent to (kept server-side).
// ['email' => '', 'name' => '', 'at' => unix time, 'msg' => one-off notice]
$fbOtp = null;
if (!$isPreview && !$fbMember) {
    $o = $_SESSION['fb_otp'][$fbFormId] ?? null;
    if (is_array($o) && (int) ($o['at'] ?? 0) > time() - FB_OTP_SCREEN_TTL) {
        $fbOtp = $o;
    } elseif ($o !== null) {
        unset($_SESSION['fb_otp'][$fbFormId]);
    }
}

// Currently verified person for this form (if any, and not stale).
$fbVerified = null;
if ($fbMember) {
    $fbVerified = $fbMember;
} elseif (!$isPreview) {
    $v = $_SESSION['fb_verified'][$fbFormId] ?? null;
    if (is_array($v) && (int) ($v['at'] ?? 0) > time() - FB_VERIFIED_TTL) {
        $fbVerified = ['name' => (string) $v['name'], 'email' => (string) $v['email']];
    } elseif ($v !== null) {
        unset($_SESSION['fb_verified'][$fbFormId]);
    }
} else {
    $fbVerified = ['name' => 'Preview Person', 'email' => 'preview@example.org'];
}

// ---------------------------------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------------------------------
if (!$isPreview && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    // A signed-in member can only ever submit feedback: the verification actions do not apply to them.
    $fbAction = $fbMember ? 'submit' : (string) ($_POST['fb_action'] ?? 'submit');

    // Honeypot: real people never see this field. Pretend it worked, store nothing, send nothing.
    if (trim((string) ($_POST['hp_website'] ?? '')) !== '') {
        if ($fbAction === 'request') {
            $_SESSION['fb_otp'][$fbFormId] = ['email' => '', 'name' => '', 'at' => time()];
        } else {
            $_SESSION['fb_sent'][$fbFormId] = true;
        }
        header('Location: ' . $fbPath);
        exit;
    }

    // ---- Step 1: ask for a verification code --------------------------------------------------
    if ($fbAction === 'request') {
        $fbName  = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($_POST['respondent_name'] ?? '')));
        $fbEmail = strtolower(trim((string) ($_POST['respondent_email'] ?? '')));

        if ($fbName === '' || mb_strlen($fbName) > 100) {
            $fbErrors['respondent_name'] = 'Please enter your name (maximum 100 characters).';
        }
        if ($fbEmail === '' || strlen($fbEmail) > 254 || !filter_var($fbEmail, FILTER_VALIDATE_EMAIL)) {
            $fbErrors['respondent_email'] = 'Please enter a valid email address.';
        }

        if (!$fbErrors) {
            try {
                $issued = (new EmailVerificationModel())->request($formIdInt, $fbEmail, $fbName);

                // $issued is null when this address asked too often (cooldown or hourly cap).
                // Respond exactly the same either way.
                if ($issued) {
                    FeedbackMailer::sendVerification(
                        $fbEmail,
                        $fbName,
                        $fbTitle,
                        $issued['code'],
                        FormModel::formatIst($issued['expires_at'])
                    );
                }

                $_SESSION['fb_otp'][$fbFormId] = ['email' => $fbEmail, 'name' => $fbName, 'at' => time()];
                header('Location: ' . $fbPath);
                exit;
            } catch (Throwable $ex) {
                app_log("Feedback verification failed for form '$formType': " . $ex->getMessage());
                $fbErrors['system'] = 'We could not send the verification email. Please try again.';
            }
        }

    // ---- Step 2: check the code the person typed ----------------------------------------------
    } elseif ($fbAction === 'verify') {
        if (!$fbOtp || ($fbOtp['email'] ?? '') === '') {
            $fbErrors['system'] = 'Your verification session has expired. Please start again.';
            unset($_SESSION['fb_otp'][$fbFormId]);
            $fbOtp = null;
        } else {
            $code = is_string($_POST['otp_code'] ?? null) ? $_POST['otp_code'] : '';

            try {
                $who = (new EmailVerificationModel())->consume($formIdInt, (string) $fbOtp['email'], $code);
            } catch (Throwable $ex) {
                app_log("Feedback verification consume failed for form '$formType': " . $ex->getMessage());
                $who = null;
            }

            if ($who) {
                $_SESSION['fb_verified'][$fbFormId] = [
                    'name'  => $who['name'] !== '' ? $who['name'] : 'Participant',
                    'email' => $who['email'],
                    'at'    => time(),
                ];
                unset($_SESSION['fb_otp'][$fbFormId]);
                header('Location: ' . $fbPath);
                exit;
            }
            // Wrong, expired, used or locked: the visitor is told the same thing for all of them.
            $fbErrors['otp_code'] = 'That code is not correct, or it has expired. Check the code in your email, or request a new one below.';
        }

    // ---- Resend: a fresh code to the same address ---------------------------------------------
    } elseif ($fbAction === 'resend') {
        if (!$fbOtp || ($fbOtp['email'] ?? '') === '') {
            $fbErrors['system'] = 'Your verification session has expired. Please start again.';
            unset($_SESSION['fb_otp'][$fbFormId]);
            $fbOtp = null;
        } else {
            try {
                $evModel = new EmailVerificationModel();
                $wait = $evModel->resendWaitSeconds($formIdInt, (string) $fbOtp['email']);

                if ($wait > 0) {
                    $fbErrors['system'] = 'Please wait a moment before requesting another code.';
                } else {
                    $issued = $evModel->request($formIdInt, (string) $fbOtp['email'], (string) $fbOtp['name']);
                    if ($issued) {
                        FeedbackMailer::sendVerification(
                            (string) $fbOtp['email'],
                            (string) $fbOtp['name'],
                            $fbTitle,
                            $issued['code'],
                            FormModel::formatIst($issued['expires_at'])
                        );
                    }
                    // Same message whether or not the hourly cap let a new one through.
                    $_SESSION['fb_otp'][$fbFormId]['msg'] = 'If the address is correct, a new code is on its way.';
                    $_SESSION['fb_otp'][$fbFormId]['at']  = time();
                    header('Location: ' . $fbPath);
                    exit;
                }
            } catch (Throwable $ex) {
                app_log("Feedback verification resend failed for form '$formType': " . $ex->getMessage());
                $fbErrors['system'] = 'We could not send the verification email. Please try again.';
            }
        }

    // ---- Back to the gate to use a different address ------------------------------------------
    } elseif ($fbAction === 'change') {
        unset($_SESSION['fb_otp'][$fbFormId]);
        header('Location: ' . $fbPath);
        exit;

    // ---- Step 3: the feedback itself ----------------------------------------------------------
    } else {
        if (!$fbVerified) {
            $fbErrors['system'] = 'Please verify your email address first.';
        } else {
            [$fbAnswers, $fbAnswerErrors] = FeedbackModel::collectAnswers($schema, $_POST);
            $fbErrors = array_merge($fbErrors, $fbAnswerErrors);

            if (!$fbErrors) {
                try {
                    $fbModel = new FeedbackModel();

                    if ($fbModel->recentCountForEmail($formIdInt, $fbVerified['email'], 60) >= FeedbackModel::MAX_PER_EMAIL_PER_HOUR
                        || $fbModel->recentCountForForm($formIdInt, 10) >= FeedbackModel::MAX_PER_FORM_PER_10_MIN) {
                        $fbErrors['system'] = 'We are receiving a high volume of submissions at the moment. Please try again shortly.';
                    } else {
                        $fbModel->create($formIdInt, $fbVerified['name'], $fbVerified['email'], $fbAnswers);

                        // The response is saved; a mail problem must never turn that into an error for the person.
                        try {
                            FeedbackMailer::sendReceipt($fbVerified['email'], $fbVerified['name'], $fbTitle);
                            FeedbackMailer::sendOrganizerAlert($form['notify_emails'] ?? '', $fbTitle, $formIdInt);
                        } catch (Throwable $mailEx) {
                            app_log("Feedback mail failed for form '$formType': " . $mailEx->getMessage());
                        }

                        // One verification, one submission (guests). Post/Redirect/Get so a refresh cannot submit twice.
                        unset($_SESSION['fb_verified'][$fbFormId]);
                        $_SESSION['fb_sent'][$fbFormId] = true;
                        header('Location: ' . $fbPath);
                        exit;
                    }
                } catch (Throwable $ex) {
                    app_log("Feedback save failed for form '$formType': " . $ex->getMessage());
                    $fbErrors['system'] = 'An error occurred while saving your feedback. Please try again.';
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------------------------
// Which screen to show
// ---------------------------------------------------------------------------------------------
$fbSent = false;
if (!$isPreview && !empty($_SESSION['fb_sent'][$fbFormId])) {
    unset($_SESSION['fb_sent'][$fbFormId]);
    $fbSent = true;
}

// One-off notice on the code screen (for example after "Resend").
$fbOtpMsg = '';
if ($fbOtp && !empty($fbOtp['msg'])) {
    $fbOtpMsg = (string) $fbOtp['msg'];
    unset($_SESSION['fb_otp'][$fbFormId]['msg']);
}

// Seconds until another code may be requested, for the Resend countdown (0 = available now).
$fbResendWait = 0;
if ($fbOtp && ($fbOtp['email'] ?? '') !== '') {
    try {
        $fbResendWait = (new EmailVerificationModel())->resendWaitSeconds($formIdInt, (string) $fbOtp['email']);
    } catch (Throwable $e) {
        $fbResendWait = 0;
    }
}

if ($fbSent)                          { $fbScreen = 'sent'; }
elseif ($fbVerified)                  { $fbScreen = 'form'; }
elseif ($fbOtp !== null)              { $fbScreen = 'code'; }
else                                  { $fbScreen = 'gate'; }

// Link-only forms are kept out of search results (same as the application renderer).
if (!empty($schema['unlisted'])) {
    header('X-Robots-Tag: noindex, nofollow');
}

// Same page frame as the member dashboard: the slim hero with a left-aligned heading.
//   'back'   a quiet "← Home" link above the heading. It replaces the breadcrumb pills.
//   'crumbs' [] switches the breadcrumb trail off. Without this the form title was printed twice:
//            once as the big heading and again as the last breadcrumb.
//   'tools'  signed-in members get the shared member menu (avatar + three lines) in the top bar.
engage_header([
    'title'       => $fbTitle,
    'heading'     => $fbTitle,
    'slim'        => true,
    'back'        => ['Home', '/'],
    'crumbs'      => [],
    'member'      => $fbMember ? $sessionMember : null,
    'tools'       => $fbMember ? engage_member_menu($sessionMember) : null,
    'description' => MiniWikiText::stripToPlainText($schema['description'] ?? ''),
    'image'       => $schema['banner_image'] ?? null,
    'image_alt'   => $fbTitle,
]);
?>
<style>
    /* Feedback form only. The shared look comes from /assets/css/engage.css; these few rules
       mirror the ones the application renderer defines inline. */

    /* Long titles wrap evenly. The heading stays left-aligned (slim hero). */
    .hero h1 { text-wrap: balance; }

    /* The form card uses the full content width, so its left edge lines up with the heading and the
       top bar, like the panels on the member dashboard. To keep a narrower form, use for example:
       .fcard { max-width: 860px; margin-left: 0; } */
    .fcard { max-width: none; }

    .banner-fig { margin: -30px -30px 26px; }
    .banner-img { display: block; width: 100%; height: auto; max-height: 260px; object-fit: cover; border-radius: 16px 16px 0 0; }
    .banner-credit { padding: 6px 30px 0; font-size: 12px; color: var(--muted); text-align: right; }
    .banner-credit a { color: inherit; text-decoration: underline; text-underline-offset: 2px; }
    .form-desc { margin: 0 0 26px; padding-bottom: 24px; border-bottom: 1px solid var(--border); color: var(--ink); font-size: 15.5px; line-height: 1.7; }
    .form-desc p { margin: 0 0 12px; }
    .form-desc > :last-child { margin-bottom: 0; }
    .form-desc ol, .form-desc ul { margin: 0 0 12px; padding-left: 22px; }
    .form-desc h2, .form-desc h3, .form-desc h4 { margin: 22px 0 8px; padding: 0; border: 0; font-size: 17px; font-weight: 800; line-height: 1.3; }
    .boxed { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; margin: 0 0 24px; }
    .boxed .field:last-child { margin-bottom: 0; }
    .verified-line { margin: 0 0 16px; font-size: 14px; color: var(--muted); }
    .error-text { display: block; margin-top: 6px; font-size: 13px; color: #b91c1c; }
    .alert h3 { margin: 0 0 6px; padding: 0; border: 0; font-size: 17px; }
    .alert p { margin: 8px 0 0; font-size: 14px; }
    .opt { display: flex; align-items: flex-start; gap: 10px; margin: 0 0 8px; font-weight: 500; font-size: 15px; cursor: pointer; }
    .opt input { width: 17px; height: 17px; margin: 3px 0 0; accent-color: var(--primary); flex: none; }
    .opt-group { display: grid; gap: 2px; margin-top: 6px; }
    .field input[type=url], .field input[type=time], .field input[type=tel] { width: 100%; padding: 12px 14px; background: #fff; color: var(--ink); border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 15px; }
    .privacy-note { margin: 18px 0 0; font-size: 13px; color: var(--muted); }
    .hp-field { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }

    /* Code entry */
    .otp-input { width: 100%; max-width: 320px; padding: 14px 16px; font: inherit; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 24px; font-weight: 700; letter-spacing: 4px; text-transform: uppercase; text-align: center; background: #fff; color: var(--ink); border: 1px solid var(--border); border-radius: 10px; }
    .otp-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0; }
    .otp-actions form { margin: 0; }
    .otp-actions button { width: auto; padding: 8px 18px; font-size: 14px; }
    .otp-actions button[disabled] { opacity: .55; cursor: not-allowed; }
</style>

<div class="fcard">
    <?php if (!empty($schema['banner_image'])): ?>
        <figure class="banner-fig">
            <img class="banner-img" src="<?= htmlspecialchars(engage_resolve_image($schema['banner_image'], 1280)) ?>" alt="">
            <?= engage_commons_caption($schema['banner_image']) ?>
        </figure>
    <?php endif; ?>

    <?php if ($isPreview): ?>
        <div class="action-banner" role="status">🔍 Preview — this is how the form will appear after email verification. Submissions are disabled in this mode.</div>
    <?php endif; ?>

    <?php if (in_array($fbScreen, ['gate', 'form'], true)): ?>
        <?php if (!empty($form['deadline_at'])): ?>
            <p class="verified-line">Feedback will remain open until <strong><?= htmlspecialchars(FormModel::formatIst($form['deadline_at'])) ?></strong>.</p>
        <?php endif; ?>

        <?php if (!empty($schema['description'])): ?>
            <div class="form-desc"><?= MiniWikiText::render($schema['description']) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($fbErrors['system'])): ?>
        <div class="alert error"><strong>Notice:</strong> <?= htmlspecialchars($fbErrors['system']) ?></div>
    <?php endif; ?>

    <?php if ($fbScreen === 'sent'): ?>
        <div class="alert ok">
            <h3>Thank you for sharing your feedback!</h3>
            <p>We have emailed you a receipt. No further action is required on your part.</p>
        </div>

    <?php elseif ($fbScreen === 'code'): ?>
        <?php if ($fbOtpMsg !== ''): ?>
            <div class="alert ok"><?= htmlspecialchars($fbOtpMsg) ?></div>
        <?php endif; ?>

        <div class="boxed">
            <p style="margin:0 0 14px;">
                <?php if (($fbOtp['email'] ?? '') !== ''): ?>
                    If <strong><?= htmlspecialchars((string) $fbOtp['email']) ?></strong> is correct, we have sent a verification code to it.
                <?php else: ?>
                    We have sent a verification code to the address you entered.
                <?php endif; ?>
                Enter it below to open the feedback form. The code works once and expires shortly.
            </p>
            <form method="POST" action="<?= htmlspecialchars($fbPath) ?>">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="fb_action" value="verify">
                <div class="field">
                    <label for="otp_code">Verification code <span class="req-star">*</span></label>
                    <input type="text" class="otp-input" name="otp_code" id="otp_code" maxlength="12" required autofocus
                        autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" placeholder="ABCD-EFGH">
                    <?php if (!empty($fbErrors['otp_code'])): ?>
                        <span class="error-text"><?= htmlspecialchars($fbErrors['otp_code']) ?></span>
                    <?php endif; ?>
                </div>
                <button type="submit">Verify and continue</button>
            </form>
        </div>

        <p style="margin:0 0 10px; font-size:14px; color:var(--muted);">Nothing arrived? Check your spam folder, or request a new code.</p>
        <div class="otp-actions">
            <form method="POST" action="<?= htmlspecialchars($fbPath) ?>">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="fb_action" value="resend">
                <button type="submit" class="btn-ghost" id="resend-btn" data-wait="<?= (int) $fbResendWait ?>"<?= $fbResendWait > 0 ? ' disabled' : '' ?>>
                    Resend code<span id="resend-count"></span></button>
            </form>
            <form method="POST" action="<?= htmlspecialchars($fbPath) ?>">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="fb_action" value="change">
                <button type="submit" class="btn-ghost">Use a different email</button>
            </form>
        </div>
        <script>
            // Convenience only: the server (EmailVerificationModel::request) enforces the cooldown.
            (function () {
                var btn = document.getElementById('resend-btn');
                var out = document.getElementById('resend-count');
                if (!btn) return;
                var left = parseInt(btn.getAttribute('data-wait'), 10) || 0;
                function fmt(s) { return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }
                function tick() {
                    if (left > 0) {
                        out.textContent = ' (' + fmt(left) + ')';
                        left--;
                        setTimeout(tick, 1000);
                    } else {
                        out.textContent = '';
                        btn.disabled = false;
                    }
                }
                tick();
            })();
        </script>

    <?php elseif ($fbScreen === 'gate'): ?>
        <form method="POST" action="<?= htmlspecialchars($fbPath) ?>">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="fb_action" value="request">

            <div class="hp-field" aria-hidden="true">
                <label>Please leave this field blank <input type="text" name="hp_website" tabindex="-1" autocomplete="off"></label>
            </div>

            <div class="boxed">
                <div class="field">
                    <label for="respondent_name">Your Name <span class="req-star">*</span></label>
                    <input type="text" name="respondent_name" id="respondent_name" maxlength="100" autocomplete="name"
                        value="<?= htmlspecialchars($fbName) ?>" required>
                    <?php if (!empty($fbErrors['respondent_name'])): ?>
                        <span class="error-text"><?= htmlspecialchars($fbErrors['respondent_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="field">
                    <label for="respondent_email">Email Address <span class="req-star">*</span></label>
                    <input type="email" name="respondent_email" id="respondent_email" maxlength="254" autocomplete="email"
                        value="<?= htmlspecialchars($fbEmail) ?>" required>
                    <span class="hint">We will send a verification code to this address. The feedback form opens once you enter it.</span>
                    <?php if (!empty($fbErrors['respondent_email'])): ?>
                        <span class="error-text"><?= htmlspecialchars($fbErrors['respondent_email']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit">Send Verification Code</button>

            <p class="privacy-note">
                Your name and email address are used to verify you, to send you a receipt and, if necessary, to follow up with you.
                They are purged automatically after <?= (int) FeedbackModel::retentionMonths() ?> months.
            </p>
        </form>

    <?php else: /* form */ ?>
        <form method="POST" action="<?= htmlspecialchars($fbPath) ?>">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="fb_action" value="submit">

            <div class="hp-field" aria-hidden="true">
                <label>Please leave this field blank <input type="text" name="hp_website" tabindex="-1" autocomplete="off"></label>
            </div>

            <p class="verified-line">
                <?= $fbMember ? 'Signed in as' : 'Verified as' ?> <strong><?= htmlspecialchars($fbVerified['name']) ?></strong>
                (<?= htmlspecialchars($fbVerified['email']) ?>). A receipt will be sent to this address.
            </p>

            <?php foreach ($fbSections as $section): ?>
                <?php if (trim($section['title']) !== '' || trim($section['description']) !== ''): ?>
                    <section class="form-section">
                        <?php if (trim($section['title']) !== ''): ?><h2><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?></h2><?php endif; ?>
                        <?php if (trim($section['description']) !== ''): ?><div class="form-section-intro"><?= MiniWikiText::render($section['description']) ?></div><?php endif; ?>
                    </section>
                <?php endif; ?>
                <?php foreach ($section['fields'] as $field):
                $name = (string) ($field['name'] ?? '');
                if ($name === '') continue;
                $type = (string) ($field['type'] ?? 'text');
                if ($type === 'file') continue;   // not supported on feedback forms

                $label     = (string) ($field['label'] ?? $name);
                $required  = !empty($field['required']) ? 'required' : '';
                $star      = $required ? ' <span class="req-star">*</span>' : '';
                $safeName  = htmlspecialchars($name);
                $rawValue  = is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
                $value     = htmlspecialchars($rawValue);
                $fieldErr  = $fbErrors[$name] ?? null;
                $labelHtml = MiniWikiText::inline(htmlspecialchars($label, ENT_QUOTES, 'UTF-8'));
                $inputTypes = ['text', 'email', 'number', 'date', 'time', 'url', 'tel'];
                ?>
                <div class="field">
                    <?php if ($type === 'checkbox'): ?>
                        <label for="<?= $safeName ?>" class="opt">
                            <input type="checkbox" name="<?= $safeName ?>" id="<?= $safeName ?>" value="Yes"
                                <?= !empty($_POST[$name]) ? 'checked' : '' ?> <?= $required ?>>
                            <span><?= $labelHtml ?><?= $star ?></span>
                        </label>

                    <?php elseif ($type === 'checkbox_group'):
                        $picked = is_array($_POST[$name] ?? null) ? $_POST[$name] : [];
                        ?>
                        <label><?= $labelHtml ?><?= $star ?></label>
                        <div class="opt-group">
                            <?php foreach (($field['options'] ?? []) as $opt): ?>
                                <label class="opt">
                                    <input type="checkbox" name="<?= $safeName ?>[]" value="<?= htmlspecialchars($opt) ?>"
                                        <?= in_array($opt, $picked, true) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($opt) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>
                        <label for="<?= $safeName ?>"><?= $labelHtml ?><?= $star ?></label>

                        <?php if ($type === 'select'): ?>
                            <select name="<?= $safeName ?>" id="<?= $safeName ?>" <?= $required ?>>
                                <option value="">-- Please select an option --</option>
                                <?php foreach (($field['options'] ?? []) as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $rawValue === $opt ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($opt) ?></option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ($type === 'textarea'): ?>
                            <textarea name="<?= $safeName ?>" id="<?= $safeName ?>" rows="5"
                                maxlength="<?= FeedbackModel::MAX_LONG ?>" <?= $required ?>><?= $value ?></textarea>

                        <?php else: ?>
                            <input type="<?= in_array($type, $inputTypes, true) ? $type : 'text' ?>"
                                name="<?= $safeName ?>" id="<?= $safeName ?>" value="<?= $value ?>"
                                maxlength="<?= FeedbackModel::MAX_TEXT ?>" <?= $required ?>>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($fieldErr): ?>
                        <span class="error-text"><?= htmlspecialchars($fieldErr) ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <button type="submit" <?= $isPreview ? 'disabled title="Disabled in preview mode"' : '' ?>>Submit Feedback</button>

            <p class="privacy-note">
                Your name and email address are used to send you a receipt and, if necessary, to follow up with you.
                They are purged automatically after <?= (int) FeedbackModel::retentionMonths() ?> months.
            </p>
        </form>
    <?php endif; ?>
</div>
<?php engage_footer(); ?>
