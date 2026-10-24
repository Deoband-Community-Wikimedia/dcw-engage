<?php
/**
 * DCW Engage - organizer view of feedback responses.
 *
 *   /admin/feedback-review                    all feedback forms, with response counts
 *   /admin/feedback-review?form=ID            responses to one form (search, paging)
 *   /admin/feedback-review?form=ID&id=RID     one response
 *
 * Same layout as Membership review: a sidebar, a search row, a table, and a two-column detail page.
 * Read-only apart from deleting a response (for privacy requests), closing / reopening the form and
 * emailing the respondent.
 *
 * "Email respondent" is sent THROUGH THE SYSTEM (FeedbackMailer::sendReply), never a mailto: link, so the
 * respondent's address is not copied into the organizer's own mail client. The organizer's sign-in email is
 * set as Reply-To so the respondent can answer. Each send is written to the app log (who, to which response);
 * the message text itself is not stored.
 */
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/FeedbackModel.php';
require_once __DIR__ . '/../../includes/mail/CoreMail.php';   // formatExpiryIST(): stored UTC -> IST text
require_once __DIR__ . '/../../includes/mail/feedback_mailer.php';   // sendReply()

Auth::requireLogin();
requireRole(['owner', 'organizer']);

const FB_LOAD_MAX = 5000;   // most responses read for one form (search and paging happen in PHP)
const FB_PER_PAGE = 25;
const FB_MAIL_SUBJECT_MAX = 150;
const FB_MAIL_BODY_MAX = 5000;

$formModel = new FormModel();
$fbModel   = new FeedbackModel();
$h         = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$formId = (int) ($_GET['form'] ?? $_POST['form_id'] ?? 0);
$respId = (int) ($_GET['id'] ?? 0);

