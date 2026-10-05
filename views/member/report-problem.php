<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/tech_issue_ui.php';

// Route: /member/report-problem  (any signed-in member, including expired ones)
MemberSession::requireLogin();
$member   = MemberSession::current();
$memberId = (string) $member['member_id'];
$fullName = trim((string) ($member['full_name'] ?? ''));

$model = new TechIssueModel();
const REPORT_BASE = '/member/report-problem';

// The Member ID is stored only to match the report to its owner.
// The technical team sees just "Member".
tech_handle_reporter_post($model, 'member', $memberId, 'Member', REPORT_BASE);

$id    = trim((string) ($_GET['id'] ?? ''));
$issue = $id !== '' ? $model->getOwned($id, 'member', $memberId) : false;
$list  = $issue ? [] : $model->listFor('member', $memberId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c567a">
    <meta name="robots" content="noindex, nofollow">
    <title>Report a problem - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/engage.css?v=2">
    <style>.hero { padding-bottom: 44px; } .wrap.cards-wrap { margin-top: 34px; }</style>
    <?php tech_styles(); ?>
</head>
<body>
    <header class="hero">
        <div class="topbar">
            <a class="brand" href="/">
                <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
                <span>DCW Engage</span>
            </a>
            <div class="tools">
                <span class="who"><?= htmlspecialchars($fullName !== '' ? $fullName : $memberId) ?></span>
                <a href="/member/dashboard" class="chip-btn">Dashboard</a>
                <a href="/member/logout" class="chip-btn">Sign out</a>
            </div>
        </div>
        <p class="kicker">Technical</p>
        <h1>Report a problem</h1>
        <p class="lead">Something not working on this site? Tell the technical team. They see your report as coming from "Member", not your name or ID.</p>
    </header>

    <main class="wrap wide cards-wrap">
        <?php tech_flash_html(); ?>

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
                <p style="margin:14px 0 0;"><a href="<?= REPORT_BASE ?>">&larr; All my reports</a></p>
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
    </main>

    <footer>
        <div class="org">
            <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
            <span>Deoband Community Wikimedia</span>
        </div>
        <div>&copy; <?= date('Y') ?> · <a href="/">dcwwiki.org</a></div>
    </footer>
</body>
</html>
