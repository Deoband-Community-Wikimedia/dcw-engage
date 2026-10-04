<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/member_support_helpers.php';
require_once __DIR__ . '/../../models/MemberTicketModel.php';

/**
 * DCW Engage - DCW Support queue for member complaints, suggestions and questions
 * (/admin/member-support, and ?id=N for one conversation).
 *
 * Role check: DCW Support or owner. DCW Support never sees conversations flagged about_staff;
 * that is enforced in MemberTicketModel's queries, so a guessed ID returns "not found".
 * The audit log records who did what, never what was written.
 */
requireRole([MemberTicketModel::SUPPORT_ROLE, 'owner']);

$isOwner    = Auth::isOwner();
$staffEmail = strtolower(currentAdminIdentifier());
$model      = new MemberTicketModel();
$self       = '/admin/member-support';

// ---------------------------------------------------------------- actions (POST, then redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int) ($_POST['id'] ?? 0);
    $back = $self . ($pid ? '?id=' . $pid : '');

    if (!ms_csrf_valid()) {
        ms_flash('error', 'Your session expired. Please try again.');
        header('Location: ' . $back); exit;
    }
    // A double click fires two valid requests. Only the first gets through; the duplicate is
    // dropped before anything is saved or emailed, with no flash of its own.
    if (!ms_submit_once()) {
        header('Location: ' . $back); exit;
    }

    $ticket = $model->getForStaff($pid, $isOwner);
    if (!$ticket) {
        ms_flash('error', 'Conversation not found.');
        header('Location: ' . $self); exit;
    }
    $tracking = $ticket['tracking_id'];

    switch ((string) ($_POST['action'] ?? '')) {
        case 'reply':
            $note = !empty($_POST['internal']);
            if ($model->staffReply($ticket, $staffEmail, (string) ($_POST['body'] ?? ''), $note)) {
                AuditLog::record($note ? 'support.note_added' : 'support.replied', Auth::id(), Auth::email(), $tracking, null);
                if (!$note) ticket_notify($pid, 'reply');
                ms_flash('ok', $note ? 'Internal note saved. The member cannot see it.' : 'Reply sent. The member has been emailed.');
            } else {
                ms_flash('error', 'Write a message first (up to ' . MemberTicketModel::MAX_BODY . ' characters).');
            }
            break;

        case 'status':
            $new = (string) ($_POST['status'] ?? '');
            if ($new === $ticket['status']) {
                ms_flash('ok', 'Status unchanged.');
            } elseif ($model->setStatus($ticket, $new)) {
                AuditLog::record('support.status_changed', Auth::id(), Auth::email(), $tracking, $ticket['status'] . ' -> ' . $new);
                ticket_notify($pid, 'status');
                ms_flash('ok', 'Status changed to ' . $new . '. The member has been emailed.');
            } else {
                ms_flash('error', 'That status is not valid for this conversation.');
            }
            break;

        case 'assign':
            $to = (string) ($_POST['assignee'] ?? '');
            if ($model->assign($ticket, $to)) {
                AuditLog::record('support.assigned', Auth::id(), Auth::email(), $tracking, $to !== '' ? 'Assigned to ' . strtolower($to) : 'Unassigned');
                ms_flash('ok', 'Assignment saved.');
            } else {
                ms_flash('error', 'That person cannot be assigned to this conversation.');
            }
            break;
    }
    header('Location: ' . $self . '?id=' . $pid); exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// ---------------------------------------------------------------- one conversation