$form = null;
if ($formId) {
    $form = $formModel->getFormById($formId);
    // One answer for "missing", "not a feedback form" and "not yours", as in the builder.
    if (!$form || !FeedbackModel::isFeedbackSchema($form['schema']) || !FormModel::userCanOpen($form)) {
        http_response_code(404);
        die('Form not found.');
    }
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
// Close / reopen. Same permission as form_manager.php (anyone who can open the form).
// A form that closed by deadline is reopened by extending the deadline in the builder, not here.
if ($form && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    $formModel->toggleFormStatus($formId, !empty($_POST['activate']));
    header('Location: /admin/feedback-review?form=' . $formId . '&toggled=1');
    exit;
}

if ($form && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    $row = $fbModel->getById((int) ($_POST['response_id'] ?? 0));
    // The response must belong to the form this user was allowed to open above.
    if ($row && (int) $row['form_id'] === $formId) {
        $fbModel->delete((int) $row['id']);
    }
    header('Location: /admin/feedback-review?form=' . $formId . '&deleted=1');
    exit;
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
// Every feedback form this person may open: the sidebar and the all-forms table.
$forms = [];
foreach ($fbModel->feedbackForms() as $f) {
    if (FormModel::userCanOpen($f)) $forms[] = $f;
}
$totalAll = array_sum(array_map(fn($f) => (int) $f['response_count'], $forms));

$labels = [];     // field name => plain-text label, in form order
$all    = [];     // every response to this form (newest first)
$resp   = null;   // the response being viewed
$prevId = $nextId = 0;
$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);

if ($form) {
    foreach (($form['schema']['fields'] ?? []) as $f) {
        if (!empty($f['name']) && ($f['type'] ?? '') !== 'file') {
            $labels[$f['name']] = MiniWikiText::stripToPlainText((string) ($f['label'] ?? $f['name']));
        }
    }
    $all = $fbModel->listForForm($formId, FB_LOAD_MAX, 0);

    if ($respId) {
        foreach ($all as $i => $r) {
            if ((int) $r['id'] === $respId) {
                $resp = $r;
                // The list is newest first: "previous" is the next-newer one, "next" the next-older one.
                $prevId = $i > 0 ? (int) $all[$i - 1]['id'] : 0;
                $nextId = $i < count($all) - 1 ? (int) $all[$i + 1]['id'] : 0;
                break;
            }
        }
        if (!$resp) { http_response_code(404); die('Response not found.'); }
    }
}

$formTitle = $form ? (string) ($form['schema']['title'] ?? 'Feedback') : 'Feedback';

// ---------------------------------------------------------------------------
// Email the respondent, through the system. Runs after the response is loaded so the address always comes
// from the stored response of THIS form, never from the request. The form posts back to the response URL.
// Problems are shown on the page (the typed text is kept); a send is only reported as done when the mailer
// says it really went out (it returns false in dev mode and on SMTP failure).
// ---------------------------------------------------------------------------
$emailError = '';
$emailKeep  = ['subject' => '', 'message' => ''];
if ($form && $resp && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');

    $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($_POST['subject'] ?? '')));
    $message = trim(str_replace("\r\n", "\n", (string) ($_POST['message'] ?? '')));
    $emailKeep = ['subject' => $subject, 'message' => $message];
    $toEmail = (string) ($resp['email'] ?? '');

    if ((int) ($_POST['response_id'] ?? 0) !== (int) $resp['id']) {
        $emailError = 'That response does not match this page. Please reload it and try again.';
    } elseif (!empty($resp['scrubbed_at']) || $toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $emailError = 'This respondent\'s email address is no longer on file, so nothing can be sent.';
    } elseif ($subject === '' || mb_strlen($subject) > FB_MAIL_SUBJECT_MAX) {
        $emailError = 'Please enter a subject (maximum ' . FB_MAIL_SUBJECT_MAX . ' characters).';
    } elseif ($message === '' || mb_strlen($message) > FB_MAIL_BODY_MAX) {
        $emailError = 'Please write a message (maximum ' . number_format(FB_MAIL_BODY_MAX) . ' characters).';
    } else {
        try {
            $replyTo = strtolower(trim((string) Auth::email()));
            $sent = FeedbackMailer::sendReply(
                $toEmail,
                (string) ($resp['name'] ?? ''),
                MiniWikiText::stripToPlainText($formTitle),
                $subject,
                $message,
                filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : ''
            );
        } catch (Throwable $e) {
            app_log("Feedback reply failed for response #{$resp['id']}: " . $e->getMessage());
            $sent = false;
        }

        if ($sent) {
            AuditLog::record('feedback.reply_sent', Auth::id(), Auth::email(), 'Response #' . (int) $resp['id'], 'Form #' . (int) $formId);
            header('Location: /admin/feedback-review?form=' . $formId . '&id=' . (int) $resp['id'] . '&emailed=1');
            exit;
        }
        $emailError = 'The email could not be sent. Please try again in a moment.';
    }
}

/** One answer as a single plain string (checkbox groups are joined). */
$flat = fn($v): string => trim(is_array($v) ? implode(', ', $v) : (string) $v);

/** A short preview of a response: the first non-empty answer, with its question. */
$preview = function (array $r) use ($labels, $flat): string {
    $answers = is_array($r['answers']) ? $r['answers'] : [];
    foreach ($labels as $name => $label) {
        $t = $flat($answers[$name] ?? '');
        if ($t !== '') {
            $t = preg_replace('/\s+/', ' ', $t);
            return mb_strimwidth($t, 0, 120, '…');
        }
    }
    return '';
};

// Search: name, email and every answer.
$rows = $all;
if ($form && $search !== '') {
    $rows = array_values(array_filter($all, function ($r) use ($search, $flat) {
        $hay = (string) ($r['name'] ?? '') . ' ' . (string) ($r['email'] ?? '');
        foreach ((is_array($r['answers']) ? $r['answers'] : []) as $v) $hay .= ' ' . $flat($v);
        return mb_stripos($hay, $search) !== false;
    }));
}
$total = count($rows);
$pages = max(1, (int) ceil($total / FB_PER_PAGE));
$page  = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$pageRows = array_slice($rows, ($page - 1) * FB_PER_PAGE, FB_PER_PAGE);

