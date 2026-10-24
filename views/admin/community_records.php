<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/MemberAuthModel.php';
require_once __DIR__ . '/../../models/CommunityRecordsModel.php';

// Reviewers and owners only. Organisers and coordinators are deliberately left out.
requireRole(['membership_reviewer', 'owner']);

$records = new CommunityRecordsModel();
$by = currentAdminIdentifier();
$h = fn($v) => htmlspecialchars((string) $v);
$fmt = fn($d) => $d ? date('j M Y', strtotime((string) $d)) : '';

$tab = (string) ($_GET['tab'] ?? 'sessions');
if (!in_array($tab, ['sessions', 'tech'], true)) $tab = 'sessions';
$sid = (int) ($_GET['session'] ?? $_POST['session_id'] ?? 0);
$session = $sid ? $records->getSession($sid) : null;
if ($sid && !$session) { http_response_code(404); die('Session not found.'); }

$error = '';
$preview = null; $previewText = ''; $previewDupes = 0;
$flash = $_SESSION['ch_flash'] ?? null;
unset($_SESSION['ch_flash']);

function ch_redirect(string $url, string $msg): void {
    $_SESSION['ch_flash'] = $msg;
    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    $act = (string) ($_POST['action'] ?? '');
    try {
        switch ($act) {
            case 'session_create':
                $newId = $records->createSession((string) ($_POST['title'] ?? ''), (string) ($_POST['held_on'] ?? ''), $by);
                AuditLog::record('records.session_created', Auth::id(), Auth::email(), 'Session #' . $newId);
                ch_redirect('/admin/community-records?session=' . $newId, 'Session created. Now add who attended.');

            case 'session_update':
                if (!$session) throw new InvalidArgumentException('Session not found.');
                $records->updateSession($sid, (string) ($_POST['title'] ?? ''), (string) ($_POST['held_on'] ?? ''), $by);
                AuditLog::record('records.session_edited', Auth::id(), Auth::email(), 'Session #' . $sid);
                ch_redirect('/admin/community-records?session=' . $sid, 'Session saved.');

            case 'attendance_preview':
            case 'attendance_commit':
                if (!$session) throw new InvalidArgumentException('Open a session first.');
                $previewText = (string) ($_POST['list'] ?? '');
                if (mb_strlen($previewText) > 50000) throw new InvalidArgumentException('That list is too long. Paste at most a few hundred lines at a time.');
                // Re-parsed on the server both times: the preview is never trusted as posted data.
                $preview = $records->previewLines($previewText, $previewDupes);
                if (!$preview) throw new InvalidArgumentException('Paste at least one line.');
                if ($act === 'attendance_commit') {
                    $n = $records->saveAttendance($sid, $preview, $by);
                    AuditLog::record('records.attendance_logged', Auth::id(), Auth::email(), 'Session #' . $sid, "$n rows");
                    ch_redirect('/admin/community-records?session=' . $sid, "$n " . ($n === 1 ? 'person' : 'people') . ' logged.');
                }
                break;

            case 'attendance_remove':
                if (!$session) throw new InvalidArgumentException('Session not found.');
                $records->removeAttendance((int) ($_POST['row_id'] ?? 0));
                AuditLog::record('records.attendance_removed', Auth::id(), Auth::email(), 'Session #' . $sid, 'Row #' . (int) ($_POST['row_id'] ?? 0));
                ch_redirect('/admin/community-records?session=' . $sid, 'Removed from this session.');

            case 'tech_add':
                $mid = $records->techAdd((string) ($_POST['who'] ?? ''), (string) ($_POST['reason'] ?? ''), (string) ($_POST['until'] ?? ''), $by);
                AuditLog::record('records.tech_added', Auth::id(), Auth::email(), (string) $mid);
                ch_redirect('/admin/community-records?tab=tech', "$mid is on the technical list.");

            case 'tech_confirm':
                $records->techConfirm((int) ($_POST['row_id'] ?? 0), (string) ($_POST['until'] ?? ''), $by);
                AuditLog::record('records.tech_reconfirmed', Auth::id(), Auth::email(), 'Row #' . (int) ($_POST['row_id'] ?? 0));
                ch_redirect('/admin/community-records?tab=tech', 'Confirmation date updated.');

            case 'tech_remove':
                $records->techRemove((int) ($_POST['row_id'] ?? 0), (string) ($_POST['reason'] ?? ''), $by);
                AuditLog::record('records.tech_removed', Auth::id(), Auth::email(), 'Row #' . (int) ($_POST['row_id'] ?? 0));
                ch_redirect('/admin/community-records?tab=tech', 'Removed from the technical list.');

            default:
                throw new InvalidArgumentException('Unknown action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        app_log('Community records error: ' . $e->getMessage());
        $error = 'Something went wrong. Please try again.';
    }
    if ($act === 'attendance_commit') $preview = null;   // failed commit: show the form again
}

$sessions = $records->listSessions();
$today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$live = count($sessions) >= CommunityRecordsModel::WINDOW;

engage_header([
    'title'   => 'Community records',
    'heading' => 'Community records',
    'kicker'  => 'Organizer workspace',
    'lead'    => 'Conversation Hour attendance and the technical contributors list. Internet support eligibility reads from these.',
    'tools'   => '',
    'wide'    => true,
    'crumbs'  => $session
        ? [['Workspace', '/admin/dashboard'], ['Community records', '/admin/community-records'], [$session['title']]]
        : [['Workspace', '/admin/dashboard'], ['Community records']],
]);
?>
<style>
    /* Community records only. Shared pieces (.sect, .tag, .fields, .idform, .hint, tabs) live in staff.css */
    .tbl .btn-ghost, .tbl .btn-ok, .tbl .btn-bad { width: auto; padding: 4px 13px; font-size: 12.5px; }
    .tbl td.act { white-space: nowrap; text-align: right; }
    .tbl td .sub { display: block; color: var(--muted); font-size: 12.5px; }
</style>

<?php if ($flash): ?><div class="alert ok"><?= $h($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= $h($error) ?></div><?php endif; ?>

<?php if ($session): /* ============================ one session ============================ */ ?>
    <a class="rv-back" href="/admin/community-records">&larr; All Conversation Hours</a>

    <section class="sect">
        <h3>Session</h3>
        <form method="POST"><?= CSRF::getInputField() ?>
            <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
            <div class="fields">
                <div><label>Title</label><input type="text" name="title" required maxlength="255" value="<?= $h($session['title']) ?>"></div>
                <div><label>Date held</label><input type="date" name="held_on" required max="<?= $h($today) ?>" value="<?= $h($session['held_on']) ?>"></div>
            </div>
            <div class="idform"><button name="action" value="session_update" class="btn-ghost">Save</button></div>
        </form>
        <p class="hint">Renaming a session does not change what past requests recorded: each request keeps its own snapshot.</p>
    </section>

    <section class="sect">
        <h3>Add attendance</h3>
        <?php if ($preview === null): ?>
            <form method="POST"><?= CSRF::getInputField() ?>
                <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                <textarea name="list" rows="8" required placeholder="Member IDs, member names or Wikimedia usernames, one per line or separated by commas"><?= $h($previewText) ?></textarea>
                <p class="hint">A member can be entered by Member ID, full name or verified Wikimedia username. Names must match exactly (capitals and extra spaces do not matter).
                    If two members share a name the line is skipped and shows both IDs, so paste the Member ID instead. A name that contains a comma cannot be used.</p>
                <p class="hint">To mark someone excused, put them on their own line: <code>Name | excused | reason</code>. A reason is required. You see a preview before anything is saved.</p>
                <div class="idform"><button name="action" value="attendance_preview" class="btn-solid">Preview</button></div>
            </form>
        <?php else:
            $cMember = $cUser = $cBad = 0;
            foreach ($preview as $p) { if ($p['kind'] === 'member') $cMember++; elseif ($p['kind'] === 'username') $cUser++; else $cBad++; } ?>
            <p><strong>Preview for &ldquo;<?= $h($session['title']) ?>&rdquo; (<?= $h($fmt($session['held_on'])) ?>).</strong>
                <?= $cMember ?> matched, <?= $cUser ?> username only, <?= $cBad ?> unreadable<?= $previewDupes ? ", $previewDupes duplicate" . ($previewDupes === 1 ? '' : 's') . ' merged' : '' ?>.
                Unreadable lines are skipped.</p>
            <div class="tbl-wrap"><table class="tbl"><thead><tr><th>You pasted</th><th>Result</th><th>Member</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($preview as $p): ?>
                <tr>
                    <td><?= $h($p['raw']) ?></td>
                    <td><span class="tag <?= $p['kind'] === 'member' ? 'ok' : ($p['kind'] === 'username' ? 'warn' : 'bad') ?>"><?= $h($p['kind'] === 'bad' ? 'Skipped' : ($p['kind'] === 'member' ? 'Matched' : 'Username only')) ?></span>
                        <span class="sub"><?= $h($p['label']) ?></span></td>
                    <td><?= $p['member_id'] ? '<code>' . $h($p['member_id']) . '</code>' : '&mdash;' ?><?= $p['kind'] !== 'bad' && $p['name'] !== '' ? '<span class="sub">' . $h($p['name']) . '</span>' : '' ?><?= $p['kind'] !== 'bad' && $p['username'] !== '' ? '<span class="sub">' . $h($p['username']) . '</span>' : '' ?></td>
                    <td><?= $p['kind'] === 'bad' ? '&mdash;' : $h(ucfirst($p['status'])) . ($p['note'] !== '' ? '<span class="sub">' . $h($p['note']) . '</span>' : '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <form method="POST" class="idform"><?= CSRF::getInputField() ?>
                <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                <input type="hidden" name="list" value="<?= $h($previewText) ?>">
                <?php if ($cMember + $cUser > 0): ?><button name="action" value="attendance_commit" class="btn-ok">Save <?= $cMember + $cUser ?> to this session</button><?php endif; ?>
                <a class="btn-ghost" style="display:inline-block;text-decoration:none;" href="/admin/community-records?session=<?= (int) $session['id'] ?>">Cancel</a>
            </form>
            <p class="hint">&ldquo;Username only&rdquo; people are saved too. They start counting once a member with that verified username exists.</p>
        <?php endif; ?>
    </section>

    <?php $att = $records->attendees((int) $session['id']); ?>
    <section class="sect">
        <h3>Logged for this session (<?= count($att) ?>)</h3>
        <?php if (!$att): ?><p class="hint">Nobody logged yet.</p><?php else: ?>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Person</th><th>Status</th><th>Logged by</th><th></th></tr></thead><tbody>
        <?php foreach ($att as $a): ?>
            <tr>
                <td><?php if ($a['member_id']): ?><code><?= $h($a['member_id']) ?></code><?php elseif ($a['pm']): ?><code><?= $h($a['pm']) ?></code><?php endif; ?>
                    <?= $h($a['wikimedia_username']) ?>
                    <?php if ($a['full_name'] !== ''): ?><span class="sub"><?= $h($a['full_name']) ?></span><?php endif; ?>
                    <?php if (!$a['member_id'] && !$a['pm']): ?><span class="sub">No member yet</span><?php endif; ?></td>
                <td><span class="tag <?= $a['status'] === 'excused' ? 'warn' : 'ok' ?>"><?= $h(ucfirst($a['status'])) ?></span><?= $a['note'] !== '' ? '<span class="sub">' . $h($a['note']) . '</span>' : '' ?></td>
                <td><?= $h($a['logged_by']) ?><span class="sub"><?= $h($fmt($a['logged_at'])) ?></span></td>
                <td class="act"><form method="POST" class="inline"><?= CSRF::getInputField() ?>
                    <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                    <input type="hidden" name="row_id" value="<?= (int) $a['id'] ?>">
                    <button name="action" value="attendance_remove" class="btn-bad" onclick="return confirm('Remove this person from the session?');">Remove</button></form></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </section>

<?php else: /* ============================ tabs ============================ */ ?>
    <nav class="rv-tabs" aria-label="Community records">
        <a class="rv-tab<?= $tab === 'sessions' ? ' on' : '' ?>" href="/admin/community-records">Conversation Hours (<?= count($sessions) ?>)</a>
        <a class="rv-tab<?= $tab === 'tech' ? ' on' : '' ?>" href="/admin/community-records?tab=tech">Technical contributors</a>
    </nav>

    <?php if ($tab === 'sessions'): ?>
        <?php if (!$live): ?>
            <div class="alert error"><strong>Only <?= count($sessions) ?> of <?= CommunityRecordsModel::WINDOW ?> sessions logged.</strong>
                Attendance is always checked. Until <?= CommunityRecordsModel::WINDOW ?> sessions are in, no member qualifies through Conversation Hour attendance (technical contributors still qualify).</div>
        <?php else: ?>
            <p class="hint" style="margin:0 0 14px;">Internet support checks the <strong><?= CommunityRecordsModel::WINDOW ?> most recent</strong> sessions below (by date held).</p>
        <?php endif; ?>

        <section class="sect">
            <h3>New Conversation Hour</h3>
            <form method="POST"><?= CSRF::getInputField() ?>
                <div class="fields">
                    <div><label>Title</label><input type="text" name="title" required maxlength="255" placeholder="e.g. Wikidata basics"></div>
                    <div><label>Date held</label><input type="date" name="held_on" required max="<?= $h($today) ?>"></div>
                </div>
                <div class="idform"><button name="action" value="session_create" class="btn-solid">Create and add attendance</button></div>
            </form>
        </section>

        <section class="sect">
            <h3>Sessions</h3>
            <?php if (!$sessions): ?><p class="hint">No sessions yet.</p><?php else: ?>
            <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Title</th><th>Date</th><th>Attendees</th><th></th></tr></thead><tbody>
            <?php foreach ($sessions as $i => $s): ?>
                <tr>
                    <td><a class="dl" href="?session=<?= (int) $s['id'] ?>"><?= $h($s['title']) ?></a>
                        <?php if ($i < CommunityRecordsModel::WINDOW): ?><span class="tag ok">Counts for eligibility</span><?php endif; ?></td>
                    <td><?= $h($fmt($s['held_on'])) ?></td>
                    <td><?= (int) $s['attendees'] ?><?= (int) $s['excused'] ? '<span class="sub">' . (int) $s['excused'] . ' excused</span>' : '' ?></td>
                    <td class="act"><a class="btn-ghost" href="?session=<?= (int) $s['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </section>

    <?php else: /* tech */ ?>
        <section class="sect">
            <h3>Add a technical contributor</h3>
            <form method="POST"><?= CSRF::getInputField() ?>
                <div class="fields">
                    <div><label>Member ID or verified Wikimedia username</label><input type="text" name="who" required maxlength="255" autocomplete="off"></div>
                    <div><label>Why (recorded)</label><input type="text" name="reason" required maxlength="500" placeholder="e.g. Maintains the DCW tools repo"></div>
                    <div><label>Confirmed until (optional)</label><input type="date" name="until" min="<?= $h($today) ?>"></div>
                </div>
                <div class="idform"><button name="action" value="tech_add" class="btn-solid">Add to list</button></div>
            </form>
            <p class="hint">With a date, the entry lapses on that day and has to be re-confirmed. Without one, it stays until someone removes it.</p>
        </section>

        <?php $cur = $records->techCurrent(); ?>
        <section class="sect">
            <h3>On the list (<?= count($cur) ?>)</h3>
            <?php if (!$cur): ?><p class="hint">Nobody yet.</p><?php else: ?>
            <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Member</th><th>Why</th><th>Added</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($cur as $t):
                $lapsed = $t['confirmed_until'] && $t['confirmed_until'] < $today; ?>
                <tr>
                    <td><code><?= $h($t['member_id']) ?></code><span class="sub"><?= $h($t['username']) ?></span></td>
                    <td style="white-space:pre-wrap"><?= $h($t['reason']) ?></td>
                    <td><?= $h($t['added_by']) ?><span class="sub"><?= $h($fmt($t['added_at'])) ?></span></td>
                    <td><?php if ($lapsed): ?><span class="tag bad">Lapsed <?= $h($fmt($t['confirmed_until'])) ?></span>
                        <?php elseif ($t['confirmed_until']): ?><span class="tag ok">Until <?= $h($fmt($t['confirmed_until'])) ?></span>
                        <?php else: ?><span class="tag ok">No end date</span><?php endif; ?></td>
                    <td class="act">
                        <form method="POST" class="inline"><?= CSRF::getInputField() ?>
                            <input type="hidden" name="row_id" value="<?= (int) $t['id'] ?>">
                            <input type="date" name="until" min="<?= $h($today) ?>" aria-label="Confirmed until">
                            <button name="action" value="tech_confirm" class="btn-ghost"><?= $lapsed ? 'Re-confirm' : 'Update date' ?></button>
                        </form>
                        <form method="POST" class="inline"><?= CSRF::getInputField() ?>
                            <input type="hidden" name="row_id" value="<?= (int) $t['id'] ?>">
                            <input type="text" name="reason" required maxlength="500" placeholder="Reason to remove" aria-label="Reason to remove" style="width:150px">
                            <button name="action" value="tech_remove" class="btn-bad" onclick="return confirm('Remove from the technical list?');">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </section>

        <?php $gone = $records->techRemoved(); if ($gone): ?>
        <details class="sect"><summary><strong>Removed (<?= count($gone) ?>)</strong></summary>
            <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Member</th><th>Originally added</th><th>Removed</th><th>Reason</th></tr></thead><tbody>
            <?php foreach ($gone as $t): ?>
                <tr><td><code><?= $h($t['member_id']) ?></code><span class="sub"><?= $h($t['username']) ?></span></td>
                    <td><?= $h($t['added_by']) ?><span class="sub"><?= $h($fmt($t['added_at'])) ?></span></td>
                    <td><?= $h($t['removed_by']) ?><span class="sub"><?= $h($fmt($t['removed_at'])) ?></span></td>
                    <td style="white-space:pre-wrap"><?= $h($t['removed_reason']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </details>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php engage_footer(); ?>
