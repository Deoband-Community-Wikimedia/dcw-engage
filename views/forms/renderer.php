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
    // getFormByType() returns false for a form that is switched off OR past its
    // deadline, so both land in the closed branch below.
    $form = $formModel->getFormByType($formType);

    if (!$form) {
        // Kindly note whether the form is closed rather than non-existent, ensuring that a form
        // which was live and is now closed displays an appropriate message instead of a plain 404.
        $inactiveForm = $formModel->getAnyFormByType($formType);

        if ($inactiveForm) {
            // Do NOT send a 403 status here. Hosts, CDNs, and browsers treat a 403 as a block
            // and replace the response body with their own error page (such as Chrome's "Access to
            // ... was denied"), preventing closed.php from reaching the visitor. Send a 200 instead;
            // closed.php already includes an "X-Robots-Tag: noindex" header to keep it out of
            // search results.
            http_response_code(200);
            $closedTitle = $inactiveForm['schema']['title'] ?? 'This form';
            // Set only when the form closed because its deadline passed, allowing closed.php
            // to display when it closed. A form manually disabled by an organizer displays the generic message.
            $closedDeadline = FormModel::closedByDeadline($inactiveForm) ? $inactiveForm['deadline_at'] : null;
            require __DIR__ . '/closed.php';
        } else {
            http_response_code(404);
            require __DIR__ . '/not_found.php';
        }
        exit;
    }
}

$schema = $form['schema'];
$errors = [];
$success = '';

// A signed-in member (Member ID + password) is already verified: their email was validated when
// their account was approved, and the session confirms their identity. Therefore, on EVERY form, they bypass
// the email gate and use the email address recorded in their member profile. This bypass is never applied for a Member ID
// that was simply keyed in: only an active member session is considered valid.
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

// A signed-in member should not be presented with an application form for a membership category they already hold
// (with any status except rejected). Redirect them to /membership, which provides details and renewal links.
// Applicable to join forms only: the renewal form remains accessible. Guest users are handled by the email check
// further below, since only an active member session reliably establishes identity.
if ($loggedMember !== null && $isMembershipForm && !$isRenewalForm) {
    try {
        require_once __DIR__ . '/../../models/MemberModel.php';
        $joinKey = MemberModel::chapterOfSlug((string) $formType);
        if ($joinKey !== null && isset(MemberSession::heldChapters()[$joinKey])) {
            header('Location: /membership?held=' . rawurlencode((string) $joinKey));
            exit;
        }
    } catch (Throwable $ex) {
        // If the lookup fails, proceed normally: the submission-time checks below will still apply.
    }
}

// Email verification takes precedence (#67). No user — whether intending to submit
// or simply save a draft — can access the form until they have proven ownership of
// the email address, preventing junk entries from creating database records or triggering magic links to
// unverified addresses. Proof of verification is maintained in the session on a per-form basis
// and redeemed via the emailed verification link (?verify=<token>).
//
// IMPORTANT: token redemption must never occur via a plain GET request. Mail
// security scanners (such as Outlook Safe Links, Proofpoint URL Defense, Mimecast,
// and Gmail's link proxy) automatically issue GET requests to every link in an incoming email
// prior to human review to check for malicious content. If a GET request consumed the one-time token,
// that automated prefetch would exhaust it first, causing the genuine applicant — arriving
// seconds or minutes later — to be redirected back to this gate with an error stating
// "expired or already used." Consequently, GET requests only *stage* the token; only an
// explicit POST request (which scanners do not issue) actually redeems it.
$verifiedEmail = '';
$verifiedName = '';   // name entered at the gate, carried forward via the emailed link
$verifyName = '';     // name entered in the current gate request (to repopulate the field)
$verifySent = false;
$pendingVerifyToken = null;