/** URL that keeps the form and search, with some parameters overridden. */
$fbUrl = function (array $over = []) use ($formId, $search): string {
    $q = array_filter(array_merge(['form' => $formId ?: '', 'q' => $search], $over), fn($v) => $v !== '' && $v !== null && $v !== 0);
    return '/admin/feedback-review' . ($q ? '?' . http_build_query($q) : '');
};
$daysSince = fn($ts) => max(0, (int) floor((time() - (int) strtotime((string) $ts)) / 86400));

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------
$crumbs = [['Workspace', '/admin/dashboard']];
if (!$form) {
    $crumbs[] = ['Feedback'];
} elseif (!$resp) {
    $crumbs[] = ['Feedback', '/admin/feedback-review'];
    $crumbs[] = [MiniWikiText::stripToPlainText($formTitle)];
} else {
    $crumbs[] = ['Feedback', '/admin/feedback-review'];
    $crumbs[] = [MiniWikiText::stripToPlainText($formTitle), '/admin/feedback-review?form=' . $formId];
    $crumbs[] = [$resp['name'] ?: 'Response'];
}

engage_header([
    'title'   => 'Feedback',
    'heading' => 'Feedback',
    'kicker'  => 'Organizer workspace',
    'lead'    => 'What people told us through our feedback forms.',
    'tools'   => '',
    'wide'    => true,
    'crumbs'  => $crumbs,
]);

