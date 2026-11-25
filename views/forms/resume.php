<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/applicant_name.php';
require_once __DIR__ . '/../../models/ApplicationModel.php';
require_once __DIR__ . '/../../includes/wikitext.php';

global $resumeToken;

$appModel = new ApplicationModel();
$application = $appModel->getApplicationByToken($resumeToken);

if (!$application) {
    http_response_code(404);
    engage_header([
        'page'    => 'form-resume',
        'title'   => 'Link expired',
        'heading' => 'Link expired or invalid',
        'crumbs'  => [['Home', '/'], ['Link expired']],
    ]);
    ?>
    <div class="fcard" style="max-width:520px; text-align:center;">
        <p class="intro" style="margin:0 0 18px;">This magic link is no longer valid. Please request a new one.</p>
        <a class="back-link" href="/" style="margin:0;">&larr; Back to DCW Engage</a>
    </div>
    <?php
    engage_footer();
    die();
}

$schema = json_decode($application['schema_json'], true);
$schemaSections = FormModel::schemaSections(is_array($schema) ? $schema : []);
$schema['fields'] = FormModel::schemaFields(is_array($schema) ? $schema : []);
$formData = json_decode($application['form_data'], true);
$status = $application['status'];
// One list for "the applicant can no longer edit" (the model uses the same one when saving).
$isLocked = in_array($status, ApplicationModel::LOCKED_STATUSES, true);
$wasDraft = $status === 'Draft';

// The name was given when the email was verified, so it is not asked again here: the form's own
// name field is taken out, and the stored name is written back into the answers on save.
// Older drafts that were saved with the placeholder "Applicant" keep showing the field.
// schemaWithoutField() removes it from $schema['fields'], $schema['sections'] and $schemaSections,
// so validation (which reads the stored sections) no longer requires a field nobody can see.
$nameFieldKey = null;
$storedName = trim((string) ($application['applicant_name'] ?? ''));
if ($storedName !== '' && $storedName !== 'Applicant') {
    $nameFieldKey = applicantNameField($schema);
    if ($nameFieldKey !== null) {
        [$schema, $schemaSections] = schemaWithoutField($schema, $schemaSections, $nameFieldKey);
    }
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }

    // Only a Draft offers a real choice here: once an application is a real
    // submission, every edit just re-saves it as a submission.
    $intent = ($_POST['intent'] ?? 'submit') === 'draft' ? 'draft' : 'submit';
    $staysDraft = $wasDraft && $intent === 'draft';

    require_once __DIR__ . '/../../models/FormModel.php';
    $formModel = new FormModel();

    // The validator accepts a required file field when either a new upload is in $_FILES or an
    // existing path is present in the data it is given. Files already on record are not in $_POST
    // (the edit form has no hidden field for them), so supply them here. Without this, a required
    // file that was uploaded earlier is reported as missing on every edit.
    // New uploads are not copied in: the validator checks $_FILES for those itself.
    $validationInput = $_POST;
    foreach ($schema['fields'] as $f) {
        if (($f['type'] ?? '') === 'file' && !empty($formData[$f['name']])) {
            $validationInput[$f['name']] = $formData[$f['name']];
        }
    }
    $errors = $formModel->validateSubmission($schema, $validationInput, $staysDraft);

    if (empty($errors)) {
        // The address was verified when the application was started. It is never taken from the request,
        // so a magic link cannot be used to move an application to an address nobody has verified.
        $email = $application['email'];
        $name = resolveApplicantName($_POST, $schema, $application['applicant_name'] ?? 'Applicant');

        $postData = $_POST;
        unset($postData['csrf_token']);
        unset($postData['email']);   // not an answer; the verified address stays as it is
        // Answers to questions in sections hidden by the applicant's choices are never saved.
        $postData = FormModel::stripHiddenAnswers($schema, $postData);

        // Keep the verified name in the answers as well.
        if ($nameFieldKey !== null) {
            $postData[$nameFieldKey] = $formData[$nameFieldKey] ?? $storedName;
        }

        // Process File Uploads
        require_once __DIR__ . '/../../models/FileUploader.php';
        $fileUploader = new FileUploader();

        // NOTE: loop variable is deliberately $fieldName, not $name. Reusing
        // $name here used to overwrite the applicant's name with the last
        // field's internal name on every edit made through the resume portal.
        foreach (FormModel::visibleFields($schema, $_POST) as $field) {
            $fieldName = $field['name'];
            if (($field['type'] ?? '') === 'file') {
                if (!empty($_FILES[$fieldName]['name'])) {
                    try {
                        $path = $fileUploader->handleUpload($_FILES[$fieldName], $fieldName, $name, $application['form_type']);
                        if ($path) {
                            $postData[$fieldName] = $path;
                        }
                    } catch (Exception $e) {
                        $errors[$fieldName] = $e->getMessage();
                        $errors['system'] = "File upload failed.";
                    }
                } else {
                    // Keep existing file if no new file is uploaded
                    $postData[$fieldName] = $formData[$fieldName] ?? '';
                }
            }
        }

        if (empty($errors)) {
            try {
                // Draft stays Draft; anything else (including a Draft being
                // finalized right now) becomes a real submission ('New').
                $newStatus = $staysDraft ? 'Draft' : 'New';
                // A revision is a Draft that staff sent back ("Needs information") being submitted again.
                // This must be read BEFORE saving: saving as 'New' turns the Draft into a normal submission.
                $revised = $wasDraft && !$staysDraft && $appModel->isSentBackDraft((int) $application['id']);
                $appModel->saveApplication($application['form_id'], $email, $name, $newStatus, json_encode($postData), $application['id']);

                // A Draft becoming a real submission earns the same emails a
                // brand-new submission gets (worded as "revised" when it is a
                // resubmission after staff asked for more information).
                // Other edits stay silent.
                if ($wasDraft && !$staysDraft) {
                    require_once __DIR__ . '/../../includes/mailer.php';
                    $trackingId = $application['tracking_id'];
                    $formTitle = $schema['title'] ?? $application['form_type'];
                    Mailer::sendApplicationReceived($email, $name, $trackingId, $formTitle, $revised);
                    Mailer::sendOrganizerAlert(
                        ['id' => $application['form_id'], 'form_type' => $application['form_type'], 'title' => $formTitle, 'notify_emails' => $application['notify_emails'] ?? ''],
                        $email,
                        $name,
                        $trackingId,
                        $revised
                    );
                }

                $success = $staysDraft ? "Draft updated successfully!" : "Application updated successfully!";
                // Update local formData so the view reflects the newest data
                $formData = $postData;
                $status = $newStatus;
                $wasDraft = $staysDraft;
            } catch (Exception $e) {
                // Reasons written for the applicant ("Applications for this form closed on ...", "locked") are
                // shown; system failures are logged with a reference and replaced by a generic message.
                $errors['system'] = engage_user_message($e, 'Application update #' . (int) $application['id']);
            }
        }
    }
}

