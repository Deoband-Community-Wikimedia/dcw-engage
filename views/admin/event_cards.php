<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/event_card_pdf.php';
require_once __DIR__ . '/../../models/EventCardModel.php';
require_once __DIR__ . '/../../models/FormModel.php';

/**
 * Event cards: create an event, add its people, preview and print their cards.
 * Access: organizer or owner (same as application review). Mirrors the dashboard flag $canManageEventCards.
 *
 * Screens (all on this one page):
 *   /admin/event-cards                    the list of events + "New event"
 *   /admin/event-cards?event=7            one event: its people, add / import, download the print file
 *   /admin/event-cards?event=7&form=3     the same, with the confirmed awardees of form 3 listed to import
 *   /admin/event-cards?event=7&pdf=all    download the print file for everyone (pdf=<participant id> for one card)
 *   /admin/event-cards?event=7&preview=12&side=back
 *                                         one side of one card as an HTML page, for the preview iframes.
 *                                         preview=0 is a draft; name, role and affiliation in the query string
 *                                         override the saved values, so unsaved edits can be previewed.
 *
 * CSRF: every form carries CSRF::getInputField() and every POST is checked with CSRF::validate(). The three forms
 * that create rows (new event, add person, CSV upload) also carry a single-use submit token, so a double click
 * cannot create duplicates. The preview and the print file are read-only GETs.
 *
 * Layout note: events are cards; people are one compact row each, and "Edit" opens that person's form in place.
 */

/** Hidden CSRF field: the portal's own CSRF class (includes/csrf.php), field name csrf_token. */
function ec_csrf_field(): string
{
    return CSRF::getInputField();
}

/** Refuse the request (403) unless the posted csrf_token matches the session's. */
function ec_csrf_verify(): void
{
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('The security check failed. Go back, reload the page and try again.');
    }
}

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

