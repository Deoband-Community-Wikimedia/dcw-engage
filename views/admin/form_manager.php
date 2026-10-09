<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/ApplicationModel.php';
require_once __DIR__ . '/../../models/NotesModel.php';

// The workspace page only hides the forms grid from other roles; that is
// tidiness, not security. This is the actual gate: everything below can read
// every applicant's data, change statuses, email applicants, and close or
// delete the form. Finance and support staff have no business here.
Auth::requireLogin();
// Reviewers and chapter coordinators get in the door, but only for membership
// forms: the per-form check below (FormModel::userCanOpen) is what keeps them
// off the rest, and keeps a coordinator to their own chapters.
requireRole(['owner', 'organizer', 'membership_reviewer', 'membership_coordinator']);

$formId = $_GET['id'] ?? null;
if (!$formId)
    die("Form ID missing.");

$formModel = new FormModel();
$appModel = new ApplicationModel();
$notesModel = new NotesModel();

$form = $formModel->getFormById($formId);
if (!$form)
    die("Form not found.");

// Membership forms: membership reviewers and owners (every chapter), and
// membership coordinators (their own chapters only). Every other form:
// organizers and owners only. Same response as a missing form, so nobody can
// tell whether a form they can't open exists. The dashboard hiding tiles is
// only tidiness; this is the real check.
if (!FormModel::userCanOpen($form)) {
    http_response_code(404);
    die("Form not found.");
}

// Anyone who can open this form may close and re-open it, and set, extend or
// remove its deadline (extending a deadline re-opens a form that closed by
// deadline, so it carries the same permission as re-opening). Deleting stays
// with organizers and owners (owners only for membership forms); editing the
// schema also goes to the chapter's coordinator for membership forms.
$canManageForm = FormModel::userCanDelete($form);
$canEditSchema = FormModel::userCanEdit($form);

// Membership forms are view/export/notes only here; decisions happen in
// /admin/membership-review (see the POST guard below).
$isMembershipForm = FormModel::isMembershipType($form['form_type']);

// Statuses this page is allowed to set. Anything else in a POST is ignored.
$allowedStatuses = ['New', 'Under Review', 'Accepted', 'Rejected'];

// Ids of the applications that belong to THIS form, and each one's current status.
// The model calls below take an application id on its own, so without this check a
// crafted POST could change, email about, or annotate an application from another form.
// A Draft is still being written (or was sent back) by the applicant: it cannot be decided
// here, so Draft ids are refused by the status handlers below.
$formApplications = $appModel->getApplicationsByFormId($formId);
$ownedIds = array_map('intval', array_column($formApplications, 'id'));
$statusById = [];
foreach ($formApplications as $a) $statusById[(int) $a['id']] = $a['status'];

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
$deadlineError = '';

