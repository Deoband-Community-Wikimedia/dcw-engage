<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/ApplicationModel.php';
require_once __DIR__ . '/../../includes/mailer.php';   // Mailer::sendStatusUpdate(), the same email form_manager sends

/**
 * /admin/application-review: one queue for the responses to every ordinary (non-membership) form.
 * Membership forms are deliberately left out: they are handled in Membership review.
 *
 * Status rules (same ones ApplicationModel::saveApplication() enforces):
 *   open       New, Submitted, Under Review   -> can be decided here
 *   waiting    Draft                          -> the applicant is editing (or was sent back); nothing to decide
 *   decided    Accepted, Rejected             -> locked
 * Accept / Reject / Under Review email the applicant exactly as the form manager does (Mailer::sendStatusUpdate),
 * with the optional note added to that email.
 * "Needs information" sets the application back to Draft and makes a one-time link
 * (/resume/<token>) the applicant uses to edit and resubmit.
 */
requireRole(['organizer', 'owner']);

const APP_OPEN = ['New', 'Submitted', 'Under Review'];
// action name -> status it sets ('info' sets Draft and issues a link)
const APP_ACTIONS = ['review' => 'Under Review', 'approve' => 'Accepted', 'reject' => 'Rejected', 'info' => 'Draft'];
const BULK_MAX = 100;

$model = new ApplicationModel();
$formModel = new FormModel();

// Forms this person may review: ordinary forms only, and only ones FormModel says they can open.
$allForms = array_values(array_filter(
    $formModel->getAllForms(),
    fn($f) => !FormModel::isMembershipType($f['form_type']) && FormModel::userCanOpen($f)
));
$allowed = [];   // form id => true
foreach ($allForms as $f) $allowed[(int) $f['id']] = true;

$notice = ''; $error = '';
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$app = false;
if ($id) {
    $app = $model->getApplicationById($id);
    if ($app && FormModel::isMembershipType($app['form_type'])) {
        http_response_code(404);
        die('Membership applications are reviewed in Membership review.');
    }
    if (!$app || !isset($allowed[(int) $app['form_id']])) { http_response_code(404); die('Application not found.'); }
}

$config = require __DIR__ . '/../../includes/config.php';
$resumeUrl = fn($token) => rtrim($config['app']['url'], '/') . '/resume/' . $token;

// Same outcome email the form manager sends. A mail failure never undoes a decision that is already saved.
$notifyApplicant = function (array $row, string $status, string $note) {
    if (!in_array($status, ['Under Review', 'Accepted', 'Rejected'], true)) return;
    try {
        Mailer::sendStatusUpdate($row['email'], $row['applicant_name'], $status, $row['tracking_id'],
                                 $row['form_title'] ?: $row['form_type'], $note);
    } catch (Throwable $e) {
        app_log("Status email failed for application #{$row['id']}: " . $e->getMessage());
    }
};

