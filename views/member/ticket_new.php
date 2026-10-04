<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/member_support_helpers.php';
require_once __DIR__ . '/../../models/MemberTicketModel.php';

/**
 * DCW Engage - start a conversation with DCW Support (/member/talk).
 * Signed-in members only. A complaint, a suggestion, or a question.
 */
MemberSession::requireLogin();
$member = MemberSession::current();
$model  = new MemberTicketModel();

$type = (string) ($_POST['type'] ?? $_GET['type'] ?? 'question');
if (!isset(MemberTicketModel::LABELS[$type])) $type = 'question';

$subject = ''; $body = ''; $aboutStaff = false; $hideName = false; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ms_csrf_valid()) {
        $error = 'Your session expired. Please try again.';
        $subject = (string) ($_POST['subject'] ?? ''); $body = (string) ($_POST['body'] ?? '');
    } elseif (!ms_submit_once()) {
        // Double click: the first request already created the conversation. It is on the dashboard.
        header('Location: /member/dashboard');
        exit;
    } else {
        $subject    = (string) ($_POST['subject'] ?? '');
        $body       = (string) ($_POST['body'] ?? '');
        $aboutStaff = !empty($_POST['about_staff']);
        $hideName   = !empty($_POST['hide_name']);
        $res = $model->create($member, $type, $subject, $body, $aboutStaff, $hideName);
        if (!empty($res['ok'])) {
            ms_flash('ok', 'Sent. Your reference is ' . $res['tracking_id'] . '. We will email you when there is a reply.');
            header('Location: /member/talk/ticket?id=' . rawurlencode($res['tracking_id']));
            exit;
        }
        $error = (string) ($res['error'] ?? 'Something went wrong. Please try again.');
    }
}

$types = [
    'question'   => ['Ask a question',  'Something you would like to know about DCW or membership.'],
    'complaint'  => ['Make a complaint', 'Something went wrong and you want it looked at.'],
    'suggestion' => ['Share a suggestion', 'An idea to make DCW better. Replies are not guaranteed.'],
];

ms_head('Talk to DCW', 'Talk to ' . MemberTicketModel::SUPPORT_LABEL,
    'Ask a question, raise a complaint, or share an idea. Replies are emailed to you, and you read them here.',
    [['/member/dashboard', 'My dashboard'], ['/member/logout', 'Sign out']],
    'Member ID ' . (string) $member['member_id']);
?>
        <section class="panel">
            <?php if ($error !== ''): ?><div class="ms-error" role="alert"><?= ms_e($error) ?></div><?php endif; ?>

            <form method="post" action="/member/talk" class="ms-form" autocomplete="off">
                <?= ms_csrf_field() ?>

                <div class="ms-types" role="radiogroup" aria-label="What would you like to do?">
                    <?php foreach ($types as $key => [$label, $desc]): ?>
                        <label>
                            <input type="radio" name="type" value="<?= ms_e($key) ?>"<?= $type === $key ? ' checked' : '' ?>>
                            <?= ms_e($label) ?>
                            <span><?= ms_e($desc) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <label for="subject">Subject</label>
                <input type="text" id="subject" name="subject" maxlength="<?= MemberTicketModel::MAX_SUBJECT ?>" value="<?= ms_e($subject) ?>" required>

                <label for="body">Your message</label>
                <textarea id="body" name="body" maxlength="<?= MemberTicketModel::MAX_BODY ?>" required><?= ms_e($body) ?></textarea>
                <p class="hint">Please do not include passwords. We will never ask for one.</p>

                <label class="ms-check" id="opt-staff" for="about_staff">
                    <input type="checkbox" id="about_staff" name="about_staff" value="1"<?= $aboutStaff ? ' checked' : '' ?>>
                    <span>This complaint is about someone on the DCW team. Only the owners of the portal will be able to read it.</span>
                </label>

                <label class="ms-check" id="opt-hide" for="hide_name">
                    <input type="checkbox" id="hide_name" name="hide_name" value="1"<?= $hideName ? ' checked' : '' ?>>
                    <span>Do not show my name to <?= ms_e(MemberTicketModel::SUPPORT_LABEL) ?>. I can still read any reply here.</span>
                </label>

                <div class="ms-actions">
                    <button type="submit" class="btn-pill">Send</button>
                    <a href="/member/dashboard">Cancel</a>
                </div>
            </form>
        </section>

        <script>
            (function () {
                var radios = document.querySelectorAll('input[name=type]');
                function sync() {
                    var t = document.querySelector('input[name=type]:checked').value;
                    document.getElementById('opt-staff').style.display = t === 'complaint' ? 'flex' : 'none';
                    document.getElementById('opt-hide').style.display = t === 'suggestion' ? 'flex' : 'none';
                }
                radios.forEach(function (r) { r.addEventListener('change', sync); });
                sync();
            })();
        </script>
<?php ms_foot(); ?>
