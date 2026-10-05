<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/tech_issue_ui.php';

// Route: /admin/report-problem  (any signed-in team account)
Auth::requireLogin();
$email = strtolower((string) Auth::email());

$model = new TechIssueModel();
const REPORT_BASE = '/admin/report-problem';

// Handles new reports and replies, then redirects. Does nothing on GET.
tech_handle_reporter_post($model, 'team', $email, $email, REPORT_BASE);

$id    = trim((string) ($_GET['id'] ?? ''));
$issue = $id !== '' ? $model->getOwned($id, 'team', $email) : false;
$list  = $issue ? [] : $model->listFor('team', $email);

engage_header([
    'title'   => 'Report a problem',
    'heading' => 'Report a problem',
    'kicker'  => 'Technical',
    'lead'    => 'Something not working in the workspace? Tell the technical team. They will see your email on the report.',
    'wide'    => true,
    'crumbs'  => $issue
        ? [['Workspace', '/admin/dashboard'], ['Report a problem', REPORT_BASE], [$issue['tracking_id']]]
        : [['Workspace', '/admin/dashboard'], ['Report a problem']],
]);
tech_styles();
tech_flash_html();
?>

<?php if ($issue): ?>
    <section class="fcard wide">
        <h2 class="ctitle" style="margin:0 0 6px;font-size:20px;font-weight:800;"><?= htmlspecialchars($issue['title']) ?></h2>
        <span class="pill" style="--tone: <?= htmlspecialchars(tech_status_tone($issue['status'])) ?>;"><?= htmlspecialchars($issue['status']) ?></span>
        <div class="issue-meta">
            <div><b>Reference</b><?= htmlspecialchars($issue['tracking_id']) ?></div>
            <div><b>About</b><?= htmlspecialchars(TechIssueModel::CATEGORIES[$issue['category']] ?? $issue['category']) ?></div>
            <div><b>Sent</b><?= htmlspecialchars(tech_date($issue['created_at'])) ?></div>
        </div>
        <?php tech_thread($model->messages((int) $issue['id'], false), 'reporter'); ?>
        <?php tech_reply_form($issue['tracking_id'], false); ?>
    </section>
<?php else: ?>
    <?php if ($list): ?>
    <section class="fcard wide">
        <h2 style="margin:0 0 10px;font-size:17px;font-weight:800;">My reports</h2>
        <div class="tbl-wrap"><table class="tbl">
            <thead><tr><th>Reference</th><th>Title</th><th>Status</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($list as $r): ?>
                <tr>
                    <td><a class="ref" href="<?= REPORT_BASE ?>?id=<?= htmlspecialchars(rawurlencode($r['tracking_id'])) ?>"><?= htmlspecialchars($r['tracking_id']) ?></a></td>
                    <td><?= htmlspecialchars($r['title']) ?><?= $r['last_sender'] === 'tech' && $r['status'] !== 'Resolved' ? ' <span class="sub warn-text">New reply</span>' : '' ?></td>
                    <td><span class="pill" style="--tone: <?= htmlspecialchars(tech_status_tone($r['status'])) ?>;"><?= htmlspecialchars($r['status']) ?></span></td>
                    <td><?= htmlspecialchars(tech_date($r['updated_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>

    <section class="fcard wide">
        <h2 style="margin:0 0 10px;font-size:17px;font-weight:800;">Send a new report</h2>
        <?php tech_report_form(); ?>
    </section>
<?php endif; ?>

<?php engage_footer(); ?>
