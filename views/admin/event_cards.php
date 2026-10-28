<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/event_card_pdf.php';
require_once __DIR__ . '/../../models/EventCardModel.php';
require_once __DIR__ . '/../../models/FormModel.php';

/**
 * Event cards: create an event, add its people, print their cards.
 * Access: organizer or owner (same as application review). Mirrors the dashboard flag $canManageEventCards.
 *
 * Screens (all on this one page):
 *   /admin/event-cards                    the list of events + "New event"
 *   /admin/event-cards?event=7            one event: its people, add / import, download the print file
 *   /admin/event-cards?event=7&form=3     the same, with the confirmed awardees of form 3 listed to import
 *   /admin/event-cards?event=7&pdf=all    download the print file for everyone (pdf=<participant id> for one card)
 *
 * CHECK BEFORE USE (names I could not see in the code I was given): csrf_field() and csrf_verify() below.
 */

Auth::requireLogin();
requireRole(['organizer', 'owner']);

$model   = new EventCardModel();
$by      = strtolower((string) Auth::email());
$eventId = (int) ($_GET['event'] ?? $_POST['event_id'] ?? 0);
$event   = $eventId > 0 ? $model->event($eventId) : null;
$formId  = (int) ($_GET['form'] ?? 0);
$error   = '';
$notes   = [];

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// ---- Print file (GET, read-only) -----------------------------------------------------------------
if ($event !== null && isset($_GET['pdf'])) {
    try {
        $people = $model->participants($eventId);
        if ($_GET['pdf'] !== 'all') {
            $one    = (int) $_GET['pdf'];
            $people = array_values(array_filter($people, fn($p) => (int) $p['id'] === $one));
        }
        AuditLog::activity('event.cards_printed', 'event:' . $eventId, count($people) . ' cards');
        event_cards_pdf_send($event, $people);
        exit;
    } catch (Throwable $e) {
        app_log('Event card PDF failed: ' . $e->getMessage());
        $error = 'Could not build the print file: ' . $e->getMessage();
    }
}

// ---- Actions (POST) ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = (string) ($_POST['action'] ?? '');
    try {
        switch ($act) {
            case 'create_event':
                $id = $model->createEvent((string) ($_POST['name'] ?? ''), (string) ($_POST['edition'] ?? ''),
                                          ($_POST['logo_key'] ?? '') !== '' ? (string) $_POST['logo_key'] : null, $by);
                header('Location: /admin/event-cards?event=' . $id . '&ok=created');
                exit;

            case 'add_person':
                $model->addPerson($eventId, (string) ($_POST['name'] ?? ''), (string) ($_POST['role'] ?? ''),
                                  (string) ($_POST['affiliation'] ?? ''), $by);
                header('Location: /admin/event-cards?event=' . $eventId . '&ok=added');
                exit;

            case 'import_mentees':
                $r = $model->importApplications($eventId, (int) ($_POST['form_id'] ?? 0), (array) ($_POST['app_ids'] ?? []), $by);
                header('Location: /admin/event-cards?event=' . $eventId . '&ok=imported&n=' . $r['added'] . '&skip=' . $r['skipped']);
                exit;

            case 'import_csv':
                $f = $_FILES['csv'] ?? null;
                if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                    throw new InvalidArgumentException('Choose a CSV file to upload.');
                }
                if ($f['size'] > 200 * 1024 || strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'csv') {
                    throw new InvalidArgumentException('Upload a .csv file under 200 KB.');
                }
                $r = $model->importCsv($eventId, $f['tmp_name'], $by);
                $notes = $r['errors'];
                $notes[] = $r['added'] . ' added.';
                break;

            case 'update_person':
                $model->updatePerson((int) ($_POST['pid'] ?? 0), (string) ($_POST['name'] ?? ''), (string) ($_POST['role'] ?? ''),
                                     (string) ($_POST['affiliation'] ?? ''), $by);
                header('Location: /admin/event-cards?event=' . $eventId . '&ok=saved');
                exit;

            case 'reissue':
                $model->reissue((int) ($_POST['pid'] ?? 0), $by);
                header('Location: /admin/event-cards?event=' . $eventId . '&ok=reissued');
                exit;

            case 'remove':
                $model->remove((int) ($_POST['pid'] ?? 0), $by);
                header('Location: /admin/event-cards?event=' . $eventId . '&ok=removed');
                exit;
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        app_log('Event cards action failed: ' . $e->getMessage());
        $error = 'Something went wrong. Nothing was changed.';
    }
}

