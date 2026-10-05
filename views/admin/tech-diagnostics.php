<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/tech_issue_ui.php';
require_once __DIR__ . '/../../includes/portal_diagnostics.php';

// Route: /admin/tech-diagnostics  (technical_manager, owner)
Auth::requireLogin();
tech_require_staff();

header('Cache-Control: no-store');

$groups = PortalDiagnostics::run();
$tally  = PortalDiagnostics::tally($groups);

AuditLog::record('tech_diagnostics.run', Auth::id(), Auth::email(), null,
    'ok ' . $tally['ok'] . ', warn ' . $tally['warn'] . ', fail ' . $tally['fail']);

$tone  = ['ok' => '#15803d', 'warn' => '#b45309', 'fail' => '#b91c1c'];
$words = ['ok' => 'Pass', 'warn' => 'Warning', 'fail' => 'Fail'];

engage_header([
    'title'   => 'Portal diagnostics',
    'heading' => 'Portal diagnostics',
    'kicker'  => 'Technical',
    'lead'    => 'Read-only health checks. No member data, settings or secrets are shown. Details of a failure go to the server log.',
    'wide'    => true,
    'crumbs'  => [['Workspace', '/admin/dashboard'], ['Reported problems', '/admin/tech-issues'], ['Diagnostics']],
]);
tech_styles();
?>
<section class="fcard wide">
    <p style="margin:0 0 14px;font-size:15px;">
        <strong><?= (int) $tally['ok'] ?></strong> passed &middot;
        <strong><?= (int) $tally['warn'] ?></strong> warnings &middot;
        <strong><?= (int) $tally['fail'] ?></strong> failed
        &nbsp;&middot;&nbsp; <a href="/admin/tech-diagnostics">Run again</a>
        &nbsp;&middot;&nbsp; <a href="/admin/tech-issues">Back to reported problems</a>
    </p>
</section>

<?php foreach ($groups as $title => $checks): ?>
<section class="fcard wide">
    <h2 style="margin:0 0 10px;font-size:17px;font-weight:800;"><?= htmlspecialchars($title) ?></h2>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead>
            <tbody>
            <?php foreach ($checks as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['label']) ?></td>
                    <td><span class="pill" style="--tone: <?= $tone[$c['state']] ?? '#475569' ?>;"><?= htmlspecialchars($words[$c['state']] ?? $c['state']) ?></span></td>
                    <td><?= htmlspecialchars($c['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endforeach; ?>

<?php engage_footer(); ?>
