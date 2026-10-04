<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/engage_staff.php';
require_once __DIR__ . '/../../models/MemberTicketModel.php';

// Nobody else, by design: complaints are confidential.
requireRole(['member_support', 'owner']);

// Owners also see tickets about team members; DCW Support never does.
$isOwner = Auth::isOwner();

$model = new MemberTicketModel();
$h = fn($v) => htmlspecialchars((string) $v);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$ticket = $id ? $model->getForStaff($id, $isOwner) : false;
if ($id && !$ticket) { http_response_code(404); die('Not found.'); }   // also what DCW Support gets for owner-only tickets
$notice = $error = '';

if ($ticket && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    if (!CSRF::consumeSubmitToken($_POST['submit_token'] ?? '')) { header('Location: /admin/member-support?id=' . $id); exit; }
    $act = $_POST['action'] ?? '';
    $body = trim((string) ($_POST['body'] ?? ''));

    if ($act === 'reply' || $act === 'note') {
        if ($body === '') $error = 'Write something first.';
        else {
            $model->staffReply($ticket, Auth::email(), $body, $act === 'note');
            if ($act === 'reply') ticket_notify($ticket, 'reply');
            $notice = $act === 'reply' ? 'Reply sent.' : 'Note saved. The member cannot see it.';
        }
    } elseif ($act === 'status') {
        $new = (string) ($_POST['status'] ?? '');
        if ($model->setStatus($ticket, $new)) {
            AuditLog::record('ticket.status', Auth::id(), Auth::email(), $ticket['member_email'], $ticket['tracking_id'] . ' -> ' . $new);
            if ($ticket['type'] === 'suggestion' || MemberTicketModel::isClosed($new)) ticket_notify($ticket, 'status');
            $notice = 'Status updated.';
        }
    } elseif ($act === 'assign') {
        $model->assign($id, (string) ($_POST['assignee'] ?? ''));
        AuditLog::record('ticket.assigned', Auth::id(), Auth::email(), null, $ticket['tracking_id'] . ' -> ' . (($_POST['assignee'] ?? '') ?: 'nobody'));
        $notice = 'Assignment saved.';
    }
    $ticket = $model->getForStaff($id, $isOwner);
}

$fmt = fn($utc) => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M Y, g:i a');
$age = function ($utc) {
    $d = (int) floor((time() - strtotime($utc . ' UTC')) / 86400);
    return $d <= 0 ? 'today' : $d . 'd';
};
// Anonymous suggestions: DCW Support sees no name or email. Owners can still trace abuse.
$who = fn($t) => ($t['hide_name'] && !$isOwner) ? 'Anonymous member' : ($t['member_name'] ?: $t['member_id']);

engage_open('Member support', 'Member support',
    'Complaints, suggestions and questions from members. Complaints are visible only to DCW Support and owners.',
    $ticket ? [['Workspace', '/admin/dashboard'], ['Queue', '/admin/member-support'], [$ticket['tracking_id'], null]]
            : [['Workspace', '/admin/dashboard'], ['Member support', null]], 'wide');
?>
<?php if ($notice): ?><div class="alert ok"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= $h($error) ?></div><?php endif; ?>

