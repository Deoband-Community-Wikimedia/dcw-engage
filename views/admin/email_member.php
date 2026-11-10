<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/member_support_helpers.php';   // ms_* helpers: CSRF, flash, double-click guard
require_once __DIR__ . '/../../models/MemberModel.php';                // MemberModel::CHAPTER_NAMES only
require_once __DIR__ . '/../../models/MemberMessageModel.php';
require_once __DIR__ . '/../../includes/mail/MemberMessageMail.php';

/**
 * DCW Engage - email a member (/admin/email-member).
 *
 * A support reviewer or owner finds a member by name, Member ID or Wikimedia username, writes a subject and a
 * message, optionally attaches ONE PDF (for example a support letter), and the email goes to the member's inbox.
 *
 * Rules:
 *   - The member's address is never shown or typed: it is looked up on the server from the Member ID.
 *   - No Reply-To. The email tells the member to sign in and write to DCW Support instead.
 *   - The PDF is read from PHP's temporary upload file while the email is sent. It is never moved, copied or
 *     stored; PHP deletes it when the request ends. Only a PDF up to 2 MB is accepted: its real content is
 *     checked, not just the file name. (It is not virus-scanned, so keep this page to people you trust.)
 *   - A struck-off member is shown in the search but cannot be emailed.
 *   - At most EM_HOURLY_MAX emails per person per hour (counted from the audit log).
 *   - A send is only reported as done when the mailer says it really went out. On a failure the typed text is
 *     kept, but the file is not (nothing is stored), so the PDF has to be attached again.
 *   - The audit log records who, which Member ID, the subject, and the PDF's name, size and a short hash.
 *     It never records the message text or the file.
 *
 * Role check: support_reviewer or owner (the same as Goodies).
 */
requireRole(['support_reviewer', 'owner']);

const EM_SUBJECT_MAX = 150;
const EM_BODY_MAX    = 5000;
const EM_FILE_MAX    = 2097152;   // 2 MB
const EM_HOURLY_MAX  = 20;

$self  = '/admin/email-member';
$model = new MemberMessageModel();

/**
 * Checks the uploaded file and returns [attachment|null, error]. No file chosen is fine: [null, ''].
 * The attachment points at PHP's own temporary file; nothing is moved or saved.
 */
function em_read_upload($f): array
{
    if (!is_array($f) || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, ''];

    $err = (int) $f['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return [null, 'That PDF is too large. The limit is 2 MB.'];
    if ($err !== UPLOAD_ERR_OK) return [null, 'The file could not be uploaded. Please try again.'];

    $tmp = (string) ($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return [null, 'The file could not be read. Please try again.'];

    $size = (int) filesize($tmp);
    if ($size <= 0) return [null, 'That file is empty.'];
    if ($size > EM_FILE_MAX) return [null, 'That PDF is too large. The limit is 2 MB.'];

    // The real content must be a PDF, whatever the file is called.
    $fh = fopen($tmp, 'rb');
    $head = $fh ? (string) fread($fh, 5) : '';
    if ($fh) fclose($fh);
    $mime = class_exists('finfo') ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : 'application/pdf';
    if ($head !== '%PDF-' || $mime !== 'application/pdf') return [null, 'Only a PDF can be attached.'];

    // The name the member sees: letters, digits, dot, dash, underscore and space only, always ending in .pdf.
    $orig = basename(str_replace('\\', '/', (string) ($f['name'] ?? '')));
    $base = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string) preg_replace('/\.pdf$/i', '', $orig)), " ._-");
    if ($base === '') $base = 'document';
    $name = mb_substr($base, 0, 70) . '.pdf';

    return [[
        'path' => $tmp,
        'name' => $name,
        'type' => 'application/pdf',
        'size' => $size,
        'sha'  => substr((string) hash_file('sha256', $tmp), 0, 12),
    ], ''];
}

$error = '';
$keep  = ['subject' => '', 'message' => ''];
$selectedId = strtoupper(trim((string) ($_GET['member'] ?? $_POST['member_id'] ?? '')));
$q = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
if (mb_strlen($q) > 100) $q = mb_substr($q, 0, 100);
$back = $self . ($selectedId !== '' ? '?member=' . rawurlencode($selectedId) . ($q !== '' ? '&q=' . rawurlencode($q) : '') : '');

