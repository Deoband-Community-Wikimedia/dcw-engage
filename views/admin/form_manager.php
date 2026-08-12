<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/ApplicationModel.php';
require_once __DIR__ . '/../../models/NotesModel.php';

Auth::requireLogin();

$formId = $_GET['id'] ?? null;
if (!$formId)
    die("Form ID missing.");

$formModel = new FormModel();
$appModel = new ApplicationModel();
$notesModel = new NotesModel();

$form = $formModel->getFormById($formId);
if (!$form)
    die("Form not found.");

// Determine select-type fields in the schema (used to build filter dropdowns).
// Defined early so it's available both to buildFilterQueryString() below
// and to the POST handler's redirects further down.
$selectFields = array_filter($form['schema']['fields'] ?? [], fn($f) => ($f['type'] ?? '') === 'select');

/**
 * Rebuilds the current filter query string (status + any dynamic field filters)
 * so filters can be preserved across redirects after actions like bulk update.
 */
function buildFilterQueryString()
{
    global $selectFields;
    $params = [];
    if (!empty($_GET['status']))
        $params['status'] = $_GET['status'];
    foreach ($selectFields as $sf) {
        $paramName = 'filter_' . $sf['name'];
        if (!empty($_GET[$paramName]))
            $params[$paramName] = $_GET[$paramName];
    }
    return $params ? '&' . http_build_query($params) : '';
}