// Errors for fields that are not on the page cannot be printed next to a field, so they are listed in
// the notice box instead. Without this a failed save can reload the page with no message at all.
$hiddenErrors = hiddenFieldErrors($errors, $schemaSections, ['system']);

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$label_html = fn($s) => MiniWikiText::inline(htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'));

engage_header([
    'page'    => 'form-resume',
    'title'   => 'Edit Application - ' . ($schema['title'] ?? 'Application'),
    'heading' => $schema['title'] ?? 'Application',
    'kicker'  => 'Edit your application',
    'crumbs'  => [['Home', '/'], ['Edit application']],
]);
?>

<div class="fcard">
    <?php if (!empty($schema['banner_image'])): ?>
        <img src="<?= $e($schema['banner_image']) ?>" alt="Banner"
            style="width:100%; height:auto; max-height:250px; object-fit:cover; border-radius:12px; margin-bottom:22px;">
    <?php endif; ?>

    <?php if ($isLocked): ?>
        <div class="action-banner">
            <strong>🔒 Application locked.</strong>
            Your application is currently marked as <strong><?= $e($status) ?></strong>. You can no longer make edits to this submission.
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert ok"><strong>Success:</strong> <?= $e($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors['system']) || $hiddenErrors): ?>
        <div class="alert error">
            <strong>Notice:</strong>
            <?php if (!empty($errors['system'])): ?><?= $e($errors['system']) ?><?php endif; ?>
            <?php foreach ($hiddenErrors as $hiddenMsg): ?>
                <div><?= $e($hiddenMsg) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?= CSRF::getInputField() ?>

        <fieldset class="group">
            <legend>Contact</legend>
            <?php if ($nameFieldKey !== null): ?>
                <div class="field">
                    <label for="applicant_name_shown">Name</label>
                    <input type="text" id="applicant_name_shown" value="<?= $e($storedName) ?>" readonly>
                </div>
            <?php endif; ?>
            <div class="field">
                <label for="email">Email address</label>
                <?php /* No name attribute: the address is the one verified at the start and is never posted. */ ?>
                <input type="email" id="email" value="<?= $e($application['email']) ?>" readonly>
                <span class="hint">This is the address you verified. It cannot be changed here.</span>
            </div>
        </fieldset>

        <?php foreach ($schemaSections as $section): ?>
            <?php if (isset($section['show_if'])): ?><div class="cond-section" <?= FormModel::showIfAttrs($section) ?>><?php endif; ?>
            <?php if (trim($section['title']) !== '' || trim($section['description']) !== ''): ?>
                <section class="form-section">
                    <?php if (trim($section['title']) !== ''): ?><h2><?= $label_html($section['title']) ?></h2><?php endif; ?>
                    <?php if (trim($section['description']) !== ''): ?><div class="form-section-intro"><?= MiniWikiText::render($section['description']) ?></div><?php endif; ?>
                </section>
            <?php endif; ?>
            <?php foreach ($section['fields'] as $field):
            $name = $field['name'];
            $label = $field['label'] ?? $name;
            $type = $field['type'] ?? 'text';
            $required = !empty($field['required']) ? 'required' : '';
            $star = ($required && !$isLocked) ? ' <span class="req-star">*</span>' : '';

            // Prioritize POST data if there's an error, otherwise load from database
            $value = $_POST[$name] ?? $formData[$name] ?? '';
            $fieldError = $errors[$name] ?? null;
            $disabledAttr = $isLocked ? 'disabled' : '';
            ?>
            <div class="field">
                <?php if ($type === 'checkbox'):
                    // An unchecked box isn't submitted, so on a POST an absent key means
                    // "unchecked"; it must NOT fall back to the saved value.
                    $isChecked = $_SERVER['REQUEST_METHOD'] === 'POST' ? !empty($_POST[$name]) : !empty($formData[$name]);
                    ?>
                    <div class="choices stack">
                        <label for="<?= $e($name) ?>">
                            <input type="checkbox" name="<?= $e($name) ?>" id="<?= $e($name) ?>" value="Yes"
                                <?= $isChecked ? 'checked' : '' ?> <?= $required ?> <?= $disabledAttr ?>>
                            <span><?= $label_html($label) ?><?= $star ?></span>
                        </label>
                    </div>

                <?php elseif ($type === 'checkbox_group'):
                    // Same absent-on-POST rule as the single checkbox above.
                    $selectedValues = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST[$name] ?? []) : ($formData[$name] ?? []);
                    if (!is_array($selectedValues)) $selectedValues = [];
                    ?>
                    <label><?= $label_html($label) ?><?= $star ?></label>
                    <div class="choices stack">
                        <?php foreach ($field['options'] ?? [] as $opt): ?>
                            <label>
                                <input type="checkbox" name="<?= $e($name) ?>[]" value="<?= $e($opt) ?>"
                                    <?= in_array($opt, $selectedValues) ? 'checked' : '' ?> <?= $disabledAttr ?>>
                                <span><?= $e($opt) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                <?php else: ?>
                    <label for="<?= $e($name) ?>"><?= $label_html($label) ?><?= $star ?></label>

                    <?php if ($type === 'select'): ?>
                        <select name="<?= $e($name) ?>" id="<?= $e($name) ?>" <?= $required ?> <?= $disabledAttr ?>>
                            <option value="">-- Select --</option>
                            <?php foreach ($field['options'] ?? [] as $opt): ?>
                                <option value="<?= $e($opt) ?>" <?= $value === $opt ? 'selected' : '' ?>><?= $e($opt) ?></option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ($type === 'textarea'): ?>
                        <textarea name="<?= $e($name) ?>" id="<?= $e($name) ?>" rows="4" <?= $required ?> <?= $disabledAttr ?>><?= $e($value) ?></textarea>

                    <?php elseif ($type === 'file'):
                        $fieldId = 'file_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
                        ?>
                        <?php if (!empty($value)): ?>
                            <div style="margin-bottom:10px; font-size:14px;">
                                Currently uploaded: <a href="<?= $e(engage_upload_url((string) $value, (string) $resumeToken)) ?>" target="_blank" rel="noopener">View file</a>
                            </div>
                        <?php endif; ?>

                        <?php if ($isLocked): ?>
                            <input type="file" name="<?= $e($name) ?>" disabled>
                        <?php else: ?>
                            <div class="dropzone <?= $fieldError ? 'dropzone-has-error' : '' ?>" id="dropzone_<?= $fieldId ?>">
                                <input type="file" name="<?= $e($name) ?>" id="<?= $fieldId ?>" class="dropzone-input"
                                    accept=".pdf,.jpg,.jpeg,.png,.docx,.doc" <?= ($required && empty($value)) ? 'required' : '' ?>>

                                <div class="dropzone-content" id="<?= $fieldId ?>_content">
                                    <svg class="dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M12 16V4M12 4L7 9M12 4l5 5" stroke-linecap="round" stroke-linejoin="round" />
                                        <path d="M4 16v3a2 2 0 002 2h12a2 2 0 002-2v-3" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    <p class="dropzone-text">
                                        <?= !empty($value)
                                            ? 'Drag &amp; drop a new file to replace it, or <span class="dropzone-browse">click to browse</span>'
                                            : 'Drag &amp; drop your file here, or <span class="dropzone-browse">click to browse</span>' ?>
                                    </p>
                                    <p class="dropzone-hint">PDF, JPG, PNG, DOC, DOCX &mdash; up to 10MB</p>
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
                        <?php endif; ?>

                    <?php else: ?>
                        <input type="<?= $e($type) ?>" name="<?= $e($name) ?>" id="<?= $e($name) ?>"
                            value="<?= $e($value) ?>" <?= $required ?> <?= $disabledAttr ?>>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($fieldError && !$isLocked): ?>
                    <span class="error-text"><?= $e($fieldError) ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (isset($section['show_if'])): ?></div><?php endif; ?>
        <?php endforeach; ?>

        <?php if (!$isLocked && $wasDraft): ?>
            <div class="btn-row">
                <button type="submit" name="intent" value="draft" formnovalidate class="draft">Save as draft</button>
                <button type="submit" name="intent" value="submit">Submit application</button>
            </div>
        <?php elseif (!$isLocked): ?>
            <button type="submit" name="intent" value="submit">Update application</button>
        <?php endif; ?>
    </form>
