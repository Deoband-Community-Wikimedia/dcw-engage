<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/FormModel.php';

// $formType should be passed from the router in index.php
global $formType;
if (!$formType)
    $formType = $_GET['type'] ?? '';

/**
 * Delete files that were moved into /uploads during a submission that then
 * failed, so a rejected or errored submission never leaves an orphan behind.
 * Paths are the 'uploads/...' strings returned by FileUploader.
 */
function cleanupUploads(array $paths)
{
    foreach ($paths as $p) {
        if (is_string($p) && strpos($p, 'uploads/') === 0) {
            $full = __DIR__ . '/../../' . $p;
            if (is_file($full)) {
                @unlink($full);
            }
        }
    }
}


// An admin previewing an in-progress, unsaved form schema (see #47) sets
// this global before including this file — views/admin/preview_form.php.
// When set, the DB lookup below is skipped entirely, and — combined with
// the guard added to the POST branch further down — a preview can never
// touch the database or send email no matter what gets POSTed here.
global $previewSchema;

if (!empty($previewSchema)) {
    $form = ['id' => null, 'schema' => $previewSchema];
} else {
    $formModel = new FormModel();
    $form = $formModel->getFormByType($formType);

    if (!$form) {
        // Tell "closed" apart from "never existed" so a form that was live and is
        // now closed shows a proper message instead of a bare 404.
        $inactiveForm = $formModel->getAnyFormByType($formType);

        if ($inactiveForm) {
            http_response_code(403);
            $closedTitle = $inactiveForm['schema']['title'] ?? 'This form';
            require __DIR__ . '/closed.php';
        } else {
            http_response_code(404);
            require __DIR__ . '/not_found.php';
        }
        die();
    }
}

$schema = $form['schema'];
$errors = [];
$success = '';

// A signed-in member (Member ID + password) is already verified: their email was proved when
// they were approved, and the session proves it is them. So on EVERY form they skip the
// email gate and use the email on their member record. Never skipped for a Member ID that was
// merely typed in: only a real member session counts.
$loggedMember = null;
if (empty($previewSchema)) {
    try {
        require_once __DIR__ . '/../../includes/member_session.php';
        $loggedMember = MemberSession::current();
    } catch (Throwable $ex) {
        $loggedMember = null;
    }
}
$isMembershipForm = str_starts_with((string) $formType, 'membership-');
$isRenewalForm = str_starts_with((string) $formType, 'membership-renewal');
$memberAutoVerified = $loggedMember !== null;

// Email verification comes first (#67). Nobody — whether they mean to submit
// or only save a draft — reaches the form until they have proved they control
// the address, so junk entries can't create rows or trigger magic links to
// addresses that aren't theirs. The proof lives in the session, per form,
// and is redeemed from the emailed link (?verify=<token>).
//
// IMPORTANT: redeeming the token must never happen on a plain GET. Mail
// security scanners (Outlook Safe Links, Proofpoint URL Defense, Mimecast,
// Gmail's link proxy) automatically issue a GET to every link in an email
// before a human opens the message, to check it isn't malicious. If GET
// consumed the one-time token, that automated prefetch would burn it first
// and the real applicant — who is only ever a GET request behind, arriving
// seconds to minutes later — would always land back on this gate with
// "expired or already used." So GET only *stages* the token; only an
// explicit POST (which scanners never send) actually redeems it.
$verifiedEmail = '';
$verifySent = false;
$pendingVerifyToken = null;

if (empty($previewSchema)) {
    // Checked in this order deliberately: the confirm-step form below posts
    // back to this same URL without stripping the query string, so
    // $_GET['verify'] is still set on that POST too. If the isset($_GET[...])
    // check ran first, it would win on every request — GET or POST — and the
    // branch that actually calls consume() would never run, leaving the
    // "Continue to application" button stuck re-rendering the same
    // confirmation screen forever. Checking for the confirm POST first
    // avoids that regardless of what's left in the query string.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_verification') {
        if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
            die("Invalid CSRF token.");
        }

        require_once __DIR__ . '/../../models/EmailVerificationModel.php';
        $verifiedFor = (new EmailVerificationModel())->consume($form['id'], (string) ($_POST['verify_token'] ?? ''));

        if ($verifiedFor) {
            $_SESSION['verified_emails'][$form['id']] = $verifiedFor;
            // Drop the token from the URL so it can't be bookmarked or shared.
            header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
            exit;
        }

        // One message for every failure — unknown, expired, used, wrong form.
        $errors['verify'] = "That verification link has expired or was already used. Enter your email below to get a new one.";
    } elseif (isset($_GET['verify'])) {
        // Side-effect-free: just carry the token forward to a confirmation
        // step. Nothing is written to the database here.
        $pendingVerifyToken = (string) $_GET['verify'];
    }

    $verifiedEmail = $memberAutoVerified
        ? (string) $loggedMember['email']
        : ($_SESSION['verified_emails'][$form['id']] ?? '');
}