// ---- Card preview (GET, read-only): one side of one card, for the iframes -------------------------
if ($event !== null && isset($_GET['preview'])) {
    $side    = ($_GET['side'] ?? 'front') === 'back' ? 'back' : 'front';
    $pid     = (int) $_GET['preview'];
    $roleKey = (string) array_key_first(EventCardModel::ROLES);
    $row     = ['name' => '', 'role' => $roleKey, 'affiliation' => ''];
    if ($pid > 0) {
        foreach ($model->participants($eventId) as $x) {
            if ((int) $x['id'] === $pid) { $row = $x; break; }
        }
    }
    // Values typed into a form but not saved yet win over the saved ones.
    foreach (['name' => 80, 'role' => 60, 'affiliation' => 120] as $k => $max) {
        if (isset($_GET[$k]) && is_string($_GET[$k])) {
            $row[$k] = mb_substr($_GET[$k], 0, $max, 'UTF-8');
        }
    }
    if (!isset(EventCardModel::ROLES[(string) $row['role']])) {
        $row['role'] = $roleKey;
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: no-store');
    try {
        $page = event_card_preview_html($event, $row, $side);
        header('Content-Type: text/html; charset=utf-8');
        echo $page;
    } catch (Throwable $e) {
        app_log('Event card preview failed: ' . $e->getMessage());
        http_response_code(500);
        echo 'The preview could not be built.';
    }
    exit;
}

// ---- Actions (POST) ------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ec_csrf_verify();
    $act = (string) ($_POST['action'] ?? '');
    try {
        if (in_array($act, ['create_event', 'add_person', 'import_csv'], true)
            && !CSRF::consumeSubmitToken($_POST['submit_token'] ?? '')) {
            throw new InvalidArgumentException('That form was already submitted. Reload the page to see the result.');
        }
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

// How many people hold each role, for the summary chips and the filter on the people list.
$roleCounts = [];
foreach ($people as $p) $roleCounts[$p['role']] = ($roleCounts[$p['role']] ?? 0) + 1;
$initial = fn(string $s): string => mb_strtoupper(mb_substr(trim($s), 0, 1)) ?: '?';

engage_header([
    'title'   => 'Event cards',
    'heading' => $event !== null ? trim($event['name'] . ' ' . $event['edition']) : 'Event cards',
    'lead'    => $event !== null ? 'Add people, check their cards, then download the print file.' : 'Set up an event, add its people and print their cards.',
    'slim'    => true,
    'wide'    => true,
    'crumbs'  => [],
    'tools'   => '',
]);
?>
<style>
    [hidden] { display: none !important; }
    .ec { max-width: 72rem; }
    .ec h2 { display: flex; align-items: baseline; gap: 10px; font-size: 1.15rem; margin: 2rem 0 .8rem; }
    .ec h2 small { color: var(--muted, #64748b); font-size: .85rem; font-weight: 500; }
    .ec .msg { padding: .75rem 1rem; border-radius: 12px; margin: .8rem 0; font-weight: 500; }
    .ec .ok  { background: #e3f6ee; color: #14513b; border: 1px solid #bfe6d4; }
    .ec .bad { background: #fdeceb; color: #8f1c10; border: 1px solid #f3c4c0; }

    .ec .btn, .ec button { font: inherit; cursor: pointer; }
    .ec .b { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 9px 18px; border-radius: 999px; border: 1px solid var(--primary, #106b9a); background: var(--primary, #106b9a); color: #fff; font-weight: 700; font-size: 14px; text-decoration: none; }
    .ec .b:hover { filter: brightness(1.08); }
    .ec .b.line { background: #fff; color: var(--primary, #106b9a); }
    .ec .b.red { background: #fff; color: #97161b; border-color: #e3b5b7; }
    .ec .b.red:hover { border-color: #97161b; }
    .ec .b.sm { padding: 6px 14px; font-size: 13px; }
    .ec :is(.b, summary, input, select, textarea, .seg button):focus-visible { outline: 2px solid var(--primary, #106b9a); outline-offset: 2px; }

    .ec .fld { display: block; font-size: 13px; font-weight: 600; }
    .ec .fld small { display: block; margin-top: 2px; color: var(--muted, #64748b); font-weight: 400; font-size: 12px; }
    .ec :is(input[type=text], input[type=search], select) { width: 100%; box-sizing: border-box; margin-top: 4px; padding: 9px 11px; font: inherit; border: 1px solid var(--border, #cbd5e1); border-radius: 10px; background: #fff; }
    .ec .form-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); align-items: end; }
    .ec .form-grid .wide { grid-column: 1 / -1; }

    .ec .panel { padding: 20px; background: var(--card, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; }
    .ec .panel h3 { margin: 0 0 4px; font-size: 16px; }
    .ec .panel > p { margin: 0 0 12px; color: var(--muted, #64748b); font-size: 13.5px; line-height: 1.45; }

    /* Event list */
    .ec .top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin: 1.4rem 0 1rem; }
    .ec .evs { display: grid; gap: 16px; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
    .ec .ev { position: relative; display: flex; flex-direction: column; gap: 14px; padding: 20px; background: var(--card, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; color: inherit; text-decoration: none; transition: transform .18s, box-shadow .18s, border-color .18s; }
    .ec .ev:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(15,23,42,.12); border-color: hsl(var(--h) 45% 70%); }
    .ec .ev::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 6px; border-radius: 18px 18px 0 0; background: linear-gradient(90deg, hsl(var(--h) 65% 50%), hsl(calc(var(--h) + 40) 70% 55%)); }
    .ec .ev .nm { display: block; font-size: 18px; font-weight: 800; line-height: 1.25; }
    .ec .ev .ed { color: var(--muted, #64748b); font-size: 14px; }
    .ec .ev .ft { display: flex; align-items: end; justify-content: space-between; margin-top: auto; }
    .ec .ev .ct { font-size: 34px; font-weight: 800; line-height: 1; color: hsl(var(--h) 60% 28%); font-variant-numeric: tabular-nums; }
    .ec .ev .ct small { display: block; margin-top: 4px; color: var(--muted, #64748b); font-size: 12.5px; font-weight: 500; }
    .ec .ev .go { color: var(--primary, #106b9a); font-weight: 700; font-size: 14px; }
    .ec .none { padding: 34px 20px; text-align: center; color: var(--muted, #64748b); background: var(--card, #fff); border: 1px dashed #c3d0dc; border-radius: 16px; }
    .ec .none b { display: block; margin-bottom: 4px; color: var(--ink, #1e293b); font-size: 16px; }

    details.ec-new > summary, details.ec-more > summary { list-style: none; cursor: pointer; }
    details.ec-new > summary::-webkit-details-marker { display: none; }
    details.ec-new[open] > summary { background: #fff; color: var(--primary, #106b9a); }
    details.ec-new .panel { margin-top: 12px; max-width: 40rem; }

    /* One event */
    .ec .crumb { display: inline-block; margin-top: 1.2rem; color: var(--primary, #106b9a); font-weight: 600; text-decoration: none; }
    .ec .crumb:hover { text-decoration: underline; }
    .ec .sum { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; margin: 14px 0 0; padding: 18px 20px; background: var(--card, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; }
    .ec .sum .tot b { display: block; font-size: 34px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
    .ec .sum .tot span { color: var(--muted, #64748b); font-size: 13px; }
    .ec .rc { display: flex; flex-wrap: wrap; gap: 8px; flex: 1 1 260px; }
    .ec .rchip { display: inline-flex; align-items: center; gap: 7px; padding: 5px 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 999px; background: #fff; font-size: 13px; font-weight: 600; cursor: pointer; }
    .ec .rchip i { width: 10px; height: 10px; border-radius: 50%; }
    .ec .rchip b { font-variant-numeric: tabular-nums; }
    .ec .rchip.on { background: var(--ink, #1e293b); border-color: var(--ink, #1e293b); color: #fff; }

    .ec .find { margin: 16px 0 12px; }
    .ec .find input { max-width: 340px; border-radius: 999px; padding: 10px 16px; margin: 0; }

    .ec .pp { margin: 0 0 8px; background: var(--card, #fff); border: 1px solid var(--border, #e2e8f0); border-left: 5px solid var(--rc); border-radius: 12px; }
    .ec .pp[open] { box-shadow: 0 8px 24px rgba(15,23,42,.09); }
    .ec .pp > summary { display: grid; grid-template-columns: 40px 1fr auto auto; gap: 14px; align-items: center; padding: 10px 16px; cursor: pointer; list-style: none; }
    .ec .pp > summary::-webkit-details-marker { display: none; }
    .ec .pp > summary:hover { background: #f8fafc; border-radius: 12px; }
    .ec .av { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; background: var(--rc); color: #fff; font-weight: 800; font-size: 17px; }
    .ec .pp .who b { display: block; font-size: 15px; overflow-wrap: anywhere; }
    .ec .pp .who span { color: var(--muted, #64748b); font-size: 13px; }
    .ec .pill { display: inline-block; padding: 3px 12px; border-radius: 999px; background: var(--rc); color: #fff; font-size: 12.5px; font-weight: 700; white-space: nowrap; }
    .ec .pp .ed { color: var(--primary, #106b9a); font-weight: 600; font-size: 14px; }
    .ec .pp .ed::before { content: "Edit"; }
    .ec .pp[open] .ed::before { content: "Close"; }
    .ec .pp .body { padding: 16px 18px 18px; border-top: 1px dashed var(--border, #e2e8f0); }
    .ec .pp .foot { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding-top: 4px; }
    .ec .pp .foot .grow { margin-left: auto; }

    /* Add people */
    .ec .adds { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); align-items: start; }
    .ec .adds .span { grid-column: 1 / -1; }
    .ec .picks { max-height: 260px; overflow: auto; margin: 10px 0; padding: 4px 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; }
    .ec .picks label { display: flex; gap: 8px; align-items: center; padding: 7px 0; border-top: 1px solid #eef2f6; font-size: 14px; cursor: pointer; }
    .ec .picks label:first-child { border-top: 0; }
    .ec .picks small { color: var(--muted, #64748b); }
    .ec .drop { display: block; padding: 22px 14px; margin: 10px 0; text-align: center; border: 2px dashed #b9c8d6; border-radius: 14px; background: #f8fafc; color: #475569; font-size: 14px; }
    .ec .drop input { margin: 8px 0 0; }
    .ec code { padding: 1px 6px; border-radius: 6px; background: #eef2f6; font-size: 12.5px; }

    /* Card preview: the modal on each person, and the live one beside "Add one person" */
    .ec .seg { display: inline-flex; border: 1px solid var(--border, #cbd5e1); border-radius: 999px; overflow: hidden; background: #fff; }
    .ec .seg button { border: 0; background: #fff; color: var(--ink, #1e293b); padding: 7px 16px; font-size: 13px; font-weight: 600; }
    .ec .seg button.on { background: var(--ink, #1e293b); color: #fff; }
    .ec iframe.card { display: block; width: 100%; aspect-ratio: 105 / 148; border: 0; border-radius: 12px; background: #eef2f6; }
    .ec dialog.pv { width: min(92vw, 400px, calc((100vh - 120px) * .7095)); padding: 14px; border: 0; border-radius: 18px; background: var(--card, #fff); box-shadow: 0 24px 60px rgba(15,23,42,.35); }
    .ec dialog.pv::backdrop { background: rgba(15,23,42,.55); }
    .ec .pv-bar { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
    .ec .live-wrap { display: grid; gap: 22px; grid-template-columns: minmax(0, 1fr) 250px; align-items: start; }
    .ec .live .seg { margin-bottom: 10px; }

    @media (max-width: 640px) {
        .ec .pp > summary { grid-template-columns: 40px 1fr auto; }
        .ec .pp .ed { display: none; }
        .ec .pp > summary .pill { grid-column: 2; justify-self: start; grid-row: 2; }
        .ec .live-wrap { grid-template-columns: 1fr; }
        .ec .live { max-width: 250px; }
    }
    @media (prefers-reduced-motion: reduce) { .ec .ev { transition: none; } .ec .ev:hover { transform: none; } }
</style>
<div class="ec">

<?php if ($okText !== ''): ?><div class="msg ok" role="status"><?= $h($okText) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="msg bad" role="alert"><?= $h($error) ?></div><?php endif; ?>
<?php foreach ($notes as $n): ?><div class="msg ok"><?= $h($n) ?></div><?php endforeach; ?>

<?php if ($event === null): ?>

    <div class="top">
        <h2 style="margin:0">Events <small><?= count($events) ?></small></h2>
        <details class="ec-new" <?= !$events ? 'open' : '' ?>>
            <summary class="b">＋ New event</summary>
            <form method="post" class="panel">
                <?= ec_csrf_field() ?>
                <input type="hidden" name="action" value="create_event">
                <?= CSRF::getSubmitField() ?>
                <div class="form-grid">
                    <label class="fld">Event name<input type="text" name="name" maxlength="120" required placeholder="Train-a-Wikipedian"></label>
                    <label class="fld">Edition<input type="text" name="edition" maxlength="80" placeholder="November 2026"></label>
                    <label class="fld wide">Event logo<small>Optional. Picked from assets/chapters/</small>
                        <select name="logo_key"><option value="">None: only the DCW logo</option>
                        <?php foreach (EventCardModel::availableLogos() as $k): ?><option value="<?= $h($k) ?>"><?= $h($k) ?></option><?php endforeach; ?>
                        </select></label>
                    <div class="wide"><button type="submit" class="b">Create event</button></div>
                </div>
            </form>
        </details>
    </div>

    <?php if (!$events): ?>
        <div class="none"><b>No events yet</b>Create your first event, then add the people who need cards.</div>
    <?php else: ?>
        <div class="evs">
            <?php foreach ($events as $e): $n = (int) $e['people']; ?>
                <a class="ev" href="/admin/event-cards?event=<?= (int) $e['id'] ?>" style="--h:<?= abs(crc32((string) $e['name'])) % 360 ?>">
                    <div><span class="nm"><?= $h($e['name']) ?></span><span class="ed"><?= $h($e['edition']) ?></span></div>
                    <div class="ft">
                        <div class="ct"><?= $n ?><small><?= $n === 1 ? 'person' : 'people' ?></small></div>
                        <span class="go">Open</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: ?>

    <a class="crumb" href="/admin/event-cards">&larr; All events</a>

    <div class="sum">
        <div class="tot"><b><?= count($people) ?></b><span><?= count($people) === 1 ? 'person' : 'people' ?> on this event</span></div>
        <?php if ($people): ?>
            <div class="rc" id="ec-roles" role="group" aria-label="Filter by role">
                <button type="button" class="rchip on" data-role="">All <b><?= count($people) ?></b></button>
                <?php foreach ($roleCounts as $role => $c): ?>
                    <button type="button" class="rchip" data-role="<?= $h($role) ?>"><i style="background:<?= $h(EventCardModel::ROLES[$role] ?? '#1b3f66') ?>"></i><?= $h($role) ?> <b><?= $c ?></b></button>
                <?php endforeach; ?>
            </div>
            <a class="b" href="/admin/event-cards?event=<?= $eventId ?>&amp;pdf=all">Download print file</a>
        <?php endif; ?>
    </div>
    <?php if ($people): ?><p style="margin:6px 4px 0;color:var(--muted,#64748b);font-size:13px"><?= count($people) ?> cards, front and back, in one PDF.</p><?php endif; ?>

    <h2>People</h2>
    <?php if ($people): ?>
        <div class="find"><input type="search" id="ec-q" placeholder="Search by name or affiliation…" aria-label="Search people"></div>
        <?php foreach ($people as $p):
            $pid     = (int) $p['id'];
            $col     = EventCardModel::ROLES[$p['role']] ?? '#1b3f66';
            $affShow = event_card_affiliation((string) $p['role'], (string) $p['affiliation']);
        ?>
            <details class="pp" style="--rc:<?= $h($col) ?>" data-role="<?= $h($p['role']) ?>" data-find="<?= $h(mb_strtolower($p['name'] . ' ' . $affShow)) ?>">
                <summary>
                    <span class="av" aria-hidden="true"><?= $h($initial((string) $p['name'])) ?></span>
                    <span class="who"><b><?= $h($p['name']) ?></b><span><?= $h($affShow !== '' ? $affShow : 'No affiliation') ?></span></span>
                    <span class="pill"><?= $h($p['role']) ?></span>
                    <span class="ed" aria-hidden="true"></span>
                </summary>
                <div class="body">
                    <form id="u<?= $pid ?>" method="post" class="form-grid">
                        <?= ec_csrf_field() ?>
                        <input type="hidden" name="action" value="update_person">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="pid" value="<?= $pid ?>">
                        <label class="fld">Name on card<input type="text" name="name" value="<?= $h($p['name']) ?>" maxlength="80" required></label>
                        <label class="fld">Role
                            <select name="role">
                                <?php foreach (EventCardModel::ROLES as $role => $color): ?>
                                    <option value="<?= $h($role) ?>" <?= $role === $p['role'] ? 'selected' : '' ?>><?= $h($role) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="fld">Affiliation<input type="text" name="affiliation" value="<?= $h($p['affiliation']) ?>" maxlength="120" placeholder="<?= $h(event_card_affiliation((string) $p['role'], '') ?: 'Optional') ?>"></label>
                        <div class="foot">
                            <button type="submit" class="b sm">Save changes</button>
                            <button type="button" class="b sm line" data-preview="<?= $pid ?>">Preview</button>
                            <a class="b sm line" href="/admin/event-cards?event=<?= $eventId ?>&amp;pdf=<?= $pid ?>">Card PDF</a>
                            <button type="submit" form="r<?= $pid ?>" formnovalidate class="b sm line grow"
                                    onclick="return confirm('Issue a new card? The old QR code stops working.');">Re-issue card</button>
                            <button type="submit" form="x<?= $pid ?>" formnovalidate class="b sm red"
                                    onclick="return confirm('Remove this person? Their QR code stops working.');">Remove</button>
                        </div>
                    </form>
                    <form id="r<?= $pid ?>" method="post">
                        <?= ec_csrf_field() ?>
                        <input type="hidden" name="action" value="reissue">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="pid" value="<?= $pid ?>">
                    </form>
                    <form id="x<?= $pid ?>" method="post">
                        <?= ec_csrf_field() ?>
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="pid" value="<?= $pid ?>">
                    </form>
                </div>
            </details>
        <?php endforeach; ?>
        <div class="none" id="ec-none" hidden>Nobody matches. Clear the search or pick another role.</div>
    <?php else: ?>
        <div class="none"><b>Nobody yet</b>Import the scholarship mentees below, or add organisers and facilitators.</div>
    <?php endif; ?>

    <h2>Add people</h2>
    <div class="adds">

        <div class="panel">
            <h3>Import mentees</h3>
            <p>Confirmed scholarship awardees from one of your program forms. Add their affiliation afterwards if the card should show one.</p>
            <form method="get" class="form-grid" style="grid-template-columns:1fr auto">
                <input type="hidden" name="event" value="<?= $eventId ?>">
                <select name="form" aria-label="Form" style="margin:0">
                    <option value="">Choose a form</option>
                    <?php foreach ($importForms as $f): ?>
                        <option value="<?= (int) $f['id'] ?>" <?= (int) $f['id'] === $formId ? 'selected' : '' ?>><?= $h($f['title']) ?> (/<?= $h($f['form_type']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="b line sm">Show awardees</button>
            </form>
            <?php if ($formId > 0): ?>
                <?php if (!$candidates): ?>
                    <p style="margin-top:12px">No confirmed awardees left to import from this form.</p>
                <?php else: ?>
                    <form method="post">
                        <?= ec_csrf_field() ?>
                        <input type="hidden" name="action" value="import_mentees">
                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                        <input type="hidden" name="form_id" value="<?= $formId ?>">
                        <div class="picks">
                            <label><input type="checkbox" onclick="this.form.querySelectorAll('.pick').forEach(c => c.checked = this.checked)" checked> <b>Select all (<?= count($candidates) ?>)</b></label>
                            <?php foreach ($candidates as $a): ?>
                                <label><input type="checkbox" class="pick" name="app_ids[]" value="<?= (int) $a['id'] ?>" checked>
                                    <span><?= $h($a['applicant_name']) ?> <small>(<?= $h($a['tracking_id']) ?>)</small></span></label>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" class="b">Add selected as Mentees</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h3>Upload a CSV</h3>
            <p>Columns: <code>name</code> <code>role</code> <code>affiliation</code> (affiliation is optional). Roles: <?= $h(implode(', ', array_keys(EventCardModel::ROLES))) ?>.</p>
            <form method="post" enctype="multipart/form-data">
                <?= ec_csrf_field() ?>
                <input type="hidden" name="action" value="import_csv">
                <?= CSRF::getSubmitField() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <label class="drop">Choose a .csv file, up to 200 KB<br><input type="file" name="csv" accept=".csv" required></label>
                <button type="submit" class="b">Upload</button>
            </form>
        </div>

        <div class="panel span">
            <h3>Add one person</h3>
            <p>For organisers, volunteers, facilitators and guests. The card on the right updates as you type.</p>
            <div class="live-wrap">
                <form method="post" class="form-grid" style="grid-template-columns:1fr" id="ec-add">
                    <?= ec_csrf_field() ?>
                    <input type="hidden" name="action" value="add_person">
                    <?= CSRF::getSubmitField() ?>
                    <input type="hidden" name="event_id" value="<?= $eventId ?>">
                    <label class="fld">Name<input type="text" name="name" maxlength="80" required></label>
                    <label class="fld">Role<select name="role">
                        <?php foreach (EventCardModel::ROLES as $role => $color): ?><option><?= $h($role) ?></option><?php endforeach; ?>
                    </select></label>
                    <label class="fld">Affiliation<small>Optional. Organisers and volunteers default to <?= $h(EventCardModel::DEFAULT_AFFILIATION) ?>.</small><input type="text" name="affiliation" maxlength="120"></label>
                    <div><button type="submit" class="b">Add person</button></div>
                </form>
                <div class="live">
                    <div class="seg" role="group" aria-label="Card side"><button type="button" data-side="front" class="on">Front</button><button type="button" data-side="back">Back</button></div>
                    <iframe class="card" id="ec-live" title="Live card preview"></iframe>
                </div>
            </div>
        </div>

    </div>

    <dialog class="pv" id="ec-pv" aria-label="Card preview">
        <div class="pv-bar">
            <div class="seg" role="group" aria-label="Card side"><button type="button" data-side="front" class="on">Front</button><button type="button" data-side="back">Back</button></div>
            <button type="button" class="b sm line" id="ec-pv-close">Close</button>
        </div>
        <iframe class="card" id="ec-pv-frame" title="Card preview"></iframe>
    </dialog>

    <script>
    // Role chips and search narrow the people list in the browser; nothing is sent to the server.
    (function () {
        var q = document.getElementById('ec-q'), roles = document.getElementById('ec-roles'), role = '';
        if (!q || !roles) return;
        var rows = [].slice.call(document.querySelectorAll('.ec .pp'));
        function run() {
            var s = q.value.trim().toLowerCase(), shown = 0;
            rows.forEach(function (r) {
                var ok = (!role || r.dataset.role === role) && (!s || r.dataset.find.indexOf(s) > -1);
                r.hidden = !ok; if (ok) shown++;
            });
            document.getElementById('ec-none').hidden = shown > 0;
        }
        q.addEventListener('input', run);
        roles.addEventListener('click', function (e) {
            var b = e.target.closest('.rchip'); if (!b) return;
            role = b.dataset.role;
            roles.querySelectorAll('.rchip').forEach(function (x) { x.classList.toggle('on', x === b); });
            run();
        });
    })();

    // Card previews. The server draws the card (same template as the PDF); these only point an iframe at it.
    // A form's current values are sent along, so a name or role that is typed but not saved yet shows up too.
    (function () {
        var EVENT = <?= (int) $eventId ?>;
        function url(pid, side, form) {
            var u = new URLSearchParams({ event: EVENT, preview: pid, side: side });
            if (form) ['name', 'role', 'affiliation'].forEach(function (k) {
                if (form.elements[k]) u.set(k, form.elements[k].value);
            });
            return '/admin/event-cards?' + u.toString();
        }
        function segment(box, side) {
            box.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b.dataset.side === side); });
        }

        // Modal, one per page, opened from a person's row.
        var dlg = document.getElementById('ec-pv'), frame = document.getElementById('ec-pv-frame');
        var st = { pid: 0, form: null, side: 'front' };
        function show() { frame.src = url(st.pid, st.side, st.form); segment(dlg.querySelector('.seg'), st.side); }
        document.addEventListener('click', function (e) {
            var b = e.target.closest('[data-preview]');
            if (!b) return;
            st = { pid: b.dataset.preview, form: document.getElementById('u' + b.dataset.preview), side: 'front' };
            show();
            if (dlg.showModal) dlg.showModal();
        });
        dlg.querySelector('.seg').addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            st.side = b.dataset.side; show();
        });
        document.getElementById('ec-pv-close').addEventListener('click', function () { dlg.close(); });
        dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
        dlg.addEventListener('close', function () { frame.removeAttribute('src'); });

        // Live preview beside "Add one person".
        var add = document.getElementById('ec-add'), live = document.getElementById('ec-live');
        if (add && live) {
            var side = 'front', t;
            var seg = live.parentNode.querySelector('.seg');
            function draw() { live.src = url(0, side, add); }
            function later() { clearTimeout(t); t = setTimeout(draw, 250); }
            add.addEventListener('input', later);
            add.addEventListener('change', later);
            seg.addEventListener('click', function (e) {
                var b = e.target.closest('button'); if (!b) return;
                side = b.dataset.side; segment(seg, side); draw();
            });
            draw();
        }
    })();
    </script>

<?php endif; ?>

</div>
<?php engage_footer(); ?>