if (empty($previewSchema)) {
    // Evaluated in this specific order: the confirmation-step form below submits
    // back to this exact URL without stripping the query string, meaning
    // $_GET['verify'] remains set on that POST request as well. If the isset($_GET[...])
    // check were evaluated first, it would take precedence on every request — whether GET or POST — and the
    // branch responsible for calling consume() would never execute, leaving the
    // "Continue to application" button trapped in a loop re-rendering the same
    // confirmation screen indefinitely. Checking for the confirmation POST first
    // avoids this issue regardless of any leftover query parameters.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_verification') {
        if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
            die("Invalid CSRF token.");
        }

        require_once __DIR__ . '/../../models/EmailVerificationModel.php';
        $verified = (new EmailVerificationModel())->consume($form['id'], (string) ($_POST['verify_token'] ?? ''));

        if ($verified) {
            $_SESSION['verified_emails'][$form['id']] = $verified['email'];
            $_SESSION['verified_names'][$form['id']] = $verified['name'];
            // Remove the token from the URL to prevent bookmarking or sharing.
            header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
            exit;
        }

        // Provide a unified message for all failure scenarios — whether unknown, expired, used, or for a different form.
        $errors['verify'] = "That verification link has expired or has already been used. Please enter your email below to receive a new one.";
    } elseif (isset($_GET['verify'])) {
        // Side-effect-free: simply pass the token forward to the confirmation
        // step. No database modifications occur here.
        $pendingVerifyToken = (string) $_GET['verify'];
    }

    $verifiedEmail = $memberAutoVerified
        ? (string) $loggedMember['email']
        : ($_SESSION['verified_emails'][$form['id']] ?? '');

    // A signed-in member's name is retrieved from their member record; only guest users provide a gate name.
    $verifiedName = $memberAutoVerified
        ? ''
        : (string) ($_SESSION['verified_names'][$form['id']] ?? '');
}