// ---------------------------------------------------------------- send (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A file bigger than the server's post limit empties $_POST and $_FILES altogether.
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        ms_flash('error', 'That upload was too large for the server. Attach a PDF of 2 MB or less.');
        header('Location: ' . $self); exit;
    }
    if (!ms_csrf_valid()) {
        ms_flash('error', 'Your session expired. Please try again.');
        header('Location: ' . $back); exit;
    }
    // A double click fires two valid requests. Only the first gets through: an email cannot be taken back.
    if (!ms_submit_once()) {
        header('Location: ' . $back); exit;
    }

    $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($_POST['subject'] ?? '')));
    $message = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['message'] ?? '')));
    $keep = ['subject' => $subject, 'message' => $message];

    $recipient = MemberMessageModel::isMemberId($selectedId) ? $model->recipientFor($selectedId) : null;
    $me = (string) Auth::email();

    if (!$recipient) {
        $error = 'Choose a member first.';
    } elseif ($recipient['struck']) {
        $error = 'This member is struck off, so no email can be sent from here.';
    } elseif (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'There is no valid email address on file for this member, so nothing can be sent.';
    } elseif ($subject === '' || mb_strlen($subject) > EM_SUBJECT_MAX) {
        $error = 'Please enter a subject (maximum ' . EM_SUBJECT_MAX . ' characters).';
    } elseif ($message === '' || mb_strlen($message) > EM_BODY_MAX) {
        $error = 'Please write a message (maximum ' . number_format(EM_BODY_MAX) . ' characters).';
    } elseif ($model->recentSendCount($me) >= EM_HOURLY_MAX) {
        $error = 'You have already sent ' . EM_HOURLY_MAX . ' emails in the last hour. Please try again a little later.';
    } else {
        [$att, $fileError] = em_read_upload($_FILES['attachment'] ?? null);
        if ($fileError !== '') {
            $error = $fileError;
        } else {
            try {
                $sent = MemberMessageMail::send($recipient['email'], $recipient['name'], $subject, $message, $att);
            } catch (Throwable $e) {
                app_log("Member email failed for {$recipient['member_id']}: " . $e->getMessage());
                $sent = false;
            }

            if ($sent) {
                $detail = 'Subject: ' . mb_strimwidth($subject, 0, 120, '…')
                        . ($att ? ' | PDF: ' . $att['name'] . ', ' . $att['size'] . ' bytes, sha256 ' . $att['sha'] : ' | no attachment');
                AuditLog::record(MemberMessageModel::AUDIT_ACTION, Auth::id(), $me, $recipient['member_id'], mb_strimwidth($detail, 0, 500, '…'));
                ms_flash('ok', 'Email sent to ' . ($recipient['name'] !== '' ? $recipient['name'] . ' ' : '') . '(' . $recipient['member_id'] . ').'
                    . ($att ? ' The PDF was attached and has not been stored.' : ''));
                header('Location: ' . $self); exit;
            }
            $error = 'The email could not be sent. Please try again in a moment' . ($att ? ', and attach the PDF again.' : '.');
        }
    }
}

// ---------------------------------------------------------------- page
$results = mb_strlen($q) >= 2 ? $model->search($q) : [];
$selected = (MemberMessageModel::isMemberId($selectedId)) ? $model->recipientFor($selectedId) : null;
$chapterLabel = fn(array $keys) => implode(', ', array_map(fn($k) => MemberModel::CHAPTER_NAMES[$k] ?? $k, $keys));

