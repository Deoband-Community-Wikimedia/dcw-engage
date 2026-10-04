<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/member_support_helpers.php';
require_once __DIR__ . '/../../models/MemberTicketModel.php';

/**
 * DCW Engage - one conversation with DCW Support (/member/talk/ticket?id=CM-XXXXXX).
 * Signed-in members only. The conversation is looked up by tracking ID AND the Member ID
 * from the session, so nobody can open someone else's by guessing an ID.
 */
MemberSession::requireLogin();
$member   = MemberSession::current();
$memberId = (string) $member['member_id'];
$model    = new MemberTicketModel();

$tid = strtoupper(trim((string) ($_GET['id'] ?? '')));
if (!preg_match('/^(CM|SG|HQ)-[A-Z0-9]{6}$/', $tid)) { header('Location: /member/dashboard'); exit; }

$ticket = $model->getForMember($tid, $memberId);
if (!$ticket) { header('Location: /member/dashboard'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ms_csrf_valid()) {
        ms_flash('error', 'Your session expired. Please try again.');
    } elseif (!ms_submit_once()) {
        // Double click: the first request already sent the message. Drop this one silently.
    } else {
        $res = $model->memberReply($ticket, $memberId, (string) ($_POST['body'] ?? ''));
        if (!empty($res['ok'])) ms_flash('ok', 'Your message has been sent.');
        else ms_flash('error', (string) ($res['error'] ?? 'Could not send your message.'));
    }
    header('Location: /member/talk/ticket?id=' . rawurlencode($tid));
    exit;
}

$messages  = $model->messagesForMember((int) $ticket['id']);
$closed    = MemberTicketModel::isClosed((string) $ticket['status']);
$isSuggest = $ticket['type'] === 'suggestion';
$label     = MemberTicketModel::LABELS[$ticket['type']] ?? 'Conversation';

ms_head($label . ' ' . $tid, (string) $ticket['subject'],
    $label . ' · ' . $tid . ' · started ' . ms_date($ticket['created_at']),
    [['/member/talk', 'New conversation'], ['/member/dashboard', 'My dashboard'], ['/member/logout', 'Sign out']],
    'Member ID ' . $memberId);
?>
        <section class="panel">
            <div class="panel-head">
                <span class="pill" style="--tone: <?= ms_e(ms_tone((string) $ticket['status'])) ?>;"><?= ms_e($ticket['status']) ?></span>
                <?php if (!empty($ticket['about_staff'])): ?>
                    <p class="ms-meta">This complaint is about someone on the DCW team, so only the owners of the portal can read it.</p>
                <?php elseif (!empty($ticket['hide_name'])): ?>
                    <p class="ms-meta">Your name is hidden from <?= ms_e(MemberTicketModel::SUPPORT_LABEL) ?>. You can still read every reply here.</p>
                <?php endif; ?>
            </div>

            <div class="ms-thread">
                <?php foreach ($messages as $m): $mine = $m['sender'] === 'member'; ?>
                    <div class="ms-msg<?= $mine ? ' mine' : '' ?>">
                        <small><?= $mine ? 'You' : ms_e(MemberTicketModel::SUPPORT_LABEL) ?> · <?= ms_e(ms_date($m['created_at'], true)) ?></small>
                        <?= ms_e($m['body']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2><?= $closed ? 'This conversation is closed' : 'Add a message' ?></h2>
                <?php if ($closed && !$isSuggest): ?>
                    <p>If you still need help, send another message and the conversation will reopen.</p>
                <?php elseif ($isSuggest): ?>
                    <p>You can add more detail to your suggestion. Not every suggestion gets an individual reply.</p>
                <?php endif; ?>
            </div>
            <form method="post" action="/member/talk/ticket?id=<?= ms_e(rawurlencode($tid)) ?>" class="ms-form" autocomplete="off">
                <?= ms_csrf_field() ?>
                <label for="body" class="sr-only" style="position:absolute;left:-9999px;">Your message</label>
                <textarea id="body" name="body" maxlength="<?= MemberTicketModel::MAX_BODY ?>" required></textarea>
                <div class="ms-actions">
                    <button type="submit" class="btn-pill">Send message</button>
                    <a href="/member/dashboard">Back to dashboard</a>
                </div>
            </form>
        </section>
<?php ms_foot(); ?>