if (empty($previewSchema) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'confirm_verification') {
    if (!CSRF::validate($_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }

    $email = trim($_POST['email'] ?? '');
    $postAction = $_POST['action'] ?? '';
    $gatePassed = false;

    if ($memberAutoVerified && in_array($postAction, ['request_verification', 'change_email'], true)) {
        // A signed-in member has no email gate and cannot swap in another address.
        $email = $verifiedEmail;
    } elseif ($postAction === 'request_verification') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Please enter a valid email address.";
        } else {
            try {
                require_once __DIR__ . '/../../models/EmailVerificationModel.php';
                require_once __DIR__ . '/../../includes/mailer.php';
                $config = require __DIR__ . '/../../includes/config.php';
                $email = strtolower($email);
                $issued = (new EmailVerificationModel())->request($form['id'], $email);

                if ($issued) {
                    $verifyUrl = rtrim($config['app']['url'], '/') . '/' . rawurlencode($formType)
                        . '?verify=' . urlencode($issued['token']);
                    Mailer::sendEmailVerification($email, $schema['title'] ?? $formType, $verifyUrl, $issued['expires_at']);
                }
                // Same screen whether or not a link went out (rate limited,
                // mail failure): the page must not reveal which happened.
                $verifySent = true;
            } catch (Exception $e) {
                app_log("Email verification request failed for form '$formType' <$email>: " . $e->getMessage());
                $errors['system'] = "Something went wrong sending your verification email. Please try again.";
            }
        }
    } elseif ($postAction === 'change_email') {
        unset($_SESSION['verified_emails'][$form['id']]);
        $verifiedEmail = '';
    } elseif ($verifiedEmail === '') {
        $errors['system'] = "Please verify your email address first.";
    } else {
        // Past this point the address the applicant typed is irrelevant. The
        // verified one is what gets saved and mailed, so editing the request
        // cannot swap in an address that was never proved.
        $email = $verifiedEmail;
        $gatePassed = true;
    }

    if (!$gatePassed) {
        // Handled above; nothing further to do for this request.
    } elseif ($postAction === 'resend_magic_link') {
        if (empty($email)) {
            $errors['email'] = "Email Address is required to resend the link.";
        } else {
            require_once __DIR__ . '/../../models/ApplicationModel.php';
            $appModel = new ApplicationModel();
            $existing = $appModel->getApplicationByEmail($form['id'], $email);
            if ($existing) {
                $token = $appModel->generateMagicLink($existing['id'], false);
                require_once __DIR__ . '/../../includes/mailer.php';
                Mailer::sendMagicLink($email, $existing['applicant_name'] ?? 'Applicant', $token);
                $success = "We have resent the magic link to $email. Please check your inbox.";
            } else {
                $errors['email'] = "No existing application found with that email address.";
            }
        }
    } else {
        // Two distinct actions live behind the same "Submit Application"
        // form: saving an incomplete draft (gets a magic link to come back
        // to) vs a final submission (gets a plain received confirmation).
        // Default to 'submit' so a stray/legacy POST without the field never
        // silently becomes a draft.
        $intent = ($_POST['intent'] ?? 'submit') === 'draft' ? 'draft' : 'submit';
        $isDraft = $intent === 'draft';

        // A draft is allowed to be incomplete by definition, so required
        // fields aren't enforced for it.
        $errors = $formModel->validateSubmission($schema, $_POST, $isDraft);

        if (empty($email)) {
            $errors['email'] = "Email Address is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Please enter a valid email address.";
        }

        require_once __DIR__ . '/../../models/ApplicationModel.php';
        $appModel = new ApplicationModel();

        $postData = $_POST;
        unset($postData['csrf_token']);

        // Membership forms (not the renewal form, which asks for the ID itself): someone who
        // already holds a membership in another chapter keeps ONE Member ID. The ID is set here,
        // on the server, never taken from the posted form: from the signed-in member's session,
        // or from what a guest typed on /membership (checked against their verified email below).
        require_once __DIR__ . '/../../models/MemberModel.php';
        if ($isMembershipForm && !$isRenewalForm) {
            unset($postData[MemberModel::JOIN_ID_FIELD]);
            $joinId = $loggedMember !== null
                ? (string) $loggedMember['member_id']
                : strtoupper(trim((string) ($_SESSION['join_member_id'][$formType] ?? '')));
            if ($joinId !== '') {
                $postData[MemberModel::JOIN_ID_FIELD] = $joinId;
            }
        }

        // Resolve the applicant name from the actual submitted field name,
        // not a hardcoded full_name assumption, so both full_name and
        // applicant_name labels keep working across builder-generated schemas.
        $applicantName = resolveApplicantName($postData, $schema);

        // Membership forms: check the Member ID now (renewal form: the one typed on the form;
        // other forms: the one set above), so a typo or someone else's ID is shown here instead
        // of surfacing only after a reviewer opens the application. A final submission only;
        // a draft may be incomplete. No ID is allowed (the person is then a new applicant),
        // a wrong one is not. The error is the same whether the ID does not exist or belongs
        // to someone else. The email checked is the verified one.
        if (empty($errors) && !$isDraft && $isMembershipForm) {
            try {
                (new MemberModel())->verifyRenewalMember([
                    'form_type' => $formType,
                    'form_data' => json_encode($postData),
                    'email'     => $email,
                ]);
            } catch (InvalidArgumentException $e) {
                $idFieldShown = $isRenewalForm
                    && in_array(MemberModel::RENEWAL_ID_FIELD, array_column($schema['fields'] ?? [], 'name'), true);
                if ($idFieldShown) {
                    $errors[MemberModel::RENEWAL_ID_FIELD] = $e->getMessage();
                } else {
                    $errors['system'] = $e->getMessage();   // no ID field on this form (or it was renamed): still say why
                }
            }
        }

        // Reject duplicates BEFORE touching any files. Uploading first and
        // checking second leaves an orphaned file on disk with no application
        // row pointing at it, which the PII scrubber can never reach.
        if (empty($errors)) {
            $existing = $appModel->getApplicationByEmail($form['id'], $email);
            if ($existing) {
                $errors['email'] = "You have already applied for this program. Check your email for a magic link to edit your application.";
                $errors['show_resend'] = true;
            }
        }

        // Only now, on an otherwise valid and non-duplicate submission, move
        // the uploaded files into place. Track what we wrote so we can undo it
        // if the save below fails for any reason.
        $uploadedPaths = [];
        if (empty($errors)) {
            require_once __DIR__ . '/../../models/FileUploader.php';
            $fileUploader = new FileUploader();

            foreach ($schema['fields'] as $field) {
                $name = $field['name'];
                if (($field['type'] ?? '') === 'file' && !empty($_FILES[$name]['name'])) {
                    try {
                        $path = $fileUploader->handleUpload($_FILES[$name], $name, $applicantName, $formType);
                        if ($path) {
                            $postData[$name] = $path;
                            $uploadedPaths[] = $path;
                        }
                    } catch (Exception $e) {
                        $errors[$name] = $e->getMessage();
                    }
                }
            }

            // A file failed validation after others already landed — remove
            // the ones that succeeded so nothing is left orphaned.
            if (!empty($errors)) {
                cleanupUploads($uploadedPaths);
            }
        }

        if (empty($errors)) {
            try {
                $status = $isDraft ? 'Draft' : 'New';
                $appId = $appModel->saveApplication($form['id'], $email, $applicantName, $status, json_encode($postData));
                $trackingId = $appModel->getTrackingId($appId);

                require_once __DIR__ . '/../../includes/mailer.php';

                if ($isDraft) {
                    // Drafts get the resume-by-email magic link, with the
                    // longer draft expiry window.
                    $token = $appModel->generateMagicLink($appId, true);
                    Mailer::sendMagicLink($email, $applicantName, $token);
                    $success = "Draft saved! Your tracking ID is: $trackingId";
                } else {
                    // A real submission gets a plain confirmation instead —
                    // no edit token. Returning applicants who need to make a
                    // correction can still use "Resend Magic Link" above.
                    $formTitle = $schema['title'] ?? $formType;
                    Mailer::sendApplicationReceived($email, $applicantName, $trackingId, $formTitle);
                    $success = "Application submitted successfully! Your tracking ID is: $trackingId";
                    // The ID typed on /membership has done its job (it travels with the saved application now).
                    unset($_SESSION['join_member_id'][$formType]);
                }

                // Notify the organizer(s) in charge of this form — only for
                // a real submission, not every incomplete draft save.
                // The address is spent once it has been saved against an
                // application; a second one needs a fresh verification.
                unset($_SESSION['verified_emails'][$form['id']]);

                if (!$isDraft) {
                    // $form comes straight from FormModel::getFormByType(),
                    // which never sets a 'title' key (only 'schema'), so
                    // Mailer::sendOrganizerAlert()'s own fallback would land
                    // on $form['form_type'] — the URL slug — instead of the
                    // real title. Sync it with what the applicant email
                    // above already resolved.
                    $form['title'] = $formTitle;
                    Mailer::sendOrganizerAlert($form, $email, $applicantName, $trackingId);
                }
            } catch (Exception $e) {
                // The save failed (including the UNIQUE(form_id, email) guard
                // catching a duplicate that slipped past the check above).
                // Delete any files we moved so they are not left orphaned.
                //
                // This used to be swallowed silently — the applicant saw a
                // generic message and the real reason was never written
                // anywhere, so a live incident (2026-09-16) had no trail to
                // diagnose from. Log the real exception; the applicant still
                // only ever sees the generic message.
                app_log("Application save failed for form '$formType' <$email>: " . $e->getMessage());
                cleanupUploads($uploadedPaths);
                $errors['system'] = "An error occurred saving your application.";
            }
        }
    }
}

