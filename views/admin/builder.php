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
            // Editing an existing form is identified by its stable form_id,
            // not by matching form_type — matching on form_type meant that
            // renaming a form's slug found no existing row to match against
            // and silently INSERTed a brand new form instead of updating the
            // one being edited (see #56).
            $formId = !empty($_POST['form_id']) ? (int)$_POST['form_id'] : null;

            try {
                if ($formId) {
                    $stmt = $db->prepare("UPDATE forms SET form_type = ?, schema_json = ?, notify_emails = ? WHERE id = ?");
                    $stmt->execute([$formType, $schemaJson, $notifyEmails !== '' ? $notifyEmails : null, $formId]);
                } else {
                    $stmt = $db->prepare("INSERT INTO forms (form_type, schema_json, notify_emails, is_active) VALUES (?, ?, ?, 1)");
                    $stmt->execute([$formType, $schemaJson, $notifyEmails !== '' ? $notifyEmails : null]);
                }
                // Back to the workspace dashboard on success (see #47) — a
                // standard Post/Redirect/Get, same pattern already used by every
                // other admin page's POST handler in this codebase.
                header('Location: /admin/dashboard');
                exit;
            } catch (PDOException $e) {
                // form_type is UNIQUE — this fires if the new/renamed slug
                // collides with a different, already-existing form.
                if ($e->getCode() === '23000') {
                    $error = "That URL slug is already in use by another form. Please choose a different one.";
                } else {
                    $error = "Something went wrong while saving the form. Please try again.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png">
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
            <input type="hidden" name="form_id" value="<?= isset($_GET['edit']) ? (int)$_GET['edit'] : '' ?>">

            <!-- Global Form Settings -->
            <div class="header-card">
                <input type="text" class="field-title-input" id="form_title" placeholder="Form Title (e.g. Untitled Form)" required style="font-size: 28px !important; width: 100%;">
                
                <div id="description_toolbar" style="display:flex; gap:6px; margin-bottom:6px;">
                    <button type="button" class="btn-outline btn-sm" title="Bold" data-wiki-wrap="'''" style="font-weight:700;">B</button>
                    <button type="button" class="btn-outline btn-sm" title="Italic" data-wiki-wrap="''" style="font-style:italic;">I</button>
                    <button type="button" class="btn-outline btn-sm" title="Link" data-wiki-link="1">Link</button>
                    <button type="button" class="btn-outline btn-sm" title="Large heading" data-wiki-heading="==">H1</button>
                    <button type="button" class="btn-outline btn-sm" title="Medium heading" data-wiki-heading="===">H2</button>
                    <button type="button" class="btn-outline btn-sm" title="Increase indent (up to 3 levels)" data-wiki-indent="1">Indent</button>
                    <button type="button" class="btn-outline btn-sm" title="Bullet list" data-wiki-list="*">&bull; List</button>
                    <button type="button" class="btn-outline btn-sm" title="Numbered list" data-wiki-list="#">1. List</button>
                </div>
                <textarea id="form_description" placeholder="Form Description (Optional)" style="width: 100%; padding: 12px; margin-bottom: 5px; border: 1px solid var(--border-color); border-radius: 6px; font-family: 'Inter', sans-serif; font-size: 14px; min-height: 140px; overflow-y: hidden; resize: vertical;"></textarea>
                <span style="font-size: 13px; color: #64748b; margin-bottom: 20px; display:block;">Formatting supported: '''bold''', ''italic'', [https://example.com link text], == Large heading ==, === Medium heading === (each heading must start and end its own line), :/::/::: for indenting up to 3 levels, and */# for bullet/numbered lists (consecutive lines of the same marker group into one list) — use the buttons above or type wikitext directly.</span>

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
            <div class="floating-action" style="display:flex; gap:10px;">
                <button type="button" class="btn-outline" id="preview_form_btn" style="background:#fff; color:#106b9a; border:1px solid #106b9a;">Preview</button>
                <button type="submit" class="btn-primary" style="box-shadow: 0 10px 15px -3px rgba(16,107,154,0.3);">Save Form & Publish</button>
            </div>
        </form>

        <!-- Opens the live public-form template in a new tab against the
             builder's current (unsaved) schema — see #47. Submitted via JS
             once the schema is built, same as the main form. -->
        <form id="previewForm" method="POST" action="/admin/preview_form" target="_blank" style="display:none;">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="schema_json" id="preview_schema_json_input">
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