engage_header([
    'title'   => 'Email a member',
    'heading' => 'Email a member',
    'lead'    => 'Send a message to a member, with an optional PDF such as a support letter. Their address is never shown.',
    'slim'    => true,
    'wide'    => true,
    'crumbs'  => [['Workspace', '/admin/dashboard'], ['Email a member']],
]);
echo ms_flash_html();
?>
        <section class="ms-card">
            <div class="panel-head">
                <h2>Find a member</h2>
                <p>Search by name, Member ID or Wikimedia username.</p>
            </div>
            <form method="get" action="<?= ms_e($self) ?>" class="ms-form ms-filters" role="search">
                <input type="search" name="q" value="<?= ms_e($q) ?>" maxlength="100" autocomplete="off"
                       placeholder="Name, Member ID or username" aria-label="Search members">
                <button type="submit" class="btn-neutral">Search</button>
            </form>

            <?php if ($q !== '' && mb_strlen($q) < 2): ?>
                <p class="ms-meta">Type at least 2 characters.</p>
            <?php elseif ($q !== '' && !$results): ?>
                <div class="empty-note">No member matches &ldquo;<?= ms_e($q) ?>&rdquo;.</div>
            <?php elseif ($results): ?>
                <div class="ms-scroll">
                <table class="ms-table">
                    <thead><tr><th>Name</th><th>Member ID</th><th>Chapters</th><th>Membership</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($results as $p): ?>
                        <tr>
                            <td><?= ms_e($p['name'] !== '' ? $p['name'] : '—') ?><?= $p['username'] !== '' ? '<small class="ms-waiting">' . ms_e($p['username']) . '</small>' : '' ?></td>
                            <td><?= ms_e($p['member_id']) ?></td>
                            <td><?= ms_e($chapterLabel($p['chapters'])) ?></td>
                            <td>
                                <?php if ($p['struck']): ?>
                                    <span class="pill" style="--tone: var(--accent, #b91c1c);">Struck off</span>
                                <?php else: ?>
                                    <span class="pill" style="--tone: <?= $p['active'] ? 'var(--leaf-dark, #15803d)' : 'var(--slate, #6b7280)' ?>;"><?= $p['active'] ? 'Active' : 'Expired' ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p['struck']): ?>
                                    <span class="ms-meta">Cannot be emailed</span>
                                <?php else: ?>
                                    <a href="<?= ms_e($self . '?member=' . rawurlencode($p['member_id']) . '&q=' . rawurlencode($q)) ?>">Write &rarr;</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

<?php if ($selectedId !== '' && !$selected): ?>
        <section class="ms-card"><div class="empty-note">No member has that Member ID.</div></section>

<?php elseif ($selected && $selected['struck']): ?>
        <section class="ms-card"><div class="empty-note"><?= ms_e($selected['name'] !== '' ? $selected['name'] : $selected['member_id']) ?> is struck off, so no email can be sent from here.</div></section>

<?php elseif ($selected):
    $toLabel = ($selected['name'] !== '' ? $selected['name'] : 'this member') . ' (' . $selected['member_id'] . ')'; ?>
        <section class="ms-card">
            <div class="panel-head">
                <h2>Write to <?= ms_e($toLabel) ?></h2>
                <p>The email says it comes from the DCW team and tells the member to sign in and write to DCW Support for further help. Replies are not collected.</p>
            </div>
            <?php if ($error !== ''): ?><div class="ms-error" role="alert"><?= ms_e($error) ?></div><?php endif; ?>
            <form method="post" action="<?= ms_e($self) ?>" enctype="multipart/form-data" class="ms-form" autocomplete="off">
                <?= ms_csrf_field() ?>
                <input type="hidden" name="member_id" value="<?= ms_e($selected['member_id']) ?>">
                <input type="hidden" name="q" value="<?= ms_e($q) ?>">

                <label for="em-subject">Subject</label>
                <input type="text" id="em-subject" name="subject" maxlength="<?= (int) EM_SUBJECT_MAX ?>" required
                       value="<?= ms_e($keep['subject']) ?>">

                <label for="em-message">Message</label>
                <textarea id="em-message" name="message" rows="9" maxlength="<?= (int) EM_BODY_MAX ?>" required><?= ms_e($keep['message']) ?></textarea>
                <p class="ms-meta">Plain text. The email greets the member by name.</p>

                <label for="em-file">PDF (optional)</label>
                <input type="file" id="em-file" name="attachment" accept="application/pdf,.pdf">
                <p class="ms-meta">One PDF, up to 2 MB. It is sent with the email and is not saved on the server.</p>

                <div class="ms-actions">
                    <button type="submit" class="btn-ok"
                            onclick="return confirm(<?= ms_e(json_encode('Send this email to ' . $toLabel . ' now? It cannot be taken back.')) ?>);">Send email</button>
                </div>
            </form>
        </section>
<?php endif; ?>
<?php engage_footer(); ?>