$svgStar = '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>';
?>
<style>
    /* Feedback review only. Shared pieces (.vtabs, .adm-shell, .pill, .tbl, buttons) come from engage.css and staff.css */
    .sect { background: var(--card); border: 1.5px solid var(--border); border-radius: 16px; padding: 20px 22px; margin: 0 0 18px; box-shadow: 0 2px 0 var(--edge), var(--lift); }
    .sect > h3:first-child { margin: 0 0 12px; font-size: 15px; font-weight: 800; letter-spacing: -.005em; }
    .sect input[type=search] { padding: 8px 12px; background: #fff; color: var(--ink); border: 1.5px solid #C5D3DE; border-radius: 10px; font: inherit; font-size: 14px; }
    .sect input[type=search]:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px color-mix(in srgb, var(--primary) 20%, transparent); }
    .btn-ghost.sm, .btn-solid.sm { width: auto; padding: 7px 16px; font-size: 13.5px; }
    a.btn-ghost, a.btn-bad, a.btn-ok { display: inline-block; text-decoration: none; }
    .muted { color: #94a3b8; }

    /* Sidebar extras: the forms listed under "All feedback" */
    .vsub.on { background: var(--primary-tint); color: var(--primary-dark); box-shadow: inset 3px 0 0 var(--primary); }
    .vsub span:first-child { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
    .vsub span:last-child { font-variant-numeric: tabular-nums; font-weight: 800; }
    @media (max-width: 860px) { .vtabs .vsub { display: flex; flex: none; margin: 0; gap: 8px; white-space: nowrap; } }

    /* Form bar: status and actions */
    .fb-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .fb-bar form { margin: 0; }
    .fb-bar .grow { margin-left: auto; font-size: 13.5px; color: var(--muted); }
    .fb-note { margin: 12px 0 0; font-size: 13.5px; color: var(--muted); }

    /* Search row */
    .rv-search { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 14px; }
    .rv-search input[type=search] { flex: 1 1 280px; min-width: 0; padding: 10px 14px 10px 38px; font-size: 15px;
        background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='%2355696C' stroke-width='2' stroke-linecap='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='M21 21l-4.3-4.3'/%3E%3C/svg%3E") no-repeat 12px center; }
    .rv-search .clear { font-size: 13.5px; font-weight: 600; color: var(--primary); text-decoration: none; }
    .rv-search .clear:hover { text-decoration: underline; }
    .rv-result { margin: 0 0 12px; font-size: 14px; color: var(--muted); }

    /* Tables */
    .tbl td .sub { display: block; color: var(--muted); font-size: 12.5px; }
    .tbl td.act { white-space: nowrap; text-align: right; }
    .tbl .btn-ghost { width: auto; padding: 4px 13px; font-size: 12.5px; }
    .tbl td.snip { color: #2c3a52; max-width: 460px; overflow-wrap: anywhere; }
    .age { display: block; margin-top: 3px; font-size: 12.5px; color: var(--muted); }
    .slug { font-size: 12.5px; color: var(--muted); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .gone { display: inline-block; padding: 1px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 700; background: #F1F5F9; color: #475569; border: 1px solid #CBD5E1; }

    .pager { display: flex; gap: 10px; justify-content: center; align-items: center; margin-top: 16px; font-size: 13.5px; color: var(--muted); }

    /* Single response */
    .rv-back { display: inline-block; margin: 0 0 12px; font-size: 14px; font-weight: 600; color: var(--primary); text-decoration: none; }
    .rv-back:hover { text-decoration: underline; }
    .rv-head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; margin: 0 0 4px; }
    .rv-head h2 { margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -.01em; overflow-wrap: anywhere; }
    .rv-meta { margin: 0; color: var(--muted); font-size: 14px; overflow-wrap: anywhere; }
    .rv-grid { display: grid; gap: 0 22px; }
    @media (min-width: 1000px) {
        .rv-grid { grid-template-columns: minmax(0, 1fr) 340px; align-items: start; }
        .rv-side { position: sticky; top: 12px; }
    }
    dl.answers { margin: 0; display: grid; grid-template-columns: minmax(130px, 240px) minmax(0, 1fr); }
    dl.answers dt, dl.answers dd { margin: 0; padding: 12px 0; border-top: 1px solid var(--border); }
    dl.answers dt:first-of-type, dl.answers dt:first-of-type + dd { border-top: 0; }
    dl.answers dt { padding-right: 16px; font-size: 13px; font-weight: 700; color: var(--muted); }
    dl.answers dd { white-space: pre-wrap; overflow-wrap: anywhere; }
    dl.answers dt em { font-weight: 500; }
    @media (max-width: 700px) {
        dl.answers { grid-template-columns: 1fr; }
        dl.answers dt { padding-bottom: 0; border-top: 1px solid var(--border); }
        dl.answers dd { padding-top: 3px; border-top: 0; }
        dl.answers dt:first-of-type { border-top: 0; }
    }
    .who-name { margin: 0; font-size: 16px; font-weight: 700; overflow-wrap: anywhere; }
    .who-sub { margin: 4px 0 0; font-size: 13px; color: var(--muted); overflow-wrap: anywhere; }
    .side-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
    .side-actions form { margin: 0; }
    .pn { display: flex; justify-content: space-between; gap: 10px; }
    .pn .off { opacity: .45; pointer-events: none; }

    /* Email respondent (sent through the system) */
    .reply-fold { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border); }
    .reply-fold > summary { cursor: pointer; font-size: 14.5px; font-weight: 800; list-style: none; color: var(--primary-dark); }
    .reply-fold > summary::-webkit-details-marker { display: none; }
    .reply-fold > summary::before { content: '\25B8'; margin-right: 8px; color: var(--muted); }
    .reply-fold[open] > summary::before { content: '\25BE'; }
    .reply-fold form { margin: 12px 0 0; }
    .reply-fold .field { margin-bottom: 12px; }
    .reply-fold input[type=text], .reply-fold textarea { width: 100%; padding: 10px 12px; background: #fff; color: var(--ink); border: 1.5px solid #C5D3DE; border-radius: 10px; font: inherit; font-size: 14px; }
    .reply-fold input[type=text]:focus, .reply-fold textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px color-mix(in srgb, var(--primary) 20%, transparent); }
    .reply-fold .hint { display: block; margin-top: 6px; font-size: 12.5px; color: var(--muted); }
    .reply-fold .err { margin: 0 0 12px; padding: 10px 12px; background: var(--accent-tint); border: 1.5px solid #f0c2bd; border-radius: 10px; font-size: 13.5px; color: var(--accent-dark); }
</style>

<?php if (!empty($_GET['deleted'])): ?><div class="alert ok">Response deleted.</div><?php endif; ?>
<?php if (!empty($_GET['toggled'])): ?><div class="alert ok">Form status updated.</div><?php endif; ?>
<?php if (!empty($_GET['emailed'])): ?><div class="alert ok">Email sent to the respondent.</div><?php endif; ?>

<div class="adm-shell">

<nav class="vtabs" aria-label="Feedback forms">
    <a class="vtab<?= !$form ? ' on' : '' ?>" href="/admin/feedback-review"<?= !$form ? ' aria-current="page"' : '' ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><?= $svgStar ?></svg><span>All feedback</span>
        <span class="vcount zero"><?= (int) $totalAll ?></span>
    </a>
    <?php foreach ($forms as $f): $on = $form && (int) $f['id'] === $formId; ?>
        <a class="vsub<?= $on ? ' on' : '' ?>" href="/admin/feedback-review?form=<?= (int) $f['id'] ?>"<?= $on ? ' aria-current="page"' : '' ?>
           title="<?= $h($f['title'] ?: $f['form_type']) ?>">
            <span><?= $h($f['title'] ?: $f['form_type']) ?></span><span><?= (int) $f['response_count'] ?></span></a>
    <?php endforeach; ?>
</nav>

<div class="adm-main">

<?php if (!$form): /* ================= all forms ================= */ ?>

    <section class="sect">
        <?php if (!$forms): ?>
            <div class="empty-note">No feedback forms yet. Create one in the form builder and choose the "Feedback" type.</div>
        <?php else: ?>
        <div class="tbl-wrap">
        <table class="tbl"><thead><tr>
            <th>Form</th><th>Status</th><th>Responses</th><th>Closes</th><th></th></tr></thead><tbody>
        <?php foreach ($forms as $f):
            $open = FormModel::isOpen($f);
            $byDl = FormModel::closedByDeadline($f);
            $n = (int) $f['response_count']; ?>
            <tr>
                <td><a class="dl" href="/admin/feedback-review?form=<?= (int) $f['id'] ?>"><?= $h($f['title'] ?: $f['form_type']) ?></a>
                    <span class="slug">/<?= $h($f['form_type']) ?></span></td>
                <td><span class="pill" style="--tone: <?= $open ? 'var(--leaf-dark)' : '#64748b' ?>;"><?= $open ? 'Open' : ($byDl ? 'Closed (deadline)' : 'Closed') ?></span></td>
                <td><strong><?= $n ?></strong></td>
                <td><?= !empty($f['deadline_at']) ? $h(FormModel::formatIst($f['deadline_at'])) : '<span class="muted">No deadline</span>' ?></td>
                <td class="act"><a class="btn-ghost" href="/admin/feedback-review?form=<?= (int) $f['id'] ?>"><?= $n ? 'Read' : 'Open' ?> &rarr;</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
        <?php endif; ?>
    </section>

<?php elseif (!$resp): /* ================= one form: responses ================= */
    $isActive  = !empty($form['is_active']);
    $isOpenNow = FormModel::isOpen($form);
    $byDeadline = FormModel::closedByDeadline($form); ?>

    <section class="sect">
        <div class="fb-bar">
            <span class="pill" style="--tone: <?= $isOpenNow ? 'var(--leaf-dark)' : '#64748b' ?>;"><?= $isOpenNow ? 'Open' : ($byDeadline ? 'Closed (deadline)' : 'Closed') ?></span>
            <strong><?= $h(MiniWikiText::stripToPlainText($formTitle)) ?></strong>
            <span class="grow"><?= count($all) ?> response<?= count($all) === 1 ? '' : 's' ?></span>
        </div>
        <div class="fb-bar" style="margin-top:12px;">
            <a class="btn-ghost sm" href="/<?= $h(rawurlencode($form['form_type'])) ?>" target="_blank" rel="noopener">View public form</a>
            <?php if (FormModel::userCanEdit($form)): ?>
                <a class="btn-ghost sm" href="/admin/builder?edit=<?= (int) $formId ?>">Edit form<?= $byDeadline ? ' / extend deadline' : '' ?></a>
            <?php endif; ?>
            <form method="POST"><?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="form_id" value="<?= (int) $formId ?>">
                <input type="hidden" name="activate" value="<?= $isActive ? '' : '1' ?>">
                <button type="submit" class="btn-ghost sm"><?= $isActive ? 'Close form' : 'Reopen form' ?></button>
            </form>
        </div>
        <p class="fb-note">Names and emails are removed automatically after <?= (int) FeedbackModel::retentionMonths() ?> months. The written answers stay.</p>
    </section>

    <form method="GET" class="rv-search" role="search">
        <input type="hidden" name="form" value="<?= (int) $formId ?>">
        <input type="search" name="q" value="<?= $h($search) ?>" maxlength="100" autocomplete="off"
               placeholder="Search name, email or anything in the answers" aria-label="Search responses">
        <button class="btn-solid sm">Search</button>
        <?php if ($search !== ''): ?><a class="clear" href="<?= $h($fbUrl(['q' => ''])) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($search !== ''): ?>
        <p class="rv-result"><?= (int) $total ?> response<?= $total === 1 ? '' : 's' ?> match &ldquo;<?= $h($search) ?>&rdquo;.</p>
    <?php endif; ?>

    <section class="sect">
        <?php if (!$pageRows): ?>
            <div class="empty-note"><?= $search !== '' ? 'Nothing matches this search.' : 'No responses yet.' ?></div>
        <?php else: ?>
        <div class="tbl-wrap">
        <table class="tbl"><thead><tr>
            <th>Respondent</th><th>Response</th><th>Submitted</th><th></th></tr></thead><tbody>
        <?php foreach ($pageRows as $r):
            $scrubbed = !empty($r['scrubbed_at']);
            $age = $daysSince($r['created_at']);
            $snip = $preview($r); ?>
            <tr>
                <td>
                    <?php if ($scrubbed): ?>
                        <a class="dl" href="<?= $h($fbUrl(['id' => (int) $r['id']])) ?>">Anonymous</a>
                        <span class="gone">Details removed</span>
                    <?php else: ?>
                        <a class="dl" href="<?= $h($fbUrl(['id' => (int) $r['id']])) ?>"><?= $h($r['name'] ?: $r['email']) ?></a>
                        <span class="sub"><?= $h($r['email']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="snip"><?= $snip !== '' ? $h($snip) : '<span class="muted">&mdash;</span>' ?></td>
                <td><?= $h(date('j M Y', (int) strtotime((string) $r['created_at']))) ?>
                    <span class="age"><?= $age === 0 ? 'Today' : $age . ' day' . ($age === 1 ? '' : 's') . ' ago' ?></span></td>
                <td class="act"><a class="btn-ghost" href="<?= $h($fbUrl(['id' => (int) $r['id']])) ?>">Read &rarr;</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
        <?php if ($pages > 1): ?>
            <div class="pager">
                <?php if ($page > 1): ?><a class="btn-ghost sm" href="<?= $h($fbUrl(['page' => $page - 1])) ?>">Newer</a><?php endif; ?>
                <span>Page <?= $page ?> of <?= $pages ?></span>
                <?php if ($page < $pages): ?><a class="btn-ghost sm" href="<?= $h($fbUrl(['page' => $page + 1])) ?>">Older</a><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </section>

<?php else: /* ================= one response ================= */
    $answers  = is_array($resp['answers']) ? $resp['answers'] : [];
    $scrubbed = !empty($resp['scrubbed_at']);
    $canEmail = !$scrubbed && !empty($resp['email']) && filter_var((string) $resp['email'], FILTER_VALIDATE_EMAIL);
    $defaultSubject = 'Re: ' . MiniWikiText::stripToPlainText($formTitle);
    $myEmail = strtolower(trim((string) Auth::email()));
    $fmt = function ($v) use ($flat, $h): string {
        $t = $flat($v);
        return $t === '' ? '&mdash;' : $h($t);
    }; ?>

    <section class="sect">
        <a class="rv-back" href="<?= $h($fbUrl(['id' => ''])) ?>">&larr; Back to responses</a>
        <div class="rv-head">
            <h2><?= $scrubbed ? 'Anonymous response' : $h($resp['name'] ?: $resp['email']) ?></h2>
            <?php if ($scrubbed): ?><span class="gone">Details removed</span><?php endif; ?>
        </div>
        <p class="rv-meta">
            <?= $scrubbed ? '' : $h($resp['email']) . ' &middot; ' ?><?= $h(MiniWikiText::stripToPlainText($formTitle)) ?> &middot;
            <?= $h(CoreMail::formatExpiryIST($resp['created_at'])) ?>
        </p>
    </section>

    <div class="rv-grid">
    <div class="rv-main">
        <section class="sect">
            <h3>Response</h3>
            <dl class="answers">
            <?php foreach ($labels as $name => $label): ?>
                <dt><?= $h($label) ?></dt>
                <dd><?= $fmt($answers[$name] ?? '') ?></dd>
            <?php endforeach; ?>
            <?php foreach ($answers as $name => $v): if (isset($labels[$name])) continue; ?>
                <?php /* Answer to a question that has since been removed from the form. */ ?>
                <dt><?= $h($name) ?> <em>(removed question)</em></dt>
                <dd><?= $fmt($v) ?></dd>
            <?php endforeach; ?>
            </dl>
        </section>
    </div>

    <aside class="rv-side">
        <section class="sect">
            <h3>Respondent</h3>
            <?php if ($scrubbed): ?>
                <p class="who-sub" style="margin:0;">Name and email were removed after <?= (int) FeedbackModel::retentionMonths() ?> months. The answers are kept.</p>
            <?php else: ?>
                <p class="who-name"><?= $h($resp['name']) ?></p>
                <p class="who-sub"><?= $h($resp['email']) ?></p>
            <?php endif; ?>

            <?php if ($canEmail): ?>
            <details class="reply-fold" id="reply"<?= $emailError !== '' ? ' open' : '' ?>>
                <summary>Email respondent</summary>
                <form method="POST" action="<?= $h($fbUrl(['id' => (int) $resp['id']])) ?>#reply"><?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="email">
                    <input type="hidden" name="form_id" value="<?= (int) $formId ?>">
                    <input type="hidden" name="response_id" value="<?= (int) $resp['id'] ?>">
                    <?php if ($emailError !== ''): ?><div class="err"><?= $h($emailError) ?></div><?php endif; ?>
                    <div class="field">
                        <label for="mail-subject">Subject</label>
                        <input type="text" id="mail-subject" name="subject" maxlength="<?= (int) FB_MAIL_SUBJECT_MAX ?>" required
                               value="<?= $h($emailKeep['subject'] !== '' ? $emailKeep['subject'] : $defaultSubject) ?>">
                    </div>
                    <div class="field">
                        <label for="mail-message">Message</label>
                        <textarea id="mail-message" name="message" rows="7" maxlength="<?= (int) FB_MAIL_BODY_MAX ?>" required><?= $h($emailKeep['message']) ?></textarea>
                        <span class="hint">Sent by DCW Engage, not from your mail app. The email greets them by name.
                            <?= $myEmail !== '' ? 'Replies go to ' . $h($myEmail) . '.' : '' ?></span>
                    </div>
                    <button type="submit" class="btn-ok" style="padding:8px 18px;font-size:13.5px;">Send email</button>
                </form>
            </details>
            <?php endif; ?>

            <div class="side-actions">
                <form method="POST" onsubmit="return confirm('Delete this response permanently?');"><?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="form_id" value="<?= (int) $formId ?>">
                    <input type="hidden" name="response_id" value="<?= (int) $resp['id'] ?>">
                    <button type="submit" class="btn-bad" style="padding:8px 18px;font-size:13.5px;">Delete</button>
                </form>
            </div>
        </section>

        <section class="sect">
            <div class="pn">
                <a class="btn-ghost sm<?= $prevId ? '' : ' off' ?>" href="<?= $h($fbUrl(['id' => $prevId])) ?>">&larr; Newer</a>
                <a class="btn-ghost sm<?= $nextId ? '' : ' off' ?>" href="<?= $h($fbUrl(['id' => $nextId])) ?>">Older &rarr;</a>
            </div>
        </section>
    </aside>
    </div>

<?php endif; ?>

</div><!-- /.adm-main -->
</div><!-- /.adm-shell -->
<?php engage_footer(); ?>