if (empty($previewSchema) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'confirm_verification') {
    if (!CSRF::validate($_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }

    $email = trim($_POST['email'] ?? '');
    $postAction = $_POST['action'] ?? '';
    $gatePassed = false;

    if ($memberAutoVerified && in_array($postAction, ['request_verification', 'change_email'], true)) {
        // A signed-in member does not use an email gate and cannot switch to an alternate address.
        $email = $verifiedEmail;
    } elseif ($postAction === 'request_verification') {
        // Name entered at the gate: strip control characters, trim whitespace, enforce requirements, and cap the length.
        // Named verify_name to prevent naming collisions with schema fields named "name".
        $verifyName = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($_POST['verify_name'] ?? '')));

        if ($verifyName === '' || mb_strlen($verifyName) > 100) {
            $errors['email'] = "Kindly enter your name (maximum 100 characters).";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Kindly enter a valid email address.";
        } else {
            try {
                require_once __DIR__ . '/../../models/EmailVerificationModel.php';
                require_once __DIR__ . '/../../includes/mailer.php';
                $config = require __DIR__ . '/../../includes/config.php';
                $email = strtolower($email);
                $formTitle = $schema['title'] ?? $formType;

                // Applicable only to membership JOIN forms (the renewal form is intended for individuals who already
                // hold an active membership). If this email address is already associated with a member, notify them via
                // email rather than sending a standard verification link. The displayed screen remains identical
                // in both cases, ensuring the page does not disclose whether an account exists.
                $held = [];
                $joinChapter = null;
                if ($isMembershipForm && !$isRenewalForm) {
                    require_once __DIR__ . '/../../models/MemberModel.php';
                    require_once __DIR__ . '/../../includes/mail/membership_mailer.php';
                    $joinChapter = MemberModel::chapterOfSlug((string) $formType);
                    $held = (new MemberModel())->membershipsForEmail($email);
                }
                $holdsThis = !empty($held) && $joinChapter !== null
                    && in_array($joinChapter, array_column($held, 'chapter'), true);

                if ($holdsThis) {
                    // Already a member of this chapter: no application is required, so no
                    // verification link is dispatched. They are directed to sign in or renew.
                    MembershipMailer::sendExistingAccount($email, $held, $joinChapter, $formTitle);
                } else {
                    $issued = (new EmailVerificationModel())->request($form['id'], $email, $verifyName);

                    if ($issued) {
                        $verifyUrl = rtrim($config['app']['url'], '/') . '/' . rawurlencode($formType)
                            . '?verify=' . urlencode($issued['token']);
                        if (!empty($held)) {
                            // Member of another chapter: shares the same verification link flow, but the email
                            // clarifies that their existing Member ID will be retained.
                            MembershipMailer::sendExistingAccount($email, $held, $joinChapter, $formTitle, $verifyUrl, $issued['expires_at']);
                        } else {
                            Mailer::sendEmailVerification($email, $formTitle, $verifyUrl, $issued['expires_at'], $verifyName);
                        }
                    }
                }
                // Maintain the same screen appearance regardless of whether a link was successfully dispatched (handling rate limits
                // or mail delivery failures): the interface must not reveal the underlying outcome.
                $verifySent = true;
            } catch (Exception $e) {
                app_log("Email verification request failed for form '$formType' <$email>: " . $e->getMessage());
                $errors['system'] = "An error occurred while dispatching your verification email. Kindly try again.";
            }
        }
    } elseif ($postAction === 'change_email') {
        unset($_SESSION['verified_emails'][$form['id']], $_SESSION['verified_names'][$form['id']]);
        $verifiedEmail = '';
        $verifiedName = '';
    } elseif ($verifiedEmail === '') {
        $errors['system'] = "Kindly verify your email address first.";
    } else {
        // Beyond this point, the email address originally typed by the applicant is disregarded. The
        // verified email address is what gets saved and processed, preventing request tampering from
        // substituting an unverified address.
        $email = $verifiedEmail;
        $gatePassed = true;
    }

    if (!$gatePassed) {
        // Handled above; no further action is required for this request.
    } elseif ($postAction === 'resend_magic_link') {
        if (empty($email)) {
            $errors['email'] = "An email address is required to resend the link.";
        } else {
            require_once __DIR__ . '/../../models/ApplicationModel.php';
            $appModel = new ApplicationModel();
            $existing = $appModel->getApplicationByEmail($form['id'], $email);
            if ($existing) {
                $token = $appModel->generateMagicLink($existing['id'], false);
                require_once __DIR__ . '/../../includes/mailer.php';
                Mailer::sendMagicLink($email, $existing['applicant_name'] ?? 'Applicant', $token);
                $success = "We have resent the magic link to $email. Kindly check your inbox.";
            } else {
                $errors['email'] = "No existing application was found associated with that email address.";
            }
        }
    } else {
        // Two distinct actions share the same "Submit Application"
        // interface: saving an incomplete draft (which generates a resume magic link)
        // versus a final submission (which yields a confirmation receipt).
        // Default to 'submit' so that stray or legacy POST requests lacking this field never
        // silently default to a draft.
        $intent = ($_POST['intent'] ?? 'submit') === 'draft' ? 'draft' : 'submit';
        $isDraft = $intent === 'draft';

        // By definition, drafts are permitted to be incomplete; therefore, required
        // fields are not enforced for them.
        $errors = $formModel->validateSubmission($schema, $_POST, $isDraft);

        if (empty($email)) {
            $errors['email'] = "An email address is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Kindly enter a valid email address.";
        }

        require_once __DIR__ . '/../../models/ApplicationModel.php';
        $appModel = new ApplicationModel();

        $postData = $_POST;
        unset($postData['csrf_token']);

        // Membership forms (excluding renewals, which collect the ID directly): applicants who
        // already hold a membership in another chapter retain a single Member ID. This ID is assigned here,
        // on the server side, and is never accepted from submitted form inputs: it is sourced from the signed-in member session,
        // or from the value entered by a guest on /membership (subsequently validated against their verified email below).
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

        // Determine the applicant's name from the submitted field name rather than
        // relying on a hardcoded full_name assumption, ensuring compatibility with both full_name and
        // applicant_name labels across builder-generated schemas.
        $applicantName = resolveApplicantName($postData, $schema);

        // If the form lacks a name field (or it was left blank, such as in a draft), default to the
        // name provided by the applicant during email verification to prevent emails from displaying "Applicant".
        if ($verifiedName !== '' && (trim((string) $applicantName) === '' || $applicantName === 'Applicant')) {
            $applicantName = $verifiedName;
        }

        // Membership forms: validate the Member ID immediately (renewal form: the ID entered on the form;
        // other forms: the ID assigned above), ensuring typos or unauthorized IDs are identified here
        // rather than only after a reviewer accesses the application. Applies to final submissions only;
        // drafts may remain incomplete. No ID is permitted (making the individual a new applicant),
        // but invalid IDs are rejected. The error message remains consistent whether the ID does not exist or belongs
        // to another user. The email address evaluated is the verified address.
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
                    $errors['system'] = $e->getMessage();   // no ID field present on this form (or renamed); indicate the reason
                }
            }
        }

        // Reject duplicates BEFORE processing any files. Uploading files first and
        // checking for duplicates second leaves orphaned files on disk without an associated application
        // record, which the PII scrubber would be unable to purge.
        if (empty($errors)) {
            $existing = $appModel->getApplicationByEmail($form['id'], $email);
            if ($existing) {
                $errors['email'] = "You have already submitted an application for this program. Kindly check your email for the magic link to edit your application.";
                $errors['show_resend'] = true;
            }
        }

        // Only proceed to store uploaded files when the submission is otherwise valid and non-duplicate.
        // Track successfully saved files so changes can be reverted if the subsequent database save fails.
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

            // If a file fails validation after others have already uploaded successfully, remove
            // the successful uploads to prevent orphaned files.
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
                    // Draft submissions receive a resume-by-email magic link, utilizing the
                    // extended draft expiration window.
                    $token = $appModel->generateMagicLink($appId, true);
                    Mailer::sendMagicLink($email, $applicantName, $token);
                    $success = "Draft saved successfully! Your tracking ID is: $trackingId";
                } else {
                    // Final submissions receive a standard confirmation receipt instead —
                    // without an edit token. Returning applicants requiring modifications
                    // can utilize the "Resend Magic Link" option above.
                    $formTitle = $schema['title'] ?? $formType;
                    Mailer::sendApplicationReceived($email, $applicantName, $trackingId, $formTitle);
                    $success = "Application submitted successfully! Your tracking ID is: $trackingId";
                    // The Member ID entered on /membership has served its purpose (it is now stored with the application).
                    unset($_SESSION['join_member_id'][$formType]);
                }

                // Notify the designated organizer(s) responsible for this form — strictly for
                // final submissions rather than every incomplete draft save.
                // The verification address is consumed once recorded against an
                // application; subsequent submissions require fresh verification.
                unset($_SESSION['verified_emails'][$form['id']], $_SESSION['verified_names'][$form['id']]);

                if (!$isDraft) {
                    // $form is retrieved directly from FormModel::getFormByType(),
                    // which does not include a 'title' key (only 'schema'), meaning
                    // Mailer::sendOrganizerAlert()'s fallback would otherwise default
                    // to $form['form_type'] — the URL slug — instead of the
                    // actual title. Synchronize it with the title resolved above for the applicant email.
                    $form['title'] = $formTitle;
                    Mailer::sendOrganizerAlert($form, $email, $applicantName, $trackingId);
                }
            } catch (Exception $e) {
                // The save operation failed (including the UNIQUE(form_id, email) constraint
                // capturing duplicates that bypassed prior checks, or
                // the deadline constraint in saveApplication() if the form closed
                // while the applicant was completing it).
                // Delete any transferred files to prevent leaving them orphaned.
                //
                // Previously, exceptions were swallowed silently — applicants saw a
                // generic error message while the root cause was never logged, leaving live incidents (such as 2026-09-16) without a diagnostic trail.
                // Log the underlying exception while presenting the applicant with a generic message,
                // excepting "form closed" rejections where the specific message is intended for display.
                app_log("Application save failed for form '$formType' <$email>: " . $e->getMessage());
                cleanupUploads($uploadedPaths);
                $errors['system'] = ($e instanceof InvalidArgumentException)
                    ? $e->getMessage()
                    : "An error occurred while saving your application.";
            }
        }
    }
}

