<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';

Auth::requireLogin();

$success = '';
$error = '';

$existingSchema = null;
$existingFormType = '';
$existingNotifyEmails = '';
if (isset($_GET['edit'])) {
    require_once __DIR__ . '/../../models/FormModel.php';
    $formModel = new FormModel();
    $form = $formModel->getFormById($_GET['edit']);
    if ($form) {
        $existingSchema = $form['schema'];
        $existingFormType = $form['form_type'];
        $existingNotifyEmails = $form['notify_emails'] ?? '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }
    
    $formType = trim($_POST['form_type']);
    $schemaJson = $_POST['schema_json'];
    $notifyEmailsRaw = trim($_POST['notify_emails'] ?? '');

    // Normalise the comma-separated recipients and validate each one.
    // Empty is allowed — a form with no recipients simply sends no alerts.
    $notifyEmails = '';
    $badEmail = null;
    if ($notifyEmailsRaw !== '') {
        $parts = array_filter(array_map('trim', explode(',', $notifyEmailsRaw)));
        foreach ($parts as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $badEmail = $addr;
                break;
            }
        }
        $notifyEmails = implode(', ', $parts);
    }

    if (empty($formType) || empty($schemaJson)) {
        $error = "Form type and schema are required.";
    } elseif (!preg_match('/^[a-z0-9_-]+$/', $formType)) {
        $error = "Invalid URL Slug. Use only lowercase letters, numbers, hyphens, and underscores.";
    } elseif ($badEmail !== null) {
        $error = "Notification email '" . htmlspecialchars($badEmail) . "' is not a valid address.";
    } else {
        // Validate JSON
        json_decode($schemaJson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = "Invalid JSON schema format generated.";
        } else {
            global $db;
            $stmt = $db->prepare("INSERT INTO forms (form_type, schema_json, notify_emails, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE schema_json = VALUES(schema_json), notify_emails = VALUES(notify_emails)");
            $stmt->execute([$formType, $schemaJson, $notifyEmails !== '' ? $notifyEmails : null]);
            $success = "Form schema saved successfully for type: " . htmlspecialchars($formType);
            $existingNotifyEmails = $notifyEmails;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Visual Form Builder</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../../assets/css/admin.css') ?>">
</head>
<body>
    <div class="container">
        <?php if ($success): ?><div class="alert-success"><?= $success ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert-error"><?= $error ?></div><?php endif; ?>

        <form id="builderForm" method="POST">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="schema_json" id="schema_json_input">

            <!-- Global Form Settings -->
            <div class="header-card">
                <input type="text" class="field-title-input" id="form_title" placeholder="Form Title (e.g. Untitled Form)" required style="font-size: 28px !important; width: 100%;">
                
                <textarea id="form_description" placeholder="Form Description (Optional)" style="width: 100%; padding: 12px; margin-bottom: 20px; border: 1px solid var(--border-color); border-radius: 6px; font-family: 'Inter', sans-serif; font-size: 14px; min-height: 80px;"></textarea>
                
                <input type="url" id="banner_image" placeholder="Banner Image URL (Optional, e.g. https://example.com/banner.jpg)" style="width: 100%; padding: 12px; margin-bottom: 20px; border: 1px solid var(--border-color); border-radius: 6px; font-family: 'Inter', sans-serif; font-size: 14px;">
                
                <label>URL Slug (Identifier)</label>
                <input type="text" name="form_type" id="form_type" required placeholder="e.g. fellowship-2026" style="margin-bottom: 0;">
                <span style="font-size: 13px; color: #64748b; margin-top: 5px; display:block;">Users will access this form at: /&lt;slug&gt;</span>

                <label style="margin-top: 20px; display:block;">Alert Emails (Optional)</label>
                <input type="text" name="notify_emails" id="notify_emails" placeholder="e.g. clublead@dcwwiki.org, coordinator@dcwwiki.org" value="<?= htmlspecialchars($existingNotifyEmails, ENT_QUOTES) ?>" style="margin-bottom: 0;">
                <span style="font-size: 13px; color: #64748b; margin-top: 5px; display:block;">Organizers notified when someone submits this form. Comma-separate multiple addresses. Leave blank for none.</span>
            </div>

            <div style="background: #e0f2fe; color: #0369a1; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #bae6fd; font-size: 14px;">
                <strong>Notice:</strong> An <em>Email Address</em> field is automatically added to the top of every form to support Magic Links. You do not need to create one below.
            </div>

            <!-- Dynamic Fields Container -->
            <div id="fields_container">
                <!-- Visual Cards will be injected here via JS -->
            </div>

            <div style="text-align: center; margin-top: 30px;">
                <button type="button" class="btn-outline" id="add_field_btn" style="border-radius: 50px; padding: 12px 30px;">+ Add Question</button>
            </div>

            <!-- Floating Save Actions -->
            <div class="floating-action">
                <button type="submit" class="btn-primary" style="box-shadow: 0 10px 15px -3px rgba(16,107,154,0.3);">Save Form & Publish</button>
            </div>
        </form>
    </div>

    <script>
        // Load existing schema if editing
        const existingSchema = <?= isset($existingSchema) && $existingSchema ? json_encode($existingSchema) : 'null' ?>;
        const existingFormType = <?= isset($existingFormType) ? json_encode($existingFormType) : 'null' ?>;
    </script>
    <script src="/assets/js/builder.js?v=<?= filemtime(__DIR__ . '/../../assets/js/builder.js') ?>"></script>
</body>
</html>
