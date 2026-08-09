<?php
require_once __DIR__ . '/../../includes/init.php';
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

$schema = $form['schema'];
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $errors = $formModel->validateSubmission($schema, $_POST);

        if (empty($email)) {
            $errors['email'] = "Email Address is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Please enter a valid email address.";
        }

        require_once __DIR__ . '/../../models/ApplicationModel.php';
        $appModel = new ApplicationModel();

        $postData = $_POST;
        unset($postData['csrf_token']);

        $nameCandidates = ['full_name', 'name', 'applicant_name'];
        $applicantName = 'Applicant';
        foreach ($nameCandidates as $candidate) {
            if (!empty(trim($postData[$candidate] ?? ''))) {
                $applicantName = trim($postData[$candidate]);
                break;
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
                $appId = $appModel->saveApplication($form['id'], $email, $applicantName, 'New', json_encode($postData));

                // Generate Magic Link
                $token = $appModel->generateMagicLink($appId, false);

                // Dispatch Email Notification
                require_once __DIR__ . '/../../includes/mailer.php';
                Mailer::sendMagicLink($email, $applicantName, $token);

                // Notify the organizer(s) in charge of this form
                Mailer::sendOrganizerAlert($form, $email, $applicantName, $appId);

                $success = "Application submitted successfully! Your tracking ID is: DCW-" . str_pad($appId, 5, '0', STR_PAD_LEFT);
            } catch (Exception $e) {
                // The save failed (including the UNIQUE(form_id, email) guard
                // catching a duplicate that slipped past the check above).
                // Delete any files we moved so they are not left orphaned.
                cleanupUploads($uploadedPaths);
                $errors['system'] = "An error occurred saving your application.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($schema['title']) ?> - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/forms.css">
</head>

<body>
    <div class="container">
        <?php if (!empty($schema['banner_image'])): ?>
            <img src="<?= htmlspecialchars($schema['banner_image']) ?>" alt="Banner"
                style="width: 100%; height: auto; border-radius: 8px; margin-bottom: 20px; max-height: 250px; object-fit: cover;">
        <?php endif; ?>

        <h1 style="<?= empty($schema['banner_image']) ? 'margin-top:0;' : 'margin-top:10px;' ?>">
            <?= htmlspecialchars($schema['title']) ?></h1>

        <?php if (!empty($schema['description'])): ?>
            <p style="color: #475569; font-size: 15px; margin-bottom: 30px; line-height: 1.6;">
                <?= nl2br(htmlspecialchars($schema['description'])) ?>
            </p>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-success">
                <h3 style="margin-top:0">Application Received!</h3>
                <?= htmlspecialchars($success) ?>
                <p style="margin-bottom:0; margin-top:10px; font-size: 14px;">We have sent a confirmation email (with a
                    magic link to edit your application if needed) to your registered address.</p>
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
                                    Magic Link</button>
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
                    <div class="form-group">
                        <label><?= htmlspecialchars($label) ?>
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

                        <?php elseif ($type === 'file'): ?>
                            <input type="file" name="<?= htmlspecialchars($name) ?>" <?= $required ?>>

                        <?php else: ?>
                            <input type="<?= htmlspecialchars($type) ?>" name="<?= htmlspecialchars($name) ?>" value="<?= $value ?>"
                                <?= $required ?>>
                        <?php endif; ?>

                        <?php if ($fieldError): ?>
                            <span class="error-text"><?= htmlspecialchars($fieldError) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <button type="submit">Submit Application</button>
            </form>
        <?php endif; ?>
    </div>
</body>

</html>