// Until the address is verified, only the verification step (or,
// if a token just arrived via GET, the confirmation step) is
// shown. A preview has no session/DB, so it always shows the form.
$showConfirm = empty($success) && $pendingVerifyToken !== null && $verifiedEmail === '';
$showGate = empty($success) && empty($previewSchema) && $verifiedEmail === '' && !$showConfirm;
$isDraftPost = ($_POST['intent'] ?? '') === 'draft';

// A signed-in member just gets their name in the top bar (same as /membership).
$member = $loggedMember;

engage_header([
    'title'   => $schema['title'],
    'heading' => $schema['title'],
    'kicker'  => 'Application',
    'lead'    => '',
    'member'  => $member,
    'crumbs'  => [['Home', '/'], [$schema['title']]],
]);
?>
<style>
    /* Application form only. Everything else comes from /assets/css/engage.css */

    /* Long titles wrap evenly instead of running as one very wide line */
    .hero h1 { max-width: 880px; margin-left: auto; margin-right: auto; text-wrap: balance; }

    /* Banner is the card's header image: flush with the card edges (card padding is 30px) */
    .banner-img { display: block; width: calc(100% + 60px); max-width: none; height: auto; max-height: 260px; object-fit: cover; margin: -30px -30px 26px; border-radius: 16px 16px 0 0; }

    /* Program description: readable body text, divided from the form below */
    .form-desc { margin: 0 0 26px; padding-bottom: 24px; border-bottom: 1px solid var(--border); color: var(--ink); font-size: 15.5px; line-height: 1.7; }
    .form-desc p { margin: 0 0 12px; }
    .form-desc > :last-child { margin-bottom: 0; }
    .form-desc ol, .form-desc ul { margin: 0 0 12px; padding-left: 22px; }
    .form-desc li { margin: 0 0 6px; }
    .form-desc h2, .form-desc h3, .form-desc h4 { margin: 22px 0 8px; padding: 0; border: 0; font-size: 17px; font-weight: 800; line-height: 1.3; }

    .boxed { background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 18px 20px; margin: 0 0 24px; }
    .boxed .field { margin-bottom: 0; }
    .verified-line { margin: 0 0 16px; font-size: 14px; color: var(--muted); }
    .fcard .linkbtn { background: none; border: none; box-shadow: none; color: var(--primary); padding: 0 0 0 6px; width: auto; font-size: 14px; font-weight: 600; text-decoration: underline; cursor: pointer; }
    .fcard .linkbtn:hover:not(:disabled) { transform: none; box-shadow: none; }
    .fcard .btn-outline { background: #fff; color: var(--primary); border: 1px solid var(--primary); box-shadow: none; }
    .fcard .btn-outline:hover:not(:disabled) { transform: none; box-shadow: none; background: #f1f7fb; }
    .fcard .btn-small { width: auto; padding: 8px 16px; font-size: 14px; }
    .btnrow { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px; }
    .fcard .btnrow button { width: auto; flex: 1 1 180px; }
    .error-text { display: block; margin-top: 6px; font-size: 13px; color: #b91c1c; }
    .alert h3 { margin: 0 0 6px; padding: 0; border: 0; font-size: 17px; }
    .alert p { margin: 8px 0 0; font-size: 14px; }

    /* Input types engage.css does not style yet */
    .field input[type=url], .field input[type=time] { width: 100%; padding: 12px 14px; background: #fff; color: var(--ink); border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 15px; }
    .field input[type=url]:focus, .field input[type=time]:focus { outline: 2px solid var(--primary); outline-offset: -1px; border-color: transparent; }

    .opt { display: flex; align-items: flex-start; gap: 10px; margin: 0 0 8px; font-weight: 500; font-size: 15px; cursor: pointer; }
    .opt input { width: 17px; height: 17px; margin: 3px 0 0; accent-color: var(--primary); flex: none; }
    .opt-group { display: grid; gap: 2px; margin-top: 6px; }

    .dropzone { position: relative; border: 2px dashed var(--border); border-radius: 12px; background: #f8fafc; text-align: center; padding: 22px 16px; transition: border-color .15s, background .15s; }
    .dropzone-dragover { border-color: var(--primary); background: #f1f7fb; }
    .dropzone-has-error { border-color: #f87171; }
    .dropzone-input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
    .dropzone-icon { width: 34px; height: 34px; color: var(--primary); }
    .dropzone-text { margin: 8px 0 2px; font-size: 14px; }
    .dropzone-browse { color: var(--primary); font-weight: 600; text-decoration: underline; }
    .dropzone-hint { margin: 0; font-size: 12.5px; color: var(--muted); }
    .dropzone-preview { align-items: center; gap: 12px; text-align: left; position: relative; z-index: 1; }
    .dropzone-file-icon { width: 30px; height: 30px; color: var(--primary); flex: none; }
    .dropzone-file-info { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .dropzone-filename { font-weight: 600; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dropzone-filesize { font-size: 12.5px; color: var(--muted); }
    .fcard .dropzone-remove { width: 32px; height: 32px; padding: 0; border-radius: 50%; background: #fff; color: #991b1b; border: 1px solid #f87171; box-shadow: none; font-size: 18px; line-height: 1; cursor: pointer; position: relative; z-index: 2; }
    .fcard .dropzone-remove:hover:not(:disabled) { transform: none; box-shadow: none; background: #fef2f2; }
</style>

<div class="fcard">
    <?php if (!empty($schema['banner_image'])): ?>
        <img class="banner-img" src="<?= htmlspecialchars($schema['banner_image']) ?>" alt="">
    <?php endif; ?>

    <?php if (!empty($previewSchema)): ?>
        <div class="action-banner" role="status">🔍 Preview — this is how the form will look. Submissions are disabled here.</div>
    <?php endif; ?>

    <?php if (!empty($schema['description'])): ?>
        <div class="form-desc"><?= MiniWikiText::render($schema['description']) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert ok">
            <h3><?= $isDraftPost ? 'Draft saved!' : 'Application received!' ?></h3>
            <?= htmlspecialchars($success) ?>
            <p><?= $isDraftPost
                ? 'We have emailed you a secure link to come back and finish this application anytime.'
                : 'We have emailed you a confirmation. No further action is needed right now.' ?></p>
        </div>
    <?php else: ?>

        <?php if (!empty($errors['system']) || !empty($errors['email']) || !empty($errors['verify'])): ?>
            <div class="alert error">
                <strong>Notice:</strong> <?= htmlspecialchars($errors['system'] ?? $errors['email'] ?? $errors['verify']) ?>
                <?php if (!empty($errors['show_resend'])): ?>
                    <form method="POST" style="margin:14px 0 0;">
                        <?= CSRF::getInputField() ?>
                        <input type="hidden" name="action" value="resend_magic_link">
                        <input type="hidden" name="email" value="<?= htmlspecialchars($verifiedEmail) ?>">
                        <button type="submit" class="btn-outline btn-small">Resend magic link</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($showConfirm): ?>
            <div class="alert ok">
                <h3>Confirm your email</h3>
                Click below to finish verifying and open the application. This extra click keeps automated
                email-safety scanners from using up your link before you get to it.
            </div>
            <form method="POST" action="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="confirm_verification">
                <input type="hidden" name="verify_token" value="<?= htmlspecialchars($pendingVerifyToken) ?>">
                <button type="submit">Continue to application</button>
            </form>

        <?php elseif ($showGate): ?>
            <?php if ($verifySent): ?>
                <div class="alert ok">
                    <h3>Check your inbox</h3>
                    If <strong><?= htmlspecialchars($email) ?></strong> can receive email, a verification link is on its
                    way. Open it in this browser to start your application. The link works once and expires soon.
                    <p>Nothing yet? Check your spam folder, or request a new link below (up to 3 per hour).</p>
                </div>
            <?php endif; ?>

            <form method="POST">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="request_verification">
                <div class="field">
                    <label for="verify_email">Verify your email to begin <span class="req-star">*</span></label>
                    <input type="email" name="email" id="verify_email" value="<?= htmlspecialchars($email ?? '') ?>" required>
                    <span class="hint">We will email you a one-time link. Once you open it, the application form unlocks,
                        whether you want to submit now or save a draft and finish later.</span>
                </div>
                <button type="submit"><?= $verifySent ? 'Send a new link' : 'Send verification link' ?></button>
            </form>

        <?php else: ?>

            <?php if ($memberAutoVerified): ?>
                <p class="verified-line">Signed in as <strong><?= htmlspecialchars($verifiedEmail) ?></strong> ✓
                    No email verification needed.</p>
            <?php elseif ($verifiedEmail !== ''): ?>
                <form method="POST" class="verified-line">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="change_email">
                    Verified as <strong><?= htmlspecialchars($verifiedEmail) ?></strong> ✓
                    <button type="submit" formnovalidate class="linkbtn">Use a different email</button>
                </form>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <?= CSRF::getInputField() ?>

                <div class="boxed">
                    <div class="field">
                        <label for="applicant_email">Email Address <span class="req-star">*</span></label>
                        <input type="email" name="email" id="applicant_email" value="<?= htmlspecialchars($verifiedEmail) ?>"
                            <?= $verifiedEmail !== '' ? 'readonly' : '' ?> required>
                        <span class="hint">We will send your secure Magic Link here to save your progress.</span>
                    </div>
                </div>

                <?php foreach ($schema['fields'] as $field):
                    $name = $field['name'];
                    $label = $field['label'] ?? $name;
                    $type = $field['type'] ?? 'text';
                    $required = !empty($field['required']) ? 'required' : '';
                    // Raw posted value (for comparisons) and its escaped twin (for printing).
                    $rawValue = is_array($_POST[$name] ?? null) ? '' : (string) ($_POST[$name] ?? '');
                    $value = htmlspecialchars($rawValue);
                    $fieldError = $errors[$name] ?? null;
                    $star = $required ? ' <span class="req-star">*</span>' : '';
                    $safeName = htmlspecialchars($name);
                    ?>
                    <div class="field">
                        <?php if ($type === 'checkbox'): ?>
                            <label for="<?= $safeName ?>" class="opt">
                                <input type="checkbox" name="<?= $safeName ?>" id="<?= $safeName ?>" value="Yes"
                                    <?= !empty($_POST[$name]) ? 'checked' : '' ?> <?= $required ?>>
                                <span><?= MiniWikiText::inline(htmlspecialchars($label, ENT_QUOTES, 'UTF-8')) ?><?= $star ?></span>
                            </label>

                        <?php elseif ($type === 'checkbox_group'):
                            $selectedValues = $_POST[$name] ?? [];
                            if (!is_array($selectedValues))
                                $selectedValues = [];
                            ?>
                            <label><?= MiniWikiText::inline(htmlspecialchars($label, ENT_QUOTES, 'UTF-8')) ?><?= $star ?></label>
                            <div class="opt-group">
                                <?php foreach ($field['options'] ?? [] as $opt): ?>
                                    <label class="opt">
                                        <input type="checkbox" name="<?= $safeName ?>[]"
                                            value="<?= htmlspecialchars($opt) ?>" <?= in_array($opt, $selectedValues) ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($opt) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                        <?php else: ?>
                            <label for="<?= $safeName ?>"><?= MiniWikiText::inline(htmlspecialchars($label, ENT_QUOTES, 'UTF-8')) ?><?= $star ?></label>

                            <?php if ($type === 'select'): ?>
                                <select name="<?= $safeName ?>" id="<?= $safeName ?>" <?= $required ?>>
                                    <option value="">-- Select --</option>
                                    <?php foreach ($field['options'] ?? [] as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $rawValue === $opt ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($type === 'textarea'): ?>
                                <textarea name="<?= $safeName ?>" id="<?= $safeName ?>" rows="4" <?= $required ?>><?= $value ?></textarea>

                            <?php elseif ($type === 'file'):
                                $fieldId = 'file_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
                                ?>
                                <div class="dropzone <?= $fieldError ? 'dropzone-has-error' : '' ?>" id="dropzone_<?= $fieldId ?>">
                                    <input type="file" name="<?= $safeName ?>" id="<?= $fieldId ?>" class="dropzone-input"
                                        accept=".pdf,.jpg,.jpeg,.png,.docx,.doc" <?= $required ?>>

                                    <div class="dropzone-content" id="<?= $fieldId ?>_content">
                                        <svg class="dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <path d="M12 16V4M12 4L7 9M12 4l5 5" stroke-linecap="round" stroke-linejoin="round" />
                                            <path d="M4 16v3a2 2 0 002 2h12a2 2 0 002-2v-3" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                        <p class="dropzone-text">Drag &amp; drop your file here, or <span class="dropzone-browse">click to browse</span></p>
                                        <p class="dropzone-hint">PDF, JPG, PNG, DOC, DOCX — up to 10MB</p>
                                    </div>

                                    <div class="dropzone-preview" id="<?= $fieldId ?>_preview" style="display:none;">
                                        <svg class="dropzone-file-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" stroke-linejoin="round" />
                                            <path d="M14 2v6h6" stroke-linejoin="round" />
                                        </svg>
                                        <div class="dropzone-file-info">
                                            <span class="dropzone-filename"></span>
                                            <span class="dropzone-filesize"></span>
                                        </div>
                                        <button type="button" class="dropzone-remove" aria-label="Remove file"
                                            onclick="removeDropzoneFile('<?= $fieldId ?>')">&times;</button>
                                    </div>
                                </div>

                            <?php else: ?>
                                <input type="<?= htmlspecialchars($type) ?>" name="<?= $safeName ?>" id="<?= $safeName ?>"
                                    value="<?= $value ?>" <?= $required ?>>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($fieldError): ?>
                            <span class="error-text"><?= htmlspecialchars($fieldError) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="btnrow">
                    <button type="submit" name="intent" value="draft" formnovalidate class="btn-outline"
                        <?= !empty($previewSchema) ? 'disabled title="Disabled in preview"' : '' ?>>Save as Draft</button>
                    <button type="submit" name="intent" value="submit"
                        <?= !empty($previewSchema) ? 'disabled title="Disabled in preview"' : '' ?>>Submit Application</button>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    document.querySelectorAll('.dropzone-input').forEach(input => {
        const fieldId = input.id;
        const dropzone = document.getElementById('dropzone_' + fieldId);
        const content = document.getElementById(fieldId + '_content');
        const preview = document.getElementById(fieldId + '_preview');

        function showPreview(file) {
            content.style.display = 'none';
            preview.style.display = 'flex';
            preview.querySelector('.dropzone-filename').textContent = file.name;
            preview.querySelector('.dropzone-filesize').textContent = formatFileSize(file.size);
            dropzone.classList.remove('dropzone-dragover');
        }

        input.addEventListener('change', () => {
            if (input.files.length > 0) {
                showPreview(input.files[0]);
            }
        });

        dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dropzone-dragover');
        });

        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('dropzone-dragover');
        });

        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dropzone-dragover');
            if (e.dataTransfer.files.length > 0) {
                input.files = e.dataTransfer.files;
                showPreview(input.files[0]);
            }
        });
    });

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function removeDropzoneFile(fieldId) {
        const input = document.getElementById(fieldId);
        input.value = '';
        document.getElementById(fieldId + '_content').style.display = 'block';
        document.getElementById(fieldId + '_preview').style.display = 'none';
    }
</script>
<?php engage_footer(); ?>
