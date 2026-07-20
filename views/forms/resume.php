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

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {
    if (!CSRF::validate($_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }
    
    require_once __DIR__ . '/../../models/FormModel.php';
    $formModel = new FormModel();
    $errors = $formModel->validateSubmission($schema, $_POST);
    
    if (empty($errors)) {
        $email = $_POST['email'] ?? $application['email'];
        $name = $_POST['full_name'] ?? $application['applicant_name'];
        
        $postData = $_POST;
        unset($postData['csrf_token']);
        
        // Process File Uploads
        require_once __DIR__ . '/../../models/FileUploader.php';
        $fileUploader = new FileUploader();
        
        foreach ($schema['fields'] as $field) {
            $name = $field['name'];
            if (($field['type'] ?? '') === 'file') {
                if (!empty($_FILES[$name]['name'])) {
                    // New file uploaded
                    try {
                        $path = $fileUploader->handleUpload($_FILES[$name], $name, $name, $application['form_type']);
                        if ($path) {
                            $postData[$name] = $path;
                        }
                    } catch (Exception $e) {
                        $errors[$name] = $e->getMessage();
                        $errors['system'] = "File upload failed.";
                    }
                } else {
                    // Keep existing file if no new file is uploaded
                    $postData[$name] = $formData[$name] ?? '';
                }
            }
        }
        
        if (empty($errors)) {
            try {
            // Update the existing application
            $appModel->saveApplication($application['form_id'], $email, $name, 'Submitted', json_encode($postData), $application['id']);
            $success = "Application updated successfully!";
            // Update local formData so the view reflects the newest data
                $formData = $postData;
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

            <?php if (!$isLocked): ?>
                <button type="submit">Update Application</button>
            <?php endif; ?>
        </form>
    </div>
</body>
</html>
