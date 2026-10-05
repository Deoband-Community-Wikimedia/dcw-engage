<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/tech_issue_ui.php';

// Route: /admin/tech-issues  (technical_manager, owner)
Auth::requireLogin();
tech_require_staff();

$model = new TechIssueModel();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        tech_flash('error', 'Your session expired. Please try again.');
        header('Location: /admin/tech-issues'); exit;
    }
    if (!CSRF::consumeSubmitToken($_POST['submit_token'] ?? '')) {
        header('Location: /admin/tech-issues'); exit;
    }

    $tracking = (string) ($_POST['tracking_id'] ?? '');
    $issue = $model->getByTracking($tracking);
    if (!$issue) {
        tech_flash('error', 'That report no longer exists.');
        header('Location: /admin/tech-issues'); exit;
    }

    $body     = trim((string) ($_POST['body'] ?? ''));
    $internal = !empty($_POST['internal']);
    $status   = (string) ($_POST['status'] ?? $issue['status']);
    if (!in_array($status, TechIssueModel::STATUSES, true)) $status = $issue['status'];

    try {
        if ($body === '') {
            if ($status !== $issue['status']) {
                $model->setStatus((int) $issue['id'], $status);
                AuditLog::record('tech_issue.updated', Auth::id(), Auth::email(), null, $tracking . ': status ' . $issue['status'] . ' -> ' . $status);
                tech_flash('success', 'Status changed to ' . $status . '.');
            } else {
                tech_flash('error', 'Write a reply, or change the status.');
            }
        } elseif (mb_strlen($body) > 4000) {
            tech_flash('error', 'Keep the message under 4000 characters.');
        } else {
            $model->staffReply($issue, $body, $internal, $status, (string) Auth::email());
            AuditLog::record('tech_issue.updated', Auth::id(), Auth::email(), null,
                $tracking . ': ' . ($internal ? 'internal note' : 'reply') . '; status ' . $status);
            tech_flash('success', $internal ? 'Internal note saved.' : 'Reply sent. The reporter will see it on their page.');
        }
    } catch (Throwable $e) {
        error_log('Tech issue staff update failed: ' . $e->getMessage());
        tech_flash('error', 'Could not save that. Please try again.');
    }
    header('Location: /admin/tech-issues?id=' . rawurlencode($tracking)); exit;
}

$id    = trim((string) ($_GET['id'] ?? ''));
$issue = $id !== '' ? $model->getByTracking($id) : false;

$filter = in_array($_GET['show'] ?? '', ['resolved', 'all'], true) ? $_GET['show'] : 'open';
$rows   = $issue ? [] : $model->listForStaff($filter);

engage_header([
    'title'   => 'Reported problems',
    'heading' => 'Reported problems',
    'kicker'  => 'Technical',
    'lead'    => 'Problems reported by members and team people. Reporters are shown only as "Member" or by team email.',
    'wide'    => true,
    'crumbs'  => $issue
        ? [['Workspace', '/admin/dashboard'], ['Reported problems', '/admin/tech-issues'], [$issue['tracking_id']]]
        : [['Workspace', '/admin/dashboard'], ['Reported problems']],
]);
tech_styles();
tech_flash_html();
?>

<?php if ($issue): ?>
    <?php $messages = $model->messages((int) $issue['id'], true); ?>
    <section class="fcard wide">
        <h2 class="ctitle" style="margin:0 0 6px;font-size:20px;font-weight:800;"><?= htmlspecialchars($issue['title']) ?></h2>
        <span class="pill" style="--tone: <?= htmlspecialchars(tech_status_tone($issue['status'])) ?>;"><?= htmlspecialchars($issue['status']) ?></span>
        <div class="issue-meta">
            <div><b>Reference</b><?= htmlspecialchars($issue['tracking_id']) ?></div>
            <div><b>Reported by</b><?= htmlspecialchars($issue['reporter_label']) ?><?= $issue['reporter_type'] === 'team' ? ' (team)' : '' ?></div>
            <div><b>About</b><?= htmlspecialchars(TechIssueModel::CATEGORIES[$issue['category']] ?? $issue['category']) ?></div>
            <div><b>Impact</b><?= htmlspecialchars(TechIssueModel::SEVERITIES[$issue['severity']] ?? $issue['severity']) ?></div>
            <div><b>Sent</b><?= htmlspecialchars(tech_date($issue['created_at'])) ?></div>
            <div><b>Page they named</b><?= $issue['page_url'] ? htmlspecialchars($issue['page_url']) : 'None' ?></div>
            <div><b>Browser</b><?= $issue['user_agent'] ? htmlspecialchars($issue['user_agent']) : 'Unknown' ?></div>
        </div>
        <?php tech_thread($messages, 'staff'); ?>
        <?php tech_reply_form($issue['tracking_id'], true, $issue['status']); ?>
    </section>

<?php else: ?>
    <section class="fcard wide">
        <div class="filters">
            <a href="/admin/tech-issues" class="<?= $filter === 'open' ? 'on' : '' ?>">Open</a>
            <a href="/admin/tech-issues?show=resolved" class="<?= $filter === 'resolved' ? 'on' : '' ?>">Resolved</a>
            <a href="/admin/tech-issues?show=all" class="<?= $filter === 'all' ? 'on' : '' ?>">All</a>
        </div>
        <?php if (!$rows): ?>
            <div class="empty-note"><?= $filter === 'open' ? 'No open problems. Nothing needs you right now.' : 'Nothing to show here.' ?></div>
        <?php else: ?>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th>Reference</th><th>Title</th><th>Reporter</th><th>About</th><th>Impact</th><th>Status</th><th>Updated</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><a class="ref" href="/admin/tech-issues?id=<?= htmlspecialchars(rawurlencode($r['tracking_id'])) ?>"><?= htmlspecialchars($r['tracking_id']) ?></a></td>
                            <td><?= htmlspecialchars($r['title']) ?><?= $r['last_sender'] === 'reporter' && $r['status'] !== 'Resolved' ? ' <span class="sub warn-text">New message</span>' : '' ?></td>
                            <td><?= htmlspecialchars($r['reporter_label']) ?></td>
                            <td><?= htmlspecialchars(TechIssueModel::CATEGORIES[$r['category']] ?? $r['category']) ?></td>
                            <td><?= htmlspecialchars(TechIssueModel::SEVERITIES[$r['severity']] ?? $r['severity']) ?></td>
                            <td><span class="pill" style="--tone: <?= htmlspecialchars(tech_status_tone($r['status'])) ?>;"><?= htmlspecialchars($r['status']) ?></span></td>
                            <td><?= htmlspecialchars(tech_date($r['updated_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php engage_footer(); ?>
