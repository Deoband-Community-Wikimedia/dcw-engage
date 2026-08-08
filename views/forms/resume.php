<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../models/ApplicationModel.php';

global $resumeToken;

$appModel = new ApplicationModel();
$application = $appModel->getApplicationByToken($resumeToken);

if (!$application) {
    http_response_code(404);
    echo "<div style='font-family:sans-serif; text-align:center; padding: 50px;'><h2>Link Expired or Invalid</h2><p>This magic link is no longer valid. Please request a new one.</p></div>";
    die();
}

$schema = json_decode($application['schema_json'], true);
$formData = json_decode($application['form_data'], true);
$status = $application['status'];
$isLocked = in_array($status, ['Under Review', 'Accepted', 'Rejected']);
$wasDraft = $status === 'Draft';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {
    if (!CSRF::validate($_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }

    // Only a Draft offers a real choice here — once an application is a real
    // submission, every edit just re-saves it as a submission (no more
    // draft state to go back to).
    $intent = ($_POST['intent'] ?? 'submit') === 'draft' ? 'draft' : 'submit';
    $staysDraft = $wasDraft && $intent === 'draft';

    require_once __DIR__ . '/../../models/FormModel.php';
    $formModel = new FormModel();
    $errors = $formModel->validateSubmission($schema, $_POST, $staysDraft);

    if (empty($errors)) {
        $email = $_POST['email'] ?? $application['email'];
        $name = $_POST['full_name'] ?? $application['applicant_name'];

        $postData = $_POST;
        unset($postData['csrf_token']);

        // Process File Uploads
        require_once __DIR__ . '/../../models/FileUploader.php';
        $fileUploader = new FileUploader();

        // NOTE: loop variable is deliberately $fieldName, not $name — reusing
        // $name here used to silently overwrite the applicant's name (set
        // above) with the last field's internal name, so it got saved wrong
        // on every edit made through the resume portal.
        foreach ($schema['fields'] as $field) {
            $fieldName = $field['name'];
            if (($field['type'] ?? '') === 'file') {
                if (!empty($_FILES[$fieldName]['name'])) {
                    // New file uploaded
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
                // finalized right now) becomes a real submission. 'Submitted'
                // was never a valid value in the applications.status ENUM —
                // this call used to fail outright.
                $newStatus = $staysDraft ? 'Draft' : 'New';
                $appModel->saveApplication($application['form_id'], $email, $name, $newStatus, json_encode($postData), $application['id']);

                // A Draft becoming a real submission is the moment it earns
                // the same emails a brand-new submission gets. Just editing
                // an already-real submission, or re-saving a Draft as a
                // Draft again, stays silent like it does today.
                if ($wasDraft && !$staysDraft) {
                    require_once __DIR__ . '/../../includes/mailer.php';
                    $trackingId = 'DCW-' . str_pad($application['id'], 5, '0', STR_PAD_LEFT);
                    $formTitle = $schema['title'] ?? $application['form_type'];
                    Mailer::sendApplicationReceived($email, $name, $trackingId, $formTitle);
                    Mailer::sendOrganizerAlert(
                        ['id' => $application['form_id'], 'form_type' => $application['form_type'], 'title' => $formTitle, 'notify_emails' => $application['notify_emails'] ?? ''],
                        $email,
                        $name,
                        $application['id']
                    );
                }

                $success = $staysDraft ? "Draft updated successfully!" : "Application updated successfully!";
                // Update local formData so the view reflects the newest data
                $formData = $postData;
                $status = $newStatus;
                $wasDraft = $staysDraft;
            } catch (Exception $e) {
                $errors['system'] = $e->getMessage();
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
    <title>Edit Application - <?= htmlspecialchars($schema['title']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/forms.css">
</head>
<body>
    <div class="container">
        <?php if (!empty($schema['banner_image'])): ?>
            <img src="<?= htmlspecialchars($schema['banner_image']) ?>" alt="Banner" style="width: 100%; height: auto; border-radius: 8px; margin-bottom: 20px; max-height: 250px; object-fit: cover;">
        <?php endif; ?>
        
        <h1 style="<?= empty($schema['banner_image']) ? 'margin-top:0;' : 'margin-top:10px;' ?>">Edit: <?= htmlspecialchars($schema['title']) ?></h1>
        
        <?php if ($isLocked): ?>
            <div class="alert-locked">
                <strong>🔒 Application Locked</strong><br><br>
                Your application is currently marked as <strong><?= htmlspecialchars($status) ?></strong>. You can no longer make edits to this submission.
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-success">
                <strong>Success:</strong> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
            
        <?php if (!empty($errors['system'])): ?>
            <div class="alert-error">
                <strong>Notice:</strong> <?= htmlspecialchars($errors['system']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <?= CSRF::getInputField() ?>
            
            <div style="background: #f8fafc; padding: 20px; border-radius: 8px; margin-bottom: 30px; border: 1px solid #e2e8f0;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Email Address <span style="color:#ef4444">*</span></label>
                    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? $application['email']) ?>" required <?= $isLocked ? 'disabled' : '' ?>>
                </div>
            </div>
            <?php foreach ($schema['fields'] as $field): 
                $name = $field['name'];
                $label = $field['label'] ?? $name;
                $type = $field['type'] ?? 'text';
                $required = !empty($field['required']) ? 'required' : '';
                
                // Prioritize POST data if there's an error, otherwise load from database
                $value = $_POST[$name] ?? $formData[$name] ?? '';
                $fieldError = $errors[$name] ?? null;
                $disabledAttr = $isLocked ? 'disabled' : '';
            ?>
                <div class="form-group">
                    <label><?= htmlspecialchars($label) ?> <?= $required && !$isLocked ? '<span style="color:#ef4444">*</span>' : '' ?></label>
                    
                    <?php if ($type === 'select'): ?>
                        <select name="<?= htmlspecialchars($name) ?>" <?= $required ?> <?= $disabledAttr ?>>
                            <option value="">-- Select --</option>
                            <?php foreach ($field['options'] ?? [] as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= $value === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                        
                    <?php elseif ($type === 'textarea'): ?>
                        <textarea name="<?= htmlspecialchars($name) ?>" rows="4" <?= $required ?> <?= $disabledAttr ?>><?= htmlspecialchars($value) ?></textarea>
                        
                    <?php elseif ($type === 'file'): ?>
                        <?php if (!empty($value)): ?>
                            <div style="margin-bottom: 10px; font-size: 14px;">
                                Currently uploaded: <a href="/<?= htmlspecialchars($value) ?>" target="_blank" style="color: var(--primary-color);">View File</a>
                            </div>
                        <?php endif; ?>
                        <!-- Only require if there is no existing value -->
                        <input type="file" name="<?= htmlspecialchars($name) ?>" <?= ($required && empty($value)) ? 'required' : '' ?> <?= $disabledAttr ?>>
                        
                    <?php else: ?>
                        <input type="<?= htmlspecialchars($type) ?>" name="<?= htmlspecialchars($name) ?>" value="<?= htmlspecialchars($value) ?>" <?= $required ?> <?= $disabledAttr ?>>
                    <?php endif; ?>
                    
                    <?php if ($fieldError && !$isLocked): ?>
                        <span class="error-text"><?= htmlspecialchars($fieldError) ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if (!$isLocked && $wasDraft): ?>
                <div style="display:flex; gap:10px;">
                    <button type="submit" name="intent" value="draft" class="btn-outline" style="background:#fff; color:#106b9a; border:1px solid #106b9a;">Save as Draft</button>
                    <button type="submit" name="intent" value="submit">Submit Application</button>
                </div>
            <?php elseif (!$isLocked): ?>
                <button type="submit" name="intent" value="submit">Update Application</button>
            <?php endif; ?>
        </form>
    </div>
</body>
</html>