// ---------------------------------------------------------------------------
// Bulk action from the queue. Every application is re-loaded and re-checked,
// so editing the request can never reach a form outside the allowed list, and
// anything no longer open is skipped instead of failing the whole batch.
// ---------------------------------------------------------------------------
if (!$id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');

    $bulkAct = (string) ($_POST['bulk_action'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
    $note = trim((string) ($_POST['note'] ?? ''));

    if (!isset(APP_ACTIONS[$bulkAct])) {
        $error = 'Choose an action to apply.';
    } elseif (!$ids) {
        $error = 'Tick at least one application first.';
    } elseif (count($ids) > BULK_MAX) {
        $error = 'Please act on at most ' . BULK_MAX . ' applications at a time.';
    } elseif ($bulkAct === 'info' && $note === '') {
        $error = 'Say what you need from the applicants.';
    } else {
        $by = currentAdminIdentifier();
        $result = ['act' => $bulkAct, 'done' => 0, 'skipped' => [], 'links' => [], 'message' => $bulkAct === 'info' ? $note : ''];

        foreach ($ids as $appId) {
            $row = $model->getApplicationById($appId);
            if (!$row || !isset($allowed[(int) $row['form_id']])) {
                $result['skipped'][] = "#$appId: not found, or not one you can review";
                continue;
            }
            $who = $row['applicant_name'] ?: $row['email'];
            if (!in_array($row['status'], APP_OPEN, true)) {
                $result['skipped'][] = "$who: already " . ($row['status'] === 'Draft' ? 'awaiting the applicant' : strtolower($row['status']));
                continue;
            }
            if ($bulkAct === 'review' && $row['status'] === 'Under Review') {
                $result['skipped'][] = "$who: already under review";
                continue;
            }
            try {
                if ($bulkAct === 'info') {
                    $token = $model->generateMagicLink((int) $row['id'], true);
                    $model->updateStatus((int) $row['id'], 'Draft');
                    $result['links'][] = ['who' => $who, 'email' => $row['email'], 'url' => $resumeUrl($token)];
                } else {
                    $model->updateStatus((int) $row['id'], APP_ACTIONS[$bulkAct]);
                    $notifyApplicant($row, APP_ACTIONS[$bulkAct], $note);
                }
                app_log("Application bulk $bulkAct: #{$row['id']} ({$row['tracking_id']}, {$row['form_type']}) by $by");
                $result['done']++;
            } catch (Throwable $e) {
                $result['skipped'][] = "$who: " . $e->getMessage();
            }
        }

        $_SESSION['application_bulk'] = $result;
        $keep = array_filter(['form' => $_GET['form'] ?? '', 'status' => $_GET['status'] ?? ''], 'strlen');
        header('Location: /admin/application-review' . ($keep ? '?' . http_build_query($keep) : ''));
        exit;
    }
}

// ---------------------------------------------------------------------------
// Single-application action
// ---------------------------------------------------------------------------
if ($app && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    $act = (string) ($_POST['action'] ?? '');
    $by = currentAdminIdentifier();
    try {
        $isOpen = in_array($app['status'], APP_OPEN, true);
        if ($act === 'resend') {
            if ($app['status'] !== 'Draft') throw new Exception('Only an application waiting on the applicant can be sent a new link.');
            $token = $model->generateMagicLink((int) $app['id'], true);
            $_SESSION['application_link'] = ['who' => $app['applicant_name'] ?: $app['email'], 'email' => $app['email'],
                                             'message' => '', 'url' => $resumeUrl($token)];
        } elseif (isset(APP_ACTIONS[$act])) {
            if (!$isOpen) throw new Exception('This application is no longer open for a decision.');
            if ($act === 'info') {
                $msg = trim((string) ($_POST['message'] ?? ''));
                if ($msg === '') throw new Exception('Say what you need from the applicant.');
                $token = $model->generateMagicLink((int) $app['id'], true);
                $model->updateStatus((int) $app['id'], 'Draft');
                $_SESSION['application_link'] = ['who' => $app['applicant_name'] ?: $app['email'], 'email' => $app['email'],
                                                 'message' => $msg, 'url' => $resumeUrl($token)];
            } else {
                $model->updateStatus((int) $app['id'], APP_ACTIONS[$act]);
                $notifyApplicant($app, APP_ACTIONS[$act], trim((string) ($_POST['note'] ?? '')));
            }
        } else {
            throw new Exception('Unknown action.');
        }
        app_log("Application $act: #{$app['id']} ({$app['tracking_id']}, {$app['form_type']}) by $by");
        header('Location: /admin/application-review?id=' . $app['id'] . '&done=' . urlencode($act));
        exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
    $app = $model->getApplicationById($id);   // fresh state after an error
}

$doneText = ['approve' => 'Accepted.', 'reject' => 'Rejected.', 'review' => 'Marked under review.',
             'info' => 'Sent back to the applicant.', 'resend' => 'New link created.'];
if (!empty($_GET['done'])) $notice = $doneText[$_GET['done']] ?? 'Saved.';

// One-time link(s) to hand to the applicant (nothing is emailed from this page).
$manual = $_SESSION['application_link'] ?? null;
unset($_SESSION['application_link']);
$bulk = $_SESSION['application_bulk'] ?? null;
unset($_SESSION['application_bulk']);
$bulkVerb = ['approve' => 'accepted', 'reject' => 'rejected', 'review' => 'marked under review', 'info' => 'sent back for more information'];

$fForm = (string) ($_GET['form'] ?? ''); $fStatus = (string) ($_GET['status'] ?? '');
$h = fn($v) => htmlspecialchars((string) $v);
$label = fn($s) => $s === 'Draft' ? 'Awaiting applicant' : $s;
$tone = fn($s) => ['New' => 'st-new', 'Submitted' => 'st-new', 'Under Review' => 'st-review',
                   'Draft' => 'st-wait', 'Accepted' => 'st-ok', 'Rejected' => 'st-bad'][$s] ?? 'st-new';

$crumbs = $app
    ? [['Workspace', '/admin/dashboard'], ['Application review', '/admin/application-review'], [$app['applicant_name'] ?: $app['email']]]
    : [['Workspace', '/admin/dashboard'], ['Application review']];

engage_header([
    'title'   => 'Application review',
    'heading' => 'Application review',
    'kicker'  => 'Organizer workspace',
    'lead'    => 'Responses to program forms. Membership forms are handled in Membership review.',
    'tools'   => '',
    'wide'    => true,
    'crumbs'  => $crumbs,
]);
?>
<style>
    /* Application review only. Everything else comes from /assets/css/engage.css */
    .sect { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 22px 24px; margin: 0 0 22px; box-shadow: 0 16px 34px rgba(15,23,42,.12); }
    .sect select, .sect input[type=text] {
        padding: 8px 12px; background: #fff; color: var(--ink);
        border: 1px solid var(--border); border-radius: 10px; font: inherit; font-size: 14px;
    }
    .sect select:focus, .sect input[type=text]:focus { outline: 2px solid var(--primary); outline-offset: -1px; border-color: transparent; }
    .btn-ghost.sm, .btn-solid.sm { width: auto; padding: 7px 16px; font-size: 13.5px; }

    .pill.st-new { --tone: var(--primary); }
    .pill.st-review { --tone: #6d28d9; }
    .pill.st-wait { --tone: #b45309; }
    .pill.st-ok { --tone: var(--leaf-dark); }
    .pill.st-bad { --tone: var(--accent); }

    .action-banner ul { flex-basis: 100%; margin: 6px 0 0; padding-left: 18px; }
    .flash-link { display: block; flex-basis: 100%; margin-top: 8px; padding: 10px; background: rgba(0,0,0,.06); border-radius: 6px; font-size: 12px; word-break: break-all; }

    dl.answers { margin: 14px 0 0; }
    dl.answers dt { margin-top: 14px; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
    dl.answers dd { margin: 3px 0 0; white-space: pre-wrap; }

    .actrow { display: flex; flex-wrap: wrap; gap: 10px; }
    .actform { margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
    .actform .field { margin-bottom: 12px; }
    .actform textarea { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; font: inherit; }

    .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 16px; }
    .filters .total { margin-left: auto; font-size: 13.5px; font-weight: 600; color: var(--muted); }
    .bulkbar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 14px; padding: 12px 14px; background: #fff; border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 4px 12px rgba(15,23,42,.08); }
    .bulkbar .count { min-width: 90px; font-size: 14px; font-weight: 700; color: var(--muted); }
    .bulkbar input[type=text] { flex: 1 1 240px; min-width: 200px; }
    .rowform { display: inline-flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0; }
    .rowform input[type=text] { width: 170px; padding: 6px 10px; font-size: 13px; }
    .rowform select { padding: 6px 8px; font-size: 13px; }
    .tbl th.pick, .tbl td.pick { width: 36px; padding-right: 0; }
    .tbl td.pick input, .tbl th.pick input { width: 16px; height: 16px; cursor: pointer; accent-color: var(--primary); }
    .tbl td.pick input:disabled { cursor: not-allowed; }
</style>

<?php if ($notice): ?><div class="alert ok"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($manual): ?>
    <div class="action-banner">
        <span><strong>Nothing was emailed.</strong> Send <?= $h($manual['who']) ?> (<?= $h($manual['email']) ?>) this one-time link<?= $manual['message'] !== '' ? ' with your message' : '' ?>:</span>
        <?php if ($manual['message'] !== ''): ?><code class="flash-link"><?= nl2br($h($manual['message'])) ?></code><?php endif; ?>
        <code class="flash-link"><?= $h($manual['url']) ?></code>
    </div>
<?php endif; ?>
<?php if ($bulk): ?>
    <div class="alert <?= $bulk['done'] ? 'ok' : 'error' ?>">
        <?= (int) $bulk['done'] ?> application<?= $bulk['done'] === 1 ? '' : 's' ?> <?= $h($bulkVerb[$bulk['act']] ?? 'updated') ?>.
        <?= $bulk['skipped'] ? count($bulk['skipped']) . ' skipped.' : '' ?>
    </div>
    <?php if ($bulk['skipped']): ?>
        <div class="action-banner"><strong>Skipped</strong><ul><?php foreach ($bulk['skipped'] as $s): ?><li><?= $h($s) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($bulk['links']): ?>
        <div class="action-banner"><strong>Nothing was emailed.</strong> Send each applicant your message and their one-time link:
            <?php if ($bulk['message'] !== ''): ?><code class="flash-link"><?= nl2br($h($bulk['message'])) ?></code><?php endif; ?>
            <ul><?php foreach ($bulk['links'] as $l): ?><li><?= $h($l['who']) ?> (<?= $h($l['email']) ?>): <code><?= $h($l['url']) ?></code></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= $h($error) ?></div><?php endif; ?>

<?php if ($app):
    $form = $formModel->getFormById((int) $app['form_id']);
    $schema = $form['schema'] ?? [];
    $data = json_decode($app['form_data'] ?? '', true) ?: [];
    $open = in_array($app['status'], APP_OPEN, true); ?>
    <section class="sect">
        <div class="qhead">
            <h3><?= $h($app['applicant_name'] ?: $app['email']) ?></h3>
            <span class="pill <?= $tone($app['status']) ?>"><?= $h($label($app['status'])) ?></span>
        </div>
        <p class="qmeta">
            <?= $h($app['email']) ?> &middot;
            <?= $h($app['form_title'] ?: $app['form_type']) ?> &middot;
            <?= $h($app['tracking_id']) ?> &middot;
            Submitted <?= $h($app['created_at']) ?>
        </p>
        <dl class="answers">
        <?php foreach ($schema['fields'] ?? [] as $f):
            if (empty($f['name'])) continue;
            $v = $data[$f['name']] ?? ''; if (is_array($v)) $v = implode(', ', $v); ?>
            <dt><?= MiniWikiText::inline($h($f['label'] ?? $f['name'])) ?></dt>
            <dd><?= $v === '' ? '&mdash;' : (($f['type'] ?? '') === 'file' ? 'File uploaded: ' . $h($v) : $h($v)) ?></dd>
        <?php endforeach; ?>
        </dl>
        <p class="qmeta" style="margin-top:16px;"><a href="/admin/form_manager?id=<?= (int) $app['form_id'] ?>">Open in form manager</a>
            for internal notes and CSV export.</p>
    </section>

    <?php if ($open): ?>
    <section class="sect">
        <form method="POST"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <div class="field">
                <label>Note to include in the email to the applicant (optional)</label>
                <input type="text" name="note" style="width:100%">
            </div>
            <div class="actrow">
            <button name="action" value="approve" class="btn-ok"
                    onclick="return confirm('Accept this application? It will be locked.');">Accept</button>
            <?php if ($app['status'] !== 'Under Review'): ?>
                <button name="action" value="review" class="btn-ghost">Mark under review</button>
            <?php endif; ?>
            <button name="action" value="reject" class="btn-bad"
                    onclick="return confirm('Reject this application? It will be locked.');">Reject</button>
            </div>
        </form>
        <form method="POST" class="actform"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <div class="field">
                <label>Ask for more information (creates a one-time link for the applicant to edit and resubmit)</label>
                <textarea name="message" rows="3" placeholder="e.g. Please upload a clearer copy of your ID."></textarea>
            </div>
            <button name="action" value="info" class="btn-ghost">Send back for more information</button>
        </form>
    </section>
    <?php elseif ($app['status'] === 'Draft'): ?>
    <section class="sect">
        <p style="margin:0 0 12px;">Waiting for the applicant to finish or update. It returns to the queue when they submit.</p>
        <form method="POST"><?= CSRF::getInputField() ?>
            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
            <button name="action" value="resend" class="btn-ghost">Create a new link</button>
        </form>
    </section>
    <?php else: ?>
        <div class="empty-note">This application has been decided and is locked.</div>
    <?php endif; ?>

<?php else:
    $rows = array_values(array_filter($model->getAllApplications(), function ($r) use ($allowed, $fForm, $fStatus) {
        return isset($allowed[(int) $r['form_id']])
            && ($fForm === '' || $r['form_type'] === $fForm)
            && ($fStatus === '' || $r['status'] === $fStatus);
    })); ?>
    <section class="sect">
        <form method="GET" class="filters">
            <select name="form"><option value="">All forms</option>
                <?php foreach ($allForms as $f): ?>
                    <option value="<?= $h($f['form_type']) ?>" <?= $f['form_type'] === $fForm ? 'selected' : '' ?>><?= $h($f['title'] ?: $f['form_type']) ?></option>
                <?php endforeach; ?></select>
            <select name="status"><option value="">All statuses</option>
                <?php foreach (['New','Submitted','Under Review','Draft','Accepted','Rejected'] as $s): ?>
                    <option value="<?= $h($s) ?>" <?= $s === $fStatus ? 'selected' : '' ?>><?= $h($label($s)) ?></option>
                <?php endforeach; ?></select>
            <button class="btn-ghost sm">Filter</button>
            <span class="total"><?= count($rows) ?> application<?= count($rows) === 1 ? '' : 's' ?></span>
        </form>

        <?php /* Posts back to this same URL, so the active filters are kept. */ ?>
        <form method="POST" id="bulkForm" class="bulkbar" style="display:none"><?= CSRF::getInputField() ?>
            <input type="hidden" name="action" value="bulk">
            <div id="bulkIdsContainer"></div>
            <span class="count" id="bulkCount"></span>
            <select name="bulk_action" id="bulkAction" required>
                <option value="">Set status to&hellip;</option>
                <option value="review">Under Review</option>
                <option value="approve">Accepted</option>
                <option value="info">Needs information</option>
                <option value="reject">Rejected</option>
            </select>
            <input type="text" name="note" id="bulkNote" placeholder="Note emailed with the decision (optional) / what you need (required for Needs information)">
            <button type="submit" id="bulkApply" class="btn-solid sm">Apply to selected</button>
        </form>

        <div class="tbl-wrap">
        <table class="tbl"><thead><tr>
            <th class="pick"><input type="checkbox" id="pickAll" aria-label="Select all"></th>
            <th>Applicant</th><th>Form</th><th>Tracking ID</th><th>Status</th><th>Submitted</th><th>Update</th></tr></thead><tbody>
        <?php foreach ($rows as $r):
            $rowOpen = in_array($r['status'], APP_OPEN, true); ?>
            <tr>
                <td class="pick"><input type="checkbox" class="pick-row" value="<?= (int) $r['id'] ?>"
                    <?= $rowOpen ? '' : 'disabled title="Already decided or waiting on the applicant"' ?>></td>
                <td><a class="dl" href="?id=<?= (int) $r['id'] ?>"><?= $h($r['applicant_name'] ?: $r['email']) ?></a>
                    <span class="sub"><?= $h($r['email']) ?></span></td>
                <td><?= $h($r['form_title'] ?: $r['form_type']) ?></td>
                <td><code><?= $h($r['tracking_id']) ?></code></td>
                <td><span class="pill <?= $tone($r['status']) ?>"><?= $h($label($r['status'])) ?></span></td>
                <td><?= $h($r['created_at']) ?></td>
                <td>
                <?php if ($rowOpen): ?>
                    <form method="POST" class="rowform"><?= CSRF::getInputField() ?>
                        <input type="hidden" name="action" value="bulk">
                        <input type="hidden" name="ids[]" value="<?= (int) $r['id'] ?>">
                        <select name="bulk_action" aria-label="Set status">
                            <option value="review">Under Review</option>
                            <option value="approve">Accepted</option>
                            <option value="info">Needs information</option>
                            <option value="reject">Rejected</option>
                        </select>
                        <input type="text" name="note" placeholder="Note (optional)">
                        <button type="submit" class="btn-ghost sm">Apply</button>
                    </form>
                <?php else: ?><span style="color:#94a3b8">&mdash;</span><?php endif; ?>
                </td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="7">Nothing to review here.</td></tr><?php endif; ?>
        </tbody></table>
        </div>
    </section>

    <script>
    (function () {
        var bulkForm = document.getElementById('bulkForm');
        var all = document.getElementById('pickAll') || { addEventListener: function () {}, style: {} };
        var rows = Array.prototype.slice.call(document.querySelectorAll('.pick-row:not(:disabled)'));
        var count = document.getElementById('bulkCount');
        var action = document.getElementById('bulkAction');
        var note = document.getElementById('bulkNote');
        var ids = document.getElementById('bulkIdsContainer');

        function picked() { return rows.filter(function (r) { return r.checked; }); }

        // The bar only appears once something is ticked.
        function sync() {
            var n = picked().length;
            bulkForm.style.display = n > 0 ? 'flex' : 'none';
            count.textContent = n + ' selected';
            all.checked = rows.length > 0 && n === rows.length;
            all.indeterminate = n > 0 && n < rows.length;
            all.disabled = rows.length === 0;
        }

        // Select all only ticks rows that can actually be acted on.
        all.addEventListener('change', function () {
            rows.forEach(function (r) { r.checked = all.checked; });
            sync();
        });
        rows.forEach(function (r) { r.addEventListener('change', sync); });

        // Returns false (and says why) if the action needs a message and has none.
        function ok(act, text) {
            if (act === 'info' && text.trim() === '') {
                alert('Say what you need from the applicants.');
                return false;
            }
            return true;
        }

        function confirmText(act, n) {
            var plural = n + ' application' + (n === 1 ? '' : 's');
            return {
                approve: 'Accept ' + plural + '? Each applicant is emailed, they are locked and this cannot be undone.',
                reject: 'Reject ' + plural + '? Each applicant is emailed, they are locked and this cannot be undone.',
                info: 'Send ' + plural + ' back for more information? You will get a link for each applicant to send them.'
            }[act];
        }

        bulkForm.addEventListener('submit', function (e) {
            var chosen = picked();
            if (chosen.length === 0) { e.preventDefault(); return; }
            if (!action.value) { e.preventDefault(); alert('Choose a status first.'); return; }
            if (!ok(action.value, note.value)) { e.preventDefault(); return; }
            var warn = confirmText(action.value, chosen.length);
            if (warn && !confirm(warn)) { e.preventDefault(); return; }

            ids.innerHTML = '';
            chosen.forEach(function (cb) {
                var input = document.createElement('input');
                input.type = 'hidden'; input.name = 'ids[]'; input.value = cb.value;
                ids.appendChild(input);
            });
            var apply = document.getElementById('bulkApply');
            apply.disabled = true; apply.textContent = 'Working…';
        });

        // Per-row Apply uses the same checks.
        document.querySelectorAll('.rowform').forEach(function (f) {
            f.addEventListener('submit', function (e) {
                var act = f.querySelector('select').value;
                var text = f.querySelector('input[type=text]').value;
                if (!ok(act, text)) { e.preventDefault(); return; }
                var warn = confirmText(act, 1);
                if (warn && !confirm(warn)) { e.preventDefault(); }
            });
        });

        sync();
    })();
    </script>
<?php endif; ?>
<?php engage_footer(); ?>