$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token']))
        die("Invalid CSRF");

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
            $targetAppId = $_POST['application_id'];
            $newStatus = $_POST['status'];
            $applicantNote = trim($_POST['applicant_note'] ?? '');
            $appModel->updateStatus($targetAppId, $newStatus);

            // Let the applicant know the moment a decision is made. Not fired
            // for 'New' since that's just the default/unreviewed state, not
            // an outcome.
            if (in_array($newStatus, ['Under Review', 'Accepted', 'Rejected'])) {
                $target = $appModel->getApplicationById($targetAppId);
                if ($target) {
                    require_once __DIR__ . '/../../includes/mailer.php';
                    $trackingId = 'DCW-' . str_pad($target['id'], 5, '0', STR_PAD_LEFT);
                    Mailer::sendStatusUpdate($target['email'], $target['applicant_name'], $newStatus, $trackingId, $target['form_title'] ?? $form['title'], $applicantNote);
                }
            }

            header("Location: /admin/form_manager?id=" . $formId);
            exit;
        } elseif ($_POST['action'] === 'bulk_update_status') {
            $selectedIds = $_POST['application_ids'] ?? [];
            $newBulkStatus = $_POST['bulk_status'] ?? '';
            $applicantNote = trim($_POST['bulk_applicant_note'] ?? '');
            if (!empty($selectedIds) && !empty($newBulkStatus)) {
                $appModel->updateStatusBulk($selectedIds, $newBulkStatus, $formId);

                if (in_array($newBulkStatus, ['Under Review', 'Accepted', 'Rejected'])) {
                    require_once __DIR__ . '/../../includes/mailer.php';
                    foreach ($selectedIds as $targetAppId) {
                        $target = $appModel->getApplicationById($targetAppId);
                        if ($target) {
                            $trackingId = 'DCW-' . str_pad($target['id'], 5, '0', STR_PAD_LEFT);
                            Mailer::sendStatusUpdate($target['email'], $target['applicant_name'], $newBulkStatus, $trackingId, $target['form_title'] ?? $form['title'], $applicantNote);
                        }
                    }
                }
            }
            header("Location: /admin/form_manager?id=" . $formId . buildFilterQueryString());
            exit;
        } elseif ($_POST['action'] === 'add_note') {
            $noteText = trim($_POST['note_text'] ?? '');
            $noteAppId = $_POST['application_id'] ?? '';
            if (!empty($noteText) && !empty($noteAppId)) {
                $notesModel->addNote(
                    $noteAppId,
                    Auth::id(),
                    Auth::email(),
                    $noteText
                );
                header("Location: /admin/form_manager?id=" . $formId);
                exit;
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

// Read filters from query string
$filterStatus = $_GET['status'] ?? '';
$activeFieldFilters = [];
foreach ($selectFields as $sf) {
    $paramName = 'filter_' . $sf['name'];
    if (!empty($_GET[$paramName])) {
        $activeFieldFilters[$sf['name']] = $_GET[$paramName];
    }
}

// Apply status filter
if (!empty($filterStatus)) {
    $applications = array_filter($applications, fn($app) => $app['status'] === $filterStatus);
}

// Apply dynamic field filters (e.g. club/role), reading from form_data JSON
if (!empty($activeFieldFilters)) {
    $applications = array_filter($applications, function ($app) use ($activeFieldFilters) {
        $data = json_decode($app['form_data'], true);
        foreach ($activeFieldFilters as $fieldName => $expectedValue) {
            if (!isset($data[$fieldName]) || $data[$fieldName] !== $expectedValue) {
                return false;
            }
        }
        return true;
    });
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($form['title']) ?> - Form Manager</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../../assets/css/admin.css') ?>">
</head>

<body>
    <div class="container">
        <div style="margin-bottom: 20px;">
            <a href="/admin/dashboard"
                style="color: var(--primary-color); text-decoration: none; font-weight: 600;">&larr; Back to
                Workspace</a>
        </div>

        <?php if ($success): ?>
            <div
                style="background: #d1fae5; color: #065f46; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #34d399;">
                <?= $success ?>
            </div><?php endif; ?>

        <div class="header-card">
            <div>
                <h1><?= htmlspecialchars($form['title']) ?></h1>
                <p class="meta" style="display: flex; align-items: center; gap: 10px;">
                    URL Endpoint: <strong>/<?= htmlspecialchars($form['form_type']) ?></strong>
                    <button type="button" class="copy-btn"
                        onclick="copyToClipboard('<?= 'http://' . $_SERVER['HTTP_HOST'] . '/' . $form['form_type'] ?>', this)">
                        <svg style="width:13px; height:13px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                        </svg>
                        <span>Copy Link</span>
                    </button>
                    &bull; Total Responses: <strong><?= count($applications) ?></strong>
                </p>
            </div>
            <div class="controls">
                <a href="?id=<?= $formId ?>&action=export" class="btn btn-outline">
                    <svg style="width:16px; height:16px; vertical-align: middle; margin-right:5px;" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
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

        <form method="GET" class="filter-bar"
            style="display:flex; gap:10px; align-items:center; margin-bottom:15px; flex-wrap:wrap;">
            <input type="hidden" name="id" value="<?= $formId ?>">

            <select name="status" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="New" <?= $filterStatus === 'New' ? 'selected' : '' ?>>New</option>
                <option value="Under Review" <?= $filterStatus === 'Under Review' ? 'selected' : '' ?>>Under Review
                </option>
                <option value="Accepted" <?= $filterStatus === 'Accepted' ? 'selected' : '' ?>>Accepted</option>
                <option value="Rejected" <?= $filterStatus === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>

            <?php foreach ($selectFields as $sf):
                $paramName = 'filter_' . $sf['name'];
                $currentVal = $_GET[$paramName] ?? '';
                ?>
                <select name="<?= htmlspecialchars($paramName) ?>" onchange="this.form.submit()">
                    <option value="">All <?= htmlspecialchars($sf['label'] ?? $sf['name']) ?></option>
                    <?php foreach ($sf['options'] ?? [] as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $currentVal === $opt ? 'selected' : '' ?>>
                            <?= htmlspecialchars($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endforeach; ?>

            <?php if (!empty($filterStatus) || !empty($activeFieldFilters)): ?>
                <a href="?id=<?= $formId ?>" class="btn btn-sm btn-outline">Clear Filters</a>
            <?php endif; ?>
        </form>


        <form method="POST" id="bulkForm" class="bulk-bar" onsubmit="return prepareBulkSubmit()"
            style="display:none; gap:10px; align-items:center; margin-bottom:15px;">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="action" value="bulk_update_status">
            <div id="bulkIdsContainer"></div>
            <span id="bulkCount" style="font-weight:600; color:#1e293b;"></span>
            <select name="bulk_status" required>
                <option value="">Set status to...</option>
                <option value="New">New</option>
                <option value="Under Review">Under Review</option>
                <option value="Accepted">Accepted</option>
                <option value="Rejected">Rejected</option>
            </select>
            <input type="text" name="bulk_applicant_note" placeholder="Optional note to include in the applicant email" style="width:260px; padding:6px 10px; font-size:13px;">
            <button type="submit" class="btn btn-sm btn-primary">Apply to Selected</button>
        </form>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                        <th>Tracking ID</th>
                        <th>Applicant Name</th>
                        <th>Status</th>
                        <th>Date Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($applications)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding: 30px; color: #64748b;">No responses match the
                                current filters.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($applications as $app):
                        $statusClass = 'status-' . str_replace(' ', '-', $app['status']);
                        ?>
                        <tr>
                            <td><input type="checkbox" class="row-checkbox" value="<?= $app['id'] ?>"
                                    onchange="updateBulkBar()"></td>
                            <td style="font-family: monospace; font-weight: 600;">
                                DCW-<?= str_pad($app['id'], 5, '0', STR_PAD_LEFT) ?></td>
                            <td style="font-weight: 500;"><?= htmlspecialchars($app['applicant_name']) ?></td>
                            <td><span
                                    class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($app['status']) ?></span>
                            </td>
                            <td style="color: #64748b;"><?= date('M j, Y H:i', strtotime($app['created_at'])) ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary"
                                    onclick='viewData(<?= json_encode($app['form_data'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, "<?= htmlspecialchars($app['applicant_name'], ENT_QUOTES) ?>", <?= $app['id'] ?>, <?= json_encode($notesModel->getNotesByApplication($app['id']), JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>View
                                    Data</button>

                                <form method="POST" style="display:inline-flex; gap:6px; align-items:center; margin-left:10px;">
                                    <?= CSRF::getInputField() ?>
                                    <input type="hidden" name="action" value="update_applicant_status">
                                    <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                    <select name="status">
                                        <option value="New" <?= $app['status'] == 'New' ? 'selected' : '' ?>>New</option>
                                        <option value="Under Review" <?= $app['status'] == 'Under Review' ? 'selected' : '' ?>>
                                            Under Review (Lock)</option>
                                        <option value="Accepted" <?= $app['status'] == 'Accepted' ? 'selected' : '' ?>>Accepted
                                        </option>
                                        <option value="Rejected" <?= $app['status'] == 'Rejected' ? 'selected' : '' ?>>Rejected
                                        </option>
                                    </select>
                                    <!-- Note is a one-off addition to the outcome email only — not the
                                         persisted internal Notes thread above (that stays admin-only). -->
                                    <input type="text" name="applicant_note" placeholder="Optional note to applicant" style="width:150px; padding:4px 8px; font-size:13px;">
                                    <button type="submit" class="btn btn-sm btn-outline">Apply</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Data Viewer Modal -->
    <div id="dataModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle" style="margin-top:0; color: var(--primary-color); margin-bottom: 20px;">Applicant Data
            </h2>
            <div id="jsonViewer" class="data-grid"></div>

            <hr style="margin: 25px 0; border: none; border-top: 1px solid #e2e8f0;">

            <h3 style="margin-bottom: 15px; color: var(--primary-color);">Internal Notes</h3>

            <div id="notesContainer" style="max-height: 200px; overflow-y: auto; margin-bottom: 15px;"></div>

            <form method="POST" id="noteForm">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="application_id" id="noteAppId" value="">
                <textarea name="note_text" rows="3" placeholder="Add an internal note (only visible to organizers)..."
                    required
                    style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0; font-family: inherit;"></textarea>
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
                    notesContainer.innerHTML =
                        '<p style="color:#94a3b8; font-size:14px;">No notes yet.</p>';
                } else {
                    notes.forEach(note => {
                        const noteEl = document.createElement('div');
                        noteEl.style.cssText =
                            'background:#f8fafc; padding:10px; border-radius:6px; margin-bottom:8px; border:1px solid #e2e8f0;';

                        const meta = document.createElement('div');
                        meta.style.cssText =
                            'font-size:13px; color:#64748b; margin-bottom:4px;';
                        meta.textContent =
                            `${note.admin_email_snapshot} · ${new Date(note.created_at.replace(' ', 'T')).toLocaleString()}`;

                        const body = document.createElement('div');
                        body.style.cssText =
                            'font-size:14px; white-space:pre-wrap;';
                        body.textContent = note.note_text;

                        noteEl.appendChild(meta);
                        noteEl.appendChild(body);

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

        window.onclick = function (event) {
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

        // --- Bulk selection logic ---
        function toggleSelectAll(source) {
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = source.checked);
            updateBulkBar();
        }

        function updateBulkBar() {
            const checked = document.querySelectorAll('.row-checkbox:checked');
            const bulkForm = document.getElementById('bulkForm');
            const bulkCount = document.getElementById('bulkCount');
            if (checked.length > 0) {
                bulkForm.style.display = 'flex';
                bulkCount.innerText = checked.length + ' selected';
            } else {
                bulkForm.style.display = 'none';
            }

            // Keep "select all" checkbox in sync if some/none/all rows are checked
            const all = document.querySelectorAll('.row-checkbox');
            const selectAll = document.getElementById('selectAll');
            selectAll.checked = all.length > 0 && checked.length === all.length;
        }

        function prepareBulkSubmit() {
            const checked = document.querySelectorAll('.row-checkbox:checked');
            const container = document.getElementById('bulkIdsContainer');
            container.innerHTML = '';
            checked.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'application_ids[]';
                input.value = cb.value;
                container.appendChild(input);
            });
            return checked.length > 0;
        }
    </script>
</body>

</html>