</div>

<?php if (!$isLocked): ?>
<script src="/assets/js/conditional-sections.js?v=<?= filemtime(__DIR__ . '/../../assets/js/conditional-sections.js') ?>"></script>
<script>
    // Drag-and-drop wiring for every file field on the page.
    document.querySelectorAll('.dropzone-input').forEach(function (input) {
        var fieldId = input.id;
        var dropzone = document.getElementById('dropzone_' + fieldId);
        var content = document.getElementById(fieldId + '_content');
        var preview = document.getElementById(fieldId + '_preview');

        function showPreview(file) {
            content.style.display = 'none';
            preview.style.display = 'flex';
            preview.querySelector('.dropzone-filename').textContent = file.name;
            preview.querySelector('.dropzone-filesize').textContent = formatFileSize(file.size);
            dropzone.classList.remove('dropzone-dragover');
        }

        input.addEventListener('change', function () {
            if (input.files.length > 0) showPreview(input.files[0]);
        });
        dropzone.addEventListener('dragover', function (e) {
            e.preventDefault();
            dropzone.classList.add('dropzone-dragover');
        });
        dropzone.addEventListener('dragleave', function () {
            dropzone.classList.remove('dropzone-dragover');
        });
        dropzone.addEventListener('drop', function (e) {
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
        document.getElementById(fieldId).value = '';
        document.getElementById(fieldId + '_content').style.display = 'block';
        document.getElementById(fieldId + '_preview').style.display = 'none';
    }
</script>
<?php endif; ?>
<?php engage_footer(); ?>