// Confirmation shown after a deadline change (set by the redirect below).
if (($_GET['deadline'] ?? '') === 'saved') {
    $success = 'Deadline updated.';
} elseif (($_GET['deadline'] ?? '') === 'removed') {
    $success = 'Deadline removed.';
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? ''))
        die("Invalid CSRF");

    if (isset($_POST['action'])) {
        // Close/re-open is open to anyone who can open this form (so reviewers and
        // coordinators can close and re-open membership forms). Deleting stays with managers.
        if ($_POST['action'] === 'delete_form' && !$canManageForm) {
            http_response_code(403);
            die("Not allowed.");
        }

        // Membership applications are handled only in Membership review, which
        // creates the member record, logs the decision and sends the right email.
        // Status changes and notes are refused here for membership forms.
        if ($isMembershipForm && in_array($_POST['action'], ['update_applicant_status', 'bulk_update_status', 'add_note'], true)) {
            http_response_code(403);
            die("Membership applications are handled in Membership review.");
        }

        if ($_POST['action'] === 'toggle_form') {
            $newStatus = ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
            $formModel->toggleFormStatus($formId, $newStatus);
            header("Location: /admin/form_manager?id=" . $formId);
            exit;
        } elseif ($_POST['action'] === 'extend_deadline' || $_POST['action'] === 'remove_deadline') {
            // The model re-checks permission and that the new deadline is in the future
            // and later than the current one. Its messages are written to be shown.
            $removing = $_POST['action'] === 'remove_deadline';
            try {
                $formModel->extendDeadline($formId, $removing ? null : trim((string) ($_POST['new_deadline'] ?? '')));
                header("Location: /admin/form_manager?id=" . $formId . "&deadline=" . ($removing ? 'removed' : 'saved'));
                exit;
            } catch (InvalidArgumentException $e) {
                $deadlineError = $e->getMessage();
            } catch (Exception $e) {
                http_response_code(403);
                die("Not allowed.");
            }
        } elseif ($_POST['action'] === 'delete_form') {
            $formModel->deleteForm($formId);
            header("Location: /admin/dashboard");
            exit;
        } elseif ($_POST['action'] === 'update_applicant_status') {
            $targetAppId = (int) ($_POST['application_id'] ?? 0);
            $newStatus = $_POST['status'] ?? '';
            $applicantNote = trim($_POST['applicant_note'] ?? '');

            if (
                in_array($targetAppId, $ownedIds, true)
                && ($statusById[$targetAppId] ?? '') !== 'Draft'
                && in_array($newStatus, $allowedStatuses, true)
            ) {
                $appModel->updateStatus($targetAppId, $newStatus);

                // Let the applicant know the moment a decision is made. Not fired
                // for 'New' since that's just the default/unreviewed state, not
                // an outcome.
                if (in_array($newStatus, ['Under Review', 'Accepted', 'Rejected'])) {
                    $target = $appModel->getApplicationById($targetAppId);
                    if ($target) {
                        require_once __DIR__ . '/../../includes/mailer.php';
                        $trackingId = $target['tracking_id'];
                        Mailer::sendStatusUpdate($target['email'], $target['applicant_name'], $newStatus, $trackingId, $target['form_title'] ?? $form['title'], $applicantNote);
                    }
                }
            }

            header("Location: /admin/form_manager?id=" . $formId . buildFilterQueryString());
            exit;
        } elseif ($_POST['action'] === 'bulk_update_status') {
            // Only ids that really belong to this form, as integers, and never Drafts.
            $selectedIds = array_values(array_filter(
                array_intersect(
                    array_map('intval', (array) ($_POST['application_ids'] ?? [])),
                    $ownedIds
                ),
                fn($i) => ($statusById[$i] ?? '') !== 'Draft'
            ));
            $newBulkStatus = $_POST['bulk_status'] ?? '';
            $applicantNote = trim($_POST['bulk_applicant_note'] ?? '');
            if (!empty($selectedIds) && in_array($newBulkStatus, $allowedStatuses, true)) {
                @set_time_limit(300);   // each decision sends an email
                $appModel->updateStatusBulk($selectedIds, $newBulkStatus, $formId);

                if (in_array($newBulkStatus, ['Under Review', 'Accepted', 'Rejected'])) {
                    require_once __DIR__ . '/../../includes/mailer.php';
                    foreach ($selectedIds as $targetAppId) {
                        $target = $appModel->getApplicationById($targetAppId);
                        if ($target) {
                            $trackingId = $target['tracking_id'];
                            try {
                                Mailer::sendStatusUpdate($target['email'], $target['applicant_name'], $newBulkStatus, $trackingId, $target['form_title'] ?? $form['title'], $applicantNote);
                            } catch (Throwable $e) {
                                // One failed email must not stop the rest; the status change is already saved.
                            }
                        }
                    }
                }
            }
            header("Location: /admin/form_manager?id=" . $formId . buildFilterQueryString());
            exit;
        } elseif ($_POST['action'] === 'add_note') {
            $noteText = trim($_POST['note_text'] ?? '');
            $noteAppId = (int) ($_POST['application_id'] ?? 0);
            if (!empty($noteText) && in_array($noteAppId, $ownedIds, true)) {
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

// Handle CSV Export (not available for membership forms)
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    if ($isMembershipForm) {
        http_response_code(403);
        die("Membership responses are not exported from here.");
    }
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
            $app['tracking_id'] ?? 'N/A',
            $app['status'],
            date('Y-m-d H:i', strtotime($app['created_at']))
        ];

        $data = json_decode($app['form_data'], true);
        foreach ($fields as $fieldName) {
            $val = $data[$fieldName] ?? '';
            $row[] = is_array($val) ? implode(', ', $val) : $val;
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

// Safe copy of the id for use inside HTML attributes.
$fid = htmlspecialchars((string) $formId, ENT_QUOTES, 'UTF-8');

// Deadline state for the header and the deadline section.
// $form was loaded before any POST; after a successful change the page redirects,
// so this always reflects the database on a normal render.
$deadlineAt = $form['deadline_at'] ?? null;
$hasDeadline = !empty($deadlineAt);
$deadlinePassed = FormModel::closedByDeadline($form);                 // switched on, but past its deadline
$switchedOff = !(int) $form['is_active'];                              // closed by hand
$deadlineLabel = $hasDeadline ? date('j M Y, g:i A', strtotime($deadlineAt)) : '';
// Pre-fill the extend box with the current deadline (or nothing) in datetime-local format.
$deadlineInputValue = ($hasDeadline && !$deadlinePassed) ? date('Y-m-d\TH:i', strtotime($deadlineAt)) : '';
$deadlineMin = date('Y-m-d\TH:i');

// Public link to the form: the configured site address when there is one (so it is https on a live site),
// otherwise the address this page was opened on.
$appConfig = require __DIR__ . '/../../includes/config.php';
$baseUrl = rtrim((string) ($appConfig['app']['url'] ?? ''), '/');
if ($baseUrl === '') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $baseUrl = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
}
$publicUrl = $baseUrl . '/' . $form['form_type'];

engage_header([
    'title'   => $form['title'] . ' - Form Manager',
    'heading' => $form['title'],
    'kicker'  => 'Form manager',
    'tools'   => '',
    'wide'    => true,
    'crumbs'  => [['Workspace', '/admin/dashboard'], [$form['title']]],
]);
?>
<style>
    /* Form manager only. Everything else comes from /assets/css/engage.css */
    .sect { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 22px 24px; margin: 0 0 22px; box-shadow: 0 16px 34px rgba(15,23,42,.12); }
    .sect select, .sect input[type=text], .sect input[type=datetime-local] {
        padding: 8px 12px; background: #fff; color: var(--ink);
        border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 14px;
    }
    .sect select:focus, .sect input[type=text]:focus, .sect input[type=datetime-local]:focus, .modal textarea:focus { outline: 2px solid var(--primary); outline-offset: -1px; border-color: transparent; }
    .btn-ghost.sm, .btn-solid.sm { width: auto; padding: 7px 16px; font-size: 13.5px; }
    a.btn-ghost { display: inline-block; text-decoration: none; }

    .head-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px 24px; }
    .head-row .meta { margin: 0; display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; font-size: 14px; color: var(--muted); }
    .head-row .controls { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .head-row .controls form { margin: 0; }
    .copy-btn {
        display: inline-flex; align-items: center; gap: 6px; padding: 3px 12px; border-radius: 999px; cursor: pointer;
        font: inherit; font-size: 12.5px; font-weight: 700; color: var(--primary); background: transparent; border: 1px solid var(--primary);
    }
    .copy-btn svg { width: 13px; height: 13px; }

    /* Deadline */
    .deadline-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px 24px; }
    .deadline-row .state { margin: 0; font-size: 14.5px; color: var(--muted); }
    .deadline-row .state strong { color: var(--ink); }
    .deadline-row .state .closed-tag { display: inline-block; margin-right: 8px; padding: 2px 10px; border-radius: 999px; font-size: 12.5px; font-weight: 700; color: #991b1b; background: #fef2f2; border: 1px solid #f87171; }
    .deadline-row .state .open-tag { display: inline-block; margin-right: 8px; padding: 2px 10px; border-radius: 999px; font-size: 12.5px; font-weight: 700; color: var(--leaf-dark); background: var(--leaf-tint); border: 1px solid var(--leaf); }
    .deadline-row .dl-controls { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .deadline-row .dl-controls form { margin: 0; display: inline-flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .deadline-note { margin: 10px 0 0; font-size: 13px; color: var(--muted); }

    .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 16px; }
    .bulkbar { display: none; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 14px; padding: 12px 14px; background: #fff; border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 4px 12px rgba(15,23,42,.08); }
    .bulkbar #bulkCount { font-size: 14px; font-weight: 700; color: var(--muted); }
    .bulkbar input[type=text] { width: 280px; max-width: 100%; }
    .rowform { display: inline-flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0 0 0 10px; }
    .rowform select { padding: 6px 8px; font-size: 13px; }
    .rowform input[type=text] { width: 170px; padding: 6px 10px; font-size: 13px; }
    .tbl td.id { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 700; }
    .tbl td.when { color: var(--muted); white-space: nowrap; }
    .tbl td input[type=checkbox], .tbl th input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--primary); cursor: pointer; }
    .tbl td input[type=checkbox]:disabled { cursor: not-allowed; }
    .tbl td.act { white-space: nowrap; }
    .draft-note { margin-left: 10px; font-size: 13px; color: var(--muted); }

    .pill.status-New { --tone: var(--primary); }
    .pill.status-Under-Review { --tone: #6d28d9; }
    .pill.status-Draft { --tone: #b45309; }
    .pill.status-Accepted { --tone: var(--leaf-dark); }
    .pill.status-Rejected { --tone: var(--accent); }

    /* Applicant data modal */
    .modal { display: none; position: fixed; inset: 0; z-index: 50; padding: 40px 16px; overflow: auto; background: rgba(15,23,42,.55); }
    .modal-content { position: relative; max-width: 720px; margin: 0 auto; padding: 28px; background: #fff; border-radius: 16px; box-shadow: 0 24px 60px rgba(15,23,42,.35); }
    .close-btn { position: absolute; top: 10px; right: 18px; font-size: 30px; line-height: 1; color: var(--muted); cursor: pointer; }
    .close-btn:hover { color: var(--ink); }
    .modal h2 { margin: 0 0 18px; color: var(--primary); font-size: 22px; font-weight: 800; }
    .modal h3 { margin: 0 0 12px; color: var(--primary); font-size: 17px; font-weight: 800; }
    .modal hr { margin: 24px 0; border: none; border-top: 1px solid var(--border); }
    .data-row { padding: 10px 0; border-bottom: 1px solid var(--border); }
    .data-label { font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
    .data-value { margin-top: 2px; white-space: pre-wrap; word-break: break-word; }
    .modal textarea { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 14.5px; }
</style>

<?php if ($success): ?><div class="alert ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($deadlineError): ?><div class="alert error"><?= htmlspecialchars($deadlineError) ?></div><?php endif; ?>

<section class="sect head-row">
    <p class="meta">
        <span>URL endpoint: <strong>/<?= htmlspecialchars($form['form_type']) ?></strong></span>
        <button type="button" class="copy-btn" data-url="<?= htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') ?>"
            onclick="copyToClipboard(this.dataset.url, this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
            </svg>
            <span>Copy link</span>
        </button>
        <span>&bull; Total responses: <strong><?= count($applications) ?></strong></span>
    </p>
    <div class="controls">
        <?php if (!$isMembershipForm): ?>
            <a href="/admin/application-review?form=<?= urlencode($form['form_type']) ?>" class="btn-ghost">Application review</a>
            <a href="?id=<?= $fid ?>&action=export" class="btn-ghost">Export CSV</a>
        <?php endif; ?>
        <?php if ($canEditSchema): ?>
            <a href="/admin/builder?edit=<?= $fid ?>" class="btn-ghost">Edit schema</a>
        <?php endif; ?>

        <?php if ($deadlinePassed): ?>
            <?php /* Switched on but past its deadline: re-opening is done by extending the deadline below. */ ?>
            <span class="draft-note" style="margin:0;">Closed by deadline. Extend it below to re-open.</span>
        <?php else: ?>
            <form method="POST">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="toggle_form">
                <?php if ($form['is_active']): ?>
                    <input type="hidden" name="is_active" value="0">
                    <button type="submit" class="btn-danger-ghost">Close form</button>
                <?php else: ?>
                    <input type="hidden" name="is_active" value="1">
                    <button type="submit" class="btn-ok">Re-open form</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="sect">
    <div class="deadline-row">
        <p class="state">
            <?php if ($deadlinePassed): ?>
                <span class="closed-tag">Closed</span>Deadline passed on <strong><?= htmlspecialchars($deadlineLabel) ?></strong>.
            <?php elseif ($hasDeadline): ?>
                <span class="<?= $switchedOff ? 'closed-tag' : 'open-tag' ?>"><?= $switchedOff ? 'Closed' : 'Open' ?></span>Closes automatically on <strong><?= htmlspecialchars($deadlineLabel) ?></strong>.
            <?php else: ?>
                <span class="<?= $switchedOff ? 'closed-tag' : 'open-tag' ?>"><?= $switchedOff ? 'Closed' : 'Open' ?></span>No deadline. The form stays open until you close it.
            <?php endif; ?>
        </p>

        <div class="dl-controls">
            <form method="POST">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="extend_deadline">
                <input type="datetime-local" name="new_deadline" value="<?= htmlspecialchars($deadlineInputValue) ?>"
                    min="<?= htmlspecialchars($deadlineMin) ?>" required aria-label="New deadline">
                <button type="submit" class="btn-solid sm"><?= $hasDeadline ? 'Extend deadline' : 'Set deadline' ?></button>
            </form>
            <?php if ($hasDeadline): ?>
                <form method="POST" onsubmit="return confirm('Remove the deadline? The form will stay open until someone closes it.');">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="remove_deadline">
                    <button type="submit" class="btn-ghost sm">Remove deadline</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($switchedOff && $hasDeadline): ?>
        <p class="deadline-note">This form was closed by hand, so extending the deadline will not re-open it. Use "Re-open form" above.</p>
    <?php elseif ($deadlinePassed): ?>
        <p class="deadline-note">Picking a new date in the future re-opens the form straight away.</p>
    <?php endif; ?>
</section>

<?php if ($isMembershipForm): ?>
<section class="sect">
    <p style="margin:0; color: var(--muted); font-size: 14px;">
        Responses to membership forms are handled in
        <a href="/admin/membership-review">Membership review</a>, not here.
    </p>
</section>
<?php else: ?>
<section class="sect">
    <form method="GET" class="filters">
        <input type="hidden" name="id" value="<?= $fid ?>">

        <select name="status" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <option value="New" <?= $filterStatus === 'New' ? 'selected' : '' ?>>New</option>
            <option value="Under Review" <?= $filterStatus === 'Under Review' ? 'selected' : '' ?>>Under Review</option>
            <option value="Accepted" <?= $filterStatus === 'Accepted' ? 'selected' : '' ?>>Accepted</option>
            <option value="Rejected" <?= $filterStatus === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
            <option value="Draft" <?= $filterStatus === 'Draft' ? 'selected' : '' ?>>Awaiting applicant</option>
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
            <a href="?id=<?= $fid ?>" class="btn-ghost sm">Clear filters</a>
        <?php endif; ?>
    </form>

    <form method="POST" id="bulkForm" class="bulkbar" onsubmit="return prepareBulkSubmit()">
        <?= CSRF::getInputField() ?>
        <input type="hidden" name="action" value="bulk_update_status">
        <div id="bulkIdsContainer"></div>
        <span id="bulkCount"></span>
        <select name="bulk_status" required>
            <option value="">Set status to...</option>
            <option value="New">New</option>
            <option value="Under Review">Under Review</option>
            <option value="Accepted">Accepted</option>
            <option value="Rejected">Rejected</option>
        </select>
        <input type="text" name="bulk_applicant_note" placeholder="Optional note to include in the applicant email">
        <button type="submit" class="btn-solid sm">Apply to selected</button>
    </form>

    <div class="tbl-wrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                    <th>Tracking ID</th>
                    <th>Applicant name</th>
                    <th>Status</th>
                    <th>Date submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($applications)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding: 30px; color: var(--muted);">No responses match the
                            current filters.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($applications as $app):
                    $statusClass = 'status-' . str_replace(' ', '-', $app['status']);
                    $isDraft = $app['status'] === 'Draft';
                    // Everything the "View data" window needs, as ONE JSON value in a data attribute.
                    // htmlspecialchars() makes it safe inside the attribute, and the browser hands the exact
                    // JSON back, so nothing an applicant typed can ever run as code.
                    $viewPayload = json_encode([
                        'data'  => $app['form_data'],
                        'name'  => $app['applicant_name'],
                        'id'    => (int) $app['id'],
                        'notes' => $notesModel->getNotesByApplication($app['id']),
                    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                    ?>
                    <tr>
                        <td><input type="checkbox" class="row-checkbox" value="<?= (int) $app['id'] ?>"
                                onchange="updateBulkBar()"
                                <?= $isDraft ? 'disabled title="The applicant is still working on this"' : '' ?>></td>
                        <td class="id"><?= htmlspecialchars($app['tracking_id'] ?? 'N/A') ?></td>
                        <td style="font-weight: 600;"><?= htmlspecialchars($app['applicant_name']) ?></td>
                        <td><span class="pill status-badge <?= $statusClass ?>"><?= htmlspecialchars($isDraft ? 'Awaiting applicant' : $app['status']) ?></span></td>
                        <td class="when"><?= date('M j, Y H:i', strtotime($app['created_at'])) ?></td>
                        <td class="act">
                            <button type="button" class="btn-solid sm"
                                data-view="<?= htmlspecialchars($viewPayload, ENT_QUOTES, 'UTF-8') ?>"
                                onclick="viewData(JSON.parse(this.dataset.view))">View data</button>

                            <?php if ($isDraft): ?>
                                <span class="draft-note">Waiting for the applicant to submit</span>
                            <?php else: ?>
                            <form method="POST" class="rowform">
                                <?= CSRF::getInputField() ?>
                                <input type="hidden" name="action" value="update_applicant_status">
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
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
                                <input type="text" name="applicant_note" placeholder="Optional note to applicant">
                                <button type="submit" class="btn-ghost sm">Apply</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Data Viewer Modal -->
<div id="dataModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal()">&times;</span>
        <h2 id="modalTitle">Applicant data</h2>
        <div id="jsonViewer" class="data-grid"></div>

        <hr>

        <h3>Internal notes</h3>

        <div id="notesContainer" style="max-height: 200px; overflow-y: auto; margin-bottom: 15px;"></div>

        <form method="POST" id="noteForm">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="action" value="add_note">
            <input type="hidden" name="application_id" id="noteAppId" value="">
            <textarea name="note_text" rows="3" placeholder="Add an internal note (only visible to organizers)..."
                required></textarea>
            <button type="submit" class="btn-solid sm" style="margin-top: 10px;">Add note</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    // Use the schema from PHP to properly map keys to labels if possible
    const formSchema = <?= json_encode($form['schema']['fields'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    function getLabelForName(name) {
        const field = formSchema.find(f => f.name === name);
        return field && field.label ? field.label : name.replace(/_/g, ' ');
    }

    // One object per application: { data, name, id, notes } (see data-view on the "View data" button).
    function viewData(a) {
        const jsonString = a.data, applicantName = a.name, appId = a.id, notes = a.notes;
        try {
            const data = typeof jsonString === 'string' ? JSON.parse(jsonString) : jsonString;
            document.getElementById('modalTitle').innerText = applicantName + "'s application";

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
                    link.style.color = 'var(--primary)';
                    link.style.textDecoration = 'underline';
                    link.innerText = 'View Uploaded File \u2197';
                    val.appendChild(link);
                } else {
                    val.innerText = Array.isArray(value) ? value.join(', ') : (value || '-');
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
                        'background:#f8fafc; padding:10px; border-radius:10px; margin-bottom:8px; border:1px solid #e2e8f0;';

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
        if (modal && event.target == modal) {
            modal.style.display = "none";
        }
    }

    function copyToClipboard(text, btn) {
        navigator.clipboard.writeText(text).then(() => {
            // Only the label changes, so the icon stays.
            const label = btn.querySelector('span');
            const originalText = label.innerText;
            label.innerText = "Copied!";
            btn.style.background = "var(--leaf-tint)";
            btn.style.color = "var(--leaf-dark)";
            btn.style.borderColor = "var(--leaf)";
            setTimeout(() => {
                label.innerText = originalText;
                btn.style.background = "transparent";
                btn.style.color = "var(--primary)";
                btn.style.borderColor = "var(--primary)";
            }, 2000);
        }).catch(err => {
            alert("Failed to copy link.");
        });
    }

    // --- Bulk selection logic (rows that cannot be decided, like Drafts, are disabled and never selected) ---
    function toggleSelectAll(source) {
        document.querySelectorAll('.row-checkbox:not(:disabled)').forEach(cb => cb.checked = source.checked);
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
        const all = document.querySelectorAll('.row-checkbox:not(:disabled)');
        const selectAll = document.getElementById('selectAll');
        selectAll.checked = all.length > 0 && checked.length === all.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
    }

    function prepareBulkSubmit() {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        if (checked.length === 0) return false;

        // Accepting or rejecting emails every selected applicant, so confirm.
        const status = document.querySelector('#bulkForm select[name=bulk_status]').value;
        if (status === 'Accepted' || status === 'Rejected') {
            const n = checked.length;
            if (!confirm(status + ' ' + n + ' application' + (n === 1 ? '' : 's') +
                '? Each applicant will be emailed.')) {
                return false;
            }
        }

        const container = document.getElementById('bulkIdsContainer');
        container.innerHTML = '';
        checked.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'application_ids[]';
            input.value = cb.value;
            container.appendChild(input);
        });
        return true;
    }
</script>
<?php engage_footer(); ?>
