<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/wiki_username.php';
require_once __DIR__ . '/../../includes/applicant_name.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/EmailVerificationModel.php';

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
$schemaSections = FormModel::schemaSections(is_array($schema) ? $schema : []);
$schema['fields'] = FormModel::schemaFields(is_array($schema) ? $schema : []);

// Feedback forms (schema "kind": "feedback") are a different kind of form, unlike applications.
// No drafts, magic links, tracking ID or applications row. They have their own view and their own table.
// The closed / missing cases above have already been handled, so this only sees open forms (and previews).
if (($schema['kind'] ?? 'application') === 'feedback') {
    require __DIR__ . '/feedback.php';
    exit;
}

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
        app_log_ignored('renderer.php:117', $ex);
        // If the lookup fails, proceed normally: the submission-time checks below will still apply.
    }
}

// Email verification takes precedence (#67). No user — whether intending to submit
// or simply save a draft — can access the form until they have proven ownership of
// the email address, preventing junk entries from creating database records or triggering magic links to
// unverified addresses. Proof of verification is maintained in the session on a per-form basis.
//
// Verification is a ONE-TIME CODE (OTP), not a link:
//   1. gate   name + email  -> POST action=request_verification -> an 8-character code is emailed
//   2. code   the person types it on the page they already have open -> POST action=verify_code
//   3. form   the application fields
// The email being verified is kept in the SESSION ($_SESSION['otp_pending']), not in a hidden field, so it
// cannot be swapped between steps. There is no link to click, so mail-security link scanners (Safe Links,
// Proofpoint, ...) have nothing to burn, and old ?verify=<token> URLs still in inboxes are simply ignored.
// Wrong guesses are capped per code inside EmailVerificationModel::consume().
//
// The code screen looks the same whether or not a code was really issued (rate limit hit, or the address
// already holds this membership), so the page does not reveal what the system knows about an address.
//
// NAME: asked once, at the gate, and carried forward (verified_names in the session). The form's own
// name field is taken out of the form below, so nobody types it twice. Signed-in members use the name
// on their member record.
$verifiedEmail = '';
$verifiedName = '';   // name entered at the gate, carried forward with the verified code
$verifyName = '';     // name entered in the current gate request (to repopulate the field)
$otpPending = null;   // ['email', 'name', 'at', 'sent_at'] while the "enter your code" screen applies
$otpNotice = '';      // one-off notice on the code screen (for example after "Resend")

/** How long the "enter your code" screen stays available before the visitor has to start again. */
const OTP_SCREEN_TTL = 1800;

if (empty($previewSchema)) {
    $verifiedEmail = $memberAutoVerified
        ? (string) $loggedMember['email']
        : ($_SESSION['verified_emails'][$form['id']] ?? '');

    // A signed-in member's name is retrieved from their member record; only guest users provide a gate name.
    $verifiedName = $memberAutoVerified
        ? ''
        : (string) ($_SESSION['verified_names'][$form['id']] ?? '');

    if (!$memberAutoVerified && $verifiedEmail === '') {
        $o = $_SESSION['otp_pending'][$form['id']] ?? null;
        if (is_array($o) && (int) ($o['at'] ?? 0) > time() - OTP_SCREEN_TTL) {
            $otpPending = $o;
        } elseif ($o !== null) {
            unset($_SESSION['otp_pending'][$form['id']]);
        }
    }
}

// The name that goes forward: the member's own name when signed in, otherwise the one typed at the gate.
// When we have one, the form's own name field is removed (not shown, not required), and the value is
// written into the answers again on save, so reviewers, exports and emails still see it.
// The admin preview (no session, no name yet) hides it as well, so organizers see what applicants will see.
$carriedName = $memberAutoVerified
    ? trim((string) ($loggedMember['full_name'] ?? ''))
    : trim($verifiedName);
$nameFieldKey = null;
if ($carriedName !== '' || !empty($previewSchema)) {
    $nameFieldKey = applicantNameField($schema);
    if ($nameFieldKey !== null) {
        [$schema, $schemaSections] = schemaWithoutField($schema, $schemaSections, $nameFieldKey);
    }
}

/**
 * Send a verification for this address, whichever way applies. Used by the gate and by "Resend".
 *
 *  - Membership JOIN form and the address already holds THIS chapter: no application is needed, so no code is
 *    issued; the person gets the "account details" email instead (sign in or renew).
 *  - Membership JOIN form and the address holds ANOTHER chapter: a code is issued as usual, and the email
 *    also explains their existing Member ID will be kept.
 *  - Everything else: the normal verification-code email.
 * A rate-limited address (cooldown / hourly cap) simply gets no new code; the caller shows the same screen.
 */