<?php if ($ticket): $thread = $model->messagesForStaff((int) $ticket['id']); ?>
    <div class="qcard" style="--tone: <?= $ticket['type'] === 'complaint' ? '#97161b' : ($ticket['type'] === 'suggestion' ? '#b45309' : '#106b9a') ?>;">
        <div class="qhead"><h3><?= $h($ticket['subject']) ?></h3><code><?= $h($ticket['tracking_id']) ?></code></div>
        <p class="qmeta"><?= $h(MemberTicketModel::LABELS[$ticket['type']]) ?> · from <strong><?= $h($who($ticket)) ?></strong>
            <?= (!$ticket['hide_name'] || $isOwner) ? '· ' . $h($ticket['member_id']) . ' · ' . $h($ticket['member_email']) : '' ?>
            · opened <?= $h($fmt($ticket['created_at'])) ?></p>
        <?php if ($ticket['about_staff']): ?><p class="alert-note">About a team member. Visible to owners only.</p><?php endif; ?>

        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <form method="POST" class="rowform"><?= CSRF::getInputField() ?><?= CSRF::getSubmitField() ?>
                <input type="hidden" name="id" value="<?= (int) $ticket['id'] ?>"><input type="hidden" name="action" value="status">
                <select name="status"><?php foreach (MemberTicketModel::STATUSES[$ticket['type']] as $s): ?>
                    <option <?= $s === $ticket['status'] ? 'selected' : '' ?>><?= $h($s) ?></option><?php endforeach; ?></select>
                <button class="btn-ghost">Set status</button>
            </form>
            <form method="POST" class="rowform"><?= CSRF::getInputField() ?><?= CSRF::getSubmitField() ?>
                <input type="hidden" name="id" value="<?= (int) $ticket['id'] ?>"><input type="hidden" name="action" value="assign">
                <select name="assignee"><option value="">Unassigned</option>
                    <?php foreach ($model->assignees() as $a): ?><option <?= $a === $ticket['assigned_to'] ? 'selected' : '' ?>><?= $h($a) ?></option><?php endforeach; ?></select>
                <button class="btn-ghost">Assign</button>
            </form>
        </div>

        <?php foreach ($thread as $m):
            $style = $m['sender'] === 'note' ? 'background:#fffbeb;border-color:#fde68a;' : ($m['sender'] === 'staff' ? 'background:#eff6ff;border-color:#bfdbfe;' : '');
            $name = $m['sender'] === 'member' ? $who($ticket) : ($m['sender'] === 'note' ? 'Internal note · ' . $m['author'] : 'Reply · ' . $m['author']); ?>
            <div class="quote" style="<?= $style ?>">
                <div class="qmeta" style="margin:0 0 4px;"><strong><?= $h($name) ?></strong> · <?= $h($fmt($m['created_at'])) ?>
                    <?= $m['sender'] === 'staff' ? ' · the member sees "DCW Support"' : '' ?></div>
                <?= nl2br($h($m['body'])) ?>
            </div>
        <?php endforeach; ?>

        <form method="POST" class="qform" style="margin-top:14px;"><?= CSRF::getInputField() ?><?= CSRF::getSubmitField() ?>
            <input type="hidden" name="id" value="<?= (int) $ticket['id'] ?>">
            <textarea name="body" rows="3" placeholder="Reply to the member, or save as an internal note."></textarea>
            <button type="submit" name="action" value="reply" class="btn-ok">Send reply</button>
            <button type="submit" name="action" value="note" class="btn-ghost">Save internal note</button>
        </form>
    </div>

<?php else:
    $fType = (string) ($_GET['type'] ?? ''); $fStatus = (string) ($_GET['status'] ?? 'open'); $fAssignee = (string) ($_GET['assignee'] ?? '');
    $rows = $model->listForStaff($isOwner, $fType, $fStatus, $fAssignee); ?>
    <form method="GET" class="box" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:14px 18px;">
        <select name="type"><option value="">All types</option>
            <?php foreach (MemberTicketModel::LABELS as $k => $l): ?><option value="<?= $k ?>" <?= $k === $fType ? 'selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select>
        <select name="status"><option value="open" <?= $fStatus === 'open' ? 'selected' : '' ?>>Open only</option><option value="" <?= $fStatus === '' ? 'selected' : '' ?>>All statuses</option></select>
        <select name="assignee"><option value="">Anyone</option><option value="none" <?= $fAssignee === 'none' ? 'selected' : '' ?>>Unassigned</option>
            <?php foreach ($model->assignees() as $a): ?><option <?= $a === $fAssignee ? 'selected' : '' ?>><?= $h($a) ?></option><?php endforeach; ?></select>
        <button class="btn-ghost">Filter</button>
    </form>
    <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Type</th><th>Subject</th><th>From</th><th>Status</th><th>Assigned</th><th>Last activity</th></tr></thead><tbody>
        <?php foreach ($rows as $r): $waiting = $r['last_sender'] === 'member' && !MemberTicketModel::isClosed($r['status']); ?>
            <tr>
                <td><?= $h(MemberTicketModel::LABELS[$r['type']]) ?><?= $r['about_staff'] ? ' <span class="pill" style="--tone:#97161b;">Owner only</span>' : '' ?></td>
                <td><a href="?id=<?= (int) $r['id'] ?>" style="color:var(--primary);font-weight:700;text-decoration:none;"><?= $h($r['subject']) ?></a>
                    <span class="sub"><?= $h($r['tracking_id']) ?></span></td>
                <td><?= $h($who($r)) ?></td>
                <td><?= $h($r['status']) ?><?= $waiting ? ' <span class="pill" style="--tone:#b45309;">Needs reply</span>' : '' ?></td>
                <td><?= $h($r['assigned_to'] ?: '—') ?></td>
                <td><?= $h($age($r['updated_at'])) ?></td>
            </tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:26px;">Nothing here.</td></tr><?php endif; ?>
        </tbody></table></div>
<?php endif; ?>
<?php engage_close(); ?>