$okText = [
    'created'  => 'Event created. Now add its people.',
    'added'    => 'Person added.',
    'saved'    => 'Saved.',
    'reissued' => 'New card issued. The old QR code no longer works.',
    'removed'  => 'Removed.',
    'imported' => ((int) ($_GET['n'] ?? 0)) . ' mentee(s) added' . (((int) ($_GET['skip'] ?? 0)) > 0 ? ', ' . (int) $_GET['skip'] . ' skipped (already there or no usable name)' : '') . '.',
][(string) ($_GET['ok'] ?? '')] ?? '';

$events = $model->events();
$people = $event !== null ? $model->participants($eventId) : [];

// Forms to import mentees from: program forms only (membership forms are left out).
$importForms = array_filter((new FormModel())->getAllForms(), fn($f) => !FormModel::isMembershipType($f['form_type']));
$candidates  = ($event !== null && $formId > 0) ? $model->candidates($formId, $eventId) : [];

engage_header([
    'title'   => 'Event cards',
    'heading' => $event !== null ? trim($event['name'] . ' ' . $event['edition']) : 'Event cards',
    'lead'    => $event !== null ? 'Add people, then download the print file.' : 'Set up an event, add its people and print their cards.',
    'slim'    => true,
    'wide'    => true,
    'crumbs'  => [],
    'tools'   => '',
]);
?>
<style>
    .ec { max-width: 62rem; }
    .ec h2 { font-size: 1.05rem; margin: 1.6rem 0 .6rem; }
    .ec .msg { padding: .6rem .9rem; border-radius: 6px; margin: .8rem 0; }
    .ec .ok  { background: #e8f6ef; color: #14513b; }
    .ec .bad { background: #fdeceb; color: #8f1c10; }
    .ec table { width: 100%; border-collapse: collapse; }
    .ec th, .ec td { text-align: left; padding: .45rem .5rem; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
    .ec th { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #475569; }
    .ec input[type=text], .ec select { padding: .35rem .5rem; border: 1px solid #cbd5e1; border-radius: 5px; max-width: 100%; }
    .ec .pill { display: inline-block; padding: .1rem .55rem; border-radius: 999px; color: #fff; font-size: .78rem; font-weight: 600; }
    .ec .row-actions form { display: inline; }
    .ec .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(19rem, 1fr)); gap: 1.2rem; }
    .ec .box { border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem; }
    .ec button, .ec .btn { cursor: pointer; }
</style>
<div class="ec">

<?php if ($okText !== ''): ?><div class="msg ok" role="status"><?= $h($okText) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="msg bad" role="alert"><?= $h($error) ?></div><?php endif; ?>
<?php foreach ($notes as $n): ?><div class="msg ok"><?= $h($n) ?></div><?php endforeach; ?>

<?php if ($event === null): ?>

    <h2>New event</h2>
    <form method="post" class="box">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_event">
        <p><label>Event name<br><input type="text" name="name" maxlength="120" required placeholder="Train-a-Wikipedian"></label></p>
        <p><label>Edition<br><input type="text" name="edition" maxlength="80" placeholder="November 2026"></label></p>
        <p><label>Event logo (optional, from assets/chapters/)<br>
            <select name="logo_key"><option value="">None: only the DCW logo</option>
            <?php foreach (EventCardModel::availableLogos() as $k): ?><option value="<?= $h($k) ?>"><?= $h($k) ?></option><?php endforeach; ?>
            </select></label></p>
        <button type="submit" class="btn-ghost">Create event</button>
    </form>

    <h2>Events</h2>
    <?php if (!$events): ?>
        <div class="empty-note">No events yet.</div>
    <?php else: ?>
        <table>
            <tr><th>Event</th><th>Edition</th><th>People</th><th></th></tr>
            <?php foreach ($events as $e): ?>
            <tr>
                <td><?= $h($e['name']) ?></td><td><?= $h($e['edition']) ?></td><td><?= (int) $e['people'] ?></td>
                <td><a href="/admin/event-cards?event=<?= (int) $e['id'] ?>">Open</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

<?php else: ?>

    <p><a href="/admin/event-cards">&larr; All events</a></p>

    <h2>People (<?= count($people) ?>)</h2>
    <?php if ($people): ?>
        <p><a class="btn-ghost" href="/admin/event-cards?event=<?= $eventId ?>&amp;pdf=all">Download print file (<?= count($people) ?> cards, front and back)</a></p>
        <table>
            <tr><th>Name on card</th><th>Role</th><th>Affiliation</th><th></th></tr>
            <?php foreach ($people as $p): $pid = (int) $p['id']; ?>
            <tr>
                <td>
                    <form id="u<?= $pid ?>" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_person">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="pid" value="<?= $pid ?>">
                    </form>
                    <input form="u<?= $pid ?>" type="text" name="name" value="<?= $h($p['name']) ?>" maxlength="80" required>
                </td>
                <td>
                    <select form="u<?= $pid ?>" name="role">
                        <?php foreach (EventCardModel::ROLES as $role => $color): ?>
                            <option value="<?= $h($role) ?>" <?= $role === $p['role'] ? 'selected' : '' ?>><?= $h($role) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="pill" style="background: <?= $h(EventCardModel::ROLES[$p['role']] ?? '#1b3f66') ?>;"><?= $h($p['role']) ?></span>
                </td>
                <td><input form="u<?= $pid ?>" type="text" name="affiliation" value="<?= $h($p['affiliation']) ?>" maxlength="120" placeholder="<?= $h(EventCardModel::DEFAULT_AFFILIATION) ?>"></td>
                <td class="row-actions">
                    <button form="u<?= $pid ?>" type="submit">Save</button>
                    <a href="/admin/event-cards?event=<?= $eventId ?>&amp;pdf=<?= $pid ?>">Card PDF</a>
                    <form method="post" onsubmit="return confirm('Issue a new card? The old QR code stops working.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reissue">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="pid" value="<?= $pid ?>">
                        <button type="submit">Re-issue</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Remove this person? Their QR code stops working.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="pid" value="<?= $pid ?>">
                        <button type="submit">Remove</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="empty-note">Nobody yet. Import the scholarship mentees below, or add organisers and facilitators.</div>
    <?php endif; ?>

    <h2>Add people</h2>
    <div class="grid">

        <div class="box">
            <strong>Import mentees: confirmed scholarship awardees</strong>
            <form method="get" style="margin: .6rem 0;">
                <input type="hidden" name="event" value="<?= $eventId ?>">
                <select name="form">
                    <option value="">Choose a form</option>
                    <?php foreach ($importForms as $f): ?>
                        <option value="<?= (int) $f['id'] ?>" <?= (int) $f['id'] === $formId ? 'selected' : '' ?>><?= $h($f['title']) ?> (/<?= $h($f['form_type']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Show confirmed awardees</button>
            </form>
            <?php if ($formId > 0): ?>
                <?php if (!$candidates): ?>
                    <p>No confirmed awardees left to import from this form.</p>
                <?php else: ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="import_mentees">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="form_id" value="<?= $formId ?>">
                        <p><label><input type="checkbox" onclick="this.form.querySelectorAll('.pick').forEach(c => c.checked = this.checked)" checked> Select all</label></p>
                        <?php foreach ($candidates as $a): ?>
                            <div><label><input type="checkbox" class="pick" name="app_ids[]" value="<?= (int) $a['id'] ?>" checked>
                                <?= $h($a['applicant_name']) ?> <small>(<?= $h($a['tracking_id']) ?>)</small></label></div>
                        <?php endforeach; ?>
                        <p><button type="submit">Add selected as Mentees</button></p>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="box">
            <strong>Add one person</strong>
            <form method="post" style="margin-top: .6rem;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_person">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <p><label>Name<br><input type="text" name="name" maxlength="80" required></label></p>
                <p><label>Role<br><select name="role">
                    <?php foreach (EventCardModel::ROLES as $role => $color): ?><option><?= $h($role) ?></option><?php endforeach; ?>
                </select></label></p>
                <p><label>Affiliation (optional)<br><input type="text" name="affiliation" maxlength="120" placeholder="<?= $h(EventCardModel::DEFAULT_AFFILIATION) ?>"></label></p>
                <button type="submit">Add</button>
            </form>
        </div>

        <div class="box">
            <strong>Upload a CSV</strong>
            <p><small>Columns: <code>name, role, affiliation</code> (affiliation optional). Roles: <?= $h(implode(', ', array_keys(EventCardModel::ROLES))) ?>.</small></p>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_csv">
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <p><input type="file" name="csv" accept=".csv" required></p>
                <button type="submit">Upload</button>
            </form>
        </div>

    </div>

<?php endif; ?>

</div>
<?php engage_footer(); ?>
