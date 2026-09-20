<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
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

if (empty($previewSchema) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }

    $email = trim($_POST['email'] ?? '');

    if (isset($_POST['action']) && $_POST['action'] === 'resend_magic_link') {
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

        // Resolve the applicant name from the actual submitted field name,
        // not a hardcoded full_name assumption, so both full_name and
        // applicant_name labels keep working across builder-generated schemas.
        $applicantName = resolveApplicantName($postData, $schema);

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
                }

                // Notify the organizer(s) in charge of this form — only for
                // a real submission, not every incomplete draft save.
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

// Determine the favicon URL: use banner_image if available, otherwise fall back to DCW logo
$faviconUrl = !empty($schema['banner_image'])
    ? htmlspecialchars($schema['banner_image'])
    : 'https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="<?= $faviconUrl ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($schema['title']) ?> - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>

<body>
    <div class="container">
        <?php if (!empty($previewSchema)): ?>
            <div
                style="background:#fef3c7; border:1px solid #f59e0b; color:#92400e; padding:10px 14px; border-radius:6px; margin-bottom:20px; font-size:14px; font-weight:600;">
                🔍 Preview — this is how the form will look. Submissions are disabled here.
            </div>
        <?php endif; ?>

        <?php if (!empty($schema['banner_image'])): ?>
            <img src="<?= htmlspecialchars($schema['banner_image']) ?>" alt="Banner"
                style="width: 100%; height: auto; border-radius: 8px; margin-bottom: 20px; max-height: 250px; object-fit: cover;">
        <?php endif; ?>

        <h1 style="<?= empty($schema['banner_image']) ? 'margin-top:0;' : 'margin-top:10px;' ?>">
            <?= htmlspecialchars($schema['title']) ?></h1>

        <?php if (!empty($schema['description'])): ?>
            <div style="color: #475569; font-size: 15px; margin-bottom: 30px; line-height: 1.6;">
                <?= MiniWikiText::render($schema['description']) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-success">
                <h3 style="margin-top:0">
                    <?= ($_POST['intent'] ?? '') === 'draft' ? 'Draft Saved!' : 'Application Received!' ?></h3>
                <?= htmlspecialchars($success) ?>
                <p style="margin-bottom:0; margin-top:10px; font-size: 14px;">
                    <?= ($_POST['intent'] ?? '') === 'draft'
                        ? 'We have emailed you a secure link to come back and finish this application anytime.'
                        : 'We have emailed you a confirmation. No further action is needed right now.' ?>
                </p>
            </div>
        <?php else: ?>

            <?php if (!empty($errors['system']) || !empty($errors['email'])): ?>
                <div class="alert-error">
                    <strong>Notice:</strong> <?= htmlspecialchars($errors['system'] ?? $errors['email']) ?>
                    <?php if (!empty($errors['show_resend'])): ?>
                        <div style="margin-top: 15px;">
                            <form method="POST" style="margin:0;">
                                <?= CSRF::getInputField() ?>
                                <input type="hidden" name="action" value="resend_magic_link">
                                <input type="hidden" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                <button type="submit"
                                    style="background: white; color: #991b1b; border: 1px solid #f87171; padding: 8px 16px; font-size: 14px; width: auto; font-weight: 500;">Resend
                                    magic link</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <?= CSRF::getInputField() ?>

                <div
                    style="background: #f8fafc; padding: 20px; border-radius: 8px; margin-bottom: 30px; border: 1px solid #e2e8f0;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Email Address <span style="color:#ef4444">*</span></label>
                        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                        <span style="font-size: 13px; color: #64748b; margin-top: 5px; display: block;">We will send your
                            secure Magic Link here to save your progress.</span>
                    </div>
                </div>

                <?php foreach ($schema['fields'] as $field):
                    $name = $field['name'];
                    $label = $field['label'] ?? $name;
                    $type = $field['type'] ?? 'text';
                    $required = !empty($field['required']) ? 'required' : '';
                    $value = htmlspecialchars($_POST[$name] ?? '');
                    $fieldError = $errors[$name] ?? null;
                    ?>
                    <div class="form-group<?= in_array($type, ['checkbox', 'checkbox_group']) ? ' form-group-checkbox' : '' ?>">
                        <?php if ($type === 'checkbox'): ?>
                            <label for="<?= htmlspecialchars($name) ?>" class="checkbox-label">
                                <input type="checkbox" name="<?= htmlspecialchars($name) ?>" id="<?= htmlspecialchars($name) ?>"
                                    value="1" <?= !empty($_POST[$name]) ? 'checked' : '' ?>             <?= $required ?>>
                                <span><?= htmlspecialchars($label) ?>
                                    <?= $required ? '<span style="color:#ef4444">*</span>' : '' ?></span>
                            </label>

                        <?php elseif ($type === 'checkbox_group'):
                            $selectedValues = $_POST[$name] ?? [];
                            if (!is_array($selectedValues))
                                $selectedValues = [];
                            ?>
                            <label><?= htmlspecialchars($label) ?>
                                <?= $required ? '<span style="color:#ef4444">*</span>' : '' ?></label>
                            <div class="checkbox-group">
                                <?php foreach ($field['options'] ?? [] as $opt): ?>
                                    <label class="checkbox-label checkbox-option">
                                        <input type="checkbox" name="<?= htmlspecialchars($name) ?>[]"
                                            value="<?= htmlspecialchars($opt) ?>" <?= in_array($opt, $selectedValues) ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($opt) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                        <?php else: ?>
                            <label for="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($label) ?>
                                <?= $required ? '<span style="color:#ef4444">*</span>' : '' ?></label>

                            <?php if ($type === 'select'): ?>
                                <select name="<?= htmlspecialchars($name) ?>" <?= $required ?>>
                                    <option value="">-- Select --</option>
                                    <?php foreach ($field['options'] ?? [] as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $value === $opt ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($type === 'textarea'): ?>
                                <textarea name="<?= htmlspecialchars($name) ?>" rows="4" <?= $required ?>><?= $value ?></textarea>

                            <?php elseif ($type === 'file'):
                                $fieldId = 'file_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
                                ?>
                                <div class="dropzone <?= $fieldError ? 'dropzone-has-error' : '' ?>" id="dropzone_<?= $fieldId ?>">
                                    <input type="file" name="<?= htmlspecialchars($name) ?>" id="<?= $fieldId ?>" class="dropzone-input"
                                        accept=".pdf,.jpg,.jpeg,.png,.docx,.doc" <?= $required ?>>

                                    <div class="dropzone-content" id="<?= $fieldId ?>_content">
                                        <svg class="dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="1.5">
                                            <path d="M12 16V4M12 4L7 9M12 4l5 5" stroke-linecap="round" stroke-linejoin="round" />
                                            <path d="M4 16v3a2 2 0 002 2h12a2 2 0 002-2v-3" stroke-linecap="round"
                                                stroke-linejoin="round" />
                                        </svg>
                                        <p class="dropzone-text">Drag &amp; drop your file here, or <span class="dropzone-browse">click
                                                to browse</span></p>
                                        <p class="dropzone-hint">PDF, JPG, PNG, DOC, DOCX — up to 10MB</p>
                                    </div>

                                    <div class="dropzone-preview" id="<?= $fieldId ?>_preview" style="display:none;">
                                        <svg class="dropzone-file-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="1.5">
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
                                <input type="<?= htmlspecialchars($type) ?>" name="<?= htmlspecialchars($name) ?>" value="<?= $value ?>"
                                    <?= $required ?>>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($fieldError): ?>
                            <span class="error-text"><?= htmlspecialchars($fieldError) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div style="display:flex; gap:10px;">
                    <button type="submit" name="intent" value="draft" formnovalidate class="btn-outline"
                        style="background:#fff; color:#106b9a; border:1px solid #106b9a;" <?= !empty($previewSchema) ? 'disabled title="Disabled in preview"' : '' ?>>Save as Draft</button>
                    <button type="submit" name="intent" value="submit" <?= !empty($previewSchema) ? 'disabled title="Disabled in preview"' : '' ?>>Submit Application</button>
                </div>
            </form>
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
</body>

</html>