$sendVerification = function (string $email, string $name) use ($form, $formType, $schema, $isMembershipForm, $isRenewalForm) {
    require_once __DIR__ . '/../../includes/mailer.php';
    $formTitle = $schema['title'] ?? $formType;

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
        MembershipMailer::sendExistingAccount($email, $held, $joinChapter, $formTitle);
        return;
    }

    $issued = (new EmailVerificationModel())->request($form['id'], $email, $name);
    if (!$issued) {
        return;
    }
    if (!empty($held)) {
        MembershipMailer::sendExistingAccount($email, $held, $joinChapter, $formTitle, $issued['code'], $issued['expires_at']);
    } else {
        Mailer::sendEmailVerification($email, $formTitle, $issued['code'], $issued['expires_at'], $name);
    }
};

if (empty($previewSchema) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    $email = trim($_POST['email'] ?? '');
    $postAction = $_POST['action'] ?? '';
    $gatePassed = false;

    if ($memberAutoVerified && in_array($postAction, ['request_verification', 'verify_code', 'resend_verification', 'change_email'], true)) {
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
                $email = strtolower($email);
                $sendVerification($email, $verifyName);

                // Maintain the same screen appearance regardless of whether a code was successfully dispatched (handling rate limits
                // or mail delivery failures): the interface must not reveal the underlying outcome.
                $otpPending = ['email' => $email, 'name' => $verifyName, 'at' => time(), 'sent_at' => time()];
                $_SESSION['otp_pending'][$form['id']] = $otpPending;
            } catch (Exception $e) {
                app_log("Email verification request failed for form '$formType' <$email>: " . $e->getMessage());
                $errors['system'] = "An error occurred while dispatching your verification email. Kindly try again.";
            }
        }
    } elseif ($postAction === 'verify_code') {
        // The address comes from the session, never from the request.
        if ($otpPending === null || ($otpPending['email'] ?? '') === '') {
            $errors['system'] = "Your verification session has expired. Kindly start again.";
            unset($_SESSION['otp_pending'][$form['id']]);
            $otpPending = null;
        } else {
            try {
                $verified = (new EmailVerificationModel())->consume(
                    $form['id'],
                    (string) $otpPending['email'],
                    is_string($_POST['otp_code'] ?? null) ? $_POST['otp_code'] : ''
                );
            } catch (Throwable $e) {
                app_log("Email verification consume failed for form '$formType': " . $e->getMessage());
                $verified = null;
            }

            if ($verified) {
                $_SESSION['verified_emails'][$form['id']] = $verified['email'];
                $_SESSION['verified_names'][$form['id']] = $verified['name'];
                unset($_SESSION['otp_pending'][$form['id']]);
                header('Location: ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
                exit;
            }

            // One message for every failure: wrong, expired, already used, superseded or too many attempts.
            $errors['verify'] = "That code is not correct, or it has expired. Check the code in your email, or request a new one below.";
        }
    } elseif ($postAction === 'resend_verification') {
        if ($otpPending === null || ($otpPending['email'] ?? '') === '') {
            $errors['system'] = "Your verification session has expired. Kindly start again.";
            unset($_SESSION['otp_pending'][$form['id']]);
            $otpPending = null;
        } else {
            // The cooldown is tracked in the session so it behaves the same for every address (including ones
            // that hold this membership and have no code on file). EmailVerificationModel enforces its own
            // cooldown and hourly cap on top of this.
            $left = EmailVerificationModel::RESEND_COOLDOWN_SECONDS - (time() - (int) ($otpPending['sent_at'] ?? 0));
            if ($left > 0) {
                $errors['system'] = "Kindly wait a moment before requesting another code.";
            } else {
                try {
                    $sendVerification((string) $otpPending['email'], (string) $otpPending['name']);
                    $otpPending['at'] = time();
                    $otpPending['sent_at'] = time();
                    $_SESSION['otp_pending'][$form['id']] = $otpPending;
                    // Same notice whether or not the hourly cap let a new code through.
                    $otpNotice = "If the address is correct, a new code is on its way.";
                } catch (Exception $e) {
                    app_log("Email verification resend failed for form '$formType' <" . $otpPending['email'] . ">: " . $e->getMessage());
                    $errors['system'] = "An error occurred while dispatching your verification email. Kindly try again.";
                }
            }
        }
    } elseif ($postAction === 'change_email') {
        unset($_SESSION['verified_emails'][$form['id']], $_SESSION['verified_names'][$form['id']], $_SESSION['otp_pending'][$form['id']]);
        $verifiedEmail = '';
        $verifiedName = '';
        $otpPending = null;
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
        // fields are not enforced for them. (The form's own name field is no longer in $schema when a
        // verified name is being carried forward, so it is not required here either.)
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

        // The name comes from verification (or the member record), never from the request.
        if ($nameFieldKey !== null) {
            $postData[$nameFieldKey] = $carriedName;
        }

        // Wikimedia usernames: MediaWiki always capitalises the first letter (and treats underscores
        // as spaces), so store them that way. This sits before the save, so it covers both
        // drafts and final submissions.
        $postData = WikiUsername::normalizeAnswers($schema, $postData);

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

        // The applicant's name: the one carried from verification when there is one. Otherwise (a preview,
        // or a verified session that somehow has no name) fall back to the name field on the form, found
        // through the schema rather than a hardcoded full_name, so builder-generated forms still work.
        $applicantName = $carriedName !== '' ? $carriedName : resolveApplicantName($postData, $schema);

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
                unset($_SESSION['verified_emails'][$form['id']], $_SESSION['verified_names'][$form['id']], $_SESSION['otp_pending'][$form['id']]);

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

// Until the email address is verified, only the verification steps are displayed: the gate, then (once a
// code has been sent) the code-entry screen. Previews lack session/database connectivity and therefore
// always display the form.
$showCode = empty($success) && empty($previewSchema) && $verifiedEmail === '' && $otpPending !== null;
$showGate = empty($success) && empty($previewSchema) && $verifiedEmail === '' && !$showCode;
$isDraftPost = ($_POST['intent'] ?? '') === 'draft';

// Seconds until another code may be requested, for the Resend countdown (0 = available now).
$otpWait = $showCode
    ? max(0, EmailVerificationModel::RESEND_COOLDOWN_SECONDS - (time() - (int) ($otpPending['sent_at'] ?? 0)))
    : 0;

// The banner, deadline line and program description appear on the first screen only.
// The short "enter your code" screen omits them.
$hideIntro = $showCode;

// Signed-in members have their name displayed in the top bar (consistent with /membership).
$member = $loggedMember;

// Link-only forms (schema flag "unlisted", set with the "Link only" checkbox in the builder) are
// not listed on the home page, and are also kept out of search results. This must run before
// engage_header(), which starts the page output.
if (!empty($schema['unlisted'])) {
    header('X-Robots-Tag: noindex, nofollow');
}

// Same page frame as the member dashboard: the slim hero with a left-aligned heading.
//   'back'   a quiet "← Home" link above the heading. It replaces the breadcrumb pills.
//   'crumbs' [] switches the breadcrumb trail off. Without this the form title was printed twice:
//            once as the big heading and again as the last breadcrumb.
//   'tools'  signed-in members get the shared member menu (avatar + three lines) in the top bar.
engage_header([
    'title'       => $schema['title'],
    'heading'     => $schema['title'],
    'slim'        => true,
    'back'        => ['Home', '/'],
    'crumbs'      => [],
    'member'      => $member,
    'tools'       => $member ? engage_member_menu($member) : null,
    // Social preview: banner image serves as the thumbnail, description serves as preview text.
    'description' => MiniWikiText::stripToPlainText($schema['description'] ?? ''),
    'image'       => $schema['banner_image'] ?? null,
    'image_alt'   => $schema['title'],
]);
?>
<style>
    /* Application form only. All other styles are sourced from /assets/css/engage.css */

    /* Long titles wrap evenly. The heading stays left-aligned (slim hero). */
    .hero h1 { text-wrap: balance; }

    /* The form card uses the full content width, so its left edge lines up with the heading and the
       top bar, like the panels on the member dashboard. To keep a narrower form, use for example:
       .fcard { max-width: 860px; margin-left: 0; } */
    .fcard { max-width: none; }

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

    /* Code entry */
    .otp-input { width: 100%; max-width: 320px; padding: 14px 16px; font: inherit; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 24px; font-weight: 700; letter-spacing: 4px; text-transform: uppercase; text-align: center; background: #fff; color: var(--ink); border: 1px solid var(--border); border-radius: 10px; }
    .otp-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0; }
    .otp-actions form { margin: 0; }
    .fcard .otp-actions button { width: auto; padding: 8px 18px; font-size: 14px; }
    .fcard .otp-actions button[disabled] { opacity: .55; cursor: not-allowed; }

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
    <?php /* The "enter your code" screen renders only its own message: omitting the banner, deadline, and program description. */ ?>
    <?php if (!$hideIntro): ?>
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
            <?php /* deadline_at is stored in UTC; formatIst() converts to IST and appends " IST", matching the builder and closed.php. */ ?>
            <p class="verified-line">Applications close on <strong><?= htmlspecialchars(FormModel::formatIst($form['deadline_at'])) ?></strong>.</p>
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

        <?php if ($showCode): ?>
            <?php if ($otpNotice !== ''): ?>
                <div class="alert ok"><?= htmlspecialchars($otpNotice) ?></div>
            <?php endif; ?>

            <div class="boxed">
                <h3 style="margin:0 0 8px; font-size:17px;">Enter your verification code</h3>
                <p style="margin:0 0 14px; font-size:14.5px;">
                    If <strong><?= htmlspecialchars((string) $otpPending['email']) ?></strong> can receive incoming mail, a verification code is on its
                    way. Enter it below to commence your application. The code is single-use and will expire shortly.
                </p>
                <form method="POST">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="verify_code">
                    <div class="field">
                        <label for="otp_code">Verification code <span class="req-star">*</span></label>
                        <input type="text" class="otp-input" name="otp_code" id="otp_code" maxlength="12" required autofocus
                            autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" placeholder="ABCD-EFGH">
                    </div>
                    <button type="submit" style="margin-top:14px;">Verify and continue</button>
                </form>
            </div>

            <p style="margin:0 0 10px; font-size:14px; color:var(--muted);">Not received yet? Kindly check your spam folder, or request a new code.</p>
            <div class="otp-actions">
                <form method="POST">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="resend_verification">
                    <button type="submit" class="btn-outline" id="resend-btn" data-wait="<?= (int) $otpWait ?>"<?= $otpWait > 0 ? ' disabled' : '' ?>>
                        Resend code<span id="resend-count"></span></button>
                </form>
                <form method="POST">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="change_email">
                    <button type="submit" formnovalidate class="btn-outline">Use a different email address</button>
                </form>
            </div>
            <script>
                // Convenience only: the server enforces the resend cooldown and the hourly limit.
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

        <?php elseif ($showGate): ?>
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
                    <span class="hint">Expect a one-time verification code in your inbox shortly. Entering this code gives you access to the application form, where you can either finalise your submission or pause to save a draft for later.</span>
                </div>
                <button type="submit">Send verification code</button>
            </form>

        <?php else: ?>

            <?php if ($memberAutoVerified): ?>
                <p class="verified-line">Signed in as <strong><?= htmlspecialchars($carriedName !== '' ? "$carriedName ($verifiedEmail)" : $verifiedEmail) ?></strong> ✓
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

                <?php foreach ($schemaSections as $section): ?>
                    <?php if (trim($section['title']) !== '' || trim($section['description']) !== ''): ?>
                        <section class="form-section">
                            <?php if (trim($section['title']) !== ''): ?><h2><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?></h2><?php endif; ?>
                            <?php if (trim($section['description']) !== ''): ?><div class="form-section-intro"><?= MiniWikiText::render($section['description']) ?></div><?php endif; ?>
                        </section>
                    <?php endif; ?>
                    <?php foreach ($section['fields'] as $field):
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
                                    value="<?= $value ?>" <?= $required ?>
                                    <?= WikiUsername::isField($field) ? 'data-wikiuser="1" autocapitalize="sentences" autocomplete="off" spellcheck="false"' : '' ?>>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($fieldError): ?>
                            <span class="error-text"><?= htmlspecialchars($fieldError) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
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

    // Wikimedia username fields: show the corrected form (first letter capitalised, underscores
    // as spaces) when the person leaves the field. The server applies the same rules on save.
    document.querySelectorAll('input[data-wikiuser]').forEach(el => {
        el.addEventListener('blur', () => {
            const v = el.value.trim().replace(/[\s_]+/g, ' ');
            if (v) el.value = v.charAt(0).toUpperCase() + v.slice(1);
        });
    });
</script>
<?php engage_footer(); ?>