if ($id > 0) {
    $ticket = $model->getForStaff($id, $isOwner);
    if (!$ticket) {
        ms_flash('error', 'Conversation not found.');
        header('Location: ' . $self); exit;
    }
    $messages  = $model->messagesForStaff($ticket);
    $assignees = $model->assignees(!empty($ticket['about_staff']));
    $label     = MemberTicketModel::LABELS[$ticket['type']] ?? 'Conversation';
    $closed    = MemberTicketModel::isClosed((string) $ticket['status']);
    $from      = !empty($ticket['hide_name'])
        ? 'Name hidden by the member'
        : trim($ticket['member_name'] . ' (' . $ticket['member_id'] . ')');

    engage_header([
        'title'   => $ticket['tracking_id'],
        'heading' => (string) $ticket['subject'],
        'kicker'  => MemberTicketModel::SUPPORT_LABEL . ' · ' . $label,
        'lead'    => $ticket['tracking_id'] . ' · started ' . ms_date($ticket['created_at']),
        'wide'    => true,
        'crumbs'  => [['Workspace', '/admin/dashboard'], [MemberTicketModel::SUPPORT_LABEL, $self], [$ticket['tracking_id']]],
    ]);
    echo ms_styles();
    echo ms_flash_html();
    ?>
        <section class="panel">
            <div class="panel-head">
                <span class="pill" style="--tone: <?= ms_e(ms_tone((string) $ticket['status'])) ?>;"><?= ms_e($ticket['status']) ?></span>
                <?php if (!empty($ticket['about_staff'])): ?><span class="ms-tag">About the team · owners only</span><?php endif; ?>
                <p class="ms-meta">From: <?= ms_e($from) ?> · Assigned to: <?= ms_e($ticket['assigned_to'] ?: 'nobody') ?> · Last updated <?= ms_e(ms_date($ticket['updated_at'], true)) ?></p>
            </div>

            <div class="ms-thread">
                <?php foreach ($messages as $m):
                    $cls = $m['sender'] === 'note' ? ' note' : ($m['sender'] === 'staff' ? ' mine' : '');
                    $who = $m['sender'] === 'member' ? ($m['author'] !== '' ? 'Member ' . $m['author'] : 'Member')
                         : ($m['sender'] === 'note' ? 'Internal note · ' . $m['author'] : MemberTicketModel::SUPPORT_LABEL . ' · ' . $m['author']);
                ?>
                    <div class="ms-msg<?= $cls ?>">
                        <small><?= ms_e($who) ?> · <?= ms_e(ms_date($m['created_at'], true)) ?></small>
                        <?= ms_e($m['body']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>Reply</h2>
                <p>The member gets an email that something is waiting. The email never contains your message.</p>
            </div>
            <form method="post" action="<?= ms_e($self) ?>" class="ms-form" autocomplete="off">
                <?= ms_csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $ticket['id'] ?>">
                <input type="hidden" name="action" value="reply">
                <textarea name="body" maxlength="<?= MemberTicketModel::MAX_BODY ?>" required aria-label="Your reply"></textarea>
                <label class="ms-check" for="internal">
                    <input type="checkbox" id="internal" name="internal" value="1">
                    <span>Internal note only. The member will not see it and will not be emailed.</span>
                </label>
                <div class="ms-actions"><button type="submit" class="btn-pill">Send</button></div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Manage</h2></div>
            <form method="post" action="<?= ms_e($self) ?>" class="ms-form ms-filters">
                <?= ms_csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $ticket['id'] ?>">
                <input type="hidden" name="action" value="status">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach (MemberTicketModel::STATUSES[$ticket['type']] as $s): ?>
                        <option value="<?= ms_e($s) ?>"<?= $s === $ticket['status'] ? ' selected' : '' ?>><?= ms_e($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-pill">Update status</button>
            </form>

            <form method="post" action="<?= ms_e($self) ?>" class="ms-form ms-filters">
                <?= ms_csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $ticket['id'] ?>">
                <input type="hidden" name="action" value="assign">
                <label for="assignee">Assigned to</label>
                <select id="assignee" name="assignee">
                    <option value="">Nobody</option>
                    <?php foreach ($assignees as $a): ?>
                        <option value="<?= ms_e($a) ?>"<?= strtolower((string) $ticket['assigned_to']) === strtolower($a) ? ' selected' : '' ?>><?= ms_e($a) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-pill">Assign</button>
            </form>
            <?php if ($closed): ?><p class="ms-meta">This conversation is closed. If the member writes again, a complaint or question reopens automatically.</p><?php endif; ?>
        </section>
    <?php
    engage_footer();
    exit;
}

// ---------------------------------------------------------------- the queue
$fType     = (string) ($_GET['type'] ?? '');
$fStatus   = (string) ($_GET['status'] ?? 'open');
$fAssignee = (string) ($_GET['assignee'] ?? '');
if (!isset(MemberTicketModel::LABELS[$fType])) $fType = '';
$allStatuses = array_values(array_unique(array_merge(...array_values(MemberTicketModel::STATUSES))));
if ($fStatus !== 'open' && $fStatus !== '' && !in_array($fStatus, $allStatuses, true)) $fStatus = 'open';
$assignees = $model->assignees(false);
if ($fAssignee !== 'none' && $fAssignee !== '' && !in_array($fAssignee, $assignees, true)) $fAssignee = '';

$tickets = $model->listForStaff($isOwner, $fType, $fStatus, $fAssignee);

engage_header([
    'title'   => MemberTicketModel::SUPPORT_LABEL,
    'heading' => MemberTicketModel::SUPPORT_LABEL,
    'kicker'  => 'Organizer workspace',
    'lead'    => 'Complaints, suggestions and questions from members. Oldest activity first.',
    'wide'    => true,
    'crumbs'  => [['Workspace', '/admin/dashboard'], [MemberTicketModel::SUPPORT_LABEL]],
]);
echo ms_styles();
echo ms_flash_html();
?>
        <section class="panel">
            <form method="get" action="<?= ms_e($self) ?>" class="ms-form ms-filters">
                <select name="type" aria-label="Type">
                    <option value="">All types</option>
                    <?php foreach (MemberTicketModel::LABELS as $k => $l): ?>
                        <option value="<?= ms_e($k) ?>"<?= $fType === $k ? ' selected' : '' ?>><?= ms_e($l) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" aria-label="Status">
                    <option value="open"<?= $fStatus === 'open' ? ' selected' : '' ?>>Open</option>
                    <option value=""<?= $fStatus === '' ? ' selected' : '' ?>>Everything</option>
                    <?php foreach ($allStatuses as $s): ?>
                        <option value="<?= ms_e($s) ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= ms_e($s) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="assignee" aria-label="Assigned to">
                    <option value="">Anyone</option>
                    <option value="none"<?= $fAssignee === 'none' ? ' selected' : '' ?>>Unassigned</option>
                    <?php foreach ($assignees as $a): ?>
                        <option value="<?= ms_e($a) ?>"<?= $fAssignee === $a ? ' selected' : '' ?>><?= ms_e($a) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-pill">Filter</button>
            </form>

            <?php if (empty($tickets)): ?>
                <div class="empty-note">Nothing here. Try a different filter.</div>
            <?php else: ?>
                <div class="ms-scroll">
                <table class="ms-table">
                    <thead><tr><th>Reference</th><th>Type</th><th>Subject</th><th>From</th><th>Status</th><th>Assigned</th><th>Updated</th></tr></thead>
                    <tbody>
                    <?php foreach ($tickets as $t):
                        $waiting = $t['last_sender'] === 'member' && !MemberTicketModel::isClosed((string) $t['status']);
                    ?>
                        <tr class="<?= $waiting ? 'wait' : '' ?>">
                            <td><a href="<?= ms_e($self) ?>?id=<?= (int) $t['id'] ?>"><?= ms_e($t['tracking_id']) ?></a></td>
                            <td><?= ms_e(MemberTicketModel::LABELS[$t['type']] ?? $t['type']) ?><?= !empty($t['about_staff']) ? '<span class="ms-tag">Team</span>' : '' ?></td>
                            <td><?= ms_e($t['subject']) ?><?= $waiting ? '<br><small style="color:#b45309;font-weight:600;">Waiting for a reply</small>' : '' ?></td>
                            <td><?= !empty($t['hide_name']) ? '<em>Hidden</em>' : ms_e($t['member_name'] !== '' ? $t['member_name'] : $t['member_id']) ?></td>
                            <td><span class="pill" style="--tone: <?= ms_e(ms_tone((string) $t['status'])) ?>;"><?= ms_e($t['status']) ?></span></td>
                            <td><?= ms_e($t['assigned_to'] ?: '—') ?></td>
                            <td><?= ms_e(ms_date($t['updated_at'], true)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if (count($tickets) >= 300): ?><p class="ms-meta">Showing the first 300. Narrow the filter to see more.</p><?php endif; ?>
            <?php endif; ?>
        </section>
<?php engage_footer(); ?>
