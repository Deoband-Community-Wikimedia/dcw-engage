<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/ApplicationModel.php';

require_once __DIR__ . '/../../models/NotesModel.php';

if (!isset($_SESSION['admin_id'])) {
    $_SESSION['admin_id'] = 1;
}
if (!isset($_SESSION['admin_email'])) {
    $_SESSION['admin_email'] = 'admin@dcwwiki.org';
}

$notesModel = new NotesModel();

$formId = $_GET['id'] ?? null;
if (!$formId) die("Form ID missing.");

$formModel = new FormModel();
$appModel = new ApplicationModel();

$form = $formModel->getFormById($formId);
if (!$form) die("Form not found.");

$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'])) die("Invalid CSRF");
    
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'toggle_form') {
            $newStatus = $_POST['is_active'] === '1' ? 1 : 0;
            $formModel->toggleFormStatus($formId, $newStatus);
            header("Location: /admin/form_manager?id=" . $formId);
            exit;
        } elseif ($_POST['action'] === 'delete_form') {
            $formModel->deleteForm($formId);
            header("Location: /admin/dashboard");
            exit;
        } elseif ($_POST['action'] === 'update_applicant_status') {
            $appModel->updateStatus($_POST['application_id'], $_POST['status']);
            $success = "Applicant status updated.";
        } elseif ($_POST['action'] === 'add_note') {
            $noteText = trim($_POST['note_text'] ?? '');
            $noteAppId = $_POST['application_id'] ?? '';
            if (!empty($noteText) && !empty($noteAppId)) {
                $notesModel->addNote(
                    $noteAppId,
                    $_SESSION['admin_id'],
                    $_SESSION['admin_email'],
                    $noteText
                );
                $success = "Note added.";
            } else {
                $success = "Could not add note — please open the application first, then add your note.";
            }
        }
    }
}

$applications = $appModel->getApplicationsByFormId($formId);