// Until the email address is verified, only the verification step (or,
// if a token was provided via GET, the confirmation step) is
// displayed. Previews lack session/database connectivity and therefore always display the form.
$showConfirm = empty($success) && $pendingVerifyToken !== null && $verifiedEmail === '';
$showGate = empty($success) && empty($previewSchema) && $verifiedEmail === '' && !$showConfirm;
$isDraftPost = ($_POST['intent'] ?? '') === 'draft';

// Signed-in members have their name displayed in the top bar (consistent with /membership).
$member = $loggedMember;

engage_header([
    'title'       => $schema['title'],
    'heading'     => $schema['title'],
    'kicker'      => 'Application',
    'lead'        => '',
    'member'      => $member,
    'crumbs'      => [['Home', '/'], [$schema['title']]],
    // Social preview: banner image serves as the thumbnail, description serves as preview text.
    'description' => MiniWikiText::stripToPlainText($schema['description'] ?? ''),
    'image'       => $schema['banner_image'] ?? null,
    'image_alt'   => $schema['title'],
]);
?>
<style>
    /* Application form only. All other styles are sourced from /assets/css/engage.css */

    /* Long titles wrap evenly instead of running as a single wide line */
    .hero h1 { max-width: 880px; margin-left: auto; margin-right: auto; text-wrap: balance; }

    /* Banner serves as the card header image: flush with the card edges (card padding is 30px).
       The attribution line (for Commons images) is positioned directly below it. */
    .banner-fig { margin: -30px -30px 26px; }
    .banner-img { display: block; width: 100%; height: auto; max-height: 260px; object-fit: cover; border-radius: 16px 16px 0 0; }
    .banner-credit { padding: 6px 30px 0; font-size: 12px; color: var(--muted); text-align: right; }
    .banner-credit a { color: inherit; text-decoration: underline; text-underline-offset: 2px; }

    /* Program description: readable body text, separated from the form below */
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

    /* Input types not yet styled by engage.css */
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
    <?php /* The "Click to continue" step renders exclusively the confirmation content: omitting the banner, deadline, and program description. */ ?>
    <?php if (!$showConfirm): ?>
        <?php if (!empty($schema['banner_image'])): ?>
            <figure class="banner-fig">
                <img class="banner-img" src="<?= htmlspecialchars(engage_resolve_image($schema['banner_image'], 1280)) ?>" alt="">
                <?= engage_commons_caption($schema['banner_image']) ?>
            </figure>
        <?php endif; ?>

        <?php if (!empty($previewSchema)): ?>
            <div class="action-banner" role="status">🔍 Preview — this is how the form will appear. Submissions are disabled in this mode.</div>
        <?php endif; ?>

        <?php if (!empty($form['deadline_at'])): ?>
            <p class="verified-line">Applications close on <strong><?= htmlspecialchars(date('j M Y, g:i A', strtotime($form['deadline_at']))) ?></strong>.</p>
        <?php endif; ?>

        <?php if (!empty($schema['description'])): ?>
            <div class="form-desc"><?= MiniWikiText::render($schema['description']) ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert ok">
            <h3><?= $isDraftPost ? 'Draft saved successfully!' : 'Application received!' ?></h3>
            <?= htmlspecialchars($success) ?>
            <p><?= $isDraftPost
                ? 'We have emailed you a secure link enabling you to return and complete this application at your convenience.'
                : 'We have emailed you a confirmation receipt. No further action is required at this time.' ?></p>
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
                <h3>Confirm your email address</h3>
                Kindly click below to complete verification and open the application. This additional step prevents automated
                email-safety scanners from consuming your verification link before you access it.
            </div>
            <form method="POST" action="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="confirm_verification">
                <input type="hidden" name="verify_token" value="<?= htmlspecialchars($pendingVerifyToken) ?>">
                <button type="submit">Proceed to application</button>
            </form>

        <?php elseif ($showGate): ?>
            <?php if ($verifySent): ?>
                <div class="alert ok">
                    <h3>Kindly check your inbox</h3>
                    If <strong><?= htmlspecialchars($email) ?></strong> can receive incoming mail, a verification link is on its
                    way. Open it within this browser session to commence your application. The link is single-use and will expire shortly.
                    <p>Not received yet? Kindly check your spam folder, or request a new link below (limited to 3 requests per hour).</p>
                </div>
            <?php endif; ?>

            <form method="POST">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="request_verification">
                <div class="field">
                    <label for="verify_name">Your name <span class="req-star">*</span></label>
                    <input type="text" name="verify_name" id="verify_name" maxlength="100"
                        value="<?= htmlspecialchars($verifyName) ?>" autocomplete="name" required>
                </div>
                <div class="field">
                    <label for="verify_email">Email address <span class="req-star">*</span></label>
                    <input type="email" name="email" id="verify_email" value="<?= htmlspecialchars($email ?? '') ?>" required>
                    <span class="hint">Expect a one-time verification link in your inbox shortly. Opening this link gives you access to the application form, where you can either finalise your submission or pause to save a draft for later.</span>
                </div>
                <button type="submit"><?= $verifySent ? 'Request a new link' : 'Send verification link' ?></button>
            </form>

        <?php else: ?>

            <?php if ($memberAutoVerified): ?>
                <p class="verified-line">Signed in as <strong><?= htmlspecialchars($verifiedEmail) ?></strong> ✓
                    Email verification is not required.</p>
            <?php elseif ($verifiedEmail !== ''): ?>
                <form method="POST" class="verified-line">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="change_email">
                    Verified as <strong><?= htmlspecialchars($verifiedName !== '' ? "$verifiedName ($verifiedEmail)" : $verifiedEmail) ?></strong> ✓
                    <button type="submit" formnovalidate class="linkbtn">Use a different email address</button>
                </form>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <?= CSRF::getInputField() ?>

                <div class="boxed">
                    <div class="field">
                        <label for="applicant_email">Email Address <span class="req-star">*</span></label>
                        <input type="email" name="email" id="applicant_email" value="<?= htmlspecialchars($verifiedEmail) ?>"
                            <?= $verifiedEmail !== '' ? 'readonly' : '' ?> required>
                        <span class="hint">We will dispatch your secure Magic Link to this address to enable progress saving.</span>
                    </div>
                </div>

                <?php foreach ($schema['fields'] as $field):
                    $name = $field['name'];
                    $label = $field['label'] ?? $name;
                    $type = $field['type'] ?? 'text';
                    $required = !empty($field['required']) ? 'required' : '';
                    // Raw posted value (for comparison purposes) and its escaped counterpart (for display).
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
                                    <option value="">-- Kindly Select --</option>
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
                        <?= !empty($previewSchema) ? 'disabled title="Disabled in preview mode"' : '' ?>>Save as Draft</button>
                    <button type="submit" name="intent" value="submit"
                        <?= !empty($previewSchema) ? 'disabled title="Disabled in preview mode"' : '' ?>>Submit Application</button>
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