// Handle CSV Export
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9]+/', '_', $form['title']) . '_Export.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Dynamic headers based on Schema + default ones
    $headers = ['Tracking ID', 'Status', 'Date Submitted'];
    $fields = [];
    foreach ($form['schema']['fields'] as $f) {
        $headers[] = $f['label'] ?? $f['name'];
        $fields[] = $f['name'];
    }
    fputcsv($output, $headers);
    
    foreach ($applications as $app) {
        $row = [
            'DCW-' . str_pad($app['id'], 5, '0', STR_PAD_LEFT),
            $app['status'],
            date('Y-m-d H:i', strtotime($app['created_at']))
        ];
        
        $data = json_decode($app['form_data'], true);
        foreach ($fields as $fieldName) {
            $row[] = $data[$fieldName] ?? '';
        }
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($form['title']) ?> - Form Manager</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
    <div class="container">
        <div style="margin-bottom: 20px;">
            <a href="/admin/dashboard" style="color: var(--primary-color); text-decoration: none; font-weight: 600;">&larr; Back to Workspace</a>
        </div>
        
        <?php if ($success): ?><div style="background: #d1fae5; color: #065f46; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #34d399;"><?= $success ?></div><?php endif; ?>

        <div class="header-card">
            <div>
                <h1><?= htmlspecialchars($form['title']) ?></h1>
                <p class="meta" style="display: flex; align-items: center; gap: 10px;">
                    URL Endpoint: <strong>/<?= htmlspecialchars($form['form_type']) ?></strong>
                    <button class="btn btn-sm btn-outline" style="padding: 4px 8px; font-size: 11px;" onclick="copyToClipboard('<?= 'http://' . $_SERVER['HTTP_HOST'] . '/' . $form['form_type'] ?>', this)">Copy Link</button>
                    &bull; Total Responses: <strong><?= count($applications) ?></strong>
                </p>
            </div>
            <div class="controls">
                <a href="?id=<?= $formId ?>&action=export" class="btn btn-outline">
                    <svg style="width:16px; height:16px; vertical-align: middle; margin-right:5px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    Export CSV
                </a>
                <a href="/admin/builder?edit=<?= $formId ?>" class="btn btn-outline">
                    Edit Schema
                </a>

                <form method="POST" style="margin:0;">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="toggle_form">
                    <?php if ($form['is_active']): ?>
                        <input type="hidden" name="is_active" value="0">
                        <button type="submit" class="btn btn-danger">Close Form</button>
                    <?php else: ?>
                        <input type="hidden" name="is_active" value="1">
                        <button type="submit" class="btn btn-success">Re-Open Form</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Tracking ID</th>
                    <th>Applicant Name</th>
                    <th>Status</th>
                    <th>Date Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($applications)): ?>
                    <tr><td colspan="5" style="text-align:center; padding: 30px; color: #64748b;">No responses yet.</td></tr>
                <?php endif; ?>
                
                <?php foreach ($applications as $app): 
                    $statusClass = 'status-' . str_replace(' ', '-', $app['status']);
                ?>
                    <tr>
                        <td style="font-family: monospace; font-weight: 600;">DCW-<?= str_pad($app['id'], 5, '0', STR_PAD_LEFT) ?></td>
                        <td style="font-weight: 500;"><?= htmlspecialchars($app['applicant_name']) ?></td>
                        <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($app['status']) ?></span></td>
                        <td style="color: #64748b;"><?= date('M j, Y H:i', strtotime($app['created_at'])) ?></td>
                        <td>
                            <button class="btn btn-sm btn-primary" onclick='viewData(<?= json_encode($app['form_data'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, "<?= htmlspecialchars($app['applicant_name'], ENT_QUOTES) ?>", <?= $app['id'] ?>, <?= json_encode($notesModel->getNotesByApplication($app['id']), JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>View Data</button>
                            
                            <form method="POST" style="display:inline-block; margin-left:10px;">
                                <?= CSRF::getInputField() ?>
                                <input type="hidden" name="action" value="update_applicant_status">
                                <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                <select name="status" onchange="this.form.submit()">
                                    <option value="New" <?= $app['status'] == 'New' ? 'selected' : '' ?>>New</option>
                                    <option value="Under Review" <?= $app['status'] == 'Under Review' ? 'selected' : '' ?>>Under Review (Lock)</option>
                                    <option value="Accepted" <?= $app['status'] == 'Accepted' ? 'selected' : '' ?>>Accepted</option>
                                    <option value="Rejected" <?= $app['status'] == 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                                </select>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Data Viewer Modal -->
    <div id="dataModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle" style="margin-top:0; color: var(--primary-color); margin-bottom: 20px;">Applicant Data</h2>
            <div id="jsonViewer" class="data-grid"></div>

            <hr style="margin: 25px 0; border: none; border-top: 1px solid #e2e8f0;">

            <h3 style="margin-bottom: 15px; color: var(--primary-color);">Internal Notes</h3>

            <div id="notesContainer" style="max-height: 200px; overflow-y: auto; margin-bottom: 15px;"></div>

            <form method="POST" id="noteForm">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="application_id" id="noteAppId" value="">
                <textarea name="note_text" rows="3" placeholder="Add an internal note (only visible to organizers)..." required style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0; font-family: inherit;"></textarea>
                <button type="submit" class="btn btn-sm btn-primary" style="margin-top: 10px;">Add Note</button>
            </form>
        </div>
    </div>

    <script>
        // Use the schema from PHP to properly map keys to labels if possible
        const formSchema = <?= json_encode($form['schema']['fields']) ?>;

        function getLabelForName(name) {
            const field = formSchema.find(f => f.name === name);
            return field && field.label ? field.label : name.replace(/_/g, ' ');
        }

        function viewData(jsonString, applicantName, appId, notes) {
            try {
                const data = typeof jsonString === 'string' ? JSON.parse(jsonString) : jsonString;
                document.getElementById('modalTitle').innerText = applicantName + "'s Application";
                
                const viewer = document.getElementById('jsonViewer');
                viewer.innerHTML = ''; // clear
                
                for (const [key, value] of Object.entries(data)) {
                    const row = document.createElement('div');
                    row.className = 'data-row';
                    
                    const label = document.createElement('div');
                    label.className = 'data-label';
                    label.innerText = getLabelForName(key);
                    
                    const val = document.createElement('div');
                    val.className = 'data-value';
                    
                    if (value && typeof value === 'string' && value.startsWith('uploads/')) {
                        const link = document.createElement('a');
                        link.href = '/' + value;
                        link.target = '_blank';
                        link.style.color = 'var(--primary-color)';
                        link.style.textDecoration = 'underline';
                        link.innerText = 'View Uploaded File \u2197';
                        val.appendChild(link);
                    } else {
                        val.innerText = value || '-';
                    }
                    
                    row.appendChild(label);
                    row.appendChild(val);
                    viewer.appendChild(row);
                }

                // Wire up Internal Notes for this application
                document.getElementById('noteAppId').value = appId;

                const notesContainer = document.getElementById('notesContainer');
                notesContainer.innerHTML = '';
                if (!notes || notes.length === 0) {
                    notesContainer.innerHTML = '<p style="color:#94a3b8; font-size:14px;">No notes yet.</p>';
                } else {
                    notes.forEach(note => {
                        const noteEl = document.createElement('div');
                        noteEl.style.cssText = 'background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:8px; border:1px solid #e2e8f0;';
                        noteEl.innerHTML = `<div style="font-size:13px; color:#64748b; margin-bottom:4px;">${note.admin_email_snapshot} &middot; ${new Date(note.created_at).toLocaleString()}</div><div style="font-size:14px;">${note.note_text}</div>`;
                        notesContainer.appendChild(noteEl);
                    });
                }
                
                document.getElementById('dataModal').style.display = "block";
            } catch (e) {
                console.error(e);
            }
        }

        function closeModal() {
            document.getElementById('dataModal').style.display = "none";
        }

        window.onclick = function(event) {
            const modal = document.getElementById('dataModal');
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
        
        function copyToClipboard(text, btn) {
            navigator.clipboard.writeText(text).then(() => {
                const originalText = btn.innerText;
                btn.innerText = "Copied!";
                btn.style.background = "#d1fae5";
                btn.style.color = "#065f46";
                btn.style.borderColor = "#34d399";
                setTimeout(() => {
                    btn.innerText = originalText;
                    btn.style.background = "transparent";
                    btn.style.color = "var(--primary-color)";
                    btn.style.borderColor = "var(--primary-color)";
                }, 2000);
            }).catch(err => {
                alert("Failed to copy link.");
            });
        }
    </script>
</body>